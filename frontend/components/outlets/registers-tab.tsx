"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Pencil, Plus, Trash2, Unlock } from "lucide-react"
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { formatDate } from "@/lib/format-date"
// Formateador único del correlativo (mig 159) — el mismo que usa el ticket.
// El preview del form y la columna del listado tienen que mostrar EXACTAMENTE
// lo que va a salir impreso.
import { formatDocumentNumber, DEFAULT_PAD_WIDTH } from "@/lib/documents/format-document-number"
import {
  useRegistersAdmin,
  useCreateRegister,
  useUpdateRegister,
  useDeleteRegister,
  type RegisterFiscal,
  type RegisterListItem,
  type RegisterNumbering,
  type RegisterPadWidth,
} from "@/hooks/use-registers-admin"
import {
  useRegisterLeases,
  useReleaseRegisterLease,
  type RegisterLeaseHolder,
} from "@/hooks/use-register-leases"

/** Tenencia (register_lease) usa timestamptz genuino, no el naive-local de
 *  venta/caja — mismo caso que `pairedAt`/`lastSeenAt` de `device` en
 *  settings/devices/page.tsx, que formatea con `new Date(iso)` directo en vez
 *  de `parseNaive` (ese asume offset stripeado, acá el offset es real). */
function niceLeaseDate(iso: string): string {
  return new Intl.DateTimeFormat("es", {
    day: "numeric", month: "short",
    hour: "2-digit", minute: "2-digit",
  }).format(new Date(iso))
}

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

/** Ancho por defecto de una caja nueva: formato fiscal PY `001-001-0002129`. */
const EMPTY_PAD_WIDTH: RegisterPadWidth = {
  factura: DEFAULT_PAD_WIDTH,
  cotizacion: DEFAULT_PAD_WIDTH,
}

/**
 * Opciones del selector de dígitos. El CHECK de la mig 159 permite 1..12, pero
 * el form ofrece 4..10: por debajo de 4 no hay talonario real y por encima de
 * 10 tampoco. Un valor fuera de la lista (cargado por API) igual se respeta —
 * el `<Select>` lo muestra porque se agrega dinámicamente abajo.
 */
const PAD_WIDTH_OPTIONS = [4, 5, 6, 7, 8, 9, 10]

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

  // Tenencia de caja (context/29 F4) — endpoint propio, aparte del CRUD de
  // caja. Se pide scopeada a esta sucursal, igual que `registers` de abajo.
  const { data: leasesData, isLoading: leasesLoading } = useRegisterLeases(outletId)
  const releaseLease = useReleaseRegisterLease()
  const leaseByRegisterId = React.useMemo(() => {
    const map = new Map<string, RegisterLeaseHolder | null>()
    for (const item of leasesData?.leases ?? []) {
      map.set(item.registerId, item.lease)
    }
    return map
  }, [leasesData])

  const [releaseTarget, setReleaseTarget] = React.useState<{
    registerName: string
    lease: RegisterLeaseHolder
  } | null>(null)

  const [showCreate, setShowCreate] = React.useState(false)
  const [newName, setNewName] = React.useState("")
  const [newFiscal, setNewFiscal] = React.useState<RegisterFiscal>(EMPTY_FISCAL)
  const [newNumbering, setNewNumbering] = React.useState<RegisterNumbering>(EMPTY_NUMBERING)
  const [newPadWidth, setNewPadWidth] = React.useState<RegisterPadWidth>(EMPTY_PAD_WIDTH)
  const [newRangeTo, setNewRangeTo] = React.useState("")

  const [editTarget, setEditTarget] = React.useState<RegisterListItem | null>(null)
  const [editName, setEditName] = React.useState("")
  const [editStatus, setEditStatus] = React.useState(true)
  const [editBlind, setEditBlind] = React.useState(false)
  const [editFiscal, setEditFiscal] = React.useState<RegisterFiscal>(EMPTY_FISCAL)
  const [editNumbering, setEditNumbering] = React.useState<RegisterNumbering>(EMPTY_NUMBERING)
  const [editPadWidth, setEditPadWidth] = React.useState<RegisterPadWidth>(EMPTY_PAD_WIDTH)
  const [editRangeTo, setEditRangeTo] = React.useState("")

  const [deleteTarget, setDeleteTarget] = React.useState<RegisterListItem | null>(null)

  const registers = (data?.registers ?? []).filter((r) => r.outletId === outletId)

  function openCreate() {
    setNewName("")
    setNewFiscal(EMPTY_FISCAL)
    setNewNumbering(EMPTY_NUMBERING)
    setNewPadWidth(EMPTY_PAD_WIDTH)
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
    setEditPadWidth({ ...EMPTY_PAD_WIDTH, ...reg.padWidth })
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
        const reg = row.original
        const to = reg.range?.facturaTo
        // Se muestra EXACTAMENTE como va a salir impreso (mig 159): mismo
        // formateador que el ticket y que el detalle. Antes se pintaba el
        // entero pelado y no coincidía con la factura.
        const next = formatDocumentNumber(
          reg.numbering.factura,
          reg.fiscal.invoicePrefix,
          reg.padWidth?.factura,
        )
        return (
          <span className="text-sm tabular-nums">
            {next || "—"}
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
      id: "tenencia",
      header: "Tenencia",
      // context/29 F4 — quién tiene la caja tomada ahora mismo (register_lease)
      // y desde cuándo. Sin esto una caja trabada (tablet rota, sin tenedor
      // visible) no tiene ninguna salida: la tenencia ya no vence sola.
      cell: ({ row }) => {
        // Mientras carga, NO afirmar "Libre" — sería un falso "sin tenedor"
        // por 1-2s en cada apertura del tab. Neutral hasta tener el dato real.
        if (leasesLoading) {
          return <span className="text-sm text-muted-foreground">—</span>
        }
        const lease = leaseByRegisterId.get(row.original.id)
        if (!lease) {
          return <Badge variant="outline">Libre</Badge>
        }
        return (
          <div className="flex flex-col gap-0.5">
            <span className="text-sm font-medium">{lease.deviceName || "Dispositivo sin nombre"}</span>
            <span className="text-xs text-muted-foreground">desde {niceLeaseDate(lease.takenAt)}</span>
            {lease.orphaned && (
              <Badge variant="destructive" className="w-fit">
                Ya no está en esta caja
              </Badge>
            )}
          </div>
        )
      },
    },
    {
      id: "hotkeys",
      header: "Hotkeys",
      // Diagnóstico del incidente 2026-08-18 (context/29): un hotkey
      // configurado antes de que existiera el filtro de sucursal puede
      // apuntar a un ítem que la caja ya no ve. El POS lo degrada solo a slot
      // vacío (no rompe la grilla), pero acá el admin ve CUÁL caja conviene
      // reabrir y reconfigurar sin tener que entrar a cada una a mirar.
      cell: ({ row }) => {
        const n = row.original.orphanHotkeys
        if (n === 0) return <span className="text-sm text-muted-foreground">—</span>
        return (
          <Badge variant="secondary" className="text-amber-700 dark:text-amber-400">
            {n} sin catálogo
          </Badge>
        )
      },
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => {
        const lease = leaseByRegisterId.get(row.original.id)
        return (
          <RowActions
            actions={[
              { label: "Editar", icon: Pencil, onSelect: () => openEdit(row.original) },
              {
                label: "Liberar caja",
                icon: Unlock,
                variant: "destructive",
                hidden: !lease,
                onSelect: () => {
                  if (lease) setReleaseTarget({ registerName: row.original.name, lease })
                },
              },
              {
                label: "Eliminar",
                icon: Trash2,
                variant: "destructive",
                onSelect: () => setDeleteTarget(row.original),
              },
            ]}
          />
        )
      },
    },
  ], [leaseByRegisterId, leasesLoading])

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
              padWidth={newPadWidth}
              onPadWidth={(p) => setNewPadWidth((w) => ({ ...w, ...p }))}
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
                    padWidth: newPadWidth,
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
              padWidth={editPadWidth}
              onPadWidth={(p) => setEditPadWidth((w) => ({ ...w, ...p }))}
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
                    padWidth: editPadWidth,
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

      {/* context/29 F4 — liberar tenencia es irreversible sobre numeración
          fiscal: corta al dispositivo que la tiene (anula sus números
          arrendados no consumidos) para que otro pueda tomar la caja. El
          nombre del dispositivo va explícito en el texto para que el admin
          sepa a quién está desconectando antes de confirmar. */}
      <AlertDialog open={releaseTarget !== null} onOpenChange={(o) => { if (!o) setReleaseTarget(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Liberar {releaseTarget ? `"${releaseTarget.registerName}"` : "caja"}</AlertDialogTitle>
            <AlertDialogDescription>
              {releaseTarget && (
                <>
                  <strong>{releaseTarget.lease.deviceName || "El dispositivo sin nombre"}</strong> tiene esta caja
                  tomada desde el {niceLeaseDate(releaseTarget.lease.takenAt)} y va a dejar de poder facturar con
                  ella inmediatamente. Cualquier número que haya arrendado y no haya usado todavía queda anulado.
                  Esta acción no se puede deshacer.
                </>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={releaseLease.isPending}
              onClick={() => {
                if (!releaseTarget) return
                releaseLease.mutate(
                  { registerLeaseId: releaseTarget.lease.registerLeaseId },
                  {
                    onSuccess: (res) => {
                      toast.success(res.alreadyReleased ? "La caja ya estaba libre" : "Caja liberada")
                      setReleaseTarget(null)
                    },
                    onError: (err) => {
                      toast.error(err.message)
                      setReleaseTarget(null)
                    },
                  },
                )
              }}
            >
              Liberar caja
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
  padWidth,
  onPadWidth,
  rangeTo,
  onRangeTo,
  numberingHint,
}: {
  idPrefix: string
  fiscal: RegisterFiscal
  onFiscal: (patch: Partial<RegisterFiscal>) => void
  numbering: RegisterNumbering
  onNumbering: (patch: Partial<RegisterNumbering>) => void
  padWidth: RegisterPadWidth
  onPadWidth: (patch: Partial<RegisterPadWidth>) => void
  rangeTo: string
  onRangeTo: (v: string) => void
  numberingHint: string
}) {
  const digits = (v: string) => v.replace(/\D/g, "")

  // El ancho guardado puede venir fuera de las opciones ofrecidas (cargado por
  // API, o backfilleado del legacy `registerDocsLeadingZeros`). Se agrega a la
  // lista en vez de descartarlo: el `<Select>` sin match dejaría el trigger en
  // blanco y el primer guardado pisaría en silencio el ancho del tenant.
  const padWidthChoices = React.useMemo(() => {
    const set = new Set(PAD_WIDTH_OPTIONS)
    if (padWidth.factura > 0) set.add(padWidth.factura)
    return [...set].sort((a, b) => a - b)
  }, [padWidth.factura])

  // Preview con el número real que la caja va a emitir. Vacío (alta sin
  // número cargado) → se previsualiza el 1, que es donde arranca la secuencia.
  const invoicePreview = formatDocumentNumber(
    numbering.factura || "1",
    fiscal.invoicePrefix,
    padWidth.factura,
  )

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
          {/* Los ceros a la izquierda son FORMATO, no parte del número: el
              correlativo se guarda como entero (por eso tipear "00002129"
              guardaba 2129). Acá se declara cuántos dígitos ocupa impreso. */}
          <div className="space-y-1.5">
            <Label htmlFor={`${idPrefix}-pad-width`}>Dígitos de la factura</Label>
            <Select
              value={String(padWidth.factura)}
              onValueChange={(v) => onPadWidth({ factura: Number(v) })}
            >
              <SelectTrigger id={`${idPrefix}-pad-width`}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {padWidthChoices.map((w) => (
                  <SelectItem key={w} value={String(w)}>
                    {w} dígitos
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground tabular-nums">
              Se imprime {invoicePreview}
            </p>
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
