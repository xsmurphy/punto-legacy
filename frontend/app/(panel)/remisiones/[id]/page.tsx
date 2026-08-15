"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import { ArrowLeft, Printer, XCircle, Loader2 } from "lucide-react"
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

import { useRemision, useCancelRemision, REMISION_MOTIVO_LABELS } from "@/hooks/use-remisiones"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { printTicketInBrowser } from "@/lib/hardware/printers/print-in-browser"
import { buildTicketDataFromRemision } from "@/lib/hardware/printers/build-ticket-data"

function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—"
  return new Date(iso).toLocaleString("es-PY", {
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
  1: "Activa",
}

const STATUS_VARIANT: Record<number, "default" | "secondary" | "destructive" | "outline"> = {
  0: "secondary",
  1: "default",
}

export default function RemisionDetailPage() {
  const params = useParams<{ id: string }>()
  const router = useRouter()
  const id     = params.id

  const { data, isLoading } = useRemision(id)
  const { data: bootstrap }  = useBootstrap()
  const cancel                = useCancelRemision()
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
        <p className="text-muted-foreground">Remisión no encontrada.</p>
        <Button variant="ghost" className="w-fit" onClick={() => router.back()}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Volver
        </Button>
      </div>
    )
  }

  const { remision, items } = data
  const isActive = remision.status === 1
  const destination = [remision.destinationContactName, remision.destinationNote]
    .filter((s): s is string => !!s && s.trim() !== "")
    .join(" — ") || "—"

  async function handlePrint() {
    setPrinting(true)
    try {
      const ticketData = buildTicketDataFromRemision(
        { remision: { ...data!.remision, motivoLabel: REMISION_MOTIVO_LABELS[remision.motivo] ?? remision.motivo }, items: data!.items },
        bootstrap?.companyName ?? "",
      )
      await printTicketInBrowser({ docType: "delivery", data: ticketData })
    } catch {
      toast.error("No se pudo imprimir")
    } finally {
      setPrinting(false)
    }
  }

  async function handleCancel() {
    try {
      await cancel.mutateAsync({ id })
      toast.success("Remisión cancelada")
      router.refresh()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al cancelar")
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
            <h1 className="text-2xl font-semibold">
              Remisión {remision.docNumber !== null ? `Nº ${remision.docNumber}` : ""}
            </h1>
            <Badge variant={STATUS_VARIANT[remision.status]}>
              {STATUS_LABEL[remision.status] ?? "Desconocido"}
            </Badge>
          </div>
          <div className="pl-10 text-sm text-muted-foreground space-y-0.5">
            <p>Motivo: <span className="font-medium text-foreground">{REMISION_MOTIVO_LABELS[remision.motivo] ?? remision.motivo}</span></p>
            <p>Fecha de traslado: {formatDate(remision.transferDate)}</p>
            <p>Origen: <span className="font-medium text-foreground">{outletLabel(remision.outletName, remision.locationName)}</span></p>
            <p>Destino: <span className="font-medium text-foreground">{destination}</span></p>
            <p>Creada por {remision.createdByName ?? remision.createdBy}</p>
            {remision.note && <p>Nota: {remision.note}</p>}
          </div>
        </div>

        <div className="flex items-center gap-2 pl-10 sm:pl-0">
          <Button variant="outline" size="sm" onClick={handlePrint} disabled={printing}>
            {printing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Printer className="mr-2 h-4 w-4" />}
            Imprimir
          </Button>
          {isActive && (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="outline" size="sm">
                  <XCircle className="mr-2 h-4 w-4" />
                  Cancelar remisión
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Cancelar remisión</AlertDialogTitle>
                  <AlertDialogDescription>
                    Esta remisión no movió stock (el motivo la deja documental), así que cancelarla
                    solo marca el documento como anulado — no hay movimientos que revertir.
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

      {remision.status === 0 && (
        <Alert variant="destructive">
          <AlertDescription>Esta remisión fue cancelada.</AlertDescription>
        </Alert>
      )}

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Artículo</TableHead>
              <TableHead>SKU</TableHead>
              <TableHead className="text-right">Cantidad</TableHead>
              <TableHead>Nota</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {items.length === 0 && (
              <TableRow>
                <TableCell colSpan={4} className="text-center text-muted-foreground py-8">
                  Sin artículos registrados
                </TableCell>
              </TableRow>
            )}
            {items.map((item) => (
              <TableRow key={item.remisionItemId}>
                <TableCell className="font-medium">{item.name}</TableCell>
                <TableCell className="text-muted-foreground">{item.sku ?? "—"}</TableCell>
                <TableCell className="text-right">{item.qty}</TableCell>
                <TableCell className="text-muted-foreground">{item.note ?? "—"}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}
