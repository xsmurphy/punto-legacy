#!/bin/sh
# Punto — entrypoint del container PHP.
#
# Corre las migraciones, seedea el super-admin y levanta los jobs de
# mantenimiento antes de servir requests.

set -e

# PHP sessions: NO se configuran. La API no llama session_start() en ningun
# lado — el auth son tokens opacos en la tabla auth_session (context/21), y el
# ultimo consumidor de $_SESSION (el rate limiter) pasó a Redis via
# api/lib/Cache/RedisClient.php, que lee REDIS_URL por su cuenta. Antes acá se
# escribia session-redis.ini con session.save_handler=redis; era config muerta
# que ademas sugeria, falsamente, que el re-login dependia de que la sesion de
# PHP sobreviviera al deploy.

# Aplicar migraciones SQL pendientes antes de servir requests.
# El script database/migrate.php es idempotente — trackea en schema_migrations
# qué archivos ya corrieron. Bootstrap one-time marca 01-13 como already-applied
# si detecta BD existente (no fuerza re-aplicar lo que ya se aplicó manualmente).
#
# Fail-fast: si una migración falla, el script exit 1 → entrypoint corta y el
# container no arranca. Mejor que servir contra un schema a medio migrar.
if [ -f /var/www/database/migrate.php ]; then
    echo "[entrypoint] corriendo auto-migrate..."
    php /var/www/database/migrate.php || {
        echo "[entrypoint] auto-migrate FAILED — abortando boot del container" >&2
        exit 1
    }
else
    echo "[entrypoint] database/migrate.php no encontrado — skip"
fi

# Seed idempotente del super-admin de /admin (realm admin). Best-effort: lee
# ADMIN_EMAIL/ADMIN_PASSWORD de env, crea el admin si no existe, no-op si faltan.
# No aborta el boot si falla (a diferencia de migrate, que es fail-fast).
if [ -f /var/www/database/seed_admin.php ]; then
    echo "[entrypoint] seed admin_user (si ADMIN_EMAIL/ADMIN_PASSWORD están seteados)..."
    php /var/www/database/seed_admin.php || echo "[entrypoint] seed_admin falló (ignorado)" >&2
fi

# Jobs de mantenimiento (drainer de FE, reconcile de rollups, purgas de
# tenant_audit/deleted_row — ver context/06-infraestructura.md § Jobs de
# mantenimiento). crond corre EN ESTE MISMO container, pegándole a
# localhost:3000 (api/docker/cron/maintenance.sh → POST /v1/maintenance).
# Gateado por EINVOICE_DRAIN_SECRET: sin la var, el endpoint respondería 503
# de cualquier forma, así que ni siquiera arrancamos el scheduler (mismo
# criterio best-effort que el seed de admin — no aborta el boot).
# `crond -b`: background (no toma el foreground, que es de tini/php -S).
# `-l 8`: loglevel 8 = solo errores + "corrió el job" (no cada wakeup de
# minuto), a stderr → docker logs. tini sigue siendo PID 1, así que crond
# (y los curl que dispara) no quedan huérfanos ni zombies.
if [ -n "$EINVOICE_DRAIN_SECRET" ]; then
    echo "[entrypoint] EINVOICE_DRAIN_SECRET seteada — arrancando crond (jobs de mantenimiento)"
    crond -b -l 8 || echo "[entrypoint] crond no pudo arrancar — jobs de mantenimiento NO programados (ignorado, la API arranca igual)" >&2
else
    echo "[entrypoint] EINVOICE_DRAIN_SECRET no seteada — jobs de mantenimiento NO programados"
fi

# Ejecutar el CMD original (tini + php -S)
exec "$@"
