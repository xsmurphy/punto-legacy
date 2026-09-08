"use client"

import * as React from "react"
import { Upload } from "lucide-react"

/**
 * Arrastrar y soltar archivos sobre el chat del agente.
 *
 * Hook + overlay compartidos por las DOS superficies (el FAB y la página
 * `/chat`). Estaba implementado solo en el FAB, así que en `/chat` soltar un
 * archivo no hacía nada — la misma asimetría que dejó esa página sin adjuntos
 * en general (2026-09-08). Se extrae en vez de copiarse: dos copias de un
 * contador de drag es exactamente lo que se desincroniza.
 *
 * El contador (`dragCounter`) NO es decorativo: `dragenter`/`dragleave` se
 * disparan también al cruzar CADA hijo anidado, así que sin contar entradas y
 * salidas el overlay parpadea mientras el usuario mueve el archivo por encima.
 */
export function useFileDrop({
  onFiles,
  enabled = true,
}: {
  onFiles: (files: File[]) => void
  enabled?: boolean
}) {
  const [isDragging, setIsDragging] = React.useState(false)
  const dragCounter = React.useRef(0)

  const carriesFiles = React.useCallback(
    (e: React.DragEvent) => enabled && Array.from(e.dataTransfer?.types ?? []).includes("Files"),
    [enabled],
  )

  const handlers = React.useMemo(
    () => ({
      onDragEnter: (e: React.DragEvent) => {
        if (!carriesFiles(e)) return
        e.preventDefault()
        dragCounter.current += 1
        setIsDragging(true)
      },
      onDragOver: (e: React.DragEvent) => {
        if (!carriesFiles(e)) return
        e.preventDefault()
        e.dataTransfer.dropEffect = "copy"
      },
      onDragLeave: (e: React.DragEvent) => {
        if (!carriesFiles(e)) return
        dragCounter.current = Math.max(0, dragCounter.current - 1)
        if (dragCounter.current === 0) setIsDragging(false)
      },
      onDrop: (e: React.DragEvent) => {
        if (!carriesFiles(e)) return
        e.preventDefault()
        dragCounter.current = 0
        setIsDragging(false)
        onFiles(Array.from(e.dataTransfer.files ?? []))
      },
    }),
    [carriesFiles, onFiles],
  )

  return { isDragging, handlers }
}

/** Cartel que cubre el área mientras se arrastra un archivo encima. */
export function FileDropOverlay() {
  return (
    <div className="pointer-events-none absolute inset-0 z-50 flex items-center justify-center bg-foreground/5 backdrop-blur-[2px]">
      <div className="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-foreground/40 bg-card px-8 py-6 shadow-lg">
        <Upload className="size-8 text-foreground/70" />
        <p className="text-sm font-medium text-foreground">Soltá para adjuntar</p>
        {/* El PDF entró al soporte el 2026-09-08 — el texto lo nombra porque
            es el formato que el comercio más manda (la factura del proveedor). */}
        <p className="text-xs text-muted-foreground">Imagen, PDF, Excel o CSV</p>
      </div>
    </div>
  )
}
