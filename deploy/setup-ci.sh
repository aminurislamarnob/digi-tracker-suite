#!/usr/bin/env bash
#
# One-time setup of the GitHub Actions deploy credentials.
#
# Run this once, from a machine that can already reach the host. It creates
# the CI key, pins the host key, and loads all five secrets into the
# repository.
#
# The private key never leaves this machine except as a GitHub secret, which
# is write-only once set -- not even the repository owner can read it back.
# That is the reason this is a script you run rather than values pasted into
# a chat window or an issue.
#
# Usage:  bash deploy/setup-ci.sh [--no-environment]
#
set -euo pipefail

REPO="${REPO:-aminurislamarnob/digi-tracker-suite}"

# Defaults from cPanel -> General Information, and DEPLOY.md section 0a.
#
# The shared IP rather than server219.web-hosting.com: section 0a recorded
# that the hostname resolves to the server's primary address and this account
# lives on a different one. The IP is the address that is unambiguously ours.
SSH_HOSTNAME="${SSH_HOSTNAME:-198.54.116.227}"
SSH_USER="${SSH_USER:-plugpxjv}"
SSH_PORT="${SSH_PORT:-21098}"

KEY="${KEY:-$HOME/.ssh/digitracker_ci}"

MAKE_ENVIRONMENT=1
[ "${1:-}" = "--no-environment" ] && MAKE_ENVIRONMENT=0

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$1"; }
die()  { printf '\033[1;31m!!  %s\033[0m\n' "$1" >&2; exit 1; }

command -v gh >/dev/null || die "gh is not installed -- see https://cli.github.com"
gh auth status >/dev/null 2>&1 || die "gh is not authenticated -- run: gh auth login"

# ---------------------------------------------------------------------------
# 1. The key.
#
# Dedicated to CI and passphrase-less, which is unavoidable: no human is
# present to type one. That is precisely why it is not your key -- if it
# leaks you revoke one line in authorized_keys and your own access is
# untouched.
# ---------------------------------------------------------------------------
if [ -f "$KEY" ]; then
    say "Reusing existing key at $KEY"
else
    say "Creating CI key at $KEY"
    ssh-keygen -t ed25519 -f "$KEY" -C "github-actions-deploy" -N ""
fi

# ---------------------------------------------------------------------------
# 2. Is it authorised on the host yet?
#
# Checked before the secrets are written, because a green write on a key the
# server rejects produces a workflow that fails at the first rsync with an
# error that reads like a network fault.
# ---------------------------------------------------------------------------
say "Testing $SSH_USER@$SSH_HOSTNAME:$SSH_PORT"

if ssh -i "$KEY" -p "$SSH_PORT" \
       -o IdentitiesOnly=yes \
       -o BatchMode=yes \
       -o ConnectTimeout=15 \
       -o StrictHostKeyChecking=accept-new \
       "$SSH_USER@$SSH_HOSTNAME" true 2>/dev/null; then
    echo "    key accepted"
else
    warn "The host did not accept this key yet."
    echo
    echo "    Authorise it, then re-run this script:"
    echo
    echo "      ssh-copy-id -i $KEY.pub -p $SSH_PORT $SSH_USER@$SSH_HOSTNAME"
    echo
    echo "    Or paste this into cPanel -> SSH Access -> Manage SSH Keys ->"
    echo "    Import, then Manage -> Authorize. An imported key that is not"
    echo "    authorised fails exactly like a wrong key:"
    echo
    sed 's/^/      /' "$KEY.pub"
    echo
    die "Stopping before writing credentials."
fi

# ---------------------------------------------------------------------------
# 3. Pin the host key.
#
# Scanned here, once, from a machine you trust -- never in the workflow.
# Scanning at run time trusts whatever answers, which is the one thing
# known_hosts exists to prevent.
#
# The entry is written [address]:port because the port is not 22, and it only
# matches when dialled on that port. This is why SSH_HOSTNAME and the scanned
# address have to be the same string.
# ---------------------------------------------------------------------------
say "Pinning host key for [$SSH_HOSTNAME]:$SSH_PORT"

KNOWN_HOSTS="$(ssh-keyscan -p "$SSH_PORT" "$SSH_HOSTNAME" 2>/dev/null)"
[ -n "$KNOWN_HOSTS" ] || die "ssh-keyscan returned nothing for $SSH_HOSTNAME:$SSH_PORT"

echo "$KNOWN_HOSTS" | awk '{print "    " $1 " " $2}'

# ---------------------------------------------------------------------------
# 4. Load the five values into the repository.
# ---------------------------------------------------------------------------
say "Writing to $REPO"

gh secret set SSH_PRIVATE_KEY --repo "$REPO" < "$KEY"
printf '%s\n' "$KNOWN_HOSTS" | gh secret set SSH_KNOWN_HOSTS --repo "$REPO"
printf '%s' "$SSH_HOSTNAME"  | gh secret set SSH_HOSTNAME    --repo "$REPO"
printf '%s' "$SSH_USER"      | gh secret set SSH_USER        --repo "$REPO"
printf '%s' "$SSH_PORT"      | gh secret set SSH_PORT        --repo "$REPO"

gh secret list --repo "$REPO" | sed 's/^/    /'

# ---------------------------------------------------------------------------
# 5. The production environment.
#
# The deploy job declares `environment: production`, which GitHub creates
# implicitly on first run with no protection at all. Created here instead so
# the approval gate exists before the first deploy rather than after it.
#
# Required reviewers need a paid plan on a private repository. If that call
# is refused the environment is still created, just ungated, and the deploy
# will run without waiting for anybody.
# ---------------------------------------------------------------------------
if [ "$MAKE_ENVIRONMENT" = "1" ]; then
    say "Creating the production environment"

    USER_ID="$(gh api /user --jq .id)"

    if gh api --method PUT "repos/$REPO/environments/production" \
         --input - >/dev/null 2>&1 <<JSON
{"reviewers":[{"type":"User","id":$USER_ID}],"deployment_branch_policy":null}
JSON
    then
        echo "    created, gated on your approval"
    else
        gh api --method PUT "repos/$REPO/environments/production" >/dev/null
        warn "Created WITHOUT required reviewers -- the API refused the gate."
        warn "On a private repo that needs a paid plan. Deploys will not wait."
    fi
fi

say "Done"
cat <<'NEXT'

    The workflow triggers on a push to main, so nothing runs until the
    branch is merged. Before merging, check:

      - all five values are listed above
      - Settings -> Environments -> production shows a required reviewer,
        if you wanted the gate

    The first run is unproven. Watch it rather than walking away.

NEXT
