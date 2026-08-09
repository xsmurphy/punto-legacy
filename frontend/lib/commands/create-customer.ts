/**
 * Comando: createCustomer — alta rápida de cliente desde el POS.
 *
 * offlineEligible: true (ver lib/commands/registry.ts).
 *
 * Ejecuta ONLINE: llama al BFF → `api/v1/contacts` (POST).
 * El backend (ContactService::create) recibe:
 *   fiscalName → razón social (contactName si no viene name)
 *   name       → nombre de la persona (contactSecondName)
 *   phone      → E.164 (contactPhone)
 *   tin        → RUC (contactTIN)
 *   ci         → cédula (contactCI)
 *   email      → (contactEmail)
 * y retorna el UUID asignado.
 *
 * Cuando se active la fase offline: el interceptor persiste en IndexedDB
 * y sincroniza al reconectar. El uid garantiza deduplicación server-side.
 *
 * Teléfono: SIEMPRE en E.164 al backend. La UI captura en formato nacional
 * y convierte con libphonenumber-js antes de llamar este comando.
 * Ver convención §31 en context/08-convenciones.md.
 *
 * Referencia visual legacy: `app/scripts/app.js` namespace `ncmCustomer`
 * funciones: `newCustomer()`, `saveCustomer()`.
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
  /** Cédula de identidad (o el número del documento extranjero — ver idType). */
  ci?: string
  /**
   * Tipo de documento (Tabla 3 SET, ver lib/contact-id-types.ts) — exclusivo
   * de Paraguay. `undefined` = no mandar el campo (tenant no-PY, o cajero no
   * tocó el selector). El backend ignora el campo igual si el tenant no es
   * PY (ContactService::isPyTenant), esto es solo para no mandar ruido.
   */
  idType?: number
  note?: string
}

// ── Shape de respuesta del backend (/v1/contacts POST) ───────────────────────
// ContactService::create retorna el UUID del contacto nuevo.
// contacts.php devuelve { ok: true, data: { id: string } }.
interface RawContactCreateResponse {
  id: string
}

// ── Executor ──────────────────────────────────────────────────────────────────

/**
 * Crea un cliente nuevo en el backend y retorna el PosCustomer para parchear el store.
 *
 * Flow:
 *   1. POST /v1/contacts con el payload del form.
 *   2. Backend crea el contacto y retorna el UUID real.
 *   3. Retornamos un PosCustomer con el UUID real (no client-side).
 *
 * Si el POST falla, lanza un ApiError que el caller debe mostrar como toast.
 * El caller NO debe cerrar el dialog en ese caso.
 */
export async function executeCreateCustomer(
  payload: CreateCustomerPayload,
): Promise<PosCustomer> {
  const result = await api.post<RawContactCreateResponse>("/v1/contacts", {
    name: payload.name,
    fiscalName: payload.fiscalName ?? payload.name,
    phone: payload.phone ?? undefined,
    tin: payload.tin ?? undefined,
    ci: payload.ci ?? undefined,
    idType: payload.idType ?? undefined,
    email: payload.email ?? undefined,
    note: payload.note ?? undefined,
    // type 1 = cliente (TYPE_CUSTOMER en ContactService)
    type: 1,
  } as Record<string, unknown>)

  return {
    id: result.id,
    name: payload.fiscalName?.trim() || payload.name,
    phone: payload.phone ?? null,
    tin: payload.tin?.trim() || null,
    storeCredit: 0,
    isCreditable: false,
  }
}

/**
 * Edita un cliente existente y retorna el PosCustomer actualizado.
 */
export async function executeUpdateCustomer(
  customerId: string,
  payload: Partial<CreateCustomerPayload>,
): Promise<PosCustomer> {
  const result = await api.put<{ id: string; name: string; phone: string | null; tin: string | null }>(
    `/v1/contacts?id=${customerId}`,
    payload as Record<string, unknown>,
  )

  return {
    id: result.id,
    name: result.name,
    phone: result.phone ?? null,
    tin: result.tin ?? null,
    storeCredit: 0,
    isCreditable: false,
  }
}

export { api }
