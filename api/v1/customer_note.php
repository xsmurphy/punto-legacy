<?php
/**
 * /api/v1/customer_note.php — notas de cliente (API compartida del sistema).
 *
 *   POST op=add { customerId, text }
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/CustomerNoteService.php';

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$op         = (string) ($_POST['op'] ?? '');
$customerId = trim((string) ($_POST['customerId'] ?? ''));
$text       = (string) ($_POST['text'] ?? '');

if ($op !== 'add') {
    apiError('Operación no soportada', 400);
}
if ($customerId === '' || trim($text) === '') {
    apiError('Faltan customerId/text', 422);
}

$res = (new CustomerNoteService())->add($companyId, $customerId, $text);
if (empty($res['ok'])) {
    apiError('No se pudo guardar la nota', 500);
}
apiOk($res);
