"use client"

import * as React from "react"
import { Pencil, Archive } from "lucide-react"

import { Button } from "@/components/ui/button"

/**
 * Fila de una taxonomía de Finanzas en `/finanzas/configuracion`: nombre +
 * código contable + badges de estado + acciones.
 *
 * Compartida por la sección de categorías y la de centros de costo. Solo se
 * comparte LA FILA, no la sección entera: una categoría vive en un árbol de 2
 * niveles con padre, `kind` y defaults del sistema, y un centro de costo es una
 * lista plana. Parametrizar esa diferencia daría un componente con más flags
 * que contenido — la fila, en cambio, es idéntica en las dos.
 */
export function TaxonomyRow({
  name,
  code,
  badges,
  indented = false,
  onEdit,
  onArchive,
}: {
  name: string
  /** Código contable externo. null → se pinta un guion tenue, no un hueco. */
  code: string | null
  /** Badges de estado propios de cada taxonomía (ej. "Por defecto"). */
  badges?: React.ReactNode
  indented?: boolean
  onEdit: () => void
  /** Omitido = la fila no se puede archivar (ej. categorías del sistema). */
  onArchive?: () => void
}) {
  return (
    <div
      className={`flex items-center justify-between gap-2 rounded-md px-2 py-2 hover:bg-accent/50 ${
        indented ? "ml-6 border-l pl-3" : ""
      }`}
    >
      <div className="flex min-w-0 items-center gap-2">
        <span className="truncate text-sm">{name}</span>
        {/* `font-mono` porque es un identificador que se compara carácter a
            carácter contra el listado del contador — la tipografía
            proporcional hace que "1.11" y "1.II" se parezcan demasiado. */}
        <span className="shrink-0 font-mono text-xs text-muted-foreground">
          {code ?? <span className="opacity-40">—</span>}
        </span>
        {badges}
      </div>
      <div className="flex shrink-0 items-center gap-1">
        <Button variant="ghost" size="icon" onClick={onEdit} aria-label={`Editar ${name}`}>
          <Pencil className="size-4" />
        </Button>
        {onArchive && (
          <Button variant="ghost" size="icon" onClick={onArchive} aria-label={`Archivar ${name}`}>
            <Archive className="size-4" />
          </Button>
        )}
      </div>
    </div>
  )
}
