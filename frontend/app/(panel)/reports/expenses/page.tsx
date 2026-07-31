"use client"

/**
 * Reporte de Movimientos de Caja — espejo de panel/reports/expenses.html.
 *
 * Lista las entradas/salidas manuales del cajón (extracciones e ingresos
 * que se cargan desde el POS). El panel puede editar y eliminar registros
 * existentes — NO crear nuevos (los movimientos se originan solo desde el POS).
 *
 * Backend: GET /v1/reports/expenses?from=&to= → { rows, users }
 *          POST /v1/reports/expenses (action=update|delete)
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import {
  AlertCircle,
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  Coins,
  Pencil,
  Trash2,
} from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
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
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { DatePicker } from "@/components/date-picker"
import { MoneyInput } from "@/components/ui/money-input"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type ExpenseRow } from "@/hooks/use-reports"
import { useUpdateExpense, useDeleteExpense } from "@/hooks/use-expenses"
import { formatMoney } from "@/lib/format"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

export default function ExpensesReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const { data, isLoading, error } = useReport<{ rows: ExpenseRow[]; users: { id: string; name: string }[] }>(
    "expenses",
    opts,
  )

  // El endpoint devuelve { rows, users }. Si por algún motivo devolviera el array directo
  // (compat con versiones previas), lo normalizamos.
  const rows = React.useMemo(() => {
    if (!data) return []
    if (Array.isArray(data)) return data as ExpenseRow[]
    return (data as { rows?: ExpenseRow[] }).rows ?? []
  }, [data])

  const users = React.useMemo(() => {
    if (!data || Array.isArray(data)) return []
    return (data as { users?: { id: string; name: string }[] }).users ?? []
  }, [data])

  const totals = React.useMemo(() => {
    let income = 0
    let extraction = 0
    rows.forEach((r) => {
      // type: 1 = extracción (sale del cajón), 2 = ingreso (entra al cajón).
      if (r.type === 2) income += r.amount
      else extraction += r.amount
    })
    return { income, extraction, net: income - extraction }
  }, [rows])

  // ── Estado de edición ─────────────────────────────────────────────────────
  const [editRow, setEditRow] = React.useState<ExpenseRow | null>(null)
  const [editDate, setEditDate] = React.useState<string>("")
  const [editAmount, setEditAmount] = React.useState<number | null>(null)
  const [editNote, setEditNote] = React.useState("")
  const [editUser, setEditUser] = React.useState("")

  function openEdit(row: ExpenseRow) {
    setEditRow(row)
    // Convertir "YYYY-MM-DD HH:mm:ss" → "YYYY-MM-DD" para el DatePicker
    setEditDate(row.date ? row.date.slice(0, 10) : "")
    setEditAmount(row.amount)
    setEditNote(row.note ?? "")
    setEditUser(row.userId ?? "")
  }

  // ── Estado de eliminación ─────────────────────────────────────────────────
  const [deleteRow, setDeleteRow] = React.useState<ExpenseRow | null>(null)

  // ── Mutaciones ────────────────────────────────────────────────────────────
  const updateMutation = useUpdateExpense()
  const deleteMutation = useDeleteExpense()

  async function handleUpdate() {
    if (!editRow || !editDate || editAmount === null) return
    try {
      await updateMutation.mutateAsync({
        id: editRow.expensesId,
        date: editDate, // ya es "YYYY-MM-DD"
        total: editAmount,
        note: editNote,
        user: editUser,
      })
      toast.success("Movimiento actualizado")
      setEditRow(null)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al actualizar")
    }
  }

  async function handleDelete() {
    if (!deleteRow) return
    try {
      await deleteMutation.mutateAsync({ id: deleteRow.expensesId })
      toast.success("Movimiento eliminado")
      setDeleteRow(null)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al eliminar")
    }
  }

  // ── Columnas ──────────────────────────────────────────────────────────────
  const columns = React.useMemo<ColumnDef<ExpenseRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{niceDateTime((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Fecha", className: "tabular-nums" },
      },
      {
        accessorKey: "type",
        header: "Tipo",
        cell: ({ row }) => {
          const t = row.original.type
          return t === 2 ? (
            <Badge variant="default" className="gap-1">
              <ArrowUp className="size-3" />
              Ingreso
            </Badge>
          ) : (
            <Badge variant="secondary" className="gap-1">
              <ArrowDown className="size-3" />
              Extracción
            </Badge>
          )
        },
        meta: { label: "Tipo" },
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
        accessorKey: "registerName",
        header: "Caja",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Caja" },
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "Usuario" },
      },
      {
        accessorKey: "note",
        header: "Concepto",
        cell: ({ getValue }) => {
          const v = (getValue() as string) ?? ""
          if (!v) return <span className="opacity-40">—</span>
          return <span className="truncate">{v}</span>
        },
        meta: { label: "Concepto" },
      },
      {
        accessorKey: "amount",
        header: "Monto",
        cell: ({ row }) => {
          const r = row.original
          const cls =
            r.type === 2
              ? "tabular-nums font-medium text-emerald-600"
              : "tabular-nums font-medium text-destructive"
          return (
            <span className={cls}>
              {r.type === 2 ? "+" : "−"} {formatMoney(r.amount, bootstrap)}
            </span>
          )
        },
        meta: { label: "Monto", className: "tabular-nums text-right" },
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => (
          <RowActions
            actions={[
              { label: "Editar", icon: Pencil, onSelect: () => openEdit(row.original) },
              {
                label: "Eliminar",
                icon: Trash2,
                variant: "destructive",
                onSelect: () => setDeleteRow(row.original),
              },
            ]}
          />
        ),
        meta: { label: "Acciones" },
        enableSorting: false,
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Movimientos de Caja</h1>
          <p className="text-sm text-muted-foreground">
            Entradas y salidas manuales del cajón. Se crean desde el POS; acá podés editar o eliminar.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{(error as Error).message}</p>
          </div>
        </div>
      )}

      {!isLoading && rows.length > 0 && (
        <StatsRow>
          <StatTile
            icon={<ArrowUp className="size-3.5 text-emerald-600" />}
            label="Ingresos"
            value={formatMoney(totals.income, bootstrap)}
            tone="positive"
          />
          <StatTile
            icon={<ArrowDown className="size-3.5 text-destructive" />}
            label="Extracciones"
            value={formatMoney(totals.extraction, bootstrap)}
            tone="negative"
          />
          <StatTile
            label="Neto"
            value={formatMoney(totals.net, bootstrap)}
            emphasis
            tone={totals.net >= 0 ? "positive" : "negative"}
          />
        </StatsRow>
      )}

      <DataTable
        tableId="report-expenses"
        data={rows}
        columns={columns}
        getRowId={(r) => r.expensesId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por concepto, usuario, caja…"
        exportFileName="movimientos_caja"
        emptyMessage={
          <EmptyState
            icon={Coins}
            title="Sin movimientos de caja"
            description="Los ingresos/extracciones se cargan desde el POS."
          />
        }
      />

      {/* Dialog de edición */}
      <Dialog open={!!editRow} onOpenChange={(o) => { if (!o) setEditRow(null) }}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Editar movimiento</DialogTitle>
          </DialogHeader>

          <div className="flex flex-col gap-4 py-2">
            <div className="flex flex-col gap-1.5">
              <Label>Fecha</Label>
              <DatePicker value={editDate} onChange={(v) => setEditDate(v)} />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label>Monto</Label>
              <MoneyInput value={editAmount} onChange={setEditAmount} />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label>Concepto</Label>
              <Textarea
                value={editNote}
                onChange={(e) => setEditNote(e.target.value)}
                placeholder="Concepto del movimiento"
                rows={2}
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label>Usuario</Label>
              <Select
                value={editUser || "__none__"}
                onValueChange={(v) => setEditUser(v === "__none__" ? "" : v)}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Sin asignar" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">Sin asignar</SelectItem>
                  {users.map((u) => (
                    <SelectItem key={u.id} value={u.id}>
                      {u.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <DialogFooter>
            <Button variant="ghost" onClick={() => setEditRow(null)}>
              Cancelar
            </Button>
            <Button
              onClick={handleUpdate}
              disabled={!editDate || editDate === "" || editAmount === null || updateMutation.isPending}
            >
              {updateMutation.isPending ? "Guardando…" : "Guardar"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* AlertDialog de eliminación */}
      <AlertDialog open={!!deleteRow} onOpenChange={(o) => { if (!o) setDeleteRow(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar movimiento</AlertDialogTitle>
            <AlertDialogDescription>
              {deleteRow
                ? `¿Eliminar el movimiento del ${niceDateTime(deleteRow.date)} por ${formatMoney(deleteRow.amount, bootstrap)}? Esta acción no se puede deshacer.`
                : "¿Confirmar eliminación?"}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteMutation.isPending ? "Eliminando…" : "Eliminar"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

// ── Sub-componentes ───────────────────────────────────────────────────────────

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

function niceDateTime(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(undefined, {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  })
}
