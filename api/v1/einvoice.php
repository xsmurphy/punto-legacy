<?php
/**
 * REST — Facturación electrónica (Factomate / SIFEN). F0: cuenta del comercio.
 * F1: outbox de emisión (context/28-facturacion-electronica-plan.md).
 *
 *   GET  /v1/einvoice?resource=account          → estado de la cuenta conectada (incluye timbrado)
 *   POST /v1/einvoice?action=account            → guarda usuario/teléfono/entorno/contraseña/config
 *   POST /v1/einvoice?action=test               → prueba la conexión (Token → PhoneLogin → GetUserInfo → sincro/config)
 *   GET  /v1/einvoice?resource=paymentMethods   → proxy de códigos de medio de pago
 *   GET  /v1/einvoice?resource=documents&transactionId=X → estado del documento de una venta (solo lectura)
 *   POST /v1/einvoice?action=drain              → drena el outbox (pending/error vencidos) — SOLO cron, secreto compartido
 *
 * Auth: panel para todo salvo `drain` (gateado por EINVOICE_DRAIN_SECRET, sin
 * realm — lo invoca el cron del sistema). Escritura de panel (POST account/test)
 * gateada por `einvoice.manage`; `documents` es lectura sin permiso especial
 * (mismo criterio que el resto de vistas de solo-lectura del panel). La cuenta
 * es SIEMPRE la de COMPANY_ID del contexto — nunca un id del request
 * (aislamiento multi-tenant, ver context/25-sucursales-y-scopes.md).
 *
 * password_enc/token_enc NUNCA salen de acá — EInvoiceService::getAccount()
 * ya los excluye del SELECT. phone_enc SÍ vuelve descifrado (ver comentario
 * en EInvoiceService::getAccount — no es secreto en el mismo sentido que la
 * contraseña, el operador lo necesita para editar sin re-tipearlo).
 */

require_once __DIR__ . '/../bootstrap.php';

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = $_GET['resource'] ?? null;
$action   = $_GET['action'] ?? null;

// ── drain: SIN apiAuthTenant — lo invoca el cron del sistema, no un operador
// del panel. Se resuelve ANTES de cualquier auth de realm porque un cron no
// tiene sesión de panel. Sin secreto configurado → 503 siempre (nunca abierto). ──
if ($method === 'POST' && $action === 'drain') {
    if (!defined('EINVOICE_DRAIN_SECRET') || EINVOICE_DRAIN_SECRET === '') {
        apiError('EINVOICE_DRAIN_SECRET no configurado — el drainer está deshabilitado.', 503);
    }
    $given = (string) ($_SERVER['HTTP_X_DRAIN_SECRET'] ?? ($_GET['secret'] ?? ''));
    // hash_equals: comparación en tiempo constante, mismo criterio que cualquier
    // verificación de secreto compartido del repo (evita timing attack trivial).
    if ($given === '' || !hash_equals(EINVOICE_DRAIN_SECRET, $given)) {
        apiError('Secreto inválido', 403);
    }
    $limitRaw = (int) ($_GET['limit'] ?? 20);
    $limit    = $limitRaw > 0 && $limitRaw <= 200 ? $limitRaw : 20;
    apiOk((new \Punto\Api\EInvoice\EInvoiceService())->drain($limit));
    exit;
}

$ctx       = apiAuthTenant(['panel']);
$companyId = COMPANY_ID;

$svc = new \Punto\Api\EInvoice\EInvoiceService();

switch ($method) {
    case 'GET':
        if ($resource === 'account') {
            apiOk($svc->getAccount($companyId));
            break;
        }

        if ($resource === 'paymentMethods') {
            try {
                apiOk($svc->paymentMethods($companyId));
            } catch (\RuntimeException $e) {
                // 409, no 403: el problema es de ESTADO (cuenta sin conectar), no de
                // permisos — el 403 mandaría al front a mostrar "no tenés permiso".
                apiError($e->getMessage(), 409);
            }
            break;
        }

        if ($resource === 'documents') {
            $transactionId = (string) ($_GET['transactionId'] ?? '');
            if ($transactionId === '') {
                apiError('Falta transactionId', 422);
            }
            apiOk($svc->documentsForTransaction($companyId, $transactionId));
            break;
        }

        apiError('resource inválido (esperado: account|paymentMethods|documents)', 422);
        break;

    case 'POST':
        if (!hasPermission('einvoice.manage')) {
            apiError('No tenés permiso para esta acción (requiere: einvoice.manage)', 403);
        }

        if ($action === 'account') {
            $username    = (string) (validateHttp('username', 'post') ?: '');
            $phone       = (string) (validateHttp('phone', 'post') ?: '');
            $environment = (string) (validateHttp('environment', 'post') ?: 'test');
            $passwordRaw = validateHttp('password', 'post');
            $password    = $passwordRaw === false || $passwordRaw === null ? null : (string) $passwordRaw;
            $configRaw   = (string) (validateHttp('config', 'post') ?: '{}');
            $config      = json_decode($configRaw, true);

            if (!is_array($config)) {
                apiError('El parámetro config debe ser un objeto JSON válido', 422);
            }

            try {
                apiOk($svc->saveAccount($companyId, $username, $password, $phone, $environment, $config));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($action === 'test') {
            try {
                apiOk($svc->testConnection($companyId));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        apiError('action inválida (esperado: account|test)', 422);
        break;

    default:
        apiError('Method not allowed', 405);
}
