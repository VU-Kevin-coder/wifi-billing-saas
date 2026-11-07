#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# 00-laravel-deploy.sh  — Render pre-start bootstrap for Hamnet
# Runs once on every container boot (Render restarts on each deploy).
# ─────────────────────────────────────────────────────────────────────────────
set -e

echo ">>> [Hamnet Deploy] Starting bootstrap..."

# ── 0. Refuse to boot with unsafe production settings ─────────────────────────
/usr/local/bin/preflight.sh web

# ── 1. Storage permissions ────────────────────────────────────────────────────
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# ── 2. Wait for the database ──────────────────────────────────────────────────
if [ -n "${DB_HOST:-}" ]; then
    echo ">>> Waiting for database at ${DB_HOST}:${DB_PORT:-3306}..."
    max_attempts=30
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        # Try MySQL first, then PostgreSQL
        if php -r "
            \$h  = getenv('DB_HOST');
            \$p  = getenv('DB_PORT') ?: '3306';
            \$u  = getenv('DB_USERNAME');
            \$pw = getenv('DB_PASSWORD');
            \$db = getenv('DB_DATABASE') ?: '';
            \$drv = getenv('DB_CONNECTION') ?: 'mysql';
            try {
                if (\$drv === 'pgsql') {
                    new PDO(\"pgsql:host=\$h;port=\$p;dbname=\$db\", \$u, \$pw);
                } else {
                    new PDO(\"mysql:host=\$h;port=\$p\", \$u, \$pw);
                }
                exit(0);
            } catch (PDOException \$e) {
                exit(1);
            }
        "; then
            echo ">>> Database is ready."
            break
        fi
        attempt=$((attempt + 1))
        echo "    Attempt ${attempt}/${max_attempts} — retrying in 2s..."
        sleep 2
    done
    if [ $attempt -ge $max_attempts ]; then
        echo "ERROR: Database did not become ready in time." >&2
        exit 1
    fi
fi

# ── 3. Generate app key if missing ────────────────────────────────────────────
if [ -z "${APP_KEY:-}" ] || [ "${APP_KEY}" = "base64:" ]; then
    echo ">>> Generating APP_KEY..."
    php artisan key:generate --force
fi

# ── 4. Run migrations ─────────────────────────────────────────────────────────
echo ">>> Running migrations..."
php artisan migrate --force --no-interaction

# ── 5. Seed platform admin (only on first boot — idempotent guard inside seeder)
# Uncomment once you have a DatabaseSeeder guard in place:
# php artisan db:seed --class=PlatformAdminSeeder --force --no-interaction

# ── 6. Warm Laravel caches ────────────────────────────────────────────────────
echo ">>> Warming caches..."
php artisan config:cache  --quiet || true
php artisan route:cache   --quiet || true
php artisan view:cache    --quiet || true
php artisan event:cache   --quiet || true

echo ">>> [Hamnet Deploy] Bootstrap complete. Starting services..."

