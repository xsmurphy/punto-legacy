"use client"

import * as React from "react"
import Link from "next/link"
import { useParams } from "next/navigation"
import { ArrowLeft, Loader2, Receipt, Ban, FileMinus } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
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
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Separator } from "@/components/ui/separator"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  usePurchase,
  useVoidPurchase,
  useCreatePurchaseCreditNote,
  type PurchaseDetail,
  type PurchaseDetailItem,
  type PurchaseCreditNote,
  type CreditNoteRefundMode,
  type PurchaseCondition,
} from "@/hooks/use-purchases"
import { formatMoney } from "@/lib/format"

/**
 * Detalle de una compra — solo lectura.
 *
 * La edición de compras no está disponible en esta iteración (el
 * `PurchasesService` PHP solo tiene `create`/`find`). Esta página sirve para
 * consultar el detalle de líneas y datos fiscales de una compra registrada.
 *
 * Acceso: desde el listado en `/reports/purchases` vía click en la fila.
 */
export default function PurchaseDetailPage() {
  const params = useParams()
  const id = typeof params.id === "string" ? params.id : (params.id?.[0] ?? "")

  const { data: bootstrap } = useBootstrap()
  const { data: purchase, isLoading, error } = usePurchase(id || null)

  if (isLoading) {
    return (
      <div className="flex h-40 items-center justify-center text-muted-foreground">
        <Loader2 className="mr-2 size-5 animate-spin" />
        Cargando compra…
      </div>
    )
  }

  if (error || !purchase) {
    return (
      <div className="flex flex-col gap-4">
        <BackButton />
        <div className="flex h-40 flex-col items-center justify-center gap-2 text-muted-foreground">
          <Receipt className="size-8" />
          <p className="text-sm">Compra no encontrada o sin acceso.</p>
        </div>
      </div>
    )
  }

  const prefix = purchase.invoicePrefix
    ? purchase.invoicePrefix.split(";").filter(Boolean).pop() ?? ""
    : ""
  const invoiceLabel =
    purchase.invoiceNo !== null
      ? `${prefix ? prefix + "-" : ""}${String(purchase.invoiceNo).padStart(7, "0")}`
      : null

  const subtotal = purchase.details.reduce(
    (acc: number, d: PurchaseDetailItem) => acc + d.price,
    0,
  )

  // Datos de cheque (F1, context/30) — solo presentes si el método de pago
  // de la compra tiene systemKey='check' e identifier (nro de cheque).
  const checkLine = purchase.paymentType?.find((p) => p.identifier)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackButton />
          <h1 className="text-2xl font-semibold">
            {invoiceLabel ? `Compra ${invoiceLabel}` : "Detalle de compra"}
          </h1>
          <p className="text-sm text-muted-foreground">
            {purchase.supplierName
              ? `Proveedor: ${purchase.supplierName}`
              : "Sin proveedor registrado"}
          </p>
        </div>
        <div className="flex items-center gap-3">
          {/* Modalidad (contado/crédito): el dato ya venía en el payload y se
              usaba para habilitar el pago a crédito, pero no se mostraba en
              ninguna parte — el listado sí la muestra y el detalle no, así que
              una compra a crédito parecía contado (reporte del owner). El
              vencimiento la insinuaba, pero solo si la compra tenía uno. */}
          <ConditionBadge condition={purchase.condition} />
          <StatusBadge status={purchase.status} />
          {purchase.status === 1 && (
            <>
              <CreditNoteDialog purchase={purchase} />
              <VoidPurchaseButton id={purchase.id} />
            </>
          )}
        </div>
      </header>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <InfoCard title="Datos generales">
          <InfoRow label="Sucursal" value={purchase.outletName ?? "—"} />
          <InfoRow label="Fecha" value={formatDate(purchase.date)} />
          {purchase.dueDate && (
            <InfoRow label="Vencimiento" value={formatDate(purchase.dueDate)} />
          )}
          {invoiceLabel && (
            <InfoRow label="Factura" value={invoiceLabel} mono />
          )}
          <InfoRow label="Usuario" value={purchase.userName ?? "—"} />
          <InfoRow
            label="Método de pago"
            value={paymentMethodLabel(purchase.paymentType)}
          />
          {checkLine && (
            <>
              <InfoRow label="Nro de cheque" value={checkLine.identifier ?? "—"} />
              {checkLine.bankName && <InfoRow label="Banco" value={checkLine.bankName} />}
              {checkLine.dueDate && (
                <InfoRow label="Vencimiento cheque" value={formatDate(checkLine.dueDate)} />
              )}
            </>
          )}
        </InfoCard>

        <InfoCard title="Totales">
          <InfoRow
            label="Subtotal"
            value={formatMoney(subtotal, bootstrap)}
            mono
          />
          <InfoRow
            label="Impuestos"
            value={formatMoney(purchase.tax, bootstrap)}
            mono
          />
          {purchase.discount > 0 && (
            <InfoRow
              label="Descuento"
              value={`- ${formatMoney(purchase.discount, bootstrap)}`}
              mono
            />
          )}
          <Separator className="my-1" />
          <InfoRow
            label="Total"
            value={formatMoney(purchase.total, bootstrap)}
            mono
            bold
          />
        </InfoCard>

        {purchase.note && (
          <InfoCard title="Nota">
            <p className="text-sm text-muted-foreground">{purchase.note}</p>
          </InfoCard>
        )}
      </div>

      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base">Líneas</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Producto / Descripción</TableHead>
                <TableHead className="text-right w-24">Cant.</TableHead>
                <TableHead className="text-right w-32">P. Unit.</TableHead>
                <TableHead className="text-right w-32">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {purchase.details.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={4}
                    className="text-center text-muted-foreground py-6"
                  >
                    Sin líneas registradas
                  </TableCell>
                </TableRow>
              ) : (
                purchase.details.map((d: PurchaseDetailItem, i: number) => {
                  const unitPrice =
                    (Number(d.qty) || 0) > 0
                      ? d.price / Number(d.qty)
                      : d.price
                  return (
                    <TableRow key={i}>
                      <TableCell className="font-medium">
                        {d.title || "(sin descripción)"}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {Number(d.qty)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMoney(unitPrice, bootstrap)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMoney(d.price, bootstrap)}
                      </TableCell>
                    </TableRow>
                  )
                })
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {purchase.creditNotes.length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Notas de crédito</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Modo</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead className="text-right w-32">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {purchase.creditNotes.map((cn: PurchaseCreditNote) => (
                  <TableRow key={cn.id}>
                    <TableCell className="tabular-nums text-sm">
                      {formatDate(cn.date)}
                    </TableCell>
                    <TableCell>
                      {cn.refundMode === "cash" ? "Devolución de dinero" : "A cuenta del saldo"}
                    </TableCell>
                    <TableCell>
                      {cn.status === 6 ? (
                        <Badge variant="destructive">Anulada</Badge>
                      ) : (
                        <Badge variant="secondary">Activa</Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatMoney(cn.total, bootstrap)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </div>
  )
}

// ── Sub-componentes ──────────────────────────────────────────────────────────

function BackButton() {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href="/reports/purchases">
        <ArrowLeft className="size-3.5" />
        Volver al historial
      </Link>
    </Button>
  )
}

/**
 * Modalidad de la compra. Es dato distinto del estado: una compra a crédito
 * puede estar Completa (recibida) y seguir impaga. El listado ya la mostraba;
 * el detalle no.
 */
function ConditionBadge({ condition }: { condition: PurchaseCondition }) {
  return (
    <Badge variant="outline">{condition === "credit" ? "Crédito" : "Contado"}</Badge>
  )
}

function StatusBadge({ status }: { status: number }) {
  if (status === 1) return <Badge variant="secondary">Completa</Badge>
  if (status === 0) return <Badge variant="outline">Orden</Badge>
  if (status === 6) return <Badge variant="destructive">Anulada</Badge>
  return <Badge variant="outline">{status}</Badge>
}

/**
 * Botón de anulación de compra. Confirma con AlertDialog antes de disparar el
 * soft-void (revierte stock + marca status=6 en el backend). La invalidación
 * del hook re-fetchea el detalle, que pasa a mostrar el badge "Anulada".
 */
function VoidPurchaseButton({ id }: { id: string }) {
  const voidPurchase = useVoidPurchase()
  const [open, setOpen] = React.useState(false)

  const onConfirm = async () => {
    try {
      await voidPurchase.mutateAsync(id)
      toast.success("Compra anulada")
      setOpen(false)
    } catch (err) {
      toast.error("No se pudo anular la compra", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      <AlertDialogTrigger asChild>
        <Button variant="destructive" size="sm">
          <Ban className="size-3.5" />
          Anular compra
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>¿Anular esta compra?</AlertDialogTitle>
          <AlertDialogDescription>
            Esta acción revierte el stock ingresado por la compra. La compra
            quedará marcada como anulada y se excluirá de los reportes de
            egresos. No se puede deshacer.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={voidPurchase.isPending}>
            Cancelar
          </AlertDialogCancel>
          <AlertDialogAction
            onClick={(e) => {
              e.preventDefault()
              onConfirm()
            }}
            disabled={voidPurchase.isPending}
          >
            {voidPurchase.isPending && (
              <Loader2 className="mr-1.5 size-4 animate-spin" />
            )}
            Anular compra
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}

/**
 * Dialog de emisión de nota de crédito de compra — total o parcial. Caso
 * real: compramos N unidades, algunas vienen dañadas, el proveedor nos
 * acredita. Solo lista líneas con `itemId` (las líneas de gasto libre no
 * tienen fila en `itemSold` y no se pueden acreditar por ítem).
 */
function CreditNoteDialog({ purchase }: { purchase: PurchaseDetail }) {
  const { data: bootstrap } = useBootstrap()
  const createCreditNote = useCreatePurchaseCreditNote()
  const [open, setOpen] = React.useState(false)
  const [qtyByItem, setQtyByItem] = React.useState<Record<string, number>>({})
  const [refundMode, setRefundMode] = React.useState<CreditNoteRefundMode>("cash")
  const [affectsStock, setAffectsStock] = React.useState(true)
  const [note, setNote] = React.useState("")

  const canUseCredit = purchase.condition === "credit" && !purchase.complete

  const lines = React.useMemo(
    () =>
      purchase.details
        .filter((d) => d.itemId)
        .map((d) => {
          const qty = Number(d.qty) || 0
          const unitPrice = qty > 0 ? d.price / qty : d.price
          const alreadyCredited = purchase.creditedQty[d.itemId] ?? 0
          const max = Math.max(0, qty - alreadyCredited)
          return { itemId: d.itemId, title: d.title || "(sin descripción)", unitPrice, max }
        }),
    [purchase.details, purchase.creditedQty],
  )

  function resetAll() {
    setQtyByItem({})
    setRefundMode("cash")
    setAffectsStock(true)
    setNote("")
  }

  function handleOpenChange(next: boolean) {
    setOpen(next)
    if (!next) resetAll()
  }

  function updateQty(itemId: string, max: number, raw: string) {
    const parsed = Math.min(Math.max(0, Number(raw) || 0), max)
    setQtyByItem((prev) => ({ ...prev, [itemId]: parsed }))
  }

  const itemsToCredit = lines
    .map((l) => ({ itemId: l.itemId, qty: qtyByItem[l.itemId] ?? 0, unitPrice: l.unitPrice }))
    .filter((l) => l.qty > 0)

  const total = itemsToCredit.reduce((sum, l) => sum + l.unitPrice * l.qty, 0)

  async function handleSubmit() {
    if (itemsToCredit.length === 0) {
      toast.error("Ingresá al menos una cantidad a acreditar.")
      return
    }
    try {
      await createCreditNote.mutateAsync({
        parentTransactionId: purchase.id,
        items: itemsToCredit.map(({ itemId, qty }) => ({ itemId, qty })),
        refundMode,
        affectsStock,
        note: note.trim() || undefined,
      })
      toast.success("Nota de crédito emitida")
      handleOpenChange(false)
    } catch (err) {
      toast.error("No se pudo emitir la nota de crédito", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <FileMinus className="size-3.5" />
          Nota de crédito
        </Button>
      </DialogTrigger>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Nota de crédito de compra</DialogTitle>
          <DialogDescription>
            Registrá lo que el proveedor te acreditó — total o parcial.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          <div className="rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Producto</TableHead>
                  <TableHead className="text-right w-28">Disponible</TableHead>
                  <TableHead className="text-right w-28">A acreditar</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.map((l) => (
                  <TableRow key={l.itemId}>
                    <TableCell className="max-w-[220px]">
                      <p className="truncate text-sm font-medium">{l.title}</p>
                      <p className="text-xs text-muted-foreground tabular-nums">
                        {formatMoney(l.unitPrice, bootstrap)} c/u
                      </p>
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-sm">{l.max}</TableCell>
                    <TableCell className="text-right">
                      <Input
                        type="number"
                        min={0}
                        max={l.max}
                        step={1}
                        disabled={l.max <= 0}
                        value={qtyByItem[l.itemId] || ""}
                        onChange={(e) => updateQty(l.itemId, l.max, e.target.value)}
                        placeholder="0"
                        className="h-9 w-20 text-right tabular-nums"
                      />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-1.5">
              <Label>Forma de acreditación</Label>
              <Select value={refundMode} onValueChange={(v) => setRefundMode(v as CreditNoteRefundMode)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="cash">Devolución de dinero</SelectItem>
                  <SelectItem value="credit" disabled={!canUseCredit}>
                    A cuenta del saldo
                  </SelectItem>
                </SelectContent>
              </Select>
              {!canUseCredit && (
                <p className="text-xs text-muted-foreground">
                  &ldquo;A cuenta del saldo&rdquo; solo aplica a compras a crédito pendientes.
                </p>
              )}
            </div>

            <div className="flex flex-col gap-1.5">
              <Label>Devuelve mercadería</Label>
              <div className="flex h-9 items-center gap-2">
                <Switch checked={affectsStock} onCheckedChange={setAffectsStock} />
                <span className="text-sm text-muted-foreground">
                  {affectsStock ? "Sí — resta stock" : "No — bonificación"}
                </span>
              </div>
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label>Nota (opcional)</Label>
            <Textarea
              placeholder="Motivo de la nota de crédito…"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={2}
              className="resize-none"
            />
          </div>

          <div className="flex items-center justify-between rounded-lg bg-muted/40 px-4 py-3">
            <span className="text-sm font-medium">Total a acreditar</span>
            <span className="text-lg font-bold tabular-nums">{formatMoney(total, bootstrap)}</span>
          </div>
        </div>

        <DialogFooter>
          <Button
            onClick={handleSubmit}
            disabled={createCreditNote.isPending || itemsToCredit.length === 0}
          >
            {createCreditNote.isPending && <Loader2 className="mr-1.5 size-4 animate-spin" />}
            Emitir nota de crédito
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function InfoCard({
  title,
  children,
}: {
  title: string
  children: React.ReactNode
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground uppercase tracking-wide">
          {title}
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-1.5">{children}</CardContent>
    </Card>
  )
}

function InfoRow({
  label,
  value,
  mono,
  bold,
}: {
  label: string
  value: string
  mono?: boolean
  bold?: boolean
}) {
  return (
    <div className="flex items-center justify-between gap-2 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className={`${mono ? "tabular-nums font-mono text-xs" : ""} ${bold ? "font-semibold text-base" : ""}`}>
        {value}
      </span>
    </div>
  )
}

/**
 * Label legible del método de pago. Desde la mig 102 el backend resuelve
 * `name` server-side (taxonomy paymentMethod real, ver PurchasesService::
 * create) — el mapa de slugs solo cubre compras viejas sin `name` guardado.
 */
const LEGACY_PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash: "Efectivo",
  card: "Tarjeta",
  transfer: "Transferencia",
  check: "Cheque",
  credit: "A crédito",
}

function paymentMethodLabel(
  paymentType: PurchaseDetail["paymentType"],
): string {
  const line = paymentType?.[0]
  if (!line) return "—"
  if (line.name) return line.name
  return LEGACY_PAYMENT_METHOD_LABELS[line.type] ?? line.type
}

function formatDate(s: string): string {
  try {
    const d = new Date(s)
    const dd = String(d.getDate()).padStart(2, "0")
    const mm = String(d.getMonth() + 1).padStart(2, "0")
    return `${dd}/${mm}/${d.getFullYear()}`
  } catch {
    return s
  }
}
