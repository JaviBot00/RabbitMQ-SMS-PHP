FROM php:8.3-cli-alpine

RUN docker-php-ext-install pdo pdo_mysql sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY shared/ /shared/

COPY reintentador/composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY reintentador/ ./

CMD ["php", "run.php"]
