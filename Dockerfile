FROM composer:2 AS composer

FROM php:8.4-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libpq-dev unzip \
    && docker-php-ext-install -j"$(nproc)" intl opcache pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/apache-ports.conf /etc/apache2/ports.conf
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-quai-antique.ini
COPY docker/entrypoint.sh /usr/local/bin/quai-antique-entrypoint
RUN chmod 755 /usr/local/bin/quai-antique-entrypoint

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-placeholder \
    DATABASE_URL='postgresql://build:build@database:5432/build?serverVersion=18&charset=utf8' \
    CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$' LOCK_DSN=flock \
    DEFAULT_URI=http://localhost:8000 \
    composer run-script auto-scripts \
    && mkdir -p var public/uploads/restaurants \
    && chown -R www-data:www-data var public/uploads

EXPOSE 8080
ENTRYPOINT ["quai-antique-entrypoint"]
CMD ["apache2-foreground"]
