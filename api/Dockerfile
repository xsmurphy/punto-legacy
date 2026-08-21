# syntax=docker/dockerfile:1.6
# ─────────────────────────────────────────────────────────────────────────────
# Punto — container PHP auto-contenido (api/)
#
# Build context: ./api  (no ROOT del repo)
# Uso: docker build -t punto-api ./api
#
# Layout en el container:
#   /var/www/api/       — código PHP (COPY . ./api)
#   /var/www/database/  — migraciones SQL (COPY database ./database)
#   WORKDIR /var/www
#   CMD: php -S 0.0.0.0:3000 api/router.php
#
# DECISIÓN DE LAYOUT: database/ queda en /var/www/database (no dentro de api/)
# para que docker-entrypoint.sh pueda encontrarlo en /var/www/database/migrate.php
# sin cambiar el entrypoint. migrate.php usa dirname(__DIR__) como repoRoot para
# buscar .env — con este layout dirname(__DIR__) = /var/www, lo que es correcto.
#
# Tras dissolve-panel (2026-06-29): solo api.punto.la vive en este container.
# panel.punto.la / admin.punto.la → frontend/ (Node/Next.js, container separado).
#
# TODO(prod): migrar de `php -S` a nginx + php-fpm cuando crezca.
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

# Observabilidad: logear errores/fatales a stderr (→ docker logs). display_errors
# queda Off (no filtrar al cliente), pero log_errors On hace visible lo que antes
# era 100% silente (incidente 2026-06-30: "Call to undefined method" sin rastro).
RUN { \
    echo 'log_errors=On'; \
    echo 'error_log=/proc/self/fd/2'; \
    echo 'display_errors=Off'; \
    echo 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING'; \
    } > /usr/local/etc/php/conf.d/errors.ini

WORKDIR /var/www

# Composer deps primero (layer cacheado hasta que cambie composer.json/lock).
# Build context = ./api, así que los paths son relativos a api/.
COPY composer.json composer.lock* ./api/
RUN cd api && composer install \
        --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader \
    && rm -rf /root/.composer

# Código de la API (todo el context = api/)
COPY . ./api

# Migraciones SQL — en /var/www/database para que docker-entrypoint.sh las encuentre
# en /var/www/database/migrate.php (ruta hardcodeada en entrypoint).
# database/ se incluye en el build context via COPY en el compose o build --build-context.
# NOTA: database/ se copia desde el directorio hermano api/database/ (movido via git mv).
COPY database ./database

# Autoload final (después de copiar el código)
RUN cd api && composer dump-autoload --no-dev --optimize --classmap-authoritative

# Workers: el built-in server sirve la API. Overridable por env de Coolify.
ENV PHP_CLI_SERVER_WORKERS=28 \
    APP_ENV=production

# Entrypoint: configura PHP sessions en Redis al boot y corre auto-migrate.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Jobs de mantenimiento (drainer de FE, reconcile de rollups, purgas — ver
# context/06-infraestructura.md § Jobs de mantenimiento): crond de BusyBox
# YA viene con la imagen Alpine base, no hace falta instalar pg_cron ni nada
# externo — todo el scheduler queda versionado en el repo y viaja con cada
# deploy. /etc/crontabs/root es donde BusyBox espera el crontab del usuario
# root (formato sin columna de usuario). docker-entrypoint.sh levanta
# `crond -b` al boot, condicionado a que EINVOICE_DRAIN_SECRET esté seteada.
COPY docker/cron/maintenance.sh /usr/local/bin/maintenance.sh
RUN chmod +x /usr/local/bin/maintenance.sh
COPY docker/cron/crontab /etc/crontabs/root

# Puerto 3000 — default que Coolify configura en Traefik.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS -H "Host: api.punto.la" http://localhost:3000/v1/health > /dev/null || exit 1

EXPOSE 3000
WORKDIR /var/www

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh", "/sbin/tini", "--"]
CMD ["php", "-S", "0.0.0.0:3000", "api/router.php"]
