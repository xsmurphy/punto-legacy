<?php
/**
 * REST canónico (API compartida /api) — Reporte de Pagos y Transacciones (raw).
 *
 *   GET  /v1/reports/transactions?view=detail|cobros|quotes&from=&to=
 *        [&cusId=&src=&singleRow=]
 *   GET  /v1/reports/transactions?id=<uuid>
 *        Detalle completo de una transacción: header, ítems, notas de crédito,
 *        agendamientos, toTransactions y creditPayments (solo type=3).
 *   POST /v1/reports/transactions (action=deletePayment|deleteQuote&id=…)
 *   PUT  /v1/reports/transactions?id=<uuid>
 *        Actualiza header, ítems (itemSold), tags y cascadas de fecha/comisión.
 *        Solo roles != 7. companyId siempre de COMPANY_ID (JWT), nunca del body.
 *
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Reports\TransactionsService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/* ───────── write: eliminar cobro / cotización ───────── */
if ($method === 'POST') {
    if ((int) $ctx['roleId'] === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    if (!in_array($action, ['deletePayment', 'deleteQuote'], true)) {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    if ($action === 'deletePayment') {
        $parentRaw = (string) (validateHttp('parent', 'post') ?: '');
        $parentId  = ($parentRaw !== '' && preg_match($uuidRe, $parentRaw)) ? $parentRaw : null;
        if (!$svc->deletePayment($id, $parentId, (string) COMPANY_ID)) {
            apiError('No se pudo eliminar', 500);
        }
        apiOk(['id' => $id, 'action' => 'deletePayment']);
    }
    // action === 'deleteQuote'
    if (!$svc->deleteQuote($id, (string) COMPANY_ID)) {
        apiError('No se pudo eliminar', 500);
    }
    apiOk(['id' => $id, 'action' => 'deleteQuote']);
}

/* ───────── GET ?id= : detalle completo de una transacción ───────── */
if ($method === 'GET' && isset($_GET['id']) && $_GET['id'] !== '') {
    $txId = (string) $_GET['id'];
    if (!preg_match($uuidRe, $txId)) {
        apiError('id inválido', 422);
    }

    // ncmExecute con forceObj=true devuelve el recordset ADOdb (NO un array) — hay
    // que iterarlo para materializar las filas (patrón de Reports/UsersService).
    $fetchAll = static function ($rs): array {
        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $out[] = $rs->GetRowAssoc();
                $rs->MoveNext();
            }
        }
        return $out;
    };

    // 1. Cargar transacción principal con datos de contactos y outlet
    $tx = ncmExecute(
        "SELECT t.*,
                c.contactName AS customerName,
                u.contactName AS userName,
                r.contactName AS responsibleName,
                o.outletName
         FROM transaction t
         LEFT JOIN contact c ON c.contactId = t.customerId AND c.companyId = ?
         LEFT JOIN contact u ON u.contactId = t.userId     AND u.companyId = ?
         LEFT JOIN contact r ON r.contactId = t.responsibleId AND r.companyId = ?
         LEFT JOIN outlet  o ON o.outletId  = t.outletId   AND o.companyId = ?
         WHERE t.transactionId = ? AND t.companyId = ?
         LIMIT 1",
        [COMPANY_ID, COMPANY_ID, COMPANY_ID, COMPANY_ID, $txId, COMPANY_ID]
    );

    if (!$tx) {
        apiError('Transacción no encontrada', 404);
    }

    $type = (int) $tx['transactionType'];

    // 2. Ítems: itemSold para type 0/3; transactionDetails JSONB para el resto
    $items = [];
    if (in_array($type, [0, 3], true)) {
        $rawItems = ncmExecute(
            "SELECT is2.itemSoldId, is2.itemId, i.itemName, is2.itemSoldUnits,
                    is2.itemSoldTotal, is2.itemSoldTax, is2.userId
             FROM itemSold is2
             LEFT JOIN item i ON i.itemId = is2.itemId AND i.companyId = ?
             WHERE is2.transactionId = ?",
            [COMPANY_ID, $txId],
            false, true
        );
        $items = $fetchAll($rawItems);
    } elseif (!empty($tx['transactionDetails'])) {
        $decoded = json_decode($tx['transactionDetails'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                $items[] = [
                    'itemSoldId'    => $k,
                    'itemId'        => $v['itemId']  ?? '',
                    'itemName'      => $v['name']    ?? '',
                    'itemSoldUnits' => $v['count']   ?? 1,
                    'itemSoldTotal' => $v['total']   ?? 0,
                    'itemSoldTax'   => 0,
                    'userId'        => $v['userId']  ?? '',
                ];
            }
        }
    }

    // 3. Notas de crédito (type=6 hijas de esta transacción)
    $creditNotes = ncmExecute(
        "SELECT transactionId, transactionDate, transactionTotal, invoiceNo
         FROM transaction
         WHERE transactionParentId = ? AND transactionType = 6 AND companyId = ?",
        [$txId, COMPANY_ID],
        false, true
    );

    // 4. Agendamientos (type=13 hijos de esta transacción)
    $appointments = ncmExecute(
        "SELECT transactionId, transactionDate, transactionTotal
         FROM transaction
         WHERE transactionParentId = ? AND transactionType = 13 AND companyId = ?",
        [$txId, COMPANY_ID],
        false, true
    );

    // 5. Transacciones asociadas (toTransaction). toTransaction NO tiene companyId
    // propio → scope por JOIN a transaction para no exponer vínculos de otra empresa.
    $toTx = ncmExecute(
        "SELECT tt.* FROM toTransaction tt
           JOIN transaction t ON t.transactionId = tt.transactionId AND t.companyId = ?
          WHERE tt.transactionId = ?",
        [COMPANY_ID, $txId],
        false, true
    );

    // 6. Para crédito (type=3): calcular total/pagado/deuda
    $creditPayments = null;
    if ($type === 3) {
        $total   = (float) $tx['transactionTotal'] - (float) $tx['transactionDiscount'];
        $paidRow = ncmExecute(
            "SELECT COALESCE(SUM(transactionTotal), 0) AS paid
             FROM transaction
             WHERE transactionParentId = ? AND transactionType = 5 AND companyId = ?",
            [$txId, COMPANY_ID]
        );
        $paid = (float) ($paidRow['paid'] ?? 0);
        $creditPayments = [
            'total' => $total,
            'paid'  => $paid,
            'debt'  => max(0, $total - $paid),
        ];
    }

    // Construir respuesta: convertir a array plano para json_encode (CaseInsensitiveArray
    // implementa ArrayAccess pero no JsonSerializable → json_encode daría {}).
    $txData = $tx instanceof \CaseInsensitiveArray ? $tx->toArray() : (array) $tx;
    $rawPayments = json_decode($tx['transactionPaymentType'] ?? '[]', true) ?? [];
    // Resolver nombre legible de cada método de pago (igual que TransactionsService::paymentsFromJson)
    $txData['transactionPaymentType'] = array_map(function ($p) {
        return [
            'type'  => (string) ($p['type']  ?? ''),
            'name'  => getPaymentMethodName($p['type'] ?? ''),
            'total' => (float) ($p['total'] ?? 0),
            'price' => (float) ($p['price'] ?? 0),
            'extra' => (string) ($p['extra'] ?? ''),
        ];
    }, is_array($rawPayments) ? $rawPayments : []);
    $txData['meta']                   = json_decode($tx['meta'] ?? '{}', true) ?? [];
    unset($txData['transactionDetails']);

    apiOk([
        'transaction'    => $txData,
        'items'          => $items,
        'creditNotes'    => $fetchAll($creditNotes),
        'appointments'   => $fetchAll($appointments),
        'toTransactions' => $fetchAll($toTx),
        'creditPayments' => $creditPayments,
    ]);
}

/* ───────── PUT ?id= : actualizar transacción (paridad legacy) ───────── */
if ($method === 'PUT' && isset($_GET['id']) && $_GET['id'] !== '') {
    if ((int) $ctx['roleId'] === 7) {
        apiError('Sin permiso para esta acción', 403);
    }

    $txId = (string) $_GET['id'];
    if (!preg_match($uuidRe, $txId)) {
        apiError('id inválido', 422);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // Verificar que la transacción pertenece al tenant del JWT
    $existing = ncmExecute(
        "SELECT transactionId, transactionType FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1",
        [$txId, COMPANY_ID]
    );
    if (!$existing) {
        apiError('Transacción no encontrada', 404);
    }

    // Gate de editabilidad enforzado en el BACKEND (no se confía en el front):
    // solo contado (0) y crédito (3) son editables. El tipo se lee de la BD, no
    // del body — así un PUT a un documento read-only (2/6/7/9/10/12/13) es rechazado.
    if (!in_array((int) $existing['transactionType'], [0, 3], true)) {
        apiError('Este tipo de documento no es editable', 422);
    }

    global $db;
    $record = [];

    // Validador de fecha reutilizable (YYYY-MM-DD [HH:MM:SS]).
    $isValidDate = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v) === 1;

    // Campos del header
    if (isset($body['date'])) {
        if (!$isValidDate($body['date'])) apiError('Fecha inválida', 422);
        $record['transactionDate'] = $body['date'];
    }
    if (isset($body['dueDate'])) {
        if ($body['dueDate'] !== '' && !$isValidDate($body['dueDate'])) apiError('Vencimiento inválido', 422);
        $record['transactionDueDate'] = $body['dueDate'];
    }
    if (isset($body['note'])) {
        $record['transactionNote'] = $body['note'];
    }
    if (isset($body['customerId']) && preg_match($uuidRe, (string) $body['customerId'])) {
        $record['customerId'] = $body['customerId'];
    }
    if (isset($body['userId']) && preg_match($uuidRe, (string) $body['userId'])) {
        $record['userId'] = $body['userId'];
    }
    if (isset($body['responsibleId']) && preg_match($uuidRe, (string) $body['responsibleId'])) {
        $record['responsibleId'] = $body['responsibleId'];
    }
    if (isset($body['outletId']) && preg_match($uuidRe, (string) $body['outletId'])) {
        $record['outletId'] = $body['outletId'];
    }
    if (isset($body['invoiceNo'])) {
        $record['invoiceNo'] = (string) $body['invoiceNo'];
    }

    // Tipo de transacción (solo 0 o 3 son editables)
    if (isset($body['transactionType'])) {
        $newType = (int) $body['transactionType'];
        if (in_array($newType, [0, 3], true)) {
            $record['transactionType'] = $newType;
            if ($newType === 3) {
                $record['transactionComplete'] = 0;
            }
        }
    }

    // Métodos de pago
    if (isset($body['payments']) && is_array($body['payments'])) {
        $pmethod = [];
        foreach ($body['payments'] as $p) {
            $pamount = (float) ($p['total'] ?? 0);
            $ptype   = (string) ($p['type']  ?? '');
            if ($ptype !== '' && $pamount > 0) {
                $pmethod[] = ['type' => $ptype, 'total' => $pamount, 'price' => $pamount, 'extra' => $ptype];
            }
        }
        $record['transactionPaymentType'] = json_encode($pmethod);
    }

    // UPDATE principal (solo si hay campos a actualizar). WHERE parametrizado
    // (companyId del JWT, nunca del body) — sin concatenación de strings.
    if ($record) {
        $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ? AND companyId = ?', [$txId, COMPANY_ID]);
    }

    // Tags — jsonb_set separado para no pisar otros campos de meta
    if (isset($body['tags']) && is_array($body['tags'])) {
        $tagsJson = json_encode($body['tags']);
        $db->Execute(
            "UPDATE transaction SET meta = jsonb_set(COALESCE(meta,'{}'), '{tags}', ?::jsonb) WHERE transactionId = ? AND companyId = ?",
            [$tagsJson, $txId, COMPANY_ID]
        );
    }

    // Ítems de itemSold (solo para type 0/3)
    if (isset($body['items']) && is_array($body['items'])) {
        foreach ($body['items'] as $itm) {
            $itemSoldId = (string) ($itm['itemSoldId'] ?? '');
            if (!preg_match($uuidRe, $itemSoldId)) {
                continue;
            }
            $irecord = [];
            if (isset($itm['itemSoldUnits'])) {
                $units = (float) $itm['itemSoldUnits'];
                if ($units <= 0) continue; // cantidad inválida → no tocar la línea
                $irecord['itemSoldUnits'] = $units;
            }
            if (isset($itm['itemSoldTotal'])) {
                $irecord['itemSoldTotal'] = (float) $itm['itemSoldTotal'];
            }
            if (isset($itm['userId']) && preg_match($uuidRe, (string) $itm['userId'])) {
                $irecord['userId'] = $itm['userId'];
            }
            if ($irecord) {
                // Scope por transactionId (ya verificado dueño del tenant en línea ~196).
                // WHERE parametrizado, sin concatenación.
                $db->AutoExecute('itemSold', $irecord, 'UPDATE',
                    'itemSoldId = ? AND transactionId = ?', [$itemSoldId, $txId]);
            }
        }
    }

    // CASCADAS del legacy cuando cambia la fecha. itemSold/comission no tienen
    // companyId propio → guard por subquery a transaction (defensa en profundidad;
    // $txId ya fue verificado dueño del tenant arriba).
    if (isset($body['date'])) {
        $db->Execute(
            "UPDATE itemSold SET itemSoldDate = ?
              WHERE transactionId = ?
                AND transactionId IN (SELECT transactionId FROM transaction WHERE companyId = ?)",
            [$body['date'], $txId, COMPANY_ID]
        );
        $db->Execute(
            "UPDATE comission SET comissionDate = ?
              WHERE transactionId = ?
                AND transactionId IN (SELECT transactionId FROM transaction WHERE companyId = ?)",
            [$body['date'], $txId, COMPANY_ID]
        );
    }

    // Auto-completar crédito si la deuda llegó a 0
    $typeCheck = (int) ($record['transactionType'] ?? $existing['transactionType']);
    if ($typeCheck === 3) {
        $totalRow = ncmExecute(
            "SELECT transactionTotal, transactionDiscount FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1",
            [$txId, COMPANY_ID]
        );
        if ($totalRow) {
            $netTotal = (float) $totalRow['transactionTotal'] - (float) $totalRow['transactionDiscount'];
            $paidRow  = ncmExecute(
                "SELECT COALESCE(SUM(transactionTotal), 0) AS paid FROM transaction WHERE transactionParentId = ? AND transactionType = 5 AND companyId = ?",
                [$txId, COMPANY_ID]
            );
            $paid = (float) ($paidRow['paid'] ?? 0);
            // round() para evitar falsos completos por error de punto flotante IEEE 754.
            if ($netTotal > 0 && round($paid, 4) >= round($netTotal, 4)) {
                $db->Execute(
                    "UPDATE transaction SET transactionComplete = 1 WHERE transactionId = ? AND companyId = ?",
                    [$txId, COMPANY_ID]
                );
            }
        }
    }

    // Rollup: marcar el día de la transacción editada como dirty (best-effort).
    try {
        $txDateForDirty = $record['transactionDate'] ?? null;
        if ($txDateForDirty === null) {
            $txRow = ncmExecute('SELECT transactionDate FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$txId, COMPANY_ID]);
            $txDateForDirty = $txRow['transactionDate'] ?? null;
        }
        if ($txDateForDirty !== null) {
            \rollupMarkDirty((string) COMPANY_ID, ['sales'], substr((string)$txDateForDirty, 0, 10));
        }
    } catch (\Throwable $e) {
        error_log('[transactions.put] rollupMarkDirty: ' . $e->getMessage());
    }

    apiOk(['updated' => true, 'id' => $txId]);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

$view = (string) (validateHttp('view') ?: 'detail');
if (!in_array($view, ['detail', 'cobros', 'quotes'], true)) {
    apiError('Vista no soportada', 422);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

$uuidOrEmpty = function ($v) use ($uuidRe) {
    $v = (string) ($v ?: '');
    return ($v !== '' && preg_match($uuidRe, $v)) ? $v : '';
};

$filters = [
    'cusId'     => $uuidOrEmpty(validateHttp('cusId')),
    'singleRow' => $uuidOrEmpty(validateHttp('singleRow')),
    'src'       => trim((string) (validateHttp('src') ?: '')),
];

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'cobros') {
    apiOk($svc->cobros($filters, $from, $to, $roc, $companyId));
} elseif ($view === 'quotes') {
    apiOk($svc->quotes($filters, $from, $to, $roc, $companyId));
} else {
    apiOk($svc->detail($filters, $from, $to, $roc, $companyId));
}
