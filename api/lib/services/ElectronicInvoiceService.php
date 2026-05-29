<?php
/**
 * ElectronicInvoiceService — consulta de facturación electrónica (Paraguay).
 *
 * Port de consultStatusElectronicInvoice (app/action.php L3558). Proxy a la API externa
 * de FE (consultFE, en app/includes/functions.php) — sólo cambia: el companyId/RUC sale
 * del JWT en vez del request. El RUC vive en company.config->>'settingRUC' (jsonb).
 *
 * Lectura con payload (rango de fechas/documento) → el endpoint la expone vía POST (§22.7).
 */

class ElectronicInvoiceService
{
    /**
     * Consulta el estado de un documento electrónico en la API de FE.
     *
     * @param array $data  fecha_desde, fecha_hasta, invoiceNum, establecimiento,
     *                     puntoExpedicion, type (0 = FC, otro = FCR).
     * @return object|null El primer documento de la respuesta de FE, o null si no hay.
     */
    public function consultStatus(string $companyId, array $data): ?object
    {
        $row = ncmExecute(
            "SELECT config->>'settingRUC' AS settingRUC FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );

        $payload = [
            'ruc'                => $row['settingRUC'] ?? null,
            'type'               => (($data['type'] ?? null) == 0) ? 'FC' : 'FCR',
            'fecha_desde'        => $data['fecha_desde'] ?? null,
            'fecha_hasta'        => $data['fecha_hasta'] ?? null,
            'establecimiento'    => $data['establecimiento'] ?? null,
            'puntoExpedicion'    => $data['puntoExpedicion'] ?? null,
            'documentoNro_desde' => $data['invoiceNum'] ?? null,
            'documentoNro_hasta' => $data['invoiceNum'] ?? null,
        ];

        $feresult = json_decode(consultFE($payload, FACTURACION_ELECTRONICA_TOKEN));

        if (empty($feresult->documents)) {
            return null;
        }
        return $feresult->documents[0];
    }
}
