# CRUD Skeleton — FrankenPHP application

FROM dunglas/frankenphp:php8.4-alpine

# PHP extensions
RUN install-php-extensions pdo_mysql intl zip opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App
WORKDIR /var/www/html

# Dependencies first (cache layer). The root requires local packages.
COPY composer.json composer.lock symfony.lock ./
COPY packages ./packages
COPY apps/store ./apps/store
COPY apps/inventory ./apps/inventory
COPY apps/payment ./apps/payment
COPY apps/wallet ./apps/wallet
COPY apps/identity ./apps/identity
COPY apps/common ./apps/common
RUN composer install --no-dev --no-scripts --no-interaction --no-progress --optimize-autoloader \
    && composer clear-cache

# Application code
COPY . ./
RUN composer dump-autoload --no-dev --optimize --no-interaction

# Writable directories
RUN mkdir -p var/jwt var/cache var/log \
    && chown -R www-data:www-data var

# Entrypoint: creates persistent dev JWT keys and validates prod keys
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
