"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { Skeleton } from "@/components/ui/skeleton"
import { Button } from "@/components/ui/button"
import { useAdminMe, type AdminMe } from "@/hooks/use-admin"
import { AdminApiError } from "@/lib/api-admin"

// Contexto para que los hijos puedan leer el admin logueado.
export const AdminContext = React.createContext<AdminMe | null>(null)

export function useAdminContext(): AdminMe {
  const ctx = React.useContext(AdminContext)
  if (!ctx) throw new Error("useAdminContext debe usarse dentro de AdminAuthGuard")
  return ctx
}

/**
 * Guard del realm admin.
 *
 * - Llama a /api/admin/me.php.
 * - Mientras carga → spinner esqueleto centrado.
 * - Si 401 → redirige a /admin/login.
 * - Si ok → renderiza children con AdminContext provisto.
 *
 * NUNCA importar hooks del realm tenant (useBootstrap, useSettings, etc.) desde acá.
 */
export function AdminAuthGuard({ children }: { children: React.ReactNode }) {
  const router = useRouter()
  const { data: admin, isLoading, error } = useAdminMe()

  React.useEffect(() => {
    if (error instanceof AdminApiError && error.status === 401) {
      router.replace("/admin/login")
    }
  }, [error, router])

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="flex flex-col items-center gap-3">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-4 w-32" />
        </div>
      </div>
    )
  }

  // 401 → el useEffect redirige; mientras tanto no renderizamos nada.
  if (error instanceof AdminApiError && error.status === 401) return null

  // Otros errores (500, red caída) → mostrar mensaje en lugar de pantalla en blanco.
  if (error) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="flex flex-col items-center gap-3 text-center max-w-sm px-4">
          <p className="text-sm font-medium text-destructive">Error al cargar el panel admin</p>
          <p className="text-xs text-muted-foreground">
            {error instanceof Error ? error.message : "Error desconocido"}
          </p>
          <Button variant="outline" size="sm" onClick={() => window.location.reload()}>
            Reintentar
          </Button>
        </div>
      </div>
    )
  }

  if (!admin) return null

  return <AdminContext.Provider value={admin}>{children}</AdminContext.Provider>
}
