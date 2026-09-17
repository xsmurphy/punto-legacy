"use client"

/**
 * Marcaciones del período — una fila por marcación (context/83 F1).
 *
 * Es el detalle crudo: quién, cuándo, entrada o salida, con foto o sin ella. El
 * resumen por persona vive en la otra pestaña.
 *
 * ── La revisión no corrige nada ────────────────────────────────────────────
 *
 * "Revisada" significa que una persona la miró y la dio por buena. NO edita la
 * marcación ni la borra: un hecho que ocurrió no se edita, y una planilla de
 * horas que se puede retocar deja de ser evidencia de nada. Si la marcación
 * está mal, lo que corresponde es que la persona vuelva a marcar — la de más
 * también queda registrada, y las dos se ven.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Check, Image as ImageIcon, LogIn, LogOut, UserCheck } from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { EmptyState } from "@/components/empty-state"
import {
  useReviewAttendanceMark,
  type AttendanceMarkRow,
} from "@/hooks/use-attendance"
import {
  formatLateness,
  formatMinutes,
  reviewReasonLabel,
} from "@/components/domain/reports/attendance/attendance-format"
import { AttendancePhotoDialog } from "@/components/domain/reports/attendance/attendance-photo-dialog"

export function AttendanceMarksTab({
  rows,
  isLoading,
}: {
  rows: AttendanceMarkRow[]
  isLoading: boolean
}) {
  const [viewing, setViewing] = React.useState<AttendanceMarkRow | null>(null)
  const review = useReviewAttendanceMark()

  const onReview = React.useCallback(
    (mark: AttendanceMarkRow) => {
      review.mutate(mark.id, {
        onSuccess: () => toast.success("Marcación revisada"),
        onError: (err) =>
          toast.error(err instanceof Error ? err.message : "No se pudo marcar como revisada"),
      })
    },
    [review],
  )

  const columns = React.useMemo<ColumnDef<AttendanceMarkRow, unknown>[]>(
    () => [
      {
        accessorKey: "employeeName",
        header: "Persona",
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span className="font-medium">{row.original.employeeName}</span>
            <span className="text-xs text-muted-foreground">
              {row.original.outletName || "Sin sucursal"}
            </span>
          </div>
        ),
        meta: { label: "Persona" },
      },
      {
        accessorKey: "localDay",
        header: "Día",
        cell: ({ getValue }) => <span className="tabular-nums">{String(getValue() ?? "")}</span>,
        meta: { label: "Día", className: "tabular-nums" },
      },
      {
        accessorKey: "localTime",
        header: "Hora",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">{String(getValue() ?? "")}</span>
        ),
        meta: { label: "Hora", className: "tabular-nums" },
      },
      {
        accessorKey: "kind",
        header: "Tipo",
        cell: ({ row }) => (
          <span className="flex items-center gap-1.5">
            {row.original.kind === "in" ? (
              <LogIn className="size-3.5 text-muted-foreground" />
            ) : (
              <LogOut className="size-3.5 text-muted-foreground" />
            )}
            {row.original.kind === "in" ? "Entrada" : "Salida"}
          </span>
        ),
        meta: { label: "Tipo" },
      },
      {
        accessorKey: "pairedMinutes",
        header: "Duración",
        cell: ({ row }) =>
          row.original.pairedMinutes !== null ? (
            <span className="tabular-nums">{formatMinutes(row.original.pairedMinutes)}</span>
          ) : row.original.unpaired ? (
            // Una entrada sin salida (o al revés). No suma horas y hay que
            // poder verlo: es lo que explica por qué el total de esa persona
            // quedó corto.
            <span className="text-muted-foreground">Sin cerrar</span>
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
        meta: { label: "Duración", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "lateMinutes",
        header: "Tardanza",
        cell: ({ row }) => (
          <span
            className={
              (row.original.lateMinutes ?? 0) > 0
                ? "tabular-nums text-destructive"
                : "tabular-nums text-muted-foreground"
            }
          >
            {row.original.kind === "in" ? formatLateness(row.original.lateMinutes) : "—"}
          </span>
        ),
        meta: { label: "Tardanza", className: "tabular-nums text-right" },
      },
      {
        id: "status",
        header: "Estado",
        cell: ({ row }) => {
          const mark = row.original
          if (mark.needsReview) {
            return <Badge variant="secondary">{reviewReasonLabel(mark.reviewReason)}</Badge>
          }
          if (mark.reviewedAt) {
            return (
              <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                <Check className="size-3.5" />
                Revisada
              </span>
            )
          }
          return <span className="text-muted-foreground">—</span>
        },
        meta: { label: "Estado" },
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => {
          const mark = row.original
          return (
            <RowActions
              actions={[
                {
                  label: "Ver foto",
                  icon: ImageIcon,
                  onSelect: () => setViewing(mark),
                  disabled: !mark.hasPhoto,
                  reason: mark.hasPhoto ? undefined : "Esta marcación entró sin foto",
                },
                {
                  label: "Marcar como revisada",
                  icon: Check,
                  onSelect: () => onReview(mark),
                  disabled: !mark.needsReview,
                  reason: mark.needsReview ? undefined : "No está pendiente de revisión",
                },
              ]}
            />
          )
        },
        meta: { label: "Acciones", className: "w-10" },
      },
    ],
    [onReview],
  )

  return (
    <div className="flex flex-col gap-6">
      <DataTable
        tableId="report-attendance-marks"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder="Buscar por persona…"
        exportFileName="marcaciones"
        emptyMessage={
          <EmptyState
            icon={UserCheck}
            title="Sin marcaciones en el período"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />

      <AttendancePhotoDialog mark={viewing} onOpenChange={(open) => !open && setViewing(null)} />
    </div>
  )
}
