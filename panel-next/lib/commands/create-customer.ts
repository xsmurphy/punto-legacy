/**
 * Comando: createCustomer — alta rápida de cliente desde el POS.
 *
 * offlineEligible: true (ver lib/commands/registry.ts).
 *
 * Hoy ejecuta ONLINE: llama al BFF → `api/v1/contacts` (POST).
 * Cuando se active la fase offline: el interceptor persiste en IndexedDB
 * y sincroniza al reconectar. No hay UID client-side aquí: el backend
 * retorna el UUID asignado y el store se parchea con `patchCustomer()`.
 *
 * TODO (Slice A): implementar la lógica real:
 *   1. Validar campos mínimos (nombre + teléfono en E.164).
 *   2. Llamar `api.post('/v1/contacts', payload)`.
 *   3. Llamar `useCatalogStore.getState().patchCustomer(result)`.
 *   4. Retornar el PosCustomer creado para seleccionarlo en el carrito.
 *
 * Teléfono: SIEMPRE en E.164 al backend. La UI captura en formato nacional
 * y convierte con libphonenumber-js antes de llamar este comando.
 * Ver convención §31 en context/08-convenciones.md.
 *
 * Referencia visual legacy: `app/scripts/app.js` namespace `ncmCustomer`
 * funciones: `newCustomer()`, `saveCustomer()`, las 8 subvistas del CRUD.
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A.
 */

import { api } from "@/lib/api-client"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"

// ── Shape del payload ─────────────────────────────────────────────────────────

export interface CreateCustomerPayload {
  /** Nombre display (razón social o nombre persona). */
  name: string
  /** Razón social si es empresa. Vacío si es persona. */
  fiscalName?: string
  /** Teléfono en E.164 (libphonenumber convierte desde la UI). */
  phone: string | null
  email?: string
  /** RUC / documento fiscal. */
  tin?: string
  /** Cédula de identidad. */
  ci?: string
  note?: string
}

// ── Executor ──────────────────────────────────────────────────────────────────

/**
 * Crea un cliente nuevo y retorna el PosCustomer para parchear el store.
 * TODO (Slice A): implementar.
 */
export async function executeCreateCustomer(
  _payload: CreateCustomerPayload,
): Promise<PosCustomer> {
  // TODO (Slice A): implementar.
  // const result = await api.post<{ id: string; name: string; phone: string | null; tin: string | null }>("/v1/contacts", _payload)
  // return { id: result.id, name: result.name, phone: result.phone, tin: result.tin, storeCredit: 0, isCreditable: false }
  throw new Error("createCustomer: no implementado aún — ver TODO en lib/commands/create-customer.ts")
}

/**
 * Edita un cliente existente y retorna el PosCustomer actualizado.
 * TODO (Slice A): implementar.
 */
export async function executeUpdateCustomer(
  _customerId: string,
  _payload: Partial<CreateCustomerPayload>,
): Promise<PosCustomer> {
  // TODO (Slice A): implementar.
  throw new Error("updateCustomer: no implementado aún — ver TODO en lib/commands/create-customer.ts")
}

export { api }
