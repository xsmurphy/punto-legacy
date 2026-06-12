"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import {
  Command,
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import type { NavEntry } from "@/components/layout/app-sidebar"

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Items del menú lateral para mostrar como atajos de navegación. */
  nav: NavEntry[]
}

/**
 * Command palette del panel — abre con ⌘K o click en el "Buscar…" del sidebar.
 * Por ahora lista solo las rutas del nav; queda preparado para sumar acciones,
 * búsquedas de items/clientes, etc. en próximas iteraciones.
 */
export function AppCommandPalette({ open, onOpenChange, nav }: Props) {
  const router = useRouter()

  const go = React.useCallback(
    (path: string) => {
      onOpenChange(false)
      router.push(path)
    },
    [router, onOpenChange],
  )

  // Aplanar el nav (algunos entries pueden ser grupos con items).
  const flat = React.useMemo(() => {
    type Flat = {
      title: string
      to: string
      icon: React.ComponentType<{ className?: string }>
    }
    const result: Flat[] = []
    nav.forEach((entry) => {
      if ("items" in entry && Array.isArray(entry.items)) {
        entry.items.forEach((c) =>
          result.push({ title: `${entry.title} · ${c.title}`, to: c.to, icon: c.icon }),
        )
      } else if ("to" in entry) {
        result.push({ title: entry.title, to: entry.to, icon: entry.icon })
      }
    })
    return result
  }, [nav])

  return (
    <CommandDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Buscar"
      description="Buscar por nombre o ir a una sección."
    >
      {/* cmdk requiere el <Command> root como contexto para CommandInput/
          List/Item. El CommandDialog del preset shadcn nuevo NO lo envuelve
          automáticamente — hay que pasarlo explícito acá. */}
      <Command>
        <CommandInput placeholder="Buscar sección o acción…" />
        <CommandList>
          <CommandEmpty>Sin resultados.</CommandEmpty>
          <CommandGroup heading="Navegación">
            {flat.map((it) => {
              const Icon = it.icon
              return (
                <CommandItem
                  key={it.to}
                  value={it.title}
                  onSelect={() => go(it.to)}
                >
                  {Icon && <Icon className="size-4" />}
                  <span>{it.title}</span>
                </CommandItem>
              )
            })}
          </CommandGroup>
        </CommandList>
      </Command>
    </CommandDialog>
  )
}
