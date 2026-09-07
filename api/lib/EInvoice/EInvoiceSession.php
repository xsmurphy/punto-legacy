<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Cómo se autentica el emisor de UNA company contra su proveedor.
 *
 * Existe porque el cutover por tenant tiene DOS mitades y el factory solo
 * cubre una. `EInvoiceService` hace en todos sus caminos exactamente lo
 * mismo antes de llamar al proveedor:
 *
 *     $bearer = ...getBearer($companyId);
 *     [$identity, $environment] = ...identity($companyId);
 *     $this->provider->loQueSea($environment, $identity, $bearer, ...);
 *
 * Con el proveedor elegido por company pero el bearer resuelto siempre por
 * `FactomateSession`, una cuenta de FE-PY intentaría loguearse contra
 * Factomate (que no tiene ni usuario ni contraseña para ella) y fallaría
 * antes de llegar a emitir. Esta interfaz es el punto donde eso se decide.
 *
 * ── Qué significa cada valor, según el proveedor ─────────────────────────
 *
 *              getBearer()                        identity()[0]
 *  Factomate   bearer de 24 h del usuario del     el LOGIN del usuario
 *              tenant, cacheado en `token_enc`    (email/UserName) — es el
 *              (cadena /Token → PhoneLogin)       header `phonenumber`
 *  FE-PY       la API key de company (env),       el UUID del tenant en
 *              que no expira ni se cachea         FE-PY (va en el path)
 *
 * Los nombres genéricos son a propósito. `EInvoiceProvider` sigue llamando
 * `$phone` y `$bearer` a esos dos parámetros porque angostarle la firma
 * obliga a tocar FactomateProvider y los ~20 call-sites de EInvoiceService —
 * el refactor correcto, pero no en el slice que estrena el proveedor que
 * todavía no facturó nada. Cuando Factomate se retire, la interfaz se
 * angosta de una sola vez y estos nombres ya describen el contrato real.
 */
interface EInvoiceSession
{
    /**
     * Credencial lista para el header `Authorization: Bearer`.
     *
     * @throws \RuntimeException con mensaje apto para persistir en
     *         `einvoice_account.last_error` (es visible en el panel).
     */
    public function getBearer(string $companyId): string;

    /**
     * Identidad del emisor + entorno.
     *
     * @return array{0: string, 1: string} [identidad, environment]
     * @throws \RuntimeException si la cuenta no está configurada o quedó a medio provisionar.
     */
    public function identity(string $companyId): array;
}
