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
        $lastUsed = getValue(
            'transaction',
            'invoiceNo',
            'WHERE companyId = ' . $company .
            ' AND registerId = ' . $register .
            ' AND (invoiceNo IS NOT NULL AND invoiceNo > 0)' .
            ' AND transactionType IN(' . $in . ')' .
            ' ORDER BY transactionDate DESC LIMIT 1'
        );

        if ($lastUsed > $number) {
            return $lastUsed;
        }

        return $number;
    }
}
