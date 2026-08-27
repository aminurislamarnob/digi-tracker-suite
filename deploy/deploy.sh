#!/usr/bin/env bash
#
# Deploy to Namecheap cPanel.
#
# The layout is split, and deliberately so. cPanel pins the subdomain's
# document root to ~/space.pluginizelab.com and offers no way to move it
# from the shell, so the application lives at ~/digi-tracker -- outside the
# web root, where .env, vendor/ and storage/ cannot be fetched over HTTP --
# and only the contents of public/ are copied into the served directory.
#
# That means every deploy has two halves, which is exactly why this is a
# script rather than a list of commands in a runbook: doing one half and
# forgetting the other leaves a front controller and an application that
# disagree about what version is running.
#
# Usage:  bash deploy/deploy.sh [--no-build] [--dry-run]
#
set -euo pipefail

SSH_HOST="${SSH_HOST:-digitracker}"
APP_DIR="${APP_DIR:-/home/plugpxjv/digi-tracker}"
DOCROOT="${DOCROOT:-/home/plugpxjv/space.pluginizelab.com}"
PHP="${PHP:-/opt/alt/php83/usr/bin/php}"
COMPOSER="${COMPOSER:-\$HOME/bin/composer}"

BUILD=1
DRY=""

for arg in "$@"; do
    case "$arg" in
        --no-build) BUILD=0 ;;
        --dry-run)  DRY="--dry-run" ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

cd "$(dirname "$0")/.."

# ---------------------------------------------------------------------------
# 1. Assets are built here, not there.
#
# cPanel has no Node toolchain worth relying on, and a build is deterministic
# from the lockfile, so it belongs on the machine that has one.
# ---------------------------------------------------------------------------
if [ "$BUILD" = "1" ]; then
    say "Building assets locally"
    npm run build
fi

if [ ! -f public/build/manifest.json ]; then
    echo "public/build/manifest.json is missing -- run without --no-build" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Application code -> outside the web root.
#
# vendor/ is excluded: it is installed on the host so the platform check runs
# against the PHP that will actually serve requests. .env is excluded because
# the deployed one is authoritative -- overwriting it with a developer's copy
# would point production at a local database, or worse, replace APP_KEY and
# make every encrypted column unreadable.
# ---------------------------------------------------------------------------
say "Syncing application to $APP_DIR"
rsync -az --delete $DRY \
    --exclude '.git' \
    --exclude '.env' \
    --exclude 'node_modules' \
    --exclude 'vendor' \
    --exclude 'public' \
    --exclude 'storage/logs/*' \
    --exclude 'storage/framework/cache/data/*' \
    --exclude 'storage/framework/sessions/*' \
    --exclude 'storage/framework/views/*' \
    --exclude 'tests' \
    --exclude '.phpunit.result.cache' \
    ./ "$SSH_HOST:$APP_DIR/"

# ---------------------------------------------------------------------------
# 3. public/ -> the document root.
#
# .htaccess is excluded from the delete sweep and written separately below:
# the served copy carries the alt-php handler line, which is not in the repo
# copy and must survive a deploy.
# ---------------------------------------------------------------------------
say "Syncing public/ to $DOCROOT"
rsync -az --delete $DRY \
    --exclude '.htaccess' \
    --exclude '.well-known' \
    --exclude 'cgi-bin' \
    --exclude 'storage' \
    public/ "$SSH_HOST:$DOCROOT/"

# ---------------------------------------------------------------------------
# 3b. The build manifest also goes into the application.
#
# Blade's @vite reads the manifest from public_path(), which still points
# inside the application even though nothing there is served. Without a copy
# here every Filament page dies with "Vite manifest not found" -- and note
# that /up keeps answering 200 throughout, because the health check renders
# no view. A green health check is not a working dashboard.
#
# Only the manifest's directory is copied, not the served assets: the URLs
# it generates are root-relative, so the browser fetches them from the
# document root where they actually live.
# ---------------------------------------------------------------------------
say "Copying build manifest into $APP_DIR/public"
rsync -az --delete $DRY \
    public/build/ "$SSH_HOST:$APP_DIR/public/build/"

if [ -n "$DRY" ]; then
    say "Dry run complete -- nothing was changed"
    exit 0
fi

# ---------------------------------------------------------------------------
# 4. Repoint the front controller.
#
# The two require paths in public/index.php assume public/ sits inside the
# application. Here it does not, so they are rewritten to absolute paths.
# ---------------------------------------------------------------------------
say "Rewriting front controller paths"
ssh "$SSH_HOST" "sed -i \
    -e \"s#__DIR__\\.'/\\.\\./storage#'$APP_DIR/storage#\" \
    -e \"s#__DIR__\\.'/\\.\\./vendor#'$APP_DIR/vendor#\" \
    -e \"s#__DIR__\\.'/\\.\\./bootstrap#'$APP_DIR/bootstrap#\" \
    $DOCROOT/index.php && $PHP -l $DOCROOT/index.php"

say "Writing document-root .htaccess"
ssh "$SSH_HOST" "cat > $DOCROOT/.htaccess" <<'HTACCESS'
# PHP 8.3 for this document root only.
#
# The account default is 8.2 and four other live sites share it, so the
# version is pinned here rather than switched account-wide. cPanel's own
# per-domain selector fails on this account (it cannot create the cagefs
# directory it needs), which is why this line does the job instead.
#
# Laravel 13 requires 8.3+. Remove this and the site dies at the platform
# check, not gracefully.
AddHandler application/x-httpd-alt-php83___lsphp .php

<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
HTACCESS

# ---------------------------------------------------------------------------
# 5. Dependencies, migrations, caches.
#
# --no-dev keeps the dev toolchain off a shared host. The caches are cleared
# before being rebuilt because a stale config cache pinned to an older .env
# is the classic cause of "it deployed but it is still using the old
# database".
# ---------------------------------------------------------------------------
say "Installing dependencies"
ssh "$SSH_HOST" "cd $APP_DIR && $PHP $COMPOSER install --no-dev --optimize-autoloader --no-interaction --prefer-dist"

say "Running migrations"
ssh "$SSH_HOST" "cd $APP_DIR && $PHP artisan migrate --force"

say "Rebuilding caches"
ssh "$SSH_HOST" "cd $APP_DIR && \
    $PHP artisan optimize:clear && \
    $PHP artisan config:cache && \
    $PHP artisan route:cache && \
    $PHP artisan view:cache && \
    $PHP artisan filament:cache-components"

say "Publishing Filament assets"
# Filament's assets land in the application's public/, which is not served.
# Publish, then copy them across.
ssh "$SSH_HOST" "cd $APP_DIR && $PHP artisan filament:assets && \
    mkdir -p $DOCROOT/js $DOCROOT/css && \
    cp -r $APP_DIR/public/js/. $DOCROOT/js/ 2>/dev/null || true; \
    cp -r $APP_DIR/public/css/. $DOCROOT/css/ 2>/dev/null || true"

say "Linking storage"
# artisan storage:link would point into the unserved public/, so the link is
# made directly in the document root.
ssh "$SSH_HOST" "ln -sfn $APP_DIR/storage/app/public $DOCROOT/storage && ls -ld $DOCROOT/storage"

say "Deployed"
