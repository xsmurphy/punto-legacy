<?php
/**
 * SaleService — guardado de ventas del POS (slice 35 del desacople de /app).
 *
 * Strangler-fig del handler monolítico `processData` en `app/action.php`.
 * Cubre los paths de venta migrados incrementalmente; los no migrados
 * siguen en el legacy de `processData`.
 *
 * Sub-slices:
 *   35a — venta simple (cashsale type=0, creditsale type=3) — sin gift card,
 *         sin schedule, sin EI, sin parent, sin recurring. ~80% del tráfico.
 *   35b — Electronic Invoice (factura electrónica PY/PE/AR).
 *   35c — Gift card sale + redemption.
 *   35d — Schedule auto-create (items con duration > 0).
 *   35e — Devolución (type=6) + pago de créditos (type=5).
 *   35f — Recurring sales (type=8) + cleanup del processData legacy.
 *
 * Estado actual: ESQUELETO (35a.1). El método save() devuelve stub.
 * Los sub-slices subsiguientes llenan la lógica por bloques (B1-B16 del
 * mapa en context/10-roadmap.md § processData).
 *
 * Reusa helpers existentes de `app/includes/functions.php` (manageStock,
 * getItemStock, manageCustomerLoyalty, sendAuditoria, etc.) — los efectos
 * colaterales se comportan igual que el legacy.
 */

class SaleService
{
    /**
     * Contexto de auth + bootstrap (companyId, outletId, userId, registerId, roleId).
     *
     * NOTA sobre el dual-source de identidad: `apiAuthTenant()` también define las
     * constantes globales COMPANY_ID/OUTLET_ID/USER_ID/REGISTER_ID via data.php.
     * El servicio recibe `$ctx` para tests/aislamiento; la lógica que reusa helpers
     * del legacy (`manageStock`, `sendAuditoria`, etc.) sigue leyendo las constantes
     * globales — coexisten hasta que esté consolidado el `/api/includes` canónico
     * (ver context/10-roadmap.md § Consolidar /api/includes).
     */
    private array $ctx; // @phpstan-ignore-line  — usado en 35a.2+

    public function __construct(array $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * Guarda una venta. Replica el contrato del legacy `processData`:
     *   - Idempotente por `transactionUID` (la cola offline reintenta).
     *   - Devuelve 200 incluso en duplicado (el front no debe reintentar).
     *   - Errores de DB devuelven 500 con el error de PG.
     *
     * Input esperado (mirror del payload del front):
     *   {
     *     uid, type ∈ {0,3}, sale[], subtotal, tax, discount, payment[],
     *     date, timestamp, user, [client], [tags], [taxObj], [addressId],
     *     [currency], [ident], [note], [invoiceno], [dueDate], [status],
     *     [dontNotify]
     *   }
     *
     * Output:
     *   {success:true, transactionId, uid}             — venta guardada
     *   {success:true, duplicated:true, uid}           — uid ya existe
     *   {success:false, error}                         — fallo de DB
     */
    public function save(array $input): array
    {
        // TODO sub-slice 35a.2+ — implementación real por bloques B1-B16.
        return [
            'success' => true,
            'todo'    => '35a.1 esqueleto — implementación pendiente',
            'uid'     => $input['uid'] ?? null,
        ];
    }
}
