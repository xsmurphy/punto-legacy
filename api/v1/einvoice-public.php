<?php
/**
 * REST PÚBLICO — portal de consulta del comprador (F6,
 * context/28-facturacion-electronica-plan.md §Portal).
 *
 *   GET /v1/einvoice-public?t=<token>                → datos del documento de esa venta
 *   GET /v1/einvoice-public?resource=kude&t=<token>  → KuDE (PDF), stream binario
 *
 * **SIN AUTENTICACIÓN, a propósito**: el comprador no tiene cuenta en Punto. La
 * autorización es el token firmado impreso en su comprobante — ver
 * `EInvoice\PortalToken`. Reglas que sostienen que esto sea seguro:
 *
 *   1. El token va firmado con HMAC: no se puede fabricar ni enumerar.
 *   2. El token lleva DENTRO el companyId — el aislamiento multi-tenant sale de
 *      lo que se firmó, nunca de un parámetro del request. No existe forma de
 *      pedir "esta venta pero de otro comercio".
 *   3. Solo se responde lo que el comprador ya tiene impreso (ver
 *      `EInvoiceService::portalDocument`): nada del outbox, ningún dato de otro
 *      cliente, ningún listado.
 *   4. Token inválido, mal formado, sin documento o de una venta inexistente
 *      responden lo MISMO (404): quien pruebe tokens no aprende nada de las
 *      diferencias entre respuestas.
 *
 * El listado por RUC del plan NO se implementa acá: los RUC paraguayos son
 * públicos, así que listar por RUC exige un segundo factor del titular (ver
 * §Portal del plan) y es una decisión de producto todavía abierta.
 */

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Method not allowed', 405);
}

$resource = $_GET['resource'] ?? null;
$claims   = \Punto\Api\EInvoice\PortalToken::verify((string) ($_GET['t'] ?? ''));

// Mensaje único para token inválido / documento inexistente — ver punto 4 arriba.
$notFound = 'No encontramos esta factura. Verificá el enlace de tu comprobante.';
if ($claims === null) {
    apiError($notFound, 404);
}

$companyId     = $claims['companyId'];
$transactionId = $claims['transactionId'];
$svc           = new \Punto\Api\EInvoice\EInvoiceService();

if ($resource === 'kude') {
    try {
        $pdf = $svc->portalKude($companyId, $transactionId);
    } catch (\RuntimeException $e) {
        // 409: el documento existe pero todavía no tiene KuDE (no emitido, o el
        // proveedor no lo generó aún). Distinto de "no existe" — acá el link SÍ
        // era válido, así que decírselo no filtra nada.
        apiError($e->getMessage(), 409);
    }
    http_response_code(200);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    // inline: el comprador abre el PDF en el navegador del teléfono, no lo baja.
    header('Content-Disposition: inline; filename="factura.pdf"');
    echo $pdf;
    exit;
}

$doc = $svc->portalDocument($companyId, $transactionId);
if ($doc === null) {
    apiError($notFound, 404);
}

apiOk($doc);
