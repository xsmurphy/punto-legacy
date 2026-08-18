<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * Numeración de documentos del POS (comprobantes, facturas, tickets).
 *
 * Reemplaza las funciones globales (Slice 11 del plan PSR-4):
 *   - getNextDocNumber($number, $in, $company, $register) → Document::getNextDocNumber(...)
 *
 * CRÍTICO — audit trail: getNextDocNumber determina el número de comprobante
 * que aparece en facturas. Semántica preservada VERBATIM.
 */
final class Document
{
    /**
     * Número de documento a usar: el mayor entre $number y el último usado en DB.
     * Equivalente legacy: `getNextDocNumber($number, $in, $company, $register)`.
     *
     * @param mixed $number    Número base (del registro).
     * @param mixed $in        Lista de transaction types para filtrar (ej: "0,3").
     * @param mixed $company   companyId para el WHERE (UUID o int).
     * @param mixed $register  registerId para el WHERE.
     */
    public static function getNextDocNumber(mixed $number, mixed $in, mixed $company, mixed $register): mixed
    {
        // registerId='' (valor real que produce el pago a proveedor —
        // PurchasesService nunca setea registerId, ver CreditPaymentService::
        // insertReceipt) rompía "invalid input syntax for type uuid" contra
        // la columna registerId. ncmExecute atrapa esa excepción y devuelve
        // false — $lastUsed cae a 0 y esta función YA devolvía $number sin
        // crashear (comportamiento sin cambios) — pero el error de Postgres
        // ENVENENA cualquier transacción que lo envuelva (StartTrans() de
        // CreditPaymentService), y el INSERT real que viene después en esa
        // misma transacción fallaba con "25P02 current transaction is
        // aborted". Cortar acá antes de tocar la DB evita el efecto
        // colateral sin tocar la semántica: mismo resultado ($number),
        // ahora sin el side-effect.
        if ($register === '') {
            return $number;
        }
        // $company/$register son UUIDs: van como parámetros `?`. En PostgreSQL
        // interpolarlos crudos en el WHERE (`companyId = 019ead57-...`) tira
        // "trailing junk after numeric literal" — PG no acepta un UUID sin
        // comillas. `getValue()` concatena el WHERE sin binding, por eso acá
        // usamos `ncmExecute` parametrizado. $in es una lista de transactionType
        // (ints) y se interpola inline como en el legacy; $number se compara en
        // PHP, no en SQL. Semántica preservada: el mayor entre $number y el
        // último invoiceNo usado en DB.
        $result = \ncmExecute(
            'SELECT invoiceNo FROM transaction' .
            ' WHERE companyId = ? AND registerId = ?' .
            ' AND (invoiceNo IS NOT NULL AND invoiceNo > 0)' .
            ' AND transactionType IN(' . $in . ')' .
            ' ORDER BY transactionDate DESC LIMIT 1',
            [$company, $register]
        );

        $lastUsed = ($result && isset($result['invoiceNo'])) ? $result['invoiceNo'] : 0;

        if ($lastUsed > $number) {
            return $lastUsed;
        }

        return $number;
    }
}
