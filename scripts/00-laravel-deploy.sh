#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# 00-laravel-deploy.sh  — production pre-start bootstrap for Hamnet
# Runs once on every web container boot.
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
    storage/app/public \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# ── 2. Wait for the database ──────────────────────────────────────────────────
DATABASE_CONNECTION_URL="${DB_URL:-${DATABASE_URL:-}}"
if [ -n "$DATABASE_CONNECTION_URL" ] || [ -n "${DB_HOST:-}" ]; then
    echo ">>> Waiting for the configured database..."
    max_attempts=30
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if php -r '
            $url = getenv("DB_URL") ?: getenv("DATABASE_URL");
            $driver = getenv("DB_CONNECTION") ?: "mysql";
            if ($url !== false && $url !== "") {
                $parts = parse_url($url);
                if (! is_array($parts)) {
                    exit(1);
                }
                if (in_array($parts["scheme"] ?? "", ["postgres", "postgresql"], true)) {
                    $driver = "pgsql";
                }
                $host = $parts["host"] ?? "";
                $port = $parts["port"] ?? ($driver === "pgsql" ? 5432 : 3306);
                $user = rawurldecode($parts["user"] ?? "");
                $password = rawurldecode($parts["pass"] ?? "");
                $database = ltrim(rawurldecode($parts["path"] ?? ""), "/");
                $query = [];
                parse_str($parts["query"] ?? "", $query);
            } else {
                $host = getenv("DB_HOST") ?: "";
                $port = getenv("DB_PORT") ?: ($driver === "pgsql" ? "5432" : "3306");
                $user = getenv("DB_USERNAME") ?: "";
                $password = getenv("DB_PASSWORD") ?: "";
                $database = getenv("DB_DATABASE") ?: "";
                $query = [];
            }
            try {
                if ($driver === "pgsql") {
                    $dsn = "pgsql:host=$host;port=$port;dbname=$database";
                    if (isset($query["sslmode"])) {
                        $dsn .= ";sslmode=".$query["sslmode"];
                    }
                    new PDO($dsn, $user, $password);
                } else {
                    new PDO("mysql:host=$host;port=$port;dbname=$database", $user, $password);
                }
                exit(0);
            } catch (PDOException) {
                exit(1);
            }
        '; then
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

# Public disk uploads must point to the mounted Railway volume when enabled.
php artisan storage:link --force

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

