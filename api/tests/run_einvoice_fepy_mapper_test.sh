#!/bin/bash
# =============================================================
# run_einvoice_fepy_mapper_test.sh — arnés del MAPPER de FE-PY (motor propio).
#
# Lo que verifica, en una línea: la venta de Punto se traduce al JSON del
# motor xmlgen (SIFEN v150) declarando el receptor correcto en los tres casos
# fiscales, el IVA coherente por línea, un total que cierra al multiplicar, y
# —sobre todo— que los guards que tienen que CORTAR la emisión (moneda que no
# es guaraní, innominado por encima del millón, crédito sin cliente
# identificado, venta sin correlativo congelado) efectivamente la cortan en
# vez de declarar mal un documento fiscal.
#
# A diferencia de run_einvoice_emitter_numbering_test.sh, este NO necesita
# Postgres ni Docker: el mapper es una traducción pura y el arnés lo ejercita
# sin tocar la base. Corre en menos de un segundo, que es lo que hace que se
# pueda correr de verdad antes de cada cambio del payload fiscal.
#
# Uso (desde la raíz del repo):
#   bash api/tests/run_einvoice_fepy_mapper_test.sh
#
# Requiere: php (>=8.1). Exit code: 0 si el test pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Wrapper compartido: exige la linea canonica de resumen, no solo exit 0.
# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"

echo "[run_einvoice_fepy_mapper_test.sh] === mapper FE-PY (receptor, IVA, total, condición, guards) ==="
harness_run "$SCRIPT_DIR/einvoice_fepy_mapper_test.php"

echo ""
echo "[run_einvoice_fepy_mapper_test.sh] TODO OK."
