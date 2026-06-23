"use client"

import * as React from "react"
import ReactMarkdown, { type Components } from "react-markdown"
import remarkGfm from "remark-gfm"
import { cn } from "@/lib/utils"

/**
 * Render de markdown para los mensajes del agente. Estilos compactos basados
 * en tokens del tema — sin plugin de typography, controlamos cada elemento
 * para que la conversación se sienta densa (no como un artículo).
 *
 * GFM (remark-gfm) habilita tablas, strikethrough, listas con check, autolinks.
 */
const COMPONENTS: Components = {
  p:      ({ children }) => <p className="mb-2 last:mb-0 leading-relaxed">{children}</p>,
  strong: ({ children }) => <strong className="font-semibold">{children}</strong>,
  em:     ({ children }) => <em className="italic">{children}</em>,
  a:      ({ children, href }) => (
    <a href={href} target="_blank" rel="noreferrer" className="underline underline-offset-2 hover:text-foreground">
      {children}
    </a>
  ),
  ul:     ({ children }) => <ul className="mb-2 ml-4 list-disc space-y-1 last:mb-0">{children}</ul>,
  ol:     ({ children }) => <ol className="mb-2 ml-4 list-decimal space-y-1 last:mb-0">{children}</ol>,
  li:     ({ children }) => <li className="leading-relaxed">{children}</li>,
  h1:     ({ children }) => <h1 className="mb-2 mt-3 text-base font-semibold first:mt-0">{children}</h1>,
  h2:     ({ children }) => <h2 className="mb-2 mt-3 text-sm font-semibold first:mt-0">{children}</h2>,
  h3:     ({ children }) => <h3 className="mb-1 mt-2 text-sm font-medium first:mt-0">{children}</h3>,
  img: ({ node, ...props }) => (
    <img {...props} className="max-w-[300px] h-auto rounded-lg" alt={props.alt ?? ""} />
  ),
  hr:     () => <hr className="my-3 border-border" />,
  blockquote: ({ children }) => (
    <blockquote className="mb-2 border-l-2 border-border pl-3 italic text-muted-foreground last:mb-0">
      {children}
    </blockquote>
  ),
  code: ({ className, children, ...props }) => {
    // Inline vs block: react-markdown 10 ya NO pasa `inline`. Detectamos por
    // presencia de `language-*` className (los bloques siempre la traen).
    const isBlock = /^language-/.test(className ?? "")
    if (isBlock) {
      return (
        <code
          className={cn("font-mono text-xs", className)}
          {...props}
        >
          {children}
        </code>
      )
    }
    return (
      <code className="rounded bg-muted px-1 py-0.5 font-mono text-[0.85em]" {...props}>
        {children}
      </code>
    )
  },
  pre: ({ children }) => (
    <pre className="mb-2 overflow-x-auto rounded-lg bg-muted/60 p-3 last:mb-0">{children}</pre>
  ),
  table: ({ children }) => (
    <div className="mb-2 overflow-x-auto last:mb-0">
      <table className="w-full border-collapse text-xs">{children}</table>
    </div>
  ),
  thead: ({ children }) => <thead className="border-b border-border">{children}</thead>,
  th:    ({ children }) => <th className="px-2 py-1.5 text-left font-medium">{children}</th>,
  td:    ({ children }) => <td className="border-t border-border/60 px-2 py-1.5">{children}</td>,
}

export function MessageMarkdown({ content }: { content: string }) {
  return (
    <div className="text-base">
      <ReactMarkdown remarkPlugins={[remarkGfm]} components={COMPONENTS}>
        {content}
      </ReactMarkdown>
    </div>
  )
}
