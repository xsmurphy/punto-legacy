"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { ChevronRight } from "lucide-react"

import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible"
import { cn } from "@/lib/utils"

export interface DocsNavBlock {
  key: string
  title: string
  articles: Array<{ slug: string; title: string }>
}

/** Secciones abiertas por el lector. Se guarda por navegador, no es dato del sitio. */
const STORAGE_KEY = "punto.docs.nav.open"

function readOpenState(): Record<string, boolean> {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    const parsed = raw ? JSON.parse(raw) : null
    if (!parsed || typeof parsed !== "object") return {}
    return Object.fromEntries(
      Object.entries(parsed as Record<string, unknown>).map(([k, v]) => [k, Boolean(v)]),
    )
  } catch {
    return {}
  }
}

function writeOpenState(state: Record<string, boolean>) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
  } catch {
    // Sin almacenamiento disponible el menú sigue funcionando, solo no recuerda.
  }
}

/**
 * Slug del artículo abierto. En el host de ayuda el navegador ve `/<slug>`,
 * pero el render estático corre sobre la ruta interna `/ayuda/<slug>`: se toma
 * el último segmento para que servidor y cliente coincidan.
 */
function useCurrentSlug(): string {
  const pathname = usePathname() ?? ""
  const last = pathname.split("/").filter(Boolean).pop() ?? ""
  return last === "ayuda" ? "" : last
}

/**
 * Índice del sitio: secciones colapsables con sus artículos adentro — sidebar
 * en desktop y menú en mobile.
 *
 * La sección del artículo que se está leyendo siempre arranca abierta; el resto
 * queda como el lector las dejó la última vez. La preferencia guardada se lee
 * DESPUÉS del montaje: el primer render tiene que coincidir con el HTML
 * estático, que no sabe nada del navegador.
 */
export function DocsNav({
  blocks,
  onNavigate,
}: {
  blocks: DocsNavBlock[]
  onNavigate?: () => void
}) {
  const current = useCurrentSlug()
  const activeKey = React.useMemo(
    () => blocks.find((b) => b.articles.some((a) => a.slug === current))?.key ?? "",
    [blocks, current],
  )

  const [open, setOpen] = React.useState<Record<string, boolean>>(() =>
    activeKey ? { [activeKey]: true } : {},
  )

  React.useEffect(() => {
    setOpen((prev) => ({ ...readOpenState(), ...prev }))
  }, [])

  React.useEffect(() => {
    if (!activeKey) return
    setOpen((prev) => (prev[activeKey] ? prev : { ...prev, [activeKey]: true }))
  }, [activeKey])

  const toggle = (key: string, value: boolean) => {
    setOpen((prev) => {
      const next = { ...prev, [key]: value }
      writeOpenState(next)
      return next
    })
  }

  return (
    <nav className="flex flex-col gap-1">
      {blocks.map((block) => {
        const isOpen = open[block.key] ?? false
        return (
          <Collapsible
            key={block.key}
            open={isOpen}
            onOpenChange={(value) => toggle(block.key, value)}
          >
            <CollapsibleTrigger className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground">
              <ChevronRight
                className={cn("size-3.5 shrink-0 transition-transform", isOpen && "rotate-90")}
              />
              <span className="flex-1">{block.title}</span>
            </CollapsibleTrigger>

            <CollapsibleContent>
              <ul className="mt-0.5 mb-2 flex flex-col gap-0.5 border-l pl-3 ml-3.5">
                {block.articles.map((article) => {
                  const active = article.slug === current
                  return (
                    <li key={article.slug}>
                      <Link
                        href={`/${article.slug}`}
                        onClick={onNavigate}
                        aria-current={active ? "page" : undefined}
                        className={cn(
                          "block rounded-md px-2 py-1.5 text-sm transition-colors",
                          active
                            ? "bg-muted font-medium text-foreground"
                            : "text-muted-foreground hover:bg-muted/60 hover:text-foreground",
                        )}
                      >
                        {article.title}
                      </Link>
                    </li>
                  )
                })}
              </ul>
            </CollapsibleContent>
          </Collapsible>
        )
      })}
    </nav>
  )
}
