#!/bin/bash
# =============================================================
# run_harness_selftest.sh — prueba el mecanismo anti "falso verde".
#
# No necesita Postgres, Docker ni Redis: verifica el harness en sí, corriendo
# arneses de mentira que mueren a propósito de las tres maneras conocidas y
# comprobando que NINGUNA puede terminar en verde.
#
# Uso:  bash api/tests/run_harness_selftest.sh
# Exit code: 0 si el mecanismo funciona.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_harness_lib.sh
source "$SCRIPT_DIR/_harness_lib.sh"

CASES_DIR="$SCRIPT_DIR/selftest"
failures=0
checks=0

# expect_case <archivo> <exit-esperado: zero|nonzero> <resumen: none|ok|fail> <descripción>
expect_case() {
  local file="$1" want_exit="$2" want_summary="$3" desc="$4"
  local rc=0 out

  checks=$((checks + 1))

  set +e
  out="$(harness_run "$CASES_DIR/$file" 2>&1)"
  rc=$?
  set -e

  local ok=1

  case "$want_exit" in
    zero)    [ "$rc" -eq 0 ] || ok=0 ;;
    nonzero) [ "$rc" -ne 0 ] || ok=0 ;;
  esac

  local name="${file%.php}"
  case "$want_summary" in
    none) grep -qE "^HARNESS RESULT: ${name} .* -> (OK|FAIL)$" <<<"$out" && ok=0 ;;
    ok)   grep -qE "^HARNESS RESULT: ${name} .* -> OK$"   <<<"$out" || ok=0 ;;
    fail) grep -qE "^HARNESS RESULT: ${name} .* -> FAIL$" <<<"$out" || ok=0 ;;
  esac

  # El caso más importante: nunca, jamás, "exit 0 + sin resumen".
  if [ "$want_summary" = "none" ] && [ "$rc" -eq 0 ]; then
    ok=0
    echo "  !! FALSO VERDE: $file salió 0 sin resumen" >&2
  fi

  if [ "$ok" = "1" ]; then
    printf '  OK    %-32s rc=%-3s %s\n' "$file" "$rc" "$desc"
  else
    failures=$((failures + 1))
    printf '  FALLA %-32s rc=%-3s %s\n' "$file" "$rc" "$desc" >&2
    echo "--- salida ---" >&2
    echo "$out" >&2
    echo "--------------" >&2
  fi
}

echo "=== selftest del mecanismo anti falso-verde ==="
echo ""

expect_case case_uncaught_exception.php nonzero none "vía 1: excepción no atrapada"
expect_case case_fatal_error.php        nonzero none "vía 2: error fatal de PHP"
expect_case case_premature_exit.php     nonzero none "vía 3: exit(0) prematuro"
expect_case case_green.php              zero    ok   "control: arnés sano pasa"
expect_case case_real_failure.php       nonzero fail "control: fallas reportadas"

echo ""
echo "checks: $checks   fallas: $failures"
if [ "$failures" -gt 0 ]; then
  echo "HARNESS SELFTEST: FALLÓ — el mecanismo anti falso-verde NO es confiable" >&2
  exit 1
fi
echo "HARNESS SELFTEST: OK — las tres vías terminan en rojo"
