# CRUD Skeleton — PHP Application

FROM php:8.4-fpm-alpine

# PHP extensions
RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    libzip-dev \
    linux-headers \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    ctype \
    iconv \
    && apk del postgresql-dev icu-dev libzip-dev linux-headers

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App
WORKDIR /var/www/html

# Dependencies first (cache layer)
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader \
    && composer clear-cache

# Application code
COPY . ./

# JWT keys directory
RUN mkdir -p var/jwt var/cache var/log \
    && chown -R www-data:www-data var

# Symfony cache warmup
RUN php bin/console cache:warmup --env=prod

EXPOSE 9000

CMD ["php-fpm"]
