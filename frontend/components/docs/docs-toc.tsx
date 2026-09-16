import type { DocHeading } from "@/lib/docs/markdown"
import { cn } from "@/lib/utils"

/** Tabla de contenidos del artículo (H2/H3). Solo en pantallas anchas. */
export function DocsToc({ headings }: { headings: DocHeading[] }) {
  if (headings.length === 0) return null

  return (
    <nav aria-label="En esta página">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
        En esta página
      </p>
      <ul className="flex flex-col gap-1.5 text-sm">
        {headings.map((heading) => (
          <li key={heading.id} className={cn(heading.depth === 3 && "pl-3")}>
            <a
              href={`#${heading.id}`}
              className="text-muted-foreground transition-colors hover:text-foreground"
            >
              {heading.text}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  )
}
