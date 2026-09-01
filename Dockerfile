FROM composer:2.10.3 AS composer

FROM php:8.5.9-apache-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
    && docker-php-ext-install -j"$(nproc)" curl intl mbstring pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl --fail --silent --show-error http://localhost/health || exit 1

FROM php-base AS development

ENV APP_ENV=dev
ENV APP_DEBUG=1

COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-interaction \
    --no-progress \
    --no-scripts

COPY . ./

RUN composer run-script auto-scripts \
    && chown -R www-data:www-data var

FROM php-base AS production

ENV APP_ENV=prod
ENV APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts

COPY .env importmap.php LICENSE ./
COPY assets ./assets
COPY bin ./bin
COPY config ./config
COPY migrations ./migrations
COPY public ./public
COPY src ./src
COPY templates ./templates
COPY translations ./translations

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev auto-scripts \
    && chown -R www-data:www-data var
