"use client"

/**
 * Reporte Calificación de Clientes (NPS) — espejo de panel/reports/satisfaction.html.
 *
 * Backend: GET /v1/reports/satisfaction?from=&to=
 * → array de filas: [{ satisfactionId, level, date, customerName, comment,
 *                      outletName, transactionId }]
 *
 * Reporte date-scoped. Muestra votos NPS del período.
 * NPS: level 1-3 = Detractores, 4-6 = Pasivos, 7-10 = Promotores.
 * Se usa monochrome — SIN colores rojo/amarillo/verde (prohibido por design system).
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, SmilePlus } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { EmptyState } from "@/components/empty-state"
import { useReport, type SatisfactionRow } from "@/hooks/use-reports"

function niceDate(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" })
}

type NpsGroup = "detractor" | "passive" | "promoter"

function npsGroup(level: number): NpsGroup {
  if (level <= 3) return "detractor"
  if (level <= 6) return "passive"
  return "promoter"
}

const NPS_BADGE: Record<NpsGroup, { label: string; variant: "default" | "secondary" | "outline" }> = {
  detractor: { label: "Detractor (Malo)", variant: "outline" },
  passive: { label: "Pasivo (Bueno)", variant: "secondary" },
  promoter: { label: "Promotor (Excelente)", variant: "default" },
}

export default function SatisfactionReportPage() {
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<SatisfactionRow[]>("satisfaction", opts)
  const rows = React.useMemo(() => data ?? [], [data])

  const nps = React.useMemo(() => {
    let detractors = 0
    let passives = 0
    let promoters = 0
    rows.forEach((r) => {
      const g = npsGroup(r.level)
      if (g === "detractor") detractors++
      else if (g === "passive") passives++
      else promoters++
    })
    const total = rows.length
    const score = total > 0
      ? Math.round(((promoters - detractors) / total) * 100)
      : null
    return { detractors, passives, promoters, total, score }
  }, [rows])

  const pctDetractors = nps.total > 0 ? (nps.detractors / nps.total) * 100 : 0
  const pctPassives = nps.total > 0 ? (nps.passives / nps.total) * 100 : 0
  const pctPromoters = nps.total > 0 ? (nps.promoters / nps.total) * 100 : 0

  const columns = React.useMemo<ColumnDef<SatisfactionRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{niceDate((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Fecha", className: "tabular-nums" },
      },
      {
        accessorKey: "customerName",
        header: "Cliente",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? <span className="font-medium">{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Cliente" },
      },
      {
        accessorKey: "level",
        header: "Calificación",
        cell: ({ getValue }) => {
          const level = Number(getValue())
          const g = npsGroup(level)
          const b = NPS_BADGE[g]
          return (
            <div className="flex items-center gap-2">
              <span className="tabular-nums font-semibold w-4 text-center">{level}</span>
              <Badge variant={b.variant} className="text-[10px]">{b.label}</Badge>
            </div>
          )
        },
        meta: { label: "Calificación" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "comment",
        header: "Comentario",
        cell: ({ getValue }) => {
          const v = (getValue() as string) ?? ""
          return v ? <span className="text-sm">{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Comentario" },
      },
    ],
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Calificación de Clientes</h1>
          <p className="text-sm text-muted-foreground">
            Net Promoter Score (NPS) del período — escala de 1 a 10.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {!isLoading && nps.total > 0 && (
        <div className="rounded-lg border bg-card p-4 flex flex-col gap-4">
          <div className="flex flex-wrap items-center gap-4">
            <div className="flex flex-col gap-0.5">
              <span className="text-[10px] uppercase tracking-wide text-muted-foreground">NPS Score</span>
              <span className="text-3xl font-bold tabular-nums">
                {nps.score !== null ? (nps.score > 0 ? `+${nps.score}` : nps.score) : "—"}
              </span>
            </div>
            <div className="flex gap-6 text-sm">
              <div className="flex flex-col gap-0.5">
                <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
                  Malos (Detractores)
                </span>
                <span className="font-medium tabular-nums">
                  {nps.detractors} ({Math.round(pctDetractors)}%)
                </span>
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
                  Buenos (Pasivos)
                </span>
                <span className="font-medium tabular-nums">
                  {nps.passives} ({Math.round(pctPassives)}%)
                </span>
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
                  Excelentes (Promotores)
                </span>
                <span className="font-medium tabular-nums">
                  {nps.promoters} ({Math.round(pctPromoters)}%)
                </span>
              </div>
            </div>
          </div>
          {/* Monochrome progress bar — three segments via multiple stacked bars */}
          <div className="flex gap-0.5 h-3 rounded-full overflow-hidden">
            {pctDetractors > 0 && (
              <div
                className="bg-muted-foreground/30 rounded-l-full"
                style={{ width: `${pctDetractors}%` }}
                title={`Detractores: ${Math.round(pctDetractors)}%`}
              />
            )}
            {pctPassives > 0 && (
              <div
                className="bg-muted-foreground/60"
                style={{ width: `${pctPassives}%` }}
                title={`Pasivos: ${Math.round(pctPassives)}%`}
              />
            )}
            {pctPromoters > 0 && (
              <div
                className="bg-foreground rounded-r-full"
                style={{ width: `${pctPromoters}%` }}
                title={`Promotores: ${Math.round(pctPromoters)}%`}
              />
            )}
          </div>
        </div>
      )}

      <DataTable
        tableId="report-satisfaction"
        data={rows}
        columns={columns}
        getRowId={(r) => r.satisfactionId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por cliente, comentario…"
        exportFileName="calificacion_clientes"
        emptyMessage={
          <EmptyState
            icon={SmilePlus}
            title="Sin calificaciones"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
  )
}

function BackLink() {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href="/reports">
        <ArrowLeft className="size-3.5" />
        Volver a reportes
      </Link>
    </Button>
  )
}
