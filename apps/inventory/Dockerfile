FROM dunglas/frankenphp:php8.4-alpine

RUN install-php-extensions pdo_mysql intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /workspace
COPY packages ./packages
COPY apps/inventory ./apps/inventory

WORKDIR /workspace/apps/inventory
RUN composer install --no-dev --no-scripts --no-interaction --no-progress --optimize-autoloader \
    && composer clear-cache

COPY apps/inventory/docker/Caddyfile /etc/caddy/Caddyfile

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
