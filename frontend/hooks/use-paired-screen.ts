"use client"

/**
 * Pairing + WS + heartbeat compartido por las pantallas device-paired de
 * "solo lectura + acciones acotadas" (KDS, pantalla de despacho — O2,
 * context/24-orders-module-plan.md). Generaliza el patrón de
 * `app/(screen)/checkout/page.tsx` (que lo tiene inline porque el checkout
 * screen es un caso único con vistas live/confirmed/idle) para no duplicarlo
 * una tercera vez.
 *
 * Responsabilidades:
 *  - Lee el Bearer token namespaced (`getDeviceToken(module)`).
 *  - Resuelve companyId/outletId vía GET /v1/screens?resource=context (fuente
 *    de verdad server-side — ver comentario en screens.php).
 *  - Abre WS a los canales dados, reconecta con backoff, heartbeat cada 30s.
 *  - `onOpen` se dispara en cada conexión (incluida la primera) — el caller
 *    lo usa para re-sincronizar su estado vía REST (evita perder eventos
 *    mientras el WS estuvo caído).
 *  - Evento `revoked` (canal `${module}:${deviceId}`) o 401 en heartbeat →
 *    limpia token/claims y vuelve a `unpaired`.
 */

import * as React from "react"
import { getDeviceToken, clearDeviceToken, type DeviceModule } from "@/lib/auth/device-token"
import { getDeviceClaims, clearDeviceClaims } from "@/lib/auth/device-claims"

const HEARTBEAT_INTERVAL = 30_000

export interface PairedScreenContext {
  companyId: string
  outletId: string
  companyName: string
  outletName: string
  registerName: string
  logoUrl: string
}

export type PairState = "unpaired" | "connecting" | "ready"

/**
 * Estado del socket, independiente del pairing: una pantalla emparejada puede
 * quedarse sin WS un rato y seguir mostrando lo último que sabe. Se expone
 * porque la pantalla NO se actualiza sola mientras está caída, y al reconectar
 * el `onOpen` re-sincroniza de golpe — sin un indicador, eso se ve como
 * "las comandas cambian solas" y nadie entiende por qué.
 */
export type WsState = "connecting" | "online" | "offline"

interface UseePairedScreenOpts {
  module: Extract<DeviceModule, "kds" | "display" | "print">
  /** Canales adicionales a suscribir además de `${module}:${deviceId}` (revocación). */
  channels: (ctx: PairedScreenContext) => string[]
  onEvent: (event: string, data: unknown) => void
  /** Se dispara cada vez que el WS abre (conexión inicial y reconexiones). */
  onOpen?: () => void
}

export function usePairedScreen({ module, channels, onEvent, onOpen }: UseePairedScreenOpts) {
  const [pairState, setPairState] = React.useState<PairState>("unpaired")
  const [wsState, setWsState] = React.useState<WsState>("connecting")
  const [ctx, setCtx] = React.useState<PairedScreenContext | null>(null)
  const wsRef = React.useRef<WebSocket | null>(null)
  const reconnectRef = React.useRef<ReturnType<typeof setTimeout> | null>(null)
  const heartbeatRef = React.useRef<ReturnType<typeof setInterval> | null>(null)
  const activeRef = React.useRef(true)
  const onEventRef = React.useRef(onEvent)
  const onOpenRef = React.useRef(onOpen)
  onEventRef.current = onEvent
  onOpenRef.current = onOpen

  const forgetDevice = React.useCallback(() => {
    clearDeviceToken(module)
    clearDeviceClaims(module)
    cleanup()
    setCtx(null)
    setPairState("unpaired")
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [module])

  function cleanup() {
    if (wsRef.current) { wsRef.current.close(); wsRef.current = null }
    if (reconnectRef.current) clearTimeout(reconnectRef.current)
    if (heartbeatRef.current) clearInterval(heartbeatRef.current)
  }

  function connectWs(token: string, wsChannels: string[]) {
    if (wsRef.current) { wsRef.current.close(); wsRef.current = null }
    const wsUrl = process.env.NEXT_PUBLIC_WS_URL ?? "ws://localhost:3001"
    const ws = new WebSocket(wsUrl)
    wsRef.current = ws
    let backoff = 1000

    ws.onopen = () => {
      for (const ch of wsChannels) ws.send(JSON.stringify({ action: "subscribe", channel: ch }))
      backoff = 1000
      setWsState("online")
      onOpenRef.current?.()
    }
    ws.onmessage = (ev) => {
      try {
        const msg = JSON.parse(ev.data as string) as { event: string; data: unknown }
        if (msg.event === "revoked") { forgetDevice(); return }
        onEventRef.current(msg.event, msg.data)
      } catch { /* ignore */ }
    }
    ws.onclose = () => {
      if (!activeRef.current) return
      if (wsRef.current === ws) {
        setWsState("offline")
        reconnectRef.current = setTimeout(() => {
          backoff = Math.min(backoff * 2, 30000)
          connectWs(token, wsChannels)
        }, backoff)
      }
    }
    ws.onerror = () => ws.close()
  }

  function startHeartbeat(token: string) {
    if (heartbeatRef.current) clearInterval(heartbeatRef.current)
    heartbeatRef.current = setInterval(async () => {
      try {
        const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
        const res = await fetch(`${apiUrl}/v1/screens?resource=heartbeat`, {
          method: "POST",
          headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
          body: "{}",
        })
        if (res.status === 401) forgetDevice()
      } catch { /* best-effort */ }
    }, HEARTBEAT_INTERVAL)
  }

  React.useEffect(() => {
    activeRef.current = true
    let cancelled = false

    async function boot() {
      const token = getDeviceToken(module)
      if (!token) { setPairState("unpaired"); return }
      setPairState("connecting")
      try {
        const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
        const res = await fetch(`${apiUrl}/v1/screens?resource=context`, {
          headers: { Authorization: `Bearer ${token}` },
        })
        if (res.status === 401) { forgetDevice(); return }
        if (!res.ok) throw new Error("context fetch failed")
        const body = (await res.json()) as { data?: PairedScreenContext }
        if (!body.data?.companyId || !body.data?.outletId) throw new Error("context incompleto")
        if (cancelled) return

        setCtx(body.data)
        const claims = getDeviceClaims(module)
        const deviceId = claims?.deviceId ?? ""
        const wsChannels = [...channels(body.data), `${module}:${deviceId}`]
        connectWs(token, wsChannels)
        startHeartbeat(token)
        setPairState("ready")
      } catch {
        if (!cancelled) setPairState("unpaired")
      }
    }

    void boot()
    return () => {
      cancelled = true
      activeRef.current = false
      cleanup()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [module])

  return { pairState, wsState, ctx, forgetDevice }
}
