"use client"

/**
 * Selector de usuario reusable del POS: vendedor de una línea, vendedor de la
 * venta y repartidor de una orden (los tres call-sites pasan su propio
 * `title`).
 *
 * Es una lista BUSCABLE, así que se compone con `Command` (cmdk) dentro de un
 * `Dialog`, que es el primitive de shadcn para exactamente este caso. Antes era
 * un `Input` con una lupa absolute + filtrado a mano + un `Button variant=ghost`
 * por fila + un `<span>` con `backgroundColor: "#6b7280"` inline como avatar:
 * cuatro desvíos del design system (hex hardcodeado, primitive equivocado,
 * `DialogTitle` con el tamaño pisado a `text-2xl`) que se veían como una
 * pantalla ajena al resto del POS — reporte del owner 2026-08-21.
 *
 * Con `Command` el filtrado lo hace cmdk (se va el `useState` de búsqueda) y
 * se gana navegación por teclado: flechas + Enter para elegir sin tocar la
 * pantalla, que es como se opera una caja de alto volumen.
 */

import * as React from "react"
import { Check } from "lucide-react"

import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "@/components/ui/command"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { useCatalogStore } from "@/lib/catalog/store"

interface SellerPickerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSelect: (userId: string | null) => void
  currentUserId?: string
  /** Título del diálogo — default "Asignar usuario". Ej.: "Asignar repartidor" (courier picker, F-D-1). */
  title?: string
}

export function SellerPickerDialog({
  open,
  onOpenChange,
  onSelect,
  currentUserId,
  title = "Asignar usuario",
}: SellerPickerDialogProps) {
  const users = useCatalogStore((s) => s.users)

  const choose = (userId: string | null) => {
    onSelect(userId)
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="overflow-hidden p-0 sm:max-w-md">
        <DialogHeader className="px-4 pt-4">
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        {/* `value` incluye el id: cmdk exige valores únicos y dos usuarios
            pueden llamarse igual. El id es un uuid, así que no interfiere con
            lo que el operador tipea al buscar por nombre. */}
        <Command className="bg-transparent">
          <CommandInput placeholder="Buscar..." autoFocus />
          <CommandList className="max-h-72">
            <CommandEmpty>Sin resultados.</CommandEmpty>
            <CommandGroup>
              {users.map((u) => (
                <CommandItem
                  key={u.id}
                  value={`${u.name} ${u.id}`}
                  onSelect={() => choose(u.id)}
                  className="gap-3 py-2"
                >
                  <Avatar className="size-7">
                    <AvatarFallback className="text-xs">
                      {u.name.charAt(0).toUpperCase()}
                    </AvatarFallback>
                  </Avatar>
                  <span className="truncate">{u.name}</span>
                  {currentUserId === u.id && <Check className="ml-auto" />}
                </CommandItem>
              ))}
            </CommandGroup>
            {currentUserId && (
              <>
                <CommandSeparator />
                <CommandGroup>
                  <CommandItem
                    value="quitar asignación"
                    onSelect={() => choose(null)}
                    className="py-2 text-muted-foreground"
                  >
                    Quitar asignación
                  </CommandItem>
                </CommandGroup>
              </>
            )}
          </CommandList>
        </Command>
      </DialogContent>
    </Dialog>
  )
}
