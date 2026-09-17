"use client"

/**
 * Resumen de asistencia — una fila por persona (context/83 F1).
 *
 * Responde la pregunta del dueño: cuánto trabajó cada uno en el período y
 * cuántas veces llegó tarde.
 *
 * ── Dos columnas que parecen ruido y no lo son ─────────────────────────────
 *
 * **Sin cerrar**: entradas que nunca tuvieron su salida. No se estiman ni se
 * completan hasta el fin del día —serían horas inventadas, y esas horas se
 * pagan—, así que suman cero y se cuentan aparte. Un número distinto de cero
 * ahí significa que las horas de esa persona están SUBestimadas, y sin la
 * columna el dueño no tendría cómo saberlo.
 *
 * **Tardanzas** en un guión: esa persona no tiene horario declarado en su
 * legajo. Distinto de cero tardanzas, que es haber llegado siempre en horario.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { UserCheck } from "lucide-react"

import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatInt } from "@/lib/format"
import type { AttendanceEmployeeRow } from "@/hooks/use-attendance"
import { formatMinutes } from "@/components/domain/reports/attendance/attendance-format"

export function AttendanceSummaryTab({
  rows,
  isLoading,
  totals,
}: {
  rows: AttendanceEmployeeRow[]
  isLoading: boolean
  totals: { workedMinutes: number; lateCount: number; needsReview: number } | undefined
}) {
  const { data: bootstrap } = useBootstrap()

  const columns = React.useMemo<ColumnDef<AttendanceEmployeeRow, unknown>[]>(
    () => [
      {
        accessorKey: "employeeName",
        header: "Persona",
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span className="font-medium">{row.original.employeeName}</span>
            <span className="text-xs text-muted-foreground">
              {row.original.jobTitle || "Sin puesto cargado"}
            </span>
          </div>
        ),
        meta: { label: "Persona" },
      },
      {
        accessorKey: "days",
        header: "Días",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatInt(Number(getValue()) || 0, bootstrap)}</span>
        ),
        meta: { label: "Días", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "workedMinutes",
        header: "Horas trabajadas",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">{formatMinutes(Number(getValue()) || 0)}</span>
        ),
        meta: { label: "Horas trabajadas", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "openPairs",
        header: "Sin cerrar",
        cell: ({ getValue }) => {
          const open = Number(getValue()) || 0
          return (
            <span
              className={open > 0 ? "tabular-nums text-destructive" : "tabular-nums text-muted-foreground"}
            >
              {open}
            </span>
          )
        },
        meta: { label: "Sin cerrar", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "lateCount",
        header: "Tardanzas",
        cell: ({ row }) =>
          row.original.hasSchedule ? (
            <span className="tabular-nums">{row.original.lateCount}</span>
          ) : (
            // Guión y no cero: esta persona no tiene horario declarado, así que
            // no hay contra qué medir. Un cero diría que llegó siempre a
            // horario, que es una afirmación que nadie hizo.
            <span className="text-muted-foreground">—</span>
          ),
        meta: { label: "Tardanzas", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "lateMinutes",
        header: "Tiempo de tardanza",
        cell: ({ row }) =>
          row.original.hasSchedule && row.original.lateMinutes > 0 ? (
            <span className="tabular-nums text-muted-foreground">
              {formatMinutes(row.original.lateMinutes)}
            </span>
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
        meta: { label: "Tiempo de tardanza", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "needsReview",
        header: "Para revisar",
        cell: ({ getValue }) => {
          const n = Number(getValue()) || 0
          return n === 0 ? (
            <span className="text-muted-foreground">—</span>
          ) : (
            <span className="tabular-nums">{n}</span>
          )
        },
        meta: { label: "Para revisar", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      {!isLoading && rows.length > 0 && totals && (
        <StatsRow>
          <StatTile label="Personas" value={formatInt(rows.length, bootstrap)} />
          <StatTile label="Horas del período" value={formatMinutes(totals.workedMinutes)} emphasis />
          <StatTile label="Tardanzas" value={formatInt(totals.lateCount, bootstrap)} />
          <StatTile label="Para revisar" value={formatInt(totals.needsReview, bootstrap)} />
        </StatsRow>
      )}

      <DataTable
        tableId="report-attendance-summary"
        data={rows}
        columns={columns}
        getRowId={(r) => r.employeeId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por persona…"
        exportFileName="asistencia"
        emptyMessage={
          <EmptyState
            icon={UserCheck}
            title="Sin marcaciones en el período"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
  )
}
