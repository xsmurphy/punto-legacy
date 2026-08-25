"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import { ArrowLeft, ArrowLeftRight, Printer, XCircle, Loader2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"

import { useStockTransfer, useCancelStockTransfer } from "@/hooks/use-stock-transfers"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { resolveDateLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"
import { printTicketInBrowser } from "@/lib/hardware/printers/print-in-browser"
import { buildTicketDataFromStockTransfer } from "@/lib/hardware/printers/build-ticket-data"
import { formatMoney as _formatMoney } from "@/lib/format"

function formatMoney(v: number): string {
  return _formatMoney(v, undefined)
}

function formatDate(
  iso: string | null | undefined,
  config: TenantLocaleConfig | null | undefined,
): string {
  if (!iso) return "—"
  return new Date(iso).toLocaleString(resolveDateLocale(config), {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
}

function outletLabel(outletName: string, locationName: string | null | undefined): string {
  return locationName ? `${outletName} → ${locationName}` : outletName
}

const STATUS_LABEL: Record<number, string> = {
  0: "Cancelada",
  1: "Completada",
}

const STATUS_VARIANT: Record<number, "default" | "secondary" | "destructive" | "outline"> = {
  0: "secondary",
  1: "default",
}

export default function StockTransferDetailPage() {
  const params = useParams<{ id: string }>()
  const router = useRouter()
  const id     = params.id

  const { data, isLoading } = useStockTransfer(id)
  const { data: bootstrap } = useBootstrap()
  const cancel               = useCancelStockTransfer()
  const [printing, setPrinting] = React.useState(false)

  if (isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-4 w-48" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  if (!data) {
    return (
      <div className="flex flex-col gap-4">
        <p className="text-muted-foreground">Transferencia no encontrada.</p>
        <Button variant="ghost" className="w-fit" onClick={() => router.back()}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Volver
        </Button>
      </div>
    )
  }

  const { transfer, items } = data
  const isCompleted = transfer.status === 1

  async function handleCancel() {
    try {
      await cancel.mutateAsync({ id })
      toast.success("Transferencia cancelada. Los movimientos de stock fueron revertidos.")
      router.refresh()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al cancelar")
    }
  }

  // Reporte de transferencias con formato de Nota de Remisión (pedido owner,
  // context/_feature-requests.md 2026-07-31) — mismo docType "delivery" y
  // mismo motor de impresión que RemisiónDetailPage, ver context/42.
  async function handlePrint() {
    setPrinting(true)
    try {
      const ticketData = buildTicketDataFromStockTransfer(data!, bootstrap?.companyName ?? "")
      await printTicketInBrowser({ docType: "delivery", data: ticketData })
    } catch {
      toast.error("No se pudo imprimir")
    } finally {
      setPrinting(false)
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="icon" onClick={() => router.back()}>
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <h1 className="text-2xl font-semibold">Transferencia de stock</h1>
            <Badge variant={STATUS_VARIANT[transfer.status]}>
              {STATUS_LABEL[transfer.status] ?? "Desconocido"}
            </Badge>
          </div>
          <div className="pl-10 text-sm text-muted-foreground space-y-0.5">
            <p>Creada: {formatDate(transfer.createdAt, bootstrap)} por {transfer.createdByName ?? transfer.createdBy}</p>
            <p>Origen: <span className="font-medium text-foreground">{outletLabel(transfer.fromOutletName, transfer.fromLocationName)}</span></p>
            <p>Destino: <span className="font-medium text-foreground">{outletLabel(transfer.toOutletName, transfer.toLocationName)}</span></p>
            {transfer.note && <p>Nota: {transfer.note}</p>}
          </div>
        </div>

        <div className="flex items-center gap-2 pl-10 sm:pl-0">
          <Button variant="outline" size="sm" onClick={handlePrint} disabled={printing}>
            {printing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Printer className="mr-2 h-4 w-4" />}
            Imprimir
          </Button>
          {isCompleted && (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="outline" size="sm">
                  <XCircle className="mr-2 h-4 w-4" />
                  Cancelar transferencia
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Cancelar transferencia</AlertDialogTitle>
                  <AlertDialogDescription>
                    Esta acción genera movimientos de reversa en el ledger de stock y puede resultar
                    en stock negativo si el inventario ya fue utilizado. Los movimientos quedan
                    registrados para auditoría.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Volver</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={handleCancel}
                    disabled={cancel.isPending}
                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                  >
                    {cancel.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    Confirmar cancelación
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          )}
        </div>
      </header>

      {transfer.status === 0 && (
        <Alert variant="destructive">
          <AlertDescription>Esta transferencia fue cancelada. Los movimientos de stock fueron revertidos.</AlertDescription>
        </Alert>
      )}

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Artículo</TableHead>
              <TableHead>SKU</TableHead>
              <TableHead className="text-right">Cantidad</TableHead>
              <TableHead className="text-right">Costo unitario (snapshot)</TableHead>
              <TableHead className="text-right">Valor transferido</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {items.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="text-center text-muted-foreground py-8">
                  Sin artículos registrados
                </TableCell>
              </TableRow>
            )}
            {items.map((item) => (
              <TableRow key={item.stockTransferItemId}>
                <TableCell className="font-medium">{item.name}</TableCell>
                <TableCell className="text-muted-foreground">{item.sku ?? "—"}</TableCell>
                <TableCell className="text-right">{item.qty}</TableCell>
                <TableCell className="text-right">{formatMoney(item.unitCost)}</TableCell>
                <TableCell className="text-right">{formatMoney(item.qty * item.unitCost)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}
