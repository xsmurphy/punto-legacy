"use client"

import * as React from "react"
import { RefreshCw } from "lucide-react"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { usePairedScreen } from "@/hooks/use-paired-screen"
import { getDeviceToken } from "@/lib/auth/device-token"
import type { Order, OrderItem, OrderStatus } from "@/hooks/use-orders"
import { STATUS_LABEL } from "@/lib/orders/order-display"
import { loadScreenTheme, resolveScreenMode, saveScreenTheme, type ScreenTheme } from "@/lib/screens/theme"
import { ScreenThemeToggle } from "@/components/screens/screen-theme-toggle"
import { DisplayColumn } from "./display-column"

/**
 * Pantalla de DESPACHO — device-paired (O2, context/24-orders-module-plan.md).
 *
 * "Despacho" y no "mozos": Punto es multi-vertical (context/20 §1.5,
 * terminología vertical-neutral). Un depósito de electrónica recibe pedidos del
 * ecommerce, alguien prepara con el KDS y otra persona despacha con ESTA — ahí
 * no hay ningún mozo. La key del module sigue siendo `display`, que ya es
 * genérica: renombrarla obligaría a migrar `device.module` de dispositivos
 * pareados en producción.
 * Mismo pairing/WS que el KDS (canal `{companyId}:kds:{outletId}`).
 *
 * BOARD DE 3 COLUMNAS POR ESTADO DE ORDEN (rediseño 2026-07-28)
 * ---------------------------------------------------------------
 * Antes esta pantalla solo mostraba órdenes con algún ítem `ready` — quien
 * despacha no veía lo que venía en camino. Ahora muestra las tres etapas
 * (`sent`/`in_progress`/`ready`, labels de `STATUS_LABEL`) para dar
 * visibilidad de todo el flujo, pero SOLO la columna "Listo" es accionable:
 * el backend únicamente permite a module=display la transición a `delivered`
 * (`assertModuleCanSetStatus` en orders-core.php). Las otras dos son
 * informativas — sus tarjetas son `div`, no `button`: sin affordance de
 * click, cursor default, opacidad reducida (`display-card.tsx`).
 *
 * Una orden entra a "Listo" cuando el BACKEND la pasa a `ready`
 * (`recomputeOrderStatus` en OrderCoreService, server-side) — el cliente no
 * recalcula nada, solo lee `order.status`.
 *
 * Escala tipográfica: mismo mecanismo que el KDS (`kds/page.tsx`) — la grilla
 * mide su ancho real con `ResizeObserver` y lo expone como `--board-col`;
 * las tarjetas escalan con `clamp()` sobre esa variable (`display-card.tsx`).
 *
 * RESPONSIVE: 3 columnas no entran en un teléfono. Igual que el KDS, el
 * ancho medido decide — por debajo de `3 * MIN_COL_PX` colapsa a UNA columna
 * con selector de estado (Tabs), default "Listo" por ser lo accionable.
 */

const ACTIVE_STATUSES = ["sent", "in_progress", "ready"] as const
type BoardStatus = (typeof ACTIVE_STATUSES)[number]

/** Ancho mínimo legible de una columna — mismo criterio que `MIN_CARD_PX` del KDS. */
const MIN_COL_PX = 260
/** gap-3 de la grilla, en px. */
const GRID_GAP_PX = 12

const COLUMNS: { status: BoardStatus; interactive: boolean }[] = [
  { status: "sent", interactive: false },
  { status: "in_progress", interactive: false },
  { status: "ready", interactive: true },
]

export default function DisplayPage() {
  const [orders, setOrders] = React.useState<Map<string, Order>>(new Map())
  const [busyIds, setBusyIds] = React.useState<Set<string>>(new Set())
  const [loading, setLoading] = React.useState(true)
  const [gridWidth, setGridWidth] = React.useState(0)
  // Selector de estado en modo teléfono — arranca en "Listo": es lo accionable.
  const [selectedStatus, setSelectedStatus] = React.useState<BoardStatus>("ready")
  // Tono de pantalla. Arranca en "dark" (comportamiento previo) y se resuelve
  // en un efecto — la preferencia vive en localStorage, leerla durante el
  // render sería un mismatch de hidratación garantizado.
  const [screenTheme, setScreenTheme] = React.useState<ScreenTheme>("dark")
  const [mode, setMode] = React.useState<"dark" | "light">("dark")

  const gridRef = React.useRef<HTMLDivElement>(null)

  React.useEffect(() => {
    setScreenTheme(loadScreenTheme("display", "dark"))
  }, [])

  function changeTheme(theme: ScreenTheme) {
    setScreenTheme(theme)
    saveScreenTheme("display", theme)
  }

  // En "auto" se re-evalúa sola: la pantalla no se recarga nunca, así que el
  // cambio de turno tiene que llegarle igual.
  React.useEffect(() => {
    const apply = () => setMode(resolveScreenMode(screenTheme))
    apply()
    if (screenTheme !== "auto") return
    const t = setInterval(apply, 60_000)
    return () => clearInterval(t)
  }, [screenTheme])

  const applyOrder = React.useCallback((order: Order) => {
    setOrders((prev) => {
      const next = new Map(prev)
      if ((ACTIVE_STATUSES as readonly string[]).includes(order.status)) {
        next.set(order.id, order)
      } else {
        // closed/cancelled/delivered salen del board — una orden entregada que
        // se queda pegada en pantalla es peor que no mostrarla.
        next.delete(order.id)
      }
      return next
    })
  }, [])

  const fetchOrders = React.useCallback(async () => {
    const token = getDeviceToken("display")
    if (!token) return
    try {
      const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
      const qs = ACTIVE_STATUSES.map((s) => `status[]=${s}`).join("&")
      // `includeItems=1`: el listado adjunta los ítems de todas las órdenes en
      // una sola query batched — sin esto había un fetch de detalle POR ORDEN
      // (N+1) que empeora con más órdenes en pantalla (ver `useActiveOrders`
      // en hooks/use-orders.ts, mismo patrón).
      const res = await fetch(`${apiUrl}/v1/orders-core?${qs}&includeItems=1`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      if (!res.ok) return
      const body = (await res.json()) as { data?: { orders: Order[] } }
      const list = body.data?.orders ?? []
      setOrders(() => {
        const next = new Map<string, Order>()
        for (const o of list) {
          if ((ACTIVE_STATUSES as readonly string[]).includes(o.status)) next.set(o.id, o)
        }
        return next
      })
    } finally {
      setLoading(false)
    }
  }, [])

  const { pairState, ctx } = usePairedScreen({
    module: "display",
    channels: (c) => [`${c.companyId}:kds:${c.outletId}`],
    onEvent: (event, data) => {
      if (event === "order:new" || event === "order:status") {
        applyOrder(data as Order)
      } else if (event === "order:item-status") {
        const payload = data as { order: Order }
        applyOrder(payload.order)
      }
    },
    onOpen: () => { void fetchOrders() },
  })

  async function postDelivered(orderItemId: string) {
    const token = getDeviceToken("display")
    if (!token) return
    const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
    const res = await fetch(`${apiUrl}/v1/orders-core?resource=item-status&id=${orderItemId}`, {
      method: "POST",
      headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
      body: JSON.stringify({ status: "delivered" }),
    })
    if (!res.ok) throw new Error("No se pudo marcar como entregado")
    const body = (await res.json()) as { data?: Order }
    if (body.data) applyOrder(body.data)
  }

  async function deliverItem(item: OrderItem) {
    setBusyIds((s) => new Set(s).add(item.id))
    const prevOrders = orders
    try {
      await postDelivered(item.id)
    } catch {
      setOrders(prevOrders)
    } finally {
      setBusyIds((s) => { const n = new Set(s); n.delete(item.id); return n })
    }
  }

  async function deliverAll(order: Order, items: OrderItem[]) {
    setBusyIds((s) => new Set(s).add(order.id))
    const prevOrders = orders
    try {
      await Promise.all(items.map((item) => postDelivered(item.id)))
    } catch {
      setOrders(prevOrders)
    } finally {
      setBusyIds((s) => { const n = new Set(s); n.delete(order.id); return n })
    }
  }

  /** Ancho medido de la grilla — decide cols (3 o 1) y el tamaño de letra. */
  React.useEffect(() => {
    const el = gridRef.current
    if (!el || typeof ResizeObserver === "undefined") return
    const ro = new ResizeObserver((entries) => {
      const w = entries[0]?.contentRect.width
      if (typeof w === "number") setGridWidth(w)
    })
    ro.observe(el)
    return () => ro.disconnect()
  }, [])

  if (pairState === "unpaired") {
    return <DeviceNotConnected kind="display" />
  }

  const byStatus = new Map<BoardStatus, Order[]>()
  for (const status of ACTIVE_STATUSES) byStatus.set(status, [])
  for (const order of orders.values()) {
    const bucket = byStatus.get(order.status as BoardStatus)
    if (bucket) bucket.push(order)
  }
  for (const bucket of byStatus.values()) {
    bucket.sort((a, b) => new Date(a.sentAt ?? a.createdAt ?? 0).getTime() - new Date(b.sentAt ?? b.createdAt ?? 0).getTime())
  }

  // Tres columnas no entran en un teléfono: por debajo del piso, colapsa a
  // una sola con selector — mismo criterio de "ancho real manda" que el KDS.
  const threeColsFit = gridWidth === 0 || gridWidth >= MIN_COL_PX * 3 + GRID_GAP_PX * 2
  const cols = threeColsFit ? 3 : 1
  const colWidth = gridWidth > 0 ? (threeColsFit ? (gridWidth - GRID_GAP_PX * 2) / 3 : gridWidth) : 0

  const visibleColumns = threeColsFit ? COLUMNS : COLUMNS.filter((c) => c.status === selectedStatus)

  return (
    <div className={`${mode === "dark" ? "dark " : ""}flex h-screen flex-col bg-background text-foreground`}>
      <header className="flex items-center gap-3 border-b px-4 py-3">
        <h1 className="text-xl font-semibold">Despacho — {ctx?.outletName ?? ""}</h1>
        {loading && <RefreshCw className="size-4 animate-spin text-muted-foreground" />}
        {!threeColsFit && (
          <Tabs value={selectedStatus} onValueChange={(v) => setSelectedStatus(v as BoardStatus)} className="ml-auto">
            <TabsList>
              {COLUMNS.map((c) => (
                <TabsTrigger key={c.status} value={c.status}>
                  {STATUS_LABEL[c.status as OrderStatus]}
                </TabsTrigger>
              ))}
            </TabsList>
          </Tabs>
        )}
        <ScreenThemeToggle theme={screenTheme} onChange={changeTheme} className={threeColsFit ? "ml-auto size-11" : "size-11"} />
      </header>

      <main className="min-h-0 flex-1 p-3">
        <div
          ref={gridRef}
          className="grid h-full min-h-0 gap-3"
          style={{
            gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))`,
            ["--board-col" as string]: `${colWidth}px`,
          }}
        >
          {visibleColumns.map((c) => (
            <DisplayColumn
              key={c.status}
              label={STATUS_LABEL[c.status as OrderStatus]}
              orders={byStatus.get(c.status) ?? []}
              interactive={c.interactive}
              busyIds={busyIds}
              onDeliverAll={deliverAll}
              onDeliverItem={deliverItem}
            />
          ))}
        </div>
      </main>
    </div>
  )
}
