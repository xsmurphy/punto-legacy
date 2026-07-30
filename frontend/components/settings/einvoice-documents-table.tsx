"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { FileText, RefreshCw, Ban, Receipt } from "lucide-react"
import { toast } from "sonner"

import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { EmptyState } from "@/components/empty-state"

import {
  einvoiceKudeUrl,
  useCancelEinvoiceDocument,
  useEinvoiceDocuments,
  useReconcileEinvoiceDocuments,
  useRetryEinvoiceDocument,
} from "@/hooks/use-einvoice"
import { usePermission } from "@/hooks/use-permissions"
import { formatCurrencyAmount } from "@/lib/format-money"
import { formatDateTime } from "@/lib/format-date"
import type { EInvoiceDocument, EInvoiceDocumentStatus } from "@/lib/types/einvoice"

const STATUS_LABEL: Record<EInvoiceDocumentStatus, string> = {
  pending: "Pendiente",
  sending: "Enviando",
  issued: "Emitido",
  error: "Error",
  cancelled: "Cancelado",
  skipped: "Omitido",
}

function StatusCell({ doc }: { doc: EInvoiceDocument }) {
  if (doc.stuck) {
    // Trabado en `sending` — nadie lo va a reintentar solo (no es seguro,
    // ver EInvoiceService::documents), necesita revisión manual. Se marca
    // distinto de "Enviando" normal para que no se confunda con "está en
    // curso, esperá" cuando en realidad quedó huérfano.
    return <Badge variant="destructive">Trabado — revisar</Badge>
  }
  switch (doc.status) {
    case "issued":
      return <Badge>Emitido</Badge>
    case "error":
      return <Badge variant="destructive">Error</Badge>
    case "cancelled":
      return <Badge variant="secondary">Cancelado</Badge>
    case "sending":
      return <Badge variant="outline">Enviando</Badge>
    case "skipped":
      return <Badge variant="secondary">Omitido</Badge>
    default:
      return <Badge variant="outline">Pendiente</Badge>
  }
}

function formatCreatedAt(iso: string | null): string {
  if (!iso) return "—"
  try {
    return formatDateTime(iso)
  } catch {
    return "—"
  }
}

/**
 * Card "Documentos" — listado de la operación de facturación electrónica
 * (F2): documentos ya encolados/emitidos, no la conexión de la cuenta (esa
 * es la card "Conexión" de arriba, F0). Usa el <DataTable> reusable del
 * proyecto (context/14-ui-conventions.md — convención obligatoria para
 * listados) en vez de una tabla one-off.
 */
export function EInvoiceDocumentsCard() {
  const canManage = usePermission("einvoice.manage")

  const [status, setStatus] = React.useState<string>("all")
  const [from, setFrom] = React.useState("")
  const [to, setTo] = React.useState("")

  // Filtros de fecha/estado van al backend (WHERE sobre la tabla completa);
  // la búsqueda libre por CDC/cliente la resuelve el <DataTable> del lado
  // del cliente sobre la página traída (su search box global ya cubre eso
  // — no duplicar un segundo input de búsqueda server-side). `pageSize` al
  // tope (200, cap del backend) porque el paginado visible es el del
  // DataTable, no el del server — un listado de FE de un comercio no
  // debería superar eso en una ventana de fechas razonable.
  const filters = React.useMemo(
    () => ({
      status: status === "all" ? undefined : (status as EInvoiceDocumentStatus | "stuck"),
      from: from || undefined,
      to: to || undefined,
      page: 1,
      pageSize: 200,
    }),
    [status, from, to],
  )

  const { data, isLoading } = useEinvoiceDocuments(filters)
  const retry = useRetryEinvoiceDocument()
  const cancel = useCancelEinvoiceDocument()
  const reconcile = useReconcileEinvoiceDocuments()

  const [cancelTarget, setCancelTarget] = React.useState<EInvoiceDocument | null>(null)
  const [cancelReason, setCancelReason] = React.useState("")

  function handleRetry(doc: EInvoiceDocument) {
    retry.mutate(doc.id, {
      onSuccess: () => toast.success("Documento reencolado para reintento."),
      onError: (err) => toast.error("No se pudo reintentar", { description: err.message }),
    })
  }

  function openCancel(doc: EInvoiceDocument) {
    setCancelTarget(doc)
    setCancelReason("")
  }

  function confirmCancel() {
    if (!cancelTarget) return
    const reason = cancelReason.trim()
    if (reason === "") {
      toast.error("Ingresá el motivo de la cancelación.")
      return
    }
    cancel.mutate(
      { id: cancelTarget.id, reason },
      {
        onSuccess: () => {
          toast.success("Documento cancelado en SIFEN.")
          setCancelTarget(null)
        },
        onError: (err) => toast.error("No se pudo cancelar", { description: err.message }),
      },
    )
  }

  function handleReconcile() {
    reconcile.mutate(undefined, {
      onSuccess: (result) =>
        toast.success(`Reconciliación completa: ${result.checked} revisados, ${result.updated} actualizados.`),
      onError: (err) => toast.error("No se pudo reconciliar", { description: err.message }),
    })
  }

  const columns = React.useMemo<ColumnDef<EInvoiceDocument, unknown>[]>(
    () => [
      {
        accessorKey: "createdAt",
        header: "Fecha",
        cell: ({ row }) => <span className="text-sm">{formatCreatedAt(row.original.createdAt)}</span>,
      },
      {
        accessorKey: "clientName",
        header: "Cliente",
        cell: ({ row }) => <span className="text-sm">{row.original.clientName ?? "Consumidor final"}</span>,
      },
      {
        accessorKey: "total",
        header: "Total",
        cell: ({ row }) => {
          const doc = row.original
          if (doc.total === null) return <span className="text-sm text-muted-foreground">—</span>
          return (
            <span className="text-sm font-medium">
              {formatCurrencyAmount(doc.total, doc.currency ?? "PYG")}
            </span>
          )
        },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ row }) => <StatusCell doc={row.original} />,
      },
      {
        accessorKey: "cdc",
        header: "CDC",
        cell: ({ row }) => (
          <span className="font-mono text-xs text-muted-foreground">{row.original.cdc ?? "—"}</span>
        ),
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => {
          const doc = row.original
          return (
            <RowActions
              actions={[
                {
                  label: "Ver KuDE",
                  icon: FileText,
                  href: doc.cdc ? einvoiceKudeUrl(doc.id) : undefined,
                  target: "_blank",
                  hidden: !doc.cdc,
                },
                {
                  label: "Reintentar",
                  icon: RefreshCw,
                  onSelect: () => handleRetry(doc),
                  hidden: doc.status !== "error" || !canManage,
                  disabled: retry.isPending,
                },
                {
                  label: "Cancelar",
                  icon: Ban,
                  variant: "destructive",
                  onSelect: () => openCancel(doc),
                  hidden: doc.status !== "issued" || !canManage,
                },
              ]}
            />
          )
        },
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [canManage, retry.isPending],
  )

  return (
    <>
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-3">
            <div>
              <CardTitle>Documentos</CardTitle>
              <CardDescription>
                Facturas electrónicas encoladas y emitidas — estado del envío a Factomate/SIFEN.
              </CardDescription>
            </div>
            {canManage && (
              <Button variant="outline" size="sm" onClick={handleReconcile} disabled={reconcile.isPending}>
                <RefreshCw className="size-4" />
                Reconciliar con SIFEN
              </Button>
            )}
          </div>
        </CardHeader>
        <CardContent>
          <DataTable
            tableId="einvoice-documents"
            columns={columns}
            data={data?.items ?? []}
            getRowId={(row) => row.id}
            isLoading={isLoading}
            searchPlaceholder="Buscar por CDC o cliente..."
            exportFileName="facturacion-electronica"
            emptyMessage={
              <EmptyState
                icon={Receipt}
                title="Sin documentos"
                description="Todavía no hay facturas electrónicas encoladas para este comercio."
              />
            }
            toolbarSlot={
              <div className="flex flex-wrap items-center gap-2">
                <Select value={status} onValueChange={setStatus}>
                  <SelectTrigger className="w-[160px]">
                    <SelectValue placeholder="Estado" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">Todos los estados</SelectItem>
                    <SelectItem value="pending">Pendiente</SelectItem>
                    <SelectItem value="sending">Enviando</SelectItem>
                    <SelectItem value="issued">Emitido</SelectItem>
                    <SelectItem value="error">Error</SelectItem>
                    <SelectItem value="cancelled">Cancelado</SelectItem>
                    <SelectItem value="stuck">Trabado</SelectItem>
                  </SelectContent>
                </Select>
                <Input
                  type="date"
                  value={from}
                  onChange={(e) => setFrom(e.target.value)}
                  className="w-[150px]"
                  aria-label="Desde"
                />
                <Input
                  type="date"
                  value={to}
                  onChange={(e) => setTo(e.target.value)}
                  className="w-[150px]"
                  aria-label="Hasta"
                />
              </div>
            }
          />
        </CardContent>
      </Card>

      {/* Cancelar es irreversible y anula un documento fiscal ya transmitido
          a SIFEN — Dialog de confirmación con motivo obligatorio, nunca un
          click directo (context/14-ui-conventions.md: Dialog, nunca Sheet/Drawer). */}
      <Dialog open={cancelTarget !== null} onOpenChange={(open) => { if (!open) setCancelTarget(null) }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancelar factura electrónica</DialogTitle>
            <DialogDescription>
              Esta acción es irreversible: anula el documento{" "}
              {cancelTarget?.cdc ? <span className="font-mono">{cancelTarget.cdc}</span> : "seleccionado"} ya
              transmitido a SIFEN. El cliente pierde validez fiscal de este comprobante.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-1.5 py-2">
            <Label htmlFor="cancel-reason">Motivo de la cancelación</Label>
            <Textarea
              id="cancel-reason"
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
              placeholder="Ej: error en los datos del cliente, venta anulada..."
              autoFocus
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancelTarget(null)}>
              Volver
            </Button>
            <Button variant="destructive" onClick={confirmCancel} disabled={cancel.isPending}>
              Confirmar cancelación
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
