"use client"

import * as React from "react"
import { useCartStore } from "@/lib/cart/store"
import { useResolvePrices } from "@/hooks/use-price-lists"
import { posApi } from "@/lib/api/pos-client"
import { useCatalogStore } from "@/lib/catalog/store"
import { usePosUIStore } from "@/lib/ui/store"

/**
 * Resuelve precios de las líneas del carrito contra el contexto de precio
 * activo (cliente y/o lista elegida a mano) — único consumidor de
 * `/v1/price_resolve` en el POS. El backend (PriceListService::resolvePriceBatch)
 * ya resolvía la prioridad override de línea > lista del contacto > lista del
 * outlet, pero nada en el front lo llamaba (bug reportado en
 * context/10-roadmap.md §2026-07-30/07-31): el descuento/lista asignado a un
 * cliente no se aplicaba en caja.
 *
 * Se monta UNA sola vez, en `app/(pos)/pos/layout.tsx` (mismo patrón que
 * `useCatalogSeed`/`useHotkeys`) — el carrito vive ahí y se mantiene montado
 * entre rutas del workspace.
 *
 * Dispara un POST batch cuando cambia el cliente, la lista manual, el set
 * de itemIds no-overridden del carrito, o `priceResolveNonce` (debounce
 * corto para no pegarle un POST a cada tecla/add rápido). El nonce lo
 * incrementa `useRealtimeSync` al recibir un evento `price-list` — sin él,
 * un carrito ya armado seguía cobrando precios resueltos ANTES de que el
 * admin editara la lista activa (bug reportado 2026-08-16, `/v1/price_resolve`
 * es mutación sin queryKey, nada la invalidaba). Las líneas con
 * `priceOverridden: true` (precio editado a mano por el cajero) nunca se
 * mandan ni se pisan, tampoco en el re-disparo por nonce.
 *
 * Sin contexto (sin cliente Y sin lista manual): restaura `unitPrice =
 * basePrice` en las líneas no-overridden. Si el POST falla (offline/error),
 * se deja el precio base tal cual — no debe romper la venta.
 */
export function usePriceContext() {
  const customerId = useCartStore((s) => s.customer?.id)
  const priceListId = useCartStore((s) => s.priceListId)
  // Bump de useRealtimeSync cuando llega un evento `price-list` (admin
  // editó la lista activa mientras el carrito ya estaba armado — ver
  // context/15-realtime-sync-plan.md). Este hook NO sabe de realtime, solo
  // reacciona a que el número cambió — mismo patrón que `quoteSaveNonce`.
  const priceResolveNonce = usePosUIStore((s) => s.priceResolveNonce)
  // Clave derivada: itemId+basePrice de las líneas no-overridden. Cambia solo
  // cuando hay algo nuevo que resolver — qty/nota/selección no la tocan, así
  // que no re-disparan el efecto.
  const lineKey = useCartStore((s) =>
    s.lines
      .filter((l) => !l.priceOverridden)
      .map((l) => `${l.itemId}:${l.basePrice ?? l.unitPrice}`)
      .join("|"),
  )

  // outletId del device: sale del catalog store, que ya lo tiene del bootstrap
  // del POS. Sin esto, el escalón "lista default del outlet" de la prioridad
  // del backend queda sin efecto.
  //
  // Antes se leía de `useBootstrap({ client: posApi })` —el bootstrap del
  // PANEL pedido con el Bearer del device— apoyándose en que el layout del POS
  // ya lo tenía cacheado. Ese fetch se eliminó del layout (bloqueaba el
  // arranque offline), y traerlo de vuelta acá sería reintroducir en el POS
  // una dependencia de red que el catalog store ya resuelve local y offline.
  const outletId = useCatalogStore((s) => s.outlet?.id) || undefined

  const resolveMutation = useResolvePrices({ client: posApi })
  const resolveRef = React.useRef(resolveMutation)
  resolveRef.current = resolveMutation

  React.useEffect(() => {
    const hasContext = !!customerId || !!priceListId
    const lines = useCartStore.getState().lines.filter((l) => !l.priceOverridden)

    if (!hasContext) {
      useCartStore.getState().restoreBasePrices()
      return
    }

    if (lines.length === 0) return

    const timer = setTimeout(() => {
      const items = lines.map((l) => ({
        itemId: l.itemId,
        basePrice: l.basePrice ?? l.unitPrice,
      }))
      resolveRef.current.mutate(
        { items, contactId: customerId, outletId, priceListId: priceListId ?? undefined },
        {
          onSuccess: (resolved) => {
            const map = new Map(
              resolved.map((r) => [r.itemId, { price: r.price, priceListName: r.priceListName }]),
            )
            useCartStore.getState().applyResolvedPrices(map)
          },
          onError: (err) => {
            // Offline o error del backend: se mantienen los precios base, no
            // se rompe la venta — el cajero puede seguir cobrando.
            console.warn("[usePriceContext] price_resolve falló, se mantienen precios base", err)
          },
        },
      )
    }, 300)

    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customerId, priceListId, lineKey, outletId, priceResolveNonce])
}
