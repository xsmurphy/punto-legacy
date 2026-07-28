<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Resuelve un bearer válido de Factomate para una company, sin que el
 * caller (EInvoiceService, y más adelante el drainer de F1) tenga que saber
 * si hace falta re-autenticar.
 *
 * A diferencia de AutomateSession (login de una sola llamada), acá "estar
 * logueado" son DOS llamadas encadenadas — `token()` (usuario+contraseña →
 * bearer de 15 min de un solo uso) seguido de `phoneLogin()` (ese bearer +
 * header phonenumber → bearer de 24 h) — pero el CONTRATO hacia afuera se
 * mantiene idéntico al de antes: `getBearer($companyId)` devuelve un string
 * listo para usar como `Authorization: Bearer`. Los callers no necesitan
 * saber que por dentro hay dos requests.
 *
 * Margen de 5 minutos antes de la expiración real: evita la carrera de usar
 * un token que expira a mitad de una request a Factomate.
 */
final class FactomateSession
{
    private const EXPIRY_MARGIN_SECONDS = 5 * 60;
    private const DEFAULT_TTL_SECONDS   = 24 * 60 * 60; // Factomate documenta bearer de 24 h en PhoneLogin.

    public function __construct(private readonly EInvoiceProvider $provider)
    {
    }

    /**
     * @throws \RuntimeException si no hay cuenta configurada, falta el
     *         teléfono del titular, o la cadena Token→PhoneLogin falla
     *         (mensaje apto para persistir en einvoice_account.last_error —
     *         ver EInvoiceService::testConnection).
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

        $phoneEnc = (string) ($row['phone_enc'] ?? '');
        if ($phoneEnc === '') {
            throw new \RuntimeException('Falta el teléfono del titular — es obligatorio para autenticar con Factomate.');
        }

        $environment = (string) ($row['environment'] ?? 'test');
        $phone       = CredentialVault::decrypt($phoneEnc);
        $username    = (string) ($row['username'] ?? '');
        $password    = CredentialVault::decrypt((string) ($row['password_enc'] ?? ''));

        $step1 = $this->provider->token($environment, $phone, $username, $password);
        $step2 = $this->provider->phoneLogin($environment, $phone, (string) $step1['token']);

        $bearer   = (string) $step2['token'];
        $expiryTs = $this->parseExpiry($step2['expiresAt'] ?? null) ?? (time() + self::DEFAULT_TTL_SECONDS);

        ncmExecute(
            // to_timestamp(?::bigint) — el placeholder llega sin tipo inferido por el
            // driver; sin el cast explícito Postgres no puede resolver el overload de
            // to_timestamp() (solo existe para double precision / text+formato) y tira
            // "function to_timestamp(unknown) is not unique".
            'UPDATE einvoice_account SET token_enc = ?, token_expires_at = to_timestamp(?::bigint), updated_at = now() WHERE companyid = ?',
            [CredentialVault::encrypt($bearer), $expiryTs, $companyId]
        );

        return $bearer;
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
