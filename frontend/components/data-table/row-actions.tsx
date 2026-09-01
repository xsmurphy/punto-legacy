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
  /**
   * POR QUÉ está deshabilitada. Se renderiza como una segunda línea, en el
   * ítem mismo — el impedimento se explica en el control que impide, que es la
   * regla del proyecto, y acá encima el control es el único lugar donde el
   * usuario lo va a buscar.
   *
   * No es un `title`: un ítem deshabilitado de Radix lleva
   * `pointer-events: none`, así que nunca dispararía el tooltip nativo. Y
   * tampoco sería suficiente en tablet, donde no hay hover. Texto visible,
   * siempre.
   *
   * Se ignora si la acción no está `disabled` — un motivo sin impedimento no
   * significa nada.
   */
  reason?: string
  hidden?: boolean
}

export interface RowActionsProps {
  actions: RowAction[]
}

/**
 * Etiqueta del ítem, con el motivo debajo cuando la acción está impedida.
 * Componente y no una expresión inline porque los ítems se renderizan en dos
 * mapas distintos (no destructivos y destructivos) y el motivo tiene que verse
 * igual en los dos.
 */
function ActionLabel({ action }: { action: RowAction }) {
  if (!action.disabled || !action.reason) return <>{action.label}</>
  return (
    <span className="flex flex-col items-start gap-0.5">
      <span>{action.label}</span>
      <span className="text-xs font-normal text-muted-foreground">{action.reason}</span>
    </span>
  )
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
        // Colapsada a botón, el motivo no tiene dónde renderizarse como texto
        // — acá sí sirve el `title`, porque el `disabled` de un <button> nativo
        // igual muestra el tooltip del navegador al pasar por encima.
        title={action.disabled && action.reason ? `${action.label} — ${action.reason}` : action.label}
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
              <ActionLabel action={action} />
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
                <ActionLabel action={action} />
              </DropdownMenuItem>
            ))}
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
