"use client"

import * as React from "react"
import { connectRealtime, disconnectRealtime } from "@/lib/realtime"

/**
 * Wrapper que abre el WS cuando hay companyId disponible. La url se toma de
 * NEXT_PUBLIC_WS_URL (configurada en Coolify). Si falta la env var, el
 * provider es un no-op — la app funciona pero sin sync realtime.
 */
export function RealtimeProvider({
  companyId,
  children,
}: {
  companyId: string | null | undefined
  children: React.ReactNode
}) {
  React.useEffect(() => {
    const url = process.env.NEXT_PUBLIC_WS_URL
    if (!url || !companyId) return
    connectRealtime(companyId, url)
    return () => disconnectRealtime()
  }, [companyId])

  return <>{children}</>
}
