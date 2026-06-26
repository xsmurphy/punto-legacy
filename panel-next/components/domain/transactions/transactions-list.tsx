"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Banknote, Ban, Copy, FileText, MoreVertical, Printer, Receipt, RotateCcw, ShoppingBasket, X } from "lucide-react"
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
import { Input } from "@/components/ui/input"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs"
import { Textarea } from "@/components/ui/textarea"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useContacts } from "@/hooks/use-contacts"
import { useOutlets } from "@/hooks/use-outlets"
import { useTeamMembers } from "@/hooks/use-team"
import type { PaymentMethodConfig } from "@/lib/types/pos-bootstrap"
import {
  useReport,
  useTransactionDetail,
  type TransactionRow,
  type TransactionsReportResponse,
  type CobrosRow,
  type CobrosReportResponse,
  type QuoteRow,
  type QuotesReportResponse,
  type TxDetailFull,
} from "@/hooks/use-reports"
import {
  useTransaction,
  type TransactionDetail,
  type TransactionDataItem,
} from "@/hooks/use-transactions"
import { api } from "@/lib/api-client"
import { formatMoney } from "@/lib/format"
import { formatAmount } from "@/lib/format-money"
import { useCartStore } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { printSale, usePrinterBindingsStore } from "@/lib/hardware/printers"
import type { TicketData } from "@/lib/hardware/printers"
import { useVoidTransaction } from "@/hooks/use-void-transaction"
import { PosReturnSheet } from "@/components/register/pos-return-sheet"
import { CreditPaymentDialog } from "@/components/register/credit-payment-dialog"
import { QuotePrintViewDialog } from "@/components/domain/transactions/quote-print-view"
import { cn } from "@/lib/utils"

// ── Helpers ───────────────────────────────────────────────────────────────────

const TX_TYPE_LABELS: Record<string, string> = {
  "0": "Contado",
  "3": "Crédito",
  "6": "Devolución",
  "7": "Anulada",
  "9": "Cotización",
  "2": "Guardado",
  "12": "Mesa",
  "13": "Cita",
}

function txTypeLabel(type: string): string {
  return TX_TYPE_LABELS[type] ?? `Tipo ${type}`
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

function fmtDate(iso: string): string {
  if (!iso) return ""
  try {
    return new Intl.DateTimeFormat("es", {
      day: "numeric",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso.replace(" ", "T")))
  } catch {
    return iso
  }
}

// ── Props ─────────────────────────────────────────────────────────────────────

interface TransactionsListProps {
  backHref: string
  mode?: "panel" | "pos"
}

// ── Edit form type ────────────────────────────────────────────────────────────

type EditForm = {
  date: string
  dueDate: string
  note: string
  customerId: string
  outletId: string
  invoiceNo: string
  userId: string
  responsibleId: string
  transactionType: number
  payments: Array<{ type: string; name: string; total: number }>
  items: Array<{ itemSoldId: string; itemSoldUnits: number; itemSoldTotal: number; itemName: string }>
}

function initEditForm(detail: TxDetailFull): EditForm {
  const tx = detail.transaction
  const rawDate = tx.transactionDate ?? ""
  const date = rawDate.includes("T") ? rawDate.slice(0, 16) : rawDate.replace(" ", "T").slice(0, 16)
  const dueDate = tx.transactionDueDate ?? ""
  return {
    date,
    dueDate: dueDate.length >= 10 ? dueDate.slice(0, 10) : dueDate,
    note: tx.transactionNote ?? "",
    customerId: tx.customerId ?? "",
    outletId: tx.outletId ?? "",
    invoiceNo: tx.invoiceNo ? String(tx.invoiceNo) : "",
    userId: tx.userId ?? "",
    responsibleId: tx.responsibleId ?? "",
    transactionType: tx.transactionType,
    payments: (tx.transactionPaymentType ?? []).map((p) => ({ type: p.type, name: p.name, total: p.total })),
    items: detail.items.map((i) => ({
      itemSoldId: i.itemSoldId,
      itemSoldUnits: i.itemSoldUnits,
      itemSoldTotal: i.itemSoldTotal,
      itemName: i.itemName,
    })),
  }
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

  const { data, isLoading, error } = useReport<TransactionsReportResponse>(
    "transactions",
    opts,
  )
  const { data: cobrosData, isLoading: cobrosLoading } = useReport<CobrosReportResponse>(
    "transactions",
    cobrosOpts,
  )
  const { data: quotesData, isLoading: quotesLoading } = useReport<QuotesReportResponse>(
    "transactions",
    quotesOpts,
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
  const cartLines = useCartStore((s) => s.lines)
  const clearCart = useCartStore((s) => s.clear)
  const addItem = useCartStore((s) => s.addItem)
  const addLines = useCartStore((s) => s.addLines)
  const router = useRouter()

  const { data: detail, isLoading: detailLoading } = useTransaction(
    mode === "pos" ? selectedId : null,
  )

  // Panel-mode: Dialog state
  const [selectedPanelId, setSelectedPanelId] = React.useState<string | null>(null)
  const [panelDialogOpen, setPanelDialogOpen] = React.useState(false)
  const [panelView, setPanelView] = React.useState<"detail" | "edit">("detail")
  const [saving, setSaving] = React.useState(false)
  const [editForm, setEditForm] = React.useState<EditForm | null>(null)

  const queryClient = useQueryClient()

  const { data: panelDetail, isLoading: panelDetailLoading } = useTransactionDetail(
    mode === "panel" ? selectedPanelId : null,
  )

  const { data: contactsData } = useContacts({ type: 1 })
  const { data: outletsData } = useOutlets()
  const { data: teamData } = useTeamMembers({ status: "1" })
  const paymentMethods = useCatalogStore((s) => s.paymentMethods)

  function handleRowClick(row: TransactionRow) {
    if (mode === "pos") {
      setSelectedId(row.transactionId)
      setSheetOpen(true)
    } else {
      setSelectedPanelId(row.transactionId)
      setPanelView("detail")
      setEditForm(null)
      setPanelDialogOpen(true)
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

  function openEdit() {
    if (!panelDetail) return
    setEditForm(initEditForm(panelDetail))
    setPanelView("edit")
  }

  async function handleSave() {
    if (!editForm || !panelDetail) return
    setSaving(true)
    try {
      const body = {
        date: editForm.date.replace("T", " ") + ":00",
        dueDate: editForm.dueDate || null,
        note: editForm.note || null,
        customerId: editForm.customerId || null,
        outletId: editForm.outletId || null,
        invoiceNo: editForm.invoiceNo ? Number(editForm.invoiceNo) : null,
        userId: editForm.userId || null,
        responsibleId: editForm.responsibleId || null,
        transactionType: editForm.transactionType,
        payments: editForm.payments.map((p) => ({ type: p.type, total: p.total })),
        items: editForm.items.map((i) => ({
          itemSoldId: i.itemSoldId,
          itemSoldUnits: Number(i.itemSoldUnits),
          itemSoldTotal: i.itemSoldTotal,
        })),
        tags: panelDetail.transaction.meta?.tags ?? [],
      }
      await api.put(`/v1/reports/transactions?id=${selectedPanelId}`, body)
      toast.success("Transaccion actualizada")
      setPanelView("detail")
      queryClient.invalidateQueries({ queryKey: ["reports", "transactions"] })
      queryClient.invalidateQueries({ queryKey: ["transaction-detail", selectedPanelId] })
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Error al guardar"
      toast.error(msg)
    } finally {
      setSaving(false)
    }
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
      {
        accessorKey: "docNo",
        header: "Documento",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span className="font-medium tabular-nums">{r.docNo || "—"}</span>
              {r.authNo ? (
                <span className="text-[10px] text-muted-foreground tabular-nums">
                  Timbrado {r.authNo}
                </span>
              ) : null}
            </div>
          )
        },
        meta: { label: "Documento" },
      },
      {
        accessorKey: "customerName",
        header: "Cliente",
        cell: ({ row }) => {
          const r = row.original
          if (!r.customerName) {
            return <span className="text-muted-foreground">Consumidor final</span>
          }
          return (
            <div className="flex flex-col">
              <span className="font-medium truncate">{r.customerName}</span>
              {r.customerTIN ? (
                <span className="text-[10px] text-muted-foreground tabular-nums">
                  {r.customerTIN}
                </span>
              ) : null}
            </div>
          )
        },
        meta: { label: "Cliente" },
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
        meta: { label: "Total", className: "tabular-nums text-right" },
      },
      {
        id: "status",
        header: "Estado",
        cell: ({ row }) => {
          const r = row.original
          return r.transactionComplete === 1 ? (
            <Badge variant="default">Pagada</Badge>
          ) : (
            <Badge variant="secondary">Pendiente</Badge>
          )
        },
        meta: { label: "Estado", className: "w-24" },
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
        meta: { label: "Cobrado", className: "tabular-nums text-right" },
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
        meta: { label: "Valor", className: "tabular-nums text-right" },
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
                    const bindings = usePrinterBindingsStore.getState().getBindingsForSale("receipt", [])
                    if (bindings.length > 0) {
                      const txItems = (detail.transactionDatas ?? [])
                        .filter((i) => i.status !== 0)
                        .map((i) => ({
                          name: i.name,
                          qty: i.count,
                          unitPrice: i.price,
                          discount: i.discount,
                          total: i.total,
                          categoryId: null as string | null,
                        }))
                      const txPayments = (detail.pMethods ?? []).map((p) => ({
                        method: p.name || p.type || "—",
                        amount: p.amount,
                      }))
                      const ticketData: TicketData = {
                        companyName: (config as { companyName?: string } | null)?.companyName ?? "",
                        customerName: detail.customerName?.trim() || undefined,
                        docType: String(detail.type),
                        documentNumber: detail.documentNo || undefined,
                        documentPrefix: detail.invoicePrefix || undefined,
                        transactionId: detail.transactionId,
                        date: detail.date ?? new Date().toISOString(),
                        items: txItems,
                        subtotal: Number(detail.total ?? 0) + Number(detail.discount ?? 0),
                        discount: Number(detail.discount ?? 0),
                        taxTotal: 0,
                        total: Number(detail.total ?? 0),
                        payments: txPayments,
                        note: detail.note || undefined,
                      }
                      printSale({ docType: "receipt", data: ticketData })
                        .then((r) => {
                          if (r.failed > 0) toast.warning(`${r.failed} impresora(s) fallaron`)
                        })
                        .catch(console.error)
                    } else {
                      window.print()
                    }
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
        </>
      )}

      {/* Panel-mode: Dialog de detalle */}
      {mode === "panel" && (
        <Dialog
          open={panelDialogOpen}
          onOpenChange={(open) => {
            setPanelDialogOpen(open)
            if (!open) {
              setPanelView("detail")
              setEditForm(null)
            }
          }}
        >
          <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
            {panelDetailLoading && (
              <div className="flex items-center justify-center p-12 text-sm text-muted-foreground">
                Cargando detalle...
              </div>
            )}
            {!panelDetailLoading && panelDetail && panelView === "detail" && (
              <PanelDetailView
                detail={panelDetail}
                bootstrap={bootstrap}
                onEdit={openEdit}
                onClose={() => setPanelDialogOpen(false)}
              />
            )}
            {!panelDetailLoading && panelDetail && panelView === "edit" && editForm && (
              <PanelEditView
                detail={panelDetail}
                form={editForm}
                setForm={setEditForm}
                saving={saving}
                contacts={contactsData?.contacts ?? []}
                outlets={outletsData?.rows ?? []}
                teamMembers={teamData?.users ?? []}
                paymentMethods={paymentMethods}
                bootstrap={bootstrap}
                onCancel={() => setPanelView("detail")}
                onSave={handleSave}
              />
            )}
          </DialogContent>
        </Dialog>
      )}
    </div>
  )
}

// ── Panel: Vista detalle ──────────────────────────────────────────────────────

function PanelDetailView({
  detail,
  bootstrap,
  onEdit,
  onClose,
}: {
  detail: TxDetailFull
  bootstrap: ReturnType<typeof useBootstrap>["data"]
  onEdit: () => void
  onClose: () => void
}) {
  const tx = detail.transaction
  const typeStr = String(tx.transactionType)
  const badgeVariant =
    tx.transactionType === 0
      ? "default"
      : tx.transactionType === 3
        ? "secondary"
        : "outline"

  const paymentLabel = (p: { type: string; name: string; extra: string }) => {
    if (p.name && p.name.trim() !== "") return p.name
    if (p.extra && p.extra.trim() !== "" && p.extra !== p.type) return p.extra
    return p.type.slice(0, 8)
  }

  const canEdit = tx.transactionType === 0 || tx.transactionType === 3

  return (
    <>
      <DialogHeader>
        <DialogTitle className="flex flex-wrap items-center gap-2">
          <Badge variant={badgeVariant}>{txTypeLabel(typeStr)}</Badge>
          {tx.invoiceNo && <span className="text-sm font-normal text-muted-foreground">#{tx.invoiceNo}</span>}
          {tx.transactionDate && (
            <span className="text-sm font-normal text-muted-foreground">{fmtDate(tx.transactionDate)}</span>
          )}
        </DialogTitle>
        <div className="flex flex-wrap gap-2 text-sm text-muted-foreground pt-1">
          {tx.customerName && <span>Cliente: <span className="text-foreground">{tx.customerName}</span></span>}
          {tx.outletName && <span>Sucursal: <span className="text-foreground">{tx.outletName}</span></span>}
          {tx.userName && <span>Usuario: <span className="text-foreground">{tx.userName}</span></span>}
        </div>
      </DialogHeader>

      <div className="flex flex-col gap-5 py-2">
        {/* Items */}
        {detail.items.length > 0 && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Items</p>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-16">Cant.</TableHead>
                  <TableHead>Articulo</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {detail.items.map((item) => (
                  <TableRow key={item.itemSoldId}>
                    <TableCell className="tabular-nums">{item.itemSoldUnits}</TableCell>
                    <TableCell>{item.itemName}</TableCell>
                    <TableCell className="tabular-nums text-right">
                      {formatMoney(item.itemSoldTotal, bootstrap)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </section>
        )}

        {/* Totales */}
        <section>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Totales</p>
          <div className="space-y-1.5 rounded-lg border border-border p-3">
            {tx.transactionDiscount > 0 && (
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Descuento</span>
                <span className="tabular-nums text-yellow-600">
                  -{formatMoney(tx.transactionDiscount, bootstrap)}
                </span>
              </div>
            )}
            <div className="flex justify-between font-bold">
              <span>Total</span>
              <span className="tabular-nums">{formatMoney(tx.transactionTotal, bootstrap)}</span>
            </div>
          </div>
        </section>

        {/* Pagos */}
        {tx.transactionPaymentType.length > 0 && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Pagos</p>
            <div className="divide-y divide-border rounded-lg border border-border">
              {tx.transactionPaymentType.map((p, i) => (
                <div key={i} className="flex items-center justify-between px-3 py-2.5 text-sm">
                  <span className="text-foreground">{paymentLabel(p)}</span>
                  <span className="tabular-nums font-medium">{formatMoney(p.total, bootstrap)}</span>
                </div>
              ))}
            </div>
          </section>
        )}

        {/* Nota */}
        {tx.transactionNote && (
          <section>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Nota</p>
            <p className="text-sm text-foreground">{tx.transactionNote}</p>
          </section>
        )}

        {/* Notas de crédito */}
        {detail.creditNotes.length > 0 && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Notas de crédito</p>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Doc</TableHead>
                  <TableHead className="text-right">Monto</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {detail.creditNotes.map((cn) => (
                  <TableRow key={cn.transactionId}>
                    <TableCell className="tabular-nums">{niceDateTime(cn.transactionDate)}</TableCell>
                    <TableCell>{cn.invoiceNo || "—"}</TableCell>
                    <TableCell className="tabular-nums text-right">
                      {formatMoney(cn.transactionTotal, bootstrap)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </section>
        )}

        {/* Agendamientos */}
        {detail.appointments.length > 0 && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Agendamientos</p>
            <div className="divide-y divide-border rounded-lg border border-border">
              {detail.appointments.map((a, i) => (
                <div key={i} className="flex items-center justify-between px-3 py-2.5 text-sm">
                  <span className="tabular-nums text-muted-foreground">{niceDateTime(a.transactionDate)}</span>
                  <span className="tabular-nums font-medium">{formatMoney(a.transactionTotal, bootstrap)}</span>
                </div>
              ))}
            </div>
          </section>
        )}

        {/* Crédito */}
        {detail.creditPayments !== null && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Crédito</p>
            <div className="space-y-1.5 rounded-lg border border-border p-3">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Total</span>
                <span className="tabular-nums">{formatMoney(detail.creditPayments.total, bootstrap)}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Pagado</span>
                <span className="tabular-nums">{formatMoney(detail.creditPayments.paid, bootstrap)}</span>
              </div>
              <div className="flex justify-between text-sm font-semibold">
                <span>Deuda</span>
                <span className="tabular-nums">{formatMoney(detail.creditPayments.debt, bootstrap)}</span>
              </div>
            </div>
          </section>
        )}
      </div>

      <DialogFooter>
        {canEdit && (
          <Button variant="outline" onClick={onEdit}>
            Editar
          </Button>
        )}
        <Button onClick={onClose}>Cerrar</Button>
      </DialogFooter>
    </>
  )
}

// ── Panel: Vista edición ──────────────────────────────────────────────────────

function PanelEditView({
  detail,
  form,
  setForm,
  saving,
  contacts,
  outlets,
  teamMembers,
  paymentMethods,
  bootstrap,
  onCancel,
  onSave,
}: {
  detail: TxDetailFull
  form: EditForm
  setForm: React.Dispatch<React.SetStateAction<EditForm | null>>
  saving: boolean
  contacts: Array<{ id: string; name: string }>
  outlets: Array<{ id: string; name: string }>
  teamMembers: Array<{ id: string; name: string }>
  paymentMethods: PaymentMethodConfig[]
  bootstrap: ReturnType<typeof useBootstrap>["data"]
  onCancel: () => void
  onSave: () => void
}) {
  const tx = detail.transaction
  const isCredit = form.transactionType === 3
  const canEditType = tx.transactionType === 0 || tx.transactionType === 3
  const canEditItems = tx.transactionType === 0 || tx.transactionType === 3

  function updatePayment(index: number, field: "type" | "name" | "total", value: string | number | null) {
    setForm((prev) => {
      if (!prev) return prev
      const payments = prev.payments.map((p, i) =>
        i === index ? { ...p, [field]: value } : p,
      )
      return { ...prev, payments }
    })
  }

  function removePayment(index: number) {
    setForm((prev) => {
      if (!prev) return prev
      return { ...prev, payments: prev.payments.filter((_, i) => i !== index) }
    })
  }

  function addPayment() {
    const defaultMethod = paymentMethods[0]
    setForm((prev) => {
      if (!prev) return prev
      return {
        ...prev,
        payments: [
          ...prev.payments,
          { type: defaultMethod?.id ?? "", name: defaultMethod?.name ?? "", total: 0 },
        ],
      }
    })
  }

  function updateItem(index: number, field: "itemSoldUnits" | "itemSoldTotal", value: number | null) {
    setForm((prev) => {
      if (!prev) return prev
      const items = prev.items.map((it, i) =>
        i === index ? { ...it, [field]: value } : it,
      )
      return { ...prev, items }
    })
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Editar transacción</DialogTitle>
      </DialogHeader>

      <div className="flex flex-col gap-5 py-2">
        {/* Tipo (Contado / Crédito) — solo editable si es 0 o 3 */}
        {canEditType && (
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Tipo</label>
            <Select
              value={String(form.transactionType)}
              onValueChange={(v) =>
                setForm((prev) =>
                  prev ? { ...prev, transactionType: Number(v) } : prev,
                )
              }
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="0">Contado</SelectItem>
                <SelectItem value="3">Crédito</SelectItem>
              </SelectContent>
            </Select>
          </div>
        )}

        {/* Fecha */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Fecha</label>
          <Input
            type="datetime-local"
            value={form.date}
            onChange={(e) => setForm((prev) => prev ? { ...prev, date: e.target.value } : prev)}
          />
        </div>

        {/* Fecha vencimiento (solo crédito) */}
        {isCredit && (
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Fecha de vencimiento</label>
            <Input
              type="date"
              value={form.dueDate}
              onChange={(e) => setForm((prev) => prev ? { ...prev, dueDate: e.target.value } : prev)}
            />
          </div>
        )}

        {/* Nro de documento */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Nro. de documento</label>
          <Input
            type="number"
            value={form.invoiceNo}
            onChange={(e) =>
              setForm((prev) => prev ? { ...prev, invoiceNo: e.target.value } : prev)
            }
            placeholder="—"
          />
        </div>

        {/* Cliente */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Cliente</label>
          <Select
            value={form.customerId}
            onValueChange={(v) => setForm((prev) => prev ? { ...prev, customerId: v } : prev)}
          >
            <SelectTrigger>
              <SelectValue placeholder="Seleccionar cliente..." />
            </SelectTrigger>
            <SelectContent>
              {contacts.map((c) => (
                <SelectItem key={c.id} value={c.id}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Sucursal */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Sucursal</label>
          <Select
            value={form.outletId}
            onValueChange={(v) => setForm((prev) => prev ? { ...prev, outletId: v } : prev)}
          >
            <SelectTrigger>
              <SelectValue placeholder="Seleccionar sucursal..." />
            </SelectTrigger>
            <SelectContent>
              {outlets.map((o) => (
                <SelectItem key={o.id} value={o.id}>
                  {o.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Vendedor */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Vendedor</label>
          <Select
            value={form.userId || "__none__"}
            onValueChange={(v) =>
              setForm((prev) =>
                prev ? { ...prev, userId: v === "__none__" ? "" : v } : prev,
              )
            }
          >
            <SelectTrigger>
              <SelectValue placeholder="Seleccionar vendedor..." />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__none__">Sin asignar</SelectItem>
              {teamMembers.map((m) => (
                <SelectItem key={m.id} value={m.id}>
                  {m.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Responsable */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Responsable</label>
          <Select
            value={form.responsibleId || "__none__"}
            onValueChange={(v) =>
              setForm((prev) =>
                prev ? { ...prev, responsibleId: v === "__none__" ? "" : v } : prev,
              )
            }
          >
            <SelectTrigger>
              <SelectValue placeholder="Seleccionar responsable..." />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__none__">Sin asignar</SelectItem>
              {teamMembers.map((m) => (
                <SelectItem key={m.id} value={m.id}>
                  {m.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Nota */}
        <div className="flex flex-col gap-1.5">
          <label className="text-sm font-medium">Nota</label>
          <Textarea
            value={form.note}
            onChange={(e) => setForm((prev) => prev ? { ...prev, note: e.target.value } : prev)}
            rows={3}
          />
        </div>

        {/* Items */}
        {canEditItems && form.items.length > 0 && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Items</p>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-20">Cant.</TableHead>
                  <TableHead>Articulo</TableHead>
                  <TableHead className="w-32">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {form.items.map((item, i) => (
                  <TableRow key={item.itemSoldId}>
                    <TableCell>
                      <Input
                        type="text"
                        inputMode="numeric"
                        value={item.itemSoldUnits}
                        onChange={(e) => updateItem(i, "itemSoldUnits", Number(e.target.value))}
                        className="w-16"
                      />
                    </TableCell>
                    <TableCell className="text-sm">{item.itemName}</TableCell>
                    <TableCell>
                      <MoneyInput
                        value={item.itemSoldTotal}
                        onChange={(v) => updateItem(i, "itemSoldTotal", v)}
                      />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </section>
        )}

        {/* Pagos */}
        <section>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Pagos</p>
          <div className="flex flex-col gap-2">
            {form.payments.map((p, i) => (
              <div key={i} className="flex items-center gap-2">
                {paymentMethods.length > 0 ? (
                  <Select
                    value={p.type}
                    onValueChange={(v) => {
                      const m = paymentMethods.find((pm) => pm.id === v)
                      setForm((prev) => {
                        if (!prev) return prev
                        const payments = prev.payments.map((pay, idx) =>
                          idx === i ? { ...pay, type: v, name: m?.name ?? v } : pay,
                        )
                        return { ...prev, payments }
                      })
                    }}
                  >
                    <SelectTrigger className="flex-1">
                      <SelectValue placeholder="Método de pago..." />
                    </SelectTrigger>
                    <SelectContent>
                      {paymentMethods.map((m) => (
                        <SelectItem key={m.id} value={m.id}>
                          {m.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : (
                  <span className="flex-1 text-sm px-3 py-2 rounded-md border border-border bg-muted/40 text-foreground">
                    {p.name || p.type.slice(0, 8)}
                  </span>
                )}
                <div className="w-32">
                  <MoneyInput
                    value={p.total}
                    onChange={(v) => updatePayment(i, "total", v)}
                  />
                </div>
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={() => removePayment(i)}
                  type="button"
                >
                  <X className="size-4" />
                </Button>
              </div>
            ))}
            <Button
              variant="outline"
              size="sm"
              className="w-fit"
              onClick={addPayment}
              type="button"
            >
              + Agregar método
            </Button>
          </div>
        </section>
      </div>

      <DialogFooter>
        <Button variant="outline" onClick={onCancel} disabled={saving}>
          Cancelar
        </Button>
        <Button onClick={onSave} disabled={saving}>
          {saving ? "Guardando..." : "Guardar"}
        </Button>
      </DialogFooter>
    </>
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
