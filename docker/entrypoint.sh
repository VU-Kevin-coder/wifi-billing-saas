#!/bin/sh
set -e

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

# Ensure public storage link exists
php artisan storage:link || true

# ─────────────────────────────────────────────────────────────
# Warm Laravel caches (config, routes, views, events)
# This makes every request faster by pre-compiling everything.
# ─────────────────────────────────────────────────────────────
echo "Warming Laravel caches..."
php artisan config:cache  --quiet || true
php artisan route:cache   --quiet || true
php artisan view:cache    --quiet || true
php artisan event:cache   --quiet || true
echo "Cache warming complete."

# ─────────────────────────────────────────────────────────────
# Run the CMD (default: php-fpm)
# ─────────────────────────────────────────────────────────────
exec "$@"