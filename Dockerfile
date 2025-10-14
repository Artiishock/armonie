FROM php:8.2-alpine

# Установка необходимых расширений
RUN apk add --no-cache \
    curl \
    git \
    zip \
    unzip

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копирование проекта
COPY . /var/www/html
WORKDIR /var/www/html

# Установка зависимостей
RUN composer install --no-dev --optimize-autoloader

# Настройка прав доступа
RUN chown -R www-data:www-data /var/www/html/storage
RUN chmod -R 755 /var/www/html/storage

# Запуск сервера
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]