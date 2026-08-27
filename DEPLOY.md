# Deploying Digi Tracker Suite

Target: **Namecheap cPanel shared hosting, with SSH.**

Two things about this application shape every decision below.

**The ingest URLs are permanent.** `/track`, `/deactivate` and `/tracking-skipped` are compiled into
every released copy of every plugin. A site running v1.4 of a plugin from three years ago will keep
posting to the hostname it was built with, forever, and there is no mechanism to tell it otherwise.
Choose the ingest hostname as if you can never change it, because you cannot.

**`APP_KEY` is the only thing standing between the database and a pile of contact details.**
Admin emails and names are encrypted at rest with it, and the blind index that makes email
searchable is an HMAC keyed with it. Lose it and that data is gone; rotate it and that data is gone.
See [Never rotate APP_KEY](#never-rotate-app_key).

---

## 1. Before you start

Run the preflight on the server. It changes nothing and takes a second.

```sh
scp deploy/preflight.sh user@server:~/
ssh user@server 'bash preflight.sh'
```

It checks PHP 8.3+, the extensions Laravel needs, `proc_open`, cron and Composer.

**If it reports the wrong PHP version**, the binary on `$PATH` is probably not the one cPanel runs.
Find the right one and tell the script:

```sh
ls /opt/cpanel/ea-php*/root/usr/bin/php
PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php bash preflight.sh
```

Note that path. Everything below — including the cron entry — must use the same binary.

### `proc_open` is not optional

The scheduler runs the queue worker with `runInBackground()`, which shells out. If `proc_open` is in
`disable_functions`, telemetry is accepted, stored in `raw_payloads`, and then **sits in the jobs
table forever**. Nothing errors. The dashboard simply stays empty while the ingest logs look
perfectly healthy. Ask Namecheap to remove it before going further.

---

## 2. Server layout

Application code must live **outside** the web root. `.env` holds the database password and the
encryption key; a misconfigured docroot serves it as plain text.

```
/home/USER/
├── digi-tracker/            ← the application, never web-accessible
│   ├── .env
│   ├── app/
│   ├── public/              ← subdomain docroot points HERE
│   └── ...
└── public_html/             ← untouched
```

In cPanel → **Domains** → create the subdomain and set its **Document Root** to
`/home/USER/digi-tracker/public`.

Do not use a symlink from `public_html`. cPanel's `FollowSymLinks` handling varies between hosts and
a broken symlink fails as a 403 with nothing useful in the logs.

### Two hostnames, permanently

The plan commits to an agency hostname now and a product hostname later. Both must resolve to this
docroot forever, because the pilot's sites will never migrate:

- `telemetry.pluginizelab.com` — used by the first releases
- a product hostname later, for anything shipped after it exists

Add the second as a parked domain or an additional subdomain pointing at the same document root. The
application does not care which hostname a heartbeat arrives on.

---

## 3. First deploy

### 3.1 Build assets locally

Never on the server — shared hosting rarely has Node, and when it does it will run out of memory.

```sh
npm ci
npm run build          # writes public/build
```

### 3.2 Upload

```sh
rsync -az --delete \
  --exclude '.git' --exclude 'node_modules' --exclude '.env' \
  --exclude 'storage/logs/*' --exclude 'storage/framework/cache/*' \
  ./ user@server:~/digi-tracker/
```

`--exclude '.env'` matters: the local file points at a local database and has a different
`APP_KEY`. Overwriting the server's copy would silently make every encrypted column unreadable.

### 3.3 Database

cPanel → **MySQL Databases**. Create a database and a user, and grant all privileges.

Note the names: cPanel prefixes both with your account name, so `digitracker` becomes
`cpaneluser_digitracker`.

### 3.4 Configure

```sh
ssh user@server
cd ~/digi-tracker

cp .env.example .env
php artisan key:generate          # do this exactly once, ever
nano .env
```

The values that matter:

```ini
APP_NAME="Digi Tracker Suite"
APP_ENV=production
APP_DEBUG=false                   # a stack trace on /track leaks the schema
APP_URL=https://telemetry.pluginizelab.com

DB_CONNECTION=mysql
DB_DATABASE=cpaneluser_digitracker
DB_USERNAME=cpaneluser_digitracker
DB_PASSWORD=...

# Both database-backed on purpose. The workload is a few hundred requests a
# day; Redis would be another daemon to keep alive on a host that kills them.
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

# Optional. Absent, no country is recorded and ingest degrades rather than
# failing. See section 8.
GEOIP_DATABASE=/home/USER/digi-tracker/storage/app/geoip/GeoLite2-Country.mmdb
```

Then back up the key immediately, somewhere that is not this server:

```sh
grep APP_KEY .env
```

### 3.5 Install and migrate

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan filament:assets
php artisan storage:link
```

`--force` is required because `migrate` refuses to run unprompted in production. That prompt exists
for a reason — read what it is about to run the first time.

### 3.6 Cache

```sh
php artisan optimize          # config, routes, views, events
php artisan filament:optimize # Filament components and Blade icons
```

Both are verified to work with this application's routes. Re-run them after **every** deploy;
skipping them leaves the old cached config in place and the new code reading stale values.

### 3.7 Permissions

```sh
chmod -R 775 storage bootstrap/cache
```

---

## 4. The cron entry

One line, once, for the life of the project.

cPanel → **Cron Jobs** → Add New Cron Job → **Once Per Minute**:

```
cd /home/USER/digi-tracker && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Use the absolute PHP path from the preflight. cron's `PATH` is not your login shell's.

That single entry drives everything:

| What | When | Why |
|---|---|---|
| `queue:work --stop-when-empty --max-time=55` | every minute | Reconciles heartbeats. Exits before the next minute so two never overlap. |
| `telemetry:classify-sites` | 02:00 | Demotes sites silent for 30 days to inactive. |
| `telemetry:build-daily-stats` | 02:15 | The nightly rollup every chart reads. |
| `telemetry:detect-anomalies` | hourly | Logs traffic that has stopped looking like WordPress. |
| `telemetry:send-digests` | Mondays 08:00 | Weekly summary to each opted-in project's team. Runs after the rollup, not before. |

Everything is idempotent and recomputed from history, so a missed run costs a late number and
nothing else. Re-running a day never double-counts.

Check it took:

```sh
php artisan schedule:list
```

---

## 5. Create the first account

There is no public sign-up. Accounts are created by hand until the success test decides whether this
becomes a product.

```sh
php artisan tinker
```

```php
use App\Models\{Account, User, Project};

$account = Account::create(['name' => 'PluginizeLab', 'slug' => 'pluginizelab']);

$user = User::create([
    'name' => 'Your Name',
    'email' => 'you@example.com',
    'password' => 'a-long-random-password',   // hashed by the model cast
]);

$user->accounts()->attach($account, ['role' => 'owner']);

$project = Project::create([
    'account_id' => $account->id,
    'name'       => 'Metadata Viewer',
    'slug'       => 'metadata-viewer',
]);

echo $project->hash;   // this is what goes in the plugin
```

The panel is at `https://telemetry.pluginizelab.com/admin`.

`Project::created()` seeds the seven standard deactivation reasons automatically.

**Never run `telemetry:seed-demo` here.** It refuses outside local and testing, but the `--force`
flag exists and invented telemetry sitting alongside measured telemetry is a problem no later
cleanup fully undoes.

---

## 6. Verify the deploy

Do not trust a green deploy. Prove the whole path end to end.

```sh
# 1. Ingest accepts a heartbeat. Substitute the real hash.
curl -s -w '\n%{http_code} in %{time_total}s\n' -X POST \
  https://telemetry.pluginizelab.com/track \
  --data-urlencode 'hash=YOUR-PROJECT-HASH' \
  --data-urlencode 'url=https://deploy-check.test' \
  --data-urlencode 'admin_email=owner@deploy-check.test' \
  --data-urlencode 'project_version=0.0.1' \
  --data-urlencode 'is_local='
```

Expect `{"success":true}` and well under a second.

```sh
# 2. The scheduler picks it up. Wait a minute, then:
php artisan tinker --execute='
  echo "queued: ", DB::table("jobs")->count(), PHP_EOL;
  $s = App\Models\Site::acrossAccounts()->where("canonical_url","deploy-check.test")->first();
  echo $s ? "reconciled: {$s->status}" : "NOT RECONCILED", PHP_EOL;'
```

`queued: 0` and `reconciled: active`. If the job is still queued, `proc_open` is disabled or the
cron entry is not firing.

```sh
# 3. Nothing errored on the way.
php artisan tinker --execute='
  echo App\Models\RawPayload::acrossAccounts()->whereNotNull("error")->count(), " failed payloads", PHP_EOL;'
```

```sh
# 4. .env is not served. This must NOT return the file.
curl -sI https://telemetry.pluginizelab.com/.env | head -1
```

Expect `404`. Anything else means the document root is wrong — stop and fix it before going further.

Then remove the test site:

```sh
php artisan tinker --execute='
  App\Support\CurrentAccount::withoutScope(fn () =>
    App\Models\Site::where("canonical_url","deploy-check.test")->delete());'
```

Finally, sign in to `/admin` and confirm the project appears.

---

## 7. Subsequent deploys

```sh
# locally
npm run build
rsync -az --delete --exclude '.git' --exclude 'node_modules' --exclude '.env' \
  --exclude 'storage/logs/*' ./ user@server:~/digi-tracker/

# on the server
cd ~/digi-tracker
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan filament:assets
php artisan optimize:clear
php artisan optimize
php artisan filament:optimize
```

`optimize:clear` before `optimize` is not superstition: a stale route cache survives a deploy and
sends new requests to controllers that no longer exist.

There is no maintenance mode step. `php artisan down` would return 503 to ingest, and the SDK does
not retry — those heartbeats are simply lost. A few seconds of mixed old and new code is the better
trade for fire-and-forget telemetry.

### Rolling back

Migrations in this application are additive; none drop a column that live code still writes. To roll
back, redeploy the previous commit and re-run the cache commands. Leave the database alone —
`migrate:rollback` on a telemetry database destroys history that cannot be re-collected, because the
heartbeats that produced it were fire-and-forget.

---

## 8. GeoIP (optional)

Without it, `country` stays null and the country breakdown is empty. Ingest is unaffected.

1. Create a free MaxMind account and generate a licence key.
2. Download `GeoLite2-Country.mmdb`.
3. Upload to `storage/app/geoip/`.
4. Set `GEOIP_DATABASE` in `.env`, then `php artisan optimize:clear && php artisan optimize`.

The file goes stale. Add a monthly refresh to `routes/console.php` rather than a second crontab
entry, and keep the crontab at one line.

A missing or corrupt database is handled: the locator marks itself unavailable and returns null
rather than retrying on every row.

---

## 8a. Email (optional)

Nothing is sent until a project switches it on, and nothing is ever sent for a demo project.

```ini
MAIL_MAILER=postmark
POSTMARK_TOKEN=your-server-token
MAIL_FROM_ADDRESS="telemetry@pluginizelab.com"
MAIL_FROM_NAME="Digi Tracker"
```

Then `php artisan optimize:clear && php artisan optimize`.

**DKIM and Return-Path must be set up in Postmark for the sending domain**, or the auto-responder
lands in spam and the whole surface is worse than not having it. Mail leaves from *our* domain
wearing the author's name in `From` with their address in `Reply-To` — a platform cannot inherit
each customer's DKIM, and forging their domain without it fails DMARC.

Per-project settings live under **Project → Edit → Email**: from name, reply-to, support inbox,
footer, and three independent switches. All default to off.

### The one rule worth restating

Telemetry consent is consent to be **measured**, not consent to be **written to**. That is why the
auto-responder only ever goes to somebody who actually typed a comment, once per person per project,
ever. Mailing everyone who dismissed the dialog would use telemetry opt-in as cover for
correspondence nobody agreed to — the exact pattern the plan objects to in Appsero's
telemetry-to-Mailchimp flow.

### Suppression

`email_suppressions` is keyed by blind index, never plaintext — its whole purpose is that we stopped
writing to those people, so it is the last table that should hold a list of live addresses.

Unsubscribe needs no login and no confirmation click, and honours RFC 8058 one-click POST. A link
that makes somebody work for it is a link that gets the spam button instead.

To suppress by hand:

```sh
php artisan tinker --execute='app(App\Services\Mailer::class)->suppress(1, "person@example.com", "manual");'
```

Bounce and complaint webhooks from Postmark are **not** wired up yet. Until they are, a hard bounce
will be retried on the next occasion rather than suppressed.

## 9. Operating it

### Never rotate APP_KEY

`end_users.email`, `first_name` and `last_name` are encrypted with it, and `email_index` is an HMAC
keyed with it. Changing `APP_KEY`:

- makes every existing encrypted value permanently unreadable, and
- makes every existing blind index unmatchable, so email search silently returns nothing.

There is no re-encryption command, because the old plaintext is not recoverable once the key is
gone. Back the key up somewhere that is not this server, and treat it as immutable.

### Ingest has no authentication

The protocol has none. The `hash` is a plain body field and it is visible in the GPL source of every
plugin that ships it. Anyone who reads that source can post heartbeats.

Controls, in order of usefulness:

- **Throttling** — 60/hour per IP and 600/hour per hash (`AppServiceProvider::configureRateLimiting`).
- **Anomaly detection** — hourly, logs bursts of new sites, payload floods and single addresses
  speaking for hundreds of sites. Thresholds in `config/telemetry.php`.
- **Saying so.** The project overview states that counts are claimed, not proven. Leave it there.

Anomalies are logged, not blocked, deliberately: a genuine viral week and an attack look identical
from the server, and silently discarding real installs is the worse failure. Watch for them:

```sh
grep 'ingest anomaly' storage/logs/laravel.log
```

### Retention and deletion on request

The policy is indefinite retention with deletion on request. That obliges you to keep a monitored
inbox and to actually be able to delete.

```sh
# See what is held, without touching anything.
php artisan telemetry:forget --email='person@example.com' --dry-run

# Erase it.
php artisan telemetry:forget --email='person@example.com'

# Or one site, given in whatever form the request arrived in.
php artisan telemetry:forget --site='http://www.example.com/'
```

It covers `end_users`, `sites`, `site_reports`, `site_plugins`, `deactivations` **and
`raw_payloads`**. That last one is why this is a command rather than a snippet: nothing cascades to
`raw_payloads` — it sits outside the foreign-key graph on purpose, so a parser fix can be replayed
against stored history — which means a hand-rolled deletion leaves the original heartbeat, admin
email included, sitting in the database.

It deletes rather than anonymises. A hashed-out row still says a site existed, on that date, running
that plugin, and somebody asking to be forgotten did not ask to leave a silhouette.

An unknown address exits successfully with "Nothing held" — "we hold nothing about you" is a valid
answer to a deletion request, and you need to be able to say it without an error.

### Backups

cPanel's own backups are enough for the volume, but verify a restore before relying on it. Priority
if you have to choose: `.env` (the key), then `end_users`, `sites`, `deactivations` — the
irreplaceable rows. `daily_stats` can be rebuilt:

```sh
php artisan telemetry:build-daily-stats --days=180
```

### Health

```sh
curl -s https://telemetry.pluginizelab.com/up          # Laravel's health endpoint
php artisan schedule:list
php artisan tinker --execute='echo DB::table("jobs")->count(), " queued", PHP_EOL;'
```

A queue depth that grows over a few minutes means the worker is not running — check `proc_open` and
the cron entry, in that order.

---

## 10. Things that will bite

| Symptom | Cause |
|---|---|
| Heartbeats return 200, dashboard stays empty | `proc_open` disabled, or no cron entry. Check `DB::table('jobs')->count()`. |
| Email search finds nothing, names render as gibberish | `APP_KEY` changed. Unrecoverable. |
| Charts show zeros for a project with sites | The rollup has not run. `telemetry:build-daily-stats`. |
| New code, old behaviour | Cached config or routes. `optimize:clear` then `optimize`. |
| `/admin` 500s after deploy | `filament:assets` and `filament:optimize` not re-run. |
| A 404 from ingest on a valid hash | Project is inactive, or flagged `is_demo`. Both refuse telemetry by design. |
| Wrong PHP version under cron | cron's `PATH` differs from your shell's. Use the absolute binary. |
| Counts far below wordpress.org | Expected. That gap is the opt-in rate, not a bug. |
