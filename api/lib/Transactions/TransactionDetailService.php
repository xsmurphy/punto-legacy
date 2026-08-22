<?php
declare(strict_types=1);

namespace Punto\Api\Transactions;

use Punto\Api\Services\TransactionLinkService;

/**
 * Resolver CANÓNICO de detalle de una transacción de venta (F1,
 * context/39-detalle-transaccion.md). Espejo de `Purchases\PurchasesService::find()`
 * (mismo patrón: un Service dedicado con JOINs que resuelve TODO server-side,
 * nada de IDs crudos que el front tenga que resolver).
 *
 * Por qué acá y no en otro lado:
 *   - `Reports\TransactionsService` es el motor de LISTADOS (detail/cobros/
 *     quotes, filas resumidas para tablas) — cargarle un `find()` de detalle
 *     completo (líneas + desglose fiscal + documentos vinculados) le mezcla
 *     dos responsabilidades distintas en una clase que ya es grande.
 *   - `services/TransactionService.php` (namespace `Punto\Api\Services`) es el
 *     servicio de OPERACIONES del POS (delete/void/changeStatus/reject) más su
 *     propio `getSingle()` con shape ad-hoc para la UX del POS — side-effecting
 *     y con convenciones propias (enc/dec, CaseInsensitiveArray manual). No es
 *     el lugar para un resolver de solo-lectura pensado para el panel.
 *   - `Transactions/` es dominio nuevo, análogo a `Purchases/`: una carpeta,
 *     un Service, sin operaciones de escritura.
 *
 * El POS (`TransactionService::getSingle()`) NO migra en F1 — tiene su propia
 * UX (POS carrito/reimpresión) y su propio riesgo; migrarlo es F4 del plan.
 *
 * Multi-tenant: $companyId siempre explícito, nunca global.
 */
final class TransactionDetailService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Detalle completo de una transacción. null si no existe o no pertenece
     * al tenant. Sin filtro de `transactionType`: el endpoint históricamente
     * resuelve cualquier tipo por id (ventas, notas de crédito, pagos,
     * cotizaciones, citas) — el caller decide qué hacer con cada tipo.
     */
    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return null;
        }

        // `t.meta::text AS meta_raw`: `meta` no sobrevive al flatten del
        // wrapper (Query::flattenJsonb la desempaqueta y borra la columna) —
        // preservamos el crudo para el campo `meta` de salida. Las keys de
        // primer nivel de meta (`transactionDetails`, `tags`) SÍ sobreviven
        // el flatten como columnas virtuales — se leen directo de `$tx`.
        $tx = ncmExecute(
            "SELECT t.*, t.meta::text AS meta_raw,
                    c.contactName AS customerName, c.contactTIN AS customerTIN,
                    u.contactName AS userName,
                    r.contactName AS responsibleName,
                    v.contactName AS voidedByName,
                    o.outletName
               FROM transaction t
               LEFT JOIN contact c ON c.contactId = t.customerId    AND c.companyId = ?
               LEFT JOIN contact u ON u.contactId = t.userId        AND u.companyId = ?
               LEFT JOIN contact r ON r.contactId = t.responsibleId AND r.companyId = ?
               LEFT JOIN contact v ON v.contactId = t.voidedBy      AND v.companyId = ?
               LEFT JOIN outlet  o ON o.outletId  = t.outletId      AND o.companyId = ?
              WHERE t.transactionId = ? AND t.companyId = ?
              LIMIT 1",
            [$companyId, $companyId, $companyId, $companyId, $companyId, $id, $companyId]
        );
        if (!$tx) {
            return null;
        }

        $type    = (int) $tx['transactionType'];
        $linkSvc = new TransactionLinkService();

        // ── Caja / timbrado ──────────────────────────────────────────────
        // Mismo criterio que Reports\TransactionsService::registerInfo()
        // (invoiceAuth/invoicePrefix/docsLeadingZeros/returnPrefix viven en
        // register.data JSONB, expuestos por el flatten) — se reusa esa
        // resolución en vez de duplicarla (visibility bump: private→public).
        $registerId = $tx['registerId'] !== null ? (string) $tx['registerId'] : null;
        $reg        = [];
        if ($registerId !== null) {
            $registers = (new \Punto\Api\Reports\TransactionsService())->registerInfo([$registerId], $companyId);
            $reg       = $registers[$registerId] ?? [];
        }
        $invoicePrefix = (string) ($tx['invoicePrefix'] ?? '');
        if ($invoicePrefix === '') {
            $invoicePrefix = (string) ($reg['invoicePrefix'] ?? '');
        }
        if ($type === 6 && ($reg['returnPrefix'] ?? null) !== null) {
            $invoicePrefix = (string) $reg['returnPrefix'];
        }
        // Ancho del talonario (mig 158) — antes salía de
        // `register.data.registerDocsLeadingZeros` y se padeaba acá a mano.
        $padWidth     = (new \Punto\Api\Reports\TransactionsService())->padWidthFor($reg, $type);
        $invoiceNoRaw = (string) ($tx['invoiceNo'] ?? '');
        $invoiceNoPad = \Punto\Api\Documents\DocumentNumber::pad($invoiceNoRaw, $padWidth);

        // Venta emitida SIN IVA (mig 101) — mismo criterio que
        // Reports\TransactionsService::detail(): el valor real de PG llega
        // como STRING ('t'/'f'), (bool) 'f' === true, por eso el chequeo
        // explícito en vez de un cast ingenuo.
        $ivaRemoved = !empty($tx['ivaRemoved']) && $tx['ivaRemoved'] !== 'f';

        // ── Líneas: meta.transactionDetails (double-encoded, §22.6) ──────
        // `transactionDetails` es una key de primer nivel de `meta` → el
        // flatten la expone como columna virtual (string JSON, doble-encode:
        // SaleService la guarda como `json_encode($saleDetail)` DENTRO del
        // json_encode del objeto meta completo).
        $metaLines = [];
        if (!empty($tx['transactionDetails'])) {
            $decoded = json_decode((string) $tx['transactionDetails'], true);
            if (is_array($decoded)) {
                $metaLines = $decoded;
            }
        }

        // Cola por itemId, mismo filtro que SaleService::persistItemsAndStock
        // (skip type=discount, skip sin itemId) y en el MISMO orden en que se
        // insertaron los itemSold — permite matchear cada fila de itemSold
        // con su línea de meta para leer taxRate/taxKind/taxIncluded/
        // taxAmount/taxNet/discount% (F2a), que NO se persisten en itemSold.
        //
        // LIMITACIÓN CONOCIDA (documentada, no resuelta): itemSold no tiene
        // columna de secuencia ni FK a su línea de meta — itemSoldId es UUID
        // v4 random, no ordenable (ver project_pg_uuid_v4_not_v7). El match
        // es FIFO por itemId: si el carrito tuvo el MISMO itemId dos veces
        // con descuentos/impuestos distintos en la misma línea, el orden de
        // fetch de itemSold desde PG (sin ORDER BY determinístico) podría
        // intercambiar cuál fila se queda con cuál desglose. Caso raro —
        // pendiente si se vuelve un problema real: agregar una columna de
        // índice de línea a itemSold.meta al vender.
        $metaQueue = [];
        foreach ($metaLines as $ml) {
            if (!is_array($ml) || ($ml['type'] ?? '') === 'discount') {
                continue;
            }
            $mlItemId = (string) ($ml['itemId'] ?? '');
            if ($mlItemId === '') {
                continue;
            }
            $metaQueue[$mlItemId][] = $ml;
        }

        $items          = [];
        $itemUserIds    = [];
        if (in_array($type, [0, 3, 9], true)) {
            $rawItems = ncmExecute(
                "SELECT is2.itemSoldId, is2.itemId, i.itemName, is2.itemSoldUnits,
                        is2.itemSoldTotal, is2.itemSoldTax, is2.itemSoldDiscount,
                        is2.itemSoldComission, is2.itemSoldDescription, is2.userId,
                        us.contactName AS userName
                   FROM itemSold is2
                   LEFT JOIN item i ON i.itemId = is2.itemId AND i.companyId = ?
                   LEFT JOIN contact us ON us.contactId = is2.userId AND us.companyId = ?
                  WHERE is2.transactionId = ?
                  ORDER BY is2.itemSoldId",
                [$companyId, $companyId, $id],
                false, false, true
            );
            foreach ((is_array($rawItems) ? $rawItems : []) as $r) {
                $itemId = (string) ($r['itemId'] ?? '');
                $ml     = ($itemId !== '' && !empty($metaQueue[$itemId])) ? array_shift($metaQueue[$itemId]) : null;

                $units = (float) ($r['itemSoldUnits'] ?? 0);
                $gross = (float) ($r['itemSoldTotal'] ?? 0);
                $taxAmount = (float) ($r['itemSoldTax'] ?? ($ml['taxAmount'] ?? 0));

                $items[] = [
                    // Nombres LEGACY (se mantienen tal cual — PanelDetailView y
                    // buildTicketDataFromTxDetail ya los leen; F1 es backend-only,
                    // ver brief §2, no se renombra lo que el front consume).
                    'itemSoldId'        => (string) ($r['itemSoldId'] ?? ''),
                    'itemId'            => $itemId,
                    'itemName'          => (string) ($r['itemName'] ?? ($ml['name'] ?? '')),
                    'itemSoldUnits'     => $units,
                    'itemSoldTotal'     => $gross,
                    'itemSoldTax'       => $taxAmount,
                    'userId'            => $r['userId'] !== null ? (string) $r['userId'] : null,

                    // Nuevos en F1 (context/39) — aditivos, no pisan nada.
                    'note'              => $ml['note'] ?? ($r['itemSoldDescription'] ?? null),
                    'unitPrice'         => $units > 0 ? $gross / $units : 0.0,
                    'itemSoldDiscount'  => (float) ($r['itemSoldDiscount'] ?? 0),
                    'discountPercent'   => isset($ml['discount']) ? (float) $ml['discount'] : null,
                    'itemSoldComission' => (float) ($r['itemSoldComission'] ?? 0),
                    'userName'          => $r['userName'] !== null ? (string) $r['userName'] : null,
                    'taxId'             => $ml['taxId']       ?? null,
                    'taxRate'           => isset($ml['taxRate']) ? (float) $ml['taxRate'] : null,
                    'taxKind'           => $ml['taxKind']      ?? null,
                    'taxIncluded'       => array_key_exists('taxIncluded', (array) $ml) ? (bool) $ml['taxIncluded'] : null,
                    // taxAmount = alias explícito de itemSoldTax (mismo dato, el
                    // pedido del brief nombra el campo así) + taxNet de meta.
                    'taxAmount'         => $taxAmount,
                    'taxNet'            => isset($ml['taxNet']) ? (float) $ml['taxNet'] : null,
                ];
            }
        } elseif ($metaLines !== []) {
            // Tipos sin itemSold (5/6/10/12/13...): las líneas viven solo en
            // meta.transactionDetails. Mismo shape de salida que la rama de
            // arriba, con lo que no está disponible en null/0 explícito (no
            // hay comisión ni discountAmount separado de itemSold acá).
            foreach ($metaLines as $k => $v) {
                if (!is_array($v)) {
                    continue;
                }
                $uid = isset($v['userId']) ? (string) $v['userId'] : (isset($v['user']) ? (string) $v['user'] : null);
                if ($uid !== null && $uid !== '') {
                    $itemUserIds[$uid] = true;
                }
                $units = (float) ($v['count'] ?? 1);
                $total = (float) ($v['total'] ?? 0);
                $items[] = [
                    // Nombres legacy — ver comentario en la rama itemSold arriba.
                    'itemSoldId'        => (string) $k,
                    'itemId'            => (string) ($v['itemId'] ?? ''),
                    'itemName'          => (string) ($v['name'] ?? ''),
                    'itemSoldUnits'     => $units,
                    'itemSoldTotal'     => $total,
                    'itemSoldTax'       => (float) ($v['taxAmount'] ?? 0),
                    'userId'            => $uid !== '' ? $uid : null,

                    // Nuevos en F1.
                    'note'              => $v['note'] ?? null,
                    'unitPrice'         => $units > 0 ? $total / $units : 0.0,
                    'itemSoldDiscount'  => (float) ($v['totalDiscount'] ?? 0),
                    'discountPercent'   => isset($v['discount']) ? (float) $v['discount'] : null,
                    'itemSoldComission' => null, // sin itemSold no hay comisión persistida
                    'userName'          => null, // resuelto abajo (batch)
                    'taxId'             => $v['taxId']   ?? null,
                    'taxRate'           => isset($v['taxRate']) ? (float) $v['taxRate'] : null,
                    'taxKind'           => $v['taxKind']  ?? null,
                    'taxIncluded'       => array_key_exists('taxIncluded', $v) ? (bool) $v['taxIncluded'] : null,
                    'taxAmount'         => (float) ($v['taxAmount'] ?? 0),
                    'taxNet'            => isset($v['taxNet']) ? (float) $v['taxNet'] : null,
                ];
            }
            if ($itemUserIds !== []) {
                $names = $this->contactNames(array_keys($itemUserIds), $companyId);
                foreach ($items as &$it) {
                    if ($it['userId'] !== null && isset($names[$it['userId']])) {
                        $it['userName'] = $names[$it['userId']];
                    }
                }
                unset($it);
            }
        }

        // ── Desglose de impuestos por tasa (toTaxObj, F2a) ────────────────
        $taxByRate = $this->resolveTaxByRate($id, $companyId, $metaLines);

        // ── Documentos vinculados (transaction_link, mig 115, context/35) ─
        $creditNotes  = $this->fetchTxSummaries($linkSvc->listDerivedIds($companyId, $id, 'return'), $companyId);
        $appointments = $this->fetchTxSummaries($linkSvc->listDerivedIds($companyId, $id, 'package_session'), $companyId);
        // quote_to_sale en AMBAS direcciones: la cotización que originó esta
        // venta (si esta transacción es una venta) y la venta facturada a
        // partir de esta cotización (si esta transacción es una cotización).
        $quotesOrigin  = $this->fetchTxSummaries($linkSvc->listOriginIds($companyId, $id, 'quote_to_sale'), $companyId);
        $quotesDerived = $this->fetchTxSummaries($linkSvc->listDerivedIds($companyId, $id, 'quote_to_sale'), $companyId);
        // Órdenes/comandas cobradas por esta factura (caso "mesa con varias
        // comandas", context/35 §order_transaction_link).
        $orders = $this->fetchOrderSummaries($linkSvc->listOrderIdsForTransaction($companyId, $id), $companyId);

        // `toTransaction` (tabla legacy, pre-mig-115): sin ningún INSERT vivo
        // en el codebase (grep confirmó — el único otro lector es el DELETE
        // en cascada de CompanyAdminService::delete()). DEPRECADO: transaction_link
        // + order_transaction_link lo reemplazan funcionalmente. Se sigue
        // leyendo acá por si queda alguna fila de datos pre-migración, pero
        // en la práctica siempre da []. No se borra la tabla en F1 (fuera de
        // alcance) — candidato a DROP en una migración futura.
        $toTx = ncmExecute(
            "SELECT tt.toTransactionId, tt.parentId, tt.transactionId
               FROM toTransaction tt
               JOIN transaction t ON t.transactionId = tt.transactionId AND t.companyId = ?
              WHERE tt.transactionId = ?",
            [$companyId, $id],
            false, false, true
        );
        $toTransactions = [];
        foreach ((is_array($toTx) ? $toTx : []) as $r) {
            $toTransactions[] = [
                'id'            => (string) ($r['toTransactionId'] ?? ''),
                'parentId'      => $r['parentId'] !== null ? (string) $r['parentId'] : null,
                'transactionId' => $r['transactionId'] !== null ? (string) $r['transactionId'] : null,
            ];
        }

        // ── Crédito (type=3): total/pagado/deuda + recibos ────────────────
        $creditPayments   = null;
        $paymentsReceived = [];
        if ($type === 3) {
            $totalNet = (float) ($tx['transactionTotal'] ?? 0) - (float) ($tx['transactionDiscount'] ?? 0);

            $creditPaymentIds = $linkSvc->listDerivedIds($companyId, $id, 'credit_payment');
            // paidForCreditOrigin() — superficie única para "cuánto se saldó
            // de esta factura a crédito" (credit_payment + return/nota de
            // crédito, mismo cálculo que `OpenInvoicesService::payedByParent()`).
            // Antes acá se usaba `sumDerivedAmounts('credit_payment')` solo
            // (respeta el `amount` del vínculo cuando un recibo se repartió
            // entre varias facturas) SIN restar una nota de crédito aplicada
            // — divergía del panel "Cuentas por Cobrar"/ficha de contacto
            // (que sí la resta) y de la Caja (POS, mismo bug, fix hermano en
            // `Services\TransactionService::getSingle()`).
            $paid = $linkSvc->paidForCreditOrigin($companyId, $id, true);
            $creditPayments = [
                'total' => $totalNet,
                'paid'  => $paid,
                'debt'  => max(0.0, $totalNet - $paid),
            ];

            if ($creditPaymentIds !== []) {
                $ph   = implode(',', array_fill(0, count($creditPaymentIds), '?'));
                $rows = ncmExecute(
                    "SELECT transactionId, transactionDate, transactionTotal, invoiceNo, transactionPaymentType
                       FROM transaction
                      WHERE transactionId IN ($ph) AND transactionType = 5 AND companyId = ?
                      ORDER BY transactionDate DESC",
                    array_merge($creditPaymentIds, [$companyId]),
                    false, false, true
                );
                foreach ((is_array($rows) ? $rows : []) as $r) {
                    $pm = json_decode((string) ($r['transactionPaymentType'] ?? '[]'), true) ?: [];
                    $paymentsReceived[] = [
                        'transactionId' => (string) $r['transactionId'],
                        'date'          => $r['transactionDate'] !== null ? (string) $r['transactionDate'] : null,
                        'amount'        => (float) $r['transactionTotal'],
                        'invoiceNo'     => $r['invoiceNo'] !== null ? (string) $r['invoiceNo'] : null,
                        'paymentMethod' => (string) ($pm[0]['name'] ?? ''),
                    ];
                }
            }
        }

        // ── Pagos (medios de pago) ─────────────────────────────────────────
        $rawPayments = json_decode((string) ($tx['transactionPaymentType'] ?? '[]'), true) ?: [];
        $payments = array_map(static function ($p) {
            return [
                'type'  => (string) ($p['type']  ?? ''),
                'name'  => getPaymentMethodName($p['type'] ?? ''),
                'total' => (float) ($p['total'] ?? $p['price'] ?? 0),
                'price' => (float) ($p['price'] ?? 0),
                'extra' => (string) ($p['extra'] ?? ''),
            ];
        }, is_array($rawPayments) ? $rawPayments : []);

        $subtotal = (float) ($tx['transactionTotal'] ?? 0); // bruto (pre-descuento) — semántica de buildSalePayload().subtotal
        $discount = (float) ($tx['transactionDiscount'] ?? 0);
        $netTotal = $subtotal - $discount;

        $txData = [
            'transactionId'          => (string) $tx['transactionId'],
            'transactionDate'        => $tx['transactionDate']    !== null ? (string) $tx['transactionDate']    : null,
            'transactionDueDate'     => $tx['transactionDueDate'] !== null ? (string) $tx['transactionDueDate'] : null,
            'transactionNote'        => $tx['transactionNote']    !== null ? (string) $tx['transactionNote']    : null,
            'transactionType'        => $type,
            'transactionStatus'      => (int) ($tx['transactionStatus'] ?? 0),
            'transactionComplete'    => (int) ($tx['transactionComplete'] ?? 0),
            'transactionTotal'       => $subtotal,
            'transactionDiscount'    => $discount,
            'transactionTax'         => (float) ($tx['transactionTax'] ?? 0),
            'transactionPaymentType' => $payments,
            'invoiceNo'              => $tx['invoiceNo'] !== null ? (string) $tx['invoiceNo'] : null,
            'customerId'             => $tx['customerId'] !== null ? (string) $tx['customerId'] : null,
            'customerName'           => $tx['customerName'] !== null ? (string) $tx['customerName'] : null,
            'customerTIN'            => $tx['customerTIN'] !== null ? (string) $tx['customerTIN'] : null,
            'userId'                 => $tx['userId'] !== null ? (string) $tx['userId'] : null,
            'userName'               => $tx['userName'] !== null ? (string) $tx['userName'] : null,
            'responsibleId'          => $tx['responsibleId'] !== null ? (string) $tx['responsibleId'] : null,
            'responsibleName'        => $tx['responsibleName'] !== null ? (string) $tx['responsibleName'] : null,
            'outletId'               => $tx['outletId'] !== null ? (string) $tx['outletId'] : null,
            'outletName'             => $tx['outletName'] !== null ? (string) $tx['outletName'] : null,
            'meta'                   => json_decode((string) ($tx['meta_raw'] ?? $tx['meta'] ?? '{}'), true) ?: [],

            // ── Nuevos en F1 (context/39) ──────────────────────────────────
            'condition'     => $type === 3 ? 'credit' : 'cash',
            // `type === 7` cubre el camino legacy (voidTransaction(), tipos
            // que NO son venta contado/crédito). Para venta (0/3) F1 de
            // context/40-anulacion-y-nota-credito.md NO pisa transactionType
            // — el flag real es `voidedAt` (mig 154).
            'void'          => $type === 7 || $tx['voidedAt'] !== null,
            'voidedAt'      => $tx['voidedAt']     !== null ? (string) $tx['voidedAt']     : null,
            'voidReason'    => $tx['voidReason']   !== null ? (string) $tx['voidReason']   : null,
            'voidedBy'      => $tx['voidedBy']     !== null ? (string) $tx['voidedBy']     : null,
            'voidedByName'  => $tx['voidedByName'] !== null ? (string) $tx['voidedByName'] : null,
            'currency'      => $tx['transactionCurrency'] !== null ? (string) $tx['transactionCurrency'] : null,
            'ivaRemoved'    => $ivaRemoved,
            'registerId'    => $registerId,
            'registerName'  => (string) ($reg['name'] ?? ''),
            'authNo'        => (string) ($reg['invoiceAuth'] ?? ''),
            'invoicePrefix' => $invoicePrefix,
            'invoiceNoPad'  => $invoiceNoPad,
            // Formateador único (mig 158). OJO: acá el separador ANTES no
            // existía (`$invoicePrefix . $invoiceNoPad` → "001-0010002129"),
            // mientras el listado y los reportes fiscales sí ponían el guion.
            // El detalle mostraba un número que no coincidía con el de la
            // factura impresa. Ahora los tres salen de `format()`.
            'docNo'         => \Punto\Api\Documents\DocumentNumber::format(
                $invoiceNoRaw, $invoicePrefix, $padWidth
            ),
            'subtotal'      => $subtotal,
            'netTotal'      => $netTotal,
        ];

        return [
            'transaction'      => $txData,
            'items'            => $items,
            'taxByRate'        => $taxByRate,
            'creditNotes'      => $creditNotes,
            'appointments'     => $appointments,
            'quotesOrigin'     => $quotesOrigin,
            'quotesDerived'    => $quotesDerived,
            'orders'           => $orders,
            'toTransactions'   => $toTransactions, // deprecado — ver comentario arriba
            'creditPayments'   => $creditPayments,
            'paymentsReceived' => $paymentsReceived,
        ];
    }

    /**
     * Desglose de impuestos por tasa: `toTaxObj.toTaxObjText` (congelado por
     * SaleService::persistRelations, F2a) es la fuente primaria. Si no hay
     * fila, o el JSON no parsea, degrada reconstruyendo desde las líneas YA
     * congeladas de meta.transactionDetails — mismo criterio que
     * SaleService::groupTaxByRate(), sin re-invocar el motor (los montos por
     * línea ya están frozen).
     *
     * F5 (context/38 §E, RG90/Libro Ventas) necesitó la MISMA regla en batch
     * para un rango de fechas — la lógica se extrajo a
     * `Tax\TaxBreakdownResolver` (decodeStoredJson/fromMetaLines) para no
     * duplicarla; acá solo queda la orquestación de un único transactionId
     * (misma query que antes). Único cambio de comportamiento, inocuo:
     * `decodeStoredJson()` normaliza cada bucket a `{taxId,rate,kind,base,
     * amount}` con cast de tipos (antes se devolvía el JSON decodificado tal
     * cual) y trata `"[]"` como ausente igual que un JSON vacío — más
     * estricto, no más laxo.
     *
     * El techo de VARCHAR(255) de `toTaxObjText` se levantó en la mig 124
     * (TEXT), así que las ventas nuevas ya no pueden truncar el desglose. El
     * fallback de abajo se mantiene igual: cubre las transacciones ANTERIORES
     * a esa migración, donde el JSON pudo haber quedado cortado o ausente.
     */
    private function resolveTaxByRate(string $transactionId, string $companyId, array $metaLines): array
    {
        $row = ncmExecute(
            'SELECT toTaxObjText FROM toTaxObj WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        $decoded = $row ? \Punto\Api\Tax\TaxBreakdownResolver::decodeStoredJson((string) ($row['toTaxObjText'] ?? '')) : null;
        if ($decoded !== null) {
            return $decoded;
        }

        return \Punto\Api\Tax\TaxBreakdownResolver::fromMetaLines($metaLines);
    }

    /**
     * Resúmenes mínimos de transacciones vinculadas (notas de crédito,
     * citas, cotizaciones) — lo suficiente para linkear y mostrar sin una
     * segunda ida y vuelta: id, tipo, número, fecha, total neto.
     *
     * @param list<string> $ids
     */
    private function fetchTxSummaries(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($v) => $v !== '' && $v !== null)));
        if ($ids === []) {
            return [];
        }
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = ncmExecute(
            "SELECT transactionId, transactionDate, transactionTotal, transactionDiscount,
                    transactionType, invoiceNo, invoicePrefix
               FROM transaction
              WHERE companyId = ? AND transactionId IN ($ph)",
            array_merge([$companyId], $ids),
            false, false, true
        );
        $out = [];
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $out[] = [
                'id'            => (string) $r['transactionId'],
                'type'          => (int) $r['transactionType'],
                'date'          => $r['transactionDate'] !== null ? (string) $r['transactionDate'] : null,
                'invoiceNo'     => $r['invoiceNo'] !== null ? (string) $r['invoiceNo'] : null,
                'invoicePrefix' => $r['invoicePrefix'] !== null ? (string) $r['invoicePrefix'] : null,
                'total'         => (float) ($r['transactionTotal'] ?? 0) - (float) ($r['transactionDiscount'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Resúmenes mínimos de órdenes/comandas (pos_order) cobradas por esta
     * transacción — caso "mesa con varias comandas" (context/35). `pos_order`
     * no tiene columna de total propia (es pre-cobro): se agrega desde
     * `pos_order_item` (qty × price), excluyendo ítems cancelados.
     *
     * @param list<string> $orderIds
     */
    private function fetchOrderSummaries(array $orderIds, string $companyId): array
    {
        $orderIds = array_values(array_unique(array_filter($orderIds, static fn ($v) => $v !== '' && $v !== null)));
        if ($orderIds === []) {
            return [];
        }
        $ph    = implode(',', array_fill(0, count($orderIds), '?'));
        $rows  = ncmExecute(
            "SELECT orderid, ordernumber, status, created_at
               FROM pos_order
              WHERE companyid = ? AND orderid IN ($ph)",
            array_merge([$companyId], $orderIds),
            false, false, true
        );
        $rows = is_array($rows) ? $rows : [];
        if ($rows === []) {
            return [];
        }

        $totalsRows = ncmExecute(
            "SELECT orderid, COALESCE(SUM(qty * COALESCE(price, 0)), 0) AS total
               FROM pos_order_item
              WHERE companyid = ? AND orderid IN ($ph) AND status <> 'cancelled'
              GROUP BY orderid",
            array_merge([$companyId], $orderIds),
            false, false, true
        );
        $totalsMap = [];
        foreach ((is_array($totalsRows) ? $totalsRows : []) as $t) {
            $totalsMap[(string) $t['orderid']] = (float) ($t['total'] ?? 0);
        }

        $out = [];
        foreach ($rows as $r) {
            $oid  = (string) $r['orderid'];
            $out[] = [
                'id'          => $oid,
                'orderNumber' => isset($r['ordernumber']) ? (int) $r['ordernumber'] : null,
                'status'      => (string) ($r['status'] ?? ''),
                'date'        => $r['created_at'] !== null ? (string) $r['created_at'] : null,
                'total'       => $totalsMap[$oid] ?? 0.0,
            ];
        }
        return $out;
    }

    /**
     * Nombres de contacto en batch (líneas de tipos sin itemSold, donde el
     * usuario de línea viene de meta y no hay JOIN posible en la query
     * principal).
     *
     * @param list<string> $ids
     * @return array<string,string>
     */
    private function contactNames(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($v) => preg_match(self::UUID_RE, (string) $v) === 1)));
        if ($ids === []) {
            return [];
        }
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = ncmExecute(
            "SELECT contactId, contactName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids),
            false, false, true
        );
        $map = [];
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $map[(string) $r['contactId']] = (string) ($r['contactName'] ?? '');
        }
        return $map;
    }
}
