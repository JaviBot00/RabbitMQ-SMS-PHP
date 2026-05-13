FROM php:8.3-cli-alpine

RUN docker-php-ext-install pdo pdo_mysql sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY shared/ /shared/

COPY consumer/composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY consumer/ ./

CMD ["php", "run.php"]
