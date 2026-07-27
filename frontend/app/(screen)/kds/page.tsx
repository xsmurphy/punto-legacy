"use client"

import * as React from "react"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { usePairedScreen } from "@/hooks/use-paired-screen"
import { useKdsHotkeys } from "@/hooks/use-kds-hotkeys"
import { getDeviceToken } from "@/lib/auth/device-token"
import { loadKdsConfig, resolveKdsMode, saveKdsConfig, type KdsConfig } from "@/lib/kds/config"
import { applyPinOrder, loadKdsPins, purgeKdsPins, saveKdsPins, togglePin } from "@/lib/kds/pins"
import { closeKdsSound, kdsSoundState, playKdsChime, unlockKdsSound, type KdsSoundState } from "@/lib/kds/sound"
import {
  belongsToScreen,
  boardTimeMs,
  doneAtMs,
  isDoneForScreen,
  isTerminal,
  pruneTerminal,
  recallableItems,
  RECALL_LIMIT,
  screenItems,
} from "@/lib/kds/board"
import type { KdsMode, KdsOrderStatus } from "@/lib/kds/kds-visuals"
import type { Order, OrderItem, OrderItemStatus } from "@/hooks/use-orders"
import { OrderCard } from "./order-card"
import { KdsBottomBar } from "./bottom-bar"
import { KdsConfigDialog } from "./config-dialog"
import { KdsRecallDialog } from "./recall-dialog"
import { KdsHelpDialog } from "./help-dialog"

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
 * decisivo: al cambiar de estado la tarjeta SALTABA de columna — quien opera
 * veía moverse justo lo que estaba leyendo.
 *
 * Ahora: las comandas van una al lado de la otra ordenadas por tiempo, cada
 * una ocupando todo el alto. **Una tarjeta no cambia de posición nunca**: ni al
 * cambiar de estado (eso es color, ver `lib/kds/kds-visuals.ts`) ni al marcar
 * un ítem (el ítem se colorea en su misma línea). Lo único que reordena es que
 * entren o salgan comandas, y el pin existe justamente para blindarse de eso.
 *
 * EL BOARD MUESTRA LO QUE FALTA HACER
 * -----------------------------------
 * Una comanda terminada sale del board: para la cocina no sirve y tapa las que
 * faltan. Se llega a ella por el panel de recall, desde donde se puede
 * DEVOLVER A PREPARACIÓN (des-bumpear sus ítems) — y al volver reaparece en su
 * posición por tiempo, no al final, porque el orden es por `sentAt`, que no
 * cambia. Qué cuenta como "terminada" —y por qué con filtro de estaciones eso
 * se decide por ítem y no por el status de la orden— vive en `lib/kds/board.ts`.
 *
 * OVERFLOW = PAGINACIÓN, NO SCROLL. Un scroll horizontal que nadie va a tocar
 * esconde comandas para siempre. La regla de la paginación es NUNCA ESCONDER EN
 * SILENCIO, NUNCA MOVERSE SOLO SIN QUE LO PIDAN: la rotación automática viene
 * APAGADA (una pantalla que cambia sola desconcierta y le pelea a quien opera)
 * y a cambio la barra inferior dice explícitamente cuántas comandas no entran.
 * Para quien la quiera, la rotación se enciende en la config, y ahí CUALQUIER
 * interacción (marcar, pinear, teclado, swipe) la congela 30s — nunca se va la
 * página que se está usando bajo las manos de quien opera.
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
 * TRES INPUTS, LAS MISMAS FUNCIONES
 * ---------------------------------
 * Esta pantalla NUNCA está desatendida: una TV colgada siempre tiene detrás el
 * teclado y el mouse de una PC — si no, no habría con qué marcar las comandas.
 * Así que el teclado no es una comodidad, es el input PRINCIPAL del caso TV
 * (ver `hooks/use-kds-hotkeys.ts`), y ninguna acción puede existir en un solo
 * input: seleccionar, marcar, retroceder, deshacer, pinear, paginar y abrir el
 * recall se hacen con teclado, con mouse y con el dedo. Si algo solo se puede
 * clickear, está mal.
 *
 * Corolario en la UI: nada depende de hover, el foco de teclado se ve a
 * distancia de TV (anillo grueso, no un outline de 1px) y toda acción por gesto
 * (long-press para retroceder) tiene su equivalente visible en la barra.
 *
 * TEMA CLARO / OSCURO
 * -------------------
 * `(screen)/layout.tsx` fija forcedTheme="light" para el checkout screen (visor
 * al cliente), así que el KDS escopea SU tema con la clase `.dark` en este
 * wrapper (Tailwind v4, `@custom-variant dark (&:is(.dark *))`). Ahora esa
 * clase sale de la config del dispositivo en vez de estar fija: una cocina con
 * ventanales al mediodía necesita claro y una barra de noche oscuro. El
 * ThemeProvider global y el resto de las pantallas quedan intactos.
 */

/** Lo que la pantalla PIDE al servidor. Lo que MUESTRA en el board es menos que esto:
 *  `ready` entra en el fetch porque el panel de recall la necesita. */
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
const INTERACTION_PAUSE_MS = 30_000
/** Desplazamiento horizontal mínimo para que un swipe cuente como cambio de página. */
const SWIPE_PX = 48

interface Station { id: string; name: string }

/** Selección por teclado. `itemId` null = la comanda entera. */
interface KdsSelection { orderId: string; itemId: string | null }

/** Última acción de marcado, para deshacerla. Solo la última — quien opera que
 *  se dio cuenta al toque; un historial profundo acá sería una máquina del
 *  tiempo que nadie puede auditar en medio del servicio. */
interface LastAction {
  id: string
  /** itemId → estado que tenía y estado que le pusimos. */
  byItem: Map<string, { from: OrderItemStatus; to: OrderItemStatus }>
}

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
  const [selection, setSelection] = React.useState<KdsSelection | null>(null)
  const [recallOpen, setRecallOpen] = React.useState(false)
  const [helpOpen, setHelpOpen] = React.useState(false)
  // Arranca en "dark" (el default y el comportamiento previo) y se resuelve en
  // un efecto: la config vive en localStorage, así que decidir el modo durante
  // el render sería un mismatch de hidratación garantizado.
  const [mode, setMode] = React.useState<KdsMode>("dark")

  /**
   * Última acción deshacible. Es ESTADO y no un ref porque la barra inferior
   * tiene un botón "Deshacer" que se habilita con esto: toda función de la
   * pantalla existe en los tres inputs (teclado, mouse y dedo), ninguna vive
   * solo en un atajo que hay que saberse.
   */
  const [lastAction, setLastAction] = React.useState<LastAction | null>(null)

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

  /** Congela la rotación automática: quien opera está mirando ESTA página. */
  const registerInteraction = React.useCallback(() => {
    pauseUntilRef.current = Date.now() + INTERACTION_PAUSE_MS
  }, [])

  /**
   * Toda orden conocida entra al mapa, también las terminales — el panel de
   * recall las muestra (sin poder devolverlas). `pruneTerminal` es lo que hace
   * que eso no crezca sin fin en una pantalla que queda abierta días.
   */
  const applyOrder = React.useCallback((order: Order) => {
    setOrders((prev) => {
      const next = new Map(prev)
      next.set(order.id, order)
      return pruneTerminal(next)
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
      setOrders((prev) => {
        const next = new Map<string, Order>()
        // Las terminales que ya conocíamos se conservan: el fetch pide solo las
        // activas, así que reconstruir el mapa a secas vaciaría el recall en
        // cada reconexión. Para todo lo demás manda el servidor — una orden que
        // ya no vuelve en la respuesta dejó de estar activa y se va.
        for (const [id, o] of prev) if (isTerminal(o.status)) next.set(id, o)
        for (const o of detailed) next.set(o.id, o)
        return pruneTerminal(next)
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

  const { pairState, wsState, ctx } = usePairedScreen({
    module: "kds",
    channels: (c) => [`${c.companyId}:kds:${c.outletId}`],
    onEvent: (event, data) => {
      if (event === "order:new") {
        const order = data as Order
        applyOrder(order)
        if (config.soundOnNew && belongsToScreen(order, config.stationIds)) playKdsChime()
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

  /** Modo claro/oscuro. En "auto" se re-evalúa sola: la pantalla no se recarga
   *  nunca, así que el cambio de turno tiene que llegarle igual. */
  React.useEffect(() => {
    const apply = () => setMode(resolveKdsMode(config.theme))
    apply()
    if (config.theme !== "auto") return
    const t = setInterval(apply, 60_000)
    return () => clearInterval(t)
  }, [config.theme])

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

  // ---- Board y recall -------------------------------------------------------

  /** Todo lo que le pertenece a esta pantalla, ordenado por tiempo. */
  const visible = React.useMemo(() => {
    return Array.from(orders.values())
      .filter((o) => belongsToScreen(o, config.stationIds))
      .sort((a, b) => {
        const ta = boardTimeMs(a)
        const tb = boardTimeMs(b)
        return config.sortOrder === "newest" ? tb - ta : ta - tb
      })
  }, [orders, config.stationIds, config.sortOrder])

  /** Lo que falta hacer. Pineadas al extremo izquierdo, en el orden en que se pinearon. */
  const board = React.useMemo(
    () => applyPinOrder(visible.filter((o) => !isDoneForScreen(o, config.stationIds)), pins),
    [visible, config.stationIds, pins]
  )

  /** Lo que ya salió, la más reciente primero y ACOTADO — ver RECALL_LIMIT. */
  const done = React.useMemo(
    () => visible.filter((o) => isDoneForScreen(o, config.stationIds)),
    [visible, config.stationIds]
  )
  const recall = React.useMemo(
    () =>
      [...done]
        .sort((a, b) => doneAtMs(b, config.stationIds) - doneAtMs(a, config.stationIds))
        .slice(0, RECALL_LIMIT),
    [done, config.stationIds]
  )

  /**
   * Purga de pins obsoletos. Se compara contra el mapa COMPLETO de órdenes
   * conocidas (no contra `board`): cambiar las estaciones visibles esconde una
   * comanda pero no la mata, y terminarla tampoco — puede volver por recall.
   * Una orden que se cae del mapa (podada por terminal) sí se lleva su pin;
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
    for (const o of board) {
      if (o.status === "sent" || o.status === "in_progress" || o.status === "ready") acc[o.status] += 1
    }
    // El tercer contador NO es del board: es lo que ya salió y sigue esperando
    // ser retirado (las entregadas/cobradas ya no le importan a nadie acá).
    acc.ready = done.filter((o) => !isTerminal(o.status)).length
    return acc
  }, [board, done])

  // ---- Layout y paginación --------------------------------------------------

  // Cuántas comandas entran DE VERDAD: la preferencia es un techo, el ancho
  // medido es la ley. Un teléfono no muestra 12 tarjetas de 30px — muestra 1 a
  // pantalla completa y agrega páginas. Sin breakpoints inventados.
  const fits = Math.floor((gridWidth + GRID_GAP_PX) / (MIN_CARD_PX + GRID_GAP_PX))
  const cols = gridWidth > 0
    ? Math.max(1, Math.min(config.cardsPerScreen, fits))
    : config.cardsPerScreen
  const colWidth = gridWidth > 0 ? (gridWidth - GRID_GAP_PX * (cols - 1)) / cols : 0

  const totalPages = Math.max(1, Math.ceil(board.length / cols))
  const safePage = Math.min(page, totalPages - 1)
  const pageOrders = board.slice(safePage * cols, safePage * cols + cols)

  React.useEffect(() => {
    if (!config.autoRotate || totalPages <= 1) return
    const t = setInterval(() => {
      // Rotación automática, pero nunca encima de quien opera.
      if (Date.now() < pauseUntilRef.current) return
      setPage((p) => (p + 1) % totalPages)
    }, config.rotateSeconds * 1000)
    return () => clearInterval(t)
  }, [totalPages, config.autoRotate, config.rotateSeconds])

  // ---- Selección por teclado ------------------------------------------------

  const selectedOrder = React.useMemo(
    () => (selection ? board.find((o) => o.id === selection.orderId) ?? null : null),
    [board, selection]
  )

  /**
   * La selección NUNCA puede apuntar a una comanda que salió del board. Se
   * suelta en vez de saltar sola a la vecina: si saltara, el Enter siguiente
   * —que quien opera ya tenía en el dedo— marcaría una comanda que no eligió.
   * Un `→` de más es barato; marcar la comanda equivocada no.
   */
  React.useEffect(() => {
    if (selection && !board.some((o) => o.id === selection.orderId)) setSelection(null)
  }, [board, selection])

  /** La selección manda sobre la paginación: si me muevo a otra página, la vista va. */
  React.useEffect(() => {
    if (!selection) return
    const i = board.findIndex((o) => o.id === selection.orderId)
    if (i < 0) return
    const target = Math.floor(i / cols)
    setPage((p) => (p === target ? p : target))
  }, [selection, board, cols])

  function moveOrder(delta: number) {
    registerInteraction()
    setSelection((prev) => {
      if (board.length === 0) return null
      // Sin selección previa se arranca por la primera comanda de la página que
      // quien opera está mirando, no por la primera del board.
      if (!prev) return { orderId: (pageOrders[0] ?? board[0]).id, itemId: null }
      const i = board.findIndex((o) => o.id === prev.orderId)
      if (i < 0) return { orderId: (pageOrders[0] ?? board[0]).id, itemId: null }
      const next = Math.min(board.length - 1, Math.max(0, i + delta))
      return { orderId: board[next].id, itemId: null }
    })
  }

  function moveItem(delta: number) {
    registerInteraction()
    setSelection((prev) => {
      if (!prev) {
        if (board.length === 0) return null
        return { orderId: (pageOrders[0] ?? board[0]).id, itemId: null }
      }
      const order = board.find((o) => o.id === prev.orderId)
      if (!order) return prev
      const items = screenItems(order, config.stationIds)
      if (items.length === 0) return prev
      const i = items.findIndex((it) => it.id === prev.itemId)
      if (i < 0) return { ...prev, itemId: (delta > 0 ? items[0] : items[items.length - 1]).id }
      const next = Math.min(items.length - 1, Math.max(0, i + delta))
      return { ...prev, itemId: items[next].id }
    })
  }

  function selectedItem(): OrderItem | null {
    if (!selectedOrder || !selection?.itemId) return null
    return screenItems(selectedOrder, config.stationIds).find((i) => i.id === selection.itemId) ?? null
  }

  function confirmSelection() {
    if (!selectedOrder) return
    const item = selectedItem()
    if (item) { void bumpItem(item); return }
    const items = screenItems(selectedOrder, config.stationIds)
    void bumpOrder(selectedOrder, items.filter((i) => i.status === "pending" || i.status === "preparing"))
  }

  function stepBackSelection() {
    const item = selectedItem()
    // Sin ítem seleccionado no se retrocede la comanda entera: retroceder de a
    // uno es reversible de un vistazo, retroceder ocho líneas de golpe no.
    if (item) void stepBackItem(item)
  }

  useKdsHotkeys(
    {
      moveOrder,
      moveItem,
      confirm: confirmSelection,
      stepBack: stepBackSelection,
      undo: () => { void undoLast() },
      togglePin: () => { if (selection) handleTogglePin(selection.orderId) },
      toggleRecall: () => { registerInteraction(); setRecallOpen((o) => !o) },
      toggleHelp: () => { registerInteraction(); setHelpOpen((o) => !o) },
      movePage: (delta) => {
        registerInteraction()
        setPage((p) => (p + delta + totalPages) % totalPages)
      },
      clearSelection: () => setSelection(null),
    },
    { helpOpen, recallOpen }
  )

  // ---- Transiciones de ítems ------------------------------------------------

  function nextItemStatus(item: OrderItem): OrderItemStatus | null {
    if (item.status === "pending") return "preparing"
    if (item.status === "preparing") return "ready"
    return null
  }

  /** Un paso HACIA ATRÁS. El backend habilita `ready → preparing → pending`
   *  (ITEM_TRANSITIONS) justamente para esto. */
  function prevItemStatus(item: OrderItem): OrderItemStatus | null {
    if (item.status === "ready") return "preparing"
    if (item.status === "preparing") return "pending"
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

  /**
   * Marcado optimista con rollback. Es el ÚNICO camino de escritura de la
   * pantalla: avanzar, retroceder, devolver a preparación y deshacer pasan todos por
   * acá, así que todos heredan el optimismo, el rollback y el guard.
   *
   * El rollback revierte SOLO los ítems de ESTE bump, no un snapshot entero
   * del estado: con `setOrders(snapshot)` un fallo del pedido A borraba
   * también el marcado optimista del pedido B que había entrado mientras
   * tanto (y el de cualquier evento del WS llegado en el medio). En una
   * cocina con dos personas marcando a la vez eso se ve como "se
   * desmarcó solo".
   *
   * Guard de reentrada por target: un doble tap sobre el mismo ítem —o un
   * tap seguido del bump de toda la tarjeta— disparaba dos POST solapados
   * para el mismo orderItemId. El backend valida la transición, pero el
   * segundo request es ruido garantizado y puede pisar el estado.
   */
  async function commitBump(busyId: string, targets: { item: OrderItem; next: OrderItemStatus }[]) {
    if (targets.length === 0) return
    if (busyIds.has(busyId) || targets.some((t) => busyIds.has(t.item.id))) return
    registerInteraction()

    // Estado previo de CADA ítem tocado, para revertir solo eso si falla.
    const previous = new Map(targets.map((t) => [t.item.id, t.item.status]))

    setBusyIds((s) => {
      const n = new Set(s)
      n.add(busyId)
      for (const t of targets) n.add(t.item.id)
      return n
    })
    patchItems(new Map(targets.map((t) => [t.item.id, t.next])))
    try {
      await Promise.all(targets.map((t) => postStatus(t.item.id, t.next)))
    } catch {
      patchItems(previous)
    } finally {
      setBusyIds((s) => {
        const n = new Set(s)
        n.delete(busyId)
        for (const t of targets) n.delete(t.item.id)
        return n
      })
    }
  }

  /** Deja registrado qué se acaba de hacer, para que "Deshacer" tenga qué revertir. */
  function rememberAction(targets: { item: OrderItem; next: OrderItemStatus }[]) {
    if (targets.length === 0) return
    setLastAction({
      id: `${Date.now()}`,
      byItem: new Map(targets.map((t) => [t.item.id, { from: t.item.status, to: t.next }])),
    })
  }

  async function bumpItem(item: OrderItem) {
    const next = nextItemStatus(item)
    if (!next) return
    const targets = [{ item, next }]
    rememberAction(targets)
    await commitBump(item.id, targets)
  }

  async function bumpOrder(order: Order, items: OrderItem[]) {
    const targets = items
      .map((item) => ({ item, next: nextItemStatus(item) }))
      .filter((t): t is { item: OrderItem; next: OrderItemStatus } => t.next !== null)
    rememberAction(targets)
    await commitBump(order.id, targets)
  }

  async function stepBackItem(item: OrderItem) {
    const next = prevItemStatus(item)
    if (!next) return
    const targets = [{ item, next }]
    rememberAction(targets)
    await commitBump(item.id, targets)
  }

  /**
   * Devolver a preparación: des-bumpea los ítems `ready` DE ESTA PANTALLA
   * (`ready → preparing`) y deja que el backend derive el estado de la orden
   * (`recomputeOrderStatus` la lleva a `in_progress` sola). No se setea el
   * status de la orden a mano: derivarlo es trabajo del service, que es el que
   * sabe agregar todos los ítems, incluidos los de las otras estaciones.
   */
  async function handleRecall(order: Order) {
    const targets = recallableItems(order, config.stationIds).map((item) => ({
      item,
      next: "preparing" as OrderItemStatus,
    }))
    rememberAction(targets)
    await commitBump(order.id, targets)
  }

  /**
   * Deshacer la última acción. Solo revierte los ítems que SIGUEN como los
   * dejamos: si despacho ya entregó uno o el otro cocinero lo tocó, ese ítem se
   * queda como está — deshacer nunca pisa una decisión más nueva que la propia.
   */
  async function undoLast() {
    const last = lastAction
    if (!last) return
    setLastAction(null)

    const targets: { item: OrderItem; next: OrderItemStatus }[] = []
    for (const order of orders.values()) {
      for (const item of order.items ?? []) {
        const rec = last.byItem.get(item.id)
        if (rec && item.status === rec.to) targets.push({ item, next: rec.from })
      }
    }
    await commitBump(`undo:${last.id}`, targets)
  }

  if (pairState === "unpaired") {
    return <DeviceNotConnected kind="kds" />
  }

  return (
    <div
      className={`${mode === "dark" ? "dark " : ""}flex h-screen flex-col overflow-hidden bg-background text-foreground`}
    >
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
          {board.length === 0 ? (
            <div
              className="flex items-center justify-center text-muted-foreground"
              style={{ gridColumn: "1 / -1" }}
            >
              <p style={{ fontSize: "clamp(1rem, 1.5vw, 1.5rem)" }}>Sin comandas pendientes</p>
            </div>
          ) : (
            pageOrders.map((order) => (
              <OrderCard
                key={order.id}
                order={order}
                config={config}
                mode={mode}
                busy={busyIds.has(order.id)}
                pinned={pins.includes(order.id)}
                selected={selection?.orderId === order.id}
                selectedItemId={selection?.orderId === order.id ? selection.itemId : null}
                onTogglePin={handleTogglePin}
                onBumpOrder={bumpOrder}
                onBumpItem={bumpItem}
                onStepBackItem={stepBackItem}
              />
            ))
          )}
        </div>
      </main>

      <KdsBottomBar
        name={config.name || ctx?.outletName || "Preparación"}
        counts={counts}
        mode={mode}
        page={safePage}
        totalPages={totalPages}
        hiddenCount={board.length - pageOrders.length}
        onPage={(p) => { registerInteraction(); setPage(p) }}
        loading={loading}
        wsState={wsState}
        needsSoundUnlock={config.soundOnNew && soundState !== "ready"}
        onUnlockSound={() => void handleUnlockSound()}
        canUndo={lastAction !== null}
        onUndo={() => { void undoLast() }}
        onShowHelp={() => setHelpOpen(true)}
      >
        <KdsRecallDialog
          open={recallOpen}
          onOpenChange={setRecallOpen}
          orders={recall}
          stationIds={config.stationIds}
          busyIds={busyIds}
          onRecall={(order) => { void handleRecall(order) }}
        />
        <KdsConfigDialog config={config} stations={stations} onChange={updateConfig} />
      </KdsBottomBar>

      <KdsHelpDialog open={helpOpen} onOpenChange={setHelpOpen} />
    </div>
  )
}
