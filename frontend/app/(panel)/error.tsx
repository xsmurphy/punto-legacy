"use client"

import * as React from "react"
import { AlertTriangle } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

/**
 * Red de contención del panel entero.
 *
 * Sin esto, un error de render en CUALQUIER pantalla del panel tira la
 * aplicación completa: pantalla negra, "This page couldn't load" del runtime
 * de Next y —lo caro— el estado en memoria perdido. Le pasó al owner el
 * 2026-09-08 confirmando el alta de facturación electrónica: el backend
 * devolvió un error, el cliente lo puso en el JSX como objeto, React lo
 * rechazó (error #31) y se llevó puesta la conversación del asistente.
 *
 * La causa de ESE caso se arregló en `lib/agent/confirm-api.ts`, que es donde
 * correspondía. Esto es otra cosa: que el próximo objeto mal puesto —en
 * cualquiera de las pantallas del panel— cueste una tarjeta de error y un
 * botón de reintentar, y no la sesión de trabajo.
 *
 * Va en `(panel)` y no en cada pantalla: `items/error.tsx` existía y por eso
 * artículos ya estaba cubierto, pero replicar el archivo por sección garantiza
 * que la que se olvide sea justo la que falle.
 */
export default function PanelError({
  error,
  reset,
}: {
  error: Error & { digest?: string }
  reset: () => void
}) {
  React.useEffect(() => {
    console.error("[panel] render error:", error)
  }, [error])

  return (
    <Card>
      <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
        <AlertTriangle className="size-8 text-destructive opacity-70" />
        <div className="flex flex-col gap-1">
          <p className="font-medium">Algo falló al mostrar esta pantalla</p>
          <p className="text-xs text-muted-foreground">
            {error.message || "Ocurrió un error inesperado."}
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={reset}>
          Reintentar
        </Button>
      </CardContent>
    </Card>
  )
}
