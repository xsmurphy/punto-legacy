"use client"

/**
 * Mapa de calor ESPACIO × HORA — qué espacio estuvo ocupado a qué hora del
 * día, sumando todo el período.
 *
 * NO es el plano del local (`space.posx/posy`): esa es otra pregunta y quedó
 * fuera de alcance. Es una matriz, y por eso es una `<Table>`: las filas son
 * espacios, las columnas horas, y cada celda un número que se lee también por
 * color.
 *
 * ── Filas ────────────────────────────────────────────────────────────────────
 * Todos los espacios activos, se hayan usado o no (`spaceList` del backend):
 * un espacio vacío todo el período es un hallazgo, y con solo los usados no se
 * vería. Los que aparecen en la matriz pero ya no están activos se suman al
 * final — tuvieron uso en el período y borrarlos del reporte sería mentir.
 *
 * ── Columnas ─────────────────────────────────────────────────────────────────
 * De la primera a la última hora con uso, sin huecos: un comercio de 8 a 20
 * no necesita ver la madrugada, pero una hora vacía EN MEDIO del horario sí
 * se tiene que ver.
 *
 * ── Color ────────────────────────────────────────────────────────────────────
 * Un solo tono (`--chart-1`, la escala verde de charts) con intensidad
 * proporcional al máximo de la matriz — es una magnitud, no categorías. Vacío
 * = fondo apagado, distinto de "poco".
 */

import * as React from "react"

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import type { OperationsHeatCell } from "@/hooks/use-reports"

function pad(h: number): string {
  return String(h).padStart(2, "0")
}

/** 0 = vacío; con uso, de 20% a 100% del tono para que "poco" siga viéndose. */
function intensity(n: number, max: number): number {
  if (n <= 0 || max <= 0) return 0
  return Math.round(20 + 80 * (n / max))
}

function cellStyle(pct: number): React.CSSProperties | undefined {
  if (pct === 0) return undefined
  return { backgroundColor: `color-mix(in oklch, var(--chart-1) ${pct}%, transparent)` }
}

export function SpaceHourHeatmap({
  cells,
  spaces,
}: {
  cells: OperationsHeatCell[]
  spaces: Array<{ spaceId: string; spaceName: string }>
}) {
  const { rows, hours, byKey, max } = React.useMemo(() => {
    const byKey = new Map<string, number>()
    let max = 0
    let minHour = 24
    let maxHour = -1
    const extra = new Map<string, string>()
    const listed = new Set(spaces.map((s) => s.spaceId))
    for (const c of cells) {
      byKey.set(`${c.spaceId}@${c.hour}`, c.sessions)
      max = Math.max(max, c.sessions)
      minHour = Math.min(minHour, c.hour)
      maxHour = Math.max(maxHour, c.hour)
      if (!listed.has(c.spaceId)) extra.set(c.spaceId, c.spaceName)
    }
    const rows = [
      ...spaces,
      ...[...extra.entries()]
        .map(([spaceId, spaceName]) => ({ spaceId, spaceName }))
        .sort((a, b) => a.spaceName.localeCompare(b.spaceName)),
    ]
    const hours =
      maxHour < 0 ? [] : Array.from({ length: maxHour - minHour + 1 }, (_, i) => minHour + i)
    return { rows, hours, byKey, max }
  }, [cells, spaces])

  return (
    <div className="flex flex-col gap-3">
      {/* La matriz puede tener 24 columnas: scrollea DENTRO de su contenedor,
          nunca la página. La columna del nombre queda fija. */}
      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="sticky left-0 z-10 bg-muted">Espacio</TableHead>
              {hours.map((h) => (
                <TableHead key={h} className="px-1 text-center text-xs tabular-nums">
                  {pad(h)}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((r) => (
              <TableRow key={r.spaceId} className="hover:bg-transparent">
                <TableCell className="sticky left-0 z-10 whitespace-nowrap bg-card font-medium">
                  {r.spaceName}
                </TableCell>
                {hours.map((h) => {
                  const n = byKey.get(`${r.spaceId}@${h}`) ?? 0
                  const label = `${r.spaceName}, ${pad(h)} h: ${n} ${n === 1 ? "sesión" : "sesiones"}`
                  return (
                    <TableCell key={h} className="p-0.5">
                      <div
                        role="img"
                        aria-label={label}
                        title={label}
                        className={
                          n === 0
                            ? "flex h-7 min-w-7 items-center justify-center rounded-sm bg-muted/40"
                            : "flex h-7 min-w-7 items-center justify-center rounded-sm text-xs font-medium tabular-nums text-foreground"
                        }
                        style={cellStyle(intensity(n, max))}
                      >
                        {n > 0 ? n : ""}
                      </div>
                    </TableCell>
                  )
                })}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <span>Menos</span>
        {[20, 40, 60, 80, 100].map((p) => (
          <span key={p} className="size-4 rounded-sm" style={cellStyle(p)} aria-hidden />
        ))}
        <span>Más</span>
      </div>
    </div>
  )
}
