#!/bin/sh
echo "Starting Statamic deployment..."

# Кэширование
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Симлинк для storage
php artisan storage:link

# Warm static cache (если используете)
php please static:warm

echo "Deployment complete!"