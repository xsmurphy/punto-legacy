/**
 * Solicitud de alta de sucursal — shape de `GET/POST /v1/outlet-requests`
 * (realm panel). Ver `api/lib/Outlets/OutletRequestService.php`.
 *
 * Cada sucursal se factura al precio del plan del tenant por mes (owner,
 * 2026-09-11), así que el alta pasa por solicitud + aprobación de Punto.
 */

/** La solicitud pendiente, tal como la ve el comercio. */
export interface OutletRequestPending {
  id: string
  name: string
  address: string | null
  status: string
  createdAt: string | null
  requestedByName: string | null
}

export interface OutletRequestStatus {
  /** `null` cuando no hay ninguna pendiente: ahí se puede pedir una nueva. */
  pending: OutletRequestPending | null
  /** Sucursales ACTIVAS — las que se facturan. */
  outletCount: number
  plan: {
    code: number
    name: string
    /**
     * `null` (y no 0) cuando el plan no tiene precio: trial, plan 0, o plan
     * sin fila. El diálogo NO inventa un monto en ese caso — distinguir
     * "gratis" de "todavía no sabemos" es parte del contrato.
     */
    price: number | null
  }
  /** precio × sucursales activas. `null` si el plan no tiene precio. */
  currentMonthly: number | null
  /** Lo que pasaría a facturarse con una sucursal más. `null` idem. */
  nextMonthly: number | null
}

export interface CreateOutletRequestInput {
  name: string
  address?: string
}
