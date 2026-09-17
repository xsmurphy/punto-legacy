#!/bin/bash
# =============================================================
# run_help_chunker_test.sh — arnés del fragmentador de la base de conocimiento
# de Punto AI (context/82 D8).
#
# Sin Docker y sin Postgres: `HelpChunker` es una clase PURA. Ese es
# justamente el motivo de que la regla de fragmentado viva en su propia clase
# y no adentro del servicio que escribe en la base — se puede ejercitar sola.
#
# Uso (un comando, desde la raíz del repo):
#   bash api/tests/run_help_chunker_test.sh
#
# Requiere: php (>=8.1). Exit code: 0 si pasó.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"

echo "[run_help_chunker_test.sh] === fragmentado de la base de conocimiento ==="
harness_run "$SCRIPT_DIR/help_chunker_test.php"

echo ""
echo "[run_help_chunker_test.sh] TODO OK."
