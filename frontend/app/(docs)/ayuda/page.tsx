import Link from "next/link"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { getBlocks } from "@/lib/docs/content"

export default function DocsHomePage() {
  const blocks = getBlocks()

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Ayuda de Punto</h1>
        <p className="text-sm text-muted-foreground">Guías paso a paso para usar Punto en tu comercio.</p>
      </header>

      <div className="grid gap-4 md:grid-cols-2">
        {blocks.map((block) => (
          <Card key={block.key}>
            <CardHeader>
              <CardTitle>
                <h2 className="text-base font-semibold tracking-tight">{block.title}</h2>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="flex flex-col gap-2">
                {block.articles.map((article) => (
                  <li key={article.slug}>
                    <Link href={`/${article.slug}`} className="group flex flex-col">
                      <span className="text-sm font-medium group-hover:underline group-hover:underline-offset-4">
                        {article.title}
                      </span>
                      <span className="text-sm text-muted-foreground">{article.resumen}</span>
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
