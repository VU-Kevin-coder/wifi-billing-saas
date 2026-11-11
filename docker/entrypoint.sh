#!/bin/sh
set -e

# ─────────────────────────────────────────────────────────────
# Provision the local-development WireGuard interface when enabled.
# The private key is retained in the named Docker volume.
# ─────────────────────────────────────────────────────────────
if [ "${WIREGUARD_ENABLED:-false}" = "true" ]; then
    interface="${WIREGUARD_INTERFACE:-wg0}"
    server_address="${WIREGUARD_SERVER_ADDRESS:-10.8.0.1}"
    listen_port="${WIREGUARD_LISTEN_PORT:-51820}"
    key_file="/var/lib/wireguard/server.key"

    case "$interface" in
        ''|*[!a-zA-Z0-9_.-]*)
            echo "ERROR: Invalid WIREGUARD_INTERFACE." >&2
            exit 1
            ;;
    esac

    command -v wg >/dev/null 2>&1 || { echo "ERROR: wireguard-tools is required." >&2; exit 1; }
    command -v ip >/dev/null 2>&1 || { echo "ERROR: iproute2 is required." >&2; exit 1; }
    mkdir -p /var/lib/wireguard
    chmod 700 /var/lib/wireguard
    if [ ! -s "$key_file" ]; then
        (umask 077; wg genkey > "$key_file")
        chmod 600 "$key_file"
    fi

    if ! ip link show dev "$interface" >/dev/null 2>&1; then
        ip link add dev "$interface" type wireguard
    fi
    wg set "$interface" private-key "$key_file" listen-port "$listen_port"
    ip address replace "${server_address}/24" dev "$interface"
    ip link set up dev "$interface"
    ip route replace 10.8.0.0/16 dev "$interface"
fi

# ─────────────────────────────────────────────────────────────
# Wait for MySQL to be ready (belt-and-braces — healthcheck
# already handles most of this, but Composer scripts may race)
# ─────────────────────────────────────────────────────────────
if [ -n "${DB_HOST:-}" ]; then
    echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
    max_attempts=30
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if php -r "
            \$h = getenv('DB_HOST');
            \$p = getenv('DB_PORT') ?: '3306';
            \$u = getenv('DB_USERNAME');
            \$pw = getenv('DB_PASSWORD');
            try {
                new PDO(\"mysql:host=\$h;port=\$p\", \$u, \$pw);
                exit(0);
            } catch (PDOException \$e) {
                exit(1);
            }
        "; then
            echo "MySQL is ready."
            break
        fi
        attempt=$((attempt + 1))
        echo "  Attempt ${attempt}/${max_attempts} failed — retrying in 2s..."
        sleep 2
    done
    if [ $attempt -ge $max_attempts ]; then
        echo "ERROR: MySQL did not become ready in time." >&2
        exit 1
    fi
fi

if [ "${WIREGUARD_ENABLED:-false}" = "true" ]; then
    php artisan wireguard:sync-peers &
fi

# ─────────────────────────────────────────────────────────────
# Fix storage permissions (host may have changed ownership)
# ─────────────────────────────────────────────────────────────
mkdir -p storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# These caches can be environment-specific or stale on the bind-mounted
# development worktree. Remove only generated bootstrap caches before FPM starts.
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php

# ─────────────────────────────────────────────────────────────
# Run the CMD (default: php-fpm)
# ─────────────────────────────────────────────────────────────
exec "$@"