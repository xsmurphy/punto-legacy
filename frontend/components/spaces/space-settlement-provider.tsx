"use client"

/**
 * Dueño persistente del flujo "cobrar un espacio" (context/15 §F3, bug T8).
 *
 * Montado UNA vez en `app/(pos)/pos/layout.tsx` — junto a `CartPanel` y
 * `PayDialog` (dentro de `CartPanel`), fuera del slot de rutas — así
 * sobrevive a la navegación entre módulos del POS. Antes este componente
 * (el diálogo de split + `handleSplitCharge` + la reconciliación post-cobro)
 * vivía en `app/(pos)/pos/espacios/page.tsx`, y ese módulo asumía seguir
 * montado detrás del carrito. Cierto en desktop; falso en mobile/tablet,
 * donde `/pos/espacios` se pinta como Dialog fullscreen ENCIMA del CartPanel
 * (`moduleAsDialog` en el layout) — el carrito recién cargado y su botón
 * "Cliente" quedaban tapados.
 *
 * Con esto movido acá, `handleSplitCharge` puede navegar a `/pos` después de
 * cargar el carrito (igual que `confirmOpenTable`/`handleAddOrder`, que ya lo
 * hacían) sin romper nada: el diálogo de split y la reconciliación siguen
 * vivos pase lo que pase con la ruta.
 *
 * Estado en `lib/spaces/settlement-store.ts` (no acá ni en la página): tiene
 * que sobrevivir el desmontaje de `/pos/espacios` Y el reset de
 * `clearCart()`. Ver el docblock de ese store para el porqué.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { toast } from "sonner"
import { SplitBillDialog, type SplitSelection } from "@/components/spaces/split-bill-dialog"
import { fetchOrderDetail, fetchOrdersBySession } from "@/hooks/use-orders"
import { fetchSessionBalance, useSessionBalance, type SessionBalance } from "@/hooks/use-space-settlement"
import { useCartStore, type SettlementIntent } from "@/lib/cart/store"
import {
  buildItemsLines,
  buildProportionalLines,
  currencyDecimals,
  sourcesFromOrders,
  splitShares,
  MONEY_EPSILON,
  type SettlementSource,
} from "@/lib/spaces/settlement-lines"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney } from "@/lib/format-money"
import { usePosUIStore } from "@/lib/ui/store"
import { useSpaceSettlementStore } from "@/lib/spaces/settlement-store"

/**
 * El monto a cobrar no puede exceder el saldo RECIÉN LEÍDO. El backend
 * también lo valida, pero recién cuando se registra el pago — es decir,
 * después de haber creado la venta. Un rechazo ahí deja plata cobrada sin
 * renglón en el ledger.
 */
function assertFitsBalance(target: number, balance: SessionBalance): void {
  if (target - balance.balance > MONEY_EPSILON) {
    throw new Error(
      "El saldo del espacio cambió (otro cobro entró recién). Revisá el monto e intentá de nuevo.",
    )
  }
}

export function SpaceSettlementProvider() {
  const router = useRouter()
  const [preparingCharge, setPreparingCharge] = React.useState(false)
  const chargeInFlight = React.useRef(false)

  const config = useCatalogStore((s) => s.config)
  const loadFromSession = useCartStore((s) => s.loadFromSession)
  const loadForSettlement = useCartStore((s) => s.loadForSettlement)
  const payOpen = usePosUIStore((s) => s.payOpen)

  const splitTarget = useSpaceSettlementStore((s) => s.splitTarget)
  const setSplitTarget = useSpaceSettlementStore((s) => s.setSplitTarget)
  const settlingSpace = useSpaceSettlementStore((s) => s.settlingSpace)
  const setSettlingSpace = useSpaceSettlementStore((s) => s.setSettlingSpace)

  const { refetch: refetchSettlingBalance } = useSessionBalance(
    settlingSpace?.sessionId ?? null,
  )

  /**
   * Arma el carrito para el modo elegido y navega a `/pos`.
   *
   * - `total` sin pagos previos → camino de SIEMPRE (`loadFromSession`):
   *   markPaid de cada orden + close de la sesión los sigue haciendo
   *   `pay-dialog.tsx`. Intacto.
   * - Cualquier otro caso → cobro parcial (`loadForSettlement`): la venta se
   *   registra en el ledger y el cierre lo decide el backend.
   *
   * Todo lo que puede fallar (ítem sin artículo de catálogo, monto que no
   * entra) falla ACÁ, antes de crear la venta — después de cobrar ya no hay
   * vuelta atrás.
   */
  async function handleSplitCharge(selection: SplitSelection) {
    const target = splitTarget
    if (!target) return
    // Guarda de doble tap: `preparingCharge` deshabilita el botón, pero entre
    // dos taps consecutivos puede no haber re-render. Dos cobros en vuelo
    // serían dos ventas (el backend deduplica el LEDGER por transactionId,
    // no las transacciones — serían dos comprobantes por la misma parte).
    if (chargeInFlight.current) return
    chargeInFlight.current = true
    const { sessionId, spaceName } = target
    setPreparingCharge(true)
    try {
      // El saldo se RELEE acá, no se usa el que mostró el diálogo: entre que
      // se abrió y el cajero tocó "Cobrar" pudo entrar un parcial de otra
      // caja. Con un `paid` viejo se tomaría el camino de espacio completo
      // (markPaid + close, sin pasar por el ledger) sobre un espacio que ya
      // tenía plata cobrada, y `SpaceSessionService::close()` no valida
      // saldo: nadie lo atraparía. El saldo cacheado es para mirar; para
      // cobrar, este.
      const [balance, { orders: summaries }] = await Promise.all([
        fetchSessionBalance(sessionId),
        fetchOrdersBySession(sessionId),
      ])
      const billable = summaries.filter((o) => o.status !== "closed" && o.status !== "cancelled")
      if (billable.length === 0) {
        toast.error("El espacio no tiene órdenes por cobrar")
        return
      }
      const orders = await Promise.all(billable.map((o) => fetchOrderDetail(o.id)))

      if (selection.mode === "total" && balance.paid <= 0) {
        loadFromSession(sessionId, spaceName, orders)
        setSplitTarget(null)
        // Mismo criterio que `confirmOpenTable`/`handleAddOrder`
        // (espacios/page.tsx): navegar a `/pos` deja el carrito recién
        // cargado accesible con su botón "Cliente" — antes NO se navegaba acá
        // porque la reconciliación post-cobro dependía de que este componente
        // siguiera montado; ahora vive en un provider persistente del layout
        // (ver docblock del archivo), así que navegar es seguro.
        router.push("/pos")
        return
      }

      const sources = sourcesFromOrders(orders)
      const decimals = currencyDecimals(config)

      let lines
      let intent: SettlementIntent

      if (selection.mode === "items") {
        // Contra el saldo recién leído: si otra caja cobró alguno de estos
        // ítems mientras el diálogo estaba abierto, el CAS del backend
        // abortaría — pero recién DESPUÉS de crear la venta, con la plata ya
        // cobrada. Se corta acá.
        const alreadySettled = balance.items.filter(
          (i) => i.settled && selection.orderItemIds.includes(i.id),
        )
        if (alreadySettled.length > 0) {
          toast.error("Otro cobro ya se llevó alguno de esos ítems", {
            description: "El saldo del espacio cambió. Revisá la selección.",
          })
          setSplitTarget(target)
          return
        }
        // El catálogo se lee acá (no por suscripción): es el mismo hidratado
        // que usan los loaders del carrito, y hace falta para re-hidratar las
        // `selections` de add-on del ítem cobrado. Sin red: offline-first.
        lines = buildItemsLines(
          sources,
          selection.orderItemIds,
          useCatalogStore.getState().items,
        )
        intent = { sessionId, kind: "items", orderItemIds: selection.orderItemIds }
      } else {
        // Base del prorrateo: SOLO los ítems todavía no saldados — los ya
        // cobrados por `kind='items'` no se vuelven a facturar ni a
        // descontar de stock.
        const unsettled = balance.items
          .filter((i) => !i.settled)
          .map((i) => sources.get(i.id))
          .filter((s): s is SettlementSource => s !== undefined)

        if (selection.mode === "share") {
          const shareTarget = splitShares(balance.total, selection.shareCount, decimals)[
            selection.shareIndex - 1
          ]
          assertFitsBalance(shareTarget, balance)
          lines = buildProportionalLines(
            unsettled,
            shareTarget,
            decimals,
            `Parte ${selection.shareIndex} de ${selection.shareCount}`,
          )
          intent = {
            sessionId,
            kind: "share",
            shareCount: selection.shareCount,
            shareIndex: selection.shareIndex,
          }
        } else {
          // `amount` explícito, o `total` con pagos previos (se cobra el saldo).
          const amountTarget = selection.mode === "amount" ? selection.amount : balance.balance
          assertFitsBalance(amountTarget, balance)
          lines = buildProportionalLines(unsettled, amountTarget, decimals)
          intent = { sessionId, kind: "amount", amount: amountTarget }
        }
      }

      loadForSettlement(spaceName, lines, intent)
      setSettlingSpace(target)
      setSplitTarget(null)
      // Mismo criterio que el camino "total": el carrito queda cargado y el
      // cajero navega a `/pos` con el botón "Cliente" a mano.
      router.push("/pos")
    } catch (err) {
      toast.error("No se pudo preparar el cobro del espacio", {
        description: err instanceof Error ? err.message : String(err),
      })
    } finally {
      chargeInFlight.current = false
      setPreparingCharge(false)
    }
  }

  // ── Post-cobro parcial ────────────────────────────────────────────────────
  //
  // El PayDialog se cerró y había un cobro parcial en curso: se relee el
  // saldo (el registro en el ledger lo invalida, esto además cubre el caso de
  // que todavía estuviera en vuelo). Saldo 0 → el backend ya cerró órdenes y
  // sesión, el espacio quedó libre. Saldo > 0 → se reabre el split para
  // cobrar la parte siguiente, ya con el saldo nuevo — sea cual sea la ruta
  // en la que esté el cajero en ese momento (por eso este efecto vive acá y
  // no en `/pos/espacios`).
  const prevPayOpen = React.useRef(payOpen)
  React.useEffect(() => {
    const wasOpen = prevPayOpen.current
    prevPayOpen.current = payOpen
    if (!wasOpen || payOpen || !settlingSpace) return

    const target = settlingSpace
    void (async () => {
      try {
        // El refetch va ANTES de limpiar `settlingSpace`: al limpiarlo, el
        // sessionId del hook pasa a null y la query queda deshabilitada.
        const { data } = await refetchSettlingBalance()
        const remaining = data?.balance ?? 0
        if (remaining > MONEY_EPSILON) {
          toast.info(`${target.spaceName} — saldo pendiente ${formatMoney(remaining, config)}`)
          setSplitTarget(target)
        } else {
          toast.success(`${target.spaceName} — cuenta saldada`)
        }
      } catch {
        // Sin saldo confiable no se decide nada: el mapa se refresca solo por
        // la invalidación de ["spaces"] y el cajero reabre el espacio si hace falta.
      } finally {
        setSettlingSpace(null)
      }
    })()
  }, [payOpen, settlingSpace, refetchSettlingBalance, config])

  return (
    <SplitBillDialog
      target={splitTarget}
      onOpenChange={(v) => !v && setSplitTarget(null)}
      onCharge={handleSplitCharge}
      preparing={preparingCharge}
    />
  )
}
