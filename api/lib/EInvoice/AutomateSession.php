<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Resuelve un bearer válido de Automate para una company, sin que el
 * caller (EInvoiceService, y más adelante el drainer de F1) tenga que
 * saber si hace falta loguear de nuevo.
 *
 * Automate no tiene "refresh silencioso": `refresh-credentials` vuelve a
 * pedir la contraseña, así que un re-`login` con las credenciales del
 * vault es equivalente y más simple — no vale la pena implementar un
 * endpoint de refresh aparte para ahorrarse un login que de todos modos
 * necesita la misma contraseña.
 *
 * Margen de 5 minutos antes de la expiración real: evita la carrera de
 * usar un token que expira a mitad de una request a Automate.
 */
final class AutomateSession
{
    private const EXPIRY_MARGIN_SECONDS = 5 * 60;
    private const DEFAULT_TTL_SECONDS   = 24 * 60 * 60; // Automate documenta JWT de 24 h.

    public function __construct(private readonly EInvoiceProvider $provider)
    {
    }

    /**
     * @throws \RuntimeException si no hay cuenta configurada o el login falla
     *         (mensaje apto para persistir en einvoice_account.last_error —
     *         ver EInvoiceService::testConnection).
     */
    public function getBearer(string $companyId): string
    {
        $row = ncmExecute(
            'SELECT username, password_enc, token_enc, token_expires_at FROM einvoice_account WHERE companyid = ?',
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

        $username = (string) ($row['username'] ?? '');
        $password = CredentialVault::decrypt((string) ($row['password_enc'] ?? ''));

        $result   = $this->provider->login($username, $password);
        $token    = (string) $result['token'];
        $expiryTs = $this->parseExpiry($result['expiresAt'] ?? null) ?? (time() + self::DEFAULT_TTL_SECONDS);

        ncmExecute(
            // to_timestamp(?::bigint) — el placeholder llega sin tipo inferido por el
            // driver; sin el cast explícito Postgres no puede resolver el overload de
            // to_timestamp() (solo existe para double precision / text+formato) y tira
            // "function to_timestamp(unknown) is not unique".
            'UPDATE einvoice_account SET token_enc = ?, token_expires_at = to_timestamp(?::bigint), updated_at = now() WHERE companyid = ?',
            [CredentialVault::encrypt($token), $expiryTs, $companyId]
        );

        return $token;
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
