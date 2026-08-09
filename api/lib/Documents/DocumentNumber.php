<?php
declare(strict_types=1);

namespace Punto\Api\Documents;

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
 * EXCEPCIÓN offline: el POS arrienda números por adelantado
 * (`numbering_lease`) para facturar sin red. Un arriendo nunca consumido ES un
 * hueco, y eso es inherente al modo offline, no de este asignador.
 */
final class DocumentNumber
{
    public const SCOPE_REGISTER = 'register';
    public const SCOPE_OUTLET   = 'outlet';
    public const SCOPE_COMPANY  = 'company';

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
     * Reserva un BLOQUE contiguo de $count números y devuelve el primero.
     *
     * Existe para el POS offline: `numbering_lease` arrienda ~100 números por
     * adelantado para poder facturar sin red. Hacer N llamadas a allocate()
     * daría N round-trips y, peor, no garantiza contigüidad si otra caja de la
     * misma secuencia asigna en el medio.
     *
     * @return int el PRIMER número del bloque; el último es $first + $count - 1.
     *
     * @throws RangeExhaustedException si el bloque se pasa del rango autorizado.
     */
    public static function allocateBlock(
        string $docType,
        string $scopeType,
        string $scopeId,
        string $companyId,
        int $count,
    ): int {
        global $db;

        if ($count < 1) {
            throw new \InvalidArgumentException('count debe ser >= 1');
        }
        if (!in_array($scopeType, [self::SCOPE_REGISTER, self::SCOPE_OUTLET, self::SCOPE_COMPANY], true)) {
            throw new \InvalidArgumentException('scopeType inválido: ' . $scopeType);
        }

        $rs = $db->Execute(
            'INSERT INTO document_sequence
                 (companyid, doctype, scopetype, scopeid, nextnumber)
             VALUES (?, ?, ?, ?, 1 + ?::bigint)
             ON CONFLICT (companyid, doctype, scopetype, scopeid)
             DO UPDATE SET nextnumber = document_sequence.nextnumber + ?::bigint,
                           updated_at = now()
             RETURNING nextnumber - ?::bigint AS allocated, rangeto',
            [$companyId, $docType, $scopeType, $scopeId, $count, $count, $count]
        );

        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException(
                'No se pudo asignar el bloque de números para el documento ' . $docType
            );
        }

        $first   = (int) ($rs->fields['allocated'] ?? 0);
        $rangeTo = $rs->fields['rangeto'] ?? null;

        if ($first < 1) {
            throw new \RuntimeException(
                'Bloque asignado inválido para el documento ' . $docType
            );
        }

        // Se valida el ÚLTIMO del bloque: arrendar números por encima del rango
        // autorizado dejaría al POS offline emitiendo fuera de timbrado sin red
        // para enterarse.
        if ($rangeTo !== null && ($first + $count - 1) > (int) $rangeTo) {
            throw new RangeExhaustedException(
                docType: $docType,
                rangeTo: (int) $rangeTo,
            );
        }

        return $first;
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
}
