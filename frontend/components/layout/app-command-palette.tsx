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
  CommandSeparator,
} from "@/components/ui/command"
import type { NavEntry, PaletteSection } from "@/lib/navigation/types"

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Items del menú lateral. Fallback cuando no hay `sections`. */
  nav: NavEntry[]
  /**
   * Índice completo del palette, derivado del registro de rutas
   * (`lib/navigation/routes.ts`) y ya filtrado por permisos.
   */
  sections?: PaletteSection[]
}

/**
 * Command palette del panel — abre con ⌘K o click en el "Buscar…" del sidebar.
 *
 * Este componente NO mantiene su propia lista de rutas. Antes tenía un
 * `EXTRA_ROUTES` hardcodeado, paralelo al menú del sidebar y sin relación con
 * las páginas que realmente existen: faltaban 35, y las que estaban se
 * renderizaban sin mirar permisos (un usuario sin `reports.expenses.view`
 * encontraba "Movimientos de caja" y se comía un 403). Ahora el índice llega
 * armado y filtrado desde `lib/navigation/build.ts`.
 *
 * El palette sigue siendo el catch-all: cualquier ruta debe ser alcanzable
 * desde acá aunque NO esté en el sidebar (que se mantiene minimalista).
 */
export function AppCommandPalette({ open, onOpenChange, nav, sections }: Props) {
  const router = useRouter()

  const go = React.useCallback(
    (path: string) => {
      onOpenChange(false)
      router.push(path)
    },
    [router, onOpenChange],
  )

  // Fallback para realms que no tienen registro propio (ej. `/admin`): se
  // aplana el nav recibido y se ofrece como única sección.
  const fallback = React.useMemo<PaletteSection[]>(() => {
    const items: PaletteSection["items"] = []
    nav.forEach((entry) => {
      if ("items" in entry && Array.isArray(entry.items)) {
        entry.items.forEach((c) => {
          const title = `${entry.title} · ${c.title}`
          items.push({ title, to: c.to, icon: c.icon, searchValue: title })
        })
      } else if ("to" in entry && typeof entry.to === "string") {
        items.push({
          title: entry.title,
          to: entry.to,
          icon: entry.icon,
          searchValue: entry.title,
        })
      }
    })
    return items.length > 0 ? [{ heading: "Navegación", items }] : []
  }, [nav])

  const rendered = sections ?? fallback

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
          {rendered.map((section, i) => (
            <React.Fragment key={section.heading}>
              {i > 0 && <CommandSeparator />}
              <CommandGroup heading={section.heading}>
                {section.items.map((it) => {
                  const Icon = it.icon
                  return (
                    <CommandItem
                      key={`${it.to}|${it.title}`}
                      // cmdk filtra por `value`: se le pasan título +
                      // keywords para que encuentre el item por sinónimos.
                      value={it.searchValue}
                      onSelect={() => go(it.to)}
                    >
                      {Icon && <Icon className="size-4" />}
                      <span>{it.title}</span>
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            </React.Fragment>
          ))}
        </CommandList>
      </Command>
    </CommandDialog>
  )
}
