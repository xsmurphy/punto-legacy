/**
 * ¿Por qué esta caja NO puede emitir un comprobante, ahora mismo?
 *
 * UNA sola respuesta, con prioridad explícita, para los dos lugares que la
 * necesitan: el CTA de cobro (`cart-panel.tsx`, que la pinta en el tooltip) y
 * el confirmar del diálogo de pago (`pay-dialog.tsx`, que la vuelve a evaluar
 * justo antes de consumir el número). Las dos veces tiene que decir exactamente
 * lo mismo.
 *
 * ## Por qué un módulo y no un `||` en cada call-site
 *
 * Hasta 2026-09-07 el único impedimento era la tenencia de caja y vivía suelto
 * como `registerBlockShortReason(verdict)` en los dos archivos. Al sumar el
 * segundo —timbrado vencido— la opción barata era encadenar un `??` en cada
 * lado; con eso el orden de prioridad queda escrito dos veces y nada impide que
 * diverjan. Acá está una vez, y el tercero que venga (la cuenta vencida de
 * context/34 §F7 D5 es el candidato obvio) se suma a esta lista, no a los
 * call-sites.
 *
 * ## Prioridad
 *
 * 1. **Timbrado vencido.** Va PRIMERO aunque la tenencia también falle: tomar
 *    la caja no habilita a facturar con el timbrado caído, así que ofrecer
 *    "tocá para tomarla" mandaría al cajero a una acción que no lo desbloquea.
 * 2. **Tenencia de caja** (context/29 §4).
 *
 * ## Todo esto funciona OFFLINE
 *
 * Las dos señales son locales: la tenencia sale del grant persistido
 * (`register-tenancy.ts`) y el vencimiento del snapshot del bootstrap
 * (`PosRegister.authExpiration`, ya baja a la caja desde `/api/pos/bootstrap`).
 * Es la mitad que importa: el guard del servidor
 * (`api/lib/Sales/InvoiceAuthGate.php`) solo se aplica cuando hay red, y sin
 * este bloqueo local el POS imprimiría el ticket y descubriría el rechazo recién
 * al sincronizar — con el comprobante ya en la mano del cliente.
 *
 * ## Qué NO bloquea
 *
 * Solo la emisión del documento fiscal. Cotizar, tomar órdenes y mandar
 * comandas siguen libres: no estampan timbrado ni consumen numeración. Mismo
 * criterio que el gate de tenencia (ver `cart-panel.tsx`, rama del CTA).
 */
import { useCatalogStore } from "@/lib/catalog/store"
import { useTenancyStore } from "@/lib/pos/tenancy-store"
import { registerBlockShortReason } from "@/lib/pos/register-conflict"
import { invoiceAuthBlockReason } from "@/lib/documents/invoice-auth-expiry"
import { tenantNow } from "@/lib/format-date"
import type { PosConfig, PosRegister } from "@/lib/types/pos-bootstrap"

export type EmissionBlockKind = "invoice-auth" | "tenancy"

export interface EmissionBlock {
  /** Una línea: qué pasa y qué hacer. Va al tooltip del control. */
  reason: string
  kind: EmissionBlockKind
}

type CatalogSlice = {
  registers: PosRegister[]
  activeRegisterId: string | null
  config: PosConfig | null
}

/**
 * Timbrado de la caja activa contra el día DEL TENANT.
 *
 * `tenantNow(timezone)` y no `new Date()`: el vencimiento es una fecha del
 * comercio, y un device con la zona mal configurada (o de viaje) no puede correr
 * el corte un día. Sin `timezone` en el bootstrap, `tenantNow` cae en la hora
 * local del device, que es la mejor aproximación disponible sin red.
 */
function invoiceAuthBlock(s: CatalogSlice): EmissionBlock | null {
  if (!s.activeRegisterId) return null
  const register = s.registers.find((r) => r.id === s.activeRegisterId)
  if (!register) return null

  const today = tenantNow(s.config?.timezone).slice(0, 10)
  const reason = invoiceAuthBlockReason(register.authExpiration, today)

  return reason === null ? null : { reason, kind: "invoice-auth" }
}

/**
 * Versión imperativa, para el camino de confirmación (`handleConfirm`), que
 * necesita la respuesta EN EL MOMENTO del click y no en el render — igual que
 * `useTenancyStore.getState().verdict` en ese mismo punto.
 */
export function emissionBlockNow(): EmissionBlock | null {
  const c = useCatalogStore.getState()
  const fromAuth = invoiceAuthBlock(c)
  if (fromAuth) return fromAuth

  const reason = registerBlockShortReason(useTenancyStore.getState().verdict)
  return reason === null ? null : { reason, kind: "tenancy" }
}

/**
 * Versión reactiva para el CTA. Se suscribe a los dos stores por separado para
 * no recalcular el carrito entero cuando cambia cualquier otra cosa del
 * catálogo.
 */
export function useEmissionBlock(): EmissionBlock | null {
  const registers = useCatalogStore((s) => s.registers)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const config = useCatalogStore((s) => s.config)
  const tenancyReason = useTenancyStore((s) => registerBlockShortReason(s.verdict))

  const fromAuth = invoiceAuthBlock({ registers, activeRegisterId, config })
  if (fromAuth) return fromAuth

  return tenancyReason === null ? null : { reason: tenancyReason, kind: "tenancy" }
}
