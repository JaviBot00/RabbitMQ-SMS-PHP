FROM php:8.3-cli-alpine

# extensiones necesarias: pdo_mysql para la BD, sockets para php-amqplib
# linux-headers necesario para compilar la extensión sockets en Alpine
RUN apk add --no-cache linux-headers \
    && docker-php-ext-install pdo pdo_mysql sockets

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

# Para pruebas de carga, servir un HTML simple con JavaScript que envíe solicitudes al emisor

COPY emisor/load-test.html /app/index.html

# Exponer el puerto
EXPOSE 8080

# Forzar a PHP a buscar los archivos en el directorio de trabajo (-t)
# CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/html"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]