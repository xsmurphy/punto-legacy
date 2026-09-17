import Link from "next/link"

import { DocsSearch } from "@/components/docs/docs-search"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { buildSearchIndex, getBlocks } from "@/lib/docs/content"

/**
 * Índice del sitio de ayuda: una tarjeta por sección con sus artículos, en el
 * mismo orden que el menú lateral. Las secciones y su orden salen de los
 * archivos de `content/ayuda`, no de una lista escrita acá.
 */
export default function DocsHomePage() {
  const blocks = getBlocks()

  return (
    <div className="flex flex-col gap-10">
      <header className="flex flex-col items-center gap-4 py-6 text-center">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Ayuda de Punto</h1>
          <p className="text-sm text-muted-foreground">
            Guías paso a paso para usar Punto en tu comercio.
          </p>
        </div>
        <DocsSearch entries={buildSearchIndex()} size="lg" className="w-full max-w-md" />
      </header>

      <div className="grid gap-4 md:grid-cols-2">
        {blocks.map((block) => (
          <Card key={block.key}>
            <CardHeader>
              <CardTitle>
                <h2 className="text-base font-semibold tracking-tight">{block.title}</h2>
              </CardTitle>
              <CardDescription>{block.description}</CardDescription>
            </CardHeader>
            <CardContent>
              <ul className="flex flex-col gap-0.5">
                {block.articles.map((article) => (
                  <li key={article.slug}>
                    <Link
                      href={`/${article.slug}`}
                      className="-mx-2 block rounded-md px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground"
                    >
                      {article.title}
                    </Link>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}
