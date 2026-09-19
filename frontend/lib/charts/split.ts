/**
 * Proporciones de una barra partida en dos (`components/charts/split-bar.tsx`).
 *
 * `widths` son las fracciones exactas para dibujar. `percents` son enteros que
 * SUMAN 100 (lo que se lee): redondear cada parte por separado da 33 + 66 o
 * 50 + 51. Con las dos partes en > 0 ninguna se lee "0 %" ni "100 %" — una
 * parte que existe no desaparece del texto.
 */
export interface SplitShares {
  widths: [number, number]
  percents: [number, number]
}

export function splitShares(a: number, b: number): SplitShares {
  const x = Number.isFinite(a) ? Math.max(0, a) : 0
  const y = Number.isFinite(b) ? Math.max(0, b) : 0
  const total = x + y
  if (total === 0) return { widths: [0, 0], percents: [0, 0] }
  const wa = x / total
  let pa = Math.round(wa * 100)
  if (x > 0 && y > 0) pa = Math.min(99, Math.max(1, pa))
  return { widths: [wa, 1 - wa], percents: [pa, 100 - pa] }
}
