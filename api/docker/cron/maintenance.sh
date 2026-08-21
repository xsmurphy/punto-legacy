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
# el cron vive adentro del container, no hay red aparte). El header Host
# importa: el router/bootstrap puede depender de él para resolver dominio/CORS
# (mismo criterio que el HEALTHCHECK del Dockerfile, que usa
# `-H "Host: api.punto.la"`).
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
    -H "Host: api.punto.la" \
    -H "X-Maintenance-Secret: ${EINVOICE_DRAIN_SECRET}" \
    "http://localhost:3000/v1/maintenance?job=${JOB}" 2>&1) \
    && echo "[maintenance-cron] job=${JOB} ok response=${RESPONSE}" \
    || {
        STATUS=$?
        echo "[maintenance-cron] job=${JOB} FAILED (curl exit=${STATUS}) response=${RESPONSE}" >&2
        exit "$STATUS"
    }
