import type { ComponentType, ReactNode } from "react"
import { Marquee } from "@/components/magicui/marquee"
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyTitle,
} from "@/components/ui/empty"
import { cn } from "@/lib/utils"

/**
 * Empty state con un Marquee decorativo que repite el icono del contexto.
 * Reemplaza el uso directo de `<Empty>` con `<EmptyMedia variant="icon">`
 * para tener un look más rico y consistente en toda la app.
 *
 * @example
 *   <EmptyState
 *     icon={NotebookPen}
 *     title="Sin notas todavía"
 *     description='Decile a Alfred "guarda una nota: …"'
 *     actions={<Button onClick={...}>Crear nota</Button>}
 *   />
 *
 * `ghost` es el mismo look (card con ícono fantasma + barra + círculo,
 * fila x N, degradado hacia arriba) — prop explícita recomendada para
 * empties nuevos. `showMarquee` sigue existiendo por compat con los usos
 * existentes; si no se pasa ninguna de las dos, el default es "ghost" (4
 * filas), igual que el comportamiento histórico de `showMarquee=true`.
 */
interface EmptyStateProps {
  icon: ComponentType<{ className?: string }>
  title: string
  description?: ReactNode
  actions?: ReactNode
  /**
   * Ghost cards decorativas arriba del título/descripción. `true` = 4 filas
   * (default), un número = esa cantidad de filas, `false` = sin ghosts
   * (ícono grande estático). Equivalente moderno de `showMarquee`.
   */
  ghost?: boolean | number
  /** @deprecated Usá `ghost`. Se mantiene por compat con call sites existentes. */
  showMarquee?: boolean
  className?: string
}

export function EmptyState({
  icon: Icon,
  title,
  description,
  actions,
  ghost,
  showMarquee,
  className,
}: EmptyStateProps) {
  const ghostRows =
    ghost !== undefined
      ? ghost === true
        ? 4
        : ghost === false
          ? 0
          : ghost
      : showMarquee === false
        ? 0
        : 4

  return (
    <Empty className={cn("px-6 py-10", className)}>
      <EmptyHeader>
        {ghostRows > 0 ? (
          <div className="mask-y-from-60% mask-x-from-95% mb-3 w-full max-w-xs space-y-2">
            <Marquee className="h-56 [--duration:8s]" repeat={5} vertical>
              {Array.from({ length: ghostRows }).map((_, i) => (
                <div
                  key={i}
                  className="flex w-full items-center gap-3 rounded-lg border px-4 py-3"
                >
                  <Icon className="shrink-0 size-5 text-muted-foreground/70" />
                  <div className="h-3 flex-1 rounded-md bg-muted" />
                  <div className="ms-auto size-5 shrink-0 rounded-full bg-muted" />
                </div>
              ))}
            </Marquee>
          </div>
        ) : (
          <div className="mb-2 flex size-12 items-center justify-center rounded-xl border bg-muted/30">
            <Icon className="size-6 text-muted-foreground" />
          </div>
        )}
        <EmptyTitle className="text-lg font-semibold tracking-tight sm:text-xl">
          {title}
        </EmptyTitle>
        {description ? (
          <EmptyDescription className="text-sm/snug">{description}</EmptyDescription>
        ) : null}
      </EmptyHeader>
      {actions ? <EmptyContent>{actions}</EmptyContent> : null}
    </Empty>
  )
}
