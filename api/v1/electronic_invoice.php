<?php
/**
 * /api/v1/electronic_invoice.php — facturación electrónica (Paraguay).
 *
 *   POST { data: { fecha_desde, fecha_hasta, invoiceNum, establecimiento,
 *                  puntoExpedicion, type } }  → consulta el estado del documento en FE
 *
 * companyId/RUC del JWT. Lectura con payload → POST (§22.7). Devuelve el documento de FE
 * tal cual (el front lo JSON.parse y lee .cdc/.docNro), o 404 si no hay documentos.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/ElectronicInvoiceService.php';

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$data = $_POST['data'] ?? [];
if (!is_array($data)) {
    $data = [];
}

$doc = (new ElectronicInvoiceService())->consultStatus($companyId, $data);
if ($doc === null) {
    apiError('No se encontraron documentos', 404);
}

apiOk($doc);
