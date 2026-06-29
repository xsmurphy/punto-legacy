"use client"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { RealtimeProvider } from "@/components/realtime-provider"
import { useRealtimeSync } from "@/hooks/use-realtime-sync"

function Inner({ scope, children }: { scope: "panel" | "pos"; children: React.ReactNode }) {
  useRealtimeSync(scope)
  return <>{children}</>
}

export function RealtimeWire({
  scope,
  children,
}: {
  scope: "panel" | "pos"
  children: React.ReactNode
}) {
  const { data } = useBootstrap()
  const companyId = data?.companyId != null ? String(data.companyId) : null
  return (
    <RealtimeProvider companyId={companyId}>
      <Inner scope={scope}>{children}</Inner>
    </RealtimeProvider>
  )
}
