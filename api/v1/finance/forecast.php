<?php
/**
 * GET /v1/finance/forecast?from=&to= — Previsión (F3, context/30-cheques-
 * prevision-creditos.md). Unión de obligaciones futuras + ingresos futuros
 * conocidos. Solo lectura — las acciones (cambiar estado de un cheque,
 * marcar cuota pagada) viven en cada módulo origen (/finanzas/cheques,
 * /finanzas/creditos).
 *
 * `obligations` (egresos futuros):
 *   - Cheques EMITIDOS pending|deposited, por duedate.
 *   - Cuotas pending de fin_loan, por duedate.
 *   - Compras (transactionType=1, no anuladas) con transactionDueDate en
 *     rango — v1 no modela pago parcial de compras, así que se listan todas
 *     las que tienen vencimiento cargado (ver context/30 "fuera de alcance").
 *
 * `income` (ingresos futuros conocidos, separado — no se suma a obligations):
 *   - Cheques RECIBIDOS pending|deposited, por duedate.
 *
 * Default de rango: hoy → hoy+30 días.
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$companyId = (string) COMPANY_ID;
$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d', strtotime('+30 days'));
}

// Cheques emitidos + cuotas de crédito + compras con vencimiento: query
// compartido con el feed de notificaciones (context/31) — ver
// Punto\Api\Finance\ObligationsService. `$from` concreto acá preserva el
// comportamiento previo (rango acotado), sin cambios en la respuesta.
$obligations = (new \Punto\Api\Finance\ObligationsService())->list($companyId, $from, $to);
usort($obligations, static fn (array $a, array $b): int => $a['dueDate'] <=> $b['dueDate']);

$income = [];

// Label legible de un cheque recibido: "Banco #nro" con fallback si no hay ninguno cargado.
$checkLabel = static function (array|\CaseInsensitiveArray $f, string $fallback): string {
    $parts = [];
    if (!empty($f['bankname'])) {
        $parts[] = (string) $f['bankname'];
    }
    if (!empty($f['checknumber'])) {
        $parts[] = '#' . (string) $f['checknumber'];
    }
    return $parts !== [] ? implode(' ', $parts) : $fallback;
};

// ── Cheques recibidos pendientes de depositar/cobrar (ingreso, separado) ─
$rs = ncmExecute(
    "SELECT checkid, duedate, amount, partyname, bankname, checknumber
       FROM fin_check
      WHERE companyid = ? AND direction = 'received' AND status IN ('pending', 'deposited')
        AND duedate IS NOT NULL AND duedate::date BETWEEN ? AND ?
      ORDER BY duedate ASC",
    [$companyId, $from, $to],
    false,
    true
);
if ($rs && is_object($rs)) {
    while (!$rs->EOF) {
        $f = $rs->fields;
        $income[] = [
            'id'      => (string) $f['checkid'],
            'type'    => 'check',
            'label'   => $checkLabel($f, 'Cheque recibido'),
            'party'   => $f['partyname'] !== null ? (string) $f['partyname'] : null,
            'dueDate' => (string) $f['duedate'],
            'amount'  => (float) $f['amount'],
            'link'    => '/finanzas/cheques',
        ];
        $rs->MoveNext();
    }
    $rs->Close();
}

apiOk([
    'from'        => $from,
    'to'          => $to,
    'obligations' => $obligations,
    'income'      => $income,
]);
