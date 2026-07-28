<?php
/**
 * REST — Facturación electrónica (Factomate / SIFEN). F0: cuenta del comercio
 * (context/28-facturacion-electronica-plan.md). F1 agrega documents/drain.
 *
 *   GET  /v1/einvoice?resource=account         → estado de la cuenta conectada (incluye timbrado)
 *   POST /v1/einvoice?action=account           → guarda usuario/teléfono/entorno/contraseña/config
 *   POST /v1/einvoice?action=test              → prueba la conexión (Token → PhoneLogin → GetUserInfo → sincro/config)
 *   GET  /v1/einvoice?resource=paymentMethods  → proxy de códigos de medio de pago
 *
 * Auth: panel. Escritura (POST) gateada por `einvoice.manage`. La cuenta es
 * SIEMPRE la de COMPANY_ID del contexto — nunca un id del request (aislamiento
 * multi-tenant, ver context/25-sucursales-y-scopes.md).
 *
 * password_enc/token_enc NUNCA salen de acá — EInvoiceService::getAccount()
 * ya los excluye del SELECT. phone_enc SÍ vuelve descifrado (ver comentario
 * en EInvoiceService::getAccount — no es secreto en el mismo sentido que la
 * contraseña, el operador lo necesita para editar sin re-tipearlo).
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = COMPANY_ID;
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource  = $_GET['resource'] ?? null;
$action    = $_GET['action'] ?? null;

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

        apiError('resource inválido (esperado: account|paymentMethods)', 422);
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
