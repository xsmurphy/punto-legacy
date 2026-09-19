import * as React from "react"
import Link from "next/link"

import { cn } from "@/lib/utils"

/**
 * BarList — ranking horizontal como LISTA con barras, no como gráfico.
 *
 * Cada fila: el nombre arriba a la izquierda (truncado, completo en el
 * `title`), el valor a la derecha y una barra fina de ancho completo debajo,
 * proporcional al máximo de la lista. Sin eje de nombres: en un `BarChart`
 * horizontal de Recharts el eje Y se come un ancho fijo y trunca justo lo que
 * hay que leer (el nombre del artículo o de la categoría).
 *
 * Es el componente de TODO ranking horizontal del panel (top artículos, top
 * categorías, ventas por sucursal). El valor llega YA formateado (`display`)
 * con los helpers del tenant (`formatMoney`, `formatQty`): la lista no sabe
 * de monedas ni de locales, solo de proporciones (`value`).
 *
 * `meta` es un dato secundario opcional que va al lado del valor (cantidad,
 * porcentaje, delta), en `text-xs`.
 */
export interface BarListItem {
  key: string
  label: string
  /** Magnitud cruda para la barra. Negativos cuentan como 0. */
  value: number
  /** El valor formateado que se lee. */
  display: React.ReactNode
  meta?: React.ReactNode
  href?: string
}

export function BarList({ items, className }: { items: BarListItem[]; className?: string }) {
  const max = Math.max(0, ...items.map((i) => (Number.isFinite(i.value) ? i.value : 0)))
  return (
    <ul className={cn("flex flex-col gap-3", className)}>
      {items.map((item) => {
        const pct = max > 0 ? Math.max(0, Math.min(100, (Math.max(0, item.value) / max) * 100)) : 0
        const body = (
          <>
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span className="min-w-0 truncate" title={item.label}>
                {item.label}
              </span>
              <span className="flex shrink-0 items-baseline gap-2">
                {item.meta != null && (
                  <span className="text-xs text-muted-foreground tabular-nums">{item.meta}</span>
                )}
                <span className="font-medium tabular-nums">{item.display}</span>
              </span>
            </div>
            <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-muted">
              <div
                className="h-full rounded-full bg-[var(--chart-1)]"
                style={{ width: `${pct}%` }}
              />
            </div>
          </>
        )
        return (
          <li key={item.key} className="min-w-0">
            {item.href ? (
              <Link href={item.href} className="block rounded-sm hover:opacity-80">
                {body}
              </Link>
            ) : (
              body
            )}
          </li>
        )
      })}
    </ul>
  )
}
