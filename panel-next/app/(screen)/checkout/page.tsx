"use client"
import * as React from "react"
import { LiveView } from "./live-view"
import { ConfirmedView } from "./confirmed-view"
import { IdleView } from "./idle-view"
import { getDeviceToken, clearDeviceToken } from "@/lib/auth/device-token"
import { DeviceNotConnected } from "@/components/layout/device-not-connected"

const HEARTBEAT_INTERVAL = 30_000
const CONFIRMED_DURATION = 5_000

export interface CartLine {
  name: string
  qty: number
  total: number
}

export interface CartPayload {
  lines: CartLine[]
  total: number
  discount: number
  customer: { name: string; tin: string } | null
}

type ScreenState =
  | { kind: "unpaired" }
  | { kind: "live"; cart: CartPayload }
  | { kind: "confirmed"; total: number; change: number }
  | { kind: "idle" }

function decodeJwtPayload(token: string): Record<string, unknown> {
  try {
    const parts = token.split(".")
    if (parts.length !== 3) return {}
    const b64 = parts[1].replace(/-/g, "+").replace(/_/g, "/")
    const padded = b64 + "==".slice((b64.length % 4) || 4)
    return JSON.parse(atob(padded)) as Record<string, unknown>
  } catch {
    return {}
  }
}

export interface ScreenContext {
  companyName: string
  logoUrl: string
  registerName: string
  outletName: string
}

export default function CheckoutPage() {
  const [state, setState] = React.useState<ScreenState>({ kind: "unpaired" })
  const [token, setToken] = React.useState<string | null>(null)
  const [screenCtx, setScreenCtx] = React.useState<ScreenContext | null>(null)
  const wsRef = React.useRef<WebSocket | null>(null)
  const reconnectRef = React.useRef<ReturnType<typeof setTimeout> | null>(null)
  const heartbeatRef = React.useRef<ReturnType<typeof setInterval> | null>(null)
  const confirmedRef = React.useRef<ReturnType<typeof setTimeout> | null>(null)
  const activeRef = React.useRef(true)

  // Cursor auto-hide
  React.useEffect(() => {
    let timer: ReturnType<typeof setTimeout>
    const onMove = () => {
      document.body.style.cursor = ""
      clearTimeout(timer)
      timer = setTimeout(() => { document.body.style.cursor = "none" }, 5000)
    }
    window.addEventListener("mousemove", onMove)
    onMove()
    return () => {
      window.removeEventListener("mousemove", onMove)
      clearTimeout(timer)
      document.body.style.cursor = ""
    }
  }, [])

  // Al montar: leer token de localStorage (punto.device.token)
  React.useEffect(() => {
    activeRef.current = true
    const stored = getDeviceToken("screen")
    if (stored) {
      setToken(stored)
    } else {
      setState({ kind: "unpaired" })
    }
    return () => {
      activeRef.current = false
      cleanup()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Cuando cambia el token: validar claims y conectar WS
  React.useEffect(() => {
    if (!token) return
    const claims = decodeJwtPayload(token)
    const cid = claims["cid"] as string | undefined
    const rid = claims["rid"] as string | undefined
    const did = claims["did"] as string | undefined
    if (!cid || !rid) {
      clearDeviceToken("screen")
      setToken(null)
      setState({ kind: "unpaired" })
      return
    }
    const channels = [`${cid}:checkout:${rid}`, `screen:${did}`]
    connectWs(token, channels)
    startHeartbeat(token)
    setState({ kind: "idle" })
    // Fetch context (company name, logo, caja, sucursal) — best-effort.
    void (async () => {
      try {
        const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
        const res = await fetch(`${apiUrl}/v1/screens?resource=context`, {
          headers: { Authorization: `Bearer ${token}` },
        })
        if (!res.ok) return
        const body = (await res.json()) as {
          ok?: boolean
          data?: ScreenContext
        }
        if (body?.data) setScreenCtx(body.data)
      } catch {
        // best-effort
      }
    })()
    return () => {
      if (wsRef.current) { wsRef.current.close(); wsRef.current = null }
      if (heartbeatRef.current) clearInterval(heartbeatRef.current)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  function cleanup() {
    if (wsRef.current) { wsRef.current.close(); wsRef.current = null }
    if (reconnectRef.current) clearTimeout(reconnectRef.current)
    if (heartbeatRef.current) clearInterval(heartbeatRef.current)
    if (confirmedRef.current) clearTimeout(confirmedRef.current)
  }

  function connectWs(tkn: string, channels: string[]) {
    if (wsRef.current) { wsRef.current.close(); wsRef.current = null }
    const wsUrl = process.env.NEXT_PUBLIC_WS_URL ?? "ws://localhost:3001"
    const ws = new WebSocket(wsUrl)
    wsRef.current = ws
    let backoff = 1000

    ws.onopen = () => {
      for (const ch of channels) {
        ws.send(JSON.stringify({ action: "subscribe", channel: ch }))
      }
    }
    ws.onmessage = (ev) => {
      try {
        const msg = JSON.parse(ev.data as string) as { event: string; data: unknown }
        handleWsEvent(msg.event, msg.data)
      } catch { /* ignore */ }
    }
    ws.onclose = () => {
      if (!activeRef.current) return
      if (wsRef.current === ws) {
        reconnectRef.current = setTimeout(() => {
          backoff = Math.min(backoff * 2, 30000)
          connectWs(tkn, channels)
        }, backoff)
      }
    }
    ws.onerror = () => ws.close()
  }

  function handleWsEvent(event: string, data: unknown) {
    switch (event) {
      case "cart-update":
        setState({ kind: "live", cart: data as CartPayload })
        break
      case "sale-confirmed": {
        const d = data as { total: number; change: number }
        if (confirmedRef.current) clearTimeout(confirmedRef.current)
        setState({ kind: "confirmed", total: d.total, change: d.change ?? 0 })
        confirmedRef.current = setTimeout(() => setState({ kind: "idle" }), CONFIRMED_DURATION)
        break
      }
      case "cart-cleared":
      case "idle":
        setState({ kind: "idle" })
        break
      case "revoked":
        clearDeviceToken("screen")
        setToken(null)
        cleanup()
        setState({ kind: "unpaired" })
        break
    }
  }

  function startHeartbeat(tkn: string) {
    if (heartbeatRef.current) clearInterval(heartbeatRef.current)
    heartbeatRef.current = setInterval(async () => {
      try {
        const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
        const res = await fetch(`${apiUrl}/v1/screens?resource=heartbeat`, {
          method: "POST",
          headers: { Authorization: `Bearer ${tkn}`, "Content-Type": "application/json" },
          body: "{}",
        })
        if (res.status === 401) {
          clearDeviceToken("screen")
          setToken(null)
          cleanup()
          setState({ kind: "unpaired" })
        }
      } catch { /* best-effort */ }
    }, HEARTBEAT_INTERVAL)
  }

  const toggleFullscreen = () => {
    if (!document.fullscreenElement) {
      void document.documentElement.requestFullscreen()
    } else {
      void document.exitFullscreen()
    }
  }

  if (state.kind === "unpaired") {
    // Reusa el mismo componente que el POS (paridad visual obligatoria).
    return <DeviceNotConnected kind="screen" />
  }

  return (
    <div className="relative w-full min-h-screen">
      {/* Botón fullscreen — top-left, visible permanente (mockup 2026-06-28). */}
      <button
        type="button"
        onClick={toggleFullscreen}
        aria-label="Pantalla completa"
        className="absolute top-4 left-4 z-50 rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M3 7V3h4M21 7V3h-4M3 17v4h4M21 17v4h-4" />
        </svg>
      </button>
      {state.kind === "live" && <LiveView cart={state.cart} ctx={screenCtx} />}
      {state.kind === "confirmed" && <ConfirmedView total={state.total} change={state.change} />}
      {state.kind === "idle" && <IdleView ctx={screenCtx} />}
    </div>
  )
}
