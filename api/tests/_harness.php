<?php
declare(strict_types=1);

/**
 * Guard anti "falso verde" de los arneses CLI.
 *
 * ── El defecto que cierra ────────────────────────────────────────────────────
 *
 * Los arneses de `api/tests/` corrían contra Postgres real y el runner `.sh`
 * decidía verde/rojo mirando UNA sola señal: el exit code de `php`. Esa señal
 * mentía en tres situaciones distintas, y en las tres el runner imprimía
 * "TODO OK" sin que se hubiera evaluado una sola aserción:
 *
 *   1. **Excepción no atrapada.** `api/includes/error_handlers.php` registra un
 *      `set_exception_handler` (para que la API devuelva un JSON 500 limpio en
 *      vez de una respuesta vacía). Un handler que retorna normalmente CONSUME
 *      la excepción: PHP ya no aplica su exit code 255 y el proceso termina en
 *      0. En HTTP eso es correcto; en CLI convierte cualquier explosión en un
 *      verde.
 *   2. **Error fatal de PHP** (E_ERROR / E_PARSE / método inexistente...). No
 *      pasa por el exception handler y, según cómo termine el request, tampoco
 *      garantiza exit code ≠ 0.
 *   3. **Terminación prematura.** Un `die()`/`exit(0)` en medio del archivo, o
 *      un `return` temprano: el arnés nunca llega a su resumen, pero sale 0.
 *
 * Este archivo cubre (2) y (3), y refuerza (1). El (1) se ataca además en la
 * raíz compartida — `error_handlers.php` sale con código ≠ 0 cuando
 * `PHP_SAPI === 'cli'` — porque ese handler lo comparten TODOS los arneses y
 * también los workers/scripts CLI, no solo estos ocho.
 *
 * ── Contrato ────────────────────────────────────────────────────────────────
 *
 * Se hace `require` como PRIMERA instrucción del arnés, ANTES de
 * `bootstrap.php`: así su `register_shutdown_function` queda registrado
 * primero y corre primero, y el guard sigue funcionando aunque el bootstrap
 * explote al cargar.
 *
 * El arnés termina SIEMPRE con `harnessFinish($failures)`, que imprime la
 * línea canónica que el runner `.sh` exige ver:
 *
 *     HARNESS RESULT: <nombre> checks=<n> failures=<n> -> OK|FAIL
 *
 * Cualquier otra forma de terminar es roja por construcción: sin esa línea el
 * runner NO imprime "TODO OK" aunque el exit code sea 0.
 */

if (PHP_SAPI !== 'cli') {
    // Defensa en profundidad: este archivo no tiene nada que hacer sirviendo
    // requests. Si alguien lo incluye desde un endpoint, es un no-op.
    return;
}

/** Código de salida de "el arnés no llegó a decir nada" (distinguible del 1 = falló una aserción). */
const HARNESS_EXIT_ABORTED = 70;

$GLOBALS['__puntoHarnessName']      = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'harness'), '.php');
$GLOBALS['__puntoHarnessCompleted'] = false;
$GLOBALS['__puntoHarnessReported']  = false;

/**
 * Marca el arnés como terminado e imprime la línea canónica de resumen.
 *
 * Es `never`: siempre sale del proceso. `$failures === 0` → exit 0, si no 1.
 */
function harnessFinish(int $failures, ?int $checks = null): void
{
    $GLOBALS['__puntoHarnessCompleted'] = true;

    $name   = (string) $GLOBALS['__puntoHarnessName'];
    $status = $failures === 0 ? 'OK' : 'FAIL';
    $checksTxt = $checks === null ? '?' : (string) $checks;

    echo "\n";
    echo $failures === 0
        ? "Todos los casos OK.\n"
        : "$failures caso(s) fallido(s).\n";
    echo "HARNESS RESULT: $name checks=$checksTxt failures=$failures -> $status\n";

    exit($failures === 0 ? 0 : 1);
}

/**
 * Reporte compartido de "el arnés murió". Lo usan el shutdown guard de acá y
 * el exception handler CLI de `error_handlers.php` (vía `function_exists`).
 */
function harnessReportAbort(string $reason, string $detail = ''): void
{
    if (!empty($GLOBALS['__puntoHarnessReported'])) {
        return; // ya se reportó la causa raíz; no duplicar ruido
    }
    $GLOBALS['__puntoHarnessReported'] = true;

    $name = (string) ($GLOBALS['__puntoHarnessName'] ?? 'harness');
    fwrite(STDERR, "\n");
    fwrite(STDERR, "================================================================\n");
    fwrite(STDERR, "ARNÉS ABORTADO: $name\n");
    fwrite(STDERR, "Motivo: $reason\n");
    if ($detail !== '') {
        fwrite(STDERR, $detail . "\n");
    }
    fwrite(STDERR, "NINGUNA aserción es concluyente. Esto NO es un verde.\n");
    fwrite(STDERR, "================================================================\n");

    // También por stdout: el runner captura la salida combinada y la línea
    // canónica brilla por su ausencia, pero conviene que el humano que lee el
    // log vea el motivo en el mismo stream que el resto del arnés.
    echo "\nHARNESS RESULT: $name checks=? failures=? -> ABORTED ($reason)\n";
}

register_shutdown_function(static function (): void {
    // Vía 2: error fatal de PHP. No pasa por set_exception_handler.
    $err   = error_get_last();
    $fatal = $err !== null
        && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);

    if ($fatal) {
        harnessReportAbort(
            'error fatal de PHP',
            '  ' . $err['message'] . "\n  @ " . $err['file'] . ':' . $err['line']
        );
        exit(HARNESS_EXIT_ABORTED);
    }

    // Vía 3: terminó sin haber impreso su resumen (die/exit temprano, return,
    // o una excepción que el handler de error_handlers.php ya consumió).
    if (empty($GLOBALS['__puntoHarnessCompleted'])) {
        harnessReportAbort('terminó sin imprimir su línea de resumen (harnessFinish() nunca corrió)');
        exit(HARNESS_EXIT_ABORTED);
    }
});
