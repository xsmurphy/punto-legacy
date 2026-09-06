<?php
/**
 * REST — Facturación electrónica (Factomate / SIFEN). F0: cuenta del comercio.
 * F1: outbox de emisión (context/28-facturacion-electronica-plan.md).
 *
 *   GET  /v1/einvoice?resource=account          → estado del emisor (fiscal, timbrado, certificado, config)
 *   POST /v1/einvoice?action=provision          → F7: crea/retoma el emisor con los datos legales (white-label)
 *   POST /v1/einvoice?action=config             → guarda config de emisión (autoIssue/onlyWithTaxId/paymentMethodMap)
 *   POST /v1/einvoice?action=uploadCert         → sube el certificado de firma (.pfx base64 + contraseña) al emisor y lo deja en custodia cifrada
 *   POST /v1/einvoice?action=deleteCert         → borra de Punto el certificado en custodia (el emisor lo conserva)
 *   POST /v1/einvoice?action=csc                → guarda el CSC de producción (id + secreto) y lo aplica al emisor
 *   POST /v1/einvoice?action=testSet            → prueba de humo del certificado contra SIFEN (consulta de RUC)
 *   POST /v1/einvoice?action=test               → re-verifica la cuenta (auth + timbrado) y refresca el cache
 *   GET  /v1/einvoice?resource=paymentMethods   → proxy de códigos de medio de pago
 *   GET  /v1/einvoice?resource=documents&transactionId=X → estado del documento de una venta (solo lectura)
 *   POST /v1/einvoice?action=drain              → drena el outbox (pending/error vencidos) — SOLO cron, secreto compartido
 *
 *   F2 — operación de los documentos ya emitidos:
 *   GET  /v1/einvoice?resource=documents&from=&to=&status=&search=&page=&pageSize= → listado paginado (panel)
 *   GET  /v1/einvoice?resource=kude&id=          → PDF (KuDE) del documento, stream binario
 *   POST /v1/einvoice?action=retry&id=           → reencola un documento en error → pending (gateado einvoice.manage)
 *   POST /v1/einvoice?action=cancel&id=          → anula un documento issued en SIFEN (gateado einvoice.manage, body: reason)
 *   POST /v1/einvoice?action=reconcile           → reconcilia sifen_status contra GetAll (gateado einvoice.manage)
 *
 * Auth: panel para todo salvo `drain` (gateado por EINVOICE_DRAIN_SECRET, sin
 * realm — lo invoca el cron del sistema). TODA escritura de panel está gateada
 * por `einvoice.manage`: el chequeo está una sola vez, arriba del bloque POST,
 * así que cubre también provision/uploadCert/deleteCert/csc/testSet — cualquier
 * `action` nueva nace gateada. `documents`/`kude` son
 * lectura sin permiso especial (mismo criterio que el resto de vistas de
 * solo-lectura del panel — ver nota de `documentsForTransaction`). La cuenta
 * es SIEMPRE la de COMPANY_ID del contexto — nunca un id del request
 * (aislamiento multi-tenant, ver context/25-sucursales-y-scopes.md).
 *
 * WHITE-LABEL (F7): el comercio nunca ve una credencial de Factomate — el
 * alta del emisor la hace Punto con su credencial admin (env,
 * FACTOMATE_ADMIN_*) vía EInvoiceProvisioningService. Ninguna respuesta de
 * este endpoint incluye usuario/contraseña/identidad de login del proveedor
 * (getAccount ya los excluye del SELECT).
 *
 * CUSTODIA (owner 2026-09-06, context/28 §Custodia): el certificado de firma y
 * el secreto del CSC entran por acá, van al proveedor y quedan GUARDADOS
 * CIFRADOS (`FiscalSecretStore`, mig 195) para poder reconfigurar la emisión
 * sin volver a pedírselos al comercio. NINGUNA respuesta de este endpoint los
 * devuelve: lo único que sale es si hay algo cargado y desde cuándo
 * (`certStored`/`certUploadedAt`/`cscStored`/`cscUpdatedAt`). Cada lectura
 * server-side queda auditada en `tenant_audit`.
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
    // Solo por header (2026-08-21): el fallback `?secret=` dejaba el secreto en
    // logs de acceso/proxy. Ningún caller lo usaba.
    $given = (string) ($_SERVER['HTTP_X_DRAIN_SECRET'] ?? '');
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
            if ($transactionId !== '') {
                // Uso puntual (F1): estado del documento de UNA venta desde la vista de venta.
                apiOk($svc->documentsForTransaction($companyId, $transactionId));
                break;
            }

            // F2: listado paginado del panel de facturación electrónica.
            $filters = [
                'from'     => (string) ($_GET['from'] ?? ''),
                'to'       => (string) ($_GET['to'] ?? ''),
                'status'   => (string) ($_GET['status'] ?? ''),
                'search'   => (string) ($_GET['search'] ?? ''),
                'page'     => (int) ($_GET['page'] ?? 1),
                'pageSize' => (int) ($_GET['pageSize'] ?? 25),
            ];
            apiOk($svc->documents($companyId, $filters));
            break;
        }

        if ($resource === 'kude') {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                apiError('Falta id', 422);
            }
            try {
                $pdf = $svc->kude($companyId, $id);
            } catch (\RuntimeException $e) {
                // 409, no 500: "todavía no está listo" / "no se emitió" es un
                // estado esperado del documento, no una falla del servidor —
                // el frontend lo traduce a "reintentar más tarde".
                apiError($e->getMessage(), 409);
            }
            // Stream binario — apiOk() envuelve todo en el JSON envelope del
            // proyecto, que acá rompería el PDF. Se responde crudo, mismo
            // criterio que cualquier endpoint de descarga de archivo del repo.
            http_response_code(200);
            header('Content-Type: application/pdf');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        }

        apiError('resource inválido (esperado: account|paymentMethods|documents|kude)', 422);
        break;

    case 'POST':
        if (!hasPermission('einvoice.manage')) {
            apiError('No tenés permiso para esta acción (requiere: einvoice.manage)', 403);
        }

        if ($action === 'provision') {
            // El formulario legal entero viaja como JSON en `form`. No pasa
            // por validateHttp campo por campo: la validación semántica
            // (qué es obligatorio, formatos de timbrado) vive en
            // EInvoiceProvisioningService::validateForm, con mensajes para
            // el comercio.
            $form = $_POST['form'] ?? null;
            if (is_string($form)) {
                $form = json_decode($form, true);
            }
            if (!is_array($form)) {
                apiError('Faltan los datos del emisor', 422);
            }

            try {
                apiOk((new \Punto\Api\EInvoice\EInvoiceProvisioningService())->provision($companyId, $form));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($action === 'config') {
            $configRaw = (string) (validateHttp('config', 'post') ?: '{}');
            $config    = json_decode($configRaw, true);
            if (!is_array($config)) {
                apiError('El parámetro config debe ser un objeto JSON válido', 422);
            }

            try {
                apiOk($svc->saveConfig($companyId, $config));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($action === 'uploadCert') {
            // Directo de $_POST, NUNCA por validateHttp ni a un log: el
            // certificado es la identidad de firma del contribuyente y la
            // contraseña lo abre. Pasan al proveedor y quedan en custodia
            // cifrada (FiscalSecretStore) — nunca vuelven en una respuesta.
            $certBase64   = (string) ($_POST['certBase64'] ?? '');
            $certPassword = (string) ($_POST['certPassword'] ?? '');

            try {
                apiOk((new \Punto\Api\EInvoice\EInvoiceProvisioningService())->uploadCert($companyId, $certBase64, $certPassword));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($action === 'deleteCert') {
            // Es SU certificado: si lo pide, Punto deja de custodiarlo. No se
            // toca el del proveedor — el comercio sigue facturando.
            try {
                apiOk((new \Punto\Api\EInvoice\EInvoiceProvisioningService())->deleteCert($companyId));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($action === 'csc') {
            // Mismo criterio que uploadCert: el secreto sale de $_POST crudo y
            // no pasa por validateHttp (que trimea/colapsa valores) ni por
            // ningún log.
            $cscId     = (string) ($_POST['cscId'] ?? '');
            $cscSecret = (string) ($_POST['cscSecret'] ?? '');

            try {
                apiOk((new \Punto\Api\EInvoice\EInvoiceProvisioningService())->saveCsc($companyId, $cscId, $cscSecret));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($action === 'testSet') {
            try {
                apiOk((new \Punto\Api\EInvoice\EInvoiceProvisioningService())->testSet($companyId));
            } catch (\RuntimeException $e) {
                // 422 con el detalle: "Error de Certificado o CSC" es
                // información accionable para el operador, no un 500.
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

        if ($action === 'retry') {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                apiError('Falta id', 422);
            }
            try {
                apiOk($svc->retry($companyId, $id));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($action === 'cancel') {
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                apiError('Falta id', 422);
            }
            $reason = (string) (validateHttp('reason', 'post') ?: '');
            try {
                apiOk($svc->cancel($companyId, $id, $reason));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($action === 'reconcile') {
            $limitRaw = (int) ($_GET['limit'] ?? 50);
            $limit    = $limitRaw > 0 && $limitRaw <= 200 ? $limitRaw : 50;
            try {
                apiOk($svc->reconcile($companyId, $limit));
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        apiError('action inválida (esperado: provision|config|uploadCert|deleteCert|csc|testSet|test|retry|cancel|reconcile)', 422);
        break;

    default:
        apiError('Method not allowed', 405);
}
