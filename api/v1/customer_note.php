<?php
/**
 * /api/v1/customer_note.php — notas de cliente (API compartida del sistema).
 *
 *   POST { customerId, text }  → crea una nota
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/CustomerNoteService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\CustomerNoteService;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$customerId = trim((string) ($_POST['customerId'] ?? ''));
$text       = (string) ($_POST['text'] ?? '');

if ($customerId === '' || trim($text) === '') {
    apiError('Faltan customerId/text', 422);
}

$res = (new CustomerNoteService(TenantContext::fromAuth($ctx)))->add($companyId, $customerId, $text);
if (empty($res['ok'])) {
    apiError('No se pudo guardar la nota', 500);
}
apiOk($res);
