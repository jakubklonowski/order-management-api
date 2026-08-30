FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        zip \
        unzip \
        git \
        $PHPIZE_DEPS

RUN docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        intl \
        zip \
        opcache \
        bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/custom.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

EXPOSE 9000

FROM base AS dev

# XDebug build only
RUN apk add --no-cache linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

COPY docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/xdebug-settings.ini

CMD ["php-fpm"]

FROM base AS prod

COPY . /var/www/html

RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

CMD ["php-fpm"]
