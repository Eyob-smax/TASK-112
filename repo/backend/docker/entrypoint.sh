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

# Run database migrations
echo "[meridian] Running database migrations..."
php /var/www/html/artisan migrate --force --no-interaction

# Warm application cache
echo "[meridian] Warming application cache..."
php /var/www/html/artisan config:cache
php /var/www/html/artisan route:cache
php /var/www/html/artisan view:cache

echo "[meridian] Entrypoint complete. Starting supervisor..."

# Hand off to supervisord (or any passed CMD)
exec "$@"
