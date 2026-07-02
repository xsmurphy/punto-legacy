/**
 * Medios de pago del tenant — CRUD sobre `taxonomy` (taxonomyType='paymentMethod').
 * Endpoint: /v1/payment-methods. Ver api/lib/PaymentMethods/PaymentMethodService.php.
 *
 * El `id` (taxonomyId) es la identidad ESTABLE del método: las ventas nuevas
 * lo guardan como clave del pago y `finAccountMap` lo referencia. `systemKey`
 * identifica métodos con comportamiento especial en el POS (cash, giftcard,
 * internal) — inmutable una vez seteado por el seed/backend.
 */

export interface PaymentMethod {
  id: string
  name: string
  code: string
  hasChange: boolean
  requiresIdentifier: boolean
  identifierLabel: string
  identifierPlaceholder: string
  systemKey: "cash" | "giftcard" | "internal" | null
  /** Cuenta de Finanzas asignada. Null = cae en Efectivo (fallback). */
  accountId: string | null
}

export interface PaymentMethodPayload {
  name: string
  code?: string
  hasChange?: boolean
  requiresIdentifier?: boolean
  identifierLabel?: string
  identifierPlaceholder?: string
  accountId?: string | null
}
