FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) pdo_mysql pdo_sqlite intl zip gd bcmath exif \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/solqubit.ini
WORKDIR /var/www/core
COPY core/composer.json core/composer.lock ./
RUN composer install --no-scripts --no-interaction --no-progress --prefer-dist

