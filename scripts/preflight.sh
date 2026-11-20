#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# preflight.sh — refuse to boot a production container with unsafe settings.
# Usage: preflight.sh [web|worker]      (default: web)
# Production checks are enforced generally; Railway's app-only boundary is
# enforced in every environment. Exit 1 = do not start.
# ─────────────────────────────────────────────────────────────────────────────
ROLE="${1:-web}"
FAIL=0

fail() { echo "PREFLIGHT FAIL: $1" >&2; FAIL=1; }
warn() { echo "PREFLIGHT WARN: $1" >&2; }
is_enabled() {
    case "$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]')" in
        1|true|'(true)'|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

IS_RAILWAY=false
if [ -n "${RAILWAY_PROJECT_ID:-}" ]; then
    IS_RAILWAY=true
    [ "${APP_ENV:-}" != "production" ] && fail "APP_ENV=production is required on Railway."
    [ "${TENANT_URL_MODE:-subdomain}" != "path" ] && fail "TENANT_URL_MODE=path is required on Railway."
    is_enabled "${WIREGUARD_ENABLED:-false}" && fail "WireGuard router support is not available in the Railway app-only profile."
    is_enabled "${RADIUS_ENABLED:-false}" && fail "RADIUS router support is not available in the Railway app-only profile."
    is_enabled "${RADIUS_PRODUCTION_ENABLED:-false}" && fail "RADIUS production support is not available in the Railway app-only profile."
    is_enabled "${ALLOW_DIRECT_ROUTER_CONNECTION:-false}" && fail "Direct router connections are not available in the Railway app-only profile."
fi

if [ "${APP_ENV:-}" != "production" ]; then
    if [ "$FAIL" -ne 0 ]; then
        echo "Refusing to start: fix the settings above." >&2
        exit 1
    fi

    echo "Preflight skipped (APP_ENV=${APP_ENV:-unset})."
    exit 0
fi

is_enabled "${APP_DEBUG:-false}" && fail "APP_DEBUG must be false in production."
case "${APP_KEY:-}" in
    ""|"base64:") fail "APP_KEY must be set to a stable, generated key." ;;
esac

case "${TENANT_URL_MODE:-subdomain}" in
    path|subdomain) ;;
    *) fail "TENANT_URL_MODE must be 'path' or 'subdomain'." ;;
esac

if [ "$ROLE" = "web" ]; then
    case "${APP_URL:-}" in
        https://*) ;;
        *) fail "APP_URL must start with https://." ;;
    esac

    [ "${SESSION_SECURE_COOKIE:-}" != "true" ] && fail "SESSION_SECURE_COOKIE must be true."

    if [ "$IS_RAILWAY" = "false" ] && [ "${TENANT_URL_MODE:-subdomain}" = "subdomain" ]; then
        if [ -z "${TENANT_BASE_DOMAIN:-}" ] || [ "${TENANT_BASE_DOMAIN}" = "localhost" ]; then
            fail "TENANT_BASE_DOMAIN must be set to your real base domain (tenant subdomains depend on it)."
        fi
    fi

    if [ "${SITE_AGENT_REQUIRED+x}" = "x" ]; then
        site_agent_required="$SITE_AGENT_REQUIRED"
    else
        site_agent_required=true
    fi

    if ! is_enabled "$site_agent_required"; then
        fail "SITE_AGENT_REQUIRED must be true in production."
    fi

    if [ "$IS_RAILWAY" = "true" ]; then
        [ "${DB_CONNECTION:-}" != "pgsql" ] && fail "DB_CONNECTION=pgsql is required for the Railway PostgreSQL service."
        [ -z "${DB_URL:-${DATABASE_URL:-}}" ] && fail "Set DB_URL or DATABASE_URL to the Railway PostgreSQL connection URL."
        [ -z "${REDIS_URL:-}" ] && fail "REDIS_URL is required for Railway sessions, cache, and queue."
        [ "${SESSION_DRIVER:-}" != "redis" ] && fail "SESSION_DRIVER=redis is required on Railway."
        [ "${CACHE_STORE:-}" != "redis" ] && fail "CACHE_STORE=redis is required on Railway."
        [ "${QUEUE_CONNECTION:-}" != "redis" ] && fail "QUEUE_CONNECTION=redis is required on Railway."
    fi

    case "${SESSION_DRIVER:-}" in
        array) fail "SESSION_DRIVER=array loses every session." ;;
        file)  warn "SESSION_DRIVER=file is lost on redeploy and cannot be shared across instances." ;;
    esac

    case "${MAIL_MAILER:-log}" in
        log|array) warn "MAIL_MAILER=${MAIL_MAILER:-log}: staff invitations and password resets will not be delivered." ;;
    esac

    [ "${PLATFORM_ADMIN_MFA_REQUIRED:-true}" = "false" ] && warn "PLATFORM_ADMIN_MFA_REQUIRED=false: platform admins are not forced to use 2FA."
    if [ "$IS_RAILWAY" = "true" ]; then
        [ "${TRUSTED_PROXIES:-}" != "*" ] && fail "Set TRUSTED_PROXIES=* for Railway's edge proxy; do not expose an alternate direct origin."
    elif [ -z "${TRUSTED_PROXIES:-}" ]; then
        fail "TRUSTED_PROXIES must contain the exact trusted proxy IPs/CIDRs."
    elif [ "${TRUSTED_PROXIES}" = "*" ]; then
        fail "TRUSTED_PROXIES must not trust every source; use the exact proxy IPs/CIDRs."
    fi
fi

if [ "$ROLE" = "worker" ] && [ "$IS_RAILWAY" = "true" ]; then
    [ "${QUEUE_CONNECTION:-}" != "redis" ] && fail "QUEUE_CONNECTION=redis is required for Railway workers."
    [ -z "${REDIS_URL:-}" ] && fail "REDIS_URL is required for Railway workers."
    [ "${DB_CONNECTION:-}" != "pgsql" ] && fail "DB_CONNECTION=pgsql is required for Railway workers."
    [ -z "${DB_URL:-${DATABASE_URL:-}}" ] && fail "Set DB_URL or DATABASE_URL for Railway workers."
    case "${APP_URL:-}" in
        https://*) ;;
        *) fail "APP_URL must start with https:// on Railway workers." ;;
    esac
fi

if [ "$FAIL" -ne 0 ]; then
    echo "Refusing to start: fix the settings above." >&2
    exit 1
fi

echo "Preflight OK ($ROLE)."
