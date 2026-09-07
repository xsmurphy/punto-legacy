<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Credenciales del emisor contra FE-PY (el motor propio).
 *
 * Es la contracara de `FactomateSession` y es deliberadamente aburrida: FE-PY
 * no tiene login, ni tokens, ni expiración, ni cadena admin. Una API key de
 * COMPANY —global de Punto, en env— y el emisor identificado por su UUID en
 * el path. No hay nada que cachear ni que renovar, y por eso este archivo no
 * escribe una sola fila.
 *
 * Consecuencia que conviene tener presente: para un tenant de FE-PY,
 * `einvoice_account.token_enc` / `token_expires_at` / `password_enc` /
 * `phone_enc` / `login_enc` quedan SIN USO. No se limpian —son de Factomate y
 * el cutover es reversible por tenant, borrarlas convertiría el plan B en un
 * re-provisioning— pero nada de este camino las lee ni las escribe.
 */
final class FePySession implements EInvoiceSession
{
    /**
     * La API key de company, desde `FEPY_API_KEY`.
     *
     * Se valida el FORMATO acá (`cmp_` + 32 hex) y no se deja que falle del
     * otro lado: FE-PY chequea el formato ANTES de tocar su base y responde
     * 401 "Invalid API key format", un mensaje que en `last_error` no le dice
     * nada al operador. Una key mal pegada en Coolify es el caso probable, y
     * este mensaje la nombra.
     *
     * @throws \RuntimeException
     */
    public function getBearer(string $companyId): string
    {
        $key = defined('FEPY_API_KEY') ? trim((string) constant('FEPY_API_KEY')) : '';
        if ($key === '') {
            // Mensaje para el OPERADOR DE PUNTO: es un problema de infra
            // nuestro, no del comercio. Mismo criterio que el guard de
            // credencial admin en el provisioning de Factomate.
            throw new \RuntimeException(
                'El servicio de facturación electrónica no está disponible en este momento. ' .
                'Contactá a soporte de Punto (FEPY_API_KEY sin configurar).'
            );
        }
        if (preg_match('/^cmp_[0-9a-f]{32}$/', $key) !== 1) {
            throw new \RuntimeException(
                'El servicio de facturación electrónica no está disponible en este momento. ' .
                'Contactá a soporte de Punto (FEPY_API_KEY con formato inválido).'
            );
        }

        return $key;
    }

    /**
     * [tenantRef, environment].
     *
     * `provider_tenant_ref` (mig 206) es el UUID del emisor en FE-PY y va en
     * el path de todas sus rutas. Vacío significa que el provisioning no
     * llegó a crear el tenant: se corta con un mensaje que dice qué falta, en
     * vez de armar una URL `/v1/tenants//de` que del otro lado es un 404
     * ilegible.
     *
     * El `environment` se devuelve por contrato de la interfaz, pero en este
     * proveedor NO elige host: test/prod es el campo `env` del tenant, fijado
     * al darlo de alta. Ver el docblock de `FePyProvider`.
     */
    public function identity(string $companyId): array
    {
        $row = ncmExecute(
            'SELECT provider_tenant_ref, environment FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );
        // Ojo wrapper: ncmExecute devuelve un objeto array-like, no un array.
        $row = $row ?: [];
        if ($row === []) {
            throw new \RuntimeException('La cuenta de facturación electrónica no está configurada.');
        }

        $ref = trim((string) ($row['provider_tenant_ref'] ?? ''));
        if ($ref === '') {
            throw new \RuntimeException(
                'El emisor todavía no está dado de alta en el motor de facturación electrónica — ' .
                'completá la configuración fiscal antes de emitir.'
            );
        }

        return [$ref, (string) ($row['environment'] ?? 'test')];
    }
}
