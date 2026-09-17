"use client"

/**
 * Visor de la foto de una marcación (context/83 F1).
 *
 * ── Por qué no es un `<img src>` y punto ───────────────────────────────────
 *
 * El objeto es PRIVADO en S3: la foto sale del endpoint, que exige el Bearer
 * del panel. Un `src` es una navegación del browser y no adjunta headers, así
 * que volvería 401. Se baja como blob y se arma un object URL — el mismo camino
 * que ya usan los adjuntos del legajo.
 *
 * El object URL se revoca al cerrar. Sin eso, una revisión de veinte
 * marcaciones deja veinte fotos vivas en memoria hasta que se recargue la
 * página.
 */

import * as React from "react"
import { toast } from "sonner"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Skeleton } from "@/components/ui/skeleton"
import { fetchAttendancePhoto, type AttendanceMarkRow } from "@/hooks/use-attendance"

export function AttendancePhotoDialog({
  mark,
  onOpenChange,
}: {
  mark: AttendanceMarkRow | null
  onOpenChange: (open: boolean) => void
}) {
  const [url, setUrl] = React.useState<string | null>(null)
  const [loading, setLoading] = React.useState(false)

  React.useEffect(() => {
    if (!mark?.hasPhoto) {
      setUrl(null)
      return
    }
    let objectUrl: string | null = null
    let cancelled = false
    setLoading(true)

    fetchAttendancePhoto(mark.id)
      .then((blob) => {
        if (cancelled) return
        objectUrl = URL.createObjectURL(blob)
        setUrl(objectUrl)
      })
      .catch((err: unknown) => {
        if (cancelled) return
        toast.error(err instanceof Error ? err.message : "No se pudo abrir la foto")
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
      setUrl(null)
    }
  }, [mark])

  return (
    <Dialog open={mark !== null} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{mark?.employeeName ?? "Marcación"}</DialogTitle>
          <DialogDescription>
            {mark
              ? `${mark.kind === "in" ? "Entrada" : "Salida"} · ${mark.localDay} ${mark.localTime}`
              : ""}
          </DialogDescription>
        </DialogHeader>

        {loading ? (
          <Skeleton className="aspect-square w-full rounded-md" />
        ) : url ? (
          // eslint-disable-next-line @next/next/no-img-element -- object URL de
          // un blob: `next/image` optimiza URLs remotas y acá no hay ninguna,
          // hay bytes ya en memoria del browser.
          <img
            src={url}
            alt={`Foto de la marcación de ${mark?.employeeName ?? ""}`}
            className="w-full rounded-md object-cover"
          />
        ) : (
          <p className="py-8 text-center text-sm text-muted-foreground">
            Esta marcación no tiene foto.
          </p>
        )}
      </DialogContent>
    </Dialog>
  )
}
