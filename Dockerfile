# syntax=docker/dockerfile:1

# ---------- Tahap 1: dependensi PHP ----------
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Script di-skip: artisan belum ada di tahap ini.
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress --ignore-platform-reqs

# ---------- Tahap 2: aset frontend ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund
COPY . .
# theme.css milik panel mengimpor dari vendor/filament, jadi tahap ini butuh
# vendor juga — di build bersih (CI) direktori itu tidak ikut COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---------- Tahap 3: runtime ----------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
        icu-libs libzip \
        icu-dev oniguruma-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip gd opcache \
    && apk del icu-dev oniguruma-dev libzip-dev

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-lemburku.ini

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY lang ./lang
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY storage ./storage
COPY artisan composer.json ./
COPY --from=assets /app/public/build ./public/build

# Peran writer dipisah dari root supaya proses PHP tidak berjalan sebagai root.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
