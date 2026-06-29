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
