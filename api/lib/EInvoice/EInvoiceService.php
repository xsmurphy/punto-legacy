<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Orquestación de F0: conectar/probar la cuenta de Automate de un comercio.
 * F1 agrega enqueue/drain/cancel/retry sobre `einvoice_document` — no viven
 * acá todavía (ver context/28-facturacion-electronica-plan.md).
 *
 * Nota sobre `einvoice_account.config`: es una columna JSONB real (no el
 * patrón legacy de `data`/`meta`/`config` que Query::flattenJsonb() aplana
 * automáticamente en TODA fila leída por ncmExecute — ver
 * api/lib/App/Database/Query.php:52). Si se selecciona la columna con su
 * nombre literal `config`, flattenJsonb la de-estructura y la borra del
 * resultado. Por eso el SELECT la alias-ea (`config AS account_config`) —
 * evita pisar el helper compartido (1035+ callers) por una colisión de
 * nombre de una tabla nueva.
 */
final class EInvoiceService
{
    private EInvoiceProvider $provider;
    private AutomateSession $session;

    public function __construct(?EInvoiceProvider $provider = null)
    {
        $this->provider = $provider ?? new AutomateProvider();
        $this->session  = new AutomateSession($this->provider);
    }

    /** Shape estable aunque no haya cuenta configurada — el frontend no rama por null. */
    public function getAccount(string $companyId): array
    {
        $row = ncmExecute(
            'SELECT provider, username, status, emitter, last_check_at, last_error, config AS account_config
               FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );

        if (!$row) {
            return [
                'configured'  => false,
                'provider'    => 'automate',
                'username'    => '',
                'status'      => 'unconfigured',
                'emitter'     => [],
                'lastCheckAt' => null,
                'lastError'   => null,
                'config'      => [],
            ];
        }

        return [
            'configured'  => true,
            'provider'    => (string) ($row['provider'] ?? 'automate'),
            'username'    => (string) ($row['username'] ?? ''),
            'status'      => (string) ($row['status'] ?? 'unconfigured'),
            'emitter'     => $this->decodeJsonb($row['emitter'] ?? null),
            'lastCheckAt' => $row['last_check_at'] ?? null,
            'lastError'   => $row['last_error'] ?? null,
            'config'      => $this->decodeJsonb($row['account_config'] ?? null),
        ];
    }

    /**
     * Crea o actualiza la cuenta. $password null/'' = conservar la guardada
     * (el frontend nunca la trae de vuelta, así que "no tocar" es el default
     * cuando el campo viene vacío). Cambiar usuario o contraseña resetea
     * status a 'unconfigured' y descarta el token cacheado — una credencial
     * nueva sin probar no puede quedar mostrando "Conectado".
     *
     * @throws \RuntimeException
     */
    public function saveAccount(string $companyId, string $username, ?string $password, array $config): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new \RuntimeException('El usuario de Automate es obligatorio.');
        }

        $existing = ncmExecute(
            'SELECT username FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );

        // El token cacheado pertenece a UN usuario de Automate. Cambiar el
        // username sin resetear dejaría la cuenta mostrando "Conectado" con un
        // bearer emitido para el usuario anterior — por eso el reset dispara
        // tanto con contraseña nueva como con usuario distinto al guardado.
        $passwordChanged = $password !== null && $password !== '';
        $usernameChanged = $existing && $username !== (string) ($existing['username'] ?? '');
        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);

        if (!$existing) {
            if (!$passwordChanged) {
                throw new \RuntimeException('La contraseña es obligatoria para conectar la cuenta por primera vez.');
            }
            ncmExecute(
                "INSERT INTO einvoice_account (companyid, provider, username, password_enc, status, config)
                 VALUES (?, 'automate', ?, ?, 'unconfigured', ?::jsonb)",
                [$companyId, $username, CredentialVault::encrypt($password), $configJson]
            );
        } elseif ($passwordChanged) {
            ncmExecute(
                "UPDATE einvoice_account
                    SET username = ?, password_enc = ?, token_enc = NULL, token_expires_at = NULL,
                        status = 'unconfigured', last_error = NULL, config = ?::jsonb, updated_at = now()
                  WHERE companyid = ?",
                [$username, CredentialVault::encrypt($password), $configJson, $companyId]
            );
        } elseif ($usernameChanged) {
            // Usuario nuevo, contraseña sin tocar: se conserva password_enc pero
            // el token del usuario viejo se descarta y la cuenta vuelve a "sin probar".
            ncmExecute(
                "UPDATE einvoice_account
                    SET username = ?, token_enc = NULL, token_expires_at = NULL,
                        status = 'unconfigured', last_error = NULL, config = ?::jsonb, updated_at = now()
                  WHERE companyid = ?",
                [$username, $configJson, $companyId]
            );
        } else {
            ncmExecute(
                'UPDATE einvoice_account SET username = ?, config = ?::jsonb, updated_at = now() WHERE companyid = ?',
                [$username, $configJson, $companyId]
            );
        }

        return $this->getAccount($companyId);
    }

    /**
     * Login + `/auth/me` — persiste status/emitter/last_check_at/last_error.
     * Nunca deja la excepción escapar sin persistir el intento: el operador
     * tiene que ver "Error de autenticación" en vez de un 500 mudo.
     */
    public function testConnection(string $companyId): array
    {
        $account = ncmExecute('SELECT companyid FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$account) {
            throw new \RuntimeException('Conectá la cuenta de Automate antes de probar la conexión.');
        }

        try {
            $token   = $this->session->getBearer($companyId);
            $emitter = $this->provider->me($token);

            ncmExecute(
                "UPDATE einvoice_account
                    SET status = 'ok', emitter = ?::jsonb, last_check_at = now(), last_error = NULL, updated_at = now()
                  WHERE companyid = ?",
                [json_encode($emitter, JSON_UNESCAPED_UNICODE), $companyId]
            );

            return ['status' => 'ok', 'emitter' => $emitter, 'lastError' => null];
        } catch (\Throwable $e) {
            // $e->getMessage() nunca incluye la contraseña (AutomateProvider/
            // AutomateSession no la interpolan en excepciones) — seguro de persistir.
            $message = $e->getMessage();
            ncmExecute(
                "UPDATE einvoice_account
                    SET status = 'auth_error', last_check_at = now(), last_error = ?, updated_at = now()
                  WHERE companyid = ?",
                [$message, $companyId]
            );

            return ['status' => 'auth_error', 'emitter' => [], 'lastError' => $message];
        }
    }

    /** @throws \RuntimeException si la cuenta no está conectada (status != 'ok'). */
    public function paymentMethods(string $companyId): array
    {
        $row = ncmExecute('SELECT status FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$row || (string) ($row['status'] ?? '') !== 'ok') {
            throw new \RuntimeException('La cuenta de facturación electrónica no está conectada.');
        }

        $token = $this->session->getBearer($companyId);
        return $this->provider->paymentMethods($token);
    }

    private function decodeJsonb(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) ($value ?? '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }
}
