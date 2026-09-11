"use client"

/**
 * Switcher de sucursales del sidebar — el menú que abre el wordmark de Punto.
 *
 * ── Por qué salió de `app-sidebar.tsx` ──────────────────────────────────
 * Vivía inline como un ternario `outlets.length > 1 ? <DropdownMenu/> :
 * <Link/>`, y esa bifurcación tenía dos problemas que no se arreglan desde el
 * call-site:
 *
 *   1. **El layout se movía.** La rama con menú usaba `w-full` (empujando el
 *      botón de colapsar contra el borde derecho) y la rama sin menú no, así
 *      que un tenant sin sucursales veía el botón de colapsar corrido a la
 *      izquierda. Contra la regla de posiciones estables (context/14 §10): el
 *      mismo control no puede estar en dos lados según el estado de los datos.
 *   2. **La entrada de alta desaparecía.** Cualquier cosa que se agregue al
 *      menú sólo existe con ≥2 sucursales — justo al revés de lo que hace
 *      falta, porque el que más necesita "Crear sucursal" es el que tiene una.
 *
 * Ahora el menú se monta SIEMPRE, con 0, 1 o N sucursales, y el trigger es
 * siempre el mismo botón. El componente es también el dueño del diálogo de
 * alta: el `<Dialog>` va como HERMANO del `<DropdownMenu>`, no adentro, porque
 * el menú desmonta su contenido al cerrarse y se llevaría puesto el diálogo.
 *
 * El alta con paywall es exclusiva del panel del comercio; bajo `scope="Admin"`
 * el menú es solo selector.
 */

import * as React from "react"
import { Check, ChevronsUpDown, Plus } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { OutletRequestDialog } from "@/components/outlets/outlet-request-dialog"
import { usePermission } from "@/hooks/use-permissions"
import { useOutletRequestStatus } from "@/hooks/use-outlet-request"
import { formatDate } from "@/lib/format-date"

/** Clave que gatea sucursales en todo el producto (`/v1/outlets.php`). */
const OUTLET_PERMISSION = "settings.outlet.manage"

export interface OutletSwitcherProps {
  scope: "Admin" | "Panel"
  outlets: Array<{ id: string; name: string }>
  activeOutletId: string
  onSelectOutlet?: (outletId: string) => void
  isSwitchingOutlet?: boolean
  viewScope?: string | "all" | null
  onSelectAllOutlets?: () => void
}

export function OutletSwitcher({
  scope,
  outlets,
  activeOutletId,
  onSelectOutlet,
  isSwitchingOutlet = false,
  viewScope = null,
  onSelectAllOutlets,
}: OutletSwitcherProps) {
  const isPanel = scope === "Panel"
  const canManageOutlets = usePermission(OUTLET_PERMISSION)
  const canRequest = isPanel && canManageOutlets

  const { data: requestStatus } = useOutletRequestStatus(canRequest)
  const pending = requestStatus?.pending ?? null

  const [dialogOpen, setDialogOpen] = React.useState(false)

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button
            type="button"
            aria-label="Sucursales"
            className="flex w-full items-center gap-2 rounded-md p-2 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground cursor-pointer group-data-[collapsible=icon]:hidden"
          >
            <PuntoLogo variant="wordmark" />
            {scope === "Admin" && (
              <Badge variant="outline" className="text-[10px] font-medium">
                ADMIN
              </Badge>
            )}
            <ChevronsUpDown className="ml-auto size-4 text-muted-foreground" />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="w-auto max-w-sm">
          <DropdownMenuLabel className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            Sucursales
          </DropdownMenuLabel>

          {/* "Todas" solo tiene sentido cuando hay más de una que agregar. */}
          {onSelectAllOutlets && outlets.length > 1 && (
            <>
              <DropdownMenuItem
                disabled={isSwitchingOutlet}
                onSelect={(e) => {
                  if (viewScope === "all") {
                    e.preventDefault()
                    return
                  }
                  onSelectAllOutlets()
                }}
              >
                <span className="flex-1 truncate">Todas</span>
                {viewScope === "all" && <Check className="size-4 opacity-70" />}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
            </>
          )}

          {outlets.length === 0 ? (
            <DropdownMenuItem disabled>
              <span className="flex-1 truncate">Todavía no tenés sucursales</span>
            </DropdownMenuItem>
          ) : (
            outlets.map((o) => {
              const isChecked =
                viewScope === "all"
                  ? false
                  : viewScope
                    ? o.id === viewScope
                    : o.id === activeOutletId
              return (
                <DropdownMenuItem
                  key={o.id}
                  disabled={isSwitchingOutlet}
                  onSelect={(e) => {
                    // Con una sola sucursal el item es informativo: seguir
                    // ofreciendo "cambiar" a la que ya está activa es ruido.
                    if (isChecked || outlets.length === 1) {
                      e.preventDefault()
                      return
                    }
                    onSelectOutlet?.(o.id)
                  }}
                >
                  <span className="flex-1 truncate">{o.name}</span>
                  {isChecked && <Check className="size-4 opacity-70" />}
                </DropdownMenuItem>
              )
            })
          )}

          {canRequest && (
            <>
              <DropdownMenuSeparator />
              {pending ? (
                // Impedimento = control DESHABILITADO con el motivo, no una
                // banda de aviso (memoria `feedback_pos_alerts_on_the_action`).
                // Es estado, no acción: no hay nada que apretar hasta que
                // Punto resuelva.
                <DropdownMenuItem disabled>
                  <span className="flex-1 truncate">
                    Solicitud pendiente
                    {pending.createdAt ? ` — ${formatDate(pending.createdAt)}` : ""}
                  </span>
                </DropdownMenuItem>
              ) : (
                <DropdownMenuItem onSelect={() => setDialogOpen(true)}>
                  <Plus className="size-4" />
                  <span className="flex-1 truncate">Crear sucursal</span>
                </DropdownMenuItem>
              )}
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>

      {canRequest && (
        <OutletRequestDialog open={dialogOpen} onOpenChange={setDialogOpen} />
      )}
    </>
  )
}
