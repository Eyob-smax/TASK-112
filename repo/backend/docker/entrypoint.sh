#!/usr/bin/env bash
set -e

# =============================================================================
# Meridian Docker Entrypoint
# =============================================================================
# Runs once at container startup before supervisor takes over.
# Performs: .env validation, key generation if missing, migrations, cache warm.
# =============================================================================

echo "[meridian] Starting entrypoint..."

# Validate required environment variables
if [ -z "$APP_KEY" ]; then
    echo "[meridian] APP_KEY is not set. Generating..."
    php /var/www/html/artisan key:generate --force
fi

if [ -z "$ATTACHMENT_ENCRYPTION_KEY" ]; then
    echo "[meridian] WARNING: ATTACHMENT_ENCRYPTION_KEY is not set. Attachment encryption will fail."
fi

# Re-apply writable permissions after Docker volumes are mounted.
mkdir -p \
    /var/www/html/storage/logs \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache

# Wait for MySQL to become available
echo "[meridian] Waiting for MySQL on ${DB_HOST}:${DB_PORT}..."
MAX_TRIES=30
TRIES=0
until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    TRIES=$((TRIES + 1))
    if [ $TRIES -ge $MAX_TRIES ]; then
        echo "[meridian] ERROR: MySQL not available after ${MAX_TRIES} attempts. Exiting."
        exit 1
    fi
    echo "[meridian] MySQL not ready yet (attempt ${TRIES}/${MAX_TRIES}). Retrying in 2s..."
    sleep 2
done
echo "[meridian] MySQL is available."

# Run database migrations (fresh if tables are in inconsistent state)
echo "[meridian] Running database migrations..."
if ! php /var/www/html/artisan migrate --force --no-interaction 2>/dev/null; then
    echo "[meridian] Migration failed — running fresh migration..."
    php /var/www/html/artisan migrate:fresh --force --no-interaction
fi

# Warm application cache (skip for testing — tests need fresh config per run)
if [ "$APP_ENV" != "testing" ]; then
    echo "[meridian] Warming application cache..."
    php /var/www/html/artisan config:cache
    php /var/www/html/artisan route:cache
    if [ -d /var/www/html/resources/views ]; then
        php /var/www/html/artisan view:cache
    else
        echo "[meridian] Skipping view cache; /var/www/html/resources/views does not exist."
    fi
else
    echo "[meridian] Skipping cache warm-up (testing environment)."
    php /var/www/html/artisan config:clear 2>/dev/null || true
    php /var/www/html/artisan route:clear 2>/dev/null || true
    # Ensure .env exists so Laravel's Dotenv loader doesn't warn
    touch /var/www/html/.env
fi

echo "[meridian] Entrypoint complete. Starting supervisor..."

# Hand off to supervisord (or any passed CMD)
exec "$@"
