# syntax=docker/dockerfile:1
#
# One image, three roles: the web server, the queue worker and the scheduler are
# the same artifact started with different commands, exactly as the development
# compose file does. Three images would be three things to keep in step for no
# reason.
#
# FrankenPHP rather than php-fpm behind nginx: one process serving HTTP, so a
# container in a hall does not hide a supervisor babysitting two daemons.

# --- The PHP everything is built against -------------------------------------
# Dependencies are resolved on the very PHP that will run them: a build stage
# with a different set of extensions resolves a different set of packages, and
# finds out about it in production.
#
# pdo_pgsql and redis for the database and the queues; gd because the receipt
# logo is rendered as a bitmap and without it the logo silently disappears;
# pcntl for Horizon's workers; intl for Filament; opcache because this copy runs
# a whole evening.
FROM dunglas/frankenphp:php8.5-alpine AS base

RUN install-php-extensions pdo_pgsql redis gd intl zip pcntl opcache

# --- PHP dependencies --------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# The lock file alone first: this layer is rebuilt only when dependencies move,
# not on every edit to the application.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script post-autoload-dump --no-dev

# --- Front-end assets --------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

# Tailwind reads the classes out of the sources, so it gets all of them - blade
# views, the till's Livewire pages, and the framework's pagination views that
# app.css names explicitly, which is why vendor comes along.
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN npm run build

# --- Runtime -----------------------------------------------------------------
FROM base

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY . .

# Package discovery as the vendor stage resolved it, for the packages this image
# actually carries.
COPY --from=vendor /app/bootstrap/cache ./bootstrap/cache

# Baked: they depend on the code, not on the environment. The configuration
# cache is deliberately NOT baked - that would cook the build machine's
# environment into the image - and is built at start instead.
RUN php artisan view:cache && php artisan event:cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Any host, plain HTTP: the tills reach this by the machine's address on the
# hall's network, and FrankenPHP would otherwise serve localhost only and go
# looking for a certificate.
ENV SERVER_NAME=:80

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
