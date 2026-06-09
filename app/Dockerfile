# syntax=docker/dockerfile:1.6
# ─────────────────────────────────────────────────────────────────────────────
# Punto — App POS (sirve app.punto.la)
#
# Build context: ROOT del repo. router.php de /app sirve /assets desde
# dirname(__DIR__), por lo que assets compartidos viven en /var/www/assets.
#
# Uso: docker build -f app/Dockerfile -t punto-app .
#
# TODO(prod): migrar de `php -S` built-in a nginx + php-fpm cuando crezca la
# carga. Por ahora WORKERS=8 cubre staging/beta.
# ─────────────────────────────────────────────────────────────────────────────


# ─── Stage 1: build de assets (Node) ─────────────────────────────────────────
FROM node:20-alpine AS assets

# build.sh y vendor-sync.sh son scripts bash (no POSIX-sh) — alpine no trae bash.
RUN apk add --no-cache bash

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund --omit=optional --ignore-scripts

COPY assets   ./assets
COPY scripts  ./scripts
COPY panel    ./panel
COPY app      ./app
COPY build.sh ./

# Genera app/cach/<hash>.js y app/cach/<hash>.css
RUN bash build.sh app


# ─── Stage 2: runtime PHP ────────────────────────────────────────────────────
FROM php:8.4-cli-alpine AS runtime

RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        linux-headers; \
    apk add --no-cache \
        postgresql-libs \
        libpng \
        libjpeg-turbo \
        freetype \
        icu-libs \
        libzip \
        bash \
        curl \
        tini; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        gd \
        intl \
        zip \
        opcache \
        bcmath; \
    pecl install redis && docker-php-ext-enable redis; \
    apk del --no-network .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.validate_timestamps=1'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'realpath_cache_size=4096K'; \
    echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www

# Composer deps (cache layer)
COPY app/composer.json app/composer.lock* ./app/
RUN cd app && composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --no-scripts \
        --no-autoloader \
    && rm -rf /root/.composer

# Código del POS
COPY app ./app

# Assets compartidos (servidos desde router.php vía dirname(__DIR__))
COPY assets ./assets

# Bundles generados en stage 1
COPY --from=assets /build/app/cach ./app/cach

RUN cd app && composer dump-autoload --no-dev --optimize --classmap-authoritative

ENV PHP_CLI_SERVER_WORKERS=8 \
    APP_ENV=production

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS http://localhost:80/login > /dev/null || exit 1

EXPOSE 80
WORKDIR /var/www/app

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php", "-S", "0.0.0.0:80", "router.php"]
