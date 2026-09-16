import type { Metadata } from "next"

import { DocsHeader } from "@/components/docs/docs-header"
import { DocsNav } from "@/components/docs/docs-nav"
import { buildSearchIndex, getBlocks } from "@/lib/docs/content"
import { DOCS_URL } from "@/lib/site/links"

/**
 * Sitio de ayuda (`docs.punto.la`). El middleware reescribe todo ese host bajo
 * este segmento; desde los hosts del panel `/ayuda` redirige al host de ayuda.
 *
 * Layout propio y público: no pasa por el layout del panel (sesión,
 * bootstrap, sidebar). Del root solo hereda fuentes, tema y providers
 * genéricos, igual que el sitio de marketing.
 */
export const metadata: Metadata = {
  metadataBase: new URL(DOCS_URL),
  title: {
    default: "Ayuda de Punto",
    template: "%s | Ayuda de Punto",
  },
  description: "Guías paso a paso para usar Punto en tu comercio.",
  alternates: { canonical: "/" },
  robots: { index: true, follow: true },
}

export default function DocsLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const blocks = getBlocks().map((block) => ({
    key: block.key,
    title: block.title,
    articles: block.articles.map((a) => ({ slug: a.slug, title: a.title })),
  }))

  return (
    <div className="min-h-svh bg-background text-foreground">
      <DocsHeader blocks={blocks} searchEntries={buildSearchIndex()} />
      <div className="mx-auto flex max-w-7xl gap-10 px-4 lg:px-6">
        <aside className="sticky top-14 hidden h-[calc(100svh-3.5rem)] w-64 shrink-0 overflow-y-auto py-8 lg:block">
          <DocsNav blocks={blocks} />
        </aside>
        <main className="min-w-0 flex-1 py-8 lg:py-10">{children}</main>
      </div>
    </div>
  )
}
