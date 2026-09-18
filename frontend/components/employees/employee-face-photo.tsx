"use client"

/**
 * La foto con la que quedó registrado el rostro de una persona.
 *
 * Existe para una sola pregunta: ¿la cara que quedó guardada es la de esta
 * persona? El panel no captura ni sube rostros —eso pasa en el reloj, con la
 * persona enfrente, y esa separación es la seguridad de la feature (ver
 * `employee-face-field.tsx`)— pero sí puede mostrar la que se registró.
 *
 * El objeto es PRIVADO en S3: se baja con el Bearer del panel y se pinta desde
 * un object URL, que se revoca al desmontar. Un `<img src>` al endpoint daría
 * 401 porque una carga de imagen no adjunta headers.
 */

import * as React from "react"
import { Loader2, ScanFace } from "lucide-react"

import { EmptyState } from "@/components/empty-state"
import { fetchEmployeeFacePhoto, type Employee } from "@/hooks/use-employees"
import { formatDate } from "@/lib/format-date"

export function EmployeeFacePhoto({ employee }: { employee: Employee | null }) {
  const enrolled = employee?.face ?? null
  const employeeId = employee?.id ?? null

  const [url, setUrl] = React.useState<string | null>(null)
  const [failed, setFailed] = React.useState(false)
  const [loading, setLoading] = React.useState(false)

  React.useEffect(() => {
    if (!employeeId || !enrolled) {
      setUrl(null)
      return
    }
    let objectUrl: string | null = null
    let cancelled = false
    setLoading(true)
    setFailed(false)

    fetchEmployeeFacePhoto(employeeId)
      .then((blob) => {
        if (cancelled) return
        objectUrl = URL.createObjectURL(blob)
        setUrl(objectUrl)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
    // `enrolled.enrolledAt` en las deps: volver a registrar el rostro cambia la
    // foto sin cambiar el id de la persona.
  }, [employeeId, enrolled?.enrolledAt, enrolled])

  if (!enrolled) {
    return (
      <EmptyState
        icon={ScanFace}
        title="Sin rostro registrado"
        description="Se registra desde el reloj de marcación de su sucursal."
        ghost={false}
      />
    )
  }

  if (loading) {
    return (
      <div className="flex h-48 items-center justify-center">
        <Loader2 className="size-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // El rostro está registrado pero la foto no se pudo traer. Se dice en una
  // línea y sin explicar el mecanismo — lo accionable es volver a registrarlo.
  if (failed || !url) {
    return (
      <EmptyState
        icon={ScanFace}
        title="No se pudo mostrar la foto"
        description="Volvé a registrar el rostro desde el reloj de su sucursal."
        ghost={false}
      />
    )
  }

  return (
    <div className="flex flex-col gap-2">
      {/* eslint-disable-next-line @next/next/no-img-element -- object URL de un
          blob privado: `next/image` optimiza por URL y no puede con blob:. */}
      <img
        src={url}
        alt="Rostro registrado"
        className="size-40 rounded-lg border object-cover"
      />
      {enrolled.enrolledAt && (
        <span className="text-sm text-muted-foreground">
          Registrado el {formatDate(enrolled.enrolledAt)}
        </span>
      )}
    </div>
  )
}
