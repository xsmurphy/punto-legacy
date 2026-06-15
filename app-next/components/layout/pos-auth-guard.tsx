"use client"

/**
 * Guard de autenticación del POS.
 *
 * Verifica que exista una sesión válida (`_jwt` cookie, realm `pos-app`)
 * consultando el BFF bootstrap. Si no hay sesión (401), redirige a
 * la pantalla de login del POS.
 *
 * Diferencias vs PanelAuthGuard (panel-next):
 *   - Cookie: `_jwt` (realm pos-app), NO `_jwt_panel`.
 *   - Layout: full-screen (sin sidebar).
 *   - Estado extra: sesión de caja (registerId, outletId) desde bootstrap.
 *
 * TODO (Sprint 0 → Slice A):
 *   - Conectar `useBootstrap()` al fetch real de `/api/pos/bootstrap`.
 *   - Implementar selector de caja si `registers.length > 1`.
 *   - Manejar handoff JWT desde `panel-next` (SSO: el cajero se loguea en
 *     panel y el POS hereda la sesión via cookie `.punto.la`).
 *
 * Ver context/16-app-next-rewrite.md §7 Sprint 0 y §3 (Auth: cookie _jwt).
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { ApiError } from "@/lib/api-client"
import { usePosBootstrap } from "@/hooks/use-pos-bootstrap"
import { Skeleton } from "@/components/ui/skeleton"

export function PosAuthGuard({ children }: { children: React.ReactNode }) {
  const router = useRouter()
  const { data: bootstrap, isLoading, error } = usePosBootstrap()

  React.useEffect(() => {
    if (error instanceof ApiError && error.status === 401) {
      // TODO (Slice A): implementar página de login del POS.
      // Por ahora redirige al login del panel-next mientras no existe el login propio.
      router.replace("/login")
    }
  }, [error, router])

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center bg-background">
        <div className="flex flex-col items-center gap-4">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-4 w-32" />
        </div>
      </div>
    )
  }

  // Si hay error no-401 (502, etc.), mostrar pantalla de error mínima.
  if (error && !(error instanceof ApiError && error.status === 401)) {
    return (
      <div className="flex h-screen items-center justify-center bg-background">
        <div className="text-center">
          <p className="text-sm text-muted-foreground">
            No se pudo conectar al servidor.
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            {error instanceof Error ? error.message : "Error desconocido"}
          </p>
        </div>
      </div>
    )
  }

  // Sesión válida o bootstrap stub (Sprint 0 — el stub no lanza 401).
  // TODO (Slice A): validar que bootstrap.outlet.id y registers.length > 0.
  void bootstrap

  return <>{children}</>
}
