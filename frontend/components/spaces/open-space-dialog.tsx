"use client"

/**
 * Dialog rápido para abrir un espacio libre (context/15-espacios-module-plan.md
 * F2). Los TRES campos son opcionales — el owner lo pidió explícitamente así
 * para comensales y vale igual para el resto: abrir un espacio tiene que ser un
 * tap, y todo lo demás se puede completar después desde el diálogo del espacio.
 *
 * ── Mozo ────────────────────────────────────────────────────────────────────
 * El backend soporta `waiterId` desde la mig 80 pero ningún componente lo
 * seteaba, así que la columna estaba siempre en NULL. Además de atribuir la
 * espacio, asignar el mozo ACTIVA LA EXCLUSIVIDAD: un espacio con mozo solo la
 * opera él (o quien tenga `pos.space.override`). Por eso el copy del campo lo
 * dice — que un desplegable opcional cambie quién puede tocar el espacio no puede
 * ser un efecto secundario invisible.
 *
 * `Select` y no el `SellerPickerDialog` del POS: ese es un Dialog con búsqueda,
 * y anidar un Dialog dentro de otro para elegir entre los pocos mozos de un
 * turno es más pesado de operar (dos capas de modal en una tablet) que un
 * desplegable.
 *
 * ── Alias ───────────────────────────────────────────────────────────────────
 * Nombre libre de la OCUPACIÓN ("los del cumpleaños"), no del espacio. Es
 * efímero: muere cuando el espacio se cierra. Ver mig 163.
 */

import * as React from "react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
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

/** Valor centinela del Select — Radix no admite `value=""` en un SelectItem. */
const NO_WAITER = "__none__"

export interface OpenSpaceValues {
  guests: number | undefined
  waiterId: string | undefined
  alias: string | undefined
}

interface Props {
  table: SpaceWithState | null
  onOpenChange: (open: boolean) => void
  onConfirm: (values: OpenSpaceValues) => void
  submitting: boolean
}

export function OpenSpaceDialog({ table, onOpenChange, onConfirm, submitting }: Props) {
  const users = useCatalogStore((s) => s.users)
  const [guests, setGuests] = React.useState("")
  const [alias, setAlias] = React.useState("")
  const [waiterId, setWaiterId] = React.useState<string>(NO_WAITER)

  React.useEffect(() => {
    if (table) {
      setGuests("")
      setAlias("")
      setWaiterId(NO_WAITER)
    }
  }, [table])

  const confirm = () =>
    onConfirm({
      guests: guests.trim() === "" ? undefined : Number(guests),
      waiterId: waiterId === NO_WAITER ? undefined : waiterId,
      alias: alias.trim() === "" ? undefined : alias.trim(),
    })

  return (
    <ResponsiveDialog open={table !== null} onOpenChange={(v) => !v && onOpenChange(false)}>
      <ResponsiveDialogContent className="sm:max-w-md">
        <ResponsiveDialogHeader>
          <ResponsiveDialogTitle>Abrir {table?.name}</ResponsiveDialogTitle>
        </ResponsiveDialogHeader>

        <div className="grid gap-4 py-2">
          <div className="grid gap-2">
            <Label htmlFor="space-guests">Comensales (opcional)</Label>
            <Input
              id="space-guests"
              type="number"
              inputMode="numeric"
              min={1}
              placeholder="Cantidad de personas"
              value={guests}
              onChange={(e) => setGuests(e.target.value)}
              autoFocus
            />
          </div>

          <div className="grid gap-2">
            <Label htmlFor="space-alias">Nombre del espacio (opcional)</Label>
            <Input
              id="space-alias"
              placeholder="Los del cumpleaños"
              maxLength={60}
              value={alias}
              onChange={(e) => setAlias(e.target.value)}
            />
            <p className="text-sm text-muted-foreground">
              Para reconocerlo de un vistazo. Se borra al cerrar el espacio.
            </p>
          </div>

          <div className="grid gap-2">
            <Label htmlFor="space-waiter">Mozo (opcional)</Label>
            <Select value={waiterId} onValueChange={setWaiterId}>
              <SelectTrigger id="space-waiter">
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
            {submitting ? "Abriendo..." : "Abrir espacio"}
          </Button>
        </ResponsiveDialogFooter>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
