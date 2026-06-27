"use client"

import * as React from "react"
import { QueryClient, QueryClientProvider } from "@tanstack/react-query"
import { TooltipProvider } from "@/components/ui/tooltip"
import { Toaster } from "@/components/ui/sonner"
import { setSharedQueryClient } from "@/lib/auth/query-client-singleton"

export function Providers({ children }: { children: React.ReactNode }) {
  // QueryClient en useState garantiza una sola instancia por render tree,
  // aún con re-renders del Server Component padre durante streaming.
  const [queryClient] = React.useState(() => {
    const qc = new QueryClient({
      defaultOptions: {
        queries: {
          staleTime: 60 * 1000,
          refetchOnWindowFocus: false,
          retry: 1,
        },
      },
    })
    setSharedQueryClient(qc)
    return qc
  })

  return (
    <QueryClientProvider client={queryClient}>
      <TooltipProvider delayDuration={300}>
        {children}
        {/* richColors apagado — el theme neutro comunica intent con ícono +
            texto en lugar de verde/rojo (filosofía Linear). */}
        <Toaster position="top-center" />
      </TooltipProvider>
    </QueryClientProvider>
  )
}
