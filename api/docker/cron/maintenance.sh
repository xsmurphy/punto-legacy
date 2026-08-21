#!/bin/sh
# Punto — trigger de un job de mantenimiento, invocado por crond (BusyBox,
# api/docker/cron/crontab) dentro del MISMO container que sirve la API.
#
# POSIX sh puro (BusyBox ash no tiene bash-isms): sin arrays, sin [[ ]],
# sin ${var,,}, etc.
#
# Uso: maintenance.sh <job-name>
#   job-name ∈ {rollup-reconcile, purge-tenant-audit, purge-deleted-row, einvoice-drain}
#
# Le pega a localhost:3000 (el mismo `php -S` que sirve el tráfico externo —
# el cron vive adentro del container, no hay red aparte). Sin header Host
# explícito: ni router.php ni bootstrap.php ni cors.php resuelven nada por
# Host en /v1/* (CORS va por Origin), y hardcodear un dominio acá viola la
# regla del proyecto (CLAUDE.md §3). Si algún día el bootstrap resuelve
# tenant por subdominio, el Host tiene que salir de una env var, no de acá.
#
# Si EINVOICE_DRAIN_SECRET no está seteada, el job de mantenimiento está
# deshabilitado (el endpoint respondería 503) — no tiene sentido pegarle y
# spamear error logs cada 5/10 minutos, así que salimos silenciosos con 0.

set -eu

JOB="${1:?uso: maintenance.sh <job-name>}"

if [ -z "${EINVOICE_DRAIN_SECRET:-}" ]; then
    echo "[maintenance-cron] EINVOICE_DRAIN_SECRET no seteada — job '$JOB' saltado (endpoint deshabilitado)"
    exit 0
fi

RESPONSE=$(curl -fsS -X POST \
    -H "X-Maintenance-Secret: ${EINVOICE_DRAIN_SECRET}" \
    "http://localhost:3000/v1/maintenance?job=${JOB}" 2>&1) \
    && echo "[maintenance-cron] job=${JOB} ok response=${RESPONSE}" \
    || {
        STATUS=$?
        echo "[maintenance-cron] job=${JOB} FAILED (curl exit=${STATUS}) response=${RESPONSE}" >&2
        exit "$STATUS"
    }
