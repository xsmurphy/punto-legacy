# syntax=docker/dockerfile:1.6
# ─────────────────────────────────────────────────────────────────────────────
# Punto — container ÚNICO (panel + admin + app + api)
#
# Este Dockerfile combina los 3 módulos PHP en un solo container y los rutea
# vía router.php raíz que despacha por Host: header. Coolify deploya esto
# como recurso tipo "Dockerfile" (no Docker Compose).
#
# 4 subdominios → 1 container:
#   panel.punto.la → /panel
#   admin.punto.la → /panel (con /admin path prefix forzado)
#   app.punto.la   → /app
#   api.punto.la   → /api
#
# El WebSocket (ws.punto.la) NO va acá — es Node.js, vive en ws-server/Dockerfile
# como recurso aparte.
#
# Build context: ROOT del repo.
#
# Uso: docker build -t punto .
# ─────────────────────────────────────────────────────────────────────────────


# ─── Stage 1: build de assets (Node) ─────────────────────────────────────────
FROM node:20-alpine AS assets

RUN apk add --no-cache bash

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund --omit=optional --ignore-scripts

COPY assets   ./assets
COPY scripts  ./scripts
COPY panel    ./panel
COPY app      ./app
COPY build.sh ./

# Build de panel (genera panel/scripts/*.js, panel/css/ncm.css)
RUN bash build.sh panel
# Build de app (genera app/cach/<hash>.{js,css})
RUN bash build.sh app


# ─── Stage 2: runtime PHP ────────────────────────────────────────────────────
FROM php:8.4-cli-alpine AS runtime

# install-php-extensions de mlocati: instala extensiones pre-compiladas cuando
# puede, compila si no. Mucho más rápido y confiable que docker-php-ext-install
# manual + apk build-deps (que tarda 5+ min compilando gd/intl desde source).
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN set -eux; \
    chmod +x /usr/local/bin/install-php-extensions; \
    apk add --no-cache bash curl tini; \
    install-php-extensions \
        pdo \
        pdo_pgsql \
        gd \
        intl \
        zip \
        opcache \
        bcmath \
        redis

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

# Composer deps de /panel y /app (la /api consume vendor/autoload de /app)
COPY panel/composer.json panel/composer.lock* ./panel/
RUN cd panel && composer install \
        --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader \
    && rm -rf /root/.composer

COPY app/composer.json app/composer.lock* ./app/
RUN cd app && composer install \
        --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader \
    && rm -rf /root/.composer

# Código de los 3 módulos
COPY panel ./panel
COPY app   ./app
COPY api   ./api
COPY assets ./assets

# Bundles generados en stage 1 (sobreescriben lo que vino del COPY si existía)
COPY --from=assets /build/panel/scripts ./panel/scripts
COPY --from=assets /build/panel/css     ./panel/css
COPY --from=assets /build/app/cach      ./app/cach

# Dispatcher raíz por Host
COPY router.php ./router.php

# Autoload final de ambos composer
RUN cd panel && composer dump-autoload --no-dev --optimize --classmap-authoritative
RUN cd app   && composer dump-autoload --no-dev --optimize --classmap-authoritative

ENV PHP_CLI_SERVER_WORKERS=8 \
    APP_ENV=production

# Puerto 3000 — es el default que Coolify configura en Traefik para apps
# tipo "Dockerfile resource". Cambiarlo en la UI también funciona, pero
# escuchar en 3000 evita tener que tocar config externa.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS -H "Host: panel.punto.la" http://localhost:3000/login > /dev/null || exit 1

EXPOSE 3000
WORKDIR /var/www

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php", "-S", "0.0.0.0:3000", "router.php"]
