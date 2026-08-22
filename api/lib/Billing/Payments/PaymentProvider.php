<?php
declare(strict_types=1);

namespace Punto\Api\Billing\Payments;

/**
 * Superficie que `PaymentsService` necesita de una pasarela de pago.
 *
 * POR QUÉ EXISTE
 * --------------
 * `PaymentsService` construía `new DlocalGoProvider()` adentro del
 * constructor, así que el camino del webhook —el que acredita PLATA— no se
 * podía ejercitar sin llamar a la API real de dLocal. La idempotencia del
 * webhook (pago duplicado → `already_paid`, sin doble crédito) se rompió una
 * vez sin que ningún arnés lo notara: el `INSERT` en `ai_credit_ledger` dejó
 * de devolver `false` cuando el wrapper PDO pasó a lanzar `DbQueryException`,
 * y con eso el guard de duplicados quedó inalcanzable.
 *
 * La interfaz es el arreglo estructural de eso: el proveedor entra por
 * constructor y el arnés inyecta un doble que devuelve el mismo pago dos
 * veces. No cambia el comportamiento de producción —`PaymentsService` sigue
 * instanciando `DlocalGoProvider` cuando no le pasan nada—, solo deja de
 * hardcodear la dependencia.
 *
 * Segundo beneficio: cuando entre otra pasarela (el negocio ya la pidió para
 * cobros locales), el contrato a implementar está escrito en un solo lugar
 * en vez de deducirse leyendo `PaymentsService`.
 */
interface PaymentProvider
{
    /** true si hay credenciales/config suficientes para operar. */
    public function isConfigured(): bool;

    /**
     * Crea un pago en la pasarela y devuelve al menos su id y la URL de
     * checkout a la que mandar al comprador.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function createPayment(array $args): array;

    /**
     * Re-consulta el estado REAL de un pago. Nunca se confía en el body del
     * webhook para decidir si se acredita.
     *
     * @return array<string,mixed> con al menos `status` y `raw`.
     */
    public function getPayment(string $providerPaymentId): array;

    /**
     * @param array<string,mixed> $headers
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool;

    /**
     * Id del pago dentro del body del webhook, o null si no viene.
     *
     * @param array<string,mixed> $body
     */
    public function extractPaymentId(array $body): ?string;
}
