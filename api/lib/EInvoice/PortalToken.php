<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Token del portal de consulta del cliente final (F6,
 * context/28-facturacion-electronica-plan.md §Portal).
 *
 * Qué resuelve: el comprador tiene que poder abrir SU factura electrónica sin
 * cuenta, sin tipear nada y sin poder ver la de otro. El token va impreso en el
 * comprobante (texto + QR), así que tiene que ser corto, opaco y verificable
 * sin estado.
 *
 * Formato: `base64url( uuid_bin(company) ‖ uuid_bin(transaction) ‖ hmac[12] )`
 * — 44 bytes, 59 caracteres. Los UUID viajan en binario (16 bytes en vez de
 * los 36 del formato canónico) porque el token entra en un QR impreso en papel
 * térmico: menos densidad de módulos = menos lecturas fallidas.
 *
 * Por qué firmado y no un identificador random guardado en una columna: el
 * token es una FUNCIÓN de la venta, así que existe desde el momento en que la
 * venta se registra — se puede imprimir en el ticket sin esperar a que el
 * documento se emita (la emisión tarda segundos y puede reintentarse). Un
 * identificador persistido daría lo mismo en seguridad pero obligaría a una
 * migración y a que el ticket espere a la fila.
 *
 * La clave sale de `APP_ENCRYPTION_KEY` con separación de dominio (HKDF-ish:
 * `hmac('einvoice-portal-v1', APP_ENCRYPTION_KEY)`), no directo: la misma clave
 * cifra credenciales del proveedor, y una clave nunca debe usarse para dos
 * propósitos criptográficos distintos. Sin `APP_ENCRYPTION_KEY` no hay módulo
 * de facturación electrónica, así que el portal no puede quedar "medio activo".
 *
 * El token NO expira: identifica un documento fiscal, que es permanente. Lo que
 * sí hace el endpoint público es no exponer nada más que ese documento (ver
 * `api/v1/einvoice-public.php`).
 */
final class PortalToken
{
    /** Bytes de firma que se conservan. 12 bytes = 96 bits — de sobra contra falsificación, y corto para el QR. */
    private const SIG_BYTES = 12;

    private const KEY_CONTEXT = 'einvoice-portal-v1';

    /**
     * @throws \RuntimeException si falta APP_ENCRYPTION_KEY (módulo sin configurar).
     */
    public static function sign(string $companyId, string $transactionId): string
    {
        $payload = self::uuidToBin($companyId) . self::uuidToBin($transactionId);
        $raw = $payload . substr(hash_hmac('sha256', $payload, self::key(), true), 0, self::SIG_BYTES);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Verifica y decodifica. Devuelve `null` ante cualquier problema — token
     * mal formado, firma inválida o clave ausente son indistinguibles para
     * quien consulta: el endpoint público responde 404 en todos los casos, sin
     * pistas sobre cuál fue.
     *
     * @return array{companyId:string,transactionId:string}|null
     */
    public static function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[A-Za-z0-9\-_]+$/', $token)) {
            return null;
        }

        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || strlen($raw) !== 32 + self::SIG_BYTES) {
            return null;
        }

        $payload = substr($raw, 0, 32);
        $given   = substr($raw, 32);

        try {
            $expected = substr(hash_hmac('sha256', $payload, self::key(), true), 0, self::SIG_BYTES);
        } catch (\RuntimeException) {
            return null;
        }

        // hash_equals: comparación en tiempo constante, mismo criterio que el
        // secreto del drainer (api/v1/einvoice.php).
        if (!hash_equals($expected, $given)) {
            return null;
        }

        return [
            'companyId'     => self::binToUuid(substr($payload, 0, 16)),
            'transactionId' => self::binToUuid(substr($payload, 16, 16)),
        ];
    }

    /** @throws \RuntimeException */
    private static function key(): string
    {
        $master = defined('APP_ENCRYPTION_KEY') ? (string) APP_ENCRYPTION_KEY : '';
        if ($master === '') {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY no configurada — el portal de facturas no puede firmar ni verificar links.'
            );
        }
        return hash_hmac('sha256', self::KEY_CONTEXT, $master, true);
    }

    /** @throws \RuntimeException si el UUID no tiene el formato canónico. */
    private static function uuidToBin(string $uuid): string
    {
        $hex = str_replace('-', '', trim($uuid));
        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            throw new \RuntimeException("Identificador inválido para el token del portal: \"$uuid\".");
        }
        $bin = hex2bin($hex);
        if ($bin === false) {
            throw new \RuntimeException("Identificador inválido para el token del portal: \"$uuid\".");
        }
        return $bin;
    }

    private static function binToUuid(string $bin): string
    {
        $hex = bin2hex($bin);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
