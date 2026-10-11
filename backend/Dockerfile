FROM php:8.4-fpm-bookworm AS vendor-build

RUN docker-php-ext-install pdo_mysql

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY composer.json composer.lock ./

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

COPY . .

RUN mkdir -p bootstrap/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload \
    --no-dev \
    --optimize \
    --no-interaction


# Stage 2：正式執行環境
FROM php:8.4-fpm-bookworm AS runtime

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html

COPY --from=vendor-build /var/www/html/ ./

RUN mkdir -p storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php-fpm/zz-home-fitness.conf /usr/local/etc/php-fpm.d/zz-home-fitness.conf

COPY docker/php/zz-home-fitness-opcache.ini /usr/local/etc/php/conf.d/zz-home-fitness-opcache.ini

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

COPY docker/php/zz-home-fitness-uploads.ini /usr/local/etc/php/conf.d/zz-home-fitness-uploads.ini