import Link from "next/link"
import Markdown, { defaultUrlTransform, type Components } from "react-markdown"
import remarkGfm from "remark-gfm"

import { Separator } from "@/components/ui/separator"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { remarkHeadingIds, resolveDocHref, type LinkContext } from "@/lib/docs/markdown"

/**
 * Estilos de lectura del cuerpo del artículo, acotados a este contenedor.
 *
 * razón (§14 Regla #1): la tipografía canónica es la de pantallas de panel. Un
 * artículo largo se lee mejor con interlineado amplio y subtítulos un paso
 * más grandes que el título de sección del panel (`text-base`), así que acá
 * H2/H3 suben a `text-xl`/`text-lg`. El H1 sí es el canon (`text-2xl`).
 * `scroll-mt-20` deja el subtítulo debajo del header fijo al saltar desde la
 * tabla de contenidos.
 */
function components(ctx: LinkContext): Components {
  return {
    h1: ({ children }) => <h1 className="text-2xl font-semibold">{children}</h1>,
    h2: ({ children, id }) => (
      <h2 id={id} className="mt-10 mb-3 scroll-mt-20 text-xl font-semibold tracking-tight">
        {children}
      </h2>
    ),
    h3: ({ children, id }) => (
      <h3 id={id} className="mt-8 mb-2 scroll-mt-20 text-lg font-semibold tracking-tight">
        {children}
      </h3>
    ),
    p: ({ children }) => <p className="my-4 leading-7">{children}</p>,
    ul: ({ children }) => <ul className="my-4 ml-6 list-disc space-y-2 leading-7">{children}</ul>,
    ol: ({ children }) => <ol className="my-4 ml-6 list-decimal space-y-2 leading-7">{children}</ol>,
    li: ({ children }) => <li className="pl-1">{children}</li>,
    strong: ({ children }) => <strong className="font-semibold">{children}</strong>,
    blockquote: ({ children }) => (
      <blockquote className="my-6 border-l-2 pl-4 text-muted-foreground">{children}</blockquote>
    ),
    code: ({ children }) => (
      <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-sm">{children}</code>
    ),
    hr: () => <Separator className="my-8" />,
    table: ({ children }) => (
      <div className="my-6 overflow-x-auto rounded-md border">
        <Table>{children}</Table>
      </div>
    ),
    thead: ({ children }) => <TableHeader>{children}</TableHeader>,
    tbody: ({ children }) => <TableBody>{children}</TableBody>,
    tr: ({ children }) => <TableRow>{children}</TableRow>,
    th: ({ children }) => <TableHead>{children}</TableHead>,
    td: ({ children }) => <TableCell className="whitespace-normal">{children}</TableCell>,
    a: ({ children, href }) => {
      const className = "font-medium underline underline-offset-4 hover:text-muted-foreground"
      if (!href) return <>{children}</>
      // Otro artículo del sitio: navegación interna.
      if (href.startsWith("/")) {
        return (
          <Link href={href} className={className}>
            {children}
          </Link>
        )
      }
      // Sección del panel u otro sitio: pestaña nueva, para no perder el artículo.
      const external = href.startsWith("http")
      return (
        <a
          href={href}
          className={className}
          target={external ? "_blank" : undefined}
          rel={external ? "noopener" : undefined}
        >
          {children}
        </a>
      )
    },
  }
}

export function ArticleMarkdown({ body, links }: { body: string; links: LinkContext }) {
  return (
    <Markdown
      remarkPlugins={[remarkGfm, remarkHeadingIds]}
      components={components(links)}
      // `panel:` y los `.md` se resuelven antes del filtro de seguridad por
      // defecto, que descartaría un esquema desconocido.
      urlTransform={(url) => {
        const resolved = resolveDocHref(url, links)
        return resolved === null ? "" : defaultUrlTransform(resolved)
      }}
    >
      {body}
    </Markdown>
  )
}
