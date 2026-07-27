<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)

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

final class CustomerService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

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
            'SELECT * FROM customerAddress WHERE companyId = ? AND customerAddressDefault = true AND status = 1 AND customerId = ? LIMIT 1',
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

    // ========================================================================
    // RECURSOS GRANULARES (piloto BFF-compone — ver context/10-roadmap.md)
    //
    // getInfo() arriba es el endpoint COMPUESTO legacy (la API arma toda la
    // pantalla). Estos métodos exponen el mismo dato pero PARTIDO en recursos
    // reusables: cada uno responde un concepto del cliente, independiente y
    // cacheable. El BFF (app/bff/customers.php) los compone en paralelo y arma
    // el shape de la pantalla. Cualquier cliente (mobile, integración) puede
    // pedir solo el recurso que necesita (ej. ?resource=debt).
    //
    // getInfo() queda como conveniencia/backward-compat; el path vigente es la
    // composición en el BFF.
    // ========================================================================

    /** Perfil del cliente: campos del contacto (sin la dirección default, que es su propio recurso). */
    public function getProfile(string $companyId, string $rawId): ?array
    {
        global $dec, $ts;
        $id = is_numeric($rawId) ? $rawId : dec($rawId);

        $c = ncmExecute(
            'SELECT * FROM contact WHERE type = 1 AND contactId = ? AND companyId = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$c) {
            return null;
        }
        return [
            'customerId'          => enc($c['contactId']),
            'customerName'        => toUTF8($c['contactName']),
            'customerFullName'    => $c['contactSecondName'],
            'customerTIN'         => $c['contactTIN'],
            'customerCI'          => $c['contactCI'],
            'customerPhone1'      => $c['contactPhone'],
            'customerPhone2'      => $c['contactPhone2'],
            'customerEmail'       => $c['contactEmail'],
            'customerAddress2'    => $c['contactAddress2'],
            'customerNote'        => $c['contactNote'],
            'customerMemberSince' => niceDate($c['contactDate']),
            'customerBDay'        => niceDate($c['contactBirthDay']),
            'loyalty'             => formatCurrentNumber($c['contactLoyaltyAmount'], $dec, $ts),
            'inCredit'            => formatCurrentNumber($c['contactStoreCredit'], $dec, $ts),
            'creditLine'          => formatCurrentNumber($c['contactCreditLine'], $dec, $ts),
        ];
    }

    /** Últimos 5 ítems comprados (ventas type 0) + fecha de la última compra. */
    public function getRecentItems(string $companyId, string $rawId): array
    {
        global $db;
        $id = is_numeric($rawId) ? $rawId : dec($rawId);

        $items = [];
        $lastPurchaseDate = '';
        $allUsers = getAllContacts(0);
        $rs = $db->Execute(
            "SELECT a.itemId AS id, a.itemSoldUnits AS usold, a.itemSoldDate AS date, a.userId AS usr
               FROM itemSold a, transaction b
              WHERE b.transactionType = '0' AND b.customerId = ? AND b.companyId = ?
                AND a.transactionId = b.transactionId
              ORDER BY a.itemSoldDate DESC LIMIT 5",
            [$id, $companyId]
        );
        if ($rs && !$rs->EOF) {
            $first = true;
            while (!$rs->EOF) {
                $f = $rs->fields;
                if ($first) { $lastPurchaseDate = $f['date']; $first = false; }
                $items[] = [
                    'itemName' => toUTF8(getItemName($f['id'])),
                    'userName' => toUTF8(getTheContactField($f['usr'], $allUsers)),
                    'itemQty'  => $f['usold'],
                ];
                $rs->MoveNext();
            }
        }
        return ['latestPurchasedItems' => $items, 'lastPurchase' => niceDate($lastPurchaseDate)];
    }

    /** Deuda del cliente: cuenta corriente + vencida (créditos type 3 sin completar). */
    public function getDebt(string $companyId, string $rawId): array
    {
        global $dec, $ts;
        $id = is_numeric($rawId) ? $rawId : dec($rawId);

        // Corriente
        $totalDeuda = 0;
        $debtList   = '';
        $retCurrent = 0.0;
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

        // Vencida (dueDate <= hoy). Bug legacy preservado: usa abs($retCurrent).
        $totalDeudaV = 0;
        $debtListV   = '';
        [$vIds, $vTotal, $vDiscount] = $this->creditRows($companyId, $id, true);
        $totalCompradoV = 0;
        $totalPagadoV   = 0;
        if (!empty($vIds)) {
            $payedV         = $this->sumPayments($companyId, $id, $vIds);
            $totalCompradoV = $vTotal - $vDiscount;
            $totalPagadoV   = $payedV + abs($retCurrent);
        }
        if ($totalPagadoV < $totalCompradoV) {
            $totalDeudaV = $totalCompradoV - $totalPagadoV;
            $debtListV   = json_encode(getDebtListByTransaction($id, true));
        }

        return [
            'totalDebt'       => formatCurrentNumber($totalDeuda, $dec, $ts),
            'totalDebtRaw'    => $totalDeuda,
            'totalDebtData'   => $debtList,
            'expiredDebt'     => formatCurrentNumber($totalDeudaV, $dec, $ts),
            'expiredDebtRaw'  => $totalDeudaV,
            'expiredDebtData' => $debtListV,
        ];
    }

    /** Gift cards activas del cliente (donde es beneficiario). */
    public function getGiftCards(string $companyId, string $rawId): array
    {
        global $db;
        $id = is_numeric($rawId) ? $rawId : dec($rawId);

        $giftCards = [];
        $rs = $db->Execute(
            'SELECT * FROM giftCardSold WHERE giftCardSoldBeneficiaryId = ? AND companyId = ?',
            [$id, $companyId]
        );
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $g = $rs->fields;
                $giftCards[] = [
                    'giftCardCode'  => iftn($g['giftCardSoldCode'], $g['timestamp']),
                    'giftCardUID'   => $g['timestamp'],
                    'giftCardTotal' => formatCurrentNumber($g['giftCardSoldValue']),
                ];
                $rs->MoveNext();
            }
        }
        return ['giftCards' => $giftCards];
    }

    /** Dirección default del cliente (con backfill lazy del legacy). */
    public function getDefaultAddress(string $companyId, string $rawId): array
    {
        global $db;
        $id = is_numeric($rawId) ? $rawId : dec($rawId);

        // SELECT * (no columnas específicas): contactAddress/contactLatLng están
        // DEMOTED a data JSONB → solo _flattenJsonb (vía ncmExecute) las expone. §22.8.
        $c = ncmExecute(
            'SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$c) {
            return ['customerAddress1' => '', 'latLng' => ''];
        }
        $address = $c['contactAddress'] ?? null;
        $latLng  = $c['contactLatLng'] ?? null;

        $custAddr = ncmExecute(
            'SELECT * FROM customerAddress WHERE companyId = ? AND customerAddressDefault = true AND status = 1 AND customerId = ? LIMIT 1',
            [$companyId, $c['contactId']]
        );
        if ($custAddr) {
            $address = $custAddr['customerAddressText'];
            if ($custAddr['customerAddressLat'] && $custAddr['customerAddressLng']) {
                $latLng = $custAddr['customerAddressLat'] . ',' . $custAddr['customerAddressLng'];
            }
        } elseif ($address) {
            $db->Insert('customerAddress', [
                'customerAddressText'    => $address,
                'customerAddressDefault' => true,
                'customerAddressDate'    => TODAY,
                'companyId'              => $companyId,
                'customerId'             => $c['contactId'],
            ]);
        }
        return ['customerAddress1' => $address, 'latLng' => $latLng];
    }

    /**
     * customerRecord (load.php L1430): fichas personalizadas del cliente.
     *
     * Devuelve datos ESTRUCTURADOS (el front renderiza con #customerRecordTpl); el
     * legacy renderizaba HTML server-side. Cada campo trae flags por tipo (isText…isTitle)
     * para las secciones del template logic-less (Mustache). valueHtml = markup→HTML
     * para texto largo (type 1); valueDate = niceDate para la vista de impresión (type 4).
     *
     * Fija el SQL injection del legacy: getValue() concatenaba cRecordFieldId/customerId
     * en el WHERE → acá se usan queries parametrizadas. Scope companyId en customerRecord.
     *
     * @return array  Shape para #customerRecordTpl (records vacío si no hay fichas).
     */
    public function getRecords(string $companyId, string $encId): array
    {
        $customerId = dec($encId);
        $customer   = getContactData($customerId, 'uid');

        $recRs = ncmExecute(
            'SELECT * FROM customerRecord WHERE companyId = ? ORDER BY customerRecordSort ASC LIMIT 10000',
            [$companyId],
            false,
            true
        );

        $records = [];
        if ($recRs) {
            while (!$recRs->EOF) {
                $r        = $recRs->fields;
                $recordId = $r['customerRecordId'];

                $fieldRs = ncmExecute(
                    'SELECT * FROM cRecordField WHERE customerRecordId = ? ORDER BY cRecordFieldSort ASC',
                    [$recordId],
                    false,
                    true
                );

                $fields = [];
                if ($fieldRs) {
                    while (!$fieldRs->EOF) {
                        $fld   = $fieldRs->fields;
                        $fId   = $fld['cRecordFieldId'];
                        $fType = (int) $fld['cRecordFieldType'];

                        // Último valor del campo para este cliente (parametrizado).
                        $valRow = ncmExecute(
                            'SELECT cRecordValueName FROM cRecordValue
                              WHERE cRecordFieldId = ? AND customerId = ?
                              ORDER BY cRecordValueDate DESC LIMIT 1',
                            [$fId, $customerId]
                        );
                        $value = $valRow ? (string) $valRow['cRecordValueName'] : '';
                        $value = html_entity_decode(str_replace(['<!--', '-->'], '', $value));

                        $encFId = enc($fId);
                        $fields[] = [
                            'id'          => $encFId,
                            'name'        => $fld['cRecordFieldName'],
                            'type'        => $fType,
                            'progress'    => $fld['cRecordFieldProgress'],
                            'hasProgress' => (bool) $fld['cRecordFieldProgress'],
                            'value'       => $value,
                            'valueHtml'   => ($fType === 1) ? markupt2HTML(['text' => $value, 'type' => 'MtH']) : '',
                            'valueDate'   => ($fType === 4) ? niceDate($value, false, false, true, true) : '',
                            'switchOn'    => ($value > 0),
                            'imageFolder' => ($fType === 5) ? ('/customer/' . $encId . '/records/' . $encFId) : '',
                            'isText'      => $fType === 0,
                            'isLong'      => $fType === 1,
                            'isNumber'    => $fType === 2,
                            'isSwitch'    => $fType === 3,
                            'isDate'      => $fType === 4,
                            'isImage'     => $fType === 5,
                            'isTitle'     => $fType === 6,
                        ];
                        $fieldRs->MoveNext();
                    }
                }

                $records[] = [
                    'id'     => enc($recordId),
                    'name'   => $r['customerRecordName'],
                    'fields' => $fields,
                ];
                $recRs->MoveNext();
            }
        }

        return [
            'cId'          => $encId,
            'customerName' => getCustomerName($customer),
            'companyLogo'  => enc($companyId),
            'today'        => niceDate(TODAY, false, false, false, true),
            'personal'     => [
                'ci'          => $customer['ci'] ?? '',
                'gender'      => $customer['gender'] ?? '',
                'age'         => $customer['age'] ?? '',
                'bDay'        => $customer['bDay'] ?? '',
                'phone'       => $customer['phone'] ?? '',
                'email'       => $customer['email'] ?? '',
                'countryName' => $customer['countryName'] ?? '',
                'city'        => $customer['city'] ?? '',
                'location'    => $customer['location'] ?? '',
                'address'     => $customer['address'] ?? '',
            ],
            'hasRecords'   => !empty($records),
            'records'      => $records,
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
