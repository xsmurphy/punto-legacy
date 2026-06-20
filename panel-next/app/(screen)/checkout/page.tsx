"use client"
import * as React from "react"
import { PairingView } from "./pairing-view"
import { LiveView } from "./live-view"
import { ConfirmedView } from "./confirmed-view"
import { IdleView } from "./idle-view"

const TOKEN_KEY = "punto_screen_token"
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
  | { kind: "pairing"; pin: string | null }
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

export default function CheckoutPage() {
  const [state, setState] = React.useState<ScreenState>({ kind: "pairing", pin: null })
  const [token, setToken] = React.useState<string | null>(null)
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

  // Al montar: leer token de localStorage
  React.useEffect(() => {
    activeRef.current = true
    const stored = localStorage.getItem(TOKEN_KEY)
    if (stored) {
      setToken(stored)
    } else {
      void requestPin()
    }
    return () => {
      activeRef.current = false
      cleanup()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Cuando cambia el token: conectar WS
  React.useEffect(() => {
    if (!token) return
    const claims = decodeJwtPayload(token)
    const cid = claims["cid"] as string | undefined
    const rid = claims["rid"] as string | undefined
    const did = claims["did"] as string | undefined
    if (!cid || !rid) {
      localStorage.removeItem(TOKEN_KEY)
      setToken(null)
      void requestPin()
      return
    }
    const channels = [`${cid}:checkout:${rid}`, `screen:${did}`]
    connectWs(token, channels)
    startHeartbeat(token)
    setState({ kind: "idle" })
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

  async function requestPin() {
    try {
      const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? ""
      const res = await fetch(`${apiUrl}/v1/screens?resource=request`, { method: "POST" })
      const json = (await res.json()) as { pin?: string; channel?: string }
      if (!json.pin) throw new Error("no pin")
      setState({ kind: "pairing", pin: json.pin })
      connectPairingWs(`pairing:${json.pin}`)
    } catch {
      reconnectRef.current = setTimeout(() => { void requestPin() }, 5000)
    }
  }

  function connectPairingWs(channel: string) {
    if (wsRef.current) { wsRef.current.close(); wsRef.current = null }
    const wsUrl = process.env.NEXT_PUBLIC_WS_URL ?? "ws://localhost:3001"
    const ws = new WebSocket(wsUrl)
    wsRef.current = ws
    let backoff = 1000

    ws.onopen = () => { ws.send(JSON.stringify({ action: "subscribe", channel })) }
    ws.onmessage = (ev) => {
      try {
        const msg = JSON.parse(ev.data as string) as { event: string; data: unknown }
        if (msg.event === "paired") {
          const d = msg.data as { token: string }
          localStorage.setItem(TOKEN_KEY, d.token)
          ws.close()
          setToken(d.token)
        }
      } catch { /* ignore */ }
    }
    ws.onclose = () => {
      if (!activeRef.current) return
      if (wsRef.current === ws) {
        reconnectRef.current = setTimeout(() => {
          backoff = Math.min(backoff * 2, 30000)
          connectPairingWs(channel)
        }, backoff)
      }
    }
    ws.onerror = () => ws.close()
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
        localStorage.removeItem(TOKEN_KEY)
        setToken(null)
        cleanup()
        void requestPin()
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
          localStorage.removeItem(TOKEN_KEY)
          setToken(null)
          cleanup()
          void requestPin()
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

  return (
    <div className="relative w-full min-h-screen">
      <div className="absolute top-3 right-3 z-50 opacity-0 hover:opacity-100 transition-opacity">
        <button
          type="button"
          onClick={toggleFullscreen}
          className="text-xs text-muted-foreground px-2 py-1 rounded bg-muted/50 hover:bg-muted"
        >
          Pantalla completa
        </button>
      </div>
      {state.kind === "pairing" && <PairingView pin={state.pin} />}
      {state.kind === "live" && <LiveView cart={state.cart} />}
      {state.kind === "confirmed" && <ConfirmedView total={state.total} change={state.change} />}
      {state.kind === "idle" && <IdleView />}
    </div>
  )
}
