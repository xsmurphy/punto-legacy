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

        $raw = (string) curlContents($this->baseUrl() . '/create', 'POST', json_encode($data), $this->headers());

        // Binding local id→tenant para que refresh/cancel puedan validar
        // pertenencia (Bancard no lo expone). Best-effort: si falla, NO se
        // rompe la creación del QR (path de dinero).
        $this->persistOwnership($raw);

        return $raw;
    }

    /**
     * Guarda en `bancard_qr` todos los ids candidatos de la respuesta de Bancard
     * apuntando al tenant emisor. Espeja las claves que prueba el front
     * (frontend/lib/payments/psp-qr.ts) + los wrappers donde puede venir anidado.
     */
    private function persistOwnership(string $raw): void
    {
        $ids = $this->extractQrIds($raw);
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            try {
                ncmExecute(
                    'INSERT INTO bancard_qr (qrId, companyId, outletId)
                     VALUES (?, ?::uuid, ?)
                     ON CONFLICT (qrId) DO NOTHING',
                    [$id, $this->ctx->companyId, $this->ctx->outletId ?: null]
                );
            } catch (\Throwable $e) {
                error_log('[BancardService] persistOwnership falló para id ' . $id . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * El companyId dueño de un QR, o null si no lo tenemos registrado.
     * null = fail-open a propósito (ver mig 174): no bloqueamos lo que no
     * conocemos, solo lo que sabemos que es de otro tenant.
     */
    public function ownerCompanyOf(string $id): ?string
    {
        global $db;
        if ($id === '' || !isset($db) || !is_object($db)) {
            return null;
        }
        try {
            // `$db->Execute` (recordset con ->EOF/->fields), NO `ncmExecute`
            // (que devuelve una fila directa — trap conocida del wrapper).
            $rs = $db->Execute('SELECT companyId FROM bancard_qr WHERE qrId = ? LIMIT 1', [$id]);
        } catch (\Throwable $e) {
            error_log('[BancardService] ownerCompanyOf falló: ' . $e->getMessage());
            return null; // fail-open ante error de DB: no rompemos el path de dinero
        }
        if ($rs === false || $rs->EOF) {
            return null;
        }
        $owner = (string) ($rs->fields['companyid'] ?? $rs->fields['companyId'] ?? '');
        return $owner !== '' ? $owner : null;
    }

    /**
     * Ids candidatos en la respuesta de Bancard: mismas claves que el front
     * (ID_KEYS) miradas en la raíz y en cada wrapper (data/result/response/qr).
     *
     * @return string[]
     */
    private function extractQrIds(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $idKeys      = ['id', 'qr_id', 'qrId', 'transaction_id', 'transactionId', 'operationId'];
        $wrapperKeys = ['data', 'result', 'response', 'qr'];

        $scopes = [$decoded];
        foreach ($wrapperKeys as $wk) {
            if (isset($decoded[$wk]) && is_array($decoded[$wk])) {
                $scopes[] = $decoded[$wk];
            }
        }

        $ids = [];
        foreach ($scopes as $scope) {
            foreach ($idKeys as $k) {
                if (isset($scope[$k]) && (is_string($scope[$k]) || is_int($scope[$k]))) {
                    $val = trim((string) $scope[$k]);
                    if ($val !== '') {
                        $ids[$val] = true; // dedup
                    }
                }
            }
        }
        return array_keys($ids);
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
