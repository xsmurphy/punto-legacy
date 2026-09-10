<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Cómo se autentica el emisor de UNA company contra su proveedor.
 *
 * Es la otra mitad de la resolución por tenant: `EInvoiceProviderFactory` dice
 * QUÉ motor habla, esto dice CON QUÉ credencial. `EInvoiceService` hace en
 * todos sus caminos exactamente lo mismo antes de llamar al motor:
 *
 *     $bearer = ...getBearer($companyId);
 *     [$tenantRef, $environment] = ...identity($companyId);
 *     $this->providerFor($companyId)->loQueSea($environment, $tenantRef, $bearer, ...);
 *
 * Está separada del motor porque cómo se autentica y cómo se identifica un
 * emisor son decisiones de cada uno, y no tienen por qué cambiar juntas con
 * los endpoints. Hoy la implementa `FePySession`: la credencial es una API key
 * de company que no expira, y la identidad es el UUID del tenant, que va en el
 * path de todas las rutas.
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
