"use client"

import * as React from "react"
import { RefreshCw } from "lucide-react"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { usePairedScreen } from "@/hooks/use-paired-screen"
import { getDeviceToken } from "@/lib/auth/device-token"
import type { Order, OrderItem } from "@/hooks/use-orders"
import { ReadyCard } from "./ready-card"

/**
 * Pantalla de DESPACHO — device-paired (O2, context/24-orders-module-plan.md).
 *
 * "Despacho" y no "mozos": Punto es multi-vertical (context/20 §1.5,
 * terminología vertical-neutral). Un depósito de electrónica recibe pedidos del
 * ecommerce, alguien prepara con el KDS y otra persona despacha con ESTA — ahí
 * no hay ningún mozo. La key del module sigue siendo `display`, que ya es
 * genérica: renombrarla obligaría a migrar `device.module` de dispositivos
 * pareados en producción.
 * Mismo pairing/WS que el KDS (canal `{companyId}:kds:{outletId}`), pero
 * SOLO lee ítems en estado `ready` (agrupados por orden) y los marca
 * `delivered` — el backend bloquea cualquier otra transición para
 * module=display (ver assertModuleCanSetStatus en orders-core.php).
 */

const ACTIVE_STATUSES = ["sent", "in_progress", "ready"] as const

export default function DisplayPage() {
  const [orders, setOrders] = React.useState<Map<string, Order>>(new Map())
  const [busyIds, setBusyIds] = React.useState<Set<string>>(new Set())
  const [loading, setLoading] = React.useState(true)

  const applyOrder = React.useCallback((order: Order) => {
    setOrders((prev) => {
      const next = new Map(prev)
      const hasReady = (order.items ?? []).some((i) => i.status === "ready")
      if ((ACTIVE_STATUSES as readonly string[]).includes(order.status) && hasReady) {
        next.set(order.id, order)
      } else {
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
      const res = await fetch(`${apiUrl}/v1/orders-core?${qs}`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      if (!res.ok) return
      const body = (await res.json()) as { data?: { orders: Order[] } }
      const list = body.data?.orders ?? []
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
          const hasReady = (o.items ?? []).some((i) => i.status === "ready")
          if ((ACTIVE_STATUSES as readonly string[]).includes(o.status) && hasReady) next.set(o.id, o)
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

  if (pairState === "unpaired") {
    return <DeviceNotConnected kind="display" />
  }

  const visible = Array.from(orders.values())
    .sort((a, b) => new Date(a.sentAt ?? a.createdAt ?? 0).getTime() - new Date(b.sentAt ?? b.createdAt ?? 0).getTime())

  return (
    <div className="dark flex h-screen flex-col bg-background text-foreground">
      <header className="flex items-center gap-3 border-b px-4 py-3">
        <h1 className="text-xl font-semibold">Listo para entregar — {ctx?.outletName ?? ""}</h1>
        {loading && <RefreshCw className="size-4 animate-spin text-muted-foreground" />}
      </header>

      <main className="flex-1 overflow-y-auto p-3">
        {visible.length === 0 ? (
          <div className="flex h-full items-center justify-center text-muted-foreground">
            <p style={{ fontSize: "clamp(1rem, 1.5vw, 1.5rem)" }}>Nada listo por ahora</p>
          </div>
        ) : (
          <div className="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] content-start gap-3">
            {visible.map((order) => (
              <ReadyCard
                key={order.id}
                order={order}
                readyItems={(order.items ?? []).filter((i) => i.status === "ready")}
                busy={busyIds.has(order.id)}
                onDeliverAll={deliverAll}
                onDeliverItem={deliverItem}
              />
            ))}
          </div>
        )}
      </main>
    </div>
  )
}
