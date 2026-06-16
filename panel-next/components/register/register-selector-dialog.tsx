"use client"

/**
 * Selector de caja activa (Slice A7).
 *
 * Dialog NO dismissable que bloquea el POS hasta que el usuario elija una
 * caja. Se muestra cuando `activeRegisterId === ''` en el guard del layout.
 *
 * Casos:
 *   - registers.length > 1 → lista de botones grandes, uno por caja.
 *   - registers.length === 1 → auto-selección desde el guard (no se llega acá).
 *   - registers.length === 0 → mensaje de "no hay cajas configuradas".
 *
 * Cierre: onSuccess de la mutation el bootstrap se refetchea; cuando
 * activeRegisterId deja de estar vacío, el guard desmonta este dialog.
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Loader2 } from "lucide-react"
import { useCatalogStore } from "@/lib/catalog/store"
import { useSetActiveRegister } from "@/hooks/use-active-register"

export function RegisterSelectorDialog() {
  const registers = useCatalogStore((s) => s.registers)
  const { mutate, isPending, variables } = useSetActiveRegister()

  return (
    <Dialog open modal>
      {/* Sin DialogClose ni click-outside → no hay forma de cerrar sin elegir */}
      <DialogContent
        className="sm:max-w-md"
        // Deshabilitar el cierre con ESC y click-outside
        onEscapeKeyDown={(e) => e.preventDefault()}
        onPointerDownOutside={(e) => e.preventDefault()}
        onInteractOutside={(e) => e.preventDefault()}
        // Ocultar el botón de cierre (shadcn DialogContent usa showCloseButton)
        showCloseButton={false}
      >
        <DialogHeader>
          <DialogTitle className="text-xl">Seleccioná la caja</DialogTitle>
          <DialogDescription>
            Elegí la caja desde la que vas a operar en esta sesión.
          </DialogDescription>
        </DialogHeader>

        <div className="mt-4 flex flex-col gap-3">
          {registers.length === 0 ? (
            <p className="rounded-lg border border-border bg-muted/40 px-4 py-5 text-center text-sm text-muted-foreground">
              No hay cajas configuradas en esta sucursal.{" "}
              <span className="font-medium text-foreground">
                Configurá una en el panel.
              </span>
            </p>
          ) : (
            registers.map((reg) => {
              const isThis = variables === reg.id && isPending
              return (
                <Button
                  key={reg.id}
                  variant="outline"
                  size="lg"
                  className="h-auto justify-start gap-4 px-5 py-4 text-left"
                  disabled={isPending}
                  onClick={() => mutate(reg.id)}
                >
                  {isThis ? (
                    <Loader2 className="size-5 shrink-0 animate-spin text-muted-foreground" />
                  ) : (
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-sm font-semibold">
                      {reg.name.slice(0, 2).toUpperCase()}
                    </span>
                  )}
                  <span className="flex-1 truncate text-base font-medium">
                    {reg.name}
                  </span>
                </Button>
              )
            })
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
