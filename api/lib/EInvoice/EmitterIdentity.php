<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Las DOS identidades del emisor ante Factomate, y la reparación de las
 * filas que se escribieron cuando creíamos que era una sola.
 *
 * ── Por qué son dos ──────────────────────────────────────────────────
 *
 *   LOGIN (`login_enc`)  El UserName del usuario del tenant — para los
 *                        usuarios que crea `CreateExternal`, su EMAIL.
 *                        Viaja en el header `phonenumber` de TODAS las
 *                        llamadas autenticadas y en el `/Token`.
 *                        Verificado 2026-07-30: el header vuelve como
 *                        `userName` (mig 100).
 *
 *   CELULAR (`phone_enc`) El teléfono del dueño del comercio. Es la
 *                        identidad de `POST /api/account/PhoneLogin` y
 *                        NADA MÁS. Verificado 2026-09-07 (fix 2d828eaf):
 *                        con el email, PhoneLogin devuelve 500.
 *
 * Mientras las dos salieron de `phone_enc`, cada corrección rompía a la
 * otra. La mig 205 separa las columnas; esta clase es el único lugar que
 * sabe cuál se usa para qué, así que ningún call-site vuelve a elegir mal.
 *
 * ── Self-healing ─────────────────────────────────────────────────────
 *
 * El backfill no se puede hacer en SQL: los valores están cifrados con la
 * clave de `CredentialVault` y Postgres no puede mirarlos para decidir
 * cuál es cuál. Se repara en PHP, en el primer uso, y es idempotente.
 * Tres formas de fila conviven en producción:
 *
 *   A) Post-205 — `login_enc` = email, `phone_enc` = celular. Nada que hacer.
 *
 *   B) Mig 100 hasta 2d828eaf — `phone_enc` contiene el EMAIL y no hay
 *      `login_enc`. El email es la identidad de login CORRECTA, así que se
 *      copia a `login_enc`; el celular se resuelve del dueño y se escribe
 *      en `phone_enc`.
 *
 *   C) Entre 2d828eaf y la 205 — `phone_enc` ya tiene el CELULAR (bien) y
 *      `login_enc` está vacía, pero el email sigue disponible en
 *      `username` (lo escribe `ensureTenantCreated`). Se promueve de ahí.
 *
 * La detección del caso B es el `@`: un celular no lo tiene y un UserName
 * de CreateExternal es siempre un email. No se adivina nada más — si la
 * heurística no aplica, se cae al valor que haya, que es el mismo
 * comportamiento que antes de esta clase.
 *
 * La reparación NUNCA pisa un valor bueno: solo escribe columnas vacías o
 * un `phone_enc` que se probó que es un email.
 */
final class EmitterIdentity
{
    /**
     * Identidad de LOGIN — el header `phonenumber` de toda llamada del
     * tenant y el usuario de `/Token`.
     *
     * Orden de resolución (el primero que exista gana):
     *   1. `login_enc` — ya reparada.
     *   2. `phone_enc` si tiene '@' — caso B, es el email.
     *   3. `username` — caso C, el email que guardó CreateExternal.
     *   4. `phone_enc` tal cual — última red, mismo valor que usaba el
     *      código antes de la mig 205.
     *
     * @throws \RuntimeException si no hay cuenta o no hay ninguna identidad.
     */
    public static function login(string $companyId): string
    {
        $row = self::row($companyId);

        $login = self::tryDecrypt($row['login_enc'] ?? null);
        if ($login !== null && $login !== '') {
            return $login;
        }

        $phone    = self::tryDecrypt($row['phone_enc'] ?? null) ?? '';
        $username = trim((string) ($row['username'] ?? ''));

        $resolved = '';
        if (self::looksLikeEmail($phone)) {
            $resolved = $phone;              // caso B
        } elseif ($username !== '') {
            $resolved = $username;           // caso C
        } elseif ($phone !== '') {
            $resolved = $phone;              // última red
        }

        if ($resolved === '') {
            throw new \RuntimeException(
                'Falta la identidad de login del emisor — la cuenta no terminó de provisionarse.'
            );
        }

        self::persistLogin($companyId, $resolved);
        return $resolved;
    }

    /**
     * CELULAR del dueño — identidad de `PhoneLogin`, y de nada más.
     *
     * Si `phone_enc` resulta ser un email (caso B), lo promueve a
     * `login_enc` si esa columna está vacía, resuelve el celular del dueño
     * y reescribe `phone_enc` con él. A partir de ahí la fila queda
     * reparada y las llamadas siguientes salen por el camino directo.
     *
     * @throws \RuntimeException si no hay cuenta, o si hay que reparar y el
     *         dueño no tiene celular cargado (mensaje apto para mostrarle
     *         al operador y para persistir en `last_error`).
     */
    public static function phone(string $companyId): string
    {
        $row   = self::row($companyId);
        $phone = self::tryDecrypt($row['phone_enc'] ?? null) ?? '';

        if ($phone !== '' && !self::looksLikeEmail($phone)) {
            return $phone; // caso A o C — ya es el celular.
        }

        // Caso B: `phone_enc` guarda el email. Antes de pisarlo, se
        // rescata como identidad de login si esa columna está vacía —
        // perderlo dejaría a la cuenta sin el UserName del header.
        if (self::looksLikeEmail($phone)) {
            $existingLogin = self::tryDecrypt($row['login_enc'] ?? null) ?? '';
            if ($existingLogin === '') {
                self::persistLogin($companyId, $phone);
            }
        }

        $ownerPhone = self::ownerPhone($companyId);
        if ($ownerPhone === '') {
            throw new \RuntimeException(
                'El dueño del comercio no tiene un celular válido cargado — es la identidad de acceso del emisor ' .
                'ante el proveedor de facturación electrónica. Cargalo en su ficha de usuario y reintentá.'
            );
        }

        ncmExecute(
            'UPDATE einvoice_account SET phone_enc = ?, updated_at = now() WHERE companyid = ?',
            [CredentialVault::encrypt($ownerPhone), $companyId]
        );

        return $ownerPhone;
    }

    /**
     * Celular del DUEÑO del comercio (contact type=0 con rol de dueño, el
     * más antiguo — el que registró la cuenta), sin '+' (convención de
     * storage del repo).
     *
     * Vive acá y no en `EInvoiceProvisioningService` porque lo necesitan
     * los dos: el alta (para mandarlo en `CreateExternal`) y la reparación
     * de arriba. Tener el query duplicado garantizaba que un día
     * divergieran y el emisor quedara registrado con un teléfono y
     * autenticándose con otro.
     */
    public static function ownerPhone(string $companyId): string
    {
        $row = ncmExecute(
            'SELECT contactphone FROM contact c
              WHERE companyid = ? AND type = 0 AND ' . \RoleService::ownerRoleSql('c') . '
              ORDER BY contactdate LIMIT 1',
            [$companyId]
        );
        $phone = ltrim(trim((string) ($row['contactphone'] ?? '')), '+');
        // Solo dígitos o nada: `contactphone` puede traer un EMAIL (el alta
        // legacy por email lo guardaba ahí sin validar — hallazgo 2026-09-07).
        // Mandarle un email a Factomate como celular es exactamente el bug
        // que esta clase existe para impedir; ante un valor no numérico se
        // devuelve vacío y el caller corta con su error legible ("cargá el
        // celular del dueño"), que es la acción correcta también para este caso.
        return preg_match('/^\d{6,15}$/', $phone) === 1 ? $phone : '';
    }

    // ── Internos ────────────────────────────────────────────────────────

    /** @return array<string,mixed>|\ArrayAccess */
    private static function row(string $companyId)
    {
        $row = ncmExecute(
            'SELECT username, login_enc, phone_enc FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );
        if (!$row) {
            throw new \RuntimeException('La cuenta de facturación electrónica no está configurada.');
        }
        return $row;
    }

    private static function persistLogin(string $companyId, string $login): void
    {
        // `login_enc IS NULL OR login_enc = ''` en el WHERE: dos requests
        // concurrentes reparando la misma fila escriben el mismo valor, y
        // el guard evita pisar una identidad ya buena si alguien la
        // corrigió a mano en el medio.
        ncmExecute(
            "UPDATE einvoice_account
                SET login_enc = ?, updated_at = now()
              WHERE companyid = ? AND (login_enc IS NULL OR login_enc = '')",
            [CredentialVault::encrypt($login), $companyId]
        );
    }

    private static function tryDecrypt(mixed $enc): ?string
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

    /**
     * Un UserName de CreateExternal es siempre un email; un celular nunca
     * tiene '@'. Es la única señal disponible para distinguir el dato
     * legacy, y no necesita ser una validación de email completa: alcanza
     * con que separe las dos formas que realmente conviven en la columna.
     */
    private static function looksLikeEmail(string $value): bool
    {
        return $value !== '' && str_contains($value, '@');
    }
}
