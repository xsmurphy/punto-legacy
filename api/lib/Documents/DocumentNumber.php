<?php
declare(strict_types=1);

namespace Punto\Api\Documents;

use Punto\Api\Sales\SaleType;

/**
 * Asignador único de numeración correlativa de documentos (context/37, mig 117).
 *
 * Reemplaza los tres mecanismos que conviven hoy — `MAX(invoiceNo)+1`,
 * `registerQuoteNumber` y `MAX(ordernumber)+1` — y los 9 contadores de
 * `register` (mig 26).
 *
 * Por qué los anteriores no servían como correlativo:
 *   - `MAX(...)+1` es una derivación, no una secuencia: si se borra la última
 *     fila el número se reemite, y no puede expresar "el timbrado arranca en
 *     1234".
 *   - `RegisterService::nextDocNumber()` es lectura pura: devuelve
 *     `max(contador, último usado)` y deja el UPDATE al caller, así que dos
 *     cajeros concurrentes obtienen el MISMO número.
 *
 * Acá la asignación es un solo statement. `UPDATE ... RETURNING` toma el row
 * lock de PG: es atómico sin advisory lock y sin escanear la tabla del
 * documento.
 *
 * ── Llamar SIEMPRE dentro de la transacción del documento ──
 * Si la operación falla, el rollback revierte también el incremento y no queda
 * hueco (D1, context/37). El costo es que las emisiones concurrentes de esa
 * misma secuencia se serializan mientras la transacción está abierta — para
 * una caja, que es de un cajero, la contención es teórica.
 *
 * OFFLINE (factura, context/29-numeracion-y-exclusividad-de-caja.md): el POS
 * NO llama a `allocate()` para facturar sin red — el arriendo de bloques por
 * adelantado (`numbering_lease`) que existía para eso fue RECHAZADO por el
 * owner 2026-08-17 (§6: la unicidad del punto de expedición ya resuelve sola
 * el problema — cada caja tiene su propia rama de numeración, no hay con
 * quién chocar). El device decide su propio número offline ("último
 * correlativo de mi caja + 1", `frontend/lib/pos/invoice-numbering.ts`) y lo
 * manda en el payload, tanto online como offline. `advanceTo()` es el
 * complemento: mantiene esta secuencia consistente con lo que el device ya
 * emitió, sin ser quien decide el número.
 */
final class DocumentNumber
{
    public const SCOPE_REGISTER = 'register';
    public const SCOPE_OUTLET   = 'outlet';
    public const SCOPE_COMPANY  = 'company';

    /**
     * Ancho por defecto del correlativo impreso: `EEE-PPP-NNNNNNN`, formato
     * fiscal PY (context/29 §1). Espejo del DEFAULT de
     * `document_sequence.padwidth` (mig 158) — si cambia uno, cambia el otro.
     */
    public const DEFAULT_PAD_WIDTH = 7;

    /**
     * Reserva y devuelve el próximo número del documento.
     *
     * @param string $docType   'factura' | 'cotizacion' | 'orden' | ...
     * @param string $scopeType una de las constantes SCOPE_*
     * @param string $scopeId   registerId | outletId | companyId según $scopeType
     *
     * @throws RangeExhaustedException si el número cae fuera del rango
     *         autorizado del timbrado (`rangeto`).
     * @throws \RuntimeException si la secuencia no pudo asignarse.
     */
    public static function allocate(
        string $docType,
        string $scopeType,
        string $scopeId,
        string $companyId,
    ): int {
        global $db;

        if (!in_array($scopeType, [self::SCOPE_REGISTER, self::SCOPE_OUTLET, self::SCOPE_COMPANY], true)) {
            throw new \InvalidArgumentException('scopeType inválido: ' . $scopeType);
        }

        // Un solo statement resuelve los dos casos. Si la secuencia no existe
        // todavía (documento que nunca se emitió en ese scope), el INSERT la
        // crea con nextnumber=2 y devuelve 1; si existe, el DO UPDATE la
        // incrementa y devuelve el valor previo. Sin SELECT-then-INSERT, que
        // sería una race entre dos cajas arrancando a la vez.
        $rs = $db->Execute(
            'INSERT INTO document_sequence
                 (companyid, doctype, scopetype, scopeid, nextnumber)
             VALUES (?, ?, ?, ?, 2)
             ON CONFLICT (companyid, doctype, scopetype, scopeid)
             DO UPDATE SET nextnumber = document_sequence.nextnumber + 1,
                           updated_at = now()
             RETURNING nextnumber - 1 AS allocated, rangeto',
            [$companyId, $docType, $scopeType, $scopeId]
        );

        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException(
                'No se pudo asignar número para el documento ' . $docType
            );
        }

        $allocated = (int) ($rs->fields['allocated'] ?? 0);
        $rangeTo   = $rs->fields['rangeto'] ?? null;

        if ($allocated < 1) {
            throw new \RuntimeException(
                'Número asignado inválido para el documento ' . $docType
            );
        }

        // Fin del rango autorizado: emitir por encima sería emitir fuera de
        // timbrado. Se lanza DESPUÉS de incrementar a propósito — al estar en
        // la transacción del documento, el rollback devuelve el número y la
        // secuencia queda intacta.
        //
        // El aviso anticipado (avisar faltando N) es F4 y depende de D5; acá
        // está solo el corte duro, que es el mínimo legal.
        if ($rangeTo !== null && $allocated > (int) $rangeTo) {
            throw new RangeExhaustedException(
                docType: $docType,
                rangeTo: (int) $rangeTo,
            );
        }

        return $allocated;
    }

    /**
     * Avanza la secuencia para que quede consistente con un número que el
     * DEVICE ya decidió y emitió (online o offline) — nunca reserva ni
     * decide el número, solo se asegura de que `document_sequence` no quede
     * atrás. Reemplaza a `allocateBlock()` (arriendo de bloques, RECHAZADO
     * 2026-08-17 — ver docblock de clase): el device manda `invoiceNo` en
     * cada venta (`api/v1/sales.php`, `api/v1/offline-sync.php`), y esta
     * llamada es el único lugar donde el server toca la secuencia en
     * respuesta a eso.
     *
     * `nextnumber` queda en `GREATEST(nextnumber, $invoiceNo + 1)` — nunca
     * retrocede. Necesario porque el device puede haber emitido offline por
     * delante de lo que el server conoce, y porque dos syncs (o una venta
     * online + un sync offline atrasado) pueden llegar fuera de orden.
     *
     * NO se llama dentro de la transacción del documento a propósito (a
     * diferencia de `allocate()`): si esto fallara y revirtiera junto con la
     * venta, se perdería una venta YA EMITIDA por el device por un problema
     * de contabilidad interna del servidor — viola §53 (el backend nunca
     * rechaza/revierte una venta ya emitida). Un fallo acá deja la secuencia
     * momentáneamente atrás; el próximo `advanceTo()` con un número mayor la
     * corrige sola.
     */
    public static function advanceTo(
        string $docType,
        string $scopeType,
        string $scopeId,
        string $companyId,
        int $invoiceNo,
    ): void {
        global $db;

        if (!in_array($scopeType, [self::SCOPE_REGISTER, self::SCOPE_OUTLET, self::SCOPE_COMPANY], true)) {
            throw new \InvalidArgumentException('scopeType inválido: ' . $scopeType);
        }
        if ($invoiceNo < 1) {
            return;
        }

        $db->Execute(
            'INSERT INTO document_sequence
                 (companyid, doctype, scopetype, scopeid, nextnumber)
             VALUES (?, ?, ?, ?, ?::bigint + 1)
             ON CONFLICT (companyid, doctype, scopetype, scopeid)
             DO UPDATE SET nextnumber = GREATEST(document_sequence.nextnumber, ?::bigint + 1),
                           updated_at = now()',
            [$companyId, $docType, $scopeType, $scopeId, $invoiceNo, $invoiceNo]
        );
    }

    /**
     * Número que se asignaría, SIN reservarlo. Solo para mostrar en pantalla
     * (ej. "próxima factura" en Ajustes).
     *
     * NUNCA usar para emitir: entre la lectura y el uso, otro proceso puede
     * haber tomado el número. Ese es exactamente el defecto de
     * `RegisterService::nextDocNumber()` que este servicio viene a corregir.
     */
    public static function peek(
        string $docType,
        string $scopeType,
        string $scopeId,
        string $companyId,
    ): int {
        $row = ncmExecute(
            'SELECT nextnumber FROM document_sequence
              WHERE companyid = ? AND doctype = ? AND scopetype = ? AND scopeid = ?
              LIMIT 1',
            [$companyId, $docType, $scopeType, $scopeId]
        );

        return $row ? (int) ($row['nextnumber'] ?? 1) : 1;
    }

    // ════════════════════════════════════════════════════════════════════
    //  FORMATO — el correlativo es un entero; los ceros son presentación
    // ════════════════════════════════════════════════════════════════════
    //
    // `nextnumber` e `invoiceNo` son BIGINT a propósito: el asignador hace
    // `nextnumber + 1`, el timbrado compara contra `rangeto` y la unicidad
    // por caja (mig 145) es sobre el entero. Meter los ceros DENTRO del valor
    // obligaría a que todo eso fuera texto y "00002129" > "10" sería falso.
    //
    // Por eso hay UN solo formateador y vive acá, al lado del asignador: el
    // ancho es un atributo del talonario (`document_sequence.padwidth`,
    // mig 158), igual que el prefijo y el rango. Antes cada consumidor hacía
    // su propio `str_pad` leyendo `register.data.registerDocsLeadingZeros`,
    // y ya habían divergido: TransactionDetailService componía
    // `prefix . numero` (sin guion) mientras FiscalService y
    // TransactionsService componían `prefix . '-' . numero`. El mismo
    // documento salía con dos números distintos según qué pantalla lo
    // pintara.

    /**
     * Correlativo con ceros a la izquierda, SIN prefijo.
     *
     * Devuelve '' para un número ausente o < 1: una transacción sin
     * `invoiceNo` no tiene número, y rellenarla daría el "0000000" fantasma
     * que producía el `str_pad('')` de los call-sites viejos.
     */
    public static function pad(int|string|null $number, ?int $padWidth = null): string
    {
        $n = (int) $number;
        if ($n < 1) {
            return '';
        }
        $w = self::normalizePadWidth($padWidth);

        return str_pad((string) $n, $w, '0', STR_PAD_LEFT);
    }

    /**
     * Número de documento completo, tal como se imprime: `001-001-0002129`.
     *
     * El guion entre prefijo y correlativo es parte del formato fiscal PY
     * (context/29 §1). Sin prefijo devuelve solo el correlativo padeado —
     * NO inventa un separador huérfano.
     *
     * @param string|null $prefix "EEE-PPP" del timbrado, o el prefijo de
     *                            devolución cuando el documento es una NC.
     */
    public static function format(
        int|string|null $number,
        ?string $prefix = null,
        ?int $padWidth = null,
    ): string {
        $padded = self::pad($number, $padWidth);
        $pfx    = trim((string) $prefix);

        if ($pfx === '') {
            return $padded;
        }
        if ($padded === '') {
            return $pfx;
        }

        return $pfx . '-' . $padded;
    }

    /**
     * Igual que `format()`, pero el prefijo y el ancho salen de la secuencia
     * en vez de que los pase el caller. Para los consumidores que tienen el
     * scope a mano y no cargaron la caja (ej. un reimpreso puntual).
     *
     * Los listados NO deberían usar esto en un loop — es un SELECT por fila.
     * Ahí va `Reports\TransactionsService::registerInfo()`, que trae prefijo
     * y ancho de todas las cajas del listado en una sola query.
     */
    public static function formatFor(
        string $docType,
        string $scopeType,
        string $scopeId,
        string $companyId,
        int|string|null $number,
    ): string {
        $meta = self::sequenceMeta($docType, $scopeType, $scopeId, $companyId);

        return self::format($number, $meta['prefix'], $meta['padWidth']);
    }

    /**
     * Prefijo y ancho declarados de una secuencia. Fallback al default legal
     * cuando la fila todavía no existe — la caja se siembra al crearse
     * (`RegisterAdminService::create`), así que esto solo pega en datos
     * anteriores a la mig 127.
     *
     * @return array{prefix: string, padWidth: int}
     */
    public static function sequenceMeta(
        string $docType,
        string $scopeType,
        string $scopeId,
        string $companyId,
    ): array {
        $row = ncmExecute(
            'SELECT prefix, padwidth FROM document_sequence
              WHERE companyid = ? AND doctype = ? AND scopetype = ? AND scopeid = ?
              LIMIT 1',
            [$companyId, $docType, $scopeType, $scopeId]
        );

        return [
            'prefix'   => (string) ($row['prefix'] ?? ''),
            'padWidth' => self::normalizePadWidth($row ? ($row['padwidth'] ?? null) : null),
        ];
    }

    /**
     * Documento al que pertenece la numeración de una transacción.
     *
     * `factura` y `cotizacion` comparten la columna `invoiceNo` pero son
     * talonarios distintos, con su propio contador y su propio ancho — el
     * mapeo sale del enum, no de literales sueltos (misma razón que el
     * `match` de `RegisterAdminService::update`).
     *
     * Devuelve `null` cuando el tipo no tiene talonario propio hoy: el caller
     * cae al default y no inventa una secuencia inexistente.
     */
    public static function docTypeForSaleType(int|string|null $saleType): ?string
    {
        return match ((int) $saleType) {
            SaleType::Cashsale->value, SaleType::Creditsale->value => 'factura',
            SaleType::Quote->value                                => 'cotizacion',
            default                                               => null,
        };
    }

    /**
     * Ancho utilizable: fuera del rango del CHECK de la mig 158 (o ausente)
     * cae al default legal. Nunca lanza — un ancho corrupto no puede impedir
     * que se imprima un documento ya emitido.
     */
    private static function normalizePadWidth(int|string|null $padWidth): int
    {
        if ($padWidth === null || $padWidth === '') {
            return self::DEFAULT_PAD_WIDTH;
        }
        $w = (int) $padWidth;

        return ($w >= 1 && $w <= 12) ? $w : self::DEFAULT_PAD_WIDTH;
    }
}
