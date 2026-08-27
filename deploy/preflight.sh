#!/usr/bin/env bash
#
# Read-only host check. Run over SSH on the Namecheap box before deploying.
#
#   bash preflight.sh
#
# Changes nothing. Every failure below is something that would otherwise
# surface as a half-working deploy: telemetry accepted but never processed,
# or a dashboard that 500s on its first chart.

set -uo pipefail

pass=0
fail=0

ok()   { printf '  \033[32mok\033[0m    %s\n' "$1"; pass=$((pass + 1)); }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; fail=$((fail + 1)); }
warn() { printf '  \033[33mwarn\033[0m  %s\n' "$1"; }

php_bin="${PHP_BIN:-php}"

echo
echo "Digi Tracker Suite — host preflight"
echo "===================================="
echo

# --- PHP -------------------------------------------------------------------
#
# cPanel usually ships several PHP binaries and the one on $PATH is often not
# the one the web server uses. If this reports the wrong version, find the
# right binary (ls /opt/cpanel/ea-php*/root/usr/bin/php) and re-run with
# PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php bash preflight.sh

echo "PHP"

if ! command -v "$php_bin" >/dev/null 2>&1; then
    bad "no php binary found on PATH (set PHP_BIN=/path/to/php)"
else
    version=$("$php_bin" -r 'echo PHP_VERSION;')

    if "$php_bin" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
        ok "php $version (composer.json requires ^8.3)"
    else
        bad "php $version — this application requires 8.3 or newer"
    fi

    echo "        $(command -v "$php_bin")"
fi

echo

# --- Extensions ------------------------------------------------------------

echo "Extensions"

# Asked of PHP directly rather than by grepping `php -m`. Under `set -o
# pipefail`, `php -m | grep -q` reports a false failure whenever grep matches
# early and exits: php then dies on SIGPIPE and poisons the pipeline status.
for ext in pdo_mysql mbstring openssl tokenizer xml ctype json bcmath fileinfo curl zip; do
    if "$php_bin" -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null; then
        ok "$ext"
    else
        bad "$ext missing"
    fi
done

echo

# --- proc_open -------------------------------------------------------------
#
# The scheduler runs the queue worker with runInBackground(), which shells out.
# Without proc_open the worker never starts: heartbeats would be accepted and
# stored, and then sit in the jobs table forever with nothing to notice.

echo "Background processes"

if "$php_bin" -r 'exit(function_exists("proc_open") && ! in_array("proc_open", array_map("trim", explode(",", (string) ini_get("disable_functions"))), true) ? 0 : 1);'; then
    ok "proc_open available (scheduler can run the queue worker)"
else
    bad "proc_open disabled — the queue would never drain"
    warn "ask Namecheap to remove proc_open from disable_functions"
fi

echo

# --- Cron ------------------------------------------------------------------

echo "Cron"

if command -v crontab >/dev/null 2>&1; then
    ok "crontab present"

    if crontab -l >/dev/null 2>&1; then
        entries=$(crontab -l 2>/dev/null | grep -c 'schedule:run' || true)

        if [ "$entries" -gt 0 ]; then
            ok "schedule:run already installed"
        else
            warn "no schedule:run entry yet — one line, once:"
            warn "  * * * * * cd \$HOME/digi-tracker && $php_bin artisan schedule:run >> /dev/null 2>&1"
        fi
    else
        warn "crontab -l returned nothing (may simply be empty)"
    fi
else
    bad "no crontab — nothing would ever fire the scheduler"
fi

echo

# --- Composer --------------------------------------------------------------

echo "Composer"

if command -v composer >/dev/null 2>&1; then
    ok "composer $(composer --version --no-ansi 2>/dev/null | awk '{print $3}')"
elif [ -f "$HOME/composer.phar" ]; then
    ok "composer.phar in \$HOME"
else
    warn "composer not found — install it, or upload vendor/ with the deploy"
fi

echo

# --- MySQL -----------------------------------------------------------------

echo "MySQL"

if command -v mysql >/dev/null 2>&1; then
    ok "mysql client present ($(mysql --version | awk '{print $3}'))"
else
    warn "no mysql client on PATH — fine, migrations go through PHP"
fi

echo
echo "===================================="
printf '%d passed, %d failed\n' "$pass" "$fail"
echo

if [ "$fail" -gt 0 ]; then
    echo "Resolve the failures above before deploying. Each one produces a"
    echo "deploy that looks healthy and quietly loses telemetry."
    exit 1
fi

echo "Host looks ready."
