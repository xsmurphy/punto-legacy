<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Caja ↔ emisor, atados por el TIMBRADO.
 *
 * ── El agujero que cierra ────────────────────────────────────────────────
 *
 * El timbrado y el punto de expedición de una caja se editan en Sucursales →
 * Cajas, y hasta hoy ese guardado no le decía NADA al emisor electrónico.
 * `RegisterService`/`RegisterAdminService` no mencionaban facturación
 * electrónica por ningún lado, y cada paso del provisioning está guardado por
 * un checkpoint que no se vuelve a correr. La caja quedaba desincronizada del
 * emisor EN SILENCIO y el comercio se enteraba al no poder facturar.
 *
 * El vínculo por timbrado ya existía, pero solo como bloqueo AL EMITIR
 * (`EInvoiceService::assertNumberingCoherence()`, caso A). Lo que faltaba es
 * lo de acá: que el vínculo se evalúe al EDITAR, cuando todavía se puede
 * hacer algo.
 *
 * ── Por qué HÍBRIDO y no "siempre propagar" ─────────────────────────────
 *
 * Porque los dos motores no pueden lo mismo, y eso no es una preferencia:
 *
 *   - **Factomate SÍ.** `ensureStampsCreated()` es idempotente por caja
 *     (`provisioning.stampRegisters[registerId]`) y su ABM crea una fila
 *     `BranchDocumentType` NUEVA por cada alta — nunca edita una existente.
 *     Renovar el timbrado de una caja es exactamente eso: una fila nueva, con
 *     `Id` nuevo, y el `stampMap` repunta. Hasta el caché de
 *     `remoteStampRow()` se invalida solo, porque entra por otra clave. Así
 *     que acá SE PROPAGA: se invalida el checkpoint de esa caja y se vuelve a
 *     registrar el timbrado.
 *
 *   - **FE-PY NO.** Su timbrado es del TENANT (`tenants.timbradoNumero`) y sus
 *     establecimientos viajan dentro del alta (`POST /v1/tenants`). Los tres
 *     métodos que harían falta para propagar —`updateTenant()`,
 *     `createStamp()`, `createActivity()`— lanzan `LogicException` en
 *     `FePyProvider` porque el motor no tiene esos endpoints. Y hay algo peor
 *     que "no se puede propagar": FE-PY INYECTA el timbrado del tenant en
 *     cada documento y el caller no puede pisarlo, así que una caja cuyo
 *     timbrado diverja del emisor emitiría con el timbrado del emisor y
 *     nuestro número, sin que nadie lo note. Eso es corrupción fiscal
 *     silenciosa, que es justo la clase de falla que este archivo existe para
 *     matar. Por eso acá SE FRENA, con el motivo.
 *
 * ── Lo que este guard NO toca ───────────────────────────────────────────
 *
 * Nada del camino de emisión. Corre sobre una acción de PANEL (editar una
 * caja), nunca sobre una venta. Una venta offline que congeló el timbrado
 * viejo se sigue guardando y marcando igual — el backend nunca rechaza una
 * venta ya emitida (`context/08` §53), y frenar una EDICIÓN no puede
 * romperlo porque no está en ese camino.
 *
 * Tampoco corre sobre comercios sin facturación electrónica: sin emisor no
 * hay con qué desincronizarse y la caja se edita como siempre.
 */
final class EInvoiceRegisterSync
{
    /**
     * ¿Este cambio de timbrado / punto de expedición es reconciliable con el
     * emisor? Si no lo es, corta ACÁ con el motivo.
     *
     * Se llama con el estado RESULTANTE del update, no con el guardado, y
     * antes de abrir la transacción — mismo lugar y mismo criterio que
     * `RegisterAdminService::assertExpeditionPointFree()`.
     *
     * @throws \RuntimeException con el mensaje que ve el comercio.
     */
    public static function assertChangeAllowed(
        string $companyId,
        string $registerName,
        string $oldAuth,
        string $oldPrefix,
        string $newAuth,
        string $newPrefix,
        bool $revalidate = false
    ): void {
        // `$revalidate` = REACTIVACIÓN. Una caja dada de baja pudo haber
        // cambiado su timbrado mientras no emitía (o el emisor pudo haberse
        // dado de alta después), así que volver a activarla es asignarle ese
        // par de nuevo aunque no "cambie" en este request. Misma excepción, y
        // por el mismo motivo, que hace `assertExpeditionPointFree()`.
        if (!$revalidate && $newAuth === $oldAuth && $newPrefix === $oldPrefix) {
            return;   // no cambió nada de lo que el emisor conoce
        }

        $account = self::account($companyId);
        if ($account === null || !self::isProvisioned($account)) {
            return;   // sin emisor no hay nada con qué desincronizarse
        }
        if (!self::isFePy($account)) {
            return;   // Factomate se propaga, no se frena — ver afterChange()
        }

        $caja = $registerName !== '' ? '"' . $registerName . '"' : 'esta caja';

        // ── (A) el timbrado del emisor es UNO y está congelado ────────────
        //
        // Vaciar el timbrado no se frena: es sacar a la caja de la facturación
        // electrónica, no hacerla emitir mal.
        $emitterStamp = self::emitterStamp($account);
        if ($newAuth !== '' && $emitterStamp !== '' && $newAuth !== $emitterStamp) {
            throw new \RuntimeException(sprintf(
                'El emisor electrónico está registrado con el timbrado %s, y este motor lo fija en el alta: no se ' .
                'puede cambiar desde Punto. Si le ponés el timbrado %s a la caja %s, sus facturas saldrían con el ' .
                'timbrado del emisor y con el número de la caja — dos datos de talonarios distintos en el mismo ' .
                'documento. Todas las cajas del comercio comparten el timbrado del emisor; lo que cambia por caja ' .
                'es el punto de expedición. Para renovar el timbrado hay que volver a dar de alta al emisor: ' .
                'escribinos antes de hacerlo.',
                $emitterStamp,
                $newAuth,
                $caja
            ));
        }

        // ── (B) el establecimiento tiene que estar declarado en el alta ───
        //
        // El punto de expedición (los últimos tres dígitos) SÍ se puede
        // cambiar libremente: FE-PY lo elige por documento y no hay nada
        // registrado de su lado que contradecir. El establecimiento (los
        // primeros tres) no: se declaró en el alta y no hay endpoint para
        // agregar uno.
        $newEstablishment = self::establishmentOf($newPrefix);
        if ($newEstablishment === '') {
            return;
        }
        if (!$revalidate && $newEstablishment === self::establishmentOf($oldPrefix)) {
            return;   // el establecimiento no se movió
        }

        $declared = self::declaredEstablishments($account);
        if ($declared === [] || in_array($newEstablishment, $declared, true)) {
            // Vacío = no sabemos qué se declaró (emisor anterior a que esto se
            // registrara). No se frena sobre una sospecha: el guard de
            // emisión sigue estando y es el que tiene el dato real.
            return;
        }

        throw new \RuntimeException(sprintf(
            'El emisor electrónico se dio de alta con el establecimiento %s y la caja %s pasaría al %s, que no está ' .
            'declarado. Este motor registra los establecimientos en el alta y no permite agregar uno después, así ' .
            'que sus facturas serían rechazadas. Usá un punto de expedición dentro del establecimiento %s (por ' .
            'ejemplo %s-002), o escribinos para volver a dar de alta al emisor con el establecimiento nuevo.',
            implode(', ', $declared),
            $caja,
            $newEstablishment,
            $declared[0],
            $declared[0]
        ));
    }

    /**
     * Propaga al emisor lo que se acaba de guardar. Corre DESPUÉS del commit:
     * anunciarle al proveedor un cambio que todavía puede revertirse es
     * exactamente el desfasaje que este archivo viene a cerrar, al revés.
     *
     * NUNCA lanza. La caja ya se guardó y su timbrado es dato fiscal de la
     * caja: revertirlo porque el proveedor no contestó sería perder lo que el
     * comercio acaba de cargar. Lo que sí hace es DECIRLO — devuelve el aviso
     * para que la respuesta del guardado lo muestre en el acto, y lo deja en
     * `last_error` para que la pantalla de facturación electrónica no lo
     * pierda al recargar.
     *
     * @return string|null Aviso para el comercio, o null si quedó sincronizado.
     */
    public static function afterChange(
        string $companyId,
        string $registerId,
        string $registerName,
        string $oldAuth,
        string $oldPrefix,
        string $newAuth,
        string $newPrefix
    ): ?string {
        if ($newAuth === $oldAuth && $newPrefix === $oldPrefix) {
            return null;
        }

        try {
            $account = self::account($companyId);
            if ($account === null || !self::isProvisioned($account) || self::isFePy($account)) {
                // FE-PY no tiene nada que propagar: o el cambio era compatible
                // (y su punto de expedición se elige por documento), o
                // assertChangeAllowed() ya lo frenó.
                return null;
            }
            if ($newAuth === '' || $newPrefix === '') {
                return null;   // la caja sale de la facturación electrónica
            }

            (new EInvoiceProvisioningService())->reprovisionRegisterStamp($companyId, $registerId);
            return null;
        } catch (\Throwable $e) {
            $aviso = sprintf(
                'La caja %s se guardó, pero el timbrado nuevo no se pudo registrar en el emisor electrónico: %s. ' .
                'Hasta que se registre, sus facturas electrónicas van a quedar en error. Volvé a conectar la ' .
                'facturación electrónica desde Configuración → Facturación electrónica.',
                $registerName !== '' ? '"' . $registerName . '"' : 'editada',
                $e->getMessage()
            );
            try {
                ncmExecute(
                    'UPDATE einvoice_account SET last_error = ?, last_check_at = now(), updated_at = now() WHERE companyid = ?',
                    [mb_substr($aviso, 0, 500), $companyId]
                );
            } catch (\Throwable $inner) {
                error_log('[EInvoiceRegisterSync] no se pudo persistir el aviso: ' . $inner->getMessage());
            }

            return $aviso;
        }
    }

    // ── Interno ──────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private static function account(string $companyId): ?array
    {
        $row = ncmExecute(
            'SELECT provider, provider_tenant_ref, factomate_tenant_id, stamp, fiscal, provisioning
               FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );

        return $row ? (array) $row : null;
    }

    /** @param array<string,mixed> $account */
    private static function isFePy(array $account): bool
    {
        return strtolower((string) ($account['provider'] ?? '')) === 'fepy';
    }

    /**
     * MISMA regla que `EInvoiceService::getAccount()`: por proveedor, no
     * global. Un tenant que migró de motor conserva el id del anterior, y
     * mirarlo sin distinguir daría por provisionado a un emisor que no existe.
     *
     * @param array<string,mixed> $account
     */
    private static function isProvisioned(array $account): bool
    {
        if (self::isFePy($account)) {
            return trim((string) ($account['provider_tenant_ref'] ?? '')) !== '';
        }

        return (int) ($account['factomate_tenant_id'] ?? 0) > 0;
    }

    /** Timbrado con el que está registrado el emisor. @param array<string,mixed> $account */
    private static function emitterStamp(array $account): string
    {
        $stamp = self::jsonb($account['stamp'] ?? null);

        return trim((string) ($stamp['StampNumber'] ?? $stamp['stampNumber'] ?? ''));
    }

    /** Los tres primeros dígitos de `EEE-PPP`. */
    private static function establishmentOf(string $prefix): string
    {
        return preg_match('/^(\d{3})-(\d{3})$/', trim($prefix), $m) === 1 ? $m[1] : '';
    }

    /**
     * Establecimientos que el alta declaró ante el emisor.
     *
     * Fuente preferida: lo que efectivamente se mandó (`provisioning
     * .fepyEstablishments`, que `FePyProvisioningService` persiste al crear el
     * tenant). Fallback para emisores anteriores a ese registro: los códigos
     * del formulario fiscal, que es de donde salieron.
     *
     * @param array<string,mixed> $account
     * @return array<int,string>
     */
    private static function declaredEstablishments(array $account): array
    {
        $prov = self::jsonb($account['provisioning'] ?? null);
        $sent = $prov['fepyEstablishments'] ?? null;
        if (is_array($sent) && $sent !== []) {
            return array_values(array_unique(array_map(
                static fn ($c) => str_pad(trim((string) $c), 3, '0', STR_PAD_LEFT),
                $sent
            )));
        }

        $fiscal = self::jsonb($account['fiscal'] ?? null);
        $rows = $fiscal['establecimientos'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $codes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = str_pad(trim((string) ($row['codigo'] ?? '')), 3, '0', STR_PAD_LEFT);
            if ($code !== '000') {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /** @return array<string,mixed> */
    private static function jsonb(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) ($value ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
