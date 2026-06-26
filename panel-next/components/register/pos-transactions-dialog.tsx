"use client"

/**
 * Modal de listado de transacciones del POS — T1.
 *
 * Layout: Dialog split 2-col (bucket xl = sm:max-w-6xl).
 * Lista con búsqueda debounced, filtro por fecha, paginación manual.
 * Detalle reutiliza useTransaction (BFF /api/pos/transactions/[id]).
 */

import * as React from "react"
import { CalendarIcon, Filter, Loader2, MoreHorizontal, Receipt, X } from "lucide-react"
import { format } from "date-fns"
import { es } from "date-fns/locale"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Progress } from "@/components/ui/progress"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { Separator } from "@/components/ui/separator"
import { EmptyState } from "@/components/empty-state"
import { usePosTransactionsList, usePosTransactionDetail } from "@/hooks/use-pos-transactions"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney } from "@/lib/format-money"
import { cn } from "@/lib/utils"
import type { PosTransactionListItem } from "@/lib/types/pos-transactions"
import type { TransactionDetail as TransactionDetailType } from "@/hooks/use-transactions"
import { toast } from "sonner"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { useCartStore } from "@/lib/cart/store"
import { QuotePrintViewDialog } from "@/components/domain/transactions/quote-print-view"
import { CreditPaymentDialog } from "@/components/register/credit-payment-dialog"
import { printSale } from "@/lib/hardware/printers"
import { getBindingsForSale } from "@/lib/hardware/printers/binding"
import type { TicketData } from "@/lib/hardware/printers"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"

// ── Helpers ───────────────────────────────────────────────────────────────────

const TX_LABELS: Record<number, string> = {
  0: "Contado",
  2: "Guardado",
  3: "Crédito",
  6: "Devolución",
  7: "Anulada",
  9: "Cotización",
  10: "Envío",
  11: "Orden",
  12: "Mesa",
  13: "Agenda",
}

function txLabel(type: number): string {
  return TX_LABELS[type] ?? `Tipo ${type}`
}

function chipStyle(item: PosTransactionListItem): string {
  if (item.type === 9) return "bg-secondary text-secondary-foreground border"
  if (item.type === 6 || item.type === 7) return "bg-muted text-muted-foreground"
  if (item.type === 3) {
    const debt = item.debt ?? 0
    const total = item.rawTotal
    if (debt === 0) return "bg-emerald-500/10 text-emerald-700 border border-emerald-500/20"
    if (debt <= total / 2) return "bg-amber-500/10 text-amber-700 border border-amber-500/20"
    return "bg-destructive/10 text-destructive border border-destructive/20"
  }
  return "bg-secondary text-secondary-foreground border"
}

/**
 * Formato compacto para filas de lista: "22 jun 12:59"
 */
function niceDateTime(iso: string): string {
  if (!iso) return "—"
  try {
    const d = new Date(iso.replace(" ", "T"))
    if (Number.isNaN(d.getTime())) return iso
    return format(d, "d MMM HH:mm", { locale: es })
  } catch {
    return iso
  }
}


function useDebounce(value: string, delay: number): string {
  const [debounced, setDebounced] = React.useState(value)
  React.useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(t)
  }, [value, delay])
  return debounced
}

// ── Filtro de tipo ────────────────────────────────────────────────────────────

const TYPE_OPTIONS = [
  { value: null, label: "Todos" },
  { value: 0, label: "Contado" },
  { value: 3, label: "Crédito" },
  { value: 9, label: "Cotización" },
  { value: 6, label: "Devolución" },
  { value: 7, label: "Anulado" },
] as const

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  open: boolean
  onOpenChange: (v: boolean) => void
}

// ── Componente principal ──────────────────────────────────────────────────────

export function PosTransactionsDialog({ open, onOpenChange }: Props) {
  const [searchInput, setSearchInput] = React.useState("")
  const [selectedDate, setSelectedDate] = React.useState<Date | undefined>(undefined)
  const [calendarOpen, setCalendarOpen] = React.useState(false)
  const [selectedId, setSelectedId] = React.useState<string | null>(null)
  const [typeFilter, setTypeFilter] = React.useState<number | null>(null)

  const q = useDebounce(searchInput, 300)
  const date = selectedDate ? format(selectedDate, "yyyy-MM-dd") : ""

  const { flat, isFetching, hasMore, fetchNextPage, error } = usePosTransactionsList({ q, date, type: typeFilter })

  function handleClose() {
    onOpenChange(false)
    setSearchInput("")
    setSelectedDate(undefined)
    setSelectedId(null)
    setTypeFilter(null)
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      {/* Bucket xl — modal split 2-col (lista + detalle) */}
      <DialogContent className="sm:max-w-6xl p-0 gap-0 overflow-hidden">
        <DialogHeader className="px-6 pt-6 pb-3 border-b">
          <DialogTitle className="text-2xl font-semibold">Transacciones</DialogTitle>
        </DialogHeader>
        {/* Contenido scrollable con alto máximo para no superar el viewport */}
        <div className="grid grid-cols-[1fr_1.2fr] max-h-[80vh] min-h-0">
          <TransactionList
            items={flat}
            isFetching={isFetching}
            hasMore={hasMore}
            error={error}
            selectedId={selectedId}
            onSelect={setSelectedId}
            onFetchNext={fetchNextPage}
            searchInput={searchInput}
            onSearchChange={setSearchInput}
            selectedDate={selectedDate}
            calendarOpen={calendarOpen}
            onCalendarOpenChange={setCalendarOpen}
            onDateChange={(d) => {
              setSelectedDate(d)
              setCalendarOpen(false)
            }}
            onDateClear={() => setSelectedDate(undefined)}
            typeFilter={typeFilter}
            onTypeFilterChange={setTypeFilter}
          />
          <TransactionDetail encId={selectedId} onClose={handleClose} />
        </div>
      </DialogContent>
    </Dialog>
  )
}

// ── Lista ─────────────────────────────────────────────────────────────────────

interface ListProps {
  items: PosTransactionListItem[]
  isFetching: boolean
  hasMore: boolean
  error: Error | null
  selectedId: string | null
  onSelect: (id: string) => void
  onFetchNext: () => void
  searchInput: string
  onSearchChange: (v: string) => void
  selectedDate: Date | undefined
  calendarOpen: boolean
  onCalendarOpenChange: (v: boolean) => void
  onDateChange: (d: Date | undefined) => void
  onDateClear: () => void
  typeFilter: number | null
  onTypeFilterChange: (v: number | null) => void
}

function TransactionList({
  items,
  isFetching,
  hasMore,
  error,
  selectedId,
  onSelect,
  onFetchNext,
  searchInput,
  onSearchChange,
  selectedDate,
  calendarOpen,
  onCalendarOpenChange,
  onDateChange,
  onDateClear,
  typeFilter,
  onTypeFilterChange,
}: ListProps) {
  return (
    <div className="flex flex-col h-full min-h-0 border-r overflow-hidden">
      {/* Filtros sticky — el título principal está en DialogHeader */}
      <div className="shrink-0 bg-background border-b px-4 pt-3 pb-3 flex flex-col gap-2">
        <div className="flex gap-2">
          <Input
            placeholder="Buscar por cliente, comprobante o ID"
            value={searchInput}
            onChange={(e) => onSearchChange(e.target.value)}
            className="flex-1"
          />
          <Popover open={calendarOpen} onOpenChange={onCalendarOpenChange}>
            <PopoverTrigger asChild>
              <Button variant="outline" className="gap-1.5 shrink-0">
                <CalendarIcon className="size-4" />
                <span>
                  {selectedDate ? format(selectedDate, "dd MMM", { locale: es }) : "Fecha"}
                </span>
              </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="end">
              <Calendar
                mode="single"
                selected={selectedDate}
                onSelect={onDateChange}
              />
            </PopoverContent>
          </Popover>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" className="gap-1.5 shrink-0">
                <Filter className="size-4" />
                <span>
                  {typeFilter != null
                    ? TYPE_OPTIONS.find((o) => o.value === typeFilter)?.label
                    : "Tipo"}
                </span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {TYPE_OPTIONS.map((o) => (
                <DropdownMenuItem
                  key={String(o.value)}
                  onSelect={() => onTypeFilterChange(o.value)}
                >
                  {o.label}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
          {selectedDate && (
            <Button
              variant="ghost"
              size="icon"
              onClick={onDateClear}
            >
              <X className="size-4" />
            </Button>
          )}
        </div>
      </div>

      {/* Lista scrollable */}
      <div className="flex-1 overflow-y-auto">
        {error && (
          <p className="text-destructive text-sm text-center py-8 px-4">{error.message}</p>
        )}

        {isFetching && items.length === 0 && (
          <div className="flex flex-col gap-1 px-4 py-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="flex flex-col gap-1.5 py-2 border-b">
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-3 w-1/2" />
              </div>
            ))}
          </div>
        )}

        {/* Empty state: lista vacía sin filtros activos */}
        {!isFetching && items.length === 0 && !error && !searchInput && !selectedDate && (
          <EmptyState
            icon={Receipt}
            title="Sin transacciones"
            description="Cuando hagas ventas aparecerán acá."
          />
        )}

        {/* Empty state: sin resultados para los filtros actuales */}
        {!isFetching && items.length === 0 && !error && (searchInput || selectedDate) && (
          <EmptyState
            icon={Receipt}
            title="Sin resultados"
            description="Probá con otro nombre, comprobante o cambiá la fecha."
          />
        )}

        {items.map((item) => (
          <TransactionRow
            key={item.id}
            item={item}
            selected={selectedId === item.id}
            onSelect={onSelect}
          />
        ))}

        {hasMore && (
          <div className="px-4 py-3 border-t">
            <Button
              variant="outline"
              className="w-full"
              onClick={onFetchNext}
              disabled={isFetching}
            >
              {isFetching ? (
                <>
                  <Loader2 className="size-4 animate-spin" />
                  Cargando…
                </>
              ) : (
                "Cargar más"
              )}
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}

function TransactionRow({
  item,
  selected,
  onSelect,
}: {
  item: PosTransactionListItem
  selected: boolean
  onSelect: (id: string) => void
}) {
  const config = useCatalogStore((s) => s.config)

  return (
    <button
      type="button"
      className={cn(
        "w-full text-left px-4 py-3 border-b transition-colors hover:bg-accent",
        selected && "bg-accent",
      )}
      onClick={() => onSelect(item.id)}
    >
      <div className="flex items-center justify-between gap-2">
        <span className={cn("font-semibold truncate", !item.customerName && "text-muted-foreground")}>
          {item.customerName || "Sin nombre"}
        </span>
        <span className="tabular-nums shrink-0 text-muted-foreground">
          {formatMoney(item.rawTotal, config)}
        </span>
      </div>
      <div className="flex items-center justify-between gap-1.5 mt-0.5">
        <div className="flex items-center gap-1.5 min-w-0">
          <span className="text-xs text-muted-foreground shrink-0">
            {niceDateTime(item.rawDate || item.date)}
          </span>
          {item.invoiceNo && (
            <span className="text-xs text-muted-foreground truncate">
              #{item.invoicePrefix}{item.invoiceNo}
            </span>
          )}
        </div>
        <span className={cn("text-[10px] px-1.5 py-0.5 rounded-md font-medium shrink-0", chipStyle(item))}>
          {txLabel(item.type)}
        </span>
      </div>
    </button>
  )
}

// ── Detalle ───────────────────────────────────────────────────────────────────

/**
 * Determina el botón primario del panel de detalle.
 * - Pagar: crédito con deuda → wireable con CreditPaymentDialog
 * - Facturar: cotización (typeNum=9) → no hay handler aún, disabled
 * - Duplicar: default
 */
function getPrimaryAction(typeNum: number, debt: number): {
  label: string
  action: "pay" | "invoice" | "duplicate"
  disabled: boolean
} {
  if (typeNum === 3 && debt > 0) return { label: "Pagar", action: "pay", disabled: false }
  if (typeNum === 9) return { label: "Facturar", action: "invoice", disabled: false }
  return { label: "Duplicar", action: "duplicate", disabled: false }
}

function TransactionDetail({ encId, onClose }: { encId: string | null; onClose: () => void }) {
  const { data: detail, isLoading } = usePosTransactionDetail(encId)
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined)
  const allBindings = bindingsData?.bindings ?? []
  const addLines = useCartStore((s) => s.addLines)
  const [quotePdfOpen, setQuotePdfOpen] = React.useState(false)
  const [creditPayOpen, setCreditPayOpen] = React.useState(false)
  const [receiptDetail, setReceiptDetail] = React.useState<NonNullable<TransactionDetailType["paymentsReceived"]>[number] | null>(null)

  if (!encId) {
    return (
      <div className="flex items-center justify-center h-full border-l">
        <EmptyState
          icon={Receipt}
          title="Seleccioná una transacción"
          description="Elegí una transacción de la izquierda para ver el detalle."
        />
      </div>
    )
  }

  if (isLoading || !detail) {
    return (
      <div className="flex flex-col gap-4 p-6 border-l overflow-y-auto">
        <Skeleton className="h-6 w-1/3" />
        <Skeleton className="h-4 w-1/2" />
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-20 w-full" />
      </div>
    )
  }

  const typeNum = Number(detail.type)
  const isCredit = typeNum === 3
  const docLabel = detail.invoicePrefix
    ? `${detail.invoicePrefix}-${detail.documentNo}`
    : (detail.documentNo ?? "")

  const items = detail.transactionDatas ?? []
  const payments = detail.pMethods ?? []
  const discount = Number(detail.discount ?? 0)
  const total = Number(detail.total ?? 0)

  const debt = detail.creditPayments?.debt ?? 0
  const paid = total - debt
  const primary = getPrimaryAction(typeNum, debt)

  // Show secondary "Duplicar" button only when primary is Pagar or Facturar
  const showSecondaryDuplicate = primary.action === "pay" || primary.action === "invoice"

  function handleDuplicate() {
    const validItems = items.filter((i) => i.status !== 0)
    if (validItems.length === 0) {
      toast.error("La transacción no tiene items para duplicar")
      return
    }
    addLines(
      validItems.map((i) => ({
        itemId: i.itemId,
        name: i.name,
        qty: i.count,
        unitPrice: i.price,
        discount: i.discount > 0 ? i.discount : undefined,
      }))
    )
    // Si duplico desde una cotización, NO heredo el parentId — eso es para Facturar.
    useCartStore.getState().setQuoteParent(null)
    onClose()
    toast.success("Items duplicados al carrito")
  }

  function handleInvoice() {
    const validItems = items.filter((i) => i.status !== 0)
    if (validItems.length === 0) {
      toast.error("La cotización no tiene items para facturar")
      return
    }
    if (!encId) {
      toast.error("Cotización sin ID — no se puede facturar")
      return
    }
    addLines(
      validItems.map((i) => ({
        itemId: i.itemId,
        name: i.name,
        qty: i.count,
        unitPrice: i.price,
        discount: i.discount > 0 ? i.discount : undefined,
      }))
    )
    // Link bidireccional con la cotización: al guardar la venta, el back vincula.
    useCartStore.getState().setQuoteParent(encId)
    onClose()
    toast.success("Cotización cargada — completá la venta para facturar")
  }

  function handlePrimaryAction() {
    if (primary.action === "pay") {
      setCreditPayOpen(true)
    } else if (primary.action === "duplicate") {
      handleDuplicate()
    } else if (primary.action === "invoice") {
      handleInvoice()
    }
  }

  function handleReprint() {
    if (!detail) return
    const bindings = getBindingsForSale(allBindings, "receipt", [])
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
      printSale({ docType: "receipt", data: ticketData, bindings: allBindings })
        .then((r) => {
          if (r.failed > 0) toast.warning(`${r.failed} impresora(s) fallaron`)
        })
        .catch(console.error)
    } else {
      toast.info(`Reimprimir #${docLabel || (detail?.transactionId ?? "")} — abriendo vista de impresión...`)
      window.print()
    }
  }

  // Formato de fecha para la cabecera (compacto pero con día)
  const dateObj = detail.date ? new Date(detail.date.replace(" ", "T")) : null
  const formattedDate = dateObj && !Number.isNaN(dateObj.getTime())
    ? format(dateObj, "d MMM, HH:mm", { locale: es })
    : "—"

  return (
    <TooltipProvider>
      <div className="flex flex-col h-full min-h-0 border-l overflow-hidden">
        <div className="flex-1 overflow-y-auto p-5">

          {/* ── Header: cliente + monto top-right + split button ─────────── */}
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-xl font-semibold truncate">
                {detail.customerName || <span className="text-muted-foreground">Sin cliente</span>}
              </h2>
              <p className="text-sm text-muted-foreground mt-0.5">
                {txLabel(typeNum)}
                {docLabel && <> &middot; <span className="tabular-nums">#{docLabel}</span></>}
                {formattedDate !== "—" && <> &middot; <span className="tabular-nums">{formattedDate}</span></>}
              </p>
            </div>
            <div className="shrink-0 flex flex-col items-end gap-2">
              <p className="text-2xl font-bold tabular-nums">{formatMoney(total, config)}</p>
              {/* Split button */}
              <div className="inline-flex">
                {primary.disabled ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span>
                        <Button size="sm" className="rounded-r-none border-r-0 opacity-60" disabled>
                          {primary.label}
                        </Button>
                      </span>
                    </TooltipTrigger>
                    <TooltipContent>Próximamente</TooltipContent>
                  </Tooltip>
                ) : (
                  <Button
                    size="sm"
                    className="rounded-r-none border-r-0"
                    onClick={handlePrimaryAction}
                  >
                    {primary.label}
                  </Button>
                )}
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button size="sm" className="rounded-l-none px-2" aria-label="Más acciones">
                      <MoreHorizontal className="size-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    {showSecondaryDuplicate && (
                      <DropdownMenuItem onSelect={handleDuplicate}>Duplicar</DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={handleReprint}>Reimprimir</DropdownMenuItem>
                    {typeNum === 9 && (
                      <DropdownMenuItem onSelect={() => setQuotePdfOpen(true)}>Ver PDF</DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem disabled>Anular</DropdownMenuItem>
                    <DropdownMenuItem disabled>Devolución</DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </div>
          </div>

          {/* ── Hero financiero (solo crédito) ────────────────────────────── */}
          {isCredit && (
            <div className="mt-6">
              {debt > 0 ? (
                <>
                  {/* 2-col stat block — excepcion §4.10: credito con deuda activa */}
                  <div className="grid grid-cols-2 gap-4">
                    <div className="border rounded-lg p-4">
                      <p className="text-xs uppercase tracking-wide text-muted-foreground">Deuda</p>
                      <p className="text-2xl font-bold tabular-nums text-destructive mt-1">
                        {formatMoney(debt, config)}
                      </p>
                    </div>
                    <div className="border rounded-lg p-4">
                      <p className="text-xs uppercase tracking-wide text-muted-foreground">Pagado</p>
                      <p className="text-2xl font-bold tabular-nums text-muted-foreground mt-1">
                        {formatMoney(paid, config)}
                      </p>
                    </div>
                  </div>
                  <div className="mt-3 flex items-center gap-3">
                    <Progress value={total > 0 ? (paid / total) * 100 : 0} className="flex-1 h-2" />
                    <span className="text-xs text-muted-foreground tabular-nums shrink-0">
                      {total > 0 ? Math.round((paid / total) * 100) : 0}% &middot; Total {formatMoney(total, config)}
                    </span>
                  </div>
                </>
              ) : (
                /* Credito totalmente pagado */
                <div className="flex items-center">
                  <Badge variant="secondary" className="bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-400">
                    Pagado
                  </Badge>
                </div>
              )}
            </div>
          )}

          {/* ── Items ─────────────────────────────────────────────────────── */}
          <div className="mt-5 rounded-lg bg-muted/40 p-4">
            <h3 className="text-sm font-medium text-muted-foreground mb-2">
              Items ({items.filter((i) => i.status !== 0).length})
            </h3>
            <div className="divide-y divide-border/60">
              {items.filter((i) => i.status !== 0).length === 0 ? (
                <p className="text-sm text-muted-foreground py-2">Sin items</p>
              ) : (
                items.filter((i) => i.status !== 0).map((item, idx) => (
                  <div key={`${item.itemId}-${idx}`} className="flex items-center justify-between py-2 text-sm gap-3">
                    <span className="truncate flex-1">{item.name}</span>
                    <span className="tabular-nums text-muted-foreground w-12 text-right">{item.count}</span>
                    <span className="tabular-nums w-24 text-right">{formatMoney(item.total, config)}</span>
                  </div>
                ))
              )}
            </div>
            {discount > 0 && (
              <div className="flex justify-between text-sm mt-3 pt-3 border-t border-border/60 text-muted-foreground">
                <span>Descuento</span>
                <span className="tabular-nums text-destructive">-{formatMoney(discount, config)}</span>
              </div>
            )}
          </div>

          {/* ── Pagos / Recibos ─────────────────────────────────────────────
              Cotización (typeNum 9): sin pagos.
              Crédito (typeNum 3): lista de recibos de pago (cada uno es una
                transacción type=5 con parentId = esta).
              Resto (contado, etc.): medios de pago directos. */}
          {typeNum !== 9 && (
            isCredit ? (
              <div className="mt-4 rounded-lg bg-muted/40 p-4">
                <h3 className="text-sm font-medium text-muted-foreground mb-2">
                  Recibos de pago ({detail.paymentsReceived?.length ?? 0})
                </h3>
                {!detail.paymentsReceived || detail.paymentsReceived.length === 0 ? (
                  <p className="text-sm text-muted-foreground italic">Sin recibos registrados</p>
                ) : (
                  <div className="divide-y divide-border/60">
                    {detail.paymentsReceived.map((r) => {
                      const rDate = r.date ? new Date(r.date.replace(" ", "T")) : null
                      const rDateStr = rDate && !Number.isNaN(rDate.getTime())
                        ? format(rDate, "d MMM, HH:mm", { locale: es })
                        : "—"
                      return (
                        <button
                          key={r.transactionId}
                          type="button"
                          onClick={() => setReceiptDetail(r)}
                          className="w-full flex items-center justify-between py-2 text-sm gap-3 hover:bg-accent/40 rounded transition-colors px-1 -mx-1"
                        >
                          <div className="min-w-0 flex-1 text-left">
                            <span className="block truncate">
                              {r.invoiceNo ? `Recibo #${r.invoiceNo}` : "Recibo"}
                              {r.paymentMethod && (
                                <span className="text-muted-foreground"> · {r.paymentMethod}</span>
                              )}
                            </span>
                            <span className="text-xs text-muted-foreground tabular-nums">{rDateStr}</span>
                          </div>
                          <span className="tabular-nums shrink-0">{formatMoney(r.amount, config)}</span>
                        </button>
                      )
                    })}
                  </div>
                )}
              </div>
            ) : (
              <div className="mt-4 rounded-lg bg-muted/40 p-4">
                <h3 className="text-sm font-medium text-muted-foreground mb-2">Pagos</h3>
                {payments.length === 0 ? (
                  <p className="text-sm text-muted-foreground italic">Sin pagos registrados</p>
                ) : (
                  <div className="divide-y divide-border/60">
                    {payments.map((p, i) => (
                      <div key={i} className="flex justify-between py-2 text-sm">
                        <span>{p.name || p.type || "—"}</span>
                        <span className="tabular-nums">{formatMoney(p.amount, config)}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )
          )}

        </div>
      </div>

      {/* Dialogs */}
      {typeNum === 9 && quotePdfOpen && (
        <QuotePrintViewDialog
          tx={detail}
          config={config}
          open={quotePdfOpen}
          onOpenChange={setQuotePdfOpen}
        />
      )}
      {isCredit && (
        <CreditPaymentDialog
          open={creditPayOpen}
          onOpenChange={setCreditPayOpen}
          parentTransactionId={encId}
          debt={debt}
          customerName={detail.customerName ?? ""}
          onSuccess={() => {
            setCreditPayOpen(false)
          }}
        />
      )}

      {/* Detalle de recibo de pago (sub-dialog) */}
      <Dialog open={receiptDetail !== null} onOpenChange={(v) => !v && setReceiptDetail(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {receiptDetail?.invoiceNo ? `Recibo #${receiptDetail.invoiceNo}` : "Recibo de pago"}
            </DialogTitle>
          </DialogHeader>
          {receiptDetail && (() => {
            const rDate = receiptDetail.date ? new Date(receiptDetail.date.replace(" ", "T")) : null
            const rDateStr = rDate && !Number.isNaN(rDate.getTime())
              ? format(rDate, "EEEE d 'de' MMMM, yyyy · HH:mm", { locale: es })
              : "—"
            return (
              <div className="space-y-3">
                <p className="text-3xl font-bold tabular-nums">{formatMoney(receiptDetail.amount, config)}</p>
                <div className="rounded-lg bg-muted/40 p-4 space-y-2 text-sm">
                  <div className="flex justify-between gap-3">
                    <span className="text-muted-foreground">Fecha</span>
                    <span className="text-right">{rDateStr}</span>
                  </div>
                  <div className="flex justify-between gap-3">
                    <span className="text-muted-foreground">Método</span>
                    <span>{receiptDetail.paymentMethod || "—"}</span>
                  </div>
                  <div className="flex justify-between gap-3">
                    <span className="text-muted-foreground">Aplicado a</span>
                    <span className="tabular-nums">{docLabel ? `#${docLabel}` : "—"}</span>
                  </div>
                </div>
              </div>
            )
          })()}
        </DialogContent>
      </Dialog>
    </TooltipProvider>
  )
}
