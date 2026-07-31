"use client"

import * as React from "react"
import { useCartStore } from "@/lib/cart/store"
import { useResolvePrices } from "@/hooks/use-price-lists"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { posApi } from "@/lib/api/pos-client"

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
 * Dispara un POST batch cuando cambia el cliente, la lista manual, o el set
 * de itemIds no-overridden del carrito (debounce corto para no pegarle un
 * POST a cada tecla/add rápido). Las líneas con `priceOverridden: true`
 * (precio editado a mano por el cajero) nunca se mandan ni se pisan.
 *
 * Sin contexto (sin cliente Y sin lista manual): restaura `unitPrice =
 * basePrice` en las líneas no-overridden. Si el POST falla (offline/error),
 * se deja el precio base tal cual — no debe romper la venta.
 */
export function usePriceContext() {
  const customerId = useCartStore((s) => s.customer?.id)
  const priceListId = useCartStore((s) => s.priceListId)
  // Clave derivada: itemId+basePrice de las líneas no-overridden. Cambia solo
  // cuando hay algo nuevo que resolver — qty/nota/selección no la tocan, así
  // que no re-disparan el efecto.
  const lineKey = useCartStore((s) =>
    s.lines
      .filter((l) => !l.priceOverridden)
      .map((l) => `${l.itemId}:${l.basePrice ?? l.unitPrice}`)
      .join("|"),
  )

  // outletId del device (bootstrap ya está cacheado por el layout — este
  // segundo useBootstrap no pega un fetch nuevo, react-query dedupea por
  // queryKey). Sin esto, el escalón "lista default del outlet" de la
  // prioridad del backend queda sin efecto.
  const { data: bootstrap } = useBootstrap({ client: posApi })
  const outletId = bootstrap?.activeOutletId || undefined

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
  }, [customerId, priceListId, lineKey, outletId])
}
