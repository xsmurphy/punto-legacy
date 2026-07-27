<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Cliente HTTP de Automate (https://automate.com.py) — proveedor de
 * facturación electrónica para Paraguay/SIFEN. Mismo estilo que
 * api/lib/Billing/Payments/DlocalGoProvider.php (cURL directo, sin
 * librería HTTP externa, timeouts explícitos).
 *
 * El spec de Automate (GET /api/docs/json) NO tipa las respuestas, así
 * que el parseo es defensivo: se busca el token en las claves más
 * probables (token/accessToken/data.token) en vez de asumir un shape
 * fijo, y se devuelve el payload crudo (`raw`) para que el caller pueda
 * ajustar sin tener que volver a pegarle a la API para adivinar.
 *
 * Nunca loguear contraseñas ni tokens — ni en excepciones ni en error_log.
 */
final class AutomateProvider implements EInvoiceProvider
{
    private const CONNECT_TIMEOUT = 5;
    private const TOTAL_TIMEOUT   = 20;

    private function baseUrl(): string
    {
        $override = defined('AUTOMATE_BASE_URL') ? (string) constant('AUTOMATE_BASE_URL') : '';
        return rtrim($override !== '' ? $override : 'https://automate.com.py', '/');
    }

    public function login(string $username, string $password): array
    {
        $raw = $this->request('POST', '/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ], null);

        $token = $this->extractToken($raw);
        if ($token === null) {
            // No logueamos el body completo si pudiera traer ecos de credenciales —
            // solo las claves de primer nivel, para poder diagnosticar el shape real
            // de la respuesta sin filtrar datos sensibles.
            $keys = implode(', ', array_keys(is_array($raw) ? $raw : []));
            throw new \RuntimeException("Automate no devolvió un token reconocible (claves recibidas: $keys)");
        }

        return [
            'token'     => $token,
            'expiresAt' => $this->extractExpiry($raw),
            'raw'       => $raw,
        ];
    }

    public function me(string $token): array
    {
        return $this->request('GET', '/api/v1/auth/me', null, $token);
    }

    public function paymentMethods(string $token): array
    {
        return $this->request('GET', '/api/v1/codes/payment-methods', null, $token);
    }

    // ── F1 — sin implementar en F0 ──────────────────────────────────────

    public function issue(string $token, array $payload): array
    {
        throw new \LogicException('AutomateProvider::issue — pendiente de F1 (outbox de emisión)');
    }

    public function cancel(string $token, string $cdc, string $reason): array
    {
        throw new \LogicException('AutomateProvider::cancel — pendiente de F1/F2 (cancelación)');
    }

    public function pdf(string $token, string $cdc): string
    {
        throw new \LogicException('AutomateProvider::pdf — pendiente de F1/F2 (KuDE)');
    }

    public function lookupCustomer(string $token, string $taxId): array
    {
        throw new \LogicException('AutomateProvider::lookupCustomer — pendiente de F3 (autocompletar RUC/CI)');
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Busca el token en las claves más probables de la respuesta de login.
     * El spec no tipa el shape — defensivo a propósito.
     */
    private function extractToken(array $raw): ?string
    {
        foreach (['token', 'accessToken', 'access_token', 'jwt'] as $key) {
            if (!empty($raw[$key]) && is_string($raw[$key])) {
                return $raw[$key];
            }
        }
        $data = $raw['data'] ?? null;
        if (is_array($data)) {
            foreach (['token', 'accessToken', 'access_token', 'jwt'] as $key) {
                if (!empty($data[$key]) && is_string($data[$key])) {
                    return $data[$key];
                }
            }
        }
        return null;
    }

    private function extractExpiry(array $raw): ?string
    {
        foreach (['expiresAt', 'expires_at', 'expiration'] as $key) {
            if (!empty($raw[$key]) && is_string($raw[$key])) {
                return $raw[$key];
            }
        }
        // Sin campo de expiración explícito en la respuesta: Automate documenta
        // JWT de 24 h — AutomateSession asume ese default cuando esto es null.
        return null;
    }

    /**
     * HTTP request con cURL. Lanza RuntimeException con el body de error en
     * excepciones HTTP no-2xx (para que EInvoiceService pueda mostrar el
     * motivo real, ej. "credenciales inválidas").
     */
    private function request(string $method, string $path, ?array $body, ?string $bearer): array
    {
        $ch = curl_init($this->baseUrl() . $path);

        $headers = ['Accept: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException("Automate $method $path — error de red: $err");
        }

        $json = json_decode((string) $resp, true);
        $json = is_array($json) ? $json : [];

        if ($code < 200 || $code >= 300) {
            // NUNCA el body crudo: el spec no tipa las respuestas, así que un error
            // de validación podría eco-ear el payload enviado (incluida la
            // contraseña del login). Este mensaje termina persistido en
            // einvoice_account.last_error y visible en el panel — solo campos
            // conocidos, y truncados.
            $msg = '';
            foreach (['message', 'error', 'detail'] as $key) {
                if (is_string($json[$key] ?? null) && $json[$key] !== '') {
                    $msg = mb_substr($json[$key], 0, 300);
                    break;
                }
            }
            if ($msg === '') {
                $msg = 'la API no devolvió un mensaje de error reconocible';
            }
            throw new \RuntimeException("Automate $method $path falló (HTTP $code): $msg");
        }

        return $json;
    }
}
