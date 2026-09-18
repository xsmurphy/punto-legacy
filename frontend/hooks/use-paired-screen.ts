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
 *  - Config del tenant en vivo: se suscribe también a `{companyId}:invalidate`
 *    y, cuando el comercio guarda Ajustes (evento `setting`) o el socket se
 *    reconecta, vuelve a pedir el contexto. Así un cambio de configuración
 *    (ej. los nombres de las etapas de las órdenes) llega a la pantalla sin
 *    recargarla. Ese canal no se reenvía al `onEvent` del caller.
 */

import * as React from "react"
import { getDeviceToken, clearDeviceToken, type DeviceModule } from "@/lib/auth/device-token"
import { getDeviceClaims, clearDeviceClaims } from "@/lib/auth/device-claims"
import type { OrderStatusLabels } from "@/lib/orders/order-status-labels"

const HEARTBEAT_INTERVAL = 30_000

export interface PairedScreenContext {
  companyId: string
  outletId: string
  companyName: string
  outletName: string
  registerName: string
  logoUrl: string
  /** Nombres de etapas de órdenes renombradas por el comercio (mismo campo que el bootstrap). */
  orderStatusLabels?: OrderStatusLabels
  /**
   * El comercio exige el ROSTRO para marcar asistencia (context/83): el reloj
   * no ofrece "Usar código".
   *
   * Opcional porque el contexto CACHEADO de una versión anterior no lo trae
   * (este hook reusa el último contexto conocido cuando no hay red), y ausente
   * tiene que significar el default del comercio: el código disponible. Es un
   * dato para PINTAR — quien rechaza una marcación con código es el servidor.
   */
  attendanceFaceOnly?: boolean
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
  module: Extract<DeviceModule, "kds" | "display" | "print" | "clock">
  /** Canales adicionales a suscribir además de `${module}:${deviceId}` (revocación). */
  channels: (ctx: PairedScreenContext) => string[]
  onEvent: (event: string, data: unknown) => void
  /**
   * Invalidación del tenant (canal de sync del comercio): entidad que cambió.
   * El hook ya usa 'setting' para refrescar su propio contexto; el resto de
   * las entidades le llegan al caller por acá — antes se TRAGABAN, y el reloj
   * nunca se enteraba de que el panel abrió un registro de rostro.
   */
  onInvalidate?: (entity: string) => void
  /** Se dispara cada vez que el WS abre (conexión inicial y reconexiones). */
  onOpen?: () => void
  /**
   * El aparato tiene que funcionar SIN RED.
   *
   * Por default, un dispositivo pareado que no logra traer su contexto cae a
   * `unpaired` y muestra "no conectado". Para un KDS o una pantalla de despacho
   * eso es correcto: sin servidor no tienen nada que mostrar.
   *
   * El reloj de marcación es lo contrario (context/83 D7): la gente entra y sale
   * igual, y la marcación se encola. Con esta opción el último contexto conocido
   * se guarda en el dispositivo y se reusa cuando la red no contesta, así que la
   * pantalla queda operativa. Lo que NO cambia es el 401: una sesión revocada
   * sigue olvidando el device — eso es una respuesta del servidor, no un
   * silencio.
   */
  offlineFirst?: boolean
}

/**
 * El último contexto conocido del dispositivo, para `offlineFirst`.
 *
 * `localStorage` y no IndexedDB porque son cuatro strings que se leen una vez
 * al arrancar. Si el storage está bloqueado (modo privado), las dos funciones
 * fallan en silencio y el aparato se comporta como antes de esta opción.
 */
const CTX_KEY_PREFIX = "punto.device.context"

function readCachedContext(module: string): PairedScreenContext | null {
  try {
    const raw = window.localStorage.getItem(`${CTX_KEY_PREFIX}.${module}`)
    if (!raw) return null
    const parsed = JSON.parse(raw) as PairedScreenContext
    return parsed?.companyId && parsed?.outletId ? parsed : null
  } catch {
    return null
  }
}

function writeCachedContext(module: string, ctx: PairedScreenContext): void {
  try {
    window.localStorage.setItem(`${CTX_KEY_PREFIX}.${module}`, JSON.stringify(ctx))
  } catch {
    /* sin storage el aparato sigue andando con red */
  }
}

function clearCachedContext(module: string): void {
  try {
    window.localStorage.removeItem(`${CTX_KEY_PREFIX}.${module}`)
  } catch {
    /* idem */
  }
}

export function usePairedScreen({ module, channels, onEvent, onInvalidate, onOpen, offlineFirst }: UseePairedScreenOpts) {
  const [pairState, setPairState] = React.useState<PairState>("unpaired")
  const [wsState, setWsState] = React.useState<WsState>("connecting")
  const [ctx, setCtx] = React.useState<PairedScreenContext | null>(null)
  const wsRef = React.useRef<WebSocket | null>(null)
  const reconnectRef = React.useRef<ReturnType<typeof setTimeout> | null>(null)
  const heartbeatRef = React.useRef<ReturnType<typeof setInterval> | null>(null)
  const activeRef = React.useRef(true)
  /** ¿Ya abrió el socket alguna vez? Distingue la primera conexión de una reconexión. */
  const hasOpenedRef = React.useRef(false)
  const onEventRef = React.useRef(onEvent)
  const onOpenRef = React.useRef(onOpen)
  const onInvalidateRef = React.useRef(onInvalidate)
  onEventRef.current = onEvent
  onOpenRef.current = onOpen
  onInvalidateRef.current = onInvalidate

  const forgetDevice = React.useCallback(() => {
    clearDeviceToken(module)
    clearDeviceClaims(module)
    clearCachedContext(module)
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

  /**
   * GET del contexto del device. `null` = no se pudo (red, contexto
   * incompleto); un 401 olvida el device. Lo usan el arranque y el refresco.
   */
  async function fetchContext(token: string): Promise<PairedScreenContext | null> {
    const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
    const res = await fetch(`${apiUrl}/v1/screens?resource=context`, {
      headers: { Authorization: `Bearer ${token}` },
    })
    if (res.status === 401) { forgetDevice(); return null }
    if (!res.ok) return null
    const body = (await res.json()) as { data?: PairedScreenContext }
    if (!body.data?.companyId || !body.data?.outletId) return null
    if (offlineFirst) writeCachedContext(module, body.data)
    return body.data
  }

  /** Refresco best-effort: si falla, la pantalla sigue con lo último que sabía. */
  async function refreshContext(token: string) {
    try {
      const next = await fetchContext(token)
      if (next && activeRef.current) setCtx(next)
    } catch { /* best-effort */ }
  }

  function connectWs(token: string, wsChannels: string[], invalidateChannel: string) {
    if (wsRef.current) { wsRef.current.close(); wsRef.current = null }
    const wsUrl = process.env.NEXT_PUBLIC_WS_URL ?? "ws://localhost:3001"
    const ws = new WebSocket(wsUrl)
    wsRef.current = ws
    let backoff = 1000

    ws.onopen = () => {
      for (const ch of [...wsChannels, invalidateChannel]) {
        ws.send(JSON.stringify({ action: "subscribe", channel: ch }))
      }
      backoff = 1000
      setWsState("online")
      // En la primera conexión el contexto se acaba de pedir en el arranque;
      // en una reconexión pudo haberse perdido un cambio de Ajustes.
      if (hasOpenedRef.current) void refreshContext(token)
      hasOpenedRef.current = true
      onOpenRef.current?.()
    }
    ws.onmessage = (ev) => {
      try {
        const msg = JSON.parse(ev.data as string) as { event: string; channel?: string; data: unknown }
        if (msg.event === "revoked") { forgetDevice(); return }
        if (msg.channel === invalidateChannel) {
          const entity = (msg.data as { entity?: string } | null)?.entity
          if (entity === "setting") void refreshContext(token)
          if (entity) onInvalidateRef.current?.(entity)
          return
        }
        onEventRef.current(msg.event, msg.data)
      } catch { /* ignore */ }
    }
    ws.onclose = () => {
      if (!activeRef.current) return
      if (wsRef.current === ws) {
        setWsState("offline")
        reconnectRef.current = setTimeout(() => {
          backoff = Math.min(backoff * 2, 30000)
          connectWs(token, wsChannels, invalidateChannel)
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
    hasOpenedRef.current = false
    let cancelled = false

    async function boot() {
      const token = getDeviceToken(module)
      if (!token) { setPairState("unpaired"); return }
      setPairState("connecting")
      try {
        // Sin red, un aparato offline-first arranca con lo último que supo. El
        // 401 no pasa por acá: `fetchContext` ya olvidó el device en ese caso,
        // así que un contexto cacheado nunca resucita una sesión revocada.
        const context = (await fetchContext(token)) ?? (offlineFirst ? readCachedContext(module) : null)
        // Un 401 ya olvidó el device dentro de fetchContext (estado `unpaired`).
        if (!getDeviceToken(module)) return
        if (!context) throw new Error("context fetch failed")
        if (cancelled) return

        setCtx(context)
        const claims = getDeviceClaims(module)
        const deviceId = claims?.deviceId ?? ""
        const wsChannels = [...channels(context), `${module}:${deviceId}`]
        connectWs(token, wsChannels, `${context.companyId}:invalidate`)
        startHeartbeat(token)
        setPairState("ready")
      } catch {
        if (cancelled) return
        const cached = offlineFirst ? readCachedContext(module) : null
        if (cached && getDeviceToken(module)) {
          // El aparato SÍ está pareado; lo que falló fue la red. Queda operativo
          // con lo último que supo y el socket reintenta solo.
          setCtx(cached)
          setPairState("ready")
          return
        }
        setPairState("unpaired")
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
