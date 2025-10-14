FROM php:8.3-alpine

# Установка системных зависимостей
RUN apk add --no-cache \
    curl git zip unzip \
    libpng-dev libzip-dev oniguruma-dev \
    postgresql-dev nodejs npm

# Установка расширений PHP
RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring zip gd

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копирование проекта
COPY . /var/www/html
WORKDIR /var/www/html

# Создание необходимых папок
RUN mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions
RUN mkdir -p storage/statamic storage/logs bootstrap/cache

# Установка прав доступа
RUN chown -R www-data:www-data /var/www/html/storage
RUN chmod -R 775 storage bootstrap/cache

# Установка зависимостей
RUN composer install --no-dev --optimize-autoloader

# Сборка фронтенда (если используете)
RUN npm install && npm run build

# Создание симлинков
RUN php artisan storage:link

# Кэширование конфигурации
RUN php artisan config:cache
RUN php artisan route:cache
RUN php artisan view:cache

# Запуск сервера
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]