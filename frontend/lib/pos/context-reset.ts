/**
 * Reset del estado atado al CONTEXTO de la caja (sucursal + caja).
 *
 * El POS opera siempre dentro de una terna `companyId + outletId + registerId`.
 * Cuando el cajero mueve el device a otra sucursal o a otra caja desde Ajustes,
 * medio estado del cliente pasa a describir un lugar donde ya no está: los
 * precios son de otra lista, el stock es de otro depósito, la numeración es de
 * otro punto de expedición y las mesas son de otro salón. Seguir operando con
 * eso puesto no es "datos viejos en pantalla" — es emitir un documento con las
 * dimensiones equivocadas.
 *
 * Por qué vive acá y no en el componente de Ajustes
 * ─────────────────────────────────────────────────
 * El cambio de contexto es UNA operación (`POST /v1/active-register`) y hasta
 * ahora su `onSuccess` invalidaba el bootstrap y nada más: ningún store del
 * cliente se enteraba. Poner el vaciado en el `onChange` del selector lo dejaría
 * atado a ese selector, y el próximo call-site que mueva la caja (un deep link,
 * el panel empujando un cambio por sync, un flujo de re-pairing) volvería a
 * quedar con el carrito de la sucursal anterior. La regla de "qué es contexto"
 * pertenece al motor del cambio de contexto, no a un `<Select>`.
 *
 * Qué NO se toca
 * ──────────────
 * - `useWorkspaceStore` — preferencia de layout del device, no del contexto.
 * - `useLockStore` — identidad del operador; es company-scoped y sobrevive.
 * - La cola de operaciones offline — cada op está sellada con su `registerId` y
 *   el cerco de `pending-ops-sync` impide que se apliquen sobre otra caja.
 *   Vaciarla acá sería PERDER ventas ya emitidas.
 *
 * Los stores que ya se re-siembran solos (catalog, vía invalidate del bootstrap)
 * no necesitan reset explícito; los que se listan abajo no tienen re-seed y se
 * quedarían con datos del contexto viejo indefinidamente.
 */

import type { QueryClient } from "@tanstack/react-query"

import { useCartStore } from "@/lib/cart/store"
import { useAddonPickerStore } from "@/lib/cart/addon-picker-store"
import { useGiftcardIssueStore } from "@/lib/cart/giftcard-issue-store"
import { useSpaceSettlementStore } from "@/lib/spaces/settlement-store"
import { useHotkeysStore } from "@/lib/hotkeys/store"

/**
 * ¿Hay algo que el cajero perdería si se cambia de contexto ahora?
 *
 * Solo las líneas cuentan. Un cliente elegido o un modo distinto se vuelven a
 * poner en dos toques; una venta a medio cargar es trabajo real. Se usa para
 * decidir si hay que PEDIR CONFIRMACIÓN antes de descartar — el reset en sí
 * corre igual una vez confirmado.
 */
export function hasContextScopedWork(): boolean {
  return useCartStore.getState().lines.length > 0
}

/**
 * Limpia todo lo que pertenecía a la sucursal/caja anterior.
 *
 * Idempotente y sin red: se llama DESPUÉS de que el servidor confirmó el
 * cambio, junto con el invalidate del bootstrap.
 */
export function resetContextScopedState(qc: QueryClient): void {
  // ── Venta en curso ────────────────────────────────────────────────────────
  // `clear()` vuelve al `initialState` completo, así que con esta sola línea se
  // van también el cliente seleccionado, el modo (venta/orden/cotización), el
  // descuento de venta, las etiquetas, la nota, la lista de precios, el
  // fulfillment, la dirección de entrega, la mesa/sesión de espacio y los
  // vínculos a orden/cotización padre. Los descuentos por línea, el vendedor
  // por línea, los add-ons y los vouchers viven DENTRO de cada línea, así que
  // se van con ellas.
  //
  // Acá se llama `clear()` pelado y no `useClearCart()` —que es el entrypoint
  // canónico— a propósito: ese hook re-lockea `posMode` a "orden" leyendo el
  // `modoSoloOrdenes` de la caja ACTUAL, y en un cambio de contexto la caja
  // actual es justamente la que estamos dejando. El re-lock correcto, con el
  // flag de la caja NUEVA, lo aplica el efecto de `cart-panel.tsx` en cuanto el
  // bootstrap re-hidrata. Leer el flag viejo acá sería quedarse con la
  // respuesta de la caja equivocada.
  useCartStore.getState().clear()

  // ── Diálogos con un ítem del catálogo viejo colgado ───────────────────────
  // Sus `pendingItem` son `PosItem` de la sucursal anterior: si el diálogo
  // queda abierto tras el cambio, agrega un ítem que esta caja ya no vende.
  useAddonPickerStore.getState().close()
  useGiftcardIssueStore.getState().close()

  // ── Mesas en proceso de cobro ─────────────────────────────────────────────
  // Apuntan a una sesión de espacio del salón anterior por id.
  useSpaceSettlementStore.getState().setSplitTarget(null)
  useSpaceSettlementStore.getState().setSettlingSpace(null)

  // ── Hotkeys ───────────────────────────────────────────────────────────────
  // La grilla es POR CAJA, pero el store persiste en localStorage y se
  // re-hidrata recién cuando resuelve la query `["pos-hotkeys", registerId]`.
  // Sin este reset queda una ventana con la grilla de la caja anterior en
  // pantalla — y si el cajero entra a editar y toca "Listo" en esa ventana,
  // GUARDA los hotkeys de la caja vieja encima de la nueva. `reset()` además
  // sale del modo edición, que es lo correcto: se estaba editando otra caja.
  useHotkeysStore.getState().reset()

  // ── Caches de react-query scopeadas por outlet pero sin outlet en la key ──
  // El resto de las queries del POS ya llevan `registerId`/`outletId` en su
  // queryKey y se re-piden solas. Estas tres no, así que hay que invalidarlas
  // a mano o siguen mostrando el salón y las ventas aparcadas de la sucursal
  // anterior.
  qc.invalidateQueries({ queryKey: ["parked-sales"] })
  qc.invalidateQueries({ queryKey: ["pos-space-sectors"] })
  qc.invalidateQueries({ queryKey: ["pos-spaces"] })
}
