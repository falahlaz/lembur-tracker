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
# CSS FilePond ikut lewat rantai impor theme.css -> filament/theme.css ->
# forms/index.css -> dist/index.css, dan satu-satunya yang menyembunyikan input
# berkas bawaan browser adalah `.filepond--browser { opacity: 0 }` di sana. Kalau
# rantai itu tidak terkompilasi, field upload tampil sebagai "Choose File" polos
# sementara sisa panel terlihat normal — jenis kerusakan yang paling lama tidak
# ketahuan. Theme hasil build satu-satunya aset yang belum punya pagar, jadi
# build harus gagal di sini.
RUN npm run build \
    && test -s public/build/manifest.json \
    && grep -rq filepond public/build/assets

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

# filament:upgrade menerbitkan aset Filament ke public/css/filament dan
# public/js/filament. Tahap vendor memakai --no-scripts, jadi post-autoload-dump
# tidak pernah jalan — tanpa baris ini panel tampil tanpa CSS.
# chown: peran writer dipisah dari root supaya proses PHP tidak berjalan
# sebagai root.
# Komponen Alpine yang dimuat lewat `x-load` gagal tanpa suara kalau berkasnya
# tidak ada: panel tetap ter-style dan tetap render, tapi setiap DatePicker
# `native(false)` jadi input readonly yang kalendernya tak pernah terbuka dan
# setiap Select `native(false)` jadi div kosong. Karena itu build harus gagal di
# sini, bukan menghasilkan image yang rusaknya baru ketahuan dari layar user.
RUN php artisan package:discover --ansi \
    && php artisan filament:upgrade \
    && test -f public/js/filament/support/support.js \
    && test -f public/js/filament/forms/components/select.js \
    && test -f public/js/filament/forms/components/date-time-picker.js \
    && test -f public/js/filament/forms/components/file-upload.js \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm"]

# ---------- Tahap 4: web ----------
# public/ ikut ke dalam image nginx, bukan di-bind dari host: aset Vite
# (public/build) dan aset Filament dibuat saat build dan tidak ada di checkout,
# jadi bind mount ./public membuat seluruh CSS/JS 404.
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=runtime /var/www/html/public /var/www/html/public
