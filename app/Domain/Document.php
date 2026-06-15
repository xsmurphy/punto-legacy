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
