"use client"

import * as React from "react"
import Link from "next/link"
import { useParams } from "next/navigation"
import { ArrowLeft, Loader2, Receipt, Ban } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
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
  type PurchaseDetailItem,
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
          <StatusBadge status={purchase.status} />
          {purchase.status === 1 && <VoidPurchaseButton id={purchase.id} />}
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
