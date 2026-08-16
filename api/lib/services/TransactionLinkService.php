<?php
declare(strict_types=1);
namespace Punto\Api\Services;

/**
 * TransactionLinkService — superficie única de lectura/escritura de
 * `transaction_link` (transacción↔transacción) y `order_transaction_link`
 * (orden↔transacción). Reemplaza `transaction.transactionparentid` (mig 115)
 * y `pos_order.saletransactionid` (mig 115). Ningún call-site debe escribir
 * SQL a mano contra estas dos tablas — todo pasa por acá.
 *
 * context/35-transaction-link.md tiene el modelo completo (tabla de `kind`,
 * decisión de vínculo binario sin `amount`).
 *
 * Patrón de service: mismo que OrderCoreService.php — $companyId explícito
 * en cada método público, ncmExecute() para lecturas simples,
 * StartTrans/HasFailedTrans/CompleteTrans para escrituras.
 */
final class TransactionLinkService
{
    /** kinds válidos de transaction_link (debe reflejar el CHECK de mig 115 + 122). */
    public const KINDS = [
        'quote_to_sale', 'credit_payment', 'purchase_payment',
        'return', 'package_session', 'table_merge', 'purchase_credit_note',
    ];

    // ------------------------------------------------------------------
    // transaction_link
    // ------------------------------------------------------------------

    /**
     * Crea el vínculo origin→derived, con `$amount` opcional (mig 123 — monto
     * imputado a ESTE vínculo puntual; null = "vale el total del documento
     * derivado", semántica histórica). Idempotente: el UNIQUE
     * (companyid, originid, derivedid, kind) no revienta en doble llamada.
     *
     * `DO UPDATE SET amount = EXCLUDED.amount` en vez de `DO NOTHING`: un
     * re-link con `DO NOTHING` ignoraría silenciosamente un `$amount` nuevo
     * si la fila ya existía (ej. reintento de red tras timeout con el mismo
     * par origin/derived pero remonto ajustado). Para los kinds que nunca
     * pasan `$amount` (return, quote_to_sale, etc.) esto es un no-op: siempre
     * escriben NULL, así que "actualizar" a NULL no cambia nada.
     */
    public function link(string $companyId, string $originId, string $derivedId, string $kind, ?float $amount = null): void
    {
        global $db;
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("kind inválido para transaction_link: {$kind}");
        }
        $db->Execute(
            'INSERT INTO transaction_link (companyid, originid, derivedid, kind, amount)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (companyid, originid, derivedid, kind)
             DO UPDATE SET amount = EXCLUDED.amount',
            [$companyId, $originId, $derivedId, $kind, $amount]
        );
    }

    /**
     * IDs de los documentos que DERIVAN de $originId (ej. pagos de una
     * factura a crédito, notas de crédito de una venta). Filtrable por kind.
     *
     * @return list<string>
     */
    public function listDerivedIds(string $companyId, string $originId, ?string $kind = null): array
    {
        $sql    = 'SELECT derivedid FROM transaction_link WHERE companyid = ? AND originid = ?';
        $params = [$companyId, $originId];
        if ($kind !== null) {
            $sql      .= ' AND kind = ?';
            $params[]  = $kind;
        }
        return $this->fetchColumn($sql, $params, 'derivedid');
    }

    /**
     * IDs de los documentos ORIGEN de $derivedId (ej. la venta original de
     * una devolución, la cotización de la que nació una venta).
     *
     * @return list<string>
     */
    public function listOriginIds(string $companyId, string $derivedId, ?string $kind = null): array
    {
        $sql    = 'SELECT originid FROM transaction_link WHERE companyid = ? AND derivedid = ?';
        $params = [$companyId, $derivedId];
        if ($kind !== null) {
            $sql      .= ' AND kind = ?';
            $params[]  = $kind;
        }
        return $this->fetchColumn($sql, $params, 'originid');
    }

    /**
     * Versión batch de listDerivedIds() para reportes que agregan sobre
     * muchos orígenes a la vez (ej. deuda de N facturas a crédito). Devuelve
     * un mapa originId → lista de derivedIds, UNA sola query.
     *
     * @param list<string> $originIds
     * @return array<string, list<string>>
     */
    public function mapDerivedIdsByOrigins(string $companyId, array $originIds, ?string $kind = null): array
    {
        $originIds = array_values(array_unique(array_filter($originIds, static fn ($v) => $v !== '' && $v !== null)));
        $map = [];
        if ($originIds === []) {
            return $map;
        }
        // IN($placeholders), no ANY(?) — mismo patrón que el resto del
        // codebase para IN batch (ver TransactionService::getMainList).
        $placeholders = implode(',', array_fill(0, count($originIds), '?'));
        $sql          = "SELECT originid, derivedid FROM transaction_link WHERE companyid = ? AND originid IN ($placeholders)";
        $params       = array_merge([$companyId], $originIds);
        if ($kind !== null) {
            $sql      .= ' AND kind = ?';
            $params[]  = $kind;
        }
        $rs = ncmExecute($sql, $params, false, true);
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $o = (string) ($f['originid'] ?? '');
                $d = (string) ($f['derivedid'] ?? '');
                if ($o !== '') {
                    $map[$o][] = $d;
                }
                $rs->MoveNext();
            }
        }
        return $map;
    }

    /**
     * Versión batch de listOriginIds() — para reportes que antes leían
     * `transaction.transactionparentid` directo de N filas derivadas (pagos,
     * devoluciones, citas de paquete) y necesitan su origen (venta, compra)
     * sin N+1. "Primero gana" por derivedId — el modelo viejo era de un solo
     * padre por fila, así que en la práctica hay a lo sumo un originid por
     * kind para cada derivedId.
     *
     * @param list<string> $derivedIds
     * @return array<string, string> derivedId → originId (solo entradas con vínculo)
     */
    public function mapOriginIdByDerivedIds(string $companyId, array $derivedIds, ?string $kind = null): array
    {
        $derivedIds = array_values(array_unique(array_filter($derivedIds, static fn ($v) => $v !== '' && $v !== null)));
        $map = [];
        if ($derivedIds === []) {
            return $map;
        }
        $placeholders = implode(',', array_fill(0, count($derivedIds), '?'));
        $sql          = "SELECT derivedid, originid FROM transaction_link WHERE companyid = ? AND derivedid IN ($placeholders)";
        $params       = array_merge([$companyId], $derivedIds);
        if ($kind !== null) {
            $sql      .= ' AND kind = ?';
            $params[]  = $kind;
        }
        $rs = ncmExecute($sql, $params, false, true);
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $d = (string) ($f['derivedid'] ?? '');
                if ($d !== '' && !isset($map[$d])) {
                    $map[$d] = (string) ($f['originid'] ?? '');
                }
                $rs->MoveNext();
            }
        }
        return $map;
    }

    /**
     * TODOS los vínculos origin→derived de una empresa, sin filtrar por
     * origen conocido de antemano — para reportes company-wide (dashboard)
     * que antes hacían `GROUP BY transactionparentid` directo sobre toda la
     * tabla. Usar con cuidado en tenants grandes (sin paginar).
     *
     * @return array<string, list<string>> originId → derivedIds
     */
    public function mapAllDerivedIdsByOrigin(string $companyId, ?string $kind = null): array
    {
        $sql    = 'SELECT originid, derivedid FROM transaction_link WHERE companyid = ?';
        $params = [$companyId];
        if ($kind !== null) {
            $sql      .= ' AND kind = ?';
            $params[]  = $kind;
        }
        $rs  = ncmExecute($sql, $params, false, true);
        $map = [];
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $o = (string) ($f['originid'] ?? '');
                $d = (string) ($f['derivedid'] ?? '');
                if ($o !== '') {
                    $map[$o][] = $d;
                }
                $rs->MoveNext();
            }
        }
        return $map;
    }

    /**
     * Cuánto se imputó a $originId vía vínculos de un $kind dado — LA
     * superficie única para "cuánto se pagó/acreditó de este documento"
     * (mig 123). `COALESCE(tl.amount, t.transactionTotal)`: si el vínculo no
     * tiene monto propio (NULL, semántica histórica) vale el total del
     * documento derivado completo; si lo tiene (recibo repartido en varias
     * facturas), vale solo lo imputado a ESTE vínculo.
     *
     * `COALESCE(t.transactionStatus, 1) <> 6` excluye anulados — el COALESCE
     * NO es cosmético: sin él una fila legacy con status NULL da NULL (no
     * true) en la comparación y sale del SUM, inflando el saldo pendiente
     * (mismo criterio que Reports\OpenInvoicesService::payedByParent).
     */
    public function sumDerivedAmounts(string $companyId, string $originId, string $kind): float
    {
        $row = ncmExecute(
            'SELECT COALESCE(SUM(COALESCE(tl.amount, t.transactionTotal)), 0) AS total
               FROM transaction_link tl
               JOIN transaction t ON t.transactionId = tl.derivedid AND t.companyId = tl.companyid
              WHERE tl.companyid = ? AND tl.originid = ? AND tl.kind = ?
                AND COALESCE(t.transactionStatus, 1) <> 6',
            [$companyId, $originId, $kind]
        );
        return (float) ($row['total'] ?? 0);
    }

    /**
     * Versión batch de sumDerivedAmounts() — para reportes que agregan sobre
     * muchos orígenes a la vez (ej. deuda de N facturas a crédito en una sola
     * pantalla) sin hacer N queries.
     *
     * @param list<string> $originIds
     * @return array<string, float> originId → monto sumado (0.0 si no tiene vínculos)
     */
    public function mapSumDerivedAmounts(string $companyId, array $originIds, string $kind): array
    {
        $originIds = array_values(array_unique(array_filter($originIds, static fn ($v) => $v !== '' && $v !== null)));
        $map = [];
        if ($originIds === []) {
            return $map;
        }
        $placeholders = implode(',', array_fill(0, count($originIds), '?'));
        $sql = "SELECT tl.originid AS originid,
                       COALESCE(SUM(COALESCE(tl.amount, t.transactionTotal)), 0) AS total
                  FROM transaction_link tl
                  JOIN transaction t ON t.transactionId = tl.derivedid AND t.companyId = tl.companyid
                 WHERE tl.companyid = ? AND tl.originid IN ($placeholders) AND tl.kind = ?
                   AND COALESCE(t.transactionStatus, 1) <> 6
                 GROUP BY tl.originid";
        $params = array_merge([$companyId], $originIds, [$kind]);
        $rs = ncmExecute($sql, $params, false, true);
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $o = (string) ($f['originid'] ?? '');
                if ($o !== '') {
                    $map[$o] = (float) ($f['total'] ?? 0);
                }
                $rs->MoveNext();
            }
        }
        return $map;
    }

    /**
     * Versión "detalle" de mapSumDerivedAmounts() — no solo la suma por
     * origen, sino CADA documento derivado que aportó (fecha, número, monto
     * imputado). Usado por el estado de cuenta del contacto
     * (`OpenInvoicesService::contactStatement()`) para mostrar qué recibo se
     * aplicó a qué factura — un recibo puede repartirse entre varias
     * facturas, así que esto es un detalle N:M, no un mapa 1:1.
     *
     * Mismo `COALESCE(tl.amount, t.transactionTotal)` que sumDerivedAmounts() /
     * mapSumDerivedAmounts() — el monto mostrado acá nunca puede divergir del
     * que ya se usa para calcular el saldo, PARA LOS VIGENTES.
     *
     * A diferencia de sumDerivedAmounts()/mapSumDerivedAmounts(), esta NO
     * excluye anulados (`transactionStatus=6`) — INCLUYE cada documento
     * derivado con su `status`, para que el caller pueda mostrarlo marcado
     * "anulado" en vez de hacerlo desaparecer. Decisión del owner (2026-08-16,
     * ver `context/40-anulacion-y-nota-credito.md`, anulación de recibos de
     * pago): un documento numerado anulado tiene que seguir siendo visible
     * para auditoría — desaparecer de la pantalla es indistinguible de
     * "se borró". El único consumidor hoy es `OpenInvoicesService::
     * contactStatement()`, que ya excluye lo anulado del CÁLCULO de saldo por
     * otra vía (`mapSumDerivedAmounts`, sin tocar acá) — este método es solo
     * el detalle de display.
     *
     * @param list<string> $originIds
     * @return array<string, list<array{derivedId:string,date:?string,invoiceNo:string,amount:float,status:int}>> originId → detalle
     */
    public function mapDerivedDetailsByOrigins(string $companyId, array $originIds, string $kind): array
    {
        $originIds = array_values(array_unique(array_filter($originIds, static fn ($v) => $v !== '' && $v !== null)));
        $map = [];
        if ($originIds === []) {
            return $map;
        }
        $placeholders = implode(',', array_fill(0, count($originIds), '?'));
        $sql = "SELECT tl.originid AS originid, tl.derivedid AS derivedid,
                       COALESCE(tl.amount, t.transactionTotal) AS amount,
                       t.transactionDate AS date,
                       COALESCE(t.invoicePrefix, '') AS prefix,
                       COALESCE(t.invoiceNo, '') AS invoiceno,
                       COALESCE(t.transactionStatus, 1) AS status
                  FROM transaction_link tl
                  JOIN transaction t ON t.transactionId = tl.derivedid AND t.companyId = tl.companyid
                 WHERE tl.companyid = ? AND tl.originid IN ($placeholders) AND tl.kind = ?
                 ORDER BY t.transactionDate ASC";
        $params = array_merge([$companyId], $originIds, [$kind]);
        $rs = ncmExecute($sql, $params, false, true);
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $o = (string) ($f['originid'] ?? '');
                if ($o !== '') {
                    $map[$o][] = [
                        'derivedId'  => (string) ($f['derivedid'] ?? ''),
                        'date'       => $f['date'] !== null ? (string) $f['date'] : null,
                        'invoiceNo'  => (string) ($f['prefix'] ?? '') . (string) ($f['invoiceno'] ?? ''),
                        'amount'     => (float) ($f['amount'] ?? 0),
                        'status'     => (int) ($f['status'] ?? 1),
                    ];
                }
                $rs->MoveNext();
            }
        }
        return $map;
    }

    // ------------------------------------------------------------------
    // order_transaction_link
    // ------------------------------------------------------------------

    /**
     * Vincula una orden a la transacción que la cobró. Idempotente — el PK
     * (orderid, transactionid) no revienta en doble llamada.
     */
    public function linkOrder(string $companyId, string $orderId, string $transactionId): void
    {
        global $db;
        $db->Execute(
            'INSERT INTO order_transaction_link (companyid, orderid, transactionid, kind)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (orderid, transactionid) DO NOTHING',
            [$companyId, $orderId, $transactionId, 'order_billed']
        );
    }

    /**
     * IDs de las órdenes cobradas por una transacción — el caso explícito
     * del owner: varias órdenes cobradas con una sola factura.
     *
     * @return list<string>
     */
    public function listOrderIdsForTransaction(string $companyId, string $transactionId): array
    {
        return $this->fetchColumn(
            'SELECT orderid FROM order_transaction_link WHERE companyid = ? AND transactionid = ?',
            [$companyId, $transactionId],
            'orderid'
        );
    }

    /**
     * IDs de las transacciones que cobraron una orden (hoy siempre 0 o 1,
     * pero el modelo no lo asume).
     *
     * @return list<string>
     */
    public function listTransactionIdsForOrder(string $companyId, string $orderId): array
    {
        return $this->fetchColumn(
            'SELECT transactionid FROM order_transaction_link WHERE companyid = ? AND orderid = ?',
            [$companyId, $orderId],
            'transactionid'
        );
    }

    /**
     * La primera transacción que cobró la orden, o null. Reemplaza el
     * `saleTransactionId` derivado directo de la columna dropeada — el
     * contrato JSON de OrderCoreService no cambia (mismo campo, ahora
     * derivado del link).
     */
    public function firstTransactionIdForOrder(string $companyId, string $orderId): ?string
    {
        $ids = $this->listTransactionIdsForOrder($companyId, $orderId);
        return $ids[0] ?? null;
    }

    /**
     * true si la orden tiene AL MENOS una transacción vinculada. Reemplaza
     * el guard `saletransactionid IS NOT NULL` de OrderCoreService::updateStatus().
     */
    public function orderHasTransaction(string $companyId, string $orderId): bool
    {
        return $this->firstTransactionIdForOrder($companyId, $orderId) !== null;
    }

    /**
     * Versión batch de firstTransactionIdForOrder() — para listados de
     * órdenes (OrderCoreService::list()) donde una query por fila sería
     * N+1. Una sola query, MIN(created_at) por orderid = "la primera".
     *
     * @param list<string> $orderIds
     * @return array<string, string> orderId → transactionId (solo entradas con vínculo)
     */
    public function mapFirstTransactionIdForOrders(string $companyId, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter($orderIds, static fn ($v) => $v !== '' && $v !== null)));
        $map = [];
        if ($orderIds === []) {
            return $map;
        }
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sql = "SELECT DISTINCT ON (orderid) orderid, transactionid
                  FROM order_transaction_link
                 WHERE companyid = ? AND orderid IN ($placeholders)
                 ORDER BY orderid, created_at ASC";
        $rs = ncmExecute($sql, array_merge([$companyId], $orderIds), false, true);
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $map[(string) ($f['orderid'] ?? '')] = (string) ($f['transactionid'] ?? '');
                $rs->MoveNext();
            }
        }
        return $map;
    }

    // ------------------------------------------------------------------
    // internos
    // ------------------------------------------------------------------

    /**
     * @param list<mixed> $params
     * @return list<string>
     */
    private function fetchColumn(string $sql, array $params, string $col): array
    {
        // ncmExecute con forceObj=true devuelve RECORDSET, no array — iterar
        // con while(!$rs->EOF). Tratarlo como array devuelve [] siempre (bug
        // ya shipeado una vez, ver project_ncmexecute_forceobj_recordset).
        $rs  = ncmExecute($sql, $params, false, true);
        $out = [];
        if ($rs) {
            while (!$rs->EOF) {
                $out[] = (string) ($rs->fields[$col] ?? '');
                $rs->MoveNext();
            }
        }
        return $out;
    }
}
