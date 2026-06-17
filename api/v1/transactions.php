<?php
/**
 * /api/v1/transactions.php — operaciones sobre transacciones/órdenes (Slice 6).
 *
 *   DELETE ?id=<txId>                      → elimina la transacción
 *   DELETE ?id=<txId>&resource=printjob    → elimina de la cola de impresión
 *   PUT    ?id=<txId>&resource=reject { motive } → rechaza orden (status 6) + WS
 *   PUT    ?id=<txId>&resource=void { motive }   → anula transacción (type→7) + auditoría
 *   POST   ?resource=itemDeletion { itemId, motive } → inserta en itemDeleted
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 * Side-effects (sendWS, updateLastTimeEdit) van aquí, no en el Service.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/TransactionService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\TransactionService;

$ctx        = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$userId     = $ctx['userId'];
$registerId = $ctx['registerId'];

global $db;
$svc      = new TransactionService(TenantContext::fromAuth($ctx), $db);
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

// --- GET ?resource=single: obtener una transacción por ID -----------------
if ($method === 'GET' && $resource === 'single') {
    $transactionId = trim((string) dec($_GET['id'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta id', 422);
    }
    $data = $svc->getSingle($transactionId, $companyId);
    if ($data === null) {
        apiError('Transacción no encontrada', 404);
    }
    apiOk($data);
}

// --- GET ?resource=mainList: lista principal de transacciones (Slice 29)
if ($method === 'GET' && $resource === 'mainList') {
    $encCid = trim((string) ($_GET['customerId'] ?? '')) ?: null;
    $date   = trim((string) ($_GET['date'] ?? '')) ?: null;
    $limit  = max(1, (int) ($_GET['limit'] ?? 30));
    apiOk($svc->getMainList($outletId, $companyId, $ctx['userId'], $ctx['roleId'], $encCid, $date, $limit));
}

// --- GET ?resource=list: lista paginada de cotizaciones/guardados (Slice 28)
if ($method === 'GET' && $resource === 'list') {
    $listType  = trim((string) ($_GET['listType'] ?? ''));
    if (!in_array($listType, ['quotes', 'saved'], true)) {
        apiError('listType debe ser quotes o saved', 422);
    }
    $encCid = trim((string) ($_GET['customerId'] ?? '')) ?: null;
    $date   = trim((string) ($_GET['date'] ?? '')) ?: null;
    $limit  = max(1, (int) ($_GET['limit'] ?? 30));
    apiOk($svc->getTransactionList($listType, $outletId, $companyId, $encCid, $date, $limit));
}

// --- DELETE: eliminar transacción o job de impresión ----------------------
if ($method === 'DELETE') {
    $transactionId = trim((string) ($_GET['id'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta id', 422);
    }
    if ($resource === 'printjob') {
        if (!$svc->deletePrintJob($transactionId, $companyId)) {
            apiError('No se pudo eliminar el job de impresión', 500);
        }
        apiOk(['deleted' => true]);
    }
    if (!$svc->delete($transactionId, $companyId)) {
        apiError('No se pudo eliminar la transacción', 500);
    }
    apiOk(['deleted' => true]);
}

// --- PUT ?resource=reject: rechazar orden (transición de estado) -----------
if ($method === 'PUT' && $resource === 'reject') {
    $transactionId = trim((string) ($_GET['id'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta id', 422);
    }
    $motive = ($_POST['motive'] ?? '') ?: null;
    if (!$svc->reject($transactionId, $companyId, $motive)) {
        apiError('No se pudo rechazar la orden', 500);
    }

    // Side-effects best-effort: fallar aquí no revierte el UPDATE.
    try {
        updateLastTimeEdit($companyId, 'order');
        sendWS([
            'channel' => $outletId . '-register',
            'event'   => 'order',
            'message' => json_encode(['ID' => $transactionId, 'registerID' => $registerId]),
        ]);
    } catch (\Throwable $e) {
        error_log('[transactions.reject] side-effect falló (ignorado): ' . $e->getMessage());
    }

    apiOk(['rejected' => true]);
}

// --- PUT ?resource=status: cambiar estado (slice 36b — strangler de action.php?sale&status=) ---
if ($method === 'PUT' && $resource === 'status') {
    $transactionId = trim((string) dec($_GET['id'] ?? ''));
    $newStatus     = (string) ($_POST['status'] ?? '');
    if ($transactionId === '' || $newStatus === '') {
        apiError('Falta id o status', 422);
    }
    $motive      = $_POST['motive'] ?? null;
    $isSchedule  = !empty($_POST['schedule']);
    $scheduleUid = isset($_POST['uid']) && $_POST['uid'] !== '' ? (string) $_POST['uid'] : null;

    $result = $svc->changeStatus(
        $transactionId, $newStatus, $companyId, $outletId,
        $motive, $isSchedule, $scheduleUid
    );
    if (!$result['ok']) {
        apiError('No se pudo cambiar el estado', 500);
    }

    // Side-effects best-effort. Fallar acá no revierte el UPDATE.
    $finalStatus = $result['finalStatus'];

    try {
        if ($finalStatus !== 'reminder') {
            updateLastTimeEdit($companyId, 'calendar');
            updateLastTimeEdit($companyId, 'order');
            sendWS([
                'channel' => enc($outletId) . '-KDS',
                'event'   => 'order',
                'message' => enc($transactionId),
            ]);
            sendWS([
                'channel' => enc($outletId) . '-register',
                'event'   => 'order',
                'message' => json_encode(['ID' => enc($transactionId), 'registerID' => enc($registerId)]),
            ]);
        }
    } catch (\Throwable $e) {
        error_log('[transactions.status] WS falló (ignorado): ' . $e->getMessage());
    }

    // Notificaciones al cliente (push / email / SMS) — solo si schedule + status final en [1,4,5,reminder]
    if ($result['notifyNeeded'] && $result['customerId']) {
        try {
            $compName    = defined('COMPANY_NAME') ? COMPANY_NAME : '';
            $compLogo    = defined('COMPANY_LOGO') ? COMPANY_LOGO : '';
            $contactData = getCustomerData($result['customerId'], 'uid');
            $contactName = getCustomerName($contactData, 'first');
            $contactMail = $contactData['email']  ?? '';
            $contactPhn  = $contactData['phone']  ?? ($contactData['phone2'] ?? '');
            $niceDate    = niceDate($result['transactionDate'], true, false, false, true);

            $subject = ''; $body = ''; $smsBody = ''; $notify = false;

            if ($finalStatus === '5') {
                $subject = '[' . $compName . '] Agendamiento';
                $body    = 'Hola ' . iftn($contactName, '!') . ',' .
                    '<p>Lamentamos que no haya podido asistir.</p>' .
                    '<p>Si desea volver a re agendar, por favor no dude en contactarnos ' . addWhatsAppLink() . '</p>';
                $smsBody = '[' . $compName . '] ' . $contactName . ', lamentamos que no haya podido asistir, ante cualquier duda por favor pongase en contacto.' . addWhatsAppLink(true);
                $notify  = true;
            } elseif ($finalStatus === '4') {
                sendPush([
                    'ids'     => enc($companyId),
                    'message' => 'Se canceló su cita con ' . $contactName . ' el ' . $niceDate,
                    'title'   => $compName,
                    'where'   => 'caja',
                    'filters' => [
                        ['key' => 'userId',    'value' => enc($result['userId'] ?? '')],
                        ['key' => 'companyId', 'value' => enc($companyId)],
                    ],
                ]);
            } elseif ($finalStatus === '1') {
                sendPush([
                    'ids'     => enc($companyId),
                    'message' => 'Se confirmó su cita con ' . $contactName . ' el ' . $niceDate,
                    'title'   => $compName,
                    'where'   => 'caja',
                    'filters' => [
                        ['key' => 'userId',    'value' => enc($result['userId'] ?? '')],
                        ['key' => 'companyId', 'value' => enc($companyId)],
                    ],
                ]);
            } elseif ($finalStatus === 'reminder') {
                $url     = getShortURL('/screens/scheduleConfirm?s=' . base64_encode(enc($transactionId) . ',' . enc($companyId)));
                $subject = '[' . $compName . '] Recordatorio';
                $body    = 'Hola ' . $contactName . ',' .
                    '<p>Le contactamos para recordarle su cita, acceda a más detalles en</p>' .
                    makeEmailActionBtn($url, 'Detalles');
                $smsBody = '[' . $compName . '] Hola ' . $contactName . ', le recordamos su agendamiento. Detalles en: \n' . $url;
                $notify  = true;
            }

            if ($notify) {
                sendEmails([
                    'subject'  => $subject,
                    'to'       => $contactMail,
                    'fromName' => $compName,
                    'data'     => [
                        'message'     => $body,
                        'companyname' => $compName,
                        'companylogo' => $compLogo,
                    ],
                ]);
                sendSMS($contactPhn, $smsBody, true, true);
            }
        } catch (\Throwable $e) {
            error_log('[transactions.status] notificaciones fallaron (ignorado): ' . $e->getMessage());
        }
    }

    apiOk(['updated' => true, 'status' => $finalStatus]);
}

// --- PUT ?resource=note: actualizar nota de transacción ------------------
if ($method === 'PUT' && $resource === 'note') {
    $transactionId = trim((string) ($_GET['id'] ?? ''));
    $note          = (string) ($_POST['note'] ?? '');
    if ($transactionId === '' || $note === '') {
        apiError('Falta id o note', 422);
    }
    if (!$svc->setNote($transactionId, $companyId, $note)) {
        apiError('No se pudo actualizar la nota', 500);
    }
    apiOk(['updated' => true]);
}

// --- PUT ?resource=void: anular transacción (type→7) ----------------------
if ($method === 'PUT' && $resource === 'void') {
    $transactionId = trim((string) ($_GET['id'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta id', 422);
    }
    $motive = (string) ($_POST['motive'] ?? '');

    if (!$svc->voidTransaction($transactionId, $companyId, $outletId, $userId, $motive)) {
        apiError('No se pudo anular la transacción', 500);
    }

    // Side-effects post-void (best-effort — la anulación ya está committeada).
    try {
        updateLastTimeEdit($companyId, 'item');

        // Auditoría — FIX: usa COMPANY_NAME (constante cargada por data.php tras
        // apiAuthTenant); el legacy llamaba getValue('setting','settingName',...) pero
        // la tabla `setting` no existe en PG (settings viven en company.config JSONB).
        $userName     = getValue('contact', 'contactName', "WHERE contactId = '$userId'");
        $registerName = getValue('register', 'registerName', "WHERE registerId = '$registerId'");
        $companyName  = defined('COMPANY_NAME') ? COMPANY_NAME : '';
        $outletName   = getCurrentOutletName($outletId);

        $transaction = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1', [$transactionId]);

        sendAuditoria([
            'date'       => TODAY,
            'user'       => $userName,
            'module'     => 'FACTURACION',
            'origin'     => 'CAJA',
            'company_id' => $companyId,
            'data'       => [
                'action'        => "El usuario {$userName} anuló una factura desde la caja {$registerName}",
                'userId'        => $userId,
                'userName'      => $userName,
                'operationData' => $transaction,
                'registerId'    => $registerId,
                'registerName'  => $registerName,
                'companyID'     => $companyId,
                'companyName'   => $companyName,
                'outletId'      => $outletId,
                'outletName'    => $outletName,
                'timestamp'     => $transaction['timestamp'] ?? null,
            ],
        ], defined('AUDITORIA_TOKEN') ? AUDITORIA_TOKEN : '');
    } catch (\Throwable $e) {
        error_log('[transactions.void] side-effect falló (ignorado): ' . $e->getMessage());
    }

    apiOk(['voided' => true]);
}

// --- POST ?resource=itemDeletion: registrar borrado de ítem ---------------
if ($method === 'POST' && $resource === 'itemDeletion') {
    $itemId = trim((string) ($_POST['itemId'] ?? ''));
    if ($itemId === '') {
        apiError('Falta itemId', 422);
    }
    $motive = (string) ($_POST['motive'] ?? '');
    if (!$svc->recordItemDeletion($itemId, $motive, $userId, $companyId, $outletId)) {
        apiError('No se pudo registrar la eliminación', 500);
    }
    apiOk(['recorded' => true]);
}

apiError('Operación no reconocida', 400);
