FROM php:8.3-alpine

# Установка системных зависимостей
RUN apk add --no-cache \
    curl \
    git \
    zip \
    unzip \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    postgresql-dev

# Установка расширений PHP
RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring zip gd

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копирование проекта
COPY . /var/www/html
WORKDIR /var/www/html

# Установка зависимостей
RUN composer install --no-dev --optimize-autoloader

# Настройка прав доступа
RUN chmod -R 755 storage bootstrap/cache

# Создание папки для базы данных SQLite
RUN mkdir -p storage/database && touch storage/database/database.sqlite

# Запуск сервера
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]