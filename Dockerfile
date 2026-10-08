# ── CitaYa V2 · PHP 8.2 + Apache ─────────────────────────────
FROM php:8.2-apache

# Extensiones: pdo_mysql (MariaDB/MySQL) y mbstring (lectura de PDF y normalización).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libonig-dev unzip poppler-utils \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring \
    && rm -rf /var/lib/apt/lists/*

# Apache: mod_rewrite + DocumentRoot en public/.
RUN a2enmod rewrite
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/uploads.ini /usr/local/etc/php/conf.d/citaya-uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Dependencias PHP primero (mejor caché de capas).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
        --no-scripts --optimize-autoloader

# Código de la aplicación.
COPY . .

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
