<?php
/**
 * REST — Facturación electrónica (SIFEN). F0: cuenta del comercio.
 * F1: outbox de emisión (context/28-facturacion-electronica-plan.md).
 *
 *   GET  /v1/einvoice?resource=account          → estado del emisor (fiscal, timbrado, certificado, config)
 *   POST /v1/einvoice?action=provision          → F7: crea/retoma el emisor con los datos legales (white-label)
 *   POST /v1/einvoice?action=config             → guarda config de emisión (autoIssue/onlyWithTaxId/paymentMethodMap)
 *   POST /v1/einvoice?action=uploadCert         → sube el certificado de firma (.pfx base64 + contraseña) al emisor y lo deja en custodia cifrada
 *   POST /v1/einvoice?action=deleteCert         → borra de Punto el certificado en custodia (el emisor lo conserva)
 *   POST /v1/einvoice?action=csc                → guarda el CSC de producción (id + secreto) y lo aplica al emisor
 *   POST /v1/einvoice?action=issueForSale&transactionId= → emite a mano la factura de una venta ya hecha
 *   POST /v1/einvoice?action=testSet            → estado del certificado del emisor (el motor lo valida al subirlo)
 *   POST /v1/einvoice?action=test               → re-verifica la cuenta (auth + timbrado) y refresca el cache
 *   GET  /v1/einvoice?resource=paymentMethods   → proxy de códigos de medio de pago
 *   GET  /v1/einvoice?resource=documents&transactionId=X → estado del documento de una venta (solo lectura)
 *   POST /v1/einvoice?action=drain              → drena el outbox (pending/error vencidos) — SOLO cron, secreto compartido
 *
 *   F2 — operación de los documentos ya emitidos:
 *   GET  /v1/einvoice?resource=documents&from=&to=&status=&search=&page=&pageSize= → listado paginado (panel)
 *   GET  /v1/einvoice?resource=kude&id=          → PDF (KuDE) del documento, stream binario
 *   POST /v1/einvoice?action=retry&id=           → reencola un documento en error → pending (gateado einvoice.manage)
 *   POST /v1/einvoice?action=reissue&id=         → F7/N2: emite de nuevo un documento RECHAZADO por SIFEN — documento NUEVO,
 *                                                  el rechazado queda como registro (gateado einvoice.manage)
 *   POST /v1/einvoice?action=cancel&id=          → anula un documento issued en SIFEN (gateado einvoice.manage, body: reason)
 *   POST /v1/einvoice?action=reconcile           → reconcilia sifen_status contra GetAll (gateado einvoice.manage)
 *   POST /v1/einvoice?action=sendKude&id=        → D8 de context/57: ENCOLA el envío del KuDE por email al cliente
 *                                                  (body: email opcional — vacío = la casilla del cliente de la venta)
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
 * WHITE-LABEL (F7): el comercio nunca ve una credencial del motor de
 * facturación — el alta del emisor la hace Punto con SU credencial de
 * plataforma (`integration.fepy` en platform_config, o `FEPY_API_KEY`) vía
 * EInvoiceProvisioningService. Ninguna respuesta de este endpoint incluye
 * credenciales ni identidad de login contra el motor (getAccount ya las
 * excluye del SELECT).
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

// El realm `api` (API key del tenant, `context/58`) entra SOLO al estado de la
// cuenta, y solo por GET. Es lo que necesita `get_einvoice_setup` para poder
// conducir la configuración de FE por MCP (M7): sin esto el bot registra
// acciones a ciegas, sin poder verificar en qué quedó el emisor.
//
// El recorte por `resource` es deliberado y no una precaución de más: el resto
// de este archivo sirve el LISTADO de documentos fiscales emitidos y el KuDE en
// PDF de cada uno, que es mucho más que "cómo está configurada mi cuenta".
// Abrirlo entero para habilitar una lectura de setup habría regalado esa
// superficie sin que nadie la pidiera.
//
// La escritura no depende de esta línea: `apiAuthTenant()` corta con 405
// cualquier verbo que no sea GET/HEAD para el realm `api` salvo que el endpoint
// declare `apiWrite` — y acá no se declara, ni se va a declarar: el certificado
// y el CSC no se cargan por API key (M8 tiene su propio mecanismo).
//
// ── El realm `pos-app` entra SOLO al KuDE, y solo por GET ────────────────────
//
// Qué expone: el PDF de UN documento fiscal ya emitido, pedido por su id y
// resuelto SIEMPRE contra `COMPANY_ID` del token (`kude($companyId, $id)`),
// así que un device no puede pedir el KuDE de otro comercio. Y es justamente el
// documento que el comercio le ENTREGA a su propio cliente: negarlo en la caja
// —el único lugar donde el cliente está parado enfrente— era el agujero
// (pedido del owner, 2026-09-09). El device ya está autenticado contra esa
// company y ya ve la venta entera en el detalle de la transacción; el KuDE no
// le agrega ningún dato que no tuviera.
//
// Qué NO expone, y por qué el recorte es por `resource` y no por archivo:
//   - `documents` (sin `transactionId`) es el LISTADO PAGINADO de todos los
//     documentos fiscales del tenant, con JOIN a cliente y totales. Una caja no
//     necesita el histórico fiscal del comercio para descargar el KuDE de la
//     venta que tiene en pantalla: el POS recibe los documentos DE ESA VENTA
//     por el detalle de la transacción (`TransactionService::getSingle`, misma
//     fuente `documentsForTransaction()`), que ya está scopeado por
//     transacción. Abrirlo acá sería regalar la superficie ancha para resolver
//     una necesidad angosta.
//   - `account` / `paymentMethods` son configuración del emisor, no del
//     comprobante.
//   - TODO el POST sigue siendo `panel` exclusivo — es donde se cargan el
//     certificado y el CSC (ver el bloque de arriba, que no se toca).
//
// El POS es TOKEN-ONLY (mandato del proyecto, `context/08` §60): esto habilita
// un Bearer de device, NUNCA una cookie. La contraparte en el front
// (`app/api/pos/einvoice/kude/route.ts`) va con `requireBearer: true`.
$realms = ['panel'];
if ($method === 'GET' && $resource === 'account') {
    $realms[] = 'api';
}
if ($method === 'GET' && $resource === 'kude') {
    $realms[] = 'pos-app';
}
$ctx       = apiAuthTenant($realms);
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
                // ── La CAJA es un canal de ENTREGA, el panel no ─────────────
                //
                // Desde el panel, `resource=kude` es la descarga INTERNA: el
                // comercio mirando su propio documento, y ve el MISMO KuDE que
                // recibe el comprador (K2 de context/73) — que vea otro es
                // justo lo que rompe la verificación de paridad.
                //
                // Desde el POS el PDF se lo lleva el comprador EN LA MANO, así
                // que corresponde el mismo gate fiscal que ya aplican los
                // otros dos canales de entrega (el email de `sendKude()` y el
                // portal de `portalKude()`): número que coincide con el
                // comprobante impreso (mig 204), no reemplazado, no anulado,
                // emitido, y aprobado por SIFEN. Ni el CDC ni el PDF prueban
                // validez — hay un caso registrado de un documento con CDC
                // válido que SIFEN rechazó después y cuyo KuDE bajaba igual.
                $pdf = AUTHED_REALM === 'pos-app'
                    ? $svc->posKude($companyId, $id)
                    : $svc->kude($companyId, $id);
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
                // 422 con el detalle: lo que falla acá es el certificado o el
                // CSC del emisor, información accionable para el operador —
                // no un 500.
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

        if ($action === 'issueForSale') {
            // Emitir a mano la factura de una venta YA HECHA. Existe porque el
            // encolado automático tiene tres salidas silenciosas (cuenta no
            // conectada, `autoIssue` apagado, `onlyWithTaxId` sin RUC) y una
            // venta que caía en cualquiera de ellas quedaba sin documento para
            // siempre: `retry` sale de `error` y `reissue` exige un rechazo de
            // SIFEN — las dos necesitan una fila que nunca se creó.
            //
            // Toma el transactionId y no un docId JUSTAMENTE por eso: el
            // documento todavía no existe.
            $txId = (string) ($_GET['transactionId'] ?? '');
            if ($txId === '') {
                apiError('Falta transactionId', 422);
            }
            try {
                apiOk($svc->issueForSaleOnDemand($companyId, $txId));
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

        if ($action === 'reissue') {
            // N2 de context/28 §F7 — "corregir y emitir de nuevo": NO es un
            // retry. Un rechazado por SIFEN está `issued`, así que reintentarlo
            // emitiría el documento fiscal dos veces; esto encola un documento
            // NUEVO y deja el rechazado como registro. No recibe ningún dato:
            // la corrección se hizo antes en la ficha del cliente / la caja /
            // el emisor, y el payload se reconstruye de ahí.
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                apiError('Falta id', 422);
            }
            try {
                apiOk($svc->reissue($companyId, $id, $ctx['userId'] ?? null));
            } catch (\RuntimeException $e) {
                // 422 con el texto tal cual: los motivos ("ya fue reemitido",
                // "no está rechazado") son accionables para el operador.
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

        if ($action === 'sendKude') {
            // D8 de context/57 — reenvío manual de la factura al cliente. Es el
            // escape para "no me llegó" / "mandámelo a la del contador", y
            // cubre al cliente que cargó su email DESPUÉS de la venta.
            //
            // No manda nada acá: ENCOLA en `notification_outbox`. El envío lo
            // hace el drainer (≤5 min), que es quien tiene reintento y deja
            // registro — mandar inline dejaría el fallo sin cola y sin rastro.
            // Un `email` distinto crea una fila nueva por el UNIQUE del outbox,
            // que es exactamente lo que se quiere.
            $id = (string) ($_GET['id'] ?? '');
            if ($id === '') {
                apiError('Falta id', 422);
            }
            $email = (string) (validateHttp('email', 'post') ?: '');
            try {
                apiOk($svc->sendKude($companyId, $id, $email, $ctx['userId'] ?? null));
            } catch (\RuntimeException $e) {
                // 422 con el texto tal cual: los motivos ("SIFEN todavía no lo
                // aprobó", "el cliente no tiene email") son accionables.
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

        apiError('action inválida (esperado: provision|config|uploadCert|deleteCert|csc|testSet|test|issueForSale|retry|reissue|cancel|reconcile|sendKude)', 422);
        break;

    default:
        apiError('Method not allowed', 405);
}
