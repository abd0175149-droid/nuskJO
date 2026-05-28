#!/bin/bash
set -e

echo "🚀 NUSUK — Starting Production Setup..."

cd /var/www/html

# Generate app key if not set
if [ -z "$APP_KEY" ]; then
    echo "🔑 Generating application key..."
    php artisan key:generate --force --no-interaction
fi

# Ensure SQLite database exists
if [ ! -f database/database.sqlite ]; then
    echo "📦 Creating SQLite database..."
    touch database/database.sqlite
    chown www-data:www-data database/database.sqlite
fi

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache database
chmod -R 775 storage bootstrap/cache database

# Cache configuration for production
echo "⚡ Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Run migrations
echo "🗄️ Running migrations..."
php artisan migrate --force --no-interaction

# Create storage symlink
php artisan storage:link --force 2>/dev/null || true

# Seed chart of accounts if fresh database
php artisan db:seed --class=ChartOfAccountsSeeder --force --no-interaction 2>/dev/null || true

echo "✅ NUSKJO is ready! Listening on port 4020"

# Execute the main process (supervisord)
exec "$@"
