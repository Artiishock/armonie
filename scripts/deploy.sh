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

#!/bin/sh

echo "Running database migrations..."
php artisan migrate --force

echo "Starting application..."
php -S 0.0.0.0:$PORT -t public