#!/bin/bash
# =============================================================
# run_einvoice_provision_form_test.sh — arnés del FORMULARIO del alta del emisor.
#
# Lo que verifica, en una línea: lo que el comercio tipea en la pantalla de
# facturación electrónica (régimen, tipo de contribuyente, establecimientos con
# sus códigos geográficos) sobrevive a la normalización que se persiste en
# `einvoice_account.fiscal` —el espejo que hidrata la pantalla al reanudar un
# alta a medias— y llega completo al payload del motor propio, que corta
# nombrando el establecimiento incompleto en vez de inventarle un domicilio.
#
# La regresión que cierra es silenciosa: `validateForm()` es una whitelist, y
# la clave que no nombra se borra en el upsert siguiente sin que falle nada.
#
# Igual que run_einvoice_fepy_mapper_test.sh, NO necesita Postgres ni Docker:
# son transformaciones puras y corre en menos de un segundo.
#
# Uso (desde la raíz del repo):
#   bash api/tests/run_einvoice_provision_form_test.sh
#
# Requiere: php (>=8.1). Exit code: 0 si el test pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Wrapper compartido: exige la linea canonica de resumen, no solo exit 0.
# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"

echo "[run_einvoice_provision_form_test.sh] === formulario del alta (espejo, normalización, payload) ==="
harness_run "$SCRIPT_DIR/einvoice_provision_form_test.php"

echo ""
echo "[run_einvoice_provision_form_test.sh] TODO OK."
