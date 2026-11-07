#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# preflight.sh — refuse to boot a production container with unsafe settings.
# Usage: preflight.sh [web|worker]      (default: web)
# Only enforced when APP_ENV=production. Exit 1 = do not start.
# ─────────────────────────────────────────────────────────────────────────────
ROLE="${1:-web}"
FAIL=0

fail() { echo "PREFLIGHT FAIL: $1" >&2; FAIL=1; }
warn() { echo "PREFLIGHT WARN: $1" >&2; }

if [ "${APP_ENV:-}" != "production" ]; then
    echo "Preflight skipped (APP_ENV=${APP_ENV:-unset})."
    exit 0
fi

[ "${APP_DEBUG:-false}" = "true" ] && fail "APP_DEBUG must be false in production."
[ -z "${APP_KEY:-}" ] && fail "APP_KEY is not set."

if [ "$ROLE" = "web" ]; then
    case "${APP_URL:-}" in
        https://*) ;;
        *) fail "APP_URL must start with https://." ;;
    esac

    [ "${SESSION_SECURE_COOKIE:-}" != "true" ] && fail "SESSION_SECURE_COOKIE must be true."

    if [ -z "${TENANT_BASE_DOMAIN:-}" ] || [ "${TENANT_BASE_DOMAIN}" = "localhost" ]; then
        fail "TENANT_BASE_DOMAIN must be set to your real base domain (tenant subdomains depend on it)."
    fi

    [ "${SITE_AGENT_REQUIRED:-true}" = "false" ] && fail "SITE_AGENT_REQUIRED must not be false in production."

    case "${SESSION_DRIVER:-}" in
        array) fail "SESSION_DRIVER=array loses every session." ;;
        file)  warn "SESSION_DRIVER=file is lost on redeploy and cannot be shared across instances." ;;
    esac

    case "${MAIL_MAILER:-log}" in
        log|array) warn "MAIL_MAILER=${MAIL_MAILER:-log}: staff invitations and password resets will not be delivered." ;;
    esac

    [ "${PLATFORM_ADMIN_MFA_REQUIRED:-true}" = "false" ] && warn "PLATFORM_ADMIN_MFA_REQUIRED=false: platform admins are not forced to use 2FA."
    [ -z "${TRUSTED_PROXIES:-}" ] && warn "TRUSTED_PROXIES is empty: client IPs (and rate limits) will be the proxy's address."
fi

if [ "$FAIL" -ne 0 ]; then
    echo "Refusing to start: fix the settings above." >&2
    exit 1
fi

echo "Preflight OK ($ROLE)."
