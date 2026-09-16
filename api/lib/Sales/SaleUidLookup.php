<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

use DB;

/**
 * Busca una venta registrada por su `transactionUID`, SIEMPRE dentro de un
 * tenant. Fuente única de las dos preguntas del money-path que dependen del uid:
 *
 *   1. `SaleService::save()` / `abortSale()` — "¿este cobro ya existe?" antes de
 *      declarar un duplicado. Duplicado solo se confirma si la venta EXISTE en
 *      el tenant del request; un 23505 sobre el uid sin fila visible (otro
 *      tenant, carrera con rollback) es un error real, no un éxito.
 *   2. `GET /v1/sales?uid=` — el POS resolviendo un cobro de resultado ambiguo
 *      (timeout / red / 5xx) antes de volver a cobrar el mismo objeto.
 *
 * POR QUÉ `transaction_registry` Y NO `transaction`
 * ------------------------------------------------
 * `transaction` está particionada por fecha (mig 156) y la unicidad del uid
 * vive en el registry (`transaction_transactionuid_key`), que es la única tabla
 * con un índice sobre la columna. Buscar el uid en `transaction` recorre todas
 * las particiones. El registry da el `transactionid` + la fecha, y con la fecha
 * el join a `transaction` poda a UNA partición.
 *
 * POR QUÉ `companyid` ES OBLIGATORIO
 * ---------------------------------
 * La unicidad del uid es GLOBAL, pero la respuesta no puede serlo: devolverle a
 * un tenant el `transactionId` —o siquiera la existencia— de una venta de otro
 * es una fuga cross-tenant. El pre-check viejo de `save()` buscaba el uid sin
 * `companyId`.
 */
final class SaleUidLookup
{
    /** Tope de `transaction_registry.transactionuid` (varchar(50)). */
    public const UID_MAX_LENGTH = 50;

    public function __construct(private readonly DB $db)
    {
    }

    /**
     * @param string|null $outletId Si viene, acota además a esa sucursal (el
     *                              device del POS solo ve lo de la suya).
     */
    public function find(string $uid, string $companyId, ?string $outletId = null): ?ExistingSale
    {
        if ($uid === '' || strlen($uid) > self::UID_MAX_LENGTH || $companyId === '') {
            return null;
        }

        $sql = 'SELECT r.transactionid, r.transactionuid, r.transactiontype, r.outletid, r.registerid,
                       r.invoiceno, r.invoiceprefix, r.invoiceserie, r.invoiceauth,
                       r.transactiondate, t.transactiontotal
                  FROM transaction_registry r
                  LEFT JOIN transaction t
                         ON t.transactionid = r.transactionid
                        AND t.transactiondate = r.transactiondate
                 WHERE r.transactionuid = ?
                   AND r.companyid = ?';
        $params = [$uid, $companyId];
        if ($outletId !== null && $outletId !== '') {
            $sql .= ' AND r.outletid = ?';
            $params[] = $outletId;
        }
        $sql .= ' LIMIT 1';

        $rs = $this->db->Execute($sql, $params);
        if (!$rs || $rs->EOF) {
            return null;
        }
        $row = $rs->fields;

        $transactionId = (string) $row['transactionid'];

        // El link del portal es un adorno del comprobante: si no se puede
        // firmar, la venta sigue existiendo y el duplicado se resuelve igual.
        $portalUrl = null;
        try {
            $portalUrl = (new \Punto\Api\EInvoice\EInvoiceService())->portalUrl($companyId, $transactionId);
        } catch (\Throwable $e) {
            error_log('[SaleUidLookup] portalUrl: ' . $e->getMessage());
        }

        return new ExistingSale(
            transactionId:     $transactionId,
            uid:               (string) $row['transactionuid'],
            type:              (int) $row['transactiontype'],
            outletId:          (string) $row['outletid'],
            registerId:        $row['registerid'] !== null ? (string) $row['registerid'] : null,
            invoiceNo:         $row['invoiceno'] !== null ? (int) $row['invoiceno'] : null,
            invoicePrefix:     $row['invoiceprefix'] !== null ? (string) $row['invoiceprefix'] : null,
            invoiceSerie:      $row['invoiceserie'] !== null ? (string) $row['invoiceserie'] : null,
            invoiceAuth:       $row['invoiceauth'] !== null ? (string) $row['invoiceauth'] : null,
            total:             $row['transactiontotal'] !== null ? (float) $row['transactiontotal'] : null,
            date:              $row['transactiondate'] !== null ? (string) $row['transactiondate'] : null,
            einvoicePortalUrl: $portalUrl,
        );
    }
}
