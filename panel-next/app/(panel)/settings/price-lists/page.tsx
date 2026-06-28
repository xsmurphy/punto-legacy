"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { ArrowLeft, Plus, Loader2, Tag, Pencil, Trash2, CalendarRange, CheckCircle2, XCircle } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
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
import { Input } from "@/components/ui/input"
import { Switch } from "@/components/ui/switch"
import { Label } from "@/components/ui/label"
import { DatePicker } from "@/components/date-picker"
import {
  usePriceLists,
  useCreatePriceList,
  useUpdatePriceList,
  useDeletePriceList,
} from "@/hooks/use-price-lists"
import type { PriceList } from "@/lib/types/price-list"

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Formatea el ajuste con signo explícito: +15% / −10% / 0% */
function formatAdjustment(adj: number): string {
  if (adj === 0) return "0%"
  const sign = adj > 0 ? "+" : "−"
  const abs  = Math.abs(adj)
  return `${sign}${abs}%`
}

/** Label de tipo: descuento, recargo o base */
function adjustmentLabel(adj: number): { text: string; variant: "default" | "secondary" | "outline" } {
  if (adj < 0) return { text: "Descuento", variant: "default" }
  if (adj > 0) return { text: "Recargo", variant: "secondary" }
  return { text: "Base", variant: "outline" }
}

function formatDate(iso: string | null): string {
  if (!iso) return "—"
  const d = new Date(iso)
  if (isNaN(d.getTime())) return "—"
  return d.toLocaleDateString("es-PY", { day: "2-digit", month: "2-digit", year: "2-digit" })
}

// ── Form de lista ─────────────────────────────────────────────────────────────

interface PriceListFormState {
  name: string
  defaultAdjustment: string
  validFrom: string
  validTo: string
  status: boolean
}

function emptyForm(): PriceListFormState {
  return { name: "", defaultAdjustment: "0", validFrom: "", validTo: "", status: true }
}

function listToForm(pl: PriceList): PriceListFormState {
  return {
    name:              pl.priceListName,
    defaultAdjustment: String(pl.defaultAdjustment),
    validFrom:         pl.validFrom ? pl.validFrom.slice(0, 10) : "",
    validTo:           pl.validTo   ? pl.validTo.slice(0, 10)   : "",
    status:            pl.status,
  }
}

interface PriceListSheetProps {
  open: boolean
  onClose: () => void
  editing: PriceList | null
}

function PriceListSheet({ open, onClose, editing }: PriceListSheetProps) {
  const create = useCreatePriceList()
  const update = useUpdatePriceList()
  const isPending = create.isPending || update.isPending

  const [form, setForm] = React.useState<PriceListFormState>(emptyForm)

  React.useEffect(() => {
    setForm(editing ? listToForm(editing) : emptyForm())
  }, [editing, open])

  const adjNum = parseFloat(form.defaultAdjustment) || 0
  const adjHint = adjNum === 0 ? "" : adjNum < 0
    ? `${Math.abs(adjNum)}% de descuento sobre el precio base`
    : `${adjNum}% de recargo sobre el precio base`

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!form.name.trim()) {
      toast.error("El nombre es requerido")
      return
    }
    const payload = {
      name:              form.name.trim(),
      defaultAdjustment: adjNum,
      validFrom:         form.validFrom || null,
      validTo:           form.validTo   || null,
      status:            form.status,
    }
    try {
      if (editing) {
        await update.mutateAsync({ id: editing.priceListId, payload })
        toast.success("Lista actualizada")
      } else {
        await create.mutateAsync(payload)
        toast.success("Lista creada")
      }
      onClose()
    } catch (err) {
      toast.error(editing ? "No se pudo actualizar" : "No se pudo crear", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose() }}>
      <DialogContent className="flex max-h-[90vh] flex-col gap-0 sm:max-w-lg">
        <DialogHeader className="pb-4">
          <DialogTitle>{editing ? "Editar lista" : "Nueva lista de precios"}</DialogTitle>
          <DialogDescription>
            Configurá nombre, ajuste global y vigencia. Podés agregar precios específicos
            por ítem desde el detalle de la lista.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="flex flex-1 flex-col gap-5 overflow-y-auto pb-6">
          {/* Nombre */}
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="pl-name">Nombre</Label>
            <Input
              id="pl-name"
              placeholder="Ej: Mayorista, PedidosYa, Promo Navidad…"
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              required
            />
          </div>

          {/* Ajuste global */}
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="pl-adj">
              Ajuste global
              <span className="ml-1 text-xs font-normal text-muted-foreground">
                (negativo = descuento, positivo = recargo)
              </span>
            </Label>
            <div className="flex items-center gap-2">
              <Input
                id="pl-adj"
                type="number"
                inputMode="decimal"
                step="0.01"
                placeholder="0"
                value={form.defaultAdjustment}
                onChange={(e) => setForm((f) => ({ ...f, defaultAdjustment: e.target.value }))}
                className="w-28 tabular-nums"
              />
              <span className="text-sm font-medium text-muted-foreground">%</span>
              {adjNum !== 0 && (
                <Badge variant={adjNum < 0 ? "default" : "secondary"} className="shrink-0">
                  {adjNum < 0 ? "−" : "+"}{Math.abs(adjNum)}%{" "}
                  {adjNum < 0 ? "descuento" : "recargo"}
                </Badge>
              )}
            </div>
            {adjHint && (
              <p className="text-xs text-muted-foreground">{adjHint}</p>
            )}
          </div>

          {/* Vigencia */}
          <div className="flex flex-col gap-1.5">
            <Label className="flex items-center gap-1.5">
              <CalendarRange className="size-3.5" />
              Vigencia
              <span className="ml-1 text-xs font-normal text-muted-foreground">(opcional)</span>
            </Label>
            <div className="grid grid-cols-2 gap-2">
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">Desde</span>
                <DatePicker
                  value={form.validFrom}
                  onChange={(v) => setForm((f) => ({ ...f, validFrom: v }))}
                  placeholder="Sin inicio"
                />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">Hasta</span>
                <DatePicker
                  value={form.validTo}
                  onChange={(v) => setForm((f) => ({ ...f, validTo: v }))}
                  placeholder="Sin vencimiento"
                />
              </div>
            </div>
            <p className="text-xs text-muted-foreground">
              La vigencia solo controla si la lista puede aplicarse automáticamente.
              Fuera de vigencia, la lista no se activa aunque esté asignada al cliente.
            </p>
          </div>

          {/* Activa */}
          <div className="flex items-center justify-between rounded-md border p-3">
            <div>
              <p className="text-sm font-medium">Lista activa</p>
              <p className="text-xs text-muted-foreground">Las listas inactivas no se aplican en ninguna venta.</p>
            </div>
            <Switch
              checked={form.status}
              onCheckedChange={(v) => setForm((f) => ({ ...f, status: v }))}
            />
          </div>

          <div className="mt-auto pt-2">
            <Button type="submit" className="w-full" disabled={isPending}>
              {isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
              {editing ? "Guardar cambios" : "Crear lista"}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export default function PriceListsPage() {
  const router = useRouter()
  const { data, isLoading, error } = usePriceLists()
  const deleteMut = useDeletePriceList()

  const [sheetOpen,    setSheetOpen]    = React.useState(false)
  const [editingList,  setEditingList]  = React.useState<PriceList | null>(null)
  const [deletingList, setDeletingList] = React.useState<PriceList | null>(null)

  const openCreate = () => { setEditingList(null); setSheetOpen(true) }
  const openEdit   = (pl: PriceList) => { setEditingList(pl); setSheetOpen(true) }
  const closeSheet = () => { setSheetOpen(false); setEditingList(null) }

  const handleDelete = async () => {
    if (!deletingList) return
    try {
      await deleteMut.mutateAsync(deletingList.priceListId)
      toast.success("Lista eliminada")
    } catch (err) {
      toast.error("No se pudo eliminar", {
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setDeletingList(null)
    }
  }

  const columns = React.useMemo<ColumnDef<PriceList>[]>(
    () => [
      {
        accessorKey: "priceListName",
        header: "Nombre",
        cell: ({ row }) => (
          <button
            type="button"
            onClick={() => router.push(`/settings/price-lists/${row.original.priceListId}`)}
            className="font-medium hover:underline text-left"
          >
            {row.original.priceListName}
          </button>
        ),
        meta: { label: "Nombre" },
      },
      {
        accessorKey: "defaultAdjustment",
        header: "Ajuste global",
        cell: ({ row }) => {
          const adj   = row.original.defaultAdjustment
          const label = adjustmentLabel(adj)
          return (
            <div className="flex items-center gap-2">
              <span className="tabular-nums font-mono text-sm">
                {formatAdjustment(adj)}
              </span>
              <Badge variant={label.variant} className="text-xs">
                {label.text}
              </Badge>
            </div>
          )
        },
        meta: { label: "Ajuste global" },
      },
      {
        accessorKey: "itemCount",
        header: "Ítems",
        cell: ({ getValue }) => {
          const n = getValue() as number
          return (
            <span className="tabular-nums text-muted-foreground">
              {n === 0 ? "Sin ítems" : `${n} ítem${n !== 1 ? "s" : ""}`}
            </span>
          )
        },
        meta: { label: "Ítems" },
      },
      {
        id: "vigencia",
        header: "Vigencia",
        cell: ({ row }) => {
          const from = row.original.validFrom
          const to   = row.original.validTo
          if (!from && !to) return <span className="text-muted-foreground">Sin límite</span>
          return (
            <span className="tabular-nums text-sm text-muted-foreground">
              {formatDate(from)} – {formatDate(to)}
            </span>
          )
        },
        meta: { label: "Vigencia" },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ getValue }) => {
          const active = getValue() as boolean
          return active ? (
            <span className="flex items-center gap-1.5 text-sm text-emerald-600">
              <CheckCircle2 className="size-3.5" />
              Activa
            </span>
          ) : (
            <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
              <XCircle className="size-3.5" />
              Inactiva
            </span>
          )
        },
        meta: { label: "Estado" },
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => (
          <div className="flex items-center justify-end gap-1">
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              onClick={(e) => { e.stopPropagation(); openEdit(row.original) }}
              title="Editar"
            >
              <Pencil className="size-3.5" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="size-8 text-muted-foreground hover:text-destructive"
              onClick={(e) => { e.stopPropagation(); setDeletingList(row.original) }}
              title="Eliminar"
            >
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        ),
        enableSorting: false,
        enableHiding: false,
      },
    ],
    [router]
  )

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Button asChild variant="ghost" size="icon" className="size-8">
            <Link href="/settings" aria-label="Volver">
              <ArrowLeft className="size-4" />
            </Link>
          </Button>
          <div>
            <h1 className="text-2xl font-semibold">Listas de precios</h1>
            <p className="text-sm text-muted-foreground">
              Ajustes globales o por ítem para clientes, sucursales o canales de venta.
            </p>
          </div>
        </div>
        <Button onClick={openCreate} className="gap-2">
          <Plus className="size-4" />
          Nueva lista
        </Button>
      </header>

      {error && (
        <div className="rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
          No se pudieron cargar las listas. {error.message}
        </div>
      )}

      <DataTable
        tableId="price-lists"
        data={data ?? []}
        columns={columns}
        isLoading={isLoading}
        getRowId={(r) => r.priceListId}
        onRowClick={(r) => router.push(`/settings/price-lists/${r.priceListId}`)}
        searchPlaceholder="Buscar listas…"
        exportFileName="listas-de-precios"
        emptyMessage={
          <EmptyState
            icon={Tag}
            title="Sin listas de precios"
            description="Creá tu primera lista para aplicar descuentos o recargos a clientes específicos."
            showMarquee={false}
            className="border-dashed py-10"
          />
        }
      />

      <PriceListSheet open={sheetOpen} onClose={closeSheet} editing={editingList} />

      <AlertDialog open={!!deletingList} onOpenChange={(o) => { if (!o) setDeletingList(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Eliminar &quot;{deletingList?.priceListName}&quot;?</AlertDialogTitle>
            <AlertDialogDescription>
              Se eliminarán también todos los precios por ítem configurados. Esta acción
              no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              disabled={deleteMut.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteMut.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
