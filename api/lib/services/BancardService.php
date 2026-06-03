<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;

/**
 * BancardService — generación/refresh/cancel de QR Bancard (Slice 43).
 *
 * Strangler de app/load.php?load=bancardQR. Port FIEL del handler legacy:
 * llama a BANCARD_QR_API vía curlContents con Bearer token y devuelve la
 * respuesta cruda de Bancard tal cual.
 *
 * ⚠️ PATH DE DINERO. No se modifica la lógica de negocio ni el shape de
 * respuesta — el objetivo es mover el código a /api sin cambiar comportamiento.
 *
 * Credenciales necesarias en .env:
 *   BANCARD_QR_API       URL base del API QR de Bancard
 *   BANCARD_QR_API_TOKEN Bearer token de autenticación
 */
final class BancardService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /** Headers comunes para todos los requests a Bancard QR. */
    private function headers(): array
    {
        return [
            'Accept: application/json',
            'Authorization: Bearer ' . (defined('BANCARD_QR_API_TOKEN') ? BANCARD_QR_API_TOKEN : ''),
            'Content-Type: application/json',
        ];
    }

    private function baseUrl(): string
    {
        return defined('BANCARD_QR_API') ? (string) BANCARD_QR_API : '';
    }

    /**
     * Crea un QR de pago.
     *
     * @param float  $amount      Monto a cobrar.
     * @param float  $saleAmount  Monto de venta (puede diferir del cobrado por comisión).
     * @param string $uid         UID de la transacción (trUID del front).
     * @param float|null $comission  Comisión (opcional).
     * @param float|null $tax        Impuesto (opcional).
     * @return string  JSON de respuesta de Bancard (se pasa crudo al front).
     */
    public function createQR(
        float $amount,
        float $saleAmount,
        string $uid,
        ?float $comission,
        ?float $tax
    ): string {
        $companyName = str_replace('&', 'y', defined('COMPANY_NAME') ? COMPANY_NAME : '');

        $data = [
            'amount'      => $amount,
            'description' => 'Pago a ' . $companyName,
            'identifier'  => json_encode([
                'companyID'  => enc($this->ctx->companyId),
                'outletID'   => enc($this->ctx->outletId),
                'registerID' => enc($this->ctx->registerId),
                'UID'        => $uid,
                'amount'     => $amount,
                'saleAmount' => $saleAmount,
                'comission'  => $comission,
                'tax'        => $tax,
            ]),
        ];

        return (string) curlContents($this->baseUrl() . '/create', 'POST', json_encode($data), $this->headers());
    }

    /**
     * Refresca un QR existente (extiende su vigencia).
     * @param string $id  ID del QR devuelto por createQR.
     */
    public function refreshQR(string $id): string
    {
        return (string) curlContents($this->baseUrl() . '/refresh/' . $id, 'POST', json_encode([]), $this->headers());
    }

    /**
     * Cancela un QR pendiente.
     * @param string $id  ID del QR.
     */
    public function cancelQR(string $id): string
    {
        return (string) curlContents($this->baseUrl() . '/revert/' . $id, 'POST', json_encode([]), $this->headers());
    }
}
