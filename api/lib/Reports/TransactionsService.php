<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Documents\DocumentNumber;
use Punto\Api\EInvoice\EInvoiceService;

/**
 * Dominio de Reportes — Pagos y Transacciones / Transactions (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportTransactionsService.php (Fase 2 batch 13). Cambios vs original:
 *  - namespace + `final`
 *  - ROC y companyId por PARÁMETRO en las 3 vistas (no globals).
 *  - `getPaymentMethodsInArray($json)` (panel-only) → `paymentsFromJson()` private inline
 *    (mismo patrón aplicado en PurchasesService — sin enc, era no-op para UUIDs PG).
 *  - `getTaxonomyName` resuelve por fallback de namespace (existe en /app).
 *
 * 3 vistas: detail (ventas 0/3/6/7/8), cobros (pagos tipo 5), quotes (cotizaciones tipo 9).
 * El CRUD de edición, los reportes fiscales (rg90/libro-ventas/mcal/tusFacturas) y la vista
 * feTable (API externa) NO se migran (siguen en panel legacy vía ?action=).
 *
 * Tenant: $roc por query; companyId bound en cada lookup.
 */
final class TransactionsService
{
    private const TX_TYPES = '0,3,6,7,8';

    private array $taxonomyCache = [];

    /** Ventas. $filters: ['cusId','src','singleRow']. */
    public function detail(array $filters, $from, $to, string $roc, string $companyId, HourBand $hours = new HourBand()): array
    {
        // Franja horaria (F1 de context/67). Va SÓLO en la rama que acota por
        // rango de fechas, porque el contrato del predicado es que acompañe a un
        // rango: medido con EXPLAIN sobre 400k filas, agregarlo a una rama sin
        // rango tira el `Index Scan Backward` que alimentaba el LIMIT y lo
        // reemplaza por un Seq Scan paralelo de todas las particiones (3,7 ms →
        // 109 ms). Que las otras ramas no la apliquen NO es un no-op silencioso:
        // el endpoint rechaza con 422 la combinación de franja con `singleRow`,
        // `src` o `cusId` — ninguna de esas vistas acota por fecha tampoco.
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        $cols = "transactionId, transactionDate, transactionDiscount, transactionTax,
                 transactionTotal, transactionPaymentType, transactionType, transactionNote,
                 transactionDueDate, transactionStatus, transactionComplete, invoiceNo,
                 invoicePrefix, customerId, registerId, userId, outletId, ivaRemoved,
                 meta->>'tags' AS tags";

        if ($filters['singleRow']) {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ") AND transactionId = ?" . $roc . "
                    ORDER BY transactionDate DESC";
            $params = [$filters['singleRow']];
        } elseif ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $isNum = ctype_digit($filters['src']);
            $invoiceClause = $isNum ? ' OR invoiceNo = ?' : '';
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ")" . $roc . "
                    AND (customerId IN (
                            SELECT contactId FROM contact
                            WHERE type = 1 AND companyId = ?
                            AND (contactName ILIKE ? OR contactTIN ILIKE ? OR data->>'contactSecondName' ILIKE ?)
                         )" . $invoiceClause . ")
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = $isNum
                ? [$companyId, $like, $like, $like, (int) $filters['src']]
                : [$companyId, $like, $like, $like];
        } elseif ($filters['cusId']) {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ")" . $roc . "
                    AND customerId = ? ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$filters['cusId']];
        } else {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ")
                    AND transactionDate BETWEEN ? AND ?" . $roc . $hourSql . "
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = array_merge([$from, $to], $hourParams);
        }

        $res = ncmExecute($sql, $params, false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => []];
        }

        $txIds = $custIds = $usrIds = $outletIds = $regIds = [];
        foreach ($res as $f) {
            $txIds[]     = (string) $f['transactionId'];
            $custIds[]   = (string) $f['customerId'];
            $usrIds[]    = (string) $f['userId'];
            $outletIds[] = (string) $f['outletId'];
            $regIds[]    = (string) $f['registerId'];
        }

        $payedMap  = $this->payedByParent($txIds, $companyId);
        $contacts  = $this->contactInfo(array_merge($custIds, $usrIds), $companyId);
        $outlets   = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds, $companyId);
        $registers = $this->registerInfo($regIds, $companyId);
        // F1 facturación electrónica: estado por transacción, batch (evita N+1
        // de un fetch por fila en el frontend). Mapa vacío si el tenant no tiene
        // FE — las filas simplemente no traen badge (ver einvoiceInfo()).
        $einvoiceMap = $this->einvoiceInfo($txIds, $companyId);

        $rows = [];
        foreach ($res as $f) {
            $type     = (string) $f['transactionType'];
            $total    = (float) $f['transactionTotal'];
            $discount = (float) $f['transactionDiscount'];
            $tax      = (float) $f['transactionTax'];
            $netTotal = $total - $discount;

            $topay = 0.0;
            if ($type === '3') {
                $payed = $payedMap[(string) $f['transactionId']] ?? 0;
                $topay = $netTotal - $payed;
            }

            // Venta emitida SIN IVA (mig 101): los importes ya estan netos y no
            // hay base gravada que declarar. Sin esto, el reporte mostraba como
            // gravado el total de una venta que nunca devengo impuesto.
            $ivaRemoved = !empty($f['ivaRemoved']) && $f['ivaRemoved'] !== 'f';

            if ($type === '7') {
                $cDiscount = $cSubtotal = $cTax = $cNet = 0.0;
            } else {
                $cDiscount = $discount; $cSubtotal = $total; $cTax = $tax; $cNet = $netTotal;
            }

            $reg = $registers[(string) $f['registerId']] ?? [];
            $invoiceAuth   = (string) ($reg['invoiceAuth'] ?? '');
            $invoicePrefix = (string) ($f['invoicePrefix'] ?? '');
            if ($invoicePrefix === '') {
                $invoicePrefix = (string) ($reg['invoicePrefix'] ?? '');
            }
            if ($type === '6' && ($reg['returnPrefix'] ?? null) !== null) {
                $invoicePrefix = (string) $reg['returnPrefix'];
            }
            // Ancho del TALONARIO de este documento (mig 159). La devolución
            // (type 6) no tiene talonario propio todavía, así que hereda el de
            // la factura — que es el ancho con el que esa caja viene
            // imprimiendo. `null` → el default legal de `DocumentNumber`.
            $padWidth  = $this->padWidthFor($reg, $type);
            $invoiceNo = (string) ($f['invoiceNo'] ?? '');

            $tagsArr = $this->decodeTags($f['tags'] ?? null);
            if ($tagsArr && (in_array('166227', $tagsArr, false))) {
                // Documento marcado como interno: no lleva timbrado, así que
                // tampoco lleva el formato fiscal. `padWidth = 1` es "sin
                // relleno" (todo número tiene al menos un dígito).
                $invoicePrefix = ''; $invoiceAuth = ''; $padWidth = 1;
            }
            $paddedNo = DocumentNumber::pad($invoiceNo, $padWidth);
            $tagNames = $this->tagNames($tagsArr, $companyId);

            $custId = (string) $f['customerId'];
            $usrId  = (string) $f['userId'];
            $einv   = $einvoiceMap[(string) $f['transactionId']] ?? null;

            $rows[] = [
                'transactionId'       => (string) $f['transactionId'],
                'einvoiceStatus'      => $einv['status'] ?? null,
                'einvoiceCdc'         => $einv['cdc'] ?? null,
                'einvoiceError'       => $einv['errorMessage'] ?? null,
                'einvoiceSifenStatus' => $einv['sifenStatus'] ?? null,
                'einvoiceSifenReason' => $einv['sifenReason'] ?? null,
                'authNo'              => $invoiceAuth,
                // Número completo del documento: EEE-PPP-NNNNNNN. La
                // concatenación pelada daba "001-0011234567" — ilegible y sin
                // ninguna lectura válida. El guión va SOLO si hay las dos
                // partes: con prefijo y sin número (ventas no fiscales) no
                // puede quedar un "001-001-" colgado.
                // Formateador único (mig 159): mismo string que imprime el
                // ticket y que muestra el detalle. Antes cada consumidor
                // componía el suyo y ya habían divergido en el separador.
                'docNo'               => DocumentNumber::format($invoiceNo, $invoicePrefix, $padWidth),
                'invoiceNo'           => $invoiceNo,
                'date'                => (string) $f['transactionDate'],
                'dueDate'             => (string) ($f['transactionDueDate'] ?? ''),
                'customerName'        => $custId ? ($contacts[$custId]['name'] ?? '') : '',
                'customerTIN'         => $custId ? ($contacts[$custId]['tin'] ?? '') : '',
                'userName'            => $usrId ? ($contacts[$usrId]['name'] ?? '-') : '-',
                'outletName'          => $outlets[(string) $f['outletId']] ?? '',
                'registerName'        => (string) ($reg['name'] ?? ''),
                'payments'            => $this->payments($f['transactionPaymentType'] ?? null),
                'note'                => (string) ($f['transactionNote'] ?? ''),
                'tags'                => $tagNames,
                'transactionType'     => (int) $type,
                'transactionComplete' => $this->isComplete($f['transactionComplete'] ?? null) ? 1 : 0,
                'topay'               => $topay,
                'netTotal'            => $cNet,
                'discount'            => $cDiscount,
                'subtotal'            => $cSubtotal,
                'tax'                 => $ivaRemoved ? 0.0 : $cTax,
                // Sin IVA no hay base gravada: la venta es exenta, no "gravada
                // con impuesto cero".
                'totalGravado'        => $ivaRemoved ? 0.0 : ($cNet - $cTax),
                'ivaRemoved'          => $ivaRemoved,
                'total'               => $cNet,
            ];
        }

        return ['rows' => $rows];
    }

    /** Pagos de ventas a crédito (tipo 5). $filters: ['cusId','src']. */
    public function cobros(array $filters, $from, $to, string $roc, string $companyId, HourBand $hours = new HourBand()): array
    {
        // Franja horaria (F1 de context/67). Va SÓLO en la rama que acota por
        // rango de fechas, porque el contrato del predicado es que acompañe a un
        // rango: medido con EXPLAIN sobre 400k filas, agregarlo a una rama sin
        // rango tira el `Index Scan Backward` que alimentaba el LIMIT y lo
        // reemplaza por un Seq Scan paralelo de todas las particiones (3,7 ms →
        // 109 ms). Que las otras ramas no la apliquen NO es un no-op silencioso:
        // el endpoint rechaza con 422 la combinación de franja con `singleRow`,
        // `src` o `cusId` — ninguna de esas vistas acota por fecha tampoco.
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        // Columnas que leen los dos loops de abajo ($f[...]) — ninguna vive en
        // meta/data/config (a diferencia de detail(), acá no hace falta `tags`).
        $cols = "transactionId, transactionType, invoiceNo, transactionDate, customerId,
                 userId, outletId, registerId, transactionPaymentType, transactionTotal";

        if ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (5)" . $roc . "
                    AND customerId IN (
                        SELECT contactId FROM contact WHERE type = 1 AND companyId = ?
                        AND (contactName ILIKE ? OR contactTIN ILIKE ? OR data->>'contactSecondName' ILIKE ?)
                    )
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$companyId, $like, $like, $like];
        } elseif ($filters['cusId']) {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (5)" . $roc . "
                    AND customerId = ? ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$filters['cusId']];
        } else {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (5)
                    AND transactionDate BETWEEN ? AND ?" . $roc . $hourSql . "
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = array_merge([$from, $to], $hourParams);
        }

        $res = ncmExecute($sql, $params, false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => []];
        }

        // mig 115: transactionParentId dropeada — origen (kind='credit_payment')
        // vía transaction_link, batch para todos los pagos de la página.
        $paymentIds = array_map(fn($f) => (string) $f['transactionId'], $res);
        $originByPayment = (new \Punto\Api\Services\TransactionLinkService())->mapOriginIdByDerivedIds($companyId, $paymentIds, 'credit_payment');

        $parentIds = $custIds = $usrIds = $outletIds = $regIds = [];
        foreach ($res as $f) {
            $parentIds[] = $originByPayment[(string) $f['transactionId']] ?? '';
            $custIds[]   = (string) $f['customerId'];
            $usrIds[]    = (string) $f['userId'];
            $outletIds[] = (string) $f['outletId'];
            $regIds[]    = (string) $f['registerId'];
        }

        $parents   = $this->parentInvoices($parentIds, '0,3', $companyId);
        $contacts  = $this->contactInfo(array_merge($custIds, $usrIds), $companyId);
        $outlets   = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds, $companyId);
        foreach ($parents as $p) { $regIds[] = (string) $p['registerId']; }
        $registers = $this->registerInfo($regIds, $companyId);

        $rows = [];
        foreach ($res as $f) {
            $pid = $originByPayment[(string) $f['transactionId']] ?? '';
            if (!isset($parents[$pid])) {
                continue;
            }
            $p = $parents[$pid];
            $parentReg = $registers[(string) $p['registerId']] ?? [];
            $custId = (string) $f['customerId'];
            $usrId  = (string) $f['userId'];

            $rows[] = [
                'transactionId' => (string) $f['transactionId'],
                'parentId'      => $pid,
                // La factura de origen se muestra con el MISMO formato que en
                // el listado de ventas. Antes acá se componía con un espacio
                // y sin padding: la misma factura se veía "001-001 2129" en
                // cobros y "001-001-0002129" en ventas.
                'parentInvoice' => DocumentNumber::format(
                    $p['invoiceNo'] ?? null,
                    (string) ($parentReg['invoicePrefix'] ?? ''),
                    // El padre es siempre una venta (parentInvoices filtra 0,3).
                    $this->padWidthFor($parentReg, 0),
                ),
                'invoiceNo'     => (string) ($f['invoiceNo'] ?? ''),
                'date'          => (string) $f['transactionDate'],
                'customerName'  => $custId ? ($contacts[$custId]['name'] ?? '') : '',
                'userName'      => $usrId ? ($contacts[$usrId]['name'] ?? '-') : '-',
                'outletName'    => $outlets[(string) $f['outletId']] ?? '',
                'registerName'  => (string) ($registers[(string) $f['registerId']]['name'] ?? ''),
                'payments'      => $this->payments($f['transactionPaymentType'] ?? null),
                'total'         => (float) $f['transactionTotal'],
                'outletId'      => (string) $f['outletId'],
                'type'          => (int) $f['transactionType'],
            ];
        }

        return ['rows' => $rows];
    }

    /** Cotizaciones (tipo 9). $filters: ['cusId','src']. */
    public function quotes(array $filters, $from, $to, string $roc, string $companyId, HourBand $hours = new HourBand()): array
    {
        // Franja horaria (F1 de context/67). Va SÓLO en la rama que acota por
        // rango de fechas, porque el contrato del predicado es que acompañe a un
        // rango: medido con EXPLAIN sobre 400k filas, agregarlo a una rama sin
        // rango tira el `Index Scan Backward` que alimentaba el LIMIT y lo
        // reemplaza por un Seq Scan paralelo de todas las particiones (3,7 ms →
        // 109 ms). Que las otras ramas no la apliquen NO es un no-op silencioso:
        // el endpoint rechaza con 422 la combinación de franja con `singleRow`,
        // `src` o `cusId` — ninguna de esas vistas acota por fecha tampoco.
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        // Columnas que lee el loop de abajo ($f[...]) — ninguna vive en meta/data/config.
        $cols = "transactionId, transactionType, invoiceNo, transactionDate, transactionStatus,
                 transactionDueDate, customerId, userId, outletId, transactionTotal, transactionDiscount";

        if ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (9)" . $roc . "
                    AND customerId IN (
                        SELECT contactId FROM contact WHERE type = 1 AND companyId = ?
                        AND (contactName ILIKE ? OR contactTIN ILIKE ? OR data->>'contactSecondName' ILIKE ?)
                    )
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$companyId, $like, $like, $like];
        } elseif ($filters['cusId']) {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (9)" . $roc . "
                    AND customerId = ? ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$filters['cusId']];
        } else {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (9)
                    AND transactionDate BETWEEN ? AND ?" . $roc . $hourSql . "
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = array_merge([$from, $to], $hourParams);
        }

        $res = ncmExecute($sql, $params, false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => []];
        }

        $custIds = $usrIds = $outletIds = [];
        foreach ($res as $f) {
            $custIds[]   = (string) $f['customerId'];
            $usrIds[]    = (string) $f['userId'];
            $outletIds[] = (string) $f['outletId'];
        }
        $contacts = $this->contactInfo(array_merge($custIds, $usrIds), $companyId);
        $outlets  = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds, $companyId);

        // Cuáles de estas cotizaciones ya se facturaron. Una sola query para
        // todas (no N+1): `transaction_link` kind='quote_to_sale', escrito por
        // `SaleService::save()` cuando la venta nace de una cotización.
        $quoteIds = array_map(static fn ($f) => (string) $f['transactionId'], $res);
        $billed   = $this->billedQuoteIds($quoteIds, $companyId);

        $rows = [];
        foreach ($res as $f) {
            $custId = (string) $f['customerId'];
            $usrId  = (string) $f['userId'];
            $txId   = (string) $f['transactionId'];
            $rows[] = [
                'transactionId'     => $txId,
                'invoiceNo'         => (string) ($f['invoiceNo'] ?? ''),
                'date'              => (string) $f['transactionDate'],
                // Estado del CICLO DE VIDA de la cotización, no el entero crudo
                // de `transactionStatus` — que es del motor de transacciones y
                // llegaba al panel como un "1" pelado dentro de un Badge
                // (reporte del tester, 2026-08-28).
                'quoteStatus'       => $this->quoteStatus(
                    isset($f['transactionStatus']) ? (int) $f['transactionStatus'] : null,
                    isset($f['transactionDueDate']) ? (string) $f['transactionDueDate'] : null,
                    isset($billed[$txId])
                ),
                'transactionStatus' => (string) ($f['transactionStatus'] ?? ''),
                'customerName'      => $custId ? ($contacts[$custId]['name'] ?? '') : '',
                'customerTIN'       => $custId ? ($contacts[$custId]['tin'] ?? '') : '',
                'userName'          => $usrId ? ($contacts[$usrId]['name'] ?? '-') : '-',
                'outletName'        => $outlets[(string) $f['outletId']] ?? '',
                // Neto con descuento, igual que detail(): total crudo mostraba el
                // monto sin restar el descuento aplicado a la cotización.
                'total'             => (float) $f['transactionTotal'] - (float) $f['transactionDiscount'],
                'outletId'          => (string) $f['outletId'],
                'type'              => (int) $f['transactionType'],
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * Estado del ciclo de vida de una cotización, en el vocabulario del negocio:
     *
     *   anulada    — `transactionStatus = 6` (mismo valor de anulación que usa
     *                el resto del motor, ver PurchasesService/SaleVoidService).
     *   facturada  — existe una venta que la tiene como origen.
     *   vencida    — pasó su `transactionDueDate` sin facturarse. Un presupuesto
     *                con fecha de validez vencida no es lo mismo que uno que
     *                sigue vigente esperando respuesta, y el vendedor necesita
     *                distinguirlos para saber a quién llamar.
     *   pendiente  — el resto.
     *
     * Se dice "facturada" y no "aprobada": lo que el sistema sabe es que se
     * emitió la venta, no lo que el cliente contestó. Un presupuesto aprobado de
     * palabra y todavía sin facturar seguiría figurando como pendiente, y
     * llamarlo "no aprobado" sería afirmar algo que Punto no tiene cómo saber.
     *
     * Recibe los dos valores que usa, no la fila: las filas del DB layer son
     * `CaseInsensitiveArray` (RecordsetIterator, `Query.php`), no `array`, así
     * que tipar la fila obliga a elegir entre un hint que revienta en runtime o
     * uno tan flojo que no dice nada. Con escalares el helper queda además puro
     * y testeable sin DB.
     */
    private function quoteStatus(?int $status, ?string $dueDate, bool $isBilled): string
    {
        if (($status ?? 1) === 6) {
            return 'anulada';
        }
        if ($isBilled) {
            return 'facturada';
        }
        $due = (string) $dueDate;
        if ($due !== '' && strtotime($due) !== false && strtotime($due) < strtotime(date('Y-m-d 00:00:00'))) {
            return 'vencida';
        }
        return 'pendiente';
    }

    /**
     * Set de cotizaciones que YA tienen una venta derivada, en una sola query.
     *
     * @param list<string> $quoteIds
     * @return array<string,true>
     */
    private function billedQuoteIds(array $quoteIds, string $companyId): array
    {
        $quoteIds = array_values(array_unique(array_filter($quoteIds)));
        if ($quoteIds === []) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($quoteIds), '?'));
        $res = ncmExecute(
            "SELECT DISTINCT originid FROM transaction_link
              WHERE companyid = ? AND kind = 'quote_to_sale' AND originid IN ($ph)",
            array_merge([$companyId], $quoteIds), false, false, true
        );
        $out = [];
        foreach (is_array($res) ? $res : [] as $r) {
            $out[(string) $r['originid']] = true;
        }
        return $out;
    }

    /**
     * Elimina un cobro (tipo 5) y desmarca el crédito padre como no completado.
     * Operación atómica: si el DELETE falla, el UPDATE del padre no se ejecuta.
     */
    public function deletePayment(string $id, ?string $parentId, string $companyId): bool
    {
        global $db;
        $db->BeginTrans();
        $r = $db->Execute(
            'DELETE FROM transaction WHERE transactionId = ? AND companyId = ? AND transactionType = 5',
            [$id, $companyId]
        );
        if ($r === false) {
            $db->RollbackTrans();
            return false;
        }
        if ($parentId) {
            $db->Execute(
                'UPDATE transaction SET transactionComplete = FALSE
                 WHERE transactionId = ? AND companyId = ? AND transactionType IN (0,3)',
                [$parentId, $companyId]
            );
        }
        $db->CommitTrans();
        return true;
    }

    /** Elimina una cotización (tipo 9). */
    public function deleteQuote(string $id, string $companyId): bool
    {
        global $db;
        $r = $db->Execute(
            'DELETE FROM transaction WHERE transactionId = ? AND companyId = ? AND transactionType = 9',
            [$id, $companyId]
        );
        return $r !== false;
    }

    /* ───────────── helpers ───────────── */

    private function isComplete($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        return $s === '1' || $s === 't' || $s === 'true';
    }

    private function decodeTags($raw): array
    {
        if (!$raw) {
            return [];
        }
        $arr = json_decode((string) $raw, true);
        return is_array($arr) ? $arr : [];
    }

    /** Medios de pago resueltos, sólo total > 0. */
    private function payments($json): array
    {
        $arr = $this->paymentsFromJson($json);
        $out = [];
        foreach ($arr as $p) {
            if (($p['total'] ?? 0) > 0) {
                $out[] = ['name' => (string) ($p['name'] ?? '')];
            }
        }
        return $out;
    }

    /** Port inline de getPaymentMethodsInArray del panel (sin enc — no-op para UUIDs PG). */
    private function paymentsFromJson($json): array
    {
        $array = json_decode($json ?: '{}', true);
        if (!is_array($array) || !$array) {
            return [];
        }
        $out = [];
        foreach ($array as $value) {
            $out[] = [
                'type'  => $value['type'] ?? '',
                'name'  => getPaymentMethodName($value['type'] ?? ''),
                'price' => (float) ($value['price'] ?? 0),
                'total' => (float) ($value['total'] ?? 0),
                'extra' => $value['extra'] ?? 0,
            ];
        }
        return $out;
    }

    /** SUM(ABS) de pagos+devoluciones (tipo 5,6) por venta origen (transaction_link, mig 115). */
    private function payedByParent(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $derivedByOrigin = (new \Punto\Api\Services\TransactionLinkService())->mapDerivedIdsByOrigins($companyId, $ids);
        $allDerived = [];
        foreach ($derivedByOrigin as $derivedIds) {
            foreach ($derivedIds as $d) {
                $allDerived[] = $d;
            }
        }
        if (!$allDerived) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($allDerived), '?'));
        $res = ncmExecute(
            "SELECT transactionId, ABS(transactionTotal) as payed
             FROM transaction WHERE transactionType IN (5,6) AND companyId = ? AND transactionId IN ($ph)",
            array_merge([$companyId], $allDerived), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $payedByDerived = [];
        foreach ($res as $r) {
            $payedByDerived[(string) $r['transactionId']] = (float) $r['payed'];
        }

        $map = [];
        foreach ($derivedByOrigin as $originId => $derivedIds) {
            $sum = 0.0;
            foreach ($derivedIds as $d) {
                $sum += $payedByDerived[$d] ?? 0.0;
            }
            $map[$originId] = abs($sum);
        }
        return $map;
    }

    private function parentInvoices(array $ids, $types, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionId, invoiceNo, invoicePrefix, registerId
             FROM transaction WHERE transactionId IN ($ph) AND transactionType IN ($types) AND companyId = ?",
            array_merge($ids, [$companyId]), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['transactionId']] = $r;
        }
        return $map;
    }

    /**
     * F1 — estado de facturación electrónica por transactionId, batch (una
     * sola query para toda la página, igual patrón que contactInfo/nameMap).
     * Si una venta tiene más de un documento (no debería en F1 — una venta
     * genera FC o FCR, nunca ambos), se queda con el más reciente.
     */
    private function einvoiceInfo(array $txIds, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($txIds)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        // `ncmRows` y no `getAssoc`: getAssoc indexa por la primera columna
        // (`transactionid`, que NO es única en einvoice_document — un reintento
        // o una rectificación agregan filas) y colapsa ANTES de que corra el
        // loop de abajo. O sea que INVERTÍA el criterio documentado: el
        // `ORDER BY created_at DESC` + "me quedo con el primero" dice "el más
        // reciente", pero el que sobrevivía al colapso era el ÚLTIMO iterado,
        // el más VIEJO. El badge de facturación electrónica podía mostrar el
        // estado del intento fallido en vez de la rectificación aprobada.
        // `NULLS LAST` para que una fila sin fecha no se robe el primer
        // puesto (en DESC el default de PG es NULLS FIRST), y `einvoicedocid`
        // como desempate estable — los UUID acá son v4 random, no ordenan por
        // tiempo, pero sirven para que dos filas con el mismo `created_at`
        // elijan siempre la misma y no una distinta por request.
        $res = ncmRows(
            "SELECT transactionid, status, cdc, error_message, sifen_status,
                    -- Solo el bulk de los NO aprobados: es de donde sale el
                    -- motivo del rechazo, y traerlo entero por fila para
                    -- descartarlo es pagar el jsonb de todas las ventas.
                    CASE WHEN sifen_status IS NOT NULL AND sifen_status NOT ILIKE '%aprobad%'
                         THEN sifen_result END AS sifen_result
               FROM einvoice_document
              WHERE companyid = ? AND transactionid IN ($ph)
              ORDER BY created_at DESC NULLS LAST, einvoicedocid DESC",
            array_merge([$companyId], $ids)
        );
        $map = [];
        foreach ($res as $d) {
            $txId = (string) ($d['transactionid'] ?? '');
            if ($txId === '' || isset($map[$txId])) {
                continue; // ya tomamos el más reciente (ORDER BY created_at DESC)
            }
            $map[$txId] = [
                'status'       => (string) ($d['status'] ?? ''),
                'cdc'          => $d['cdc'] ?? null,
                'errorMessage' => $d['error_message'] ?? null,
                // Estado FISCAL (¿SIFEN lo aceptó?), distinto del outbox de
                // arriba (¿se mandó?). Manda éste: un documento 'issued' que
                // SIFEN rechazó NO es una factura válida, y el listado lo
                // pintaba como emitido. El motivo se parsea con el mismo
                // helper que usa la pantalla de FE — no se duplica acá.
                'sifenStatus'  => $d['sifen_status'] ?? null,
                'sifenReason'  => EInvoiceService::sifenReason($d['sifen_result'] ?? null),
            ];
        }
        return $map;
    }

    private function contactInfo(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName, contactTIN FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = [
                'name' => trim((string) ($c['contactName'] ?? '')),
                'tin'  => (string) ($c['contactTIN'] ?? ''),
            ];
        }
        return $map;
    }

    private function nameMap(string $table, string $idCol, string $nameCol, array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT $idCol, $nameCol FROM $table WHERE companyId = ? AND $idCol IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r[$idCol]] = (string) ($r[$nameCol] ?? '');
        }
        return $map;
    }

    /**
     * Ancho del talonario que le corresponde a una transacción, sacado de una
     * fila de `registerInfo()`.
     *
     * Público porque los otros dos consumidores del formato lo necesitan con
     * el MISMO criterio (`Transactions\TransactionDetailService::find()` y
     * `Reports\FiscalService`). Duplicar este `??` en cada uno es exactamente
     * cómo se separaron los tres `str_pad` que la mig 159 viene a unificar.
     *
     * Los documentos sin talonario propio todavía (devolución type 6, pago de
     * crédito type 5) heredan el de la FACTURA: es el ancho con el que esa
     * caja viene imprimiendo, no el default genérico. `null` = sin dato →
     * `DocumentNumber` pone el default legal.
     *
     * @param array<string,mixed> $reg fila de registerInfo()
     */
    public function padWidthFor(array $reg, int|string|null $saleType): ?int
    {
        $widths  = is_array($reg['padWidth'] ?? null) ? $reg['padWidth'] : [];
        $docType = DocumentNumber::docTypeForSaleType($saleType);

        return $widths[$docType] ?? $widths['factura'] ?? null;
    }

    /**
     * registers → {name, invoiceAuth, invoicePrefix, padWidth, returnPrefix}
     *
     * Público (F1, context/39-detalle-transaccion.md): `Transactions\TransactionDetailService::find()`
     * lo reusa para resolver timbrado/prefix del detalle — mismo criterio que
     * el listado, sin duplicar la lectura de `register.data` JSONB.
     *
     * `padWidth` es un mapa docType → ancho (mig 159), no un escalar: el ancho
     * es propiedad del TALONARIO, y una caja lleva uno por documento (factura
     * y cotización comparten la columna `invoiceNo` pero son dos talonarios).
     * Reemplaza al `registerDocsLeadingZeros` de `register.data`, que era un
     * ancho único para toda la caja y que los tres consumidores padeaban por
     * su cuenta con resultados divergentes (ver `DocumentNumber::format`).
     */
    public function registerInfo(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        // Anchos de TODAS las secuencias de estas cajas en UNA query — el
        // formateo de un listado no puede hacer un SELECT por fila (por eso
        // `DocumentNumber::formatFor()` no se usa acá).
        //
        // `ncmRows` y no `getAssoc`: el resultado es una fila POR TALONARIO
        // (una caja lleva factura + cotización + recibo…), así que `scopeid`
        // —la primera columna— se repite y getAssoc dejaba una sola por caja.
        // El mapa docType→ancho terminaba con UN solo docType y el resto de
        // los documentos de esa caja caía al default genérico de
        // `DocumentNumber::format()` en vez de usar el ancho configurado.
        $padByRegister = [];
        $seqRes = ncmRows(
            "SELECT scopeid, doctype, padwidth
               FROM document_sequence
              WHERE companyid = ? AND scopetype = 'register' AND scopeid IN ($ph)",
            array_merge([$companyId], $ids)
        );
        foreach ($seqRes as $s) {
            $padByRegister[(string) $s['scopeid']][(string) $s['doctype']] = (int) $s['padwidth'];
        }

        // Migración 26 (2026-06-13): registerInvoiceAuth/Prefix/DocsLeadingZeros
        // viven en `data` JSONB. `SELECT *` deja que ncmExecute aplique
        // `_flattenJsonb` y exponga las keys del JSONB como columnas virtuales
        // en cada fila — el loop de abajo las lee con `$r['registerInvoiceAuth']`
        // sin cambios.
        $res = ncmExecute(
            "SELECT *
             FROM register WHERE companyId = ? AND registerId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            // Post-Migración 26 + flatten: registerInvoiceAuth/Prefix/DocsLeadingZeros
            // viven en el JSONB pero _flattenJsonb los re-expone como columnas
            // virtuales en `$r`. registerReturnPrefix nunca fue columna —
            // siempre vivió en `data`, accedido ahora también vía flatten.
            // ncmExecute(getAssoc=true) devuelve CaseInsensitiveArray por fila
            // → array_key_exists() rompe (espera array puro). Usamos ?? null
            // que funciona con ArrayAccess y mantiene la semántica original
            // (returnPrefix=null cuando la key no existe en el JSONB).
            $map[(string) $r['registerId']] = [
                'name'             => (string) ($r['registerName'] ?? ''),
                'invoiceAuth'      => (string) ($r['registerInvoiceAuth'] ?? ''),
                'invoicePrefix'    => (string) ($r['registerInvoicePrefix'] ?? ''),
                // docType → ancho. Ya NO sale de `register.data`: el legacy
                // `registerDocsLeadingZeros` quedó backfilleado en
                // `document_sequence.padwidth` por la mig 159 y esta es la
                // única lectura viva. Vacío = el default legal de 7 lo pone
                // `DocumentNumber::format()`.
                'padWidth'         => $padByRegister[(string) $r['registerId']] ?? [],
                'returnPrefix'     => isset($r['registerReturnPrefix'])
                    ? (string) $r['registerReturnPrefix'] : null,
            ];
        }
        return $map;
    }

    /**
     * Lookup directo bindeado por companyId: NO usa el global `getTaxonomyName` (que delega a
     * Punto\App\Domain\Taxonomy y lee `$SQLcompanyId` del global vacío en /api → 'None' silente).
     * Fix preventivo descubierto en batch 14 con ProductsService.
     */
    private function tagNames(array $tagIds, string $companyId): array
    {
        $out = [];
        foreach ($tagIds as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            if (!array_key_exists($id, $this->taxonomyCache)) {
                $r = ncmExecute(
                    "SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1",
                    [$id, $companyId]
                );
                $name = $r ? (string) ($r['taxonomyName'] ?? '') : '';
                $this->taxonomyCache[$id] = ($name === '' || $name === 'None') ? '' : toUTF8($name);
            }
            if ($this->taxonomyCache[$id] !== '') {
                $out[] = $this->taxonomyCache[$id];
            }
        }
        return $out;
    }
}
