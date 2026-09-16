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
import { paletteScore } from "@/lib/navigation/search"
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
/** Identidad de un item dentro del palette. Única aunque dos títulos coincidan. */
function itemValue(section: PaletteSection, item: PaletteSection["items"][number]): string {
  return `${section.heading}|${item.to}|${item.title}`
}

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
          items.push({ title: `${entry.title} · ${c.title}`, to: c.to, icon: c.icon, keywords: [] })
        })
      } else if ("to" in entry && typeof entry.to === "string") {
        items.push({ title: entry.title, to: entry.to, icon: entry.icon, keywords: [] })
      }
    })
    return items.length > 0 ? [{ heading: "Navegación", items }] : []
  }, [nav])

  const rendered = sections ?? fallback

  /**
   * cmdk identifica cada item por su `value` y es lo único que le pasa al
   * filtro. El título no alcanza como identificador (dos secciones pueden
   * llamarse igual en grupos distintos), así que el `value` es la ruta y acá
   * se resuelve de vuelta a la entrada para puntuarla con título + sinónimos.
   */
  const byValue = React.useMemo(() => {
    const map = new Map<string, PaletteSection["items"][number]>()
    rendered.forEach((section) => {
      section.items.forEach((it) => map.set(itemValue(section, it), it))
    })
    return map
  }, [rendered])

  const filter = React.useCallback(
    (value: string, search: string) => {
      const item = byValue.get(value)
      if (!item) return 0
      return paletteScore(item, search)
    },
    [byValue],
  )

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
      <Command filter={filter}>
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
                      key={itemValue(section, it)}
                      value={itemValue(section, it)}
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
