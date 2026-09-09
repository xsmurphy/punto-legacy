"use client"

import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * Sección de formulario consistente cross-app. Reemplaza los Card+CardHeader+
 * CardTitle (text-sm font-medium) que se confundían con los FormLabel
 * (también text-sm font-medium) — no había jerarquía visual.
 *
 * Patrón:
 *  - Título: text-base font-semibold tracking-tight (16px / 600 / -0.4px)
 *  - Border-b sutil debajo del título (separador visual sin Card)
 *  - Sin Card → menos bordes en pantallas con muchas secciones (settings,
 *    contacts/[id], outlets/[id], items/[id]).
 *
 * Usalo en cualquier form que tenga grupos de campos. Si la sección necesita
 * un borde envolvente, envolvé en un <Card> externo manualmente — esto es
 * solo el bloque visual de "título + contenido".
 */
export function FormSection({
  title,
  description,
  className,
  children,
}: {
  title: string
  description?: string
  className?: string
  children: React.ReactNode
}) {
  return (
    <section className={cn("flex flex-col gap-4", className)}>
      <div className="flex flex-col gap-1 border-b pb-2">
        <h3 className="text-base font-semibold tracking-tight">{title}</h3>
        {description && (
          <p className="text-xs text-muted-foreground">{description}</p>
        )}
      </div>
      <div className="flex flex-col gap-4">{children}</div>
    </section>
  )
}

/**
 * Varias <FormSection> en columnas, sin huecos.
 *
 * Usa columnas CSS y NO un `grid lg:grid-cols-2`: en un grid las celdas de
 * una misma fila se estiran a la altura de la más alta, así que una sección
 * corta al lado de una larga deja un hueco del tamaño de la diferencia
 * (reportado por el owner en `/settings?section=pos`, donde "Ventas" convive
 * con "Cajas y arqueo"). Con columnas el contenido fluye y el navegador
 * balancea las alturas solo.
 *
 * `break-inside-avoid` mantiene cada sección entera en una columna, y el
 * `mb-6` va en los hijos porque en multi-columna `gap` es solo horizontal.
 *
 * A cambio, el orden de lectura pasa a ser por columna (toda la izquierda,
 * después la derecha). Sirve para bloques independientes entre sí —
 * ajustes, grupos de campos sin secuencia. Si el orden importa, una sola
 * columna.
 */
export function FormSectionColumns({
  children,
  className,
}: {
  children: React.ReactNode
  className?: string
}) {
  return (
    <div
      className={cn(
        "columns-1 gap-6 lg:columns-2 [&>*]:mb-6 [&>*]:break-inside-avoid",
        className,
      )}
    >
      {children}
    </div>
  )
}
