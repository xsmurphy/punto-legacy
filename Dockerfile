# syntax=docker/dockerfile:1.6
# ─────────────────────────────────────────────────────────────────────────────
# Punto — container PHP único (api)
#
# Tras dissolve-panel (2026-06-29):
#   /panel fue eliminado. El backend admin se movió a api/v1/admin/.
#   Este container sirve únicamente api.punto.la.
#
#   panel.punto.la + admin.punto.la → container Node/Next.js (frontend/).
#   api.punto.la                    → este container PHP.
#
# INFRA: configurar Coolify/Traefik para que panel.* y admin.* apunten al
# servicio frontend (Node), NO a este container.
#
# El WebSocket (ws.punto.la) NO va acá — es Node.js, vive en ws-server/Dockerfile
# como recurso aparte.
#
# Build context: ROOT del repo.
#
# Uso: docker build -t punto .
# ─────────────────────────────────────────────────────────────────────────────


# ─── Stage 1: runtime PHP ────────────────────────────────────────────────────
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
    # CRÍTICO: el server corre con `php -S` (CLI SAPI), no FPM. Sin enable_cli=1
    # opcache queda inactivo y cada request recompila functions.php (10k+ líneas).
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.validate_timestamps=1'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'realpath_cache_size=4096K'; \
    echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www

# Composer deps de api (vendor con namespace Punto\Api\*)
COPY api/composer.json api/composer.lock* ./api/
RUN cd api && composer install \
        --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader \
    && rm -rf /root/.composer

# Código del módulo api
COPY api   ./api

# Migraciones SQL — corren via docker-entrypoint.sh al boot del container
COPY database ./database

# Dispatcher raíz por Host
COPY router.php ./router.php

# Autoload final
RUN cd api && composer dump-autoload --no-dev --optimize --classmap-authoritative

# Workers: el built-in server sirve la API. Overridable por env de Coolify.
ENV PHP_CLI_SERVER_WORKERS=28 \
    APP_ENV=production

# Entrypoint: configura PHP sessions en Redis al boot.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Puerto 3000 — default que Coolify configura en Traefik.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS -H "Host: api.punto.la" http://localhost:3000/v1/health > /dev/null || exit 1

EXPOSE 3000
WORKDIR /var/www

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh", "/sbin/tini", "--"]
CMD ["php", "-S", "0.0.0.0:3000", "router.php"]
