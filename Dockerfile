# syntax=docker/dockerfile:1.7

FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache icu-libs sqlite-libs \
    && apk add --no-cache --virtual .build-deps icu-dev sqlite-dev linux-headers \
    && docker-php-ext-install -j$(nproc) intl opcache pdo_sqlite \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

FROM base AS dev
RUN apk add --no-cache --virtual .xdebug-build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .xdebug-build-deps
COPY docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/99-app.ini
CMD ["php-fpm"]

FROM base AS prod-deps
COPY composer.json composer.lock* symfony.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-progress --optimize-autoloader

FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/99-app.ini
COPY --from=prod-deps /app/vendor /app/vendor
COPY . /app
RUN mkdir -p var/cache var/log var/data \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var
USER www-data
CMD ["php-fpm"]

FROM nginx:alpine AS nginx-prod
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /app/public
