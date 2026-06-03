<?php
/**
 * /api/v1/pix.php — QR Pix y verificación de transacción (Slice 43, strangler de
 *                    load=pixQR y load=verifyTransactionPix).
 *
 *   POST { type:'create', QRAmount, description, name, phone?, email?, cpf, UID }
 *        → genera QR Pix. Devuelve la respuesta de Pix + campo `token` (para verifyTransaction).
 *   POST { type:'verify', token, referenceId }
 *        → verifica estado de la transacción.
 *
 * Auth: JWT de tenant. PATH DE DINERO — port fiel del handler legacy.
 * ⚠️ DEUDA: el token Pix viaja cliente↔servidor (legacy design). Ver PixService.php.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/PixService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\PixService;

$ctx = apiAuthTenant();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc  = new PixService(TenantContext::fromAuth($ctx));
$type = (string) ($_POST['type'] ?? '');

if ($type === 'create') {
    $amount = (float)  ($_POST['QRAmount']    ?? 0);
    $desc   = (string) ($_POST['description'] ?? '');
    $name   = (string) ($_POST['name']        ?? '');
    $phone  = (string) ($_POST['phone']       ?? '');
    $email  = (string) ($_POST['email']       ?? '');
    $cpf    = (string) ($_POST['cpf']         ?? '');

    if ($amount <= 0 || $desc === '' || $name === '' || $cpf === '') {
        apiError('QRAmount, description, name y cpf son obligatorios', 422);
    }

    try {
        $res = $svc->createQR($amount, $desc, $name, $phone, $email, $cpf);
        apiOk($res);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 502);
    }
}

if ($type === 'verify') {
    $token       = trim((string) ($_POST['token']       ?? ''));
    $referenceId = trim((string) ($_POST['referenceId'] ?? ''));

    if ($token === '' || $referenceId === '') {
        apiError('token y referenceId son obligatorios', 422);
    }

    $res = $svc->verifyTransaction($token, $referenceId);
    if (isset($res['error'])) {
        apiError($res['error'], 400);
    }
    // El front consume result.success — mantener shape { success: [...] }
    apiOk($res);
}

apiError('type debe ser create|verify', 422);
