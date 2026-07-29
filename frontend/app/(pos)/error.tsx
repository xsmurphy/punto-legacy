"use client"

/**
 * Error boundary del árbol POS completo (`app/(pos)/**`).
 *
 * Contexto: la PWA (Serwist, skipWaiting+clientsClaim) sirve un shell que
 * puede quedar viejo tras un deploy — el cliente intenta pedir un chunk JS
 * con hash que ya no existe en el servidor → ChunkLoadError → Chrome muestra
 * su pantalla nativa "This page couldn't load". Este boundary evita esa
 * pantalla nativa mostrando un estado propio, y para el caso específico de
 * chunk viejo, `ChunkErrorReload` (montado en el layout) ya intentó un
 * reload automático antes de que este boundary se vea — si igual llegamos
 * acá es porque ese reload ya se gastó (guard de sessionStorage) o el error
 * no es de chunk.
 */

import * as React from "react"
import { AlertTriangle, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { isChunkLoadError, reloadNowForChunkError } from "@/lib/pos/chunk-error-reload"

export default function PosError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  React.useEffect(() => {
    console.error(error)
  }, [error])

  function handleRetry() {
    if (isChunkLoadError(error)) {
      // Purga el shell cacheado antes de recargar: un reload pelado vuelve a
      // recibir del service worker el mismo HTML/chunk viejo que causó el error.
      reloadNowForChunkError()
      return
    }
    reset()
  }

  return (
    <div className="flex h-svh items-center justify-center p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <div className="mb-2 flex size-10 items-center justify-center rounded-full bg-destructive/10">
            <AlertTriangle className="size-5 text-destructive" />
          </div>
          <CardTitle className="text-2xl font-semibold">No se pudo cargar</CardTitle>
          <CardDescription>
            Hubo un problema al cargar esta pantalla. Puede ser una actualización reciente de la app — recargá para
            traer la versión nueva.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button onClick={handleRetry} className="w-full">
            <RefreshCw className="size-4" />
            Recargar
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}
