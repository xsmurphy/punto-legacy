#!/bin/bash
# =============================================================
# _harness_lib.sh — wrapper compartido para correr un arnés PHP desde su
# runner `.sh`.
#
# ── El defecto que cierra ────────────────────────────────────────────────────
#
# Cada runner hacía:
#
#     php ... "$SCRIPT_DIR/foo_test.php"
#     echo "[run_foo.sh] TODO OK."
#
# Con `set -e`, eso confía en UNA sola señal: el exit code de php. Y esa señal
# mentía — el `set_exception_handler` de `api/includes/error_handlers.php`
# consumía las excepciones y el proceso salía 0 sin haber evaluado una sola
# aserción. Resultado: "TODO OK" sobre un arnés que murió en la línea 3.
#
# `harness_run` agrega la segunda señal, la que no se puede falsificar por
# accidente: el arnés TIENE que haber impreso su línea canónica de resumen
#
#     HARNESS RESULT: <nombre> checks=<n> failures=<n> -> OK
#
# que sólo emite `harnessFinish()` (ver `api/tests/_harness.php`) al final del
# archivo. Sin esa línea el runner es rojo aunque php haya salido 0.
#
# ── Uso ─────────────────────────────────────────────────────────────────────
#
#     source "$SCRIPT_DIR/_harness_lib.sh"
#     harness_run "$SCRIPT_DIR/foo_test.php"
#
# La salida del arnés se muestra en vivo (tee) y además se inspecciona.
# =============================================================

# Opciones de php compartidas por todos los arneses.
HARNESS_PHP_OPTS=(-d variables_order=EGPCS -d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING')

# JWT_SECRET de prueba. `DeviceAuth::issueDeviceToken()` LANZA
# `RuntimeException('JWT_SECRET no configurado')` si no está seteado, y varios
# arneses emiten tokens de device para armar sus fixtures. Ningún runner lo
# exportaba: la excepción mataba el arnés entero a mitad de camino y —antes del
# mecanismo anti falso-verde— el runner igual imprimía "TODO OK".
#
# Va acá, en el wrapper compartido, y no en cada runner: es una precondición de
# entorno común a todos. Se respeta un valor ya exportado por el caller.
# Es un secreto DE PRUEBA contra Postgres descartable — no tiene ni debe tener
# relación con el de producción.
export JWT_SECRET="${JWT_SECRET:-arnes-test-secret-no-usar-en-produccion}"

harness_run() {
  local php_file="$1"; shift
  local name
  name="$(basename "$php_file" .php)"

  local out_file
  out_file="$(mktemp)"

  local rc=0
  # `set -e` no debe abortar acá: necesitamos inspeccionar el exit code Y la
  # salida antes de decidir. Se desactiva con `set +e` en vez de encadenar
  # `|| true`: ese `||` EJECUTA `true` cuando el pipe falla, y eso PISA
  # `PIPESTATUS` con (0) — el exit code real de php se perdía y el wrapper
  # reportaba "php salió 0" incluso sobre un arnés que había abortado con 70.
  set +e
  php "${HARNESS_PHP_OPTS[@]}" "$php_file" "$@" 2>&1 | tee "$out_file"
  rc="${PIPESTATUS[0]}"
  set -e

  local summary
  summary="$(grep -E "^HARNESS RESULT: ${name} .* -> (OK|FAIL)$" "$out_file" || true)"

  if [ -z "$summary" ]; then
    echo "" >&2
    echo "################################################################" >&2
    echo "# FALSO VERDE EVITADO: $name" >&2
    echo "# php salió con código $rc, pero el arnés NUNCA imprimió su línea" >&2
    echo "# de resumen ('HARNESS RESULT: $name ... -> OK|FAIL')." >&2
    echo "# Murió antes de terminar: ninguna aserción es concluyente." >&2
    echo "################################################################" >&2
    rm -f "$out_file"
    return 70
  fi

  if [ "$rc" -ne 0 ]; then
    echo "" >&2
    echo "[$name] FALLÓ (exit $rc) — $summary" >&2
    rm -f "$out_file"
    return "$rc"
  fi

  if ! grep -qE "^HARNESS RESULT: ${name} .* -> OK$" "$out_file"; then
    echo "" >&2
    echo "[$name] exit code 0 pero el resumen dice FAIL — $summary" >&2
    rm -f "$out_file"
    return 1
  fi

  rm -f "$out_file"
  return 0
}
