<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

use DB; // wrapper PDO en namespace global, en `app/includes/lib/DB.php`
use Punto\Api\Context\TenantContext;

/**
 * SaleService — guardado de ventas del POS (slice 35 del desacople de /app).
 *
 * Strangler-fig del handler monolítico `processData` en `app/action.php`.
 * Cubre los paths de venta migrados incrementalmente; los no migrados siguen
 * en el legacy de `processData`.
 *
 * Sub-slices:
 *   35a — venta simple (cashsale type=0, creditsale type=3): sin gift card,
 *         sin schedule, sin EI, sin parent, sin recurring. ~80% del tráfico.
 *   35b — Electronic Invoice (factura electrónica PY/PE/AR).
 *   35c — Gift card sale + redemption.
 *   35d — Schedule auto-create (items con duration > 0).
 *   35e — Devolución (type=6) + pago de créditos (type=5).
 *   35f — Recurring sales (type=8) + cleanup del processData legacy.
 *
 * Estado actual: ESQUELETO (35a.1). save() devuelve un SaleResult mock para
 * validar el routing end-to-end. Los sub-slices subsiguientes implementan los
 * bloques B1-B16 del mapa documentado en context/10-roadmap.md.
 *
 * Diseño:
 *   - final + readonly: cerrado a herencia accidental, dependencias inmutables.
 *   - DI por constructor: $ctx y $db inyectados (no globals).
 *   - DTOs tipados de entrada/salida (SaleInput / SaleResult).
 *   - Errores por excepciones custom (Duplicate/Invalid/Aborted), no por arrays.
 *
 * Helpers del legacy (manageStock, sendAuditoria, etc.) se siguen llamando como
 * funciones globales en los sub-slices que los necesiten — wrappear en
 * interfaces queda como deuda; el patrón actual permite mockear vía partial
 * mocks o redefiniendo en runtime durante tests.
 */
final class SaleService
{
    public function __construct(
        private readonly TenantContext $ctx,
        private readonly DB $db,
    ) {
    }

    /**
     * Guarda una venta. Idempotente por `transactionUID`.
     *
     * @throws Exceptions\DuplicateSaleException  Si el UID ya existe (el endpoint devuelve 200 con duplicated=true).
     * @throws Exceptions\SaleAbortedException    Si la transacción de PG falla (el endpoint devuelve 500).
     */
    public function save(SaleInput $input): SaleResult
    {
        // TODO sub-slice 35a.2+ — implementación real por bloques B1-B16.
        // (En este stub no usamos $this->ctx ni $this->db; los sub-slices siguientes
        //  ya leen company/db de acá.)
        return SaleResult::created(
            transactionId: '00000000-0000-0000-0000-000000000000',
            uid: $input->uid,
        );
    }
}
