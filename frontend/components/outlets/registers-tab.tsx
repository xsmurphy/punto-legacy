"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Pencil, Plus, Trash2 } from "lucide-react"
import { toast } from "sonner"

import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
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
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Separator } from "@/components/ui/separator"
import { formatDate } from "@/lib/format-date"
import {
  useRegistersAdmin,
  useCreateRegister,
  useUpdateRegister,
  useDeleteRegister,
  type RegisterFiscal,
  type RegisterListItem,
  type RegisterNumbering,
} from "@/hooks/use-registers-admin"

/** Hoy en formato YYYY-MM-DD según el reloj local, para comparar contra las
 *  fechas del timbrado (que son fechas puras, sin hora ni zona). */
function todayLocalISO(): string {
  const d = new Date()
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const dd = String(d.getDate()).padStart(2, "0")
  return `${d.getFullYear()}-${mm}-${dd}`
}

/** Fecha local a N días de hoy, YYYY-MM-DD. Para el aviso de "por vencer". */
function inDaysLocalISO(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() + days)
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const dd = String(d.getDate()).padStart(2, "0")
  return `${d.getFullYear()}-${mm}-${dd}`
}

const EMPTY_FISCAL: RegisterFiscal = {
  invoiceAuth: "",
  invoicePrefix: "",
  invoiceAuthStart: "",
  invoiceAuthExpiration: "",
}

/** En el ALTA los campos arrancan vacíos y el backend siembra la secuencia en
 *  1 si el usuario no carga nada. En la EDICIÓN llegan siempre con el próximo
 *  número real de la caja — nunca en blanco. */
const EMPTY_NUMBERING: RegisterNumbering = {
  factura: "",
  cotizacion: "",
}

/**
 * CRUD de cajas para una sucursal específica. Scoped por `outletId`: filtra
 * la lista global y el form de crear no pregunta sucursal (la asume).
 *
 * Vive bajo /outlets/[id] (tab "Cajas") porque Caja es siempre dependiente
 * de Sucursal en la jerarquía Company → Outlet → Register.
 */
export function RegistersTab({ outletId }: { outletId: string }) {
  const { data, isLoading } = useRegistersAdmin()
  const createRegister = useCreateRegister()
  const updateRegister = useUpdateRegister()
  const deleteRegister = useDeleteRegister()

  const [showCreate, setShowCreate] = React.useState(false)
  const [newName, setNewName] = React.useState("")
  const [newFiscal, setNewFiscal] = React.useState<RegisterFiscal>(EMPTY_FISCAL)
  const [newNumbering, setNewNumbering] = React.useState<RegisterNumbering>(EMPTY_NUMBERING)
  const [newRangeTo, setNewRangeTo] = React.useState("")

  const [editTarget, setEditTarget] = React.useState<RegisterListItem | null>(null)
  const [editName, setEditName] = React.useState("")
  const [editStatus, setEditStatus] = React.useState(true)
  const [editBlind, setEditBlind] = React.useState(false)
  const [editFiscal, setEditFiscal] = React.useState<RegisterFiscal>(EMPTY_FISCAL)
  const [editNumbering, setEditNumbering] = React.useState<RegisterNumbering>(EMPTY_NUMBERING)
  const [editRangeTo, setEditRangeTo] = React.useState("")

  const [deleteTarget, setDeleteTarget] = React.useState<RegisterListItem | null>(null)

  const registers = (data?.registers ?? []).filter((r) => r.outletId === outletId)

  function openCreate() {
    setNewName("")
    setNewFiscal(EMPTY_FISCAL)
    setNewNumbering(EMPTY_NUMBERING)
    setNewRangeTo("")
    setShowCreate(true)
  }

  function openEdit(reg: RegisterListItem) {
    setEditTarget(reg)
    setEditName(reg.name)
    setEditStatus(reg.status)
    setEditBlind(reg.blindControl)
    setEditFiscal({ ...EMPTY_FISCAL, ...reg.fiscal })
    setEditNumbering({ ...EMPTY_NUMBERING, ...reg.numbering })
    setEditRangeTo(reg.range?.facturaTo != null ? String(reg.range.facturaTo) : "")
  }

  const columns = React.useMemo<ColumnDef<RegisterListItem>[]>(() => [
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ row }) =>
        row.original.status ? (
          <Badge variant="default">Activa</Badge>
        ) : (
          <Badge variant="secondary">Inactiva</Badge>
        ),
    },
    {
      id: "timbrado",
      header: "Timbrado",
      // La caja es el punto de expedición (context/29 §1): su timbrado se
      // administra ACÁ. Facturación electrónica y la numeración fiscal solo
      // lo leen.
      cell: ({ row }) => {
        const f = row.original.fiscal
        if (!f.invoiceAuth) {
          return <span className="text-sm text-muted-foreground">Sin timbrado</span>
        }
        // Vencido = la caja NO puede facturar (el lease de numeración lo corta
        // server-side). Se avisa acá para que no se enteren recién al cobrar.
        // Comparación de strings YYYY-MM-DD: ordenan igual que las fechas y
        // evitan meter una TZ del browser en una fecha que es del tenant.
        return (
          <span className="text-sm tabular-nums">
            {f.invoiceAuth}
            {f.invoicePrefix ? ` · ${f.invoicePrefix}` : ""}
          </span>
        )
      },
    },
    {
      id: "numeracion",
      header: "Próxima factura",
      // El correlativo vive en `document_sequence` y siempre tiene valor: es el
      // número exacto que va a salir en la próxima venta de esta caja. Si hay
      // rango de timbrado cargado se muestra el techo al lado — es la única
      // pista de cuánto queda antes de que la caja deje de poder facturar.
      cell: ({ row }) => {
        const next = row.original.numbering.factura
        const to = row.original.range?.facturaTo
        return (
          <span className="text-sm tabular-nums">
            {next}
            {to != null && (
              <span className="text-muted-foreground"> / {to}</span>
            )}
          </span>
        )
      },
    },
    {
      id: "vence",
      header: "Vence",
      // Vencido = la caja NO puede facturar (el asignador de numeración lo
      // corta server-side). Se avisa acá para que no se enteren recién al
      // cobrar. Comparación de strings YYYY-MM-DD: ordenan igual que las fechas
      // y evitan meter una TZ del browser en una fecha que es del tenant.
      cell: ({ row }) => {
        const exp = row.original.fiscal.invoiceAuthExpiration
        if (!exp) return <span className="text-sm text-muted-foreground">—</span>
        const expired = exp < todayLocalISO()
        const soon = !expired && exp <= inDaysLocalISO(30)
        return (
          <div className="flex items-center gap-2">
            <span className="text-sm tabular-nums">{formatDate(exp)}</span>
            {expired && <Badge variant="destructive">Vencido</Badge>}
            {soon && <Badge variant="secondary">Por vencer</Badge>}
          </div>
        )
      },
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
              onSelect: () => setDeleteTarget(row.original),
            },
          ]}
        />
      ),
    },
  ], [])

  return (
    <>
      <DataTable
        tableId={`registers-outlet-${outletId}`}
        columns={columns}
        data={registers}
        isLoading={isLoading}
        searchPlaceholder="Buscar caja..."
        exportFileName={null}
        // Va en el toolbar de la tabla y no en una fila propia arriba: el
        // <DataTable> ya tiene su barra con el buscador y "Columnas", y una
        // segunda fila solo para un botón desperdicia el alto.
        rightToolbarSlot={
          // `size="sm" h-9` para alinear con el buscador y "Columnas" del
          // toolbar, que van a esa altura.
          <Button size="sm" className="h-9" onClick={openCreate}>
            <Plus className="size-3.5" />
            Nueva caja
          </Button>
        }
      />

      <Dialog open={showCreate} onOpenChange={setShowCreate}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle className="text-2xl font-semibold">Nueva caja</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="new-name">Nombre</Label>
              <Input
                id="new-name"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="Caja Principal"
                autoFocus
              />
            </div>

            <Separator />

            {/* El timbrado se carga en el ALTA y no en un segundo paso: la caja
                ES el punto de expedición, y el número desde el que arranca sale
                del rango que la SET autorizó para ese timbrado. Sin esto la
                caja empieza en 1, que casi nunca es lo que corresponde. */}
            <StampAndNumbering
              idPrefix="new"
              fiscal={newFiscal}
              onFiscal={(p) => setNewFiscal((f) => ({ ...f, ...p }))}
              numbering={newNumbering}
              onNumbering={(p) => setNewNumbering((n) => ({ ...n, ...p }))}
              rangeTo={newRangeTo}
              onRangeTo={setNewRangeTo}
              numberingHint="Desde qué número emite esta caja. Vacío arranca en 1."
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCreate(false)}>Cancelar</Button>
            <Button
              disabled={!newName.trim() || createRegister.isPending}
              onClick={() => {
                createRegister.mutate(
                  {
                    outletId,
                    name: newName.trim(),
                    fiscal: newFiscal,
                    numbering: newNumbering,
                    range: { facturaTo: newRangeTo },
                  },
                  {
                    onSuccess: () => { toast.success("Caja creada"); setShowCreate(false) },
                    onError: (err) => toast.error(err.message),
                  }
                )
              }}
            >
              Crear
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={editTarget !== null} onOpenChange={(o) => { if (!o) setEditTarget(null) }}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle className="text-2xl font-semibold">Editar caja</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="edit-name">Nombre</Label>
              <Input
                id="edit-name"
                value={editName}
                onChange={(e) => setEditName(e.target.value)}
              />
            </div>
            <div className="flex items-center gap-3">
              <Switch id="edit-status" checked={editStatus} onCheckedChange={setEditStatus} />
              <Label htmlFor="edit-status">Activa</Label>
            </div>
            {/* Panel-only: el POS lo lee pero su PUT de config no puede tocarlo
                (whitelist server-side) — por eso NO existe en Ajustes del POS. */}
            <div className="flex items-start gap-3">
              <Switch id="edit-blind" checked={editBlind} onCheckedChange={setEditBlind} />
              <div className="space-y-0.5">
                <Label htmlFor="edit-blind">Control de caja a ciegas</Label>
                <p className="text-xs text-muted-foreground">
                  El cajero lleva el turno sin ver los montos acumulados: el resumen y el
                  arqueo no muestran totales. Los reportes del panel no cambian.
                </p>
              </div>
            </div>

            <Separator />

            <StampAndNumbering
              idPrefix="edit"
              fiscal={editFiscal}
              onFiscal={(p) => setEditFiscal((f) => ({ ...f, ...p }))}
              numbering={editNumbering}
              onNumbering={(p) => setEditNumbering((n) => ({ ...n, ...p }))}
              rangeTo={editRangeTo}
              onRangeTo={setEditRangeTo}
              numberingHint="El próximo número que va a emitir esta caja."
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditTarget(null)}>Cancelar</Button>
            <Button
              disabled={!editName.trim() || updateRegister.isPending}
              onClick={() => {
                if (!editTarget) return
                updateRegister.mutate(
                  {
                    id: editTarget.id,
                    name: editName.trim(),
                    status: editStatus,
                    blindControl: editBlind,
                    fiscal: editFiscal,
                    numbering: editNumbering,
                    range: { facturaTo: editRangeTo },
                  },
                  {
                    onSuccess: () => { toast.success("Caja actualizada"); setEditTarget(null) },
                    onError: (err) => toast.error(err.message),
                  }
                )
              }}
            >
              Guardar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={deleteTarget !== null} onOpenChange={(o) => { if (!o) setDeleteTarget(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar caja</AlertDialogTitle>
            <AlertDialogDescription>
              {`¿Eliminar "${deleteTarget?.name}"? Si tiene transacciones históricas, se desactivará en lugar de eliminarse.`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!deleteTarget) return
                deleteRegister.mutate(deleteTarget.id, {
                  onSuccess: (res) => {
                    if (res.deleted === 'soft') {
                      toast.info("Caja desactivada — tiene transacciones históricas")
                    } else {
                      toast.success("Caja eliminada")
                    }
                    setDeleteTarget(null)
                  },
                  onError: (err) => {
                    toast.error(err.message)
                    setDeleteTarget(null)
                  },
                })
              }}
            >
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

/**
 * Timbrado + numeración de una caja. Compartido por el alta y la edición:
 * son los MISMOS datos y divergir los dos formularios fue lo que dejó el alta
 * pidiendo solo el nombre y obligando a un segundo paso para cargar el
 * timbrado que la caja necesita para poder facturar.
 */
function StampAndNumbering({
  idPrefix,
  fiscal,
  onFiscal,
  numbering,
  onNumbering,
  rangeTo,
  onRangeTo,
  numberingHint,
}: {
  idPrefix: string
  fiscal: RegisterFiscal
  onFiscal: (patch: Partial<RegisterFiscal>) => void
  numbering: RegisterNumbering
  onNumbering: (patch: Partial<RegisterNumbering>) => void
  rangeTo: string
  onRangeTo: (v: string) => void
  numberingHint: string
}) {
  const digits = (v: string) => v.replace(/\D/g, "")

  return (
    <>
      {/* Timbrado — la caja es el punto de expedición: estos datos son de la
          caja (sirven a la numeración fiscal y a la impresión, tenga o no el
          comercio facturación electrónica). */}
      <div className="space-y-3">
        <div>
          <h3 className="text-base font-semibold tracking-tight">Timbrado</h3>
          <p className="text-sm text-muted-foreground">
            El timbrado que la SET asignó a este punto de expedición.
          </p>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor={`${idPrefix}-stamp-auth`}>Número de timbrado</Label>
            <Input
              id={`${idPrefix}-stamp-auth`}
              value={fiscal.invoiceAuth}
              onChange={(e) => onFiscal({ invoiceAuth: digits(e.target.value) })}
              placeholder="12345678"
              className="tabular-nums"
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={`${idPrefix}-stamp-prefix`}>Establecimiento y punto (EEE-PPP)</Label>
            <Input
              id={`${idPrefix}-stamp-prefix`}
              value={fiscal.invoicePrefix}
              onChange={(e) =>
                onFiscal({ invoicePrefix: e.target.value.replace(/[^0-9-]/g, "").slice(0, 7) })
              }
              placeholder="001-001"
              className="tabular-nums"
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={`${idPrefix}-stamp-start`}>Vigente desde</Label>
            <Input
              id={`${idPrefix}-stamp-start`}
              type="date"
              value={fiscal.invoiceAuthStart}
              onChange={(e) => onFiscal({ invoiceAuthStart: e.target.value })}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={`${idPrefix}-stamp-exp`}>Vence</Label>
            <Input
              id={`${idPrefix}-stamp-exp`}
              type="date"
              value={fiscal.invoiceAuthExpiration}
              onChange={(e) => onFiscal({ invoiceAuthExpiration: e.target.value })}
            />
          </div>
        </div>
      </div>

      {/* Numeración — el correlativo real de la caja (`document_sequence`). Va
          aparte del timbrado: el timbrado es lo que la SET autoriza, esto es
          dónde está parado el contador hoy. */}
      <div className="space-y-3">
        <div>
          <h3 className="text-base font-semibold tracking-tight">Numeración</h3>
          <p className="text-sm text-muted-foreground">{numberingHint}</p>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor={`${idPrefix}-next-invoice`}>Próxima factura</Label>
            <Input
              id={`${idPrefix}-next-invoice`}
              value={numbering.factura}
              onChange={(e) => onNumbering({ factura: digits(e.target.value) })}
              placeholder="1"
              className="tabular-nums"
            />
          </div>
          <div className="space-y-1.5">
            {/* Techo del rango autorizado: al agotarse, la caja deja de
                facturar en vez de emitir fuera de timbrado. Vacío = sin techo
                declarado (no bloquea). */}
            <Label htmlFor={`${idPrefix}-range-to`}>Última factura del rango</Label>
            <Input
              id={`${idPrefix}-range-to`}
              value={rangeTo}
              onChange={(e) => onRangeTo(digits(e.target.value))}
              placeholder="Opcional"
              className="tabular-nums"
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={`${idPrefix}-next-quote`}>Próxima cotización</Label>
            <Input
              id={`${idPrefix}-next-quote`}
              value={numbering.cotizacion}
              onChange={(e) => onNumbering({ cotizacion: digits(e.target.value) })}
              placeholder="1"
              className="tabular-nums"
            />
          </div>
        </div>
        <p className="text-xs text-muted-foreground">
          No se puede repetir un número ya emitido en esta caja.
        </p>
      </div>
    </>
  )
}
