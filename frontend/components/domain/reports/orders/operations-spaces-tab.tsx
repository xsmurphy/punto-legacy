"use client"

/**
 * Tab "Espacios" del reporte de Órdenes — sesiones, duración, personas y la
 * matriz espacio × hora.
 *
 * "Espacio" y "personas", nunca mesa ni comensal: un espacio es un box de un
 * taller, un consultorio o un puesto igual que una mesa (regla del owner
 * 2026-09-10).
 *
 * ── Cobertura ────────────────────────────────────────────────────────────────
 * La cantidad de personas es OPCIONAL al abrir un espacio. El promedio sale
 * solo de las sesiones que la tienen (el backend no trata el vacío como cero),
 * y la pantalla dice sobre cuántas — arriba, antes del número. La duración
 * cuenta solo las sesiones cerradas: una abierta todavía no terminó.
 *
 * Las sesiones que se UNIERON a otra no son ocupación (quedan cerradas sin
 * órdenes) y se informan aparte, en vez de inflar la rotación.
 */

import * as React from "react"
import { LayoutGrid } from "lucide-react"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { formatInt } from "@/lib/format"
import { formatMinutes } from "@/lib/orders/order-stage-marks"
import { resolveNumberLocale } from "@/lib/tenant-locale"
import type { OperationsSpaces } from "@/hooks/use-reports"
import type { Bootstrap } from "@/lib/types/bootstrap"

import { CoverageNote, coveragePct } from "./coverage-note"
import { SpaceHourHeatmap } from "./space-hour-heatmap"

/** Debajo de este % de sesiones con personas cargadas, el promedio es de una muestra. */
const COBERTURA_MINIMA_PCT = 50

export function OperationsSpacesTab({
  spaces,
  isLoading,
  bootstrap,
}: {
  spaces: OperationsSpaces | undefined
  isLoading: boolean
  bootstrap: Bootstrap | undefined
}) {
  const unused = React.useMemo(() => {
    if (!spaces) return []
    const used = new Set(spaces.heatmap.map((c) => c.spaceId))
    return spaces.spaceList.filter((s) => !used.has(s.spaceId))
  }, [spaces])

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-3 md:flex-row">
          {Array.from({ length: 4 }, (_, i) => (
            <Skeleton key={i} className="h-20 flex-1" />
          ))}
        </div>
        <Skeleton className="h-[360px] w-full" />
      </div>
    )
  }

  if (!spaces || (spaces.spaceList.length === 0 && spaces.sessions === 0)) {
    return (
      <EmptyState
        icon={LayoutGrid}
        title="Sin espacios configurados"
        description="Cuando configures espacios y se abran sesiones en ellos, vas a ver acá cuánto se usan y a qué hora."
      />
    )
  }

  const cov = spaces.coverage
  const open = cov.total - cov.closed
  const guestsPct = coveragePct(cov.withGuests, cov.total)
  const usedCount = spaces.spaceList.length - unused.length
  const oneDecimal = new Intl.NumberFormat(resolveNumberLocale(bootstrap), {
    maximumFractionDigits: 1,
  })

  return (
    <div className="flex flex-col gap-4">
      <StatsRow>
        <StatTile label="Sesiones" value={formatInt(spaces.sessions, bootstrap)} emphasis />
        <StatTile
          label="Duración promedio"
          value={spaces.avgMinutes === null ? "—" : formatMinutes(spaces.avgMinutes, bootstrap)}
        />
        <StatTile
          label="Personas promedio"
          value={spaces.avgGuests === null ? "—" : oneDecimal.format(spaces.avgGuests)}
        />
        <StatTile
          label="Espacios usados"
          value={`${formatInt(usedCount, bootstrap)} de ${formatInt(spaces.spaceList.length, bootstrap)}`}
        />
      </StatsRow>

      <CoverageNote
        low={cov.total > 0 && guestsPct < COBERTURA_MINIMA_PCT}
        lowTitle="Pocas sesiones con la cantidad de personas"
        lowDescription="Cargar la cantidad de personas es opcional al abrir un espacio. Con menos de la mitad cargada, el promedio describe a las sesiones en las que alguien la anotó, no a todas."
      >
        Personas: cargadas en {formatInt(cov.withGuests, bootstrap)} de{" "}
        {formatInt(cov.total, bootstrap)} sesiones ({guestsPct.toFixed(0)}%). Duración:
        sobre {formatInt(cov.closed, bootstrap)} sesiones cerradas
        {open > 0 ? `; ${formatInt(open, bootstrap)} siguen abiertas y todavía no cuentan` : ""}.
        {spaces.merged > 0 &&
          ` ${formatInt(spaces.merged, bootstrap)} ${spaces.merged === 1 ? "sesión se unió" : "sesiones se unieron"} a otra y no cuentan como ocupación.`}
      </CoverageNote>

      <Card>
        <CardHeader className="pb-2">
          <CardTitle>Uso por espacio y hora</CardTitle>
          <p className="text-sm text-muted-foreground">
            Cuántas sesiones ocuparon cada espacio en cada hora del día, sumando
            todo el período. Una sesión que sigue abierta cuenta solo en su hora
            de apertura.
          </p>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {spaces.heatmap.length === 0 ? (
            <EmptyState
              icon={LayoutGrid}
              title="Ningún espacio se usó en este período"
              description="Ajustá el rango de fechas y volvé a consultar."
              showMarquee={false}
              className="border-0 p-0"
            />
          ) : (
            <SpaceHourHeatmap cells={spaces.heatmap} spaces={spaces.spaceList} />
          )}
          {unused.length > 0 && spaces.heatmap.length > 0 && (
            <p className="text-sm text-muted-foreground">
              Sin uso en el período: {unused.map((s) => s.spaceName).join(", ")}.
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
