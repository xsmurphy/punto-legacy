<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;

/**
 * PixService — generación de QR Pix y verificación de transacción (Slice 43).
 *
 * Strangler de app/load.php?load=pixQR y load=verifyTransactionPix.
 * Port FIEL del handler legacy — llama a API_PIX_URL.
 *
 * ⚠️ PATH DE DINERO. Port fiel sin cambios de lógica.
 *
 * ⚠️ DEUDA DE DISEÑO (no se resuelve en este slice): el token de Pix se
 * obtiene en `getToken()` y actualmente el flujo legacy lo envía al cliente
 * para que éste lo reenvíe en la siguiente llamada (verifyTransactionPix).
 * Esto expone el token API en el browser. La corrección correcta sería
 * almacenar el token server-side (sesión / cache / BD) y resolverlo por UID
 * de transacción. Se difiere como deuda de seguridad registrada.
 *
 * Credenciales necesarias en .env:
 *   API_PIX_URL        URL base del API Pix
 *   API_PIX_CLIENT_ID  Client ID de autenticación OAuth
 *   API_PIX_SECRET     Secret de autenticación OAuth
 */
final class PixService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /**
     * Obtiene un token Bearer de Pix vía client_credentials.
     * Retorna el token string, o lanza RuntimeException si falla.
     */
    public function getToken(): string
    {
        $url    = (defined('API_PIX_URL')       ? API_PIX_URL       : '') . '/api/token';
        $header = ['Accept: application/json', 'Content-Type: application/json'];
        $data   = [
            'grant_type' => 'client_credentials',
            'client_id'  => defined('API_PIX_CLIENT_ID') ? API_PIX_CLIENT_ID : '',
            'secret'     => defined('API_PIX_SECRET')     ? API_PIX_SECRET     : '',
        ];

        $res = json_decode((string) curlContents($url, 'POST', json_encode($data), $header), true);
        if (!isset($res['token'])) {
            throw new \RuntimeException('Pix token not found');
        }
        return (string) $res['token'];
    }

    /**
     * Genera un QR Pix.
     *
     * @return array  Respuesta de Pix con `reference_id`, `qr_image`, etc.
     *                Incluye el token en el campo `token` (paridad con legacy:
     *                load.php L149 `$result['token'] = $pixToken`).
     * @throws \RuntimeException si no se pudo obtener token o Pix devuelve error.
     */
    public function createQR(
        float  $amount,
        string $description,
        string $name,
        string $phone,
        string $email,
        string $cpf
    ): array {
        $token  = $this->getToken();
        $url    = (defined('API_PIX_URL') ? API_PIX_URL : '') . '/api/generate_qr';
        $header = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $companyName = str_replace('&', 'y', defined('COMPANY_NAME') ? COMPANY_NAME : '');
        $data = [
            'amount'      => $amount,
            'name'        => $name,
            'phone'       => $phone,
            'email'       => $email,
            'description' => $description . ' - ' . $companyName,
            'cpf'         => $cpf,
        ];

        $res = json_decode((string) curlContents($url, 'POST', json_encode($data), $header), true);
        if (isset($res['error'])) {
            throw new \RuntimeException((string) $res['error']);
        }

        // Incluye el token en la respuesta para que el front lo guarde y lo reenvíe
        // en verifyTransactionPix (paridad con legacy load.php:149 — deuda de diseño).
        $res['token'] = $token;
        return $res;
    }

    /**
     * Verifica el estado de una transacción Pix por referenceId.
     *
     * @param string $token       Bearer token devuelto por createQR (pasado por el cliente).
     * @param string $referenceId ID de la transacción Pix.
     * @return array  Shape { success: [<txData>] } o { error }.
     */
    public function verifyTransaction(string $token, string $referenceId): array
    {
        $url    = (defined('API_PIX_URL') ? API_PIX_URL : '') . '/api/transaction/' . $referenceId;
        $header = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $res = json_decode((string) curlContents($url, 'GET', false, $header), true);
        if (isset($res['error'])) {
            return ['error' => $res['error']];
        }
        return ['success' => $res];
    }
}
