<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Resuelve un bearer válido de Factomate para una company, sin que el
 * caller (EInvoiceService, drainer de F1) tenga que saber cómo se
 * autentica. Contrato: `getBearer($companyId)` devuelve un string listo
 * para `Authorization: Bearer`.
 *
 * F7 — cadena ADMIN (white-label, el camino por defecto):
 *
 *   POST /Token                  admin de Punto (env)         → bearer admin 15 min
 *   POST /api/account/PhoneLogin bearer admin + phonenumber   → bearer del USUARIO del tenant, 24 h
 *
 * La consecuencia arquitectónica (doc de integración de Automate §2,
 * confirmada por su código en producción — factomate-auth.service.ts
 * `resolveByPhoneViaAdmin`): con el bearer admin se emiten bearers de
 * usuario vía PhoneLogin SIN la contraseña del usuario final. Es lo que
 * permite ocultar Factomate por completo: el comercio no tiene credencial
 * que tipear, y Punto no necesita usar la contraseña por-tenant que
 * devolvió CreateExternal (queda en el vault solo como respaldo/portabilidad).
 *
 * El header `phonenumber` de PhoneLogin es la IDENTIDAD DE LOGIN del
 * usuario (verificado 2026-07-30: vuelve como `userName`), no un teléfono
 * literal — para usuarios provisionados por CreateExternal es su EMAIL.
 * Se guarda cifrada en `einvoice_account.phone_enc` (nombre heredado de
 * mig 95, documentado en mig 100).
 *
 * Fallback LEGACY (cuentas de F0 provisionadas a mano, ej. la de DEV):
 * sin credencial admin en env, o si la cuenta tiene contraseña propia en
 * el vault y la cadena admin falla, se usa la cadena vieja
 * token(usuario, contraseña) → phoneLogin. Se retira cuando ya no queden
 * cuentas manuales.
 *
 * Margen de 5 minutos antes de la expiración real: evita usar un token
 * que expira a mitad de una request.
 */
final class FactomateSession
{
    private const EXPIRY_MARGIN_SECONDS = 5 * 60;
    private const DEFAULT_TTL_SECONDS   = 24 * 60 * 60; // PhoneLogin documenta 24 h.

    /**
     * Cache por request del bearer ADMIN (15 min de vida real, pero un
     * request PHP dura segundos — un provisioning hace varias llamadas
     * admin seguidas y no tiene sentido pedir un token por cada una).
     * @var array<string,string> environment => bearer
     */
    private static array $adminBearer = [];

    public function __construct(private readonly EInvoiceProvider $provider)
    {
    }

    /**
     * Bearer ADMIN de Punto (usuario global sin tenant). Solo sirve para
     * CreateExternal y para emitir bearers de usuario vía PhoneLogin —
     * ninguna operación de tenant se hace con él.
     *
     * @throws \RuntimeException si la credencial admin no está configurada.
     */
    public function getAdminBearer(string $environment): string
    {
        if (isset(self::$adminBearer[$environment])) {
            return self::$adminBearer[$environment];
        }

        [$username, $password] = self::adminCredentials($environment);
        if ($username === '' || $password === '') {
            throw new \RuntimeException(
                'La credencial admin de Factomate no está configurada (FACTOMATE_ADMIN_USERNAME_' .
                strtoupper($environment) . ' / FACTOMATE_ADMIN_PASSWORD_' . strtoupper($environment) . ').'
            );
        }

        // El admin autentica solo con /Token (usuario global sin tenant, no
        // pasa por PhoneLogin) — mismo camino que Automate en producción
        // (efatech.token.ts). El header phonenumber lleva el propio username
        // admin como identidad de login.
        $step1 = $this->provider->token($environment, $username, $username, $password);
        $bearer = (string) $step1['token'];
        self::$adminBearer[$environment] = $bearer;
        return $bearer;
    }

    /** Login (username) de la credencial admin — para el header phonenumber de las llamadas admin. */
    public function getAdminLogin(string $environment): string
    {
        return self::adminCredentials($environment)[0];
    }

    /** true si hay credencial admin configurada para ese entorno. */
    public static function hasAdminCredentials(string $environment): bool
    {
        [$u, $p] = self::adminCredentials($environment);
        return $u !== '' && $p !== '';
    }

    /**
     * Bearer del USUARIO del tenant, cacheado en `token_enc` (24 h).
     *
     * @throws \RuntimeException si no hay cuenta, falta la identidad de
     *         login, o ninguna cadena de auth funciona (mensaje apto para
     *         persistir en einvoice_account.last_error).
     */
    public function getBearer(string $companyId): string
    {
        $row = ncmExecute(
            'SELECT username, password_enc, phone_enc, environment, token_enc, token_expires_at
               FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );
        if (!$row) {
            throw new \RuntimeException('La cuenta de facturación electrónica no está configurada.');
        }

        $tokenEnc  = (string) ($row['token_enc'] ?? '');
        $expiresAt = $row['token_expires_at'] ?? null;

        if ($tokenEnc !== '' && $this->stillValid($expiresAt)) {
            return CredentialVault::decrypt($tokenEnc);
        }

        $loginEnc = (string) ($row['phone_enc'] ?? '');
        if ($loginEnc === '') {
            throw new \RuntimeException('Falta la identidad de login del emisor — la cuenta no terminó de provisionarse.');
        }

        $environment = (string) ($row['environment'] ?? 'test');
        $login       = CredentialVault::decrypt($loginEnc);

        // Camino 1 — cadena ADMIN (white-label): PhoneLogin con el bearer
        // admin, sin contraseña del tenant.
        $adminError = null;
        if (self::hasAdminCredentials($environment)) {
            try {
                $adminBearer = $this->getAdminBearer($environment);
                $step2 = $this->provider->phoneLogin($environment, $login, $adminBearer);
                return $this->persistBearer($companyId, $step2);
            } catch (\RuntimeException $e) {
                // No se cae directo: si la cuenta tiene credencial propia
                // (F0 manual), el camino legacy todavía puede autenticarla.
                $adminError = $e->getMessage();
                error_log("[FactomateSession] cadena admin falló para $companyId: $adminError");
            }
        }

        // Camino 2 — LEGACY: credencial propia de la cuenta (vault).
        $passwordEnc = (string) ($row['password_enc'] ?? '');
        $username    = (string) ($row['username'] ?? '');
        if ($passwordEnc === '' || $username === '') {
            throw new \RuntimeException(
                $adminError !== null
                    ? "No se pudo autenticar con Factomate (admin): $adminError"
                    : 'La credencial admin de Factomate no está configurada y la cuenta no tiene credencial propia.'
            );
        }

        $password = CredentialVault::decrypt($passwordEnc);
        $step1 = $this->provider->token($environment, $login, $username, $password);
        $step2 = $this->provider->phoneLogin($environment, $login, (string) $step1['token']);
        return $this->persistBearer($companyId, $step2);
    }

    /** @param array{token:string,expiresAt:?string} $step2 */
    private function persistBearer(string $companyId, array $step2): string
    {
        $bearer   = (string) $step2['token'];
        $expiryTs = $this->parseExpiry($step2['expiresAt'] ?? null) ?? (time() + self::DEFAULT_TTL_SECONDS);

        ncmExecute(
            // to_timestamp(?::bigint) — sin el cast explícito Postgres no puede
            // resolver el overload de to_timestamp() y tira "is not unique".
            'UPDATE einvoice_account SET token_enc = ?, token_expires_at = to_timestamp(?::bigint), updated_at = now() WHERE companyid = ?',
            [CredentialVault::encrypt($bearer), $expiryTs, $companyId]
        );

        return $bearer;
    }

    /** @return array{0:string,1:string} [username, password] — vacíos si no configurados. */
    private static function adminCredentials(string $environment): array
    {
        $suffix = $environment === 'prod' ? '_PROD' : '_TEST';
        $u = defined('FACTOMATE_ADMIN_USERNAME' . $suffix) ? (string) constant('FACTOMATE_ADMIN_USERNAME' . $suffix) : '';
        $p = defined('FACTOMATE_ADMIN_PASSWORD' . $suffix) ? (string) constant('FACTOMATE_ADMIN_PASSWORD' . $suffix) : '';
        return [$u, $p];
    }

    private function stillValid(mixed $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }
        $ts = is_string($expiresAt) ? strtotime($expiresAt) : false;
        if ($ts === false) {
            return false;
        }
        return $ts > (time() + self::EXPIRY_MARGIN_SECONDS);
    }

    private function parseExpiry(?string $expiresAt): ?int
    {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }
        $ts = strtotime($expiresAt);
        return $ts === false ? null : $ts;
    }
}
