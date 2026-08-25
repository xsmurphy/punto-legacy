"use client"

/**
 * ActionMenu — menú de acciones contextual: `DropdownMenu` en desktop,
 * drawer inferior en móvil.
 *
 * POR QUÉ EXISTE. Un `DropdownMenu` anclado al trigger es correcto con mouse
 * y con una tablet de caja, pero en un teléfono de 390pt la lista sale
 * pegada al borde, tapa la fila que la abrió y sus ítems quedan a un par de
 * píxeles del dedo: es el mismo motivo por el que los modales chicos bajan
 * como drawer bajo el breakpoint (`context/14-ui-conventions.md` §2.2 #1).
 * El owner lo reportó sobre los menús de fila del POS (2026-08-25) y la
 * regla es: en móvil SIEMPRE de abajo hacia arriba.
 *
 * Va acá y no en cada call-site a propósito — el bug era el mismo en el
 * detalle de transacción, en el de orden y en el badge de estado, y un fix
 * por menú se desincroniza al primer menú nuevo. Es el equivalente de
 * `ResponsiveDialog` para el actionsheet: mismo corte de breakpoint
 * (`useIsMobile`, 768px), misma prohibición de importar `drawer` desde un
 * call-site.
 *
 * API DECLARATIVA (y no un espejo de `DropdownMenu*`): las acciones viajan
 * como datos, así el drawer las puede pintar como lista y —lo que importa—
 * la regla del owner "menú de fila = TEXTO SOLO, sin iconos ni 'Marcar
 * como'" queda impuesta por el tipo, no por la disciplina del que escribe el
 * call-site. `RowAction` de `components/data-table/row-actions.tsx` es la
 * misma forma menos el `icon` obligatorio (ese lo usa cuando la acción
 * colapsa a botón suelto en una tabla del panel).
 *
 * Las destructivas van al final y separadas, igual que en `RowActions`.
 */

import * as React from "react"

import { cn } from "@/lib/utils"
import { useIsMobile } from "@/hooks/use-mobile"
import { Button } from "@/components/ui/button"
import { Separator } from "@/components/ui/separator"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import {
  Drawer,
  DrawerClose,
  DrawerContent,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
} from "@/components/ui/drawer"

export interface ActionMenuAction {
  /** Texto de la acción. Sin icono: el menú es texto solo (regla del owner). */
  label: string
  onSelect: () => void
  variant?: "destructive"
  disabled?: boolean
  /** La acción no aplica en este contexto — se filtra antes de contar. */
  hidden?: boolean
}

export interface ActionMenuProps {
  actions: ActionMenuAction[]
  /**
   * Control que abre el menú. Se le pasa `asChild` al trigger de la rama que
   * corresponda, así que tiene que ser UN elemento que acepte ref y props
   * (un `<Button>`, un `<Badge>`).
   */
  trigger: React.ReactElement
  /**
   * Encabezado de la rama drawer. También es el nombre accesible del diálogo
   * (vaul lo exige), por eso no es opcional en la práctica.
   */
  title?: string
  /** Alineación de la rama DropdownMenu. Sin efecto en el drawer. */
  align?: "start" | "center" | "end"
}

export function ActionMenu({
  actions,
  trigger,
  title = "Acciones",
  align = "end",
}: ActionMenuProps) {
  const isMobile = useIsMobile()
  const [open, setOpen] = React.useState(false)

  const visible = actions.filter((a) => !a.hidden)
  const regular = visible.filter((a) => a.variant !== "destructive")
  const destructive = visible.filter((a) => a.variant === "destructive")

  if (visible.length === 0) return null

  if (isMobile) {
    return (
      <Drawer open={open} onOpenChange={setOpen}>
        <DrawerTrigger asChild>{trigger}</DrawerTrigger>
        {/* `mx-auto max-w-lg`: mismo ancho acotado que el resto de los
            actionsheets del POS (ver `ResponsiveDialogContent`). */}
        <DrawerContent className="mx-auto max-w-lg">
          <DrawerHeader className="pb-2">
            <DrawerTitle>{title}</DrawerTitle>
          </DrawerHeader>
          <div className="flex flex-col overflow-y-auto px-2 pb-2">
            {regular.map((action) => (
              <ActionRow key={action.label} action={action} onDone={() => setOpen(false)} />
            ))}
            {destructive.length > 0 && regular.length > 0 && (
              <Separator className="my-1.5" />
            )}
            {destructive.map((action) => (
              <ActionRow key={action.label} action={action} onDone={() => setOpen(false)} />
            ))}
          </div>
          <DrawerClose className="sr-only">Cerrar</DrawerClose>
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
      <DropdownMenuContent align={align}>
        {regular.map((action) => (
          <DropdownMenuItem
            key={action.label}
            disabled={action.disabled}
            onSelect={action.onSelect}
          >
            {action.label}
          </DropdownMenuItem>
        ))}
        {destructive.length > 0 && regular.length > 0 && <DropdownMenuSeparator />}
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
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/**
 * Fila del drawer. `Button` ghost a ancho completo y no un `<button>` pelado:
 * el primitive ya trae el foco visible, el estado disabled y —vía
 * `[data-pos-touch]`— el mínimo táctil de 44px en móvil.
 */
function ActionRow({
  action,
  onDone,
}: {
  action: ActionMenuAction
  onDone: () => void
}) {
  return (
    <Button
      variant="ghost"
      size="lg"
      disabled={action.disabled}
      onClick={() => {
        onDone()
        action.onSelect()
      }}
      className={cn(
        "w-full justify-start rounded-lg px-3 text-[15px] font-medium",
        action.variant === "destructive" &&
          "text-destructive hover:bg-destructive/10 hover:text-destructive",
      )}
    >
      {action.label}
    </Button>
  )
}
