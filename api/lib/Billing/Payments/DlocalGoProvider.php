<?php
declare(strict_types=1);

namespace Punto\Api\Billing\Payments;

/**
 * Proveedor de pagos dLocal Go (https://dlocalgo.com).
 *
 * V1: checkout hosteado (payment_method_flow = REDIRECT) para compra de packs
 * de créditos. El usuario se redirige a la página de dLocal, paga, y dLocal
 * notifica vía webhook → el backend RE-CONSULTA el pago (no confía en el body)
 * y acredita.
 *
 * Seguridad:
 *   - Webhook verificado con HMAC-SHA256 sobre el body crudo (hash_equals,
 *     fail-closed si no hay secret).
 *   - getPayment() re-consulta el estado real a la API de dLocal — un atacante
 *     no puede forjar un "paid".
 *
 * Config (constantes definidas en simple.config.php desde $_ENV):
 *   DLOCAL_GO_API_KEY, DLOCAL_GO_SECRET_KEY, DLOCAL_GO_WEBHOOK_SECRET,
 *   DLOCAL_GO_ENVIRONMENT ('sandbox'|'production'), DLOCAL_GO_BASE_URL (override).
 */
final class DlocalGoProvider
{
    private const SANDBOX_URL = 'https://api-sbx.dlocalgo.com/v1';
    private const PROD_URL    = 'https://api.dlocalgo.com/v1';
    private const TIMEOUT     = 20;

    /** Lee config: prioriza constante (simple.config.php) → getenv → $_ENV. */
    private function cfg(string $key, string $default = ''): string
    {
        if (defined($key)) {
            return (string) constant($key);
        }
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return (string) $v;
        }
        return (string) ($_ENV[$key] ?? $default);
    }

    private function apiKey(): string        { return $this->cfg('DLOCAL_GO_API_KEY'); }
    private function secretKey(): string     { return $this->cfg('DLOCAL_GO_SECRET_KEY'); }
    private function webhookSecret(): string { return $this->cfg('DLOCAL_GO_WEBHOOK_SECRET'); }

    private function baseUrl(): string
    {
        $override = $this->cfg('DLOCAL_GO_BASE_URL');
        if ($override !== '') {
            return rtrim($override, '/');
        }
        $env = strtolower($this->cfg('DLOCAL_GO_ENVIRONMENT', 'sandbox'));
        return $env === 'production' ? self::PROD_URL : self::SANDBOX_URL;
    }

    /** El feature está disponible solo si hay api key + secret. */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->secretKey() !== '';
    }

    /**
     * Crea un pago/checkout. Devuelve {providerPaymentId, redirectUrl, rawStatus}.
     * $args: amount, currency, country, orderId, description, successUrl, backUrl, notificationUrl.
     */
    public function createPayment(array $args): array
    {
        $body = [
            'amount'              => $args['amount'],
            'currency'            => $args['currency'],
            'country'             => $args['country'],
            'order_id'            => $args['orderId'],
            'description'         => $args['description'],
            'payment_method_flow' => 'REDIRECT',
            'success_url'         => $args['successUrl'],
            'back_url'            => $args['backUrl'],
            'notification_url'    => $args['notificationUrl'],
        ];

        $r = $this->request('POST', '/payments', $body);

        return [
            'providerPaymentId' => (string) ($r['id'] ?? ''),
            'redirectUrl'       => (string) ($r['redirect_url'] ?? ''),
            'rawStatus'         => (string) ($r['status'] ?? ''),
        ];
    }

    /**
     * Re-consulta un pago a dLocal (fuente de verdad — NO confiar en el webhook body).
     * Devuelve {providerPaymentId, status (normalizado), orderId, amount, currency, raw}.
     */
    public function getPayment(string $providerPaymentId): array
    {
        $r = $this->request('GET', '/payments/' . rawurlencode($providerPaymentId), null);

        return [
            'providerPaymentId' => (string) ($r['id'] ?? $providerPaymentId),
            'status'            => $this->normalizeStatus((string) ($r['status'] ?? '')),
            'orderId'           => (string) ($r['order_id'] ?? ''),
            'amount'            => (float)  ($r['amount'] ?? 0),
            'currency'          => (string) ($r['currency'] ?? ''),
            'raw'               => $r,
        ];
    }

    /**
     * Verifica la firma HMAC-SHA256 del webhook sobre el body CRUDO.
     * Fail-closed: sin secret configurado → rechaza. $headers = $_SERVER.
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        $secret = $this->webhookSecret();
        if ($secret === '') {
            return false; // fail-closed
        }

        $provided = '';
        foreach (['HTTP_X_SIGNATURE', 'HTTP_X_DLOCAL_SIGNATURE', 'HTTP_SIGNATURE'] as $h) {
            if (!empty($headers[$h])) {
                $provided = trim((string) $headers[$h]);
                break;
            }
        }
        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $provided);
    }

    /** Extrae el payment id del body del webhook. */
    public function extractPaymentId(array $body): ?string
    {
        foreach (['payment_id', 'id'] as $k) {
            if (!empty($body[$k])) {
                return (string) $body[$k];
            }
        }
        return null;
    }

    /** Normaliza el estado de dLocal a paid|pending|failed. */
    private function normalizeStatus(string $status): string
    {
        $u = strtoupper(trim($status));
        if (in_array($u, ['PAID', 'AUTHORIZED', 'APPROVED'], true)) {
            return 'paid';
        }
        if (in_array($u, ['REJECTED', 'CANCELLED', 'CANCELED', 'EXPIRED'], true)) {
            return 'failed';
        }
        return 'pending';
    }

    /** HTTP request con cURL. Lanza RuntimeException en error HTTP. */
    private function request(string $method, string $path, ?array $body): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey() . ':' . $this->secretKey(),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException(
                "dLocal $method $path falló (HTTP $code): " . ($err !== '' ? $err : (string) $resp)
            );
        }

        $json = json_decode((string) $resp, true);
        return is_array($json) ? $json : [];
    }
}
