<?php

/**
 * Handlers globales de excepciones y fatales de la API — REGISTRO ÚNICO.
 *
 * Vivían inline en `api/bootstrap.php`, que es el punto de entrada del realm
 * de tenant/POS. Los 13 endpoints de `/v1/admin/*` NO cargan `bootstrap.php`
 * (entran por `includes/db.php` + `lib/Auth/AdminAuth.php`), así que el realm
 * admin nunca tuvo handler: con `display_errors=0`, cualquier excepción no
 * atrapada ahí devolvía un 500 en blanco, sin cuerpo JSON — exactamente el
 * modo de falla del incidente 2026-06-30 que motivó agregar estos handlers.
 *
 * El hueco pasó de teórico a probable cuando el wrapper DB empezó a LANZAR
 * (`DbQueryException`, 2026-08-22) en vez de devolver `false`, así que la
 * respuesta correcta es la de siempre en este codebase: mover la lógica al
 * lugar compartido, no copiarla en el segundo realm. `AdminAuth.php` la
 * carga; cualquier realm nuevo debería hacer lo mismo.
 *
 * `puntoRegisterErrorHandlers()` es idempotente: llamarla dos veces (el mismo
 * request pasa por bootstrap Y por AdminAuth) no duplica el shutdown handler.
 */

function puntoRegisterErrorHandlers(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    // ── Observabilidad de errores ────────────────────────────────────────────────
    // display_errors sigue en 0 (no filtrar stack traces al cliente), pero los
    // fatales/excepciones DEBEN ser visibles. Incidente 2026-06-30: un
    // "Call to undefined method DB::GetRow()" en pagos a crédito quedó 100% silente
    // (display_errors=0 + log_errors off) y costó horas de diagnóstico. Estos
    // handlers logean a stderr (→ docker logs) y devuelven un JSON 500 limpio en
    // vez de una respuesta vacía/HTML. error_log va a stderr por la config del
    // Dockerfile (log_errors=On, error_log=/proc/self/fd/2).
    // GlitchTip (monitor.actuo.app, self-hosted — habla el protocolo de Sentry,
    // por eso el cliente es el SDK `sentry/sentry` y el DSN va en SENTRY_DSN) se
    // inicializa en api/bootstrap.php, después del autoload y SOLO si SENTRY_DSN
    // está seteado (el realm /admin no lo inicializa). Estos handlers lo invocan
    // vía function_exists: sin DSN, captureException/captureMessage no existen
    // y el reporte es no-op — el error_log + JSON 500 siguen funcionando igual.
    set_exception_handler(static function (\Throwable $e): void {
        error_log('[uncaught] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        // Error SQL que nadie atrapó (DB::Execute y compañía lanzan desde
        // 2026-08-22 en vez de devolver `false` silencioso — ver
        // api/lib/Support/DbQueryException.php). El SQL y el SQLSTATE van al log
        // para poder diagnosticar; NUNCA a la respuesta: el texto de PG filtra
        // nombres de tabla/columna del schema. La respuesta es el 500 genérico
        // de más abajo.
        if ($e instanceof \Punto\Api\Support\DbQueryException) {
            error_log('[db] SQLSTATE ' . $e->sqlState() . ' | params: ' . $e->paramCount() . ' | SQL: ' . $e->sql());
        }
        if (function_exists('\\Sentry\\captureException')) {
            \Sentry\captureException($e);
        }
        if (!headers_sent()) {
            // Guard de cierre de período (mig 157, context/48 D7): endpoints que
            // no atrapan \Throwable ellos mismos llegan hasta acá. Se responde
            // 409 con el mensaje ya amigable de la excepción (armado en
            // api/lib/Support/PeriodClosedException.php) en vez del 500
            // genérico — único lugar del mapeo, ver DB::Execute().
            if ($e instanceof \Punto\Api\Support\PeriodClosedException) {
                http_response_code(409);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => ['message' => $e->getMessage(), 'code' => 409]], JSON_UNESCAPED_UNICODE);
                return;
            }
            // Error SQL: 500 con mensaje GENÉRICO. El `getMessage()` de
            // DbQueryException es el texto crudo de PG ("column t.foo does not
            // exist", "duplicate key value violates unique constraint
            // uq_..."): filtra el schema al cliente, así que no sale nunca.
            // Ya quedó logueado arriba con SQLSTATE + SQL.
            if ($e instanceof \Punto\Api\Support\DbQueryException) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => ['message' => 'Error al procesar la operación', 'code' => 500]], JSON_UNESCAPED_UNICODE);
                return;
            }
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['ok' => false, 'error' => ['message' => 'Error interno del servidor', 'code' => 500]]);
    });
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        error_log('[fatal] ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        if (function_exists('\\Sentry\\captureMessage')) {
            \Sentry\captureMessage(
                '[fatal] ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line'],
                \Sentry\Severity::fatal()
            );
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => ['message' => 'Error interno del servidor', 'code' => 500]]);
        }
    });
}
