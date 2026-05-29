<?php
/**
 * CustomerService — lecturas agregadas del cliente para el POS (desacople de /app).
 *
 * getInfo porta el handler `customerInfo` (app/load.php L1906): resumen para el
 * modal de info del cliente — contacto + últimos ítems comprados + deuda (cuenta
 * corriente y vencida) + gift cards + dirección default.
 *
 * Lecturas con ncmExecute; las sumatorias por lote y el backfill de dirección usan
 * $db->Execute/$db->Insert DIRECTOS y PARAMETRIZADOS (ncmInsert llama a Insert_ID(),
 * no implementado en la DB.php de /app → fatal). Esto corrige bugs PG del legacy:
 *   - El legacy hacía STRING_AGG(transactionId) + `IN(<string>)` sin comillas →
 *     UUIDs sin quotear, SQL roto en PG. Acá se recolectan los ids y se usa
 *     `IN (?,?,…)` parametrizado (los ids vienen de nuestra propia query, no del user).
 *   - Scope companyId agregado a todas las queries de transaction (el legacy sólo
 *     filtraba customerId).
 *
 * BUG LEGACY PRESERVADO (a propósito, para no cambiar el número que ve el usuario):
 * el cálculo de la deuda VENCIDA usa el total de devoluciones de la deuda CORRIENTE
 * ($retCurrent), no el de las vencidas — el legacy referenciaba $totalRetrns en vez
 * de $totalRetrnsV (que además era dead code). Ver QA si esto se decide corregir.
 */

class CustomerService
{
    /**
     * @return array|null  Shape legacy de customerInfo, o null si el cliente no existe.
     */
    public function getInfo(string $companyId, string $rawId): ?array
    {
        global $db, $dec, $ts;

        // id: numérico → tal cual (el front a veces lo manda sin encriptar); si no, dec().
        $id = is_numeric($rawId) ? $rawId : dec($rawId);

        $customer = ncmExecute(
            'SELECT * FROM contact WHERE type = 1 AND contactId = ? AND companyId = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$customer) {
            return null;
        }

        $purchasedItems   = [];
        $giftCards        = [];
        $notes            = []; // el legacy nunca lo poblaba — se mantiene vacío.
        $lastPurchaseDate = '';

        // --- Últimos 5 ítems comprados (ventas al contado, type 0) ---
        $allUsers = getAllContacts(0);
        $itemsRs  = $db->Execute(
            "SELECT a.itemId AS id, a.itemSoldUnits AS usold, a.itemSoldDate AS date, a.userId AS usr
               FROM itemSold a, transaction b
              WHERE b.transactionType = '0' AND b.customerId = ? AND b.companyId = ?
                AND a.transactionId = b.transactionId
              ORDER BY a.itemSoldDate DESC LIMIT 5",
            [$id, $companyId]
        );
        if ($itemsRs && !$itemsRs->EOF) {
            $first = true;
            while (!$itemsRs->EOF) {
                $f = $itemsRs->fields;
                if ($first) {
                    $lastPurchaseDate = $f['date'];
                    $first = false;
                }
                $purchasedItems[] = [
                    'itemName' => toUTF8(getItemName($f['id'])),
                    'userName' => toUTF8(getTheContactField($f['usr'], $allUsers)),
                    'itemQty'  => $f['usold'],
                ];
                $itemsRs->MoveNext();
            }
        }

        // --- Cuenta corriente (créditos type 3 sin completar) ---
        $totalDeuda = 0;
        $debtList   = '';
        $retCurrent = 0.0; // devoluciones de la deuda corriente — reusado por la vencida (bug legacy).

        [$curIds, $curTotal, $curDiscount] = $this->creditRows($companyId, $id, false);
        if (!empty($curIds)) {
            $retCurrent = $this->sumReturns($companyId, $id, $curIds);
            $payed      = $this->sumPayments($companyId, $id, $curIds);

            $totalComprado = $curTotal - $curDiscount;
            $totalPagado   = $payed + abs($retCurrent);
            if ($totalPagado < $totalComprado) {
                $totalDeuda = $totalComprado - $totalPagado;
                $debtList   = json_encode(getDebtListByTransaction($id));
            }
        }

        // --- Deuda vencida (dueDate <= hoy) ---
        $totalDeudaV = 0;
        $debtListV   = '';

        [$vIds, $vTotal, $vDiscount] = $this->creditRows($companyId, $id, true);
        $totalCompradoV = 0;
        $totalPagadoV   = 0;
        if (!empty($vIds)) {
            $payedV         = $this->sumPayments($companyId, $id, $vIds);
            $totalCompradoV = $vTotal - $vDiscount;
            // Bug legacy preservado: abs($retCurrent) (devoluciones de la deuda corriente).
            $totalPagadoV   = $payedV + abs($retCurrent);
        }
        if ($totalPagadoV < $totalCompradoV) {
            $totalDeudaV = $totalCompradoV - $totalPagadoV;
            $debtListV   = json_encode(getDebtListByTransaction($id, true));
        }

        $creditLine = $customer['contactCreditLine'];

        // --- Gift cards del cliente ---
        $gcRs = $db->Execute(
            'SELECT * FROM giftCardSold WHERE giftCardSoldBeneficiaryId = ? AND companyId = ?',
            [$id, $companyId]
        );
        if ($gcRs && !$gcRs->EOF) {
            while (!$gcRs->EOF) {
                $g    = $gcRs->fields;
                $code = iftn($g['giftCardSoldCode'], $g['timestamp']);
                $giftCards[] = [
                    'giftCardCode'  => $code,
                    'giftCardUID'   => $g['timestamp'],
                    'giftCardTotal' => formatCurrentNumber($g['giftCardSoldValue']),
                ];
                $gcRs->MoveNext();
            }
        }

        // --- Dirección default (con backfill lazy del legacy) ---
        $address = $customer['contactAddress'];
        $latLng  = $customer['contactLatLng'];

        $custAddr = ncmExecute(
            'SELECT * FROM customerAddress WHERE companyId = ? AND customerAddressDefault = true AND customerId = ? LIMIT 1',
            [$companyId, $customer['contactId']]
        );
        if ($custAddr) {
            $address = $custAddr['customerAddressText'];
            if ($custAddr['customerAddressLat'] && $custAddr['customerAddressLng']) {
                $latLng = $custAddr['customerAddressLat'] . ',' . $custAddr['customerAddressLng'];
            }
        } elseif ($address) {
            // Backfill: si el contacto tiene address pero no hay fila default, créala.
            $db->Insert('customerAddress', [
                'customerAddressText'    => $address,
                'customerAddressDefault' => true,
                'customerAddressDate'    => TODAY,
                'companyId'              => $companyId,
                'customerId'             => $customer['contactId'],
            ]);
        }

        return [
            'customerId'           => enc($customer['contactId']),
            'customerName'         => toUTF8($customer['contactName']),
            'customerFullName'     => $customer['contactSecondName'],
            'customerTIN'          => $customer['contactTIN'],
            'customerCI'           => $customer['contactCI'],
            'customerPhone1'       => $customer['contactPhone'],
            'customerPhone2'       => $customer['contactPhone2'],
            'customerEmail'        => $customer['contactEmail'],
            'customerAddress1'     => $address,
            'customerAddress2'     => $customer['contactAddress2'],
            'customerNote'         => $customer['contactNote'],
            'customerMemberSince'  => niceDate($customer['contactDate']),
            'customerBDay'         => niceDate($customer['contactBirthDay']),
            'latLng'               => $latLng,
            'lastPurchase'         => niceDate($lastPurchaseDate),
            'totalDebt'            => formatCurrentNumber($totalDeuda, $dec, $ts),
            'totalDebtRaw'         => $totalDeuda,
            'totalDebtData'        => $debtList,
            'expiredDebt'          => formatCurrentNumber($totalDeudaV, $dec, $ts),
            'expiredDebtRaw'       => $totalDeudaV,
            'expiredDebtData'      => $debtListV,
            'loyalty'              => formatCurrentNumber($customer['contactLoyaltyAmount'], $dec, $ts),
            'inCredit'             => formatCurrentNumber($customer['contactStoreCredit'], $dec, $ts),
            'creditLine'           => formatCurrentNumber($creditLine, $dec, $ts),
            'latestPurchasedItems' => $purchasedItems,
            'giftCards'            => $giftCards,
            'notes'                => $notes,
        ];
    }

    // --- internos -----------------------------------------------------------

    /**
     * Filas de crédito (type 3) sin completar del cliente. Devuelve [ids[], total, discount].
     * @return array{0:string[],1:float,2:float}
     */
    private function creditRows(string $companyId, string $id, bool $overdue): array
    {
        global $db;

        $sql = 'SELECT transactionId, transactionTotal, transactionDiscount
                  FROM transaction
                 WHERE customerId = ? AND companyId = ? AND transactionType = 3 AND transactionComplete = false';
        $params = [$id, $companyId];
        if ($overdue) {
            $sql .= ' AND transactionDueDate <= ?';
            $params[] = TODAY;
        }

        $rs = $db->Execute($sql, $params);

        $ids = [];
        $total = 0.0;
        $discount = 0.0;
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $f         = $rs->fields;
                $ids[]     = (string) $f['transactionId'];
                $total    += (float) $f['transactionTotal'];
                $discount += (float) $f['transactionDiscount'];
                $rs->MoveNext();
            }
        }
        return [$ids, $total, $discount];
    }

    /** SUM(ABS(total)) de devoluciones (type 6) cuyas transactionParentId ∈ $ids. */
    private function sumReturns(string $companyId, string $id, array $ids): float
    {
        global $db;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rs = $db->Execute(
            "SELECT SUM(ABS(transactionTotal)) AS total FROM transaction
              WHERE customerId = ? AND companyId = ? AND transactionType = 6
                AND transactionParentId IN ($ph)",
            array_merge([$id, $companyId], $ids)
        );
        return ($rs && !$rs->EOF) ? (float) ($rs->fields['total'] ?? 0) : 0.0;
    }

    /** SUM(total) de pagos (type 5) cuyas transactionParentId ∈ $ids. */
    private function sumPayments(string $companyId, string $id, array $ids): float
    {
        global $db;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rs = $db->Execute(
            "SELECT SUM(transactionTotal) AS payed FROM transaction
              WHERE transactionType = 5 AND companyId = ? AND customerId = ?
                AND transactionParentId IN ($ph)",
            array_merge([$companyId, $id], $ids)
        );
        return ($rs && !$rs->EOF) ? (float) ($rs->fields['payed'] ?? 0) : 0.0;
    }
}
