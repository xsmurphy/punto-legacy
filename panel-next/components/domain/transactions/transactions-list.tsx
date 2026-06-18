"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Copy, Printer, Receipt } from "lucide-react"

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
import { EmptyState } from "@/components/empty-state"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type TransactionRow,
  type TransactionsReportResponse,
} from "@/hooks/use-reports"
import {
  useTransaction,
  type TransactionDetail,
  type TransactionDataItem,
} from "@/hooks/use-transactions"
import { formatMoney } from "@/lib/format"
import { formatAmount } from "@/lib/format-money"
import { useCartStore } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { cn } from "@/lib/utils"

// ── Helpers ───────────────────────────────────────────────────────────────────

const TX_TYPE_LABELS: Record<string, string> = {
  "0": "Contado",
  "3": "Crédito",
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

// ── Componente principal ──────────────────────────────────────────────────────

export function TransactionsList({ backHref, mode = "panel" }: TransactionsListProps) {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "detail" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<TransactionsReportResponse>(
    "transactions",
    opts,
  )

  const rows = data?.rows ?? []

  // POS-mode: Sheet state
  const [selectedId, setSelectedId] = React.useState<string | null>(null)
  const [sheetOpen, setSheetOpen] = React.useState(false)
  const [confirmDuplicateOpen, setConfirmDuplicateOpen] = React.useState(false)
  const [pendingDuplicate, setPendingDuplicate] = React.useState<TransactionDetail | null>(null)

  const config = useCatalogStore((s) => s.config)
  const cartLines = useCartStore((s) => s.lines)
  const clearCart = useCartStore((s) => s.clear)
  const addItem = useCartStore((s) => s.addItem)
  const router = useRouter()

  const { data: detail, isLoading: detailLoading } = useTransaction(
    mode === "pos" ? selectedId : null,
  )

  function handleRowClick(row: TransactionRow) {
    if (mode === "pos") {
      setSelectedId(row.transactionId)
      setSheetOpen(true)
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

  const columns = React.useMemo<ColumnDef<TransactionRow>[]>(
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

      <DataTable
        tableId="report-transactions"
        data={rows}
        columns={columns}
        getRowId={(r) => r.transactionId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por documento, cliente, cajero…"
        exportFileName="transacciones"
        onRowClick={mode === "pos" ? handleRowClick : undefined}
        emptyMessage={
          <EmptyState
            icon={Receipt}
            title="Sin transacciones"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />

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
                  onReprint={() => window.print()}
                />
              )}
            </SheetContent>
          </Sheet>
        </>
      )}
    </div>
  )
}

// ── Detalle de transacción ────────────────────────────────────────────────────

export function TransactionDetailContent({
  tx,
  config,
  onDuplicate,
  onReprint,
}: {
  tx: TransactionDetail
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onDuplicate: () => void
  onReprint: () => void
}) {
  const docLabel = tx.invoicePrefix
    ? `${tx.invoicePrefix}-${tx.documentNo}`
    : tx.documentNo

  return (
    <div className="flex flex-col gap-4 p-6 pt-4">
      {/* Header */}
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-lg font-bold text-foreground">{tx.name || "Sin nombre"}</p>
          <p className="text-sm text-muted-foreground">{txTypeLabel(tx.type)}</p>
          {docLabel && (
            <p className="mt-1 text-xs text-muted-foreground">Comprobante #{docLabel}</p>
          )}
          <p className="text-xs text-muted-foreground">{fmtDate(tx.date)}</p>
        </div>
        <div className="flex shrink-0 gap-2">
          <Button variant="outline" size="sm" onClick={onDuplicate} className="gap-1.5">
            <Copy className="size-4" />
            Duplicar
          </Button>
          <Button variant="outline" size="sm" onClick={onReprint} className="gap-1.5">
            <Printer className="size-4" />
            Reimprimir
          </Button>
        </div>
      </div>

      {/* Ítems */}
      {(tx.transactionDatas ?? []).filter((i) => i.status !== 0).length > 0 && (
        <section>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Ítems
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
