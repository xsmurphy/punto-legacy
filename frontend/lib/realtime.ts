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
}

type Subscriber = (ev: InvalidateEvent) => void

let ws: WebSocket | null = null
let backoffMs = 1000
let retryTimer: ReturnType<typeof setTimeout> | null = null
let companyId: string | null = null
let wsUrl: string | null = null
const subscribers = new Set<Subscriber>()

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

export function disconnectRealtime() {
  if (retryTimer) {
    clearTimeout(retryTimer)
    retryTimer = null
  }
  ws?.close()
  ws = null
  subscribers.clear()
}
