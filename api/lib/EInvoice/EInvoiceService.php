<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Orquestación de F0: conectar/probar la cuenta de Factomate de un
 * comercio y leer el timbrado vigente. F1 agrega enqueue/drain/cancel/retry
 * sobre `einvoice_document` — no viven acá todavía (ver
 * context/28-facturacion-electronica-plan.md).
 *
 * Nota sobre `einvoice_account.config`: es una columna JSONB real (no el
 * patrón legacy de `data`/`meta`/`config` que Query::flattenJsonb() aplana
 * automáticamente en TODA fila leída por ncmExecute — ver
 * api/lib/App/Database/Query.php:52). Si se selecciona la columna con su
 * nombre literal `config`, flattenJsonb la de-estructura y la borra del
 * resultado. Por eso el SELECT la alias-ea (`config AS account_config`) —
 * evita pisar el helper compartido (1035+ callers) por una colisión de
 * nombre de una tabla nueva. `stamp`/`emitter` no colisionan con esos
 * nombres mágicos, así que no necesitan alias.
 */
final class EInvoiceService
{
    private EInvoiceProvider $provider;
    private FactomateSession $session;

    public function __construct(?EInvoiceProvider $provider = null)
    {
        $this->provider = $provider ?? new FactomateProvider();
        $this->session  = new FactomateSession($this->provider);
    }

    /** Shape estable aunque no haya cuenta configurada — el frontend no rama por null. */
    public function getAccount(string $companyId): array
    {
        $row = ncmExecute(
            'SELECT provider, username, phone_enc, environment, status, emitter, stamp, stamp_synced_at,
                    last_check_at, last_error, config AS account_config
               FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );

        if (!$row) {
            return [
                'configured'    => false,
                'provider'      => 'factomate',
                'username'      => '',
                'phone'         => '',
                'environment'   => 'test',
                'status'        => 'unconfigured',
                'emitter'       => [],
                'stamp'         => [],
                'stampSyncedAt' => null,
                'lastCheckAt'   => null,
                'lastError'     => null,
                'config'        => [],
            ];
        }

        return [
            'configured'  => true,
            'provider'    => (string) ($row['provider'] ?? 'factomate'),
            'username'    => (string) ($row['username'] ?? ''),
            // Se devuelve DESCIFRADO (a diferencia de la contraseña, que
            // nunca vuelve): el teléfono no es secreto en sí mismo — es un
            // factor de auth (header phonenumber) igual que el usuario, no
            // una clave que desbloquea la cuenta por sí sola. Cifrarlo en
            // BD protege un dump/backup; el operador autenticado de su
            // propia cuenta ya lo conoce, y necesita verlo para poder
            // editar el resto sin tener que re-tipearlo cada vez.
            'phone'       => $this->tryDecryptPhone($row['phone_enc'] ?? null) ?? '',
            'environment' => (string) ($row['environment'] ?? 'test'),
            'status'      => (string) ($row['status'] ?? 'unconfigured'),
            'emitter'     => $this->decodeJsonb($row['emitter'] ?? null),
            // Timbrado vigente cacheado de sincro/config — se LEE, no se
            // crea (ver FactomateProvider::sincroConfig).
            'stamp'         => $this->decodeJsonb($row['stamp'] ?? null),
            'stampSyncedAt' => $row['stamp_synced_at'] ?? null,
            'lastCheckAt'   => $row['last_check_at'] ?? null,
            'lastError'     => $row['last_error'] ?? null,
            'config'        => $this->decodeJsonb($row['account_config'] ?? null),
        ];
    }

    /**
     * Crea o actualiza la cuenta. $password null/'' = conservar la guardada
     * (el frontend nunca la trae de vuelta, así que "no tocar" es el
     * default cuando el campo viene vacío) — a diferencia de username/phone/
     * environment, que SIEMPRE vienen del form porque getAccount() los
     * devuelve en claro y el frontend los pre-carga.
     *
     * Cambiar cualquiera de los cuatro (usuario, contraseña, teléfono,
     * entorno) invalida el bearer cacheado: quedó emitido para una
     * combinación de credenciales o un HOST (test≠prod son hosts distintos)
     * que ya no aplica. Una credencial nueva sin probar no puede quedar
     * mostrando "Conectado".
     *
     * @throws \RuntimeException
     */
    public function saveAccount(
        string $companyId,
        string $username,
        ?string $password,
        string $phone,
        string $environment,
        array $config
    ): array {
        $username = trim($username);
        if ($username === '') {
            throw new \RuntimeException('El usuario de Factomate es obligatorio.');
        }

        if (!in_array($environment, ['test', 'prod'], true)) {
            throw new \RuntimeException('Entorno inválido (esperado: test o prod).');
        }

        // phoneValidateForStorage (api/includes/phone.php) es el helper
        // canónico del proyecto: valida con libphonenumber y normaliza a
        // storage SIN '+' (convención del repo, ver
        // feedback_phone_storage_no_plus) — se reusa en vez de inventar
        // otra normalización acá. require_once defensivo porque no todo
        // caller de EInvoiceService pasa por functions.php (que ya lo
        // incluye) — mismo patrón que ContactService/UsersService.
        require_once dirname(__DIR__, 3) . '/api/includes/phone.php';
        $phoneForStorage = phoneValidateForStorage($phone, 'PY', 'El teléfono del titular no es un número válido.');
        if ($phoneForStorage === null) {
            throw new \RuntimeException('El teléfono del titular es obligatorio.');
        }

        $existing = ncmExecute(
            'SELECT username, phone_enc, environment FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );

        $passwordChanged = $password !== null && $password !== '';
        $existingPhone    = $existing ? $this->tryDecryptPhone($existing['phone_enc'] ?? null) : null;
        $usernameChanged  = $existing && $username !== (string) ($existing['username'] ?? '');
        $phoneChanged     = $existing && $phoneForStorage !== $existingPhone;
        $environmentChanged = $existing && $environment !== (string) ($existing['environment'] ?? 'test');
        $credentialsChanged = $passwordChanged || $usernameChanged || $phoneChanged || $environmentChanged;

        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);
        $phoneEnc   = CredentialVault::encrypt($phoneForStorage);

        if (!$existing) {
            if (!$passwordChanged) {
                throw new \RuntimeException('La contraseña es obligatoria para conectar la cuenta por primera vez.');
            }
            ncmExecute(
                "INSERT INTO einvoice_account (companyid, provider, username, password_enc, phone_enc, environment, status, config)
                 VALUES (?, 'factomate', ?, ?, ?, ?, 'unconfigured', ?::jsonb)",
                [$companyId, $username, CredentialVault::encrypt($password), $phoneEnc, $environment, $configJson]
            );
        } elseif ($credentialsChanged) {
            if ($passwordChanged) {
                ncmExecute(
                    "UPDATE einvoice_account
                        SET username = ?, password_enc = ?, phone_enc = ?, environment = ?,
                            token_enc = NULL, token_expires_at = NULL,
                            status = 'unconfigured', last_error = NULL, config = ?::jsonb, updated_at = now()
                      WHERE companyid = ?",
                    [$username, CredentialVault::encrypt($password), $phoneEnc, $environment, $configJson, $companyId]
                );
            } else {
                // Usuario/teléfono/entorno cambiaron pero la contraseña no:
                // se conserva password_enc, se pisa el resto y se descarta
                // el token — vuelve a "sin probar".
                ncmExecute(
                    "UPDATE einvoice_account
                        SET username = ?, phone_enc = ?, environment = ?,
                            token_enc = NULL, token_expires_at = NULL,
                            status = 'unconfigured', last_error = NULL, config = ?::jsonb, updated_at = now()
                      WHERE companyid = ?",
                    [$username, $phoneEnc, $environment, $configJson, $companyId]
                );
            }
        } else {
            // Nada de lo que invalida el bearer cambió (ej. solo se tocó
            // autoIssue/onlyWithTaxId) — se actualiza config sin resetear status.
            ncmExecute(
                'UPDATE einvoice_account SET username = ?, config = ?::jsonb, updated_at = now() WHERE companyid = ?',
                [$username, $configJson, $companyId]
            );
        }

        return $this->getAccount($companyId);
    }

    /**
     * Cadena completa de F0: Token → PhoneLogin → GetUserInfo → sincro/config.
     * Persiste status/emitter/stamp/stamp_synced_at/last_error. Nunca deja
     * la excepción escapar sin persistir el intento: el operador tiene que
     * ver "Error de autenticación" en vez de un 500 mudo.
     *
     * Sin timbrado vigente no se puede facturar — si sincro/config no trae
     * `stamps[0]`, el resultado es 'auth_error' aunque el login haya sido
     * exitoso (no es un error de autenticación real, pero el efecto para
     * el operador es el mismo: la cuenta no está lista para emitir).
     */
    public function testConnection(string $companyId): array
    {
        $account = ncmExecute('SELECT companyid FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$account) {
            throw new \RuntimeException('Conectá la cuenta de Factomate antes de probar la conexión.');
        }

        try {
            $bearer = $this->session->getBearer($companyId);
            [$phone, $environment] = $this->phoneAndEnvironment($companyId);

            $emitter = $this->provider->userInfo($environment, $phone, $bearer);
            $sincro  = $this->provider->sincroConfig($environment, $phone, $bearer);
            $stamp   = $this->extractStamp($sincro);

            if ($stamp === null) {
                $message = 'Factomate no devolvió un timbrado vigente para esta cuenta — sin timbrado no se puede '
                    . 'facturar. El timbrado se provisiona del lado de Factomate, contactalos para que lo asignen.';
                ncmExecute(
                    "UPDATE einvoice_account
                        SET status = 'auth_error', emitter = ?::jsonb, last_check_at = now(), last_error = ?, updated_at = now()
                      WHERE companyid = ?",
                    [json_encode($emitter, JSON_UNESCAPED_UNICODE), $message, $companyId]
                );
                return ['status' => 'auth_error', 'emitter' => $emitter, 'stamp' => [], 'lastError' => $message];
            }

            ncmExecute(
                "UPDATE einvoice_account
                    SET status = 'ok', emitter = ?::jsonb, stamp = ?::jsonb, stamp_synced_at = now(),
                        last_check_at = now(), last_error = NULL, updated_at = now()
                  WHERE companyid = ?",
                [json_encode($emitter, JSON_UNESCAPED_UNICODE), json_encode($stamp, JSON_UNESCAPED_UNICODE), $companyId]
            );

            return ['status' => 'ok', 'emitter' => $emitter, 'stamp' => $stamp, 'lastError' => null];
        } catch (\Throwable $e) {
            // $e->getMessage() nunca incluye la contraseña ni el bearer
            // (FactomateProvider/FactomateSession no los interpolan en
            // excepciones) — seguro de persistir.
            $message = $e->getMessage();
            ncmExecute(
                "UPDATE einvoice_account
                    SET status = 'auth_error', last_check_at = now(), last_error = ?, updated_at = now()
                  WHERE companyid = ?",
                [$message, $companyId]
            );

            return ['status' => 'auth_error', 'emitter' => [], 'stamp' => [], 'lastError' => $message];
        }
    }

    /** @throws \RuntimeException si la cuenta no está conectada (status != 'ok'). */
    public function paymentMethods(string $companyId): array
    {
        $row = ncmExecute('SELECT status FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$row || (string) ($row['status'] ?? '') !== 'ok') {
            throw new \RuntimeException('La cuenta de facturación electrónica no está conectada.');
        }

        $bearer = $this->session->getBearer($companyId);
        [$phone, $environment] = $this->phoneAndEnvironment($companyId);
        return $this->provider->paymentMethods($environment, $phone, $bearer);
    }

    /**
     * @return array{0: string, 1: string} [$phone, $environment] descifrados/listos para pasar al provider.
     * @throws \RuntimeException si falta el teléfono (cuenta a medio configurar).
     */
    private function phoneAndEnvironment(string $companyId): array
    {
        $row   = ncmExecute('SELECT phone_enc, environment FROM einvoice_account WHERE companyid = ?', [$companyId]);
        $phone = $this->tryDecryptPhone($row['phone_enc'] ?? null);
        if ($phone === null) {
            throw new \RuntimeException('Falta el teléfono del titular — es obligatorio para autenticar con Factomate.');
        }
        return [$phone, (string) ($row['environment'] ?? 'test')];
    }

    /**
     * `stamps[0]` de la respuesta de sincro/config. El shape no está
     * tipado en la guía (que a veces usa lowercase — "stamps[0]" — y en
     * otro punto documenta PascalCase para otro endpoint — "Items[0].CDC")
     * así que se prueban ambos casings y el envoltorio `data`/`Data`, igual
     * criterio defensivo que extractToken().
     */
    private function extractStamp(array $sincro): ?array
    {
        foreach (['stamps', 'Stamps'] as $key) {
            if (is_array($sincro[$key] ?? null) && is_array($sincro[$key][0] ?? null)) {
                return $sincro[$key][0];
            }
        }
        $wrapped = $sincro['data'] ?? $sincro['Data'] ?? null;
        if (is_array($wrapped)) {
            foreach (['stamps', 'Stamps'] as $key) {
                if (is_array($wrapped[$key] ?? null) && is_array($wrapped[$key][0] ?? null)) {
                    return $wrapped[$key][0];
                }
            }
        }
        return null;
    }

    private function tryDecryptPhone(mixed $enc): ?string
    {
        $enc = (string) ($enc ?? '');
        if ($enc === '') {
            return null;
        }
        try {
            return CredentialVault::decrypt($enc);
        } catch (\Throwable $e) {
            return null;
        }
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
