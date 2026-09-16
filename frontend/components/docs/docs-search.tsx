"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { FileText, Search } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Command,
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import type { DocSearchEntry } from "@/lib/docs/content"
import { paletteScore } from "@/lib/navigation/search"
import { cn } from "@/lib/utils"

/** ¿El foco está en un campo de texto? Ahí `/` se escribe, no abre el buscador. */
function isTyping(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  return target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName)
}

/**
 * Buscador del sitio de ayuda. El índice se arma en build (título, keywords,
 * resumen y subtítulos de cada artículo) y se filtra acá con el mismo ranking
 * del buscador del panel (`paletteScore`): cada palabra tiene que aparecer, y
 * el título pesa más que el resto.
 */
export function DocsSearch({
  entries,
  className,
}: {
  entries: DocSearchEntry[]
  className?: string
}) {
  const router = useRouter()
  const [open, setOpen] = React.useState(false)

  React.useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const commandK = event.key.toLowerCase() === "k" && (event.metaKey || event.ctrlKey)
      const slash = event.key === "/" && !isTyping(event.target)
      if (commandK || slash) {
        event.preventDefault()
        setOpen((value) => (commandK ? !value : true))
      }
    }
    document.addEventListener("keydown", onKeyDown)
    return () => document.removeEventListener("keydown", onKeyDown)
  }, [])

  const bySlug = React.useMemo(() => new Map(entries.map((e) => [e.slug, e])), [entries])

  const filter = React.useCallback(
    (value: string, search: string) => {
      const entry = bySlug.get(value)
      return entry ? paletteScore(entry, search) : 0
    },
    [bySlug],
  )

  const groups = React.useMemo(() => {
    const map = new Map<string, DocSearchEntry[]>()
    for (const entry of entries) {
      map.set(entry.block, [...(map.get(entry.block) ?? []), entry])
    }
    return Array.from(map)
  }, [entries])

  return (
    <>
      <Button
        variant="outline"
        onClick={() => setOpen(true)}
        className={cn("justify-start text-muted-foreground", className)}
      >
        <Search />
        <span className="flex-1 text-left">Buscar</span>
        <kbd className="hidden rounded border bg-muted px-1.5 font-mono text-xs sm:inline">⌘K</kbd>
      </Button>

      <CommandDialog open={open} onOpenChange={setOpen} title="Buscar" description="Buscar en la ayuda">
        <Command filter={filter}>
          <CommandInput placeholder="Buscar en la ayuda" />
          <CommandList>
            <CommandEmpty>Sin resultados.</CommandEmpty>
            {groups.map(([block, items]) => (
              <CommandGroup key={block} heading={block}>
                {items.map((entry) => (
                  <CommandItem
                    key={entry.slug}
                    value={entry.slug}
                    onSelect={() => {
                      setOpen(false)
                      router.push(`/${entry.slug}`)
                    }}
                  >
                    <FileText className="size-4" />
                    <div className="flex min-w-0 flex-col">
                      <span className="truncate">{entry.title}</span>
                      <span className="truncate text-xs text-muted-foreground">{entry.resumen}</span>
                    </div>
                  </CommandItem>
                ))}
              </CommandGroup>
            ))}
          </CommandList>
        </Command>
      </CommandDialog>
    </>
  )
}
