"use client"

/**
 * Montado en el layout del POS. Escucha errores globales (`error` y
 * `unhandledrejection`) buscando ChunkLoadError / módulo dinámico que no
 * carga — típico tras un deploy con la PWA vieja en caché (Serwist,
 * skipWaiting+clientsClaim) — y dispara un reload automático único.
 *
 * Si el reload ya se intentó en esta sesión de tab (guard en
 * sessionStorage), no vuelve a recargar: se deja que `app/(pos)/error.tsx`
 * muestre el estado de error para que el cajero no quede en un loop.
 */

import * as React from "react"
import { isChunkLoadError, reloadOnceForChunkError } from "@/lib/pos/chunk-error-reload"

export function ChunkErrorListener() {
  React.useEffect(() => {
    function handleError(event: ErrorEvent) {
      if (isChunkLoadError(event.error ?? event.message)) {
        reloadOnceForChunkError()
      }
    }

    function handleRejection(event: PromiseRejectionEvent) {
      if (isChunkLoadError(event.reason)) {
        reloadOnceForChunkError()
      }
    }

    window.addEventListener("error", handleError)
    window.addEventListener("unhandledrejection", handleRejection)
    return () => {
      window.removeEventListener("error", handleError)
      window.removeEventListener("unhandledrejection", handleRejection)
    }
  }, [])

  return null
}
