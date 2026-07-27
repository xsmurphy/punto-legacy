"use client"

import * as React from "react"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { usePairedScreen } from "@/hooks/use-paired-screen"
import { getDeviceToken } from "@/lib/auth/device-token"
import { loadKdsConfig, saveKdsConfig, type KdsConfig } from "@/lib/kds/config"
import { applyPinOrder, loadKdsPins, purgeKdsPins, saveKdsPins, togglePin } from "@/lib/kds/pins"
import { closeKdsSound, kdsSoundState, playKdsChime, unlockKdsSound, type KdsSoundState } from "@/lib/kds/sound"
import type { KdsOrderStatus } from "@/lib/kds/kds-visuals"
import type { Order, OrderItem, OrderItemStatus } from "@/hooks/use-orders"
import { OrderCard } from "./order-card"
import { KdsBottomBar } from "./bottom-bar"
import { KdsConfigDialog } from "./config-dialog"

/**
 * KDS — pantalla de cocina device-paired (O2, context/24-orders-module-plan.md).
 * Mismo patrón de pairing/WS que `app/(screen)/checkout` (Device Authorization
 * Grant, module=kds, canal `{companyId}:kds:{outletId}`), pero de solo-lectura
 * + transición de ítems: NO cobra, NO edita el carrito.
 *
 * FLUJO HORIZONTAL DE COMANDAS (rediseño 2026-07-27)
 * --------------------------------------------------
 * Antes: tres columnas por estado. La cocina prioriza por TIEMPO, no por
 * estado, así que las columnas gastaban el ancho de la pantalla en estados
 * (un tercio cada uno, vacíos o no) en vez de gastarlo en comandas. Y lo
 * decisivo: al cambiar de estado la tarjeta SALTABA de columna — el cocinero
 * veía moverse justo lo que estaba leyendo.
 *
 * Ahora: las comandas van una al lado de la otra ordenadas por tiempo, cada
 * una ocupando todo el alto. **Una tarjeta no cambia de posición nunca**: ni al
 * cambiar de estado (eso es color, ver `lib/kds/kds-visuals.ts`) ni al marcar
 * un ítem (el ítem se colorea en su misma línea). Lo único que reordena es que
 * entren o salgan comandas, y el pin existe justamente para blindarse de eso.
 *
 * OVERFLOW = PAGINACIÓN, NO SCROLL. Es una TV desatendida: un scroll
 * horizontal que nadie va a tocar esconde comandas para siempre. Las páginas
 * rotan solas cada 12s, y CUALQUIER interacción (marcar, pinear, pasar de
 * página a mano, swipe) congela la rotación 30s — nunca se va la página que el
 * cocinero está usando bajo sus manos.
 *
 * TV / TABLET / TELÉFONO — la misma pantalla en los tres
 * -----------------------------------------------------
 * `cardsPerScreen` es un MÁXIMO PREFERIDO, no una cantidad impuesta: la
 * cantidad real es `min(preferencia, cuántas entran con MIN_CARD_PX)`. Si el
 * operador pide 8 y la pantalla da para 2, se muestran 2 y el resto pagina. En
 * un teléfono vertical eso colapsa naturalmente a UNA comanda a pantalla
 * completa — el flujo horizontal sigue siendo el modelo, solo que de a una.
 *
 * Es el mismo resultado que `repeat(auto-fill, minmax(MIN_CARD_PX, 1fr))` con
 * la preferencia como techo, pero calculado en JS sobre el ancho medido porque
 * la paginación necesita saber CUÁNTAS entraron: con `auto-fill` el número lo
 * decide el motor de layout y el tamaño de página quedaría adivinado.
 *
 * Sin hover ni interacción obligatoria: en una TV nadie toca la pantalla. Toda
 * la información se lee sin tocar nada y la paginación avanza sola; el pin, el
 * bump y el swipe son affordances de tablet/teléfono, nunca requisitos.
 *
 * Dark forzado con `className="dark"` en el wrapper: `(screen)/layout.tsx` fija
 * forcedTheme="light" para el checkout screen (visor al cliente). Tailwind v4
 * con `@custom-variant dark (&:is(.dark *))` — el `.dark` en un div ancestro
 * alcanza para escopear el theme sin tocar el ThemeProvider global.
 */

const ACTIVE_STATUSES = ["sent", "in_progress", "ready"] as const

/**
 * Ancho mínimo legible de una comanda (nº de orden + "2× Milanesa napolitana"
 * sin cortar). Por debajo de esto se muestran MENOS comandas y se pagina, nunca
 * se encogen más. Con 260px: un teléfono de 375px da 1 (pantalla completa), un
 * tablet vertical de 768px da 2, uno horizontal de 1024px da 3, y una TV de
 * 1920px da hasta 7 — ahí manda la preferencia del operador.
 */
const MIN_CARD_PX = 260
/** gap-2 de la grilla, en px — se descuenta para calcular el ancho real de columna. */
const GRID_GAP_PX = 8
const AUTO_PAGE_MS = 12_000
const INTERACTION_PAUSE_MS = 30_000
/** Desplazamiento horizontal mínimo para que un swipe cuente como cambio de página. */
const SWIPE_PX = 48

interface Station { id: string; name: string }

export default function KdsPage() {
  const [orders, setOrders] = React.useState<Map<string, Order>>(new Map())
  const [stations, setStations] = React.useState<Station[]>([])
  const [config, setConfig] = React.useState<KdsConfig>(() => loadKdsConfig())
  const [pins, setPins] = React.useState<string[]>(() => loadKdsPins())
  const [busyIds, setBusyIds] = React.useState<Set<string>>(new Set())
  const [loading, setLoading] = React.useState(true)
  const [hydrated, setHydrated] = React.useState(false)
  const [soundState, setSoundState] = React.useState<KdsSoundState>("blocked")
  const [page, setPage] = React.useState(0)
  const [gridWidth, setGridWidth] = React.useState(0)

  const gridRef = React.useRef<HTMLDivElement>(null)
  const pauseUntilRef = React.useRef(0)
  const swipeStartRef = React.useRef<{ x: number; y: number } | null>(null)
  /**
   * Un swipe termina con el dedo ENCIMA de una tarjeta, y el browser dispara el
   * `click` igual: sin esto, pasar de página marcaría toda la comanda como
   * preparada. Se descarta el click sintético inmediatamente posterior al
   * gesto.
   */
  const suppressClickUntilRef = React.useRef(0)

  /** Congela la rotación automática: el cocinero está mirando ESTA página. */
  const registerInteraction = React.useCallback(() => {
    pauseUntilRef.current = Date.now() + INTERACTION_PAUSE_MS
  }, [])

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
      // Recién con un sync completo encima sabemos qué órdenes siguen vivas —
      // antes de esto purgar pins borraría TODO (el mapa arranca vacío).
      setHydrated(true)
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

  /** ¿La comanda tiene algo para ESTA estación? (mismo criterio que el filtro visible). */
  const matchesStations = React.useCallback(
    (order: Order) => {
      if (config.stationIds.length === 0) return true
      const items = order.items
      if (!items) return true // el detalle todavía no llegó — no la escondemos
      return items.some((i) => i.stationId && config.stationIds.includes(i.stationId))
    },
    [config.stationIds]
  )

  const { pairState, ctx } = usePairedScreen({
    module: "kds",
    channels: (c) => [`${c.companyId}:kds:${c.outletId}`],
    onEvent: (event, data) => {
      if (event === "order:new") {
        const order = data as Order
        applyOrder(order)
        if (config.soundOnNew && matchesStations(order)) playKdsChime()
      } else if (event === "order:status") {
        applyOrder(data as Order)
      } else if (event === "order:item-status") {
        applyOrder((data as { order: Order }).order)
      }
    },
    onOpen: () => { void fetchOrders() },
  })

  React.useEffect(() => {
    if (ctx?.outletId) void fetchStations(ctx.outletId)
  }, [ctx?.outletId, fetchStations])

  React.useEffect(() => {
    setSoundState(kdsSoundState())
    return () => { closeKdsSound() }
  }, [])

  /** Ancho medido de la grilla — de acá sale cuántas comandas entran y el tamaño de letra. */
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

  function updateConfig(next: KdsConfig) {
    registerInteraction()
    setConfig(next)
    saveKdsConfig(next)
    // El diálogo tiene un "Probar" que puede haber desbloqueado el audio.
    setSoundState(kdsSoundState())
  }

  async function handleUnlockSound() {
    registerInteraction()
    const ok = await unlockKdsSound()
    setSoundState(kdsSoundState())
    if (ok) playKdsChime()
  }

  function handleTogglePin(orderId: string) {
    registerInteraction()
    setPins((prev) => {
      const next = togglePin(prev, orderId)
      saveKdsPins(next)
      return next
    })
  }

  // ---- Órdenes visibles -----------------------------------------------------

  const visible = React.useMemo(() => {
    const sorted = Array.from(orders.values())
      .filter((o) => config.stationIds.length === 0 || (o.items ?? []).some((i) => i.stationId && config.stationIds.includes(i.stationId)))
      .sort((a, b) => {
        const ta = new Date(a.sentAt ?? a.createdAt ?? 0).getTime()
        const tb = new Date(b.sentAt ?? b.createdAt ?? 0).getTime()
        return config.sortOrder === "newest" ? tb - ta : ta - tb
      })
    // Pineadas al extremo izquierdo, en el orden en que se pinearon.
    return applyPinOrder(sorted, pins)
  }, [orders, config.stationIds, config.sortOrder, pins])

  /**
   * Purga de pins obsoletos. Se compara contra el mapa COMPLETO de órdenes
   * activas (no contra `visible`): cambiar las estaciones visibles esconde una
   * comanda pero no la mata, y no tiene por qué costarle el pin. Una orden
   * cobrada o cancelada, en cambio, sale del mapa y su pin se va con ella —
   * sin esto localStorage crecería para siempre en una pantalla que queda
   * abierta durante días.
   */
  React.useEffect(() => {
    if (!hydrated) return
    setPins((prev) => {
      const next = purgeKdsPins(prev, new Set(orders.keys()))
      if (next === prev) return prev
      saveKdsPins(next)
      return next
    })
  }, [orders, hydrated])

  const counts = React.useMemo(() => {
    const acc: Record<KdsOrderStatus, number> = { sent: 0, in_progress: 0, ready: 0 }
    for (const o of visible) {
      if (o.status === "sent" || o.status === "in_progress" || o.status === "ready") acc[o.status] += 1
    }
    return acc
  }, [visible])

  // ---- Layout y paginación --------------------------------------------------

  // Cuántas comandas entran DE VERDAD: la preferencia es un techo, el ancho
  // medido es la ley. Un teléfono no muestra 12 tarjetas de 30px — muestra 1 a
  // pantalla completa y agrega páginas. Sin breakpoints inventados.
  const fits = Math.floor((gridWidth + GRID_GAP_PX) / (MIN_CARD_PX + GRID_GAP_PX))
  const cols = gridWidth > 0
    ? Math.max(1, Math.min(config.cardsPerScreen, fits))
    : config.cardsPerScreen
  const colWidth = gridWidth > 0 ? (gridWidth - GRID_GAP_PX * (cols - 1)) / cols : 0

  const totalPages = Math.max(1, Math.ceil(visible.length / cols))
  const safePage = Math.min(page, totalPages - 1)
  const pageOrders = visible.slice(safePage * cols, safePage * cols + cols)

  React.useEffect(() => {
    if (totalPages <= 1) return
    const t = setInterval(() => {
      // Rotación automática, pero nunca encima del cocinero.
      if (Date.now() < pauseUntilRef.current) return
      setPage((p) => (p + 1) % totalPages)
    }, AUTO_PAGE_MS)
    return () => clearInterval(t)
  }, [totalPages])

  // ---- Transiciones de ítems ------------------------------------------------

  function nextItemStatus(item: OrderItem): OrderItemStatus | null {
    if (item.status === "pending") return "preparing"
    if (item.status === "preparing") return "ready"
    return null
  }

  /** Aplica estados de ítem en memoria — el marcado se ve al instante, sin esperar al POST. */
  function patchItems(updates: Map<string, OrderItemStatus>) {
    setOrders((prev) => {
      const next = new Map(prev)
      for (const [orderId, order] of prev) {
        if (!order.items) continue
        let changed = false
        const items = order.items.map((i) => {
          const status = updates.get(i.id)
          if (!status || status === i.status) return i
          changed = true
          return { ...i, status }
        })
        if (changed) next.set(orderId, { ...order, items })
      }
      return next
    })
  }

  async function postStatus(orderItemId: string, status: OrderItemStatus) {
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

  /** Marcado optimista con rollback: se pinta ya, y si el POST falla se restaura el snapshot. */
  async function commitBump(busyId: string, targets: { item: OrderItem; next: OrderItemStatus }[]) {
    if (targets.length === 0) return
    registerInteraction()
    const snapshot = orders
    setBusyIds((s) => new Set(s).add(busyId))
    patchItems(new Map(targets.map((t) => [t.item.id, t.next])))
    try {
      await Promise.all(targets.map((t) => postStatus(t.item.id, t.next)))
    } catch {
      setOrders(snapshot)
    } finally {
      setBusyIds((s) => { const n = new Set(s); n.delete(busyId); return n })
    }
  }

  async function bumpItem(item: OrderItem) {
    const next = nextItemStatus(item)
    if (!next) return
    await commitBump(item.id, [{ item, next }])
  }

  async function bumpOrder(order: Order, items: OrderItem[]) {
    const targets = items
      .map((item) => ({ item, next: nextItemStatus(item) }))
      .filter((t): t is { item: OrderItem; next: OrderItemStatus } => t.next !== null)
    await commitBump(order.id, targets)
  }

  if (pairState === "unpaired") {
    return <DeviceNotConnected kind="kds" />
  }

  return (
    <div className="dark flex h-screen flex-col overflow-hidden bg-background text-foreground">
      <main className="min-h-0 flex-1 p-2">
        <div
          ref={gridRef}
          className="grid h-full min-h-0 gap-2"
          onTouchStart={(e) => {
            const t = e.touches[0]
            swipeStartRef.current = t ? { x: t.clientX, y: t.clientY } : null
          }}
          onTouchEnd={(e) => {
            const start = swipeStartRef.current
            const t = e.changedTouches[0]
            swipeStartRef.current = null
            if (!start || !t || totalPages <= 1) return
            const dx = t.clientX - start.x
            // Solo horizontal: el gesto vertical es el scroll DENTRO de la comanda.
            if (Math.abs(dx) < SWIPE_PX || Math.abs(dx) <= Math.abs(t.clientY - start.y)) return
            registerInteraction()
            suppressClickUntilRef.current = Date.now() + 500
            setPage((p) => (dx < 0 ? (p + 1) % totalPages : (p - 1 + totalPages) % totalPages))
          }}
          onClickCapture={(e) => {
            if (Date.now() >= suppressClickUntilRef.current) return
            suppressClickUntilRef.current = 0
            e.preventDefault()
            e.stopPropagation()
          }}
          style={{
            gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))`,
            // Explícito: la única fila ocupa TODO el alto, así cada comanda es
            // full-height y el scroll queda dentro de la tarjeta, nunca en la
            // página.
            gridAutoRows: "1fr",
            // Ancho real de columna — las tarjetas escalan su tipografía con
            // `clamp()` sobre esta variable (ver order-card.tsx).
            ["--kds-col" as string]: `${colWidth}px`,
          }}
        >
          {visible.length === 0 ? (
            <div
              className="flex items-center justify-center text-muted-foreground"
              style={{ gridColumn: "1 / -1" }}
            >
              <p style={{ fontSize: "clamp(1rem, 1.5vw, 1.5rem)" }}>Sin comandas activas</p>
            </div>
          ) : (
            pageOrders.map((order) => (
              <OrderCard
                key={order.id}
                order={order}
                config={config}
                busy={busyIds.has(order.id)}
                pinned={pins.includes(order.id)}
                onTogglePin={handleTogglePin}
                onBumpOrder={bumpOrder}
                onBumpItem={bumpItem}
              />
            ))
          )}
        </div>
      </main>

      <KdsBottomBar
        name={config.name || ctx?.outletName || "Cocina"}
        counts={counts}
        page={safePage}
        totalPages={totalPages}
        onPage={(p) => { registerInteraction(); setPage(p) }}
        loading={loading}
        needsSoundUnlock={config.soundOnNew && soundState !== "ready"}
        onUnlockSound={() => void handleUnlockSound()}
      >
        <KdsConfigDialog config={config} stations={stations} onChange={updateConfig} />
      </KdsBottomBar>
    </div>
  )
}
