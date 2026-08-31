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
 * Auth: lectura panel + pos-app; escritura solo panel. Tenant por COMPANY_ID +
 * outlet. Para pos-app el OUTLET_ID sale de la fila `device` (bootstrap.php),
 * así el listado de transacciones del POS queda scopeado a la sucursal de la
 * caja — con panel-only, el POS pedía con la cookie del operador y mostraba la
 * sucursal del view-scope del panel (bug 2026-07-30).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx    = apiAuthTenant(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? ['panel', 'pos-app', 'api'] : ['panel']);
$svc    = new \Punto\Api\Reports\TransactionsService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/* ───────── write: eliminar cobro / cotización ───────── */
if ($method === 'POST') {
    if (!hasPermission('reports.sales.view')) {
        apiError('No tenés permiso para esta acción (requiere: reports.sales.view)', 403);
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

/* ───────── GET ?id= : detalle completo de una transacción ─────────
 * F1 (context/39-detalle-transaccion.md): resolver canónico único,
 * Transactions\TransactionDetailService::find() — espejo de
 * Purchases\PurchasesService::find(). La query inline que vivía acá
 * (header + items + creditNotes/appointments, sin timbrado/caja/comisión/
 * desglose fiscal/cotizaciones/órdenes) murió; ver el Service para el detalle
 * de qué resuelve y por qué. El POS (TransactionService::getSingle) sigue
 * con su propio shape — migrarlo es F4 del plan. */
if ($method === 'GET' && isset($_GET['id']) && $_GET['id'] !== '') {
    $txId = (string) $_GET['id'];
    if (!preg_match($uuidRe, $txId)) {
        apiError('id inválido', 422);
    }

    $detail = (new \Punto\Api\Transactions\TransactionDetailService())->find($txId, (string) COMPANY_ID);
    if ($detail === null) {
        apiError('Transacción no encontrada', 404);
    }

    apiOk($detail);
}

/* ───────── PUT ?id= : actualizar transacción (paridad legacy) ───────── */
if ($method === 'PUT' && isset($_GET['id']) && $_GET['id'] !== '') {
    if (!hasPermission('reports.sales.view')) {
        apiError('No tenés permiso para esta acción (requiere: reports.sales.view)', 403);
    }

    $txId = (string) $_GET['id'];
    if (!preg_match($uuidRe, $txId)) {
        apiError('id inválido', 422);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // Verificar que la transacción pertenece al tenant del JWT
    $existing = ncmExecute(
        "SELECT transactionId, transactionType, transactionDate, transactionDiscount,
                transactionTotal, customerId, outletId, invoiceNo, transactionPaymentType
           FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1",
        [$txId, COMPANY_ID]
    );
    if (!$existing) {
        apiError('Transacción no encontrada', 404);
    }

    // Gate de editabilidad enforzado en el BACKEND (no se confía en el front):
    // contado (0), crédito (3) y cotización (9) son editables. El tipo se lee de
    // la BD, no del body — así un PUT a un documento read-only (2/6/7/10/12/13)
    // es rechazado.
    $editableTypes = [0, 3, 9];
    if (!in_array((int) $existing['transactionType'], $editableTypes, true)) {
        apiError('Este tipo de documento no es editable', 422);
    }
    $isQuote = (int) $existing['transactionType'] === 9;

    // ── Inmutabilidad del documento fiscal ──────────────────────────────────
    // Una factura emitida (contado 0 / crédito 3) ya salió con un timbrado, un
    // número y un contenido declarado. Nada de eso puede cambiar después:
    // corregir una factura emitida es emitir una nota de crédito, no editarla.
    // Editable queda solo lo que NO está en el documento: quién la hizo, el
    // usuario por línea, notas y etiquetas.
    //
    // La cotización (9) no es documento fiscal — sigue siendo editable entera.
    //
    // Se bloquea por CAMBIO REAL, no por presencia del campo: el formulario
    // manda el body completo en cada guardado, así que rechazar por venir
    // presente haría fallar hasta un cambio de nota. Se compara contra lo
    // guardado y solo se corta si el valor es distinto.
    $esFiscal = !$isQuote;

    /** Compara laxo: el body manda números como string y null como ''. */
    $cambia = static function (mixed $nuevo, mixed $actual, bool $numerico = false): bool {
        if ($nuevo === null || $nuevo === '') {
            return $actual !== null && (string) $actual !== '';
        }
        if ($numerico) {
            return abs((float) $nuevo - (float) $actual) > 0.0001;
        }
        return (string) $nuevo !== (string) $actual;
    };

    if ($esFiscal) {
        $bloqueados = [];

        if (isset($body['date']) && $cambia(substr((string) $body['date'], 0, 10), substr((string) $existing['transactionDate'], 0, 10))) {
            $bloqueados[] = 'la fecha';
        }
        if (array_key_exists('customerId', $body) && $cambia($body['customerId'], $existing['customerId'])) {
            $bloqueados[] = 'el cliente';
        }
        if (array_key_exists('outletId', $body) && $cambia($body['outletId'], $existing['outletId'])) {
            $bloqueados[] = 'la sucursal';
        }
        if (array_key_exists('invoiceNo', $body) && $cambia($body['invoiceNo'], $existing['invoiceNo'], true)) {
            $bloqueados[] = 'el número de factura';
        }
        if (isset($body['transactionType']) && (int) $body['transactionType'] !== (int) $existing['transactionType']) {
            $bloqueados[] = 'el tipo (contado/crédito)';
        }

        // Formas de pago: van impresas en el documento.
        if (isset($body['payments']) && is_array($body['payments'])) {
            $normalizar = static function (array $lista): string {
                $out = [];
                foreach ($lista as $p) {
                    $tipo  = (string) ($p['type'] ?? '');
                    $monto = round((float) ($p['total'] ?? 0), 4);
                    if ($tipo !== '' && $monto > 0) {
                        $out[] = $tipo . ':' . $monto;
                    }
                }
                sort($out);
                return implode('|', $out);
            };
            $actuales = json_decode((string) ($existing['transactionPaymentType'] ?? '[]'), true);
            if ($normalizar($body['payments']) !== $normalizar(is_array($actuales) ? $actuales : [])) {
                $bloqueados[] = 'las formas de pago';
            }
        }

        // Líneas: cantidad e importe. El usuario por línea sí se puede corregir.
        if (isset($body['items']) && is_array($body['items']) && $body['items'] !== []) {
            $ids = [];
            foreach ($body['items'] as $itm) {
                $sid = (string) ($itm['itemSoldId'] ?? '');
                if (preg_match($uuidRe, $sid)) {
                    $ids[$sid] = $itm;
                }
            }
            if ($ids !== []) {
                $ph  = implode(',', array_fill(0, count($ids), '?'));
                $rsL = ncmExecute(
                    "SELECT itemSoldId, itemSoldUnits, itemSoldTotal FROM itemSold
                      WHERE itemSoldId IN ($ph) AND transactionId = ?",
                    array_merge(array_keys($ids), [$txId]),
                    false,
                    true
                );
                if ($rsL !== false && is_object($rsL)) {
                    while (!$rsL->EOF) {
                        $f   = $rsL->fields;
                        $sid = (string) ($f['itemsoldid'] ?? $f['itemSoldId'] ?? '');
                        $itm = $ids[$sid] ?? null;
                        if ($itm !== null) {
                            if (isset($itm['itemSoldUnits']) && $cambia($itm['itemSoldUnits'], $f['itemsoldunits'] ?? $f['itemSoldUnits'] ?? 0, true)) {
                                $bloqueados[] = 'las cantidades';
                            }
                            if (isset($itm['itemSoldTotal']) && $cambia($itm['itemSoldTotal'], $f['itemsoldtotal'] ?? $f['itemSoldTotal'] ?? 0, true)) {
                                $bloqueados[] = 'los importes';
                            }
                        }
                        $rsL->MoveNext();
                    }
                    $rsL->Close();
                }
            }
        }

        $bloqueados = array_values(array_unique($bloqueados));
        if ($bloqueados !== []) {
            apiError(
                'No se puede modificar ' . implode(', ', $bloqueados) . ' de una factura ya emitida. '
                . 'Para corregirla, emití una nota de crédito. '
                . 'Sí podés editar el usuario, el usuario por ítem, las notas y las etiquetas.',
                422
            );
        }
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

    // Tipo de transacción (solo 0 o 3 son editables destino; una cotización no
    // puede "convertirse" de tipo vía este PUT — eso es un flujo aparte).
    if (!$isQuote && isset($body['transactionType'])) {
        $newType = (int) $body['transactionType'];
        if (in_array($newType, [0, 3], true)) {
            $record['transactionType'] = $newType;
            if ($newType === 3) {
                $record['transactionComplete'] = 0;
            }
        }
    }

    // Métodos de pago — no aplican a cotizaciones (no afectan caja/crédito).
    if (!$isQuote && isset($body['payments']) && is_array($body['payments'])) {
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

    // Ítems de itemSold (para type 0/3/9). Para cotización (9) esto NO dispara
    // movimiento de stock ni comisión — itemSold es solo el detalle de líneas;
    // manageStock/comisión se generan en otro flujo (venta), nunca desde este PUT.
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
        // Cotización (9) no genera comisión — guard defensivo, no tocar esa tabla.
        if (!$isQuote) {
            $db->Execute(
                "UPDATE comission SET comissionDate = ?
                  WHERE transactionId = ?
                    AND transactionId IN (SELECT transactionId FROM transaction WHERE companyId = ?)",
                [$body['date'], $txId, COMPANY_ID]
            );
        }
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
            // kind='credit_payment' (mig 115) — reemplaza transactionParentId.
            $paid = 0.0;
            $creditPaymentIds2 = (new \Punto\Api\Services\TransactionLinkService())->listDerivedIds(COMPANY_ID, $txId, 'credit_payment');
            if ($creditPaymentIds2 !== []) {
                $cp2Ph   = implode(',', array_fill(0, count($creditPaymentIds2), '?'));
                $paidRow = ncmExecute(
                    "SELECT COALESCE(SUM(transactionTotal), 0) AS paid FROM transaction WHERE transactionId IN ($cp2Ph) AND transactionType = 5 AND companyId = ?",
                    array_merge($creditPaymentIds2, [COMPANY_ID])
                );
                $paid = (float) ($paidRow['paid'] ?? 0);
            }
            // round() para evitar falsos completos por error de punto flotante IEEE 754.
            if ($netTotal > 0 && round($paid, 4) >= round($netTotal, 4)) {
                $db->Execute(
                    "UPDATE transaction SET transactionComplete = TRUE WHERE transactionId = ? AND companyId = ?",
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
