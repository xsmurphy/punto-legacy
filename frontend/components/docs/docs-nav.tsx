"use client"

import Link from "next/link"
import { usePathname } from "next/navigation"

import { cn } from "@/lib/utils"

export interface DocsNavBlock {
  key: string
  title: string
  articles: Array<{ slug: string; title: string }>
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

/** Índice de artículos agrupados por bloque — sidebar en desktop y menú en mobile. */
export function DocsNav({
  blocks,
  onNavigate,
}: {
  blocks: DocsNavBlock[]
  onNavigate?: () => void
}) {
  const current = useCurrentSlug()

  return (
    <nav className="flex flex-col gap-6">
      {blocks.map((block) => (
        <div key={block.key}>
          <p className="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            {block.title}
          </p>
          <ul className="flex flex-col gap-0.5">
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
        </div>
      ))}
    </nav>
  )
}
