"use client"

/**
 * Edita la ocupación EN CURSO: nombre libre, comensales y mozo asignado
 * (mig 163 + exclusividad, owner 2026-08-23).
 *
 * Es el mismo formulario que `OpenSpaceDialog` pero sobre un espacio ya abierto,
 * y aun así son dos componentes: acá los campos arrancan con el valor actual y
 * el contrato con el backend es por PRESENCIA (mandar `alias: ""` BORRA el
 * alias — no es lo mismo que no mandarlo). Fusionarlos obligaría a un
 * `mode: "open" | "edit"` con ramas en cada campo y en el submit, que es más
 * enredo que las pocas líneas que comparten.
 *
 * Reasignar el mozo es el pase de turno normal: el dueño del espacio se lo pasa
 * a un compañero. Quien NO es el dueño no llega hasta acá — el backend lo
 * rechaza con 403 (`SpaceOwnershipGuard`), no solo la UI.
 *
 * ── DOS entradas, UN formulario ─────────────────────────────────────────────
 *
 * El menú de acciones del tile (`space-actions-menu.tsx`) ofrece "Etiquetar" y
 * "Asignar Usuario" como ítems separados, y los dos abren ESTE diálogo con los
 * tres campos visibles: la diferencia es solo `focusField`, que decide en cuál
 * arranca el cursor. No se parte en dos diálogos a propósito — son tres datos
 * de la misma ocupación que se guardan en un solo PATCH, y separarlos obligaría
 * a dos requests y a decidir qué pasa si la segunda falla. El nombre del ítem
 * dice a qué venías; el form te deja corregir lo de al lado si ya estás ahí.
 */

import * as React from "react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
  ResponsiveDialogDescription,
  ResponsiveDialogFooter,
} from "@/components/ui/responsive-dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useCatalogStore } from "@/lib/catalog/store"
import type { SpaceWithState } from "@/hooks/use-pos-spaces"

const NO_WAITER = "__none__"

export interface EditSessionValues {
  alias: string | null
  guests: number | null
  waiterId: string | null
}

interface Props {
  table: SpaceWithState | null
  onOpenChange: (open: boolean) => void
  onConfirm: (values: EditSessionValues) => void
  submitting: boolean
  /**
   * Campo en el que arranca el cursor. Único efecto: mover el `autoFocus`. El
   * formulario muestra siempre los tres campos (ver docblock). Default:
   * `"alias"`, que es como se abría antes de que existiera esta prop.
   */
  focusField?: "alias" | "waiterId"
}

export function EditSpaceSessionDialog({
  table,
  onOpenChange,
  onConfirm,
  submitting,
  focusField = "alias",
}: Props) {
  const users = useCatalogStore((s) => s.users)
  const session = table?.session ?? null

  const [alias, setAlias] = React.useState("")
  const [guests, setGuests] = React.useState("")
  const [waiterId, setWaiterId] = React.useState<string>(NO_WAITER)

  // Re-hidratar cada vez que se abre sobre un espacio: si el diálogo conservara
  // el estado del último uso, editar el espacio 3 después del 7 mostraría los
  // datos de la 7 y un submit distraído se los copiaría encima.
  React.useEffect(() => {
    if (!session) return
    setAlias(session.alias ?? "")
    setGuests(session.guests !== null ? String(session.guests) : "")
    setWaiterId(session.waiterId ?? NO_WAITER)
  }, [session])

  const confirm = () =>
    onConfirm({
      alias: alias.trim() === "" ? null : alias.trim(),
      guests: guests.trim() === "" ? null : Number(guests),
      waiterId: waiterId === NO_WAITER ? null : waiterId,
    })

  return (
    <ResponsiveDialog open={table !== null} onOpenChange={(v) => !v && onOpenChange(false)}>
      <ResponsiveDialogContent className="sm:max-w-md">
        <ResponsiveDialogHeader>
          <ResponsiveDialogTitle>Editar {table?.name}</ResponsiveDialogTitle>
          <ResponsiveDialogDescription>
            Estos datos valen mientras el espacio esté abierto.
          </ResponsiveDialogDescription>
        </ResponsiveDialogHeader>

        <div className="grid gap-4 py-2">
          <div className="grid gap-2">
            <Label htmlFor="edit-alias">Nombre del espacio</Label>
            <Input
              id="edit-alias"
              placeholder="Los del cumpleaños"
              maxLength={60}
              value={alias}
              onChange={(e) => setAlias(e.target.value)}
              autoFocus={focusField === "alias"}
            />
          </div>

          <div className="grid gap-2">
            <Label htmlFor="edit-guests">Comensales</Label>
            <Input
              id="edit-guests"
              type="number"
              inputMode="numeric"
              min={0}
              placeholder="Cantidad de personas"
              value={guests}
              onChange={(e) => setGuests(e.target.value)}
            />
          </div>

          <div className="grid gap-2">
            <Label htmlFor="edit-waiter">Mozo</Label>
            <Select value={waiterId} onValueChange={setWaiterId}>
              <SelectTrigger id="edit-waiter" autoFocus={focusField === "waiterId"}>
                <SelectValue placeholder="Sin asignar" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NO_WAITER}>Sin asignar</SelectItem>
                {users.map((u) => (
                  <SelectItem key={u.id} value={u.id}>
                    {u.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">
              {waiterId === NO_WAITER
                ? "Sin mozo asignado, cualquiera puede operar el espacio."
                : "Solo ese mozo va a poder operar el espacio (un encargado puede intervenir)."}
            </p>
          </div>
        </div>

        <ResponsiveDialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            Cancelar
          </Button>
          <Button onClick={confirm} disabled={submitting}>
            {submitting ? "Guardando..." : "Guardar"}
          </Button>
        </ResponsiveDialogFooter>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
