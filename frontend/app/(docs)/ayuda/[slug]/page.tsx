import type { Metadata } from "next"
import Link from "next/link"
import { notFound } from "next/navigation"
import { ArrowLeft, ArrowRight } from "lucide-react"

import { ArticleMarkdown } from "@/components/docs/article-markdown"
import { DocsToc } from "@/components/docs/docs-toc"
import { cn } from "@/lib/utils"
import {
  type DocArticle,
  getArticle,
  getBlocks,
  getArticles,
  getNeighbors,
  getSlugByFile,
} from "@/lib/docs/content"
import { APP_URL } from "@/lib/site/links"

/** Solo existen los artículos de `content/ayuda`: cualquier otro slug es 404. */
export const dynamicParams = false

export function generateStaticParams() {
  return getArticles().map((article) => ({ slug: article.slug }))
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>
}): Promise<Metadata> {
  const { slug } = await params
  const article = getArticle(slug)
  if (!article) return {}
  return {
    title: article.title,
    description: article.resumen,
    alternates: { canonical: `/${article.slug}` },
    openGraph: { title: article.title, description: article.resumen, type: "article" },
  }
}

export default async function DocsArticlePage({
  params,
}: {
  params: Promise<{ slug: string }>
}) {
  const { slug } = await params
  const article = getArticle(slug)
  if (!article) notFound()

  const block = getBlocks().find((b) => b.key === article.blockKey)
  const { prev, next } = getNeighbors(slug)

  return (
    <div className="flex gap-10">
      <article className="min-w-0 max-w-3xl flex-1">
        {block && (
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            {block.title}
          </p>
        )}

        <ArticleMarkdown body={article.body} links={{ appUrl: APP_URL, slugByFile: getSlugByFile() }} />

        {(prev || next) && (
          <nav className="mt-12 grid gap-3 border-t pt-6 sm:grid-cols-2" aria-label="Artículos">
            {prev ? <NeighborLink article={prev} direction="prev" /> : <span className="hidden sm:block" />}
            {next && <NeighborLink article={next} direction="next" />}
          </nav>
        )}
      </article>

      <aside className="sticky top-24 hidden max-h-[calc(100svh-7rem)] w-56 shrink-0 self-start overflow-y-auto xl:block">
        <DocsToc headings={article.headings} />
      </aside>
    </div>
  )
}

function NeighborLink({ article, direction }: { article: DocArticle; direction: "prev" | "next" }) {
  const isNext = direction === "next"
  return (
    <Link
      href={`/${article.slug}`}
      className={cn(
        "flex items-center gap-3 rounded-md border p-4 transition-colors hover:bg-muted",
        isNext && "justify-end text-right sm:col-start-2",
      )}
    >
      {!isNext && <ArrowLeft className="size-4 shrink-0 text-muted-foreground" />}
      <span className="flex min-w-0 flex-col">
        <span className="text-xs text-muted-foreground">{isNext ? "Siguiente" : "Anterior"}</span>
        <span className="text-sm font-medium">{article.title}</span>
      </span>
      {isNext && <ArrowRight className="size-4 shrink-0 text-muted-foreground" />}
    </Link>
  )
}
