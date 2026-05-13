FROM php:8.3-cli-alpine

# extensiones necesarias: pdo_mysql para la BD, sockets para php-amqplib
RUN docker-php-ext-install pdo pdo_mysql sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# copiar el shared primero (dependencia local del actor)
COPY shared/ /shared/

# copiar composer.json e instalar dependencias
COPY emisor/composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# copiar el código fuente del actor
COPY emisor/ ./

CMD ["php", "run.php"]
