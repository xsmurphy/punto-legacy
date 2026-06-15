"use client"

import * as React from "react"
import { useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"

import { PlanPanel } from "@/components/billing/plan-panel"

// Página standalone de "Mi plan". El contenido vive en `<PlanPanel>` para que
// también pueda embeberse en el modal /settings (sección "Mi plan"). Esta
// ruta SIGUE siendo el SUCCESS_URL de dLocal Go — por eso queda el handler
// de `?checkout=success` aquí (depende de la URL, no del contenido).
export default function HistoryBillingPage() {
  const qc = useQueryClient()

  React.useEffect(() => {
    if (typeof window === "undefined") return
    const params = new URLSearchParams(window.location.search)
    if (params.get("checkout") !== "success") return

    toast.success(
      "¡Pago recibido! Tus créditos se acreditan en unos instantes.",
    )
    void qc.invalidateQueries({ queryKey: ["billing"] })

    // Segunda invalidación a los 4 s (el webhook es async)
    const timer = setTimeout(() => {
      void qc.invalidateQueries({ queryKey: ["billing"] })
    }, 4000)

    window.history.replaceState({}, "", window.location.pathname)

    return () => clearTimeout(timer)
  }, [qc])

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-bold tracking-tight">Mi plan</h1>
        <p className="text-sm text-muted-foreground">
          Tu plan, tus créditos y tus facturas.
        </p>
      </div>
      <PlanPanel />
    </div>
  )
}
