"use client"

/**
 * Unir la cuenta de esta mesa con la de OTRA mesa abierta (owner 2026-08-23).
 *
 * Direccionalidad: la mesa desde la que se abre este diálogo es el ORIGEN — se
 * absorbe en la que se elija y su espacio queda libre. El copy lo dice en cada
 * fila ("Mesa 3 se une a Mesa 7") porque la operación NO es simétrica y
 * equivocarse deja los clientes sentados en una mesa que el sistema ve libre.
 *
 * A diferencia de mover, unir sí puede fallar por reglas del dominio (mesas de
 * distinta sucursal, familias de cobro parcial incompatibles — ver
 * `SpaceSessionService::merge()`). Este diálogo no las replica: las valida el
 * backend y el error llega como toast. Duplicar la regla acá la desincronizaría
 * en cuanto una de las dos cambie.
 *
 * `AlertDialog` de confirmación encima: unir mueve pedidos ya en cocina y pagos
 * ya cobrados a otra cuenta, y no hay un "deshacer".
 */

import * as React from "react"
import { Merge } from "lucide-react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
  ResponsiveDialogDescription,
} from "@/components/ui/responsive-dialog"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
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
  /** Mesa ORIGEN — la que se absorbe. null = diálogo cerrado. */
  table: SpaceWithState | null
  spaces: SpaceWithState[]
  onOpenChange: (open: boolean) => void
  onConfirm: (targetSessionId: string) => void
  submitting: boolean
}

export function MergeSpaceDialog({ table, spaces, onOpenChange, onConfirm, submitting }: Props) {
  const [pending, setPending] = React.useState<SpaceWithState | null>(null)

  // Destinos: cualquier otra mesa con sesión activa. `bill_requested` entra a
  // propósito — unirle una mesa revierte el pedido de cuenta a `open`
  // server-side (el total cambió), igual que agregar una orden.
  const targets = React.useMemo(
    () =>
      spaces.filter(
        (s) =>
          s.id !== table?.id &&
          s.session !== null &&
          (s.state === "occupied" || s.state === "bill_requested"),
      ),
    [spaces, table],
  )

  React.useEffect(() => {
    if (!table) setPending(null)
  }, [table])

  return (
    <>
      <ResponsiveDialog open={table !== null} onOpenChange={(v) => !v && onOpenChange(false)}>
        <ResponsiveDialogContent className="sm:max-w-md">
          <ResponsiveDialogHeader>
            <ResponsiveDialogTitle>Unir {table?.name} con otra mesa</ResponsiveDialogTitle>
            <ResponsiveDialogDescription>
              {table?.name} se une a la mesa que elijas y queda libre. La cuenta
              sigue en la otra.
            </ResponsiveDialogDescription>
          </ResponsiveDialogHeader>

          {targets.length === 0 ? (
            <EmptyState
              icon={Merge}
              title="No hay otra mesa abierta"
              description="Para unir cuentas hacen falta dos mesas ocupadas."
              className="py-8"
            />
          ) : (
            <Command>
              <CommandInput placeholder="Buscar mesa..." />
              <CommandList>
                <CommandEmpty>Sin resultados.</CommandEmpty>
                <CommandGroup>
                  {targets.map((s) => (
                    <CommandItem
                      key={s.id}
                      value={`${s.name} ${s.session?.alias ?? ""}`}
                      disabled={submitting}
                      onSelect={() => setPending(s)}
                    >
                      <div className="flex min-w-0 flex-col">
                        <span className="truncate">{s.name}</span>
                        {s.session?.alias && (
                          <span className="truncate text-sm text-muted-foreground">
                            {s.session.alias}
                          </span>
                        )}
                      </div>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          )}
        </ResponsiveDialogContent>
      </ResponsiveDialog>

      <AlertDialog open={pending !== null} onOpenChange={(v) => !v && setPending(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              ¿Unir {table?.name} a {pending?.name}?
            </AlertDialogTitle>
            <AlertDialogDescription>
              Las órdenes y los pagos ya registrados de {table?.name} pasan a la
              cuenta de {pending?.name}, y {table?.name} queda libre. No se puede
              deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Volver</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                const target = pending
                setPending(null)
                if (target?.session) onConfirm(target.session.id)
              }}
            >
              Unir mesas
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
