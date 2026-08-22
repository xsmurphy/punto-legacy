<?php
declare(strict_types=1);

namespace Punto\Api\Support;

/**
 * Se lanza cuando una query del wrapper PDO (`api/includes/lib/DB.php`)
 * falla con `PDOException` y el fallo NO es un caso ya tipado (cierre de
 * período → `PeriodClosedException`, que se chequea ANTES).
 *
 * POR QUÉ EXISTE
 * --------------
 * Hasta 2026-08-22 el wrapper capturaba el `PDOException`, guardaba el
 * mensaje en `lastError`, hacía `error_log` y devolvía `false`. De 1.602
 * call-sites de `Execute(` solo 4 comparaban el retorno contra `false`, así
 * que en la práctica un error SQL se degradaba a "recordset vacío" y el
 * número equivocado llegaba al usuario con HTTP 200. Dos bugs shipped
 * documentados (context/10-roadmap.md) vivieron meses por eso:
 *
 *   - el `max(uuid)` del reporte de producción (PG UUID v4: `max()` no
 *     existe para `uuid`, la agregación reventaba y el reporte mostraba
 *     vacío en vez de fallar);
 *   - un `23502` (NOT NULL) que hacía que `RoleService::_savePermissions()`
 *     nunca persistiera y respondiera OK igual.
 *
 * Es el caso de libro "arreglar el wrapper, no el call-site" (CLAUDE.md):
 * el DB layer es el único choke point por el que pasan TODAS las queries
 * (`Query::execute`/`ncmExecute`, `AutoExecute`, y los `$db->Execute`
 * directos de los servicios), así que la visibilidad del error se resuelve
 * ahí una vez y no en 1.602 lugares.
 *
 * QUÉ LLEVA (y qué NO)
 * --------------------
 * Mensaje de PG, SQLSTATE, el SQL truncado a 500 chars y el CONTEO de
 * parámetros. Los VALORES de los parámetros NO se guardan nunca: llevan PII
 * del tenant (teléfonos, documentos, nombres, montos) y esta excepción
 * termina en `error_log` y en GlitchTip (monitor.actuo.app, self-hosted; se
 * reporta con el SDK de Sentry, que es el protocolo que GlitchTip habla).
 *
 * MAPEO A HTTP
 * ------------
 * Si nadie la atrapa, `api/bootstrap.php` (set_exception_handler) responde
 * 500 con un mensaje genérico. El SQL y el texto de PG NUNCA salen al
 * cliente — filtrarían el schema. Van a `error_log` y a GlitchTip.
 *
 * CUÁNDO ATRAPARLA
 * ----------------
 * Solo en caminos donde el fallo es TOLERABLE y el fallback es correcto:
 * feature/tabla opcional, o side-effect no crítico de un camino del POS que
 * ya emitió un documento (telemetría, marcado de rollup, notificación
 * realtime, impresión). En esos casos: `try/catch (DbQueryException)` +
 * `error_log` explícito, nunca un catch mudo. Ver el invariante en
 * `context/08-convenciones-criticas.md`.
 *
 * POR QUÉ EXTIENDE `\Exception` Y NO `\RuntimeException`
 * ----------------------------------------------------
 * El repo tiene ~104 `catch (\RuntimeException)` que traducen fallos de
 * NEGOCIO a respuestas amigables (400/422 con el texto de la excepción). Si
 * esta clase heredara de `\RuntimeException`, todos ellos se tragarían los
 * errores de SQL y los re-etiquetarían como fallos de negocio — exactamente
 * lo que este cambio vino a eliminar. El caso peor documentado:
 * `EInvoiceService::issue()` atrapa el `\RuntimeException` de
 * `SaleToInvoiceMapper::build()` y marca el documento fiscal como error
 * PERMANENTE con el texto crudo de PG (`markError`), sin reintento posible.
 *
 * Consecuencia buscada: un error de SQL sin `catch (DbQueryException)`
 * explícito llega a `api/bootstrap.php` y sale como 500 genérico + GlitchTip,
 * que es el contrato. Quien QUIERA tolerarlo lo atrapa por su tipo real.
 */
final class DbQueryException extends \Exception
{
    /** SQLSTATE de PG (`$e->getCode()` del PDOException — es string, ej. '23502'). */
    private string $sqlState;

    /** SQL que falló, truncado a 500 chars. Nunca se muestra al cliente. */
    private string $sql;

    /** Cantidad de parámetros posicionales. Los VALORES no se guardan (PII). */
    private int $paramCount;

    public function __construct(
        string $dbMessage,
        string $sqlState,
        string $sql,
        int $paramCount = 0,
        ?\Throwable $previous = null
    ) {
        $this->sqlState   = $sqlState;
        $this->sql        = mb_substr($sql, 0, 500);
        $this->paramCount = $paramCount;

        // `code` de Exception es int; el SQLSTATE de PG es
        // alfanumérico ('23505', 'PC001', '42703'), así que el string
        // canónico vive en $sqlState y sqlState() es el getter a usar.
        // `(int)` acá es best-effort para compatibilidad con quien lea
        // getCode(); NUNCA compares el SQLSTATE contra getCode().
        parent::__construct($dbMessage, (int) $sqlState, $previous);
    }

    /** SQLSTATE de PG como string ('23505', '23502', '42703', ...). */
    public function sqlState(): string
    {
        return $this->sqlState;
    }

    /** SQL que falló (truncado). Para logs/diagnóstico, nunca para el cliente. */
    public function sql(): string
    {
        return $this->sql;
    }

    /** Cantidad de parámetros posicionales del statement que falló. */
    public function paramCount(): int
    {
        return $this->paramCount;
    }
}
