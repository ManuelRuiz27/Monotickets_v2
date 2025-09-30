# syntax=docker/dockerfile:1.6

FROM composer:2.7 AS composer-prod-deps
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

FROM composer:2.7 AS composer-dev-deps
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install \
    --prefer-dist \
    --no-interaction \
    --no-progress

FROM php:8.2-fpm-alpine AS base
WORKDIR /var/www/html

RUN apk add --no-cache \
        bash \
        curl \
        supervisor \
        icu-data-full \
        tzdata \
        fontconfig \
        ttf-dejavu

RUN apk add --no-cache --virtual .build-deps \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        zlib-dev \
        oniguruma-dev \
        g++ \
        make \
        autoconf

RUN docker-php-ext-configure intl \
    && docker-php-ext-install intl pdo_mysql \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd zip \
    && apk del .build-deps

COPY docker/supervisor/ /etc/supervisor/conf.d/

FROM base AS development
COPY --from=composer-dev-deps /var/www/html/vendor ./vendor
COPY --from=composer-dev-deps /var/www/html/composer.lock ./composer.lock
COPY . .
RUN chown -R www-data:www-data storage bootstrap/cache

FROM base AS production
COPY --from=composer-prod-deps /var/www/html/vendor ./vendor
COPY --from=composer-prod-deps /var/www/html/composer.lock ./composer.lock
COPY . .
RUN chown -R www-data:www-data storage bootstrap/cache

FROM nginx:1.25-alpine AS nginx
RUN apk add --no-cache curl
RUN rm /etc/nginx/conf.d/default.conf
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public

