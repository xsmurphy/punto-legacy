"use client"

import * as React from "react"
import { MoreHorizontal, type LucideIcon } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"

export interface RowAction {
  label: string
  /**
   * Se usa SOLO cuando la acción colapsa a botón suelto (única visible):
   * ahí el icono ES el botón. Dentro del dropdown los ítems van sin icono
   * (convención UI 2026-08-08: el menú de fila es texto solo).
   */
  icon: LucideIcon
  /** Acción imperativa. Mutuamente excluyente con `href`. */
  onSelect?: () => void
  /**
   * Si la acción es NAVEGAR, poné la URL acá en vez de un `onSelect` con
   * `window.open`: se renderiza como `<a>` y conserva ctrl/middle-click,
   * "abrir en pestaña nueva" y "copiar dirección" del menú contextual —
   * además de no chocar con los bloqueadores de popups.
   */
  href?: string
  /** Solo con `href`. */
  target?: string
  variant?: "destructive"
  disabled?: boolean
  hidden?: boolean
}

export interface RowActionsProps {
  actions: RowAction[]
}

/**
 * Única forma válida de renderizar acciones de fila en un <DataTable>.
 *
 * Por qué: 4 botones sueltos por fila (Abrir · copiar · Reconectar · Revocar)
 * infla la tabla y le come el ancho a los datos, y deja acciones destructivas
 * a un click de distancia sin fricción. Toda fila con 2+ acciones las agrupa
 * acá; una sola acción se queda como botón directo (un dropdown de 1 ítem es
 * peor que el botón suelto).
 */
export function RowActions({ actions }: RowActionsProps) {
  const visible = actions.filter((action) => !action.hidden)

  if (visible.length === 0) {
    return null
  }

  if (visible.length === 1) {
    const action = visible[0]
    const Icon = action.icon
    const className =
      action.variant === "destructive" ? "text-destructive hover:text-destructive" : undefined
    if (action.href) {
      return (
        <Button variant="ghost" size="icon" aria-label={action.label} title={action.label} asChild>
          <a href={action.href} target={action.target} rel="noreferrer" className={className}>
            <Icon className="size-4" />
          </a>
        </Button>
      )
    }
    return (
      <Button
        variant="ghost"
        size="icon"
        aria-label={action.label}
        title={action.label}
        disabled={action.disabled}
        onClick={action.onSelect}
        className={className}
      >
        <Icon className="size-4" />
      </Button>
    )
  }

  const nonDestructive = visible.filter((action) => action.variant !== "destructive")
  const destructive = visible.filter((action) => action.variant === "destructive")

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" aria-label="Más acciones">
          <MoreHorizontal className="size-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {nonDestructive.map((action) => (
          // `asChild` + `<a>` cuando la acción es navegar: el ítem sigue
          // siendo un link de verdad dentro del menú.
          <DropdownMenuItem
            key={action.label}
            disabled={action.disabled}
            onSelect={action.onSelect}
            asChild={action.href !== undefined}
          >
            {action.href !== undefined ? (
              <a href={action.href} target={action.target} rel="noreferrer">
                {action.label}
              </a>
            ) : (
              action.label
            )}
          </DropdownMenuItem>
        ))}
        {destructive.length > 0 && (
          <>
            {nonDestructive.length > 0 && <DropdownMenuSeparator />}
            {destructive.map((action) => (
              <DropdownMenuItem
                key={action.label}
                variant="destructive"
                disabled={action.disabled}
                onSelect={action.onSelect}
              >
                {action.label}
              </DropdownMenuItem>
            ))}
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
