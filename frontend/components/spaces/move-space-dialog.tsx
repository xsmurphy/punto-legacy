"use client"

/**
 * Mover una mesa abierta a otro espacio LIBRE — los clientes se cambiaron de
 * lugar (owner 2026-08-23).
 *
 * La sesión es la misma: se mudan las personas, no la cuenta. Las órdenes (aun
 * las ya enviadas a cocina) y los pagos parciales van con ella sin tocarse,
 * porque cuelgan de la sesión y no del espacio — ver `SpaceSessionService::move()`.
 * Por eso este diálogo no advierte nada: no hay nada que se pierda.
 *
 * Lista de destinos = espacios en estado `free` del mismo outlet. Se compone
 * con `Command` (cmdk) igual que `SellerPickerDialog`: un salón grande tiene
 * decenas de mesas y buscar por número con el teclado es más rápido que
 * scrollear, que es como se opera una caja (§14 Regla #3: listado corto
 * embebido, sin DataTable).
 */

import * as React from "react"
import { ArrowRightLeft } from "lucide-react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
  ResponsiveDialogDescription,
} from "@/components/ui/responsive-dialog"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { EmptyState } from "@/components/empty-state"
import type { SpaceWithState } from "@/hooks/use-pos-spaces"

interface Props {
  /** Mesa que se mueve. null = diálogo cerrado. */
  table: SpaceWithState | null
  /** Todos los espacios del outlet — se filtran los libres acá adentro. */
  spaces: SpaceWithState[]
  onOpenChange: (open: boolean) => void
  onConfirm: (targetSpaceId: string) => void
  submitting: boolean
}

export function MoveSpaceDialog({ table, spaces, onOpenChange, onConfirm, submitting }: Props) {
  // `free` ya excluye deshabilitados y ocupados; los decorativos (barra,
  // pared, planta) nunca tienen estado libre operable, y el backend los
  // rechaza igual — el filtro de acá es UX, el invariante está allá.
  const targets = React.useMemo(
    () => spaces.filter((s) => s.state === "free" && s.id !== table?.id),
    [spaces, table],
  )

  return (
    <ResponsiveDialog open={table !== null} onOpenChange={(v) => !v && onOpenChange(false)}>
      <ResponsiveDialogContent className="sm:max-w-md">
        <ResponsiveDialogHeader>
          <ResponsiveDialogTitle>Mover {table?.name}</ResponsiveDialogTitle>
          <ResponsiveDialogDescription>
            Elegí el espacio libre al que se pasa. Las órdenes y los pagos ya
            registrados se mueven con la mesa.
          </ResponsiveDialogDescription>
        </ResponsiveDialogHeader>

        {targets.length === 0 ? (
          <EmptyState
            icon={ArrowRightLeft}
            title="No hay espacios libres"
            description="Liberá o cerrá otra mesa para poder mover esta."
            className="py-8"
          />
        ) : (
          <Command>
            <CommandInput placeholder="Buscar espacio..." />
            <CommandList>
              <CommandEmpty>Sin resultados.</CommandEmpty>
              <CommandGroup>
                {targets.map((s) => (
                  <CommandItem
                    key={s.id}
                    value={s.name}
                    disabled={submitting}
                    onSelect={() => onConfirm(s.id)}
                  >
                    {s.name}
                  </CommandItem>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        )}
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
