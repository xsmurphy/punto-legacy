# syntax=docker/dockerfile:1.6
# ─────────────────────────────────────────────────────────────────────────────
# Punto — API compartida (sirve api.punto.la)
#
# El backend legacy de /app fue movido a api/core en la Fase 2 del cleanup
# (legacy-cleanup 2026-06-29). El namespace Punto\App\* se conserva;
# composer/autoload vive en api/core/vendor.
#
# Build context: ROOT del repo.
#
# Uso: docker build -f api/Dockerfile -t punto-api .
#
# TODO(prod): migrar de `php -S` a nginx + php-fpm cuando crezca.
# ─────────────────────────────────────────────────────────────────────────────


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

# Composer deps de api/core (namespace Punto\App\* — autoload canonico del backend)
COPY api/core/composer.json api/core/composer.lock* ./api/core/
RUN cd api/core && composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --no-scripts \
        --no-autoloader \
    && rm -rf /root/.composer

# /api entero (incluye api/core con head.php, includes/, vendor)
COPY api ./api

RUN cd api/core && composer dump-autoload --no-dev --optimize --classmap-authoritative

ENV PHP_CLI_SERVER_WORKERS=8 \
    APP_ENV=production

# Healthcheck: pide un endpoint real de /v1. El server vivo responde 200|401|403;
# un container muerto/colgado da curl-fail o 5xx.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS -o /dev/null -w '%{http_code}' http://localhost:80/v1/currencies | grep -qE '^(200|401|403)$' || exit 1

EXPOSE 80
WORKDIR /var/www/api

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php", "-S", "0.0.0.0:80", "router.php"]
