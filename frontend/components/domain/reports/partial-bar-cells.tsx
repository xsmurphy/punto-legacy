import { Cell } from "recharts"
import { bucketOpacity } from "@/lib/charts/granularity"

/**
 * Celdas de una `<Bar>` de serie temporal que atenúan los períodos
 * INCOMPLETOS (la primera o última semana/mes que el rango recorta, ver
 * `lib/charts/granularity.ts`). Se pasan como hijos de la barra:
 *
 *     <Bar dataKey="total" fill="var(--color-total)">
 *       {partialBarCells(data)}
 *     </Bar>
 *
 * Es una función y no un componente porque Recharts lee los `<Cell>` como
 * hijos DIRECTOS de la barra: envueltos en un componente propio no los ve.
 * `baseOpacity` es la opacidad propia de la serie (la del período anterior en
 * el comparativo de Ventas va más tenue de por sí).
 */
export function partialBarCells(
  data: ReadonlyArray<{ partial?: boolean }>,
  baseOpacity = 1,
) {
  return data.map((point, i) => (
    <Cell key={`partial-${i}`} fillOpacity={bucketOpacity(point, baseOpacity)} />
  ))
}
