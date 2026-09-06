<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Custodia de los secretos FISCALES del emisor: el certificado de firma
 * (`.pfx` + su contraseña) y el `CSCProduccion` de SIFEN.
 * Ver `context/28-facturacion-electronica-plan.md` §Custodia del certificado
 * y del CSC (decisión del owner, 2026-09-06) y la mig 195.
 *
 * ── Por qué esta clase existe, y no un par de UPDATE sueltos ────────────────
 *
 * La decisión de GUARDAR estos secretos vino con tres condiciones que el owner
 * no puso como deseables sino como parte de la decisión: auditar cada lectura,
 * decírselo al comercio y poder borrarlos. La primera es la que necesita una
 * casa: si descifrar fuera un `CredentialVault::decrypt()` más, cualquier
 * call-site futuro leería el certificado sin dejar rastro y nadie se enteraría
 * hasta que hubiera que investigar una filtración.
 *
 * Por eso las columnas `cert_pfx_enc`/`cert_password_enc`/`csc_secret_enc` se
 * nombran en UN SOLO archivo del repo, y la única forma de descifrarlas pasa
 * por `readCertificate()`/`readCscSecret()`, que auditan ANTES de descifrar.
 * Un `grep` por el nombre de columna alcanza para verificar el invariante.
 *
 * ── Qué se audita ──────────────────────────────────────────────────────────
 *
 * Una fila en `tenant_audit` por lectura, con `method='READ'` y un endpoint
 * sintético (`/einvoice/secret/<clase>`) — la tabla es "quién hizo qué", y
 * leer la identidad de firma de un contribuyente es un QUÉ. El `meta` lleva el
 * MOTIVO declarado por el caller: sin motivo, la fila diría que alguien
 * descifró un certificado y no para qué, que es la mitad de la pregunta.
 *
 * El autor sale de `AuditActor::resolve()`, el mismo embudo que usa
 * `apiAuthTenant()` — así una lectura disparada desde una caja queda a nombre
 * del operador del PIN y no de la tablet (ver `api/lib/Auth/AuditActor.php`).
 *
 * Se audita ANTES de descifrar: un intento fallido de descifrado (clave
 * rotada, dato corrupto) también es un acceso al secreto y también tiene que
 * quedar registrado.
 *
 * ── Qué NO hace ────────────────────────────────────────────────────────────
 *
 * No devuelve nada de esto al frontend ni lo loguea — ni el archivo, ni la
 * contraseña, ni un hash de ninguno de los dos (regla vigente del plan
 * §Certificado de firma, que el cambio de decisión no tocó). Lo único que sale
 * de acá hacia la UI es `status()`: si hay algo cargado y desde cuándo.
 */
final class FiscalSecretStore
{
    /** Clases de secreto — se usan en el endpoint sintético de la auditoría. */
    private const KIND_CERT = 'certificate';
    private const KIND_CSC  = 'csc';

    // ── Certificado de firma ────────────────────────────────────────────

    /**
     * Guarda el `.pfx` y su contraseña, cifrados. Se llama DESPUÉS de que el
     * proveedor aceptó el certificado: si lo rechazó, guardarlo dejaría en
     * custodia un archivo que ni siquiera sirve para firmar.
     *
     * @throws \RuntimeException si el vault no puede cifrar (clave ausente o inválida).
     */
    public static function storeCertificate(string $companyId, string $certBase64, string $certPassword): void
    {
        ncmExecute(
            'UPDATE einvoice_account
                SET cert_pfx_enc = ?, cert_password_enc = ?, cert_uploaded_at = now(), updated_at = now()
              WHERE companyid = ?',
            [
                CredentialVault::encrypt($certBase64),
                CredentialVault::encrypt($certPassword),
                $companyId,
            ]
        );
    }

    /**
     * Descifra el certificado guardado. `null` = no hay ninguno (nunca se
     * cargó, o el comercio lo borró) — el caller decide qué hacer con eso;
     * acá no se inventa nada.
     *
     * @param string $reason Para qué se lo lee. Queda en la auditoría.
     * @return array{certBase64:string,certPassword:string}|null
     * @throws \RuntimeException si hay un certificado guardado pero no se puede descifrar.
     */
    public static function readCertificate(string $companyId, string $reason): ?array
    {
        $row = ncmExecute(
            'SELECT cert_pfx_enc, cert_password_enc FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );
        $row  = is_array($row) ? $row : [];
        $pfx  = (string) ($row['cert_pfx_enc'] ?? '');
        $pass = (string) ($row['cert_password_enc'] ?? '');
        if ($pfx === '') {
            // No hubo lectura de un secreto: no hay secreto. Auditar acá
            // llenaría la tabla de filas que no dicen nada.
            return null;
        }

        self::audit($companyId, self::KIND_CERT, $reason);

        return [
            'certBase64' => CredentialVault::decrypt($pfx),
            // Un `.pfx` sin contraseña existe, y los certificados cargados
            // antes de la mig 195 no dejaron ninguna de las dos columnas: solo
            // se descifra si hay algo que descifrar.
            'certPassword' => $pass === '' ? '' : CredentialVault::decrypt($pass),
        ];
    }

    /**
     * Borrado explícito, a pedido del comercio: es SU certificado. Después de
     * esto, reconfigurar la emisión vuelve a exigir que lo suba.
     *
     * No toca el certificado del lado del proveedor. Factomate no expone un
     * borrado y, sobre todo, quitarlo de allá dejaría al comercio sin poder
     * facturar sin haberlo pedido: lo que el comercio pide es que PUNTO deje
     * de custodiarlo.
     */
    public static function deleteCertificate(string $companyId): void
    {
        ncmExecute(
            'UPDATE einvoice_account
                SET cert_pfx_enc = NULL, cert_password_enc = NULL, cert_uploaded_at = NULL, updated_at = now()
              WHERE companyid = ?',
            [$companyId]
        );
    }

    // ── CSC de SIFEN ────────────────────────────────────────────────────

    /** @throws \RuntimeException */
    public static function storeCscSecret(string $companyId, string $secret): void
    {
        ncmExecute(
            'UPDATE einvoice_account
                SET csc_secret_enc = ?, csc_updated_at = now(), updated_at = now()
              WHERE companyid = ?',
            [CredentialVault::encrypt($secret), $companyId]
        );
    }

    /**
     * @param string $reason Para qué se lo lee. Queda en la auditoría.
     * @throws \RuntimeException si hay secreto guardado pero no se puede descifrar.
     */
    public static function readCscSecret(string $companyId, string $reason): ?string
    {
        $row = ncmExecute('SELECT csc_secret_enc FROM einvoice_account WHERE companyid = ?', [$companyId]);
        $row = is_array($row) ? $row : [];
        $enc = (string) ($row['csc_secret_enc'] ?? '');
        if ($enc === '') {
            return null;
        }

        self::audit($companyId, self::KIND_CSC, $reason);

        return CredentialVault::decrypt($enc);
    }

    public static function deleteCscSecret(string $companyId): void
    {
        ncmExecute(
            'UPDATE einvoice_account
                SET csc_secret_enc = NULL, csc_updated_at = NULL, updated_at = now()
              WHERE companyid = ?',
            [$companyId]
        );
    }

    // ── Lo único que sale hacia la UI ───────────────────────────────────

    /**
     * Qué hay en custodia y desde cuándo. NO devuelve nada del contenido — es
     * literalmente el contrato que la pantalla le muestra al comercio.
     *
     * @return array{certStored:bool,certUploadedAt:?string,cscStored:bool,cscUpdatedAt:?string}
     */
    public static function status(string $companyId): array
    {
        $row = ncmExecute(
            "SELECT (cert_pfx_enc IS NOT NULL AND cert_pfx_enc <> '') AS cert_stored,
                    cert_uploaded_at,
                    (csc_secret_enc IS NOT NULL AND csc_secret_enc <> '') AS csc_stored,
                    csc_updated_at
               FROM einvoice_account WHERE companyid = ?",
            [$companyId]
        );

        $row = is_array($row) ? $row : [];

        return [
            'certStored'     => !empty($row['cert_stored']),
            'certUploadedAt' => $row['cert_uploaded_at'] ?? null,
            'cscStored'      => !empty($row['csc_stored']),
            'cscUpdatedAt'   => $row['csc_updated_at'] ?? null,
        ];
    }

    // ── Auditoría ───────────────────────────────────────────────────────

    /**
     * Una fila en `tenant_audit` por acceso. Best-effort igual que
     * `tenantAudit()` (nunca tira la operación), pero NUNCA muda: si no se
     * puede auditar queda la línea en el log de errores — que es justamente la
     * señal de que hubo un acceso sin rastro.
     */
    private static function audit(string $companyId, string $kind, string $reason): void
    {
        if (!function_exists('tenantAudit')) {
            // Contexto sin bootstrap (CLI, migración). No debería pasar desde
            // ninguna ruta viva, y si pasa hay que enterarse.
            error_log(sprintf(
                '[FiscalSecretStore] lectura de "%s" para company %s SIN auditar (sin bootstrap): %s',
                $kind,
                $companyId,
                $reason
            ));
            return;
        }

        $realm    = defined('AUTHED_REALM') ? (string) AUTHED_REALM : 'panel';
        $userId   = defined('AUTHED_USER_ID') && AUTHED_USER_ID !== '' ? (string) AUTHED_USER_ID : null;
        $outletId = defined('OUTLET_ID') && OUTLET_ID !== '' ? (string) OUTLET_ID : null;
        $deviceId = defined('AUTHED_DEVICE_ID') && AUTHED_DEVICE_ID !== '' ? (string) AUTHED_DEVICE_ID : null;

        // Mismo embudo que apiAuthTenant(): bajo `pos-app` la fila queda a
        // nombre del operador del PIN, no de la terminal.
        $actor = \Punto\Api\Auth\AuditActor::resolve(
            $realm,
            $companyId,
            $userId,
            $deviceId,
            ['secret' => $kind, 'reason' => $reason]
        );

        tenantAudit(
            [
                'companyId' => $companyId,
                'userId'    => $actor['userId'],
                'outletId'  => $outletId,
                'realm'     => $realm,
            ],
            'READ',
            '/einvoice/secret/' . $kind,
            $companyId,
            $actor['meta']
        );
    }
}
