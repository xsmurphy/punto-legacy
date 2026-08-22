"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Lock, CalendarClock } from "lucide-react"
import { toast } from "sonner"

import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { Badge } from "@/components/ui/badge"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { EmptyState } from "@/components/empty-state"
import { formatInt } from "@/lib/format"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useSettings, useUpdateSettings } from "@/hooks/use-settings"
import { usePeriodClose, useClosePeriod } from "@/hooks/use-period-close"
import type { PeriodCloseMonth } from "@/lib/types/period-close"

const CLOSE_MONTHS_OPTIONS = Array.from({ length: 12 }, (_, i) => i + 1)

function formatPeriod(period: string): string {
  const [y, m] = period.split("-").map(Number)
  const label = new Intl.DateTimeFormat("es", { month: "long", year: "numeric" }).format(
    new Date(y, m - 1, 1),
  )
  return label.charAt(0).toUpperCase() + label.slice(1)
}

function formatClosedAt(iso: string | null): string {
  if (!iso) return "—"
  return new Intl.DateTimeFormat("es", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso))
}

/** `dateStr` es 'YYYY-MM-DD' puro (sin hora/zona) — se parsea a mano para
 *  evitar el corrimiento de día que da `new Date('YYYY-MM-DD')` (UTC
 *  medianoche) al formatear en una zona horaria negativa como Asunción. */
function formatDateOnly(dateStr: string): string {
  const [y, m, d] = dateStr.split("-").map(Number)
  return new Intl.DateTimeFormat("es", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(y, m - 1, d))
}

/**
 * true si `period` ('YYYY-MM') cae dentro de la ventana abierta del tenant
 * (mes en curso + `closeMonths` anteriores) — mismo criterio que
 * `period_close_due()` en SQL (api/database/migrations/postgres/157_period_close.sql).
 * Solo informativo para deshabilitar la acción en el front: la autoridad real
 * es la validación server-side en POST /v1/period-close.
 */
function isInOpenWindow(period: string, closeMonths: number): boolean {
  const [y, m] = period.split("-").map(Number)
  const periodDate = new Date(y, m - 1, 1)
  const now = new Date()
  const cutoff = new Date(now.getFullYear(), now.getMonth(), 1)
  const boundary = new Date(cutoff.getFullYear(), cutoff.getMonth() - closeMonths, 1)
  return periodDate >= boundary
}

export default function CierreDePeriodoPage() {
  const { data, isLoading } = usePeriodClose()
  const { data: settings } = useSettings()
  const { data: bootstrap } = useBootstrap()
  const updateSettings = useUpdateSettings()
  const closePeriod = useClosePeriod()
  const [confirmPeriod, setConfirmPeriod] = React.useState<string | null>(null)

  const closeMonths = data?.closeMonths ?? 1

  const columns = React.useMemo<ColumnDef<PeriodCloseMonth>[]>(
    () => [
      {
        accessorKey: "period",
        header: "Período",
        cell: ({ row }) => <span>{formatPeriod(row.original.period)}</span>,
      },
      {
        accessorKey: "transactionCount",
        header: "Transacciones",
        cell: ({ row }) => formatInt(row.original.transactionCount, bootstrap),
      },
      {
        accessorKey: "closed",
        header: "Estado",
        cell: ({ row }) =>
          row.original.closed ? (
            <Badge variant="default">Cerrado</Badge>
          ) : (
            <Badge variant="outline">Abierto</Badge>
          ),
      },
      {
        accessorKey: "closedAt",
        header: "Cerrado el",
        cell: ({ row }) => formatClosedAt(row.original.closedAt),
      },
      {
        accessorKey: "closedBy",
        header: "Por quién",
        cell: ({ row }) => row.original.closedBy ?? "—",
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => (
          <RowActions
            actions={[
              {
                label: "Cerrar",
                icon: Lock,
                onSelect: () => setConfirmPeriod(row.original.period),
                hidden:
                  row.original.closed || isInOpenWindow(row.original.period, closeMonths),
              },
            ]}
          />
        ),
      },
    ],
    [closeMonths, bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Cierre de período</h1>
        <p className="text-sm text-muted-foreground">
          Un período cerrado vuelve inmutables sus ventas, gastos, pagos y
          movimientos de stock — corregilo con un documento nuevo (nota de
          crédito, ajuste de stock, movimiento de caja), no editando lo viejo.
        </p>
      </header>

      <div className="flex flex-wrap items-center gap-3">
        <Label htmlFor="close-months" className="text-sm text-muted-foreground">
          Ventana abierta
        </Label>
        <Select
          value={String(settings?.settingPeriodCloseMonths ?? 1)}
          onValueChange={(v) => {
            updateSettings.mutate(
              { settingPeriodCloseMonths: Number(v) },
              {
                onSuccess: () => toast.success("Ventana de cierre actualizada"),
                onError: (err) => toast.error(err.message),
              },
            )
          }}
        >
          <SelectTrigger id="close-months" className="w-48">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {CLOSE_MONTHS_OPTIONS.map((n) => (
              <SelectItem key={n} value={String(n)}>
                {n} {n === 1 ? "mes" : "meses"}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {data && (
          <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
            <CalendarClock className="size-3.5" />
            Próximo cierre automático: {formatDateOnly(data.nextAutoClose)}
          </span>
        )}
      </div>

      <DataTable
        tableId="period-close"
        columns={columns}
        data={data?.months ?? []}
        getRowId={(row) => row.period}
        isLoading={isLoading}
        searchPlaceholder="Buscar período..."
        exportFileName={null}
        emptyMessage={
          <EmptyState
            icon={Lock}
            title="Sin períodos"
            description="Todavía no hay transacciones registradas para tu comercio."
          />
        }
      />

      <AlertDialog
        open={confirmPeriod !== null}
        onOpenChange={(o) => {
          if (!o) setConfirmPeriod(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Cerrar {confirmPeriod ? formatPeriod(confirmPeriod) : "período"}
            </AlertDialogTitle>
            <AlertDialogDescription>
              Las ventas, gastos, pagos y movimientos de stock de este mes
              quedarán inmutables. No hay forma de reabrirlo — cualquier
              corrección posterior se hace con un documento nuevo. Esta acción
              no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (!confirmPeriod) return
                closePeriod.mutate(confirmPeriod, {
                  onSuccess: () => {
                    toast.success("Período cerrado")
                    setConfirmPeriod(null)
                  },
                  onError: (err) => {
                    toast.error(err.message)
                  },
                })
              }}
            >
              Cerrar período
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
