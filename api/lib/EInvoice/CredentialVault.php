<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Cifrado reversible de credenciales (usuario/contraseña/teléfono, token)
 * del proveedor de facturación electrónica.
 *
 * Por qué reversible y no hasheado: la API de Factomate no tiene un
 * "refresh" silencioso — reautenticar es volver a encadenar `/Token`
 * (usuario+contraseña) → `PhoneLogin` (bearer + teléfono), así que hay
 * que poder recuperar tanto el password como el teléfono en texto plano
 * para volver a loguear cuando el token de 24 h expira. Un hash
 * unidireccional (como el password del usuario de Punto) no sirve acá.
 *
 * AES-256-GCM: cifrado autenticado (el tag detecta manipulación del
 * ciphertext), a diferencia del AES-128-ECB legacy de `head.php`
 * (enc/dec, sin IV, sin autenticación — no reusar, es criptográficamente
 * débil y además `enc()/dec()` son no-ops en este repo).
 *
 * Formato de salida: base64(iv[12] || tag[16] || ciphertext). El IV va
 * embebido para no necesitar una columna aparte, y se regenera en cada
 * encrypt() (nunca reusar IV con la misma clave en GCM).
 *
 * Clave: APP_ENCRYPTION_KEY (constante de simple.config.php, base64 de
 * 32 bytes). Sin clave válida el vault no arranca — falla explícito en
 * vez de guardar credenciales sin cifrar o con una clave débil derivada.
 */
final class CredentialVault
{
    private const CIPHER    = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const IV_BYTES  = 12;
    private const TAG_BYTES = 16;

    /** @throws \RuntimeException si APP_ENCRYPTION_KEY falta o es inválida. */
    private static function key(): string
    {
        $raw = defined('APP_ENCRYPTION_KEY') ? (string) constant('APP_ENCRYPTION_KEY') : '';
        if ($raw === '') {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY no está configurada — el vault de credenciales de facturación electrónica no puede operar.'
            );
        }

        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY inválida — debe ser base64 de exactamente 32 bytes.'
            );
        }

        return $key;
    }

    /** @throws \RuntimeException */
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv  = random_bytes(self::IV_BYTES);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('No se pudo cifrar la credencial.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /** @throws \RuntimeException */
    public static function decrypt(string $encoded): string
    {
        $key = self::key();
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Credencial cifrada con formato inválido.');
        }

        $iv         = substr($raw, 0, self::IV_BYTES);
        $tag        = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('No se pudo descifrar la credencial (clave incorrecta o dato corrupto).');
        }

        return $plaintext;
    }
}
