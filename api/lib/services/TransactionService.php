<?php
/**
 * TransactionService — operaciones sobre transacciones/órdenes del POS (Slice 6).
 *
 * Lógica portada de app/action.php:
 *   deleteTransaction  (L1188) — elimina una transacción
 *   deleteInPrintServer (L155) — elimina un job de la cola de impresión
 *   rejectOrder        (L1260) — rechaza una orden (status 6); la notif WS va al caller
 *   recordItemDeletion  (L605) — inserta en itemDeleted (historial de borrado de ítems)
 *
 * Bugs PG corregidos respecto al legacy:
 *   - LIMIT 1 en DELETE → eliminado (transactionId es PK, limita solo).
 *   - db_prepare() interpolado en WHERE → bindeo parametrizado.
 *   - UUIDs enviados sin comillas en SQL → bindeados.
 *
 * El caller (api/v1/transactions.php) es responsable de los efectos secundarios
 * (sendWS, updateLastTimeEdit) para que el servicio sea puro de BD.
 */

class TransactionService
{
    /**
     * Lee una transacción por ID y devuelve el shape que espera el POS front.
     * tags y transactionDetails viven en meta JSONB (§22.6); ncmExecute/_flattenJsonb
     * los expone como strings en $fields antes de json_decode.
     *
     * @return array|null null si la transacción no existe.
     */
    public function getSingle(string $transactionId, string $companyId): ?array
    {
        $fields = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$fields) {
            return null;
        }

        // tags en meta → _flattenJsonb expone como JSON string
        $rawTags = $fields['tags'] ?? null;
        $tags    = $rawTags ? (implodes(',', json_decode($rawTags, true)) ?: '') : '';

        $paymentType  = json_decode($fields['transactionPaymentType'] ?? '', true);
        $paymentTypes = [];
        if ($paymentType) {
            foreach ($paymentType as $v) {
                $paymentTypes[] = [
                    'amount' => $v['price'],
                    'name'   => getPaymentMethodName($v['type']),
                    'type'   => $v['type'],
                    'extra'  => $v['extra'],
                    'UID'    => $v['UID'] ?? '',
                ];
            }
        }

        $payedData = [];
        $payed     = 0;
        if ((string) $fields['transactionType'] === '3') {
            $payedData = $this->getTypePayments($transactionId, $companyId);
            $payedRow  = ncmExecute(
                'SELECT SUM(ABS(transactionTotal)) as payed FROM transaction WHERE transactionType IN(5,6) AND transactionParentId = ? AND companyId = ?',
                [$transactionId, $companyId]
            );
            $payed = (float) ($payedRow['payed'] ?? 0);
        }

        $address = null;
        if ((string) $fields['transactionType'] === '12') {
            $address = getCustomerTransactionAddress($transactionId, true);
        }

        $startDate = false;
        $startH    = false;
        $endH      = false;
        if (!empty($fields['fromDate']) && !empty($fields['toDate'])) {
            [$startDate, $startH, $endH] = dateStartEndTime($fields['fromDate'], $fields['toDate']);
        }

        $isSession = $fields['transactionParentId'] ? enc($fields['transactionParentId']) : false;
        $discount  = (float) ($fields['transactionDiscount'] ?? 0);
        $total     = (float) ($fields['transactionTotal'] ?? 0) - $discount;

        // transactionDetails en meta → _flattenJsonb expone como JSON string
        $rawDetails       = $fields['transactionDetails'] ?? null;
        $transactionDatas = $rawDetails ? json_decode($rawDetails, true) : null;

        return [
            'transactionId'   => enc($fields['transactionId']),
            'customerId'      => enc($fields['customerId']),
            'customerUnd'     => $fields['customerId'],
            'userId'          => enc($fields['userId']),
            'note'            => $fields['transactionNote'],
            'tags'            => $tags,
            'documentNo'      => $fields['invoiceNo'],
            'invoicePrefix'   => $fields['invoicePrefix'] ?? '',
            'name'            => $fields['transactionName'],
            'type'            => $fields['transactionType'],
            'status'          => $fields['transactionStatus'],
            'date'            => $fields['transactionDate'],
            'dueDate'         => $fields['transactionDueDate'],
            'startDate'       => $startDate,
            'endDate'         => $fields['toDate'],
            'startHour'       => $startH,
            'endHour'         => $endH,
            'hasSession'      => $isSession,
            'isSession'       => $isSession,
            'parentID'        => $isSession,
            'UID'             => $fields['timestamp'],
            'pMethods'        => $paymentTypes,
            'toPay'           => $total - $payed,
            'total'           => number_format($total, 2, '.', ''),
            'discount'        => number_format($discount, 2, '.', ''),
            'payedData'       => $payedData,
            'transactionData' => $rawDetails,
            'transactionDatas'=> $transactionDatas,
            'address'         => $address,
        ];
    }

    /**
     * Pagos de crédito/deuda (tipo 5) de una transacción padre.
     * Versión corregida de getAllTransactionPayments: parametriza el UUID (la versión
     * legacy lo interpolaba sin comillas → siempre falla en PG).
     */
    private function getTypePayments(string $transactionId, string $companyId): array
    {
        global $db;
        $result = $db->Execute(
            'SELECT transactionId, transactionTotal, userId, transactionDate, transactionPaymentType, invoiceNo
             FROM transaction
             WHERE transactionType = 5 AND transactionParentId = ? AND companyId = ?
             LIMIT 100',
            [$transactionId, $companyId]
        );
        if (!$result || $result->EOF) {
            return [];
        }
        $a = [];
        while (!$result->EOF) {
            $f   = $result->fields;
            $a[] = [
                'id'        => enc($f['transactionId']),
                'total'     => abs($f['transactionTotal']),
                'userid'    => $f['userId'],
                'date'      => $f['transactionDate'],
                'methods'   => $f['transactionPaymentType'],
                'receiptNo' => $f['invoiceNo'],
            ];
            $result->MoveNext();
        }
        $result->Close();
        return $a;
    }


    /**
     * Elimina una transacción. Requiere scope companyId (tenant).
     * LIMIT 1 omitido: transactionId es PRIMARY KEY → único por definición.
     */
    public function delete(string $transactionId, string $companyId): bool
    {
        global $db;
        $res = $db->Execute(
            'DELETE FROM transaction WHERE transactionId = ? AND companyId = ?',
            [$transactionId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Elimina un job de la cola printServer por transactionId + companyId.
     */
    public function deletePrintJob(string $transactionId, string $companyId): bool
    {
        global $db;
        $res = $db->Execute(
            'DELETE FROM printServer WHERE transactionId = ? AND companyId = ?',
            [$transactionId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Rechaza una orden: transactionStatus = 6 + nota opcional.
     * La notificación WS (sendWS) queda en el caller (api/v1/transactions.php).
     *
     * @param string      $transactionId  UUID de la transacción.
     * @param string      $companyId      UUID del tenant.
     * @param string|null $motive         Motivo del rechazo (opcional). Se strip_tags.
     * @return bool       true si no hubo error de BD (igual que el legacy !==false; no implica que existía).
     */
    public function reject(string $transactionId, string $companyId, ?string $motive): bool
    {
        global $db;

        $setCols = 'transactionStatus = ?, updated_at = NOW()';
        $params  = [6];

        if ($motive !== null && $motive !== '') {
            $setCols  .= ', transactionNote = ?';
            $params[]  = strip_tags($motive);
        }
        $params[] = $transactionId;
        $params[] = $companyId;

        $res = $db->Execute(
            "UPDATE transaction SET $setCols WHERE transactionId = ? AND companyId = ?",
            $params
        );
        return $res !== false;
    }

    /**
     * Registra la eliminación de un ítem en el historial (itemDeleted).
     * El motive se sanitiza con markupt2HTML (igual que el legacy; preserva formato markup).
     *
     * @param string $itemId    UUID del ítem eliminado.
     * @param string $motive    Motivo del borrado.
     * @param string $userId    UUID del usuario que lo borró (se guarda en el JSON data).
     * @param string $companyId UUID del tenant.
     * @param string $outletId  UUID del outlet.
     */
    public function recordItemDeletion(
        string $itemId,
        string $motive,
        string $userId,
        string $companyId,
        string $outletId
    ): bool {
        global $db;
        $data = json_encode(['motive' => markupt2HTML(['text' => $motive, 'type' => 'HtM']), 'user' => $userId]);
        $res  = $db->Execute(
            'INSERT INTO itemDeleted (itemId, date, data, companyId, outletId)
             VALUES (?, NOW(), ?, ?, ?)',
            [$itemId, $data, $companyId, $outletId]
        );
        return $res !== false;
    }

    // -------------------------------------------------------------------------
    // Slice 28 — quotesList + savedList (load.php L2863 + L2979)
    // -------------------------------------------------------------------------

    /**
     * Lista paginada de transacciones para el panel de ventas (buildList).
     * Cubre listType = 'quotes' (type 9) y 'saved' (type 2).
     *
     * Retorna { date, listName, transactionsList[], footBtn } — mismo shape
     * que OrderService::getList (orders), compatible con #transactionsListTpl.
     *
     * Fija el SQL injection del legacy (concatenación de COMPANY_ID / outletId /
     * customerId / dates directamente en la cadena SQL).
     */
    public function getTransactionList(
        string  $listType,
        string  $outletId,
        string  $companyId,
        ?string $encCustomerId,
        ?string $date,
        int     $limit
    ): array {
        global $db, $dec, $ts;

        static $configs = [
            'quotes' => [
                'txType'   => 9,
                'listName' => 'Cotizaciones',
                'label'    => '<span class="label bg-warning dk text-xs">Cotización</span>',
                'anchor'   => 'openQuotes',
                'colors'   => ['0'=>'b-light','1'=>'b-light','2'=>'b-info','3'=>'b-primary',
                               '4'=>'b-success','5'=>'b-danger','6'=>'b-3x b-l b-dark'],
            ],
            'saved' => [
                'txType'   => 2,
                'listName' => 'Guardado',
                'label'    => '<span class="label text-dark b b-light text-xs">Guardado</span>',
                'anchor'   => 'openSaved',
                'colors'   => ['0'=>'b-light','1'=>'b-light','2'=>'b-info','3'=>'b-primary',
                               '4'=>'b-success','5'=>'b-danger','6'=>'b-3x b-l b-dark'],
            ],
        ];

        $cfg = $configs[$listType] ?? $configs['quotes'];

        $where  = ['transactionType = ?', 'companyId = ?'];
        $params = [(int) $cfg['txType'], $companyId];

        if ($encCustomerId) {
            $where[]  = 'customerId = ?';
            $params[] = dec($encCustomerId);
        } else {
            $where[]  = 'outletId = ?';
            $params[] = $outletId;
        }

        if ($date) {
            $where[]  = 'transactionDate BETWEEN ? AND ?';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 23:59:59';
            $limit    = 1500;
        }

        $sql = 'SELECT * FROM transaction WHERE ' . implode(' AND ', $where)
             . ' ORDER BY transactionDate DESC LIMIT ' . (int) $limit;
        $rs  = $db->Execute($sql, $params);

        $anchor = $cfg['anchor'];
        $out = [
            'date'             => $date,
            'listName'         => $cfg['listName'],
            'transactionsList' => [],
            'footBtn'          => '',
        ];

        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $f      = $rs->fields;
                $status = (string) ($f['transactionStatus'] ?? '');
                $cusData = getCustomerData($f['customerId'] ?? null, 'uid');
                $cusName = iftn(getCustomerName($cusData), 'Sin Nombre');

                $txName = ($listType === 'saved')
                    ? '<span class="text-info">' . ($f['transactionName'] ?? '') . '</span>'
                    : '';

                $stat = 'b-5x b-l ' . ($cfg['colors'][$status] ?? '');

                $out['transactionsList'][] = [
                    'id'          => enc($f['transactionId']),
                    'title'       => $txName . ' ' . $cusName,
                    'date'        => niceDate($f['transactionDate']),
                    'docNumber'   => ' #' . $f['invoicePrefix'] . $f['invoiceNo'],
                    'amount'      => formatCurrentNumber($f['transactionTotal'], $dec, $ts),
                    'label'       => $cfg['label'],
                    'type'        => $f['transactionType'],
                    'borderColor' => $stat,
                ];

                $rs->MoveNext();
            }

            $out['footBtn'] = $date
                ? '<a href="#' . $anchor . '&c=' . $encCustomerId . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>'
                : '<a href="#' . $anchor . '&l=' . ($limit + 50) . '&c=' . $encCustomerId . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</span>';
        } else {
            $out['footBtn'] = '<a href="#' . $anchor . '&c=' . $encCustomerId . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
        }

        return $out;
    }
}
