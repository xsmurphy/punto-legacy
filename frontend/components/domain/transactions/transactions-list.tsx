"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Banknote, Ban, Copy, FileText, MoreVertical, Printer, Receipt, RotateCcw, ShoppingBasket } from "lucide-react"
import { useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"

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
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { EmptyState } from "@/components/empty-state"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs"
import { Textarea } from "@/components/ui/textarea"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type TransactionRow,
  type TransactionsReportResponse,
  type CobrosRow,
  type CobrosReportResponse,
  type QuoteRow,
  type QuotesReportResponse,
} from "@/hooks/use-reports"
import {
  useTransaction,
  type TransactionDetail,
  type TransactionDataItem,
} from "@/hooks/use-transactions"
import { posApi } from "@/lib/api/pos-client"
import { formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"
import { formatAmount } from "@/lib/format-money"
import { useCartStore } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { buildTicketDataFromTransaction } from "@/lib/hardware/printers/build-ticket-data"
import { usePrintWithPicker } from "@/lib/hardware/printers/print-with-fallback"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"
import { useVoidTransaction } from "@/hooks/use-void-transaction"
import { PosReturnSheet } from "@/components/register/pos-return-sheet"
import { CreditPaymentDialog } from "@/components/register/credit-payment-dialog"
import { QuotePrintViewDialog } from "@/components/domain/transactions/quote-print-view"
import { cn } from "@/lib/utils"

// ── Helpers ───────────────────────────────────────────────────────────────────

export const TX_TYPE_LABELS: Record<string, string> = {
  "0": "Contado",
  "3": "Crédito",
  "5": "Recibo",
  "6": "Devolución",
  "7": "Anulada",
  "9": "Cotización",
  "2": "Guardado",
  "12": "Mesa",
  "13": "Cita",
}

export function txTypeLabel(type: string): string {
  return TX_TYPE_LABELS[type] ?? `Tipo ${type}`
}

function niceDateTime(iso: string): string {
  if (!iso) return "—"
  return formatDateTime(iso)
}

function fmtDate(iso: string): string {
  if (!iso) return ""
  return formatDateTime(iso, "d MMM yyyy, HH:mm")
}

// ── Props ─────────────────────────────────────────────────────────────────────

interface TransactionsListProps {
  backHref: string
  mode?: "panel" | "pos"
}

// ── Componente principal ──────────────────────────────────────────────────────

export function TransactionsList({ backHref, mode = "panel" }: TransactionsListProps) {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "detail" } }),
    [range],
  )
  const cobrosOpts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "cobros" } }),
    [range],
  )
  const quotesOpts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "quotes" } }),
    [range],
  )

  // En modo POS el listado viaja con el cliente del DEVICE (Bearer): el scope
  // de sucursal sale de la fila `device`, no del view-scope del panel. Con el
  // cliente del panel, la caja mostraba las ventas de la sucursal seleccionada
  // en el PANEL (o de todas) — bug reportado 2026-07-30.
  const reportClient = mode === "pos" ? posApi : undefined
  const { data, isLoading, error } = useReport<TransactionsReportResponse>(
    "transactions",
    { ...opts, client: reportClient },
  )
  const { data: cobrosData, isLoading: cobrosLoading } = useReport<CobrosReportResponse>(
    "transactions",
    { ...cobrosOpts, client: reportClient },
  )
  const { data: quotesData, isLoading: quotesLoading } = useReport<QuotesReportResponse>(
    "transactions",
    { ...quotesOpts, client: reportClient },
  )

  const rows = data?.rows ?? []
  const cobrosRows = cobrosData?.rows ?? []
  const quotesRows = quotesData?.rows ?? []

  // POS-mode: Sheet state
  const [selectedId, setSelectedId] = React.useState<string | null>(null)
  const [sheetOpen, setSheetOpen] = React.useState(false)
  const [confirmDuplicateOpen, setConfirmDuplicateOpen] = React.useState(false)
  const [pendingDuplicate, setPendingDuplicate] = React.useState<TransactionDetail | null>(null)
  const [returnSheetForTx, setReturnSheetForTx] = React.useState<string | null>(null)

  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined)
  const allBindings = bindingsData?.bindings ?? []
  const { requestPrint, pickerDialog: reprintPickerDialog } = usePrintWithPicker()
  const cartLines = useCartStore((s) => s.lines)
  const clearCart = useCartStore((s) => s.clear)
  const addItem = useCartStore((s) => s.addItem)
  const addLines = useCartStore((s) => s.addLines)
  const router = useRouter()

  const { data: detail, isLoading: detailLoading } = useTransaction(
    mode === "pos" ? selectedId : null,
  )

  const queryClient = useQueryClient()

  // Panel-mode: "Pagos recibidos" no tiene página propia (F3, context/39) —
  // el owner fue explícito en que ahí alcanza con un modal básico, no hay
  // mucho más que fecha/monto/método para mostrar.
  const [selectedCobro, setSelectedCobro] = React.useState<CobrosRow | null>(null)

  function handleRowClick(row: { transactionId: string }) {
    if (mode === "pos") {
      setSelectedId(row.transactionId)
      setSheetOpen(true)
    } else {
      // Transacciones y Cotizaciones son la MISMA entidad (una venta) —
      // misma página de detalle, el contenido se adapta a lo que el
      // payload traiga (F2, context/39-detalle-transaccion.md).
      router.push(`/transactions/${row.transactionId}`)
    }
  }

  function requestDuplicate(tx: TransactionDetail) {
    if (cartLines.length > 0) {
      setPendingDuplicate(tx)
      setConfirmDuplicateOpen(true)
    } else {
      executeDuplicate(tx)
    }
  }

  function executeDuplicate(tx: TransactionDetail) {
    clearCart()
    const items = (tx.transactionDatas ?? []).filter((i) => i.status !== 0)
    for (const item of items) {
      addItem({
        id: item.itemId,
        name: item.name,
        price: item.price,
      })
    }
    router.push("/pos")
  }

  function handleAddToCart(tx: TransactionDetail) {
    const items = (tx.transactionDatas ?? []).filter((i) => i.status !== 0)
    if (items.length === 0) return
    addLines(
      items.map((i) => ({
        itemId: i.itemId,
        name: i.name,
        qty: i.count,
        unitPrice: i.price,
        discount: i.discount > 0 ? i.discount : undefined,
        note: i.note || undefined,
      })),
    )
    toast.success(`${items.length} ítem${items.length !== 1 ? "s" : ""} agregado${items.length !== 1 ? "s" : ""} al carrito`)
    setSheetOpen(false)
  }

  // ── Columns: Transacciones ────────────────────────────────────────────────

  const txColumns = React.useMemo<ColumnDef<TransactionRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{niceDateTime((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Fecha", className: "tabular-nums" },
      },
      // Documento / Timbrado / Cliente / RUC van en columnas SEPARADAS
      // (owner 2026-08-08). Antes Timbrado y RUC eran una segunda línea de
      // 10px bajo su columna: ilegibles, no ordenables y no se podían ocultar
      // ni exportar por separado. Son datos fiscales que el contador cruza uno
      // por uno, no metadata decorativa.
      {
        accessorKey: "docNo",
        header: "Documento",
        cell: ({ getValue }) => (
          <span className="font-medium tabular-nums">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Documento", className: "tabular-nums" },
      },
      {
        accessorKey: "authNo",
        header: "Timbrado",
        cell: ({ getValue }) => {
          const v = (getValue() as string) || ""
          return v ? (
            <span className="tabular-nums">{v}</span>
          ) : (
            <span className="text-muted-foreground">—</span>
          )
        },
        meta: { label: "Timbrado", className: "tabular-nums" },
      },
      {
        accessorKey: "customerName",
        header: "Cliente",
        cell: ({ getValue }) => {
          const name = (getValue() as string) || ""
          return name ? (
            <span className="truncate font-medium">{name}</span>
          ) : (
            <span className="text-muted-foreground">Consumidor final</span>
          )
        },
        meta: { label: "Cliente" },
      },
      {
        accessorKey: "customerTIN",
        header: "RUC",
        cell: ({ getValue }) => {
          const tin = (getValue() as string) || ""
          return tin ? (
            <span className="tabular-nums">{tin}</span>
          ) : (
            <span className="text-muted-foreground">—</span>
          )
        },
        meta: { label: "RUC", className: "tabular-nums" },
      },
      {
        accessorKey: "userName",
        header: "Cajero",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground truncate">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "Cajero" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">
            {(getValue() as string) || "—"}
          </span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        id: "payments",
        header: "Pago",
        cell: ({ row }) => {
          const r = row.original
          const ps = r.payments ?? []
          if (!ps.length) return <span className="text-muted-foreground">—</span>
          return (
            <div className="flex flex-wrap gap-1">
              {ps.slice(0, 2).map((p, i) => (
                <Badge key={i} variant="outline" className="text-[10px]">
                  {p.name || "—"}
                </Badge>
              ))}
              {ps.length > 2 && (
                <span className="text-[10px] text-muted-foreground">+{ps.length - 2}</span>
              )}
            </div>
          )
        },
        meta: { label: "Pago" },
      },
      {
        accessorKey: "total",
        header: "Total",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: {
          label: "Total",
          className: "tabular-nums text-right",
          footerSum: true,
          footerFormat: (sum) => (
            <span className="font-medium">{formatMoney(sum, bootstrap)}</span>
          ),
        },
      },
      {
        id: "status",
        header: "Tipo",
        // Antes mostraba Pagada/Pendiente desde `transactionComplete`, que NO
        // es la modalidad de venta sino el estado de cobro: se flipea a true
        // cuando la deuda se salda (ObligationsService), así que una venta a
        // crédito ya cobrada aparecía como "Pagada" — indistinguible de un
        // contado. La modalidad sale de `transactionType` y su mapa canónico
        // (TX_TYPE_LABELS, el mismo que usa el detalle).
        cell: ({ row }) => {
          const r = row.original
          const label = txTypeLabel(String(r.transactionType))
          // El estado de cobro no se pierde: un crédito todavía adeudado va
          // atenuado. No se agrega columna — el ancho queda igual.
          const pendingCredit = r.transactionType === 3 && r.transactionComplete !== 1
          if (!pendingCredit) return <Badge variant="default">{label}</Badge>
          return (
            <Tooltip>
              <TooltipTrigger asChild>
                <Badge variant="secondary">{label}</Badge>
              </TooltipTrigger>
              <TooltipContent>Pendiente de cobro</TooltipContent>
            </Tooltip>
          )
        },
        meta: { label: "Tipo", className: "w-24" },
      },
      {
        id: "einvoice",
        header: "Fact. electrónica",
        cell: ({ row }) => {
          const r = row.original
          // null = tenant sin FE conectada o venta no encolada (autoIssue off,
          // onlyWithTaxId sin cliente identificado) — no es un error, se omite
          // el badge en vez de mostrar un falso "pendiente".
          if (!r.einvoiceStatus) {
            return <span className="text-muted-foreground">—</span>
          }
          if (r.einvoiceStatus === "issued") {
            return (
              <Tooltip>
                <TooltipTrigger asChild>
                  <Badge variant="default" className="max-w-32 truncate">
                    {r.einvoiceCdc ?? "Emitida"}
                  </Badge>
                </TooltipTrigger>
                <TooltipContent>{r.einvoiceCdc ?? "Emitida"}</TooltipContent>
              </Tooltip>
            )
          }
          if (r.einvoiceStatus === "error") {
            return (
              <Tooltip>
                <TooltipTrigger asChild>
                  <Badge variant="destructive" className="max-w-32 truncate">
                    Error
                  </Badge>
                </TooltipTrigger>
                <TooltipContent>{r.einvoiceError ?? "Rechazada por el proveedor"}</TooltipContent>
              </Tooltip>
            )
          }
          // pending / sending / cancelled / skipped — estado transitorio u opcional.
          return <Badge variant="secondary">Pendiente</Badge>
        },
        meta: { label: "Fact. electrónica", className: "w-32" },
      },
    ],
    [bootstrap],
  )

  // ── Columns: Cobros ───────────────────────────────────────────────────────

  const cobrosColumns = React.useMemo<ColumnDef<CobrosRow>[]>(
    () => [
      {
        accessorKey: "parentInvoice",
        header: "Doc. Ref. #",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Doc. Ref. #" },
      },
      {
        accessorKey: "invoiceNo",
        header: "Documento #",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Documento #" },
      },
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{niceDateTime((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "customerName",
        header: "Cliente",
        cell: ({ getValue }) => (
          <span>{(getValue() as string) || <span className="text-muted-foreground">Consumidor final</span>}</span>
        ),
        meta: { label: "Cliente" },
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Usuario" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "registerName",
        header: "Caja",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Caja" },
      },
      {
        id: "payments",
        header: "M. de Pago",
        cell: ({ row }) => {
          const ps = row.original.payments ?? []
          if (!ps.length) return <span className="text-muted-foreground">—</span>
          return (
            <div className="flex flex-wrap gap-1">
              {ps.slice(0, 2).map((p, i) => (
                <Badge key={i} variant="outline" className="text-[10px]">
                  {p.name || "—"}
                </Badge>
              ))}
              {ps.length > 2 && (
                <span className="text-[10px] text-muted-foreground">+{ps.length - 2}</span>
              )}
            </div>
          )
        },
        meta: { label: "M. de Pago" },
      },
      {
        accessorKey: "total",
        header: "Cobrado",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: {
          label: "Cobrado",
          className: "tabular-nums text-right",
          footerSum: true,
          footerFormat: (sum) => (
            <span className="font-medium">{formatMoney(sum, bootstrap)}</span>
          ),
        },
      },
    ],
    [bootstrap],
  )

  // ── Columns: Cotizaciones ─────────────────────────────────────────────────

  const quotesColumns = React.useMemo<ColumnDef<QuoteRow>[]>(
    () => [
      {
        accessorKey: "invoiceNo",
        header: "Documento #",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Documento #" },
      },
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{niceDateTime((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "transactionStatus",
        header: "Estado",
        cell: ({ getValue }) => {
          const v = getValue() as string
          const variant =
            v === "activa" ? "default" : v === "vencida" ? "destructive" : "secondary"
          return <Badge variant={variant}>{v || "—"}</Badge>
        },
        meta: { label: "Estado" },
      },
      {
        accessorKey: "customerName",
        header: "Cliente",
        cell: ({ getValue }) => (
          <span>{(getValue() as string) || <span className="text-muted-foreground">Consumidor final</span>}</span>
        ),
        meta: { label: "Cliente" },
      },
      {
        accessorKey: "customerTIN",
        header: "TIN",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "TIN" },
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Usuario" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "total",
        header: "Valor",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: {
          label: "Valor",
          className: "tabular-nums text-right",
          footerSum: true,
          footerFormat: (sum) => (
            <span className="font-medium">{formatMoney(sum, bootstrap)}</span>
          ),
        },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink backHref={backHref} />
          <h1 className="text-2xl font-semibold">Transacciones</h1>
          <p className="text-sm text-muted-foreground">
            Todas las ventas del período: facturas, tickets y notas de crédito.
            Si necesitás ver más de 5.000 movimientos, achicá el rango de fechas.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudieron cargar las transacciones</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {mode === "panel" ? (
        <Tabs defaultValue="transacciones">
          <TabsList>
            <TabsTrigger value="transacciones">Transacciones</TabsTrigger>
            <TabsTrigger value="cobros">Pagos recibidos</TabsTrigger>
            <TabsTrigger value="quotes">Cotizaciones</TabsTrigger>
          </TabsList>

          <TabsContent value="transacciones" className="mt-4">
            <DataTable
              tableId="report-transactions"
              data={rows}
              columns={txColumns}
              getRowId={(r) => r.transactionId}
              isLoading={isLoading}
              searchPlaceholder="Buscar por documento, cliente, cajero…"
              exportFileName="transacciones"
              onRowClick={handleRowClick}
              emptyMessage={
                <EmptyState
                  icon={Receipt}
                  title="Sin transacciones"
                  description="Ajustá el rango de fechas y volvé a consultar."
                />
              }
            />
          </TabsContent>

          <TabsContent value="cobros" className="mt-4">
            <DataTable
              tableId="report-cobros"
              data={cobrosRows}
              columns={cobrosColumns}
              getRowId={(r) => r.transactionId}
              isLoading={cobrosLoading}
              searchPlaceholder="Buscar por documento, cliente, usuario…"
              exportFileName="cobros"
              onRowClick={(row) => setSelectedCobro(row)}
              emptyMessage={
                <EmptyState
                  icon={Receipt}
                  title="Sin pagos recibidos"
                  description="Ajustá el rango de fechas y volvé a consultar."
                />
              }
            />
          </TabsContent>

          <TabsContent value="quotes" className="mt-4">
            <DataTable
              tableId="report-quotes"
              data={quotesRows}
              columns={quotesColumns}
              getRowId={(r) => r.transactionId}
              isLoading={quotesLoading}
              searchPlaceholder="Buscar por documento, cliente, usuario…"
              exportFileName="cotizaciones"
              onRowClick={handleRowClick}
              emptyMessage={
                <EmptyState
                  icon={Receipt}
                  title="Sin cotizaciones"
                  description="Ajustá el rango de fechas y volvé a consultar."
                />
              }
            />
          </TabsContent>
        </Tabs>
      ) : (
        <DataTable
          tableId="report-transactions"
          data={rows}
          columns={txColumns}
          getRowId={(r) => r.transactionId}
          isLoading={isLoading}
          searchPlaceholder="Buscar por documento, cliente, cajero…"
          exportFileName="transacciones"
          onRowClick={handleRowClick}
          emptyMessage={
            <EmptyState
              icon={Receipt}
              title="Sin transacciones"
              description="Ajustá el rango de fechas y volvé a consultar."
            />
          }
        />
      )}

      {/* POS-mode: Sheet de detalle */}
      {mode === "pos" && (
        <>
          <AlertDialog open={confirmDuplicateOpen} onOpenChange={setConfirmDuplicateOpen}>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Reemplazar venta actual</AlertDialogTitle>
                <AlertDialogDescription>
                  El carrito tiene ítems. Al duplicar esta venta se reemplazarán.
                  Esta acción no se puede deshacer.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction
                  autoFocus
                  onClick={() => {
                    if (pendingDuplicate) executeDuplicate(pendingDuplicate)
                    setConfirmDuplicateOpen(false)
                    setPendingDuplicate(null)
                  }}
                >
                  Reemplazar venta
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>

          <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
            <SheetContent side="right" className="w-full sm:max-w-md overflow-y-auto">
              <SheetHeader className="pb-0">
                <SheetTitle>Detalle de transacción</SheetTitle>
              </SheetHeader>
              {detailLoading && (
                <div className="flex flex-1 items-center justify-center p-8 text-sm text-muted-foreground">
                  Cargando detalle...
                </div>
              )}
              {!detailLoading && detail && (
                <TransactionDetailContent
                  tx={detail}
                  config={config}
                  onDuplicate={() => requestDuplicate(detail)}
                  onReprint={() => {
                    // Wrapper compartido (build-ticket-data.ts): mismo mapeo
                    // de items/pagos que pos-transactions-dialog.tsx, con
                    // categoryId real (antes null hardcodeado acá). docType
                    // receipt/factura sale del tipo de transacción — misma
                    // regla que pos-transactions-dialog:handleReprint.
                    const docType = Number(detail.type) === 5 ? "receipt" : "factura"
                    const ticketData = buildTicketDataFromTransaction(detail, config, docType)
                    // Sin binding → printTicketInBrowser (plantilla del
                    // docType) en vez del window.print() del DOM crudo de
                    // antes (A4 genérico, ignoraba la plantilla).
                    requestPrint(docType, ticketData, allBindings)
                  }}
                  onReturn={() => setReturnSheetForTx(detail.transactionId)}
                  onAddToCart={() => handleAddToCart(detail)}
                  onClose={() => setSheetOpen(false)}
                />
              )}
            </SheetContent>
          </Sheet>

          <PosReturnSheet
            open={returnSheetForTx !== null}
            onOpenChange={(v) => {
              if (!v) {
                setReturnSheetForTx(null)
                queryClient.invalidateQueries({ queryKey: ["pos-transaction", selectedId] })
                queryClient.invalidateQueries({ queryKey: ["pos-transactions"] })
              }
            }}
            parentTransactionId={returnSheetForTx ?? undefined}
          />
          {reprintPickerDialog}
        </>
      )}

      {/* Panel-mode: modal básico de "Pagos recibidos" — sin página propia
          (F3, context/39-detalle-transaccion.md): fecha/monto/medio/cliente/
          usuario ya vienen en la fila del listado, sin otra ida y vuelta. */}
      {mode === "panel" && (
        <Dialog open={selectedCobro !== null} onOpenChange={(open) => !open && setSelectedCobro(null)}>
          <DialogContent className="sm:max-w-md">
            {selectedCobro && (
              <>
                <DialogHeader>
                  <DialogTitle>Pago recibido</DialogTitle>
                </DialogHeader>
                <div className="flex flex-col gap-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Fecha</span>
                    <span className="tabular-nums">{niceDateTime(selectedCobro.date)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Monto</span>
                    <span className="tabular-nums font-semibold">
                      {formatMoney(selectedCobro.total, bootstrap)}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Medio de pago</span>
                    <span>{selectedCobro.payments?.map((p) => p.name).join(", ") || "—"}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Cliente</span>
                    <span>{selectedCobro.customerName || "Consumidor final"}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Usuario</span>
                    <span>{selectedCobro.userName || "—"}</span>
                  </div>
                </div>
                <DialogFooter>
                  <Button asChild variant="outline" onClick={() => setSelectedCobro(null)}>
                    <Link href={`/transactions/${selectedCobro.parentId}`}>Ver transacción</Link>
                  </Button>
                  <Button onClick={() => setSelectedCobro(null)}>Cerrar</Button>
                </DialogFooter>
              </>
            )}
          </DialogContent>
        </Dialog>
      )}
    </div>
  )
}

// ── Detalle de transacción (modo POS / Sheet) ─────────────────────────────────

export function TransactionDetailContent({
  tx,
  config,
  onDuplicate,
  onReprint,
  onReturn,
  onAddToCart,
  onClose,
}: {
  tx: TransactionDetail
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onDuplicate: () => void
  onReprint: () => void
  onReturn?: () => void
  onAddToCart?: () => void
  onClose?: () => void
}) {
  const router = useRouter()
  const qc = useQueryClient()
  const voidTx = useVoidTransaction()
  const setQuoteParent = useCartStore((s) => s.setQuoteParent)
  const setCustomer = useCartStore((s) => s.setCustomer)
  const clearCart = useCartStore((s) => s.clear)
  const addLines = useCartStore((s) => s.addLines)
  const cartLines = useCartStore((s) => s.lines)
  const paymentMethods = useCatalogStore((s) => s.paymentMethods)

  const [voidDialogOpen, setVoidDialogOpen] = React.useState(false)
  const [voidMotive, setVoidMotive] = React.useState("")
  const [invoiceConfirmOpen, setInvoiceConfirmOpen] = React.useState(false)
  const [creditPayDialogOpen, setCreditPayDialogOpen] = React.useState(false)
  const [showQuotePdf, setShowQuotePdf] = React.useState(false)

  const txType = tx.type
  const isVoid = txType === "7"
  const isReturn = txType === "6"
  const isReadOnly = isVoid || isReturn
  const isQuote = txType === "9"

  const canVoid = (txType === "0" || txType === "3" || txType === "9") && !isVoid
  const canReturn = (txType === "0" || txType === "3") && !isVoid && !isReturn
  const canDuplicate = !isReadOnly
  const canAddToCart = (tx.transactionDatas?.filter((i) => i.status !== 0).length ?? 0) > 0 && !isVoid
  const canInvoice = isQuote && !isVoid
  const canPay = txType === "3" && (tx.creditPayments?.debt ?? 0) > 0

  const docLabel = tx.invoicePrefix
    ? `${tx.invoicePrefix}-${tx.documentNo}`
    : tx.documentNo

  function handleInvoiceQuote() {
    if (cartLines.length > 0) {
      setInvoiceConfirmOpen(true)
    } else {
      doInvoiceQuote()
    }
  }

  function doInvoiceQuote() {
    const items = (tx.transactionDatas ?? []).filter((i) => i.status !== 0)
    clearCart()
    addLines(
      items.map((i) => ({
        itemId: i.itemId,
        name: i.name,
        qty: i.count,
        unitPrice: i.price,
        discount: i.discount > 0 ? i.discount : undefined,
        note: i.note || undefined,
      })),
    )
    setQuoteParent(tx.transactionId)
    if (tx.customerId) {
      // Buscar en el cache de pos-bootstrap sin trigger de fetch extra.
      // En modo panel (sin pos-bootstrap cargado) getQueryData devuelve undefined → skip.
      const posBootstrap = qc.getQueryData<import("@/lib/types/pos-bootstrap").PosBootstrap>(["pos-bootstrap"])
      const fullCustomer = posBootstrap?.customers?.find((c) => c.id === tx.customerId)
      if (fullCustomer) {
        setCustomer(fullCustomer)
      }
    }
    onClose?.()
    toast.success("Cotización cargada — completá el pago para facturar")
    router.push("/pos")
  }

  return (
    <div className="flex flex-col gap-4 p-6 pt-4">
      {/* Header */}
      <div className="flex flex-col gap-3">
        <div className="flex items-center justify-between gap-2">
          {/* Badge tipo */}
          {txType === "0" && <Badge variant="default">CONTADO</Badge>}
          {txType === "3" && <Badge variant="secondary">CRÉDITO</Badge>}
          {txType === "6" && <Badge variant="outline">DEVOLUCIÓN</Badge>}
          {txType === "7" && <Badge variant="secondary" className="opacity-60">ANULADA</Badge>}
          {txType === "9" && (
            <Badge variant="outline" className="border-[var(--chart-1)] text-[var(--chart-1)]">
              COTIZACIÓN
            </Badge>
          )}
          {!["0", "3", "6", "7", "9"].includes(txType) && (
            <Badge variant="outline">{TX_TYPE_LABELS[txType] ?? txType}</Badge>
          )}

          {/* Botón principal + Opciones */}
          <div className="flex items-center gap-2">
            {canInvoice && (
              <Button size="sm" onClick={handleInvoiceQuote} className="gap-1.5">
                <FileText className="size-4" />
                Facturar
              </Button>
            )}
            {!canInvoice && canPay && (
              <Button size="sm" onClick={() => setCreditPayDialogOpen(true)} className="gap-1.5">
                <Banknote className="size-4" />
                Pagar
              </Button>
            )}
            {!canInvoice && !canPay && canDuplicate && (
              <Button size="sm" variant="outline" onClick={onDuplicate} className="gap-1.5">
                <Copy className="size-4" />
                Duplicar
              </Button>
            )}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" className="size-8 p-0">
                  <MoreVertical className="size-4" />
                  <span className="sr-only">Opciones</span>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {canPay && (
                  <DropdownMenuItem onClick={() => setCreditPayDialogOpen(true)}>
                    <Banknote className="size-4 mr-2" />
                    Pagar
                  </DropdownMenuItem>
                )}
                {canPay && (canInvoice || canDuplicate || canAddToCart) && <DropdownMenuSeparator />}
                {canInvoice && canDuplicate && (
                  <DropdownMenuItem onClick={onDuplicate}>
                    <Copy className="size-4 mr-2" />
                    Duplicar
                  </DropdownMenuItem>
                )}
                {canAddToCart && onAddToCart && (
                  <DropdownMenuItem onClick={onAddToCart}>
                    <ShoppingBasket className="size-4 mr-2" />
                    Agregar al carrito
                  </DropdownMenuItem>
                )}
                <DropdownMenuItem onClick={onReprint}>
                  <Printer className="size-4 mr-2" />
                  Reimprimir
                </DropdownMenuItem>
                {isQuote && (
                  <DropdownMenuItem onClick={() => setShowQuotePdf(true)}>
                    <FileText className="size-4 mr-2" />
                    Ver PDF
                  </DropdownMenuItem>
                )}
                {(canReturn || canVoid) && <DropdownMenuSeparator />}
                {canReturn && onReturn && (
                  <DropdownMenuItem onClick={onReturn}>
                    <RotateCcw className="size-4 mr-2" />
                    Devolución
                  </DropdownMenuItem>
                )}
                {canVoid && (
                  <DropdownMenuItem
                    className="text-destructive focus:text-destructive"
                    onClick={() => setVoidDialogOpen(true)}
                  >
                    <Ban className="size-4 mr-2" />
                    Anular
                  </DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>

        {/* Datos del cliente y documento */}
        <div>
          <p className="text-lg font-bold text-foreground">{tx.name || "Sin nombre"}</p>
          {docLabel && (
            <p className="mt-0.5 text-xs text-muted-foreground">Comprobante #{docLabel}</p>
          )}
          <p className="text-xs text-muted-foreground">{fmtDate(tx.date)}</p>
        </div>
      </div>

      {/* Items */}
      {(tx.transactionDatas ?? []).filter((i) => i.status !== 0).length > 0 && (
        <section>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Items
          </p>
          <div className="divide-y divide-border rounded-lg border border-border">
            {tx.transactionDatas!.filter((i) => i.status !== 0).map((item, idx) => (
              <ItemRow key={idx} item={item} config={config} />
            ))}
          </div>
        </section>
      )}

      {/* Totales */}
      <section>
        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Totales
        </p>
        <div className="space-y-1.5 rounded-lg border border-border p-3">
          {parseFloat(tx.discount) > 0 && (
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Descuento</span>
              <span className="tabular-nums text-yellow-600">
                -{formatAmount(parseFloat(tx.discount), config)}
              </span>
            </div>
          )}
          <div className="flex justify-between font-bold">
            <span>Total</span>
            <span className="tabular-nums text-lg">
              {formatAmount(parseFloat(tx.total), config)}
            </span>
          </div>
        </div>
      </section>

      {/* Pagos */}
      {tx.pMethods.length > 0 && (
        <section>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Pagos
          </p>
          <div className="divide-y divide-border rounded-lg border border-border">
            {tx.pMethods.map((pm, idx) => (
              <div
                key={idx}
                className="flex items-center justify-between px-3 py-2.5 text-sm"
              >
                <span className="text-foreground">{pm.name}</span>
                <span className="tabular-nums font-medium">
                  {formatAmount(pm.amount, config)}
                </span>
              </div>
            ))}
          </div>
        </section>
      )}

      {/* Nota */}
      {tx.note && (
        <section>
          <p className="mb-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Nota
          </p>
          <p className="text-sm text-foreground">{tx.note}</p>
        </section>
      )}

      {/* Documentos asociados */}
      {(() => {
        const hasNC  = (tx.creditNotes?.length ?? 0) > 0
        const hasPay = (tx.paymentsReceived?.length ?? 0) > 0
        const hasApt = (tx.appointments?.length ?? 0) > 0
        if (!hasNC && !hasPay && !hasApt) return null
        const defaultTab = hasNC ? "nc" : hasPay ? "payments" : "appointments"
        return (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Documentos asociados
            </p>
            <Tabs defaultValue={defaultTab}>
              <TabsList className="w-full">
                {hasNC && (
                  <TabsTrigger value="nc" className="flex-1">
                    NC ({tx.creditNotes!.length})
                  </TabsTrigger>
                )}
                {hasPay && (
                  <TabsTrigger value="payments" className="flex-1">
                    Pagos ({tx.paymentsReceived!.length})
                  </TabsTrigger>
                )}
                {hasApt && (
                  <TabsTrigger value="appointments" className="flex-1">
                    Agendas ({tx.appointments!.length})
                  </TabsTrigger>
                )}
              </TabsList>
              {hasNC && (
                <TabsContent value="nc" className="mt-2">
                  <div className="divide-y divide-border rounded-lg border border-border">
                    {tx.creditNotes!.map((cn) => (
                      <div key={cn.transactionId} className="flex items-center justify-between px-3 py-2.5 text-sm">
                        <div className="flex flex-col">
                          <span className="tabular-nums text-muted-foreground">{fmtDate(cn.transactionDate)}</span>
                          {cn.invoiceNo && <span className="text-xs text-muted-foreground">#{cn.invoiceNo}</span>}
                        </div>
                        <span className="tabular-nums font-medium text-destructive">
                          -{formatAmount(cn.transactionTotal, config)}
                        </span>
                      </div>
                    ))}
                  </div>
                </TabsContent>
              )}
              {hasPay && (
                <TabsContent value="payments" className="mt-2">
                  <div className="divide-y divide-border rounded-lg border border-border">
                    {tx.paymentsReceived!.map((pr) => (
                      <div key={pr.transactionId} className="flex items-center justify-between px-3 py-2.5 text-sm">
                        <div className="flex flex-col">
                          <span className="tabular-nums text-muted-foreground">{fmtDate(pr.date)}</span>
                          {pr.invoiceNo && <span className="text-xs text-muted-foreground">#{pr.invoiceNo}</span>}
                          {pr.paymentMethod && <span className="text-xs text-muted-foreground">{pr.paymentMethod}</span>}
                        </div>
                        <span className="tabular-nums font-medium" style={{ color: 'var(--chart-1)' }}>
                          +{formatAmount(pr.amount, config)}
                        </span>
                      </div>
                    ))}
                  </div>
                </TabsContent>
              )}
              {hasApt && (
                <TabsContent value="appointments" className="mt-2">
                  <div className="divide-y divide-border rounded-lg border border-border">
                    {tx.appointments!.map((a, i) => (
                      <div key={i} className="flex items-center justify-between px-3 py-2.5 text-sm">
                        <span className="tabular-nums text-muted-foreground">{fmtDate(a.transactionDate)}</span>
                        <span className="tabular-nums font-medium">{formatAmount(a.transactionTotal, config)}</span>
                      </div>
                    ))}
                  </div>
                </TabsContent>
              )}
            </Tabs>
          </section>
        )
      })()}

      {/* Dialog: Confirmar Anular */}
      <AlertDialog open={voidDialogOpen} onOpenChange={setVoidDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Anular transacción</AlertDialogTitle>
            <AlertDialogDescription>
              Esta acción es irreversible. La venta y sus movimientos de stock serán revertidos.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <div className="py-2">
            <Textarea
              placeholder="Motivo (opcional)"
              value={voidMotive}
              onChange={(e) => setVoidMotive(e.target.value)}
              rows={2}
              className="resize-none"
            />
          </div>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={voidTx.isPending}>Cancelar</AlertDialogCancel>
            {/* Button plano — AlertDialogAction cierra el dialog antes de que isPending bloquee el doble-click */}
            <Button
              variant="destructive"
              disabled={voidTx.isPending}
              onClick={() => {
                voidTx.mutate(
                  { id: tx.transactionId, motive: voidMotive },
                  {
                    onSuccess: () => {
                      toast.success("Transacción anulada")
                      setVoidDialogOpen(false)
                      onClose?.()
                    },
                    onError: () => {
                      toast.error("No se pudo anular la transacción")
                    },
                  },
                )
              }}
            >
              {voidTx.isPending ? "Anulando..." : "Anular"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Dialog: Confirmar reemplazo de carrito al facturar cotización */}
      <AlertDialog open={invoiceConfirmOpen} onOpenChange={setInvoiceConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Reemplazar carrito</AlertDialogTitle>
            <AlertDialogDescription>
              El carrito tiene ítems. Al facturar esta cotización se reemplazarán.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              autoFocus
              onClick={() => {
                setInvoiceConfirmOpen(false)
                doInvoiceQuote()
              }}
            >
              Reemplazar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {canPay && (
        <CreditPaymentDialog
          open={creditPayDialogOpen}
          onOpenChange={setCreditPayDialogOpen}
          parentTransactionId={tx.transactionId}
          debt={tx.creditPayments!.debt}
          customerName={tx.name || "Cliente"}
          paymentMethods={paymentMethods}
          config={config}
          onSuccess={(result) => {
            if (result.parentComplete) {
              onClose?.()
            }
          }}
        />
      )}

      {isQuote && showQuotePdf && (
        <QuotePrintViewDialog
          tx={tx}
          config={config}
          open={showQuotePdf}
          onOpenChange={setShowQuotePdf}
        />
      )}
    </div>
  )
}

// ── Sub-componentes ───────────────────────────────────────────────────────────

function ItemRow({
  item,
  config,
}: {
  item: TransactionDataItem
  config: ReturnType<typeof useCatalogStore.getState>["config"]
}) {
  const hasDiscount = item.discount > 0
  return (
    <div className="flex items-start gap-2.5 px-3 py-2.5">
      <span
        className={cn(
          "flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-semibold tabular-nums",
          hasDiscount
            ? "border-yellow-500/40 bg-yellow-500/15 text-yellow-500"
            : "border-border bg-muted/40 text-muted-foreground",
        )}
      >
        {item.count}
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-foreground">{item.name}</p>
        {item.note && (
          <p className="truncate text-[11px] text-muted-foreground">{item.note}</p>
        )}
        {hasDiscount && (
          <p className="text-[11px] text-yellow-600">-{Math.round(item.discount)}%</p>
        )}
      </div>
      <span className="shrink-0 text-sm font-semibold tabular-nums text-foreground">
        {formatAmount(item.total, config)}
      </span>
    </div>
  )
}

function BackLink({ backHref }: { backHref: string }) {
  const isPos = backHref.includes("/pos")
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href={backHref}>
        <ArrowLeft className="size-3.5" />
        {!isPos && "Volver a reportes"}
      </Link>
    </Button>
  )
}
