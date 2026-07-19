"use client"

/**
 * Tiempo transcurrido "vivo" (tick cada segundo) desde un timestamp, con tier
 * de alerta configurable — usado por las tarjetas de orden del KDS y la
 * pantalla de mozos (O2, context/24-orders-module-plan.md) para el color de
 * urgencia (verde/ámbar/rojo).
 */

import * as React from "react"

export type ElapsedTier = "fresh" | "warn" | "late"

export interface ElapsedThresholds {
  warnMin: number
  lateMin: number
}

export interface ElapsedResult {
  label: string
  minutes: number
  tier: ElapsedTier
}

export function useElapsed(since: string | null | undefined, thresholds: ElapsedThresholds): ElapsedResult {
  const [now, setNow] = React.useState(() => Date.now())

  React.useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(t)
  }, [])

  return React.useMemo(() => {
    if (!since) return { label: "—", minutes: 0, tier: "fresh" as const }
    const sinceMs = new Date(since).getTime()
    const diffMs = Math.max(0, now - sinceMs)
    const minutes = Math.floor(diffMs / 60000)
    const seconds = Math.floor((diffMs % 60000) / 1000)
    const tier: ElapsedTier =
      minutes >= thresholds.lateMin ? "late" : minutes >= thresholds.warnMin ? "warn" : "fresh"
    const label = minutes < 1 ? `${seconds}s` : minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`
    return { label, minutes, tier }
  }, [now, since, thresholds.warnMin, thresholds.lateMin])
}
