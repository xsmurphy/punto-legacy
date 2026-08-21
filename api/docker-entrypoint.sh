#!/bin/sh
# Punto — entrypoint del container PHP.
#
# Configura PHP en runtime para usar Redis como session handler. Sin esto las
# sesiones PHP viven en /tmp/sess_* y se PIERDEN en cada deploy (el container
# se recrea desde cero), forzando al user a re-loguear cada vez. Con Redis,
# las sesiones persisten porque Redis sigue corriendo independiente.
#
# REDIS_URL formato Coolify: redis://[user[:pass]@]host[:port][/db]
# PHP redis session save_path formato: tcp://host:port?auth=pass&database=db

set -e

# Parsear REDIS_URL si está seteada. Si no, dejar PHP con files default.
if [ -n "$REDIS_URL" ]; then
    # Strip scheme
    _url="${REDIS_URL#redis://}"
    _url="${_url#rediss://}"

    # Extraer credenciales si hay @
    case "$_url" in
        *@*)
            _creds="${_url%%@*}"
            _hostpart="${_url#*@}"
            # user:pass o solo pass
            case "$_creds" in
                *:*) _pass="${_creds#*:}" ;;
                *)   _pass="$_creds" ;;
            esac
            ;;
        *)
            _pass=""
            _hostpart="$_url"
            ;;
    esac

    # Extraer db si hay /
    case "$_hostpart" in
        */*)
            _db="${_hostpart#*/}"
            _hostport="${_hostpart%%/*}"
            ;;
        *)
            _db="0"
            _hostport="$_hostpart"
            ;;
    esac

    # Armar save_path
    _save_path="tcp://${_hostport}?database=${_db}"
    if [ -n "$_pass" ]; then
        _save_path="${_save_path}&auth=${_pass}"
    fi

    cat > /usr/local/etc/php/conf.d/session-redis.ini <<EOF
session.save_handler = redis
session.save_path    = "${_save_path}"
EOF

    echo "[entrypoint] PHP sessions → Redis (${_hostport} db=${_db})"
else
    echo "[entrypoint] REDIS_URL no seteada — sessions en /tmp (se pierden por deploy)"
fi

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
