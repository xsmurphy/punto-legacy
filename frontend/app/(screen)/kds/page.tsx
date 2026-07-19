"use client"

import * as React from "react"
import { RefreshCw } from "lucide-react"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { usePairedScreen } from "@/hooks/use-paired-screen"
import { getDeviceToken } from "@/lib/auth/device-token"
import { loadKdsConfig, saveKdsConfig, type KdsConfig } from "@/lib/kds/config"
import type { Order, OrderItem } from "@/hooks/use-orders"
import { OrderCard } from "./order-card"
import { KdsConfigSheet } from "./config-sheet"

/**
 * KDS — pantalla de cocina device-paired (O2, context/24-orders-module-plan.md).
 * Mismo patrón de pairing/WS que `app/(screen)/checkout` (Device
 * Authorization Grant, module=kds, canal `{companyId}:kds:{outletId}`), pero
 * de solo-lectura + transición de ítems: NO cobra, NO edita el carrito.
 *
 * Dark por defecto (pantalla de cocina) — se fuerza con `className="dark"`
 * en el wrapper porque `(screen)/layout.tsx` fija forcedTheme="light" para
 * el checkout screen (visor al cliente). Tailwind v4 con
 * `@custom-variant dark (&:is(.dark *))` — el `.dark` en un div ancestro
 * alcanza para escopear el theme sin tocar el ThemeProvider global.
 */

const ACTIVE_STATUSES = ["sent", "in_progress", "ready"] as const

interface Station { id: string; name: string }

export default function KdsPage() {
  const [orders, setOrders] = React.useState<Map<string, Order>>(new Map())
  const [stations, setStations] = React.useState<Station[]>([])
  const [config, setConfig] = React.useState<KdsConfig>(() => loadKdsConfig())
  const [busyIds, setBusyIds] = React.useState<Set<string>>(new Set())
  const [loading, setLoading] = React.useState(true)

  const applyOrder = React.useCallback((order: Order) => {
    setOrders((prev) => {
      const next = new Map(prev)
      if ((ACTIVE_STATUSES as readonly string[]).includes(order.status)) {
        next.set(order.id, order)
      } else {
        next.delete(order.id)
      }
      return next
    })
  }, [])

  const fetchOrders = React.useCallback(async () => {
    const token = getDeviceToken("kds")
    if (!token) return
    try {
      const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
      const qs = ACTIVE_STATUSES.map((s) => `status[]=${s}`).join("&")
      const res = await fetch(`${apiUrl}/v1/orders-core?${qs}`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      if (!res.ok) return
      const body = (await res.json()) as { data?: { orders: Order[] } }
      const list = body.data?.orders ?? []
      // list() no trae ítems (mismo N+1 liviano documentado en O1 para /pos/ordenes)
      // — pedimos el detalle de cada una para tener los ítems que el KDS necesita.
      const detailed = await Promise.all(
        list.map(async (o) => {
          const r = await fetch(`${apiUrl}/v1/orders-core?id=${o.id}`, {
            headers: { Authorization: `Bearer ${token}` },
          })
          if (!r.ok) return o
          const b = (await r.json()) as { data?: Order }
          return b.data ?? o
        })
      )
      setOrders(() => {
        const next = new Map<string, Order>()
        for (const o of detailed) {
          if ((ACTIVE_STATUSES as readonly string[]).includes(o.status)) next.set(o.id, o)
        }
        return next
      })
    } finally {
      setLoading(false)
    }
  }, [])

  const fetchStations = React.useCallback(async (outletId: string) => {
    const token = getDeviceToken("kds")
    if (!token) return
    try {
      const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
      const res = await fetch(`${apiUrl}/v1/order-stations?outletId=${outletId}`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      if (!res.ok) return
      const body = (await res.json()) as { data?: { stations: Station[] } }
      setStations(body.data?.stations ?? [])
    } catch { /* best-effort */ }
  }, [])

  const { pairState, ctx } = usePairedScreen({
    module: "kds",
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

  React.useEffect(() => {
    if (ctx?.outletId) void fetchStations(ctx.outletId)
  }, [ctx?.outletId, fetchStations])

  function updateConfig(next: KdsConfig) {
    setConfig(next)
    saveKdsConfig(next)
  }

  async function postStatus(orderItemId: string, status: string) {
    const token = getDeviceToken("kds")
    if (!token) return
    const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
    const res = await fetch(`${apiUrl}/v1/orders-core?resource=item-status&id=${orderItemId}`, {
      method: "POST",
      headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
      body: JSON.stringify({ status }),
    })
    if (!res.ok) throw new Error("No se pudo actualizar el ítem")
    const body = (await res.json()) as { data?: Order }
    if (body.data) applyOrder(body.data)
  }

  function nextItemStatus(item: OrderItem): string | null {
    if (item.status === "pending") return "preparing"
    if (item.status === "preparing") return "ready"
    return null
  }

  async function bumpItem(item: OrderItem) {
    const next = nextItemStatus(item)
    if (!next) return
    setBusyIds((s) => new Set(s).add(item.id))
    const prevOrders = orders
    try {
      await postStatus(item.id, next)
    } catch {
      setOrders(prevOrders) // rollback optimista
    } finally {
      setBusyIds((s) => { const n = new Set(s); n.delete(item.id); return n })
    }
  }

  async function bumpOrder(order: Order, items: OrderItem[]) {
    if (items.length === 0) return
    setBusyIds((s) => new Set(s).add(order.id))
    const prevOrders = orders
    try {
      await Promise.all(
        items.map((item) => {
          const next = nextItemStatus(item)
          return next ? postStatus(item.id, next) : Promise.resolve()
        })
      )
    } catch {
      setOrders(prevOrders)
    } finally {
      setBusyIds((s) => { const n = new Set(s); n.delete(order.id); return n })
    }
  }

  if (pairState === "unpaired") {
    return <DeviceNotConnected kind="kds" />
  }

  const visible = Array.from(orders.values())
    .filter((o) => config.stationIds.length === 0 || (o.items ?? []).some((i) => i.stationId && config.stationIds.includes(i.stationId)))
    .sort((a, b) => new Date(a.sentAt ?? a.createdAt ?? 0).getTime() - new Date(b.sentAt ?? b.createdAt ?? 0).getTime())

  const cols = config.density === "compact"
    ? "grid-cols-[repeat(auto-fill,minmax(220px,1fr))]"
    : "grid-cols-[repeat(auto-fill,minmax(320px,1fr))]"

  function renderCards(list: Order[]) {
    return (
      <div className={`grid ${cols} gap-3 content-start`}>
        {list.map((order) => (
          <OrderCard
            key={order.id}
            order={order}
            config={config}
            busy={busyIds.has(order.id)}
            onBumpOrder={bumpOrder}
            onBumpItem={bumpItem}
          />
        ))}
      </div>
    )
  }

  return (
    <div className="dark flex h-screen flex-col bg-background text-foreground">
      <header className="flex items-center justify-between gap-3 border-b px-4 py-3">
        <div className="flex items-baseline gap-3">
          <h1 className="text-xl font-semibold">KDS — {ctx?.outletName ?? "Cocina"}</h1>
          {loading && <RefreshCw className="size-4 animate-spin text-muted-foreground" />}
        </div>
        <KdsConfigSheet config={config} stations={stations} onChange={updateConfig} />
      </header>

      <main className="flex-1 overflow-y-auto p-3">
        {visible.length === 0 ? (
          <div className="flex h-full items-center justify-center text-muted-foreground">
            <p style={{ fontSize: "clamp(1rem, 1.5vw, 1.5rem)" }}>Sin órdenes activas</p>
          </div>
        ) : config.columnMode === "stream" ? (
          renderCards(visible)
        ) : (
          <div className="grid h-full grid-cols-1 gap-4 lg:grid-cols-3">
            <section className="flex flex-col gap-2">
              <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Nuevas ({visible.filter((o) => o.status === "sent").length})
              </h2>
              {renderCards(visible.filter((o) => o.status === "sent"))}
            </section>
            <section className="flex flex-col gap-2">
              <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                En preparación ({visible.filter((o) => o.status === "in_progress").length})
              </h2>
              {renderCards(visible.filter((o) => o.status === "in_progress"))}
            </section>
            <section className="flex flex-col gap-2">
              <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Listas ({visible.filter((o) => o.status === "ready").length})
              </h2>
              {renderCards(visible.filter((o) => o.status === "ready"))}
            </section>
          </div>
        )}
      </main>
    </div>
  )
}
