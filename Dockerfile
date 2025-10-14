FROM php:8.3-alpine

# Установка системных зависимостей
RUN apk add --no-cache \
    curl git zip unzip \
    libpng-dev libzip-dev oniguruma-dev

# Установка расширений PHP
RUN docker-php-ext-install pdo pdo_mysql mbstring zip gd

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копирование проекта
COPY . /var/www/html
WORKDIR /var/www/html

# Создание необходимых папок
RUN mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions
RUN mkdir -p storage/statamic storage/logs bootstrap/cache

# Установка прав доступа
RUN chmod -R 775 storage bootstrap/cache

# Установка зависимостей БЕЗ скриптов
RUN composer install --no-dev --optimize-autoloader --no-scripts

# ЗАКОММЕНТИРУЙТЕ эти строки временно:
# RUN php artisan config:cache
# RUN php artisan route:cache
# RUN php artisan view:cache

# Запуск сервера
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]