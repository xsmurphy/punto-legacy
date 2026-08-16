/**
 * Cliente WebSocket singleton para invalidaciones realtime.
 *
 * Mantiene UNA conexión por browser. Reconnect exponential backoff
 * (1s → 30s cap). Suscriptores reciben eventos `invalidate` con shape
 * `{ entity, op, id, scope }`. Mapeo entity→queryKeys vive en
 * `hooks/use-realtime-sync.ts`.
 */

export type InvalidateEvent = {
  entity: string
  op: "create" | "update" | "delete"
  id: string | null
  scope: "all" | "dashboard"
  /**
   * Variante plural de `id` (context/15 §Modelo quirúrgico, 2026-08-16):
   * varios recursos tocados por UNA sola operación (ej. una venta de N
   * líneas mueve stock de N ítems distintos — un solo evento con los N
   * ids). Campo ADICIONAL, retrocompatible — `id` sigue viajando (`null`
   * cuando se usa `ids`). Ausente/undefined en cualquier evento viejo o de
   * un entity que no batchea.
   */
  ids?: string[]
}

type Subscriber = (ev: InvalidateEvent) => void
type ReconnectSubscriber = () => void

let ws: WebSocket | null = null
let backoffMs = 1000
let retryTimer: ReturnType<typeof setTimeout> | null = null
let companyId: string | null = null
let wsUrl: string | null = null
// true desde la PRIMERA apertura exitosa del socket. Sirve para distinguir
// "primera conexión" (no hay nada que resincronizar, el bootstrap ya trajo
// todo fresco) de "reconexión tras una caída" (si algo cambió mientras el
// WS estuvo caído, nos lo perdimos — ver resync en `open()` más abajo).
let hasEverConnected = false
const subscribers = new Set<Subscriber>()
const reconnectSubscribers = new Set<ReconnectSubscriber>()

export function connectRealtime(cid: string, url: string) {
  companyId = cid
  wsUrl = url
  open()
}

function open() {
  if (!wsUrl || !companyId) return
  if (ws && ws.readyState <= 1) return
  try {
    ws = new WebSocket(wsUrl)
  } catch {
    scheduleReconnect()
    return
  }
  ws.onopen = () => {
    backoffMs = 1000
    ws?.send(JSON.stringify({ action: "subscribe", channel: `${companyId}:invalidate` }))
    // Resync tras reconexión: ws-server es un relay puro sin backlog/replay
    // (ver context/15-realtime-sync-plan.md). Si el socket estuvo caído
    // (proxy timeout, tablet que se durmió, red intermitente) no hay forma
    // de saber qué invalidaciones nos perdimos — la única respuesta honesta
    // es invalidar TODO el cache al volver a conectar. No dispara en la
    // primera conexión (ahí el bootstrap ya trajo todo fresco).
    if (hasEverConnected) {
      reconnectSubscribers.forEach((cb) => cb())
    }
    hasEverConnected = true
  }
  ws.onmessage = (m) => {
    try {
      const parsed = JSON.parse(typeof m.data === "string" ? m.data : "")
      if (parsed.event === "invalidate" && parsed.data) {
        subscribers.forEach((cb) => cb(parsed.data as InvalidateEvent))
      }
    } catch {
      // ignore non-JSON or malformed events
    }
  }
  ws.onclose = () => scheduleReconnect()
  ws.onerror = () => ws?.close()
}

function scheduleReconnect() {
  if (retryTimer) clearTimeout(retryTimer)
  retryTimer = setTimeout(() => open(), backoffMs)
  backoffMs = Math.min(backoffMs * 2, 30_000)
}

export function subscribeRealtime(cb: Subscriber): () => void {
  subscribers.add(cb)
  return () => {
    subscribers.delete(cb)
  }
}

/** Se dispara en cada reconexión (NO en la primera conexión). Ver `open()`. */
export function subscribeReconnect(cb: ReconnectSubscriber): () => void {
  reconnectSubscribers.add(cb)
  return () => {
    reconnectSubscribers.delete(cb)
  }
}

export function disconnectRealtime() {
  if (retryTimer) {
    clearTimeout(retryTimer)
    retryTimer = null
  }
  ws?.close()
  ws = null
  hasEverConnected = false
  subscribers.clear()
  reconnectSubscribers.clear()
}
