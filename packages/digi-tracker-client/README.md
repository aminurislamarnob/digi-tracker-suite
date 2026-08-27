# pluginizelab/digi-tracker-client

WordPress telemetry client for [Digi Tracker Suite](../../README.md). Reports opt-in usage data to
your own server instead of a vendor's.

A fork of [appsero/client](https://github.com/Appsero/client) v2.0.4 (MIT), redistributed under
GPL-2.0-or-later so it can be bundled in wordpress.org plugins.

---

## Why this is renamed rather than a drop-in replacement

`dokan-lite` bundles `appsero/client` **unscoped, in the global `Appsero\` namespace**, and the
standard integration guard is `if ( ! class_exists( 'Appsero\Client' ) )`.

On any site running two plugins that bundle the SDK, whichever loads first wins. Had this fork kept
the `Appsero\` namespace, then on a Dokan site Dokan's unmodified client would load first, our
plugin would silently use it, and **our telemetry would go to Appsero's servers** — with no error,
no warning, and nothing in the logs.

Renaming the namespace eliminates that entire class of failure. The hooks are renamed for the same
reason: with a shared `appsero_endpoint` filter, one plugin redirecting its own telemetry would
redirect everyone's.

## What changed from upstream

| Change | Why |
|---|---|
| Namespace → `PluginizeLab\DigiTracker` | See above. |
| Filters → `digi_tracker_endpoint`, `digi_tracker_is_local`, `digi_tracker_custom_deactivation_reasons` | Same collision risk as the namespace. |
| Default endpoint → `https://telemetry.pluginizelab.com` | |
| **`icanhazip.com` lookup removed** | Upstream asks a third party for the site's own public IP on every heartbeat — an undisclosed outbound request from the customer's server to a host named in no privacy policy. The receiving endpoint already sees the address; country is derived there. `ip_address` stays in the payload, always empty, for wire compatibility. |
| Consent text names Digi Tracker and links our policy | It is the disclosure that makes this legal under wordpress.org Guideline 7. Naming the wrong service would make it worthless. |
| `user-agent` → `DigiTracker/<md5(home_url)>;` | Honesty. The server's fingerprint regex accepts either prefix. |
| `client` version → `dt-1.0.0` | A server accepting both clients can tell which produced a payload. Bare version numbers would collide. |
| `Client::updater()` and `Client::license()` removed | Out of scope — the plugins are free and wordpress.org serves their updates. Dead accessors requiring classes we do not ship would only produce fatal errors. |

**The wire format is otherwise byte-identical**, so a server built for this fork also accepts
unmodified `appsero/client` traffic.

## Usage

```php
require_once __DIR__ . '/vendor/autoload.php';

$client = new \PluginizeLab\DigiTracker\Client(
    'your-project-uuid',        // the hash from the dashboard
    'Your Plugin Name',
    __FILE__
);

$insights = $client->insights();

$insights->add_plugin_data();   // optional: names of other active plugins
$insights->init();
```

### Never do these

```php
$insights->send_tracking_data( true );   // bypasses consent entirely
update_option( 'your-slug_allow_tracking', 'yes' );  // pre-consents for the user
```

wordpress.org Guideline 7 prohibits phoning home without explicit consent. The opt-in notice is what
makes this plugin compliant; both lines above defeat it and are grounds for removal from the
directory.

You will find `send_tracking_data( true )` inside the SDK itself, in `activate_plugin()`. That is
safe, and not a precedent: the `$override` flag skips the *consent* check, and `activate_plugin()`
performs its own consent check first, returning early for anyone who has not opted in. All the flag
does there is let a reactivating site send immediately rather than waiting out the once-a-week
limit. Called from your own code, with no such guard in front of it, it sends data from people who
declined.

## Hooks

| Hook | Purpose |
|---|---|
| `digi_tracker_endpoint` | Redirect telemetry to another host. |
| `digi_tracker_is_local` | Override local-site detection. Useful when testing against a `.test` domain — see below. |
| `digi_tracker_privacy_policy_url` | Point the consent notice at a different policy. |
| `digi_tracker_homepage_url` | Link shown in the deactivation dialog. |
| `digi_tracker_custom_deactivation_reasons` | Replace the seven default reasons. |

### Testing against a local site

`Insights::is_local_server()` treats `.test`, `.local`, `.localhost` and loopback addresses as local
and sets `is_local=1` on the payload. The receiving server excludes those from install counts, which
is correct in production and inconvenient while testing:

```php
add_filter( 'digi_tracker_is_local', '__return_false' );
```

## Licence

GPL-2.0-or-later. Forked from `appsero/client`, © Tareq Hasan, MIT — a permissive licence, so
redistribution under GPL is allowed. The MIT notice is retained in `LICENSE`.

## Re-syncing with upstream

The fork is a deliberately small diff — about 60 changed lines per file — so upstream fixes can be
pulled in by re-applying the same renames rather than by merging.

**Formatting is left exactly as upstream wrote it**, in WordPress Coding Standards style, and
`pint.json` excludes `packages/` for that reason. Reformatting to Laravel style would turn every
future upstream diff into noise and hide the handful of lines that actually differ.

`tests/Unit/SdkForkTest.php` guards the renames that carry consequences. Run it after any re-sync.
