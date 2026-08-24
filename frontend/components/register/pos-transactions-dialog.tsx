"use client"

/**
 * Modal de listado de transacciones del POS — T1.
 *
 * Layout: Dialog split 2-col (bucket xl = sm:max-w-6xl).
 * Lista con búsqueda debounced, filtro por fecha, paginación manual.
 * Detalle reutiliza useTransaction (BFF /api/pos/transactions/[id]).
 */

import * as React from "react"
import { useQueryClient } from "@tanstack/react-query"
import { CalendarIcon, ChevronLeft, Filter, Loader2, MoreHorizontal, Receipt, X } from "lucide-react"
import { format } from "date-fns"
import { es } from "date-fns/locale"
import { parseNaive, formatDateTime } from "@/lib/format-date"

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
import { useDebounce } from "@/hooks/use-debounce"
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
// Formateador único del correlativo (mig 158) — el mismo que imprime el ticket.
import { formatDocumentNumber } from "@/lib/documents/format-document-number"
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
import { buildTicketDataFromTransaction } from "@/lib/hardware/printers/build-ticket-data"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"
import { posApi } from "@/lib/api/pos-client"
import { usePrintWithPicker } from "@/lib/hardware/printers/print-with-fallback"
import { TransactionSuccessDialog } from "@/components/register/transaction-success-dialog"
import { PosReturnSheet } from "@/components/register/pos-return-sheet"
import { PosVoidSaleDialog } from "@/components/register/pos-void-sale-dialog"

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
  // "" y no "—": es un segmento más de la línea de metadata de la fila, y los
  // segmentos vacíos se omiten junto con su separador (owner 2026-08-24).
  if (!iso) return ""
  return formatDateTime(iso)
}

/**
 * Número de comprobante para la fila de lista — "#0001-0000123" (mismo
 * formato con guion que `docLabel` del panel de detalle, aprobado por el
 * owner). Ventas emitidas antes del fix del P0 de numeración (invoiceNo
 * NULL, ver context/29) o cotizaciones sin invoiceNo devuelven "" — el
 * segmento se OMITE de la línea de metadata en vez de pintar un "—" suelto
 * (owner 2026-08-24). Nunca "#null"/"#undefined".
 */
function invoiceLabel(item: PosTransactionListItem, padWidth: number | null): string {
  if (!item.invoiceNo) return ""
  // mig 158: mismo formateador que el ticket. Antes el número salía pelado
  // acá y con ceros en la factura impresa — el cajero veía dos números
  // distintos para la misma venta.
  const formatted = formatDocumentNumber(item.invoiceNo, item.invoicePrefix, padWidth)
  return formatted ? `#${formatted}` : ""
}

/**
 * RUC (si tiene) o CI del cliente — `customerDoc` ya resuelve esa prioridad
 * server-side (getMainList). "" cuando el cliente no tiene ninguno cargado
 * (consumidor final o contacto sin documento): el segmento se omite y la
 * línea arranca en la fecha, sin hueco ni guion (owner 2026-08-24).
 */
function customerDocLabel(item: PosTransactionListItem): string {
  return item.customerDoc || ""
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
  /**
   * Se dispara SOLO cuando el usuario descarta el modal (ESC, backdrop, botón
   * cerrar) — no cuando se cierra porque una acción se llevó al cajero a otro
   * lado (duplicar/facturar dejan items en el carrito). El menú del POS lo usa
   * para volver a abrirse: entrar por menú → transacciones y cerrar tiene que
   * devolver al menú, no al carrito.
   */
  onDismiss?: () => void
}

// ── Componente principal ──────────────────────────────────────────────────────

export function PosTransactionsDialog({ open, onOpenChange, onDismiss }: Props) {
  const [searchInput, setSearchInput] = React.useState("")
  const [selectedDate, setSelectedDate] = React.useState<Date | undefined>(undefined)
  const [calendarOpen, setCalendarOpen] = React.useState(false)
  const [selectedId, setSelectedId] = React.useState<string | null>(null)
  const [typeFilter, setTypeFilter] = React.useState<number | null>(null)

  const q = useDebounce(searchInput, 300)
  const date = selectedDate ? format(selectedDate, "yyyy-MM-dd") : ""

  const { flat, isFetching, hasMore, fetchNextPage, error } = usePosTransactionsList({ q, date, type: typeFilter })

  // `reason` distingue descartar de accionar: duplicar/facturar cierran el modal
  // porque el cajero sigue en el carrito, y ahí reabrir el menú estorbaría.
  function handleClose(reason: "dismiss" | "action" = "dismiss") {
    onOpenChange(false)
    setSearchInput("")
    setSelectedDate(undefined)
    setSelectedId(null)
    setTypeFilter(null)
    if (reason === "dismiss") onDismiss?.()
  }

  return (
    <Dialog open={open} onOpenChange={() => handleClose("dismiss")}>
      {/* Bucket xl — modal split 2-col (lista + detalle) */}
      {/* Listado grande → fullscreen en mobile (opt-in del primitive). */}
      <DialogContent mobileFullscreen className="sm:max-w-6xl p-0 gap-0 overflow-hidden">
        <DialogHeader className="px-6 pt-6 pb-3 border-b">
          <DialogTitle className="text-2xl font-semibold">Transacciones</DialogTitle>
        </DialogHeader>
        {/* Mobile: 1 columna, navega entre lista <-> detalle según selectedId.
            Desktop (>=md): split 2-col clásico. */}
        <div className="grid grid-cols-1 md:grid-cols-[1fr_1.2fr] max-h-[90vh] md:max-h-[80vh] min-h-0">
          <div className={cn("min-h-0", selectedId ? "hidden md:flex md:flex-col" : "flex flex-col")}>
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
          </div>
          <div className={cn("min-h-0", selectedId ? "flex flex-col" : "hidden md:flex md:flex-col")}>
            <TransactionDetail
              encId={selectedId}
              onClose={() => handleClose("action")}
              onBack={() => setSelectedId(null)}
            />
          </div>
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
      <div className="shrink-0 bg-background border-b px-6 pt-3 pb-3 flex flex-col gap-2">
        <div className="flex gap-2">
          <Input
            placeholder="Buscar por cliente, RUC/CI, comprobante o ID"
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
          <p className="text-destructive text-sm text-center py-8 px-6">{error.message}</p>
        )}

        {isFetching && items.length === 0 && (
          <div className="flex flex-col gap-1 px-6 py-3">
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
          <div className="px-6 py-3 border-t">
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
  // Ancho del correlativo de la caja (mig 158) — el listado muestra el mismo
  // número que salió impreso.
  const invoicePadWidth = useCatalogStore((s) => s.invoicePadWidth)
  const hasName = Boolean(item.customerName)

  // Metadata de la fila en UNA línea horizontal: documento · fecha · nro de
  // comprobante (owner 2026-08-24). Los segmentos vacíos se omiten CON su
  // separador — un cliente sin RUC/CI hace que la línea arranque en la fecha,
  // sin "—" de relleno ni hueco. La altura de la fila no depende de qué
  // segmentos haya (§10: posiciones estables), porque siempre es una línea.
  const metaSegments = [
    { key: "doc", text: customerDocLabel(item), grow: true },
    { key: "date", text: niceDateTime(item.rawDate || item.date), grow: false },
    { key: "invoice", text: invoiceLabel(item, invoicePadWidth), grow: false },
  ].filter((s) => s.text !== "")

  return (
    // Touch target ≥44px (§7 POS específico): 2 líneas de texto + py-3 dan
    // ~56px de alto, con los mismos 5 datos que antes ocupaban 3 líneas.
    <button
      type="button"
      className={cn(
        "w-full text-left px-6 py-3 border-b transition-colors hover:bg-accent",
        selected && "bg-accent",
      )}
      onClick={() => onSelect(item.id)}
    >
      {/* Primario: cliente/razón social + monto */}
      <div className="flex items-baseline justify-between gap-2">
        <span className={cn("text-sm font-medium truncate", !hasName && "text-muted-foreground")}>
          {item.customerName || "Consumidor final"}
        </span>
        <span className="text-sm font-semibold tabular-nums shrink-0">
          {formatMoney(item.rawTotal, config)}
        </span>
      </div>
      {/* Secundario: documento · fecha · nro de comprobante + tipo */}
      <div className="flex items-center justify-between gap-3 mt-1">
        <div className="flex min-w-0 items-center gap-3 text-xs text-muted-foreground">
          {metaSegments.map((seg, i) => (
            <React.Fragment key={seg.key}>
              {i > 0 && (
                <span className="text-muted-foreground/50 shrink-0" aria-hidden>
                  ·
                </span>
              )}
              <span className={cn("tabular-nums", seg.grow ? "min-w-0 truncate" : "shrink-0")}>
                {seg.text}
              </span>
            </React.Fragment>
          ))}
        </div>
        <Badge variant="secondary" className={cn("shrink-0", chipStyle(item))}>
          {txLabel(item.type)}
        </Badge>
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

/**
 * Exportado — reusado standalone por `PosTransactionDetailDialog`
 * (pos-transaction-detail-dialog.tsx) para abrir el detalle de UNA
 * transacción sin el listado al lado (ej. desde el tab Financiero de
 * Cliente, F4 de context/39-detalle-transaccion.md). `bordered=false` saca
 * el `md:border-l` pensado para el split lista+detalle de
 * `PosTransactionsDialog`.
 */
export function TransactionDetail({
  encId,
  onClose,
  onBack,
  bordered = true,
}: {
  encId: string | null
  onClose: () => void
  onBack?: () => void
  bordered?: boolean
}) {
  const { data: detail, isLoading } = usePosTransactionDetail(encId)
  const queryClient = useQueryClient()
  const config = useCatalogStore((s) => s.config)
  const paymentMethods = useCatalogStore((s) => s.paymentMethods)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const invoicePadWidth = useCatalogStore((s) => s.invoicePadWidth)
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined, { client: posApi })
  const allBindings = bindingsData?.bindings ?? []
  const { requestPrint, pickerDialog } = usePrintWithPicker()
  const addLines = useCartStore((s) => s.addLines)
  const [quotePdfOpen, setQuotePdfOpen] = React.useState(false)
  const [creditPayOpen, setCreditPayOpen] = React.useState(false)
  const [receiptDetail, setReceiptDetail] = React.useState<NonNullable<TransactionDetailType["paymentsReceived"]>[number] | null>(null)
  // Pantalla "¿imprimir?" tras cobrar un crédito — el pago genera un recibo
  // (docType "receipt", transactionType=5) que hasta ahora no se ofrecía
  // imprimir. `encId` es el id del recibo recién creado (formato enc(), el
  // que espera `/pos/transactions/[id]`); se resuelve el detalle recién al
  // imprimir para no pagar un round-trip si el cajero no imprime.
  const [creditReceipt, setCreditReceipt] = React.useState<{ encId: string; amount: number } | null>(null)
  // Anulación (F6, context/40-anulacion-y-nota-credito.md) — "Devolución"
  // reusa PosReturnSheet (ya vive standalone en transactions-list.tsx del
  // panel, mismo patrón `parentTransactionId`).
  const [voidDialogOpen, setVoidDialogOpen] = React.useState(false)
  const [returnSheetOpen, setReturnSheetOpen] = React.useState(false)

  if (!encId) {
    return (
      <div className={cn("flex items-center justify-center h-full", bordered && "md:border-l")}>
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
      <div className={cn("flex flex-col gap-4 p-6 overflow-y-auto", bordered && "md:border-l")}>
        <Skeleton className="h-6 w-1/3" />
        <Skeleton className="h-4 w-1/2" />
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-20 w-full" />
      </div>
    )
  }

  const typeNum = Number(detail.type)
  const isCredit = typeNum === 3
  // mig 158 — formateador único; idéntico a lo que imprime el ticket.
  const docLabel = formatDocumentNumber(
    detail.documentNo,
    detail.invoicePrefix,
    invoicePadWidth,
  )

  // Anulación/Devolución (F6, context/40): solo venta contado/crédito, y
  // solo si NO está anulada — para otros tipos y para una tx ya anulada los
  // items del menú se OCULTAN, no se deshabilitan.
  const isVoided = detail.void === true || Boolean(detail.voidedAt)
  const canOfferVoid = (typeNum === 0 || typeNum === 3) && !isVoided
  const canOfferReturn = (typeNum === 0 || typeNum === 3) && !isVoided

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
    const docType = Number(detail.type) === 5 ? "receipt" : "factura"
    // Wrapper compartido (build-ticket-data.ts) — mismo mapeo que
    // transactions-list.tsx:onReprint y quote-print-view.tsx, con categoryId
    // real resuelto contra el catálogo.
    const ticketData = buildTicketDataFromTransaction(detail, config, docType)
    requestPrint(docType, ticketData, allBindings)
  }

  // Recibo del pago de crédito: docType "receipt" (nunca "factura" — la
  // factura es la venta original, el recibo respalda el pago). Se pide el
  // detalle del recibo recién creado (no viene en el resultado del mutation)
  // y se reusa el mismo wrapper de impresión que el resto de la app.
  async function handlePrintCreditReceipt(receiptEncId: string) {
    try {
      const receiptDetailData = await posApi.get<TransactionDetailType>(
        `/pos/transactions/${encodeURIComponent(receiptEncId)}`,
      )
      const ticketData = buildTicketDataFromTransaction(receiptDetailData, config, "receipt")
      requestPrint("receipt", ticketData, allBindings)
    } catch {
      toast.error("No se pudo obtener el recibo para imprimir")
    }
  }

  // Formato de fecha para la cabecera (compacto pero con día)
  const dateObj = detail.date ? parseNaive(detail.date) : null
  const formattedDate = dateObj
    ? format(dateObj, "d MMM, HH:mm", { locale: es })
    : "—"

  return (
    <TooltipProvider>
      <div className={cn("flex flex-col h-full min-h-0 overflow-hidden", bordered && "md:border-l")}>
        {onBack && (
          <div className="md:hidden flex items-center gap-2 px-3 py-2 border-b">
            <Button variant="ghost" size="sm" onClick={onBack} className="gap-1.5">
              <ChevronLeft className="size-4" />
              Volver
            </Button>
          </div>
        )}
        <div className="flex-1 overflow-y-auto p-6">

          {/* ── Header: cliente + monto top-right + split button ─────────── */}
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-xl font-semibold truncate">
                {detail.customerName || <span className="text-muted-foreground">Sin cliente</span>}
              </h2>
              <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                {isCredit ? (
                  <Badge
                    variant="secondary"
                    className={cn(
                      "font-medium",
                      debt <= 0
                        ? "bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-400"
                        : "bg-amber-500/10 text-amber-700 border-amber-500/20",
                    )}
                  >
                    Crédito
                  </Badge>
                ) : (
                  <span>{txLabel(typeNum)}</span>
                )}
                {docLabel && <span className="tabular-nums">#{docLabel}</span>}
                {formattedDate !== "—" && <span className="tabular-nums">{formattedDate}</span>}
                {isVoided && <Badge variant="destructive">Anulada</Badge>}
              </div>
              {isVoided && (
                <p className="mt-1 text-xs text-muted-foreground">
                  {detail.voidedAt && formatDateTime(detail.voidedAt)}
                  {detail.voidedByName && ` · ${detail.voidedByName}`}
                  {detail.voidReason && ` · ${detail.voidReason}`}
                </p>
              )}
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
                    {(canOfferReturn || canOfferVoid) && <DropdownMenuSeparator />}
                    {canOfferVoid && (
                      <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onSelect={() => setVoidDialogOpen(true)}
                      >
                        Anular
                      </DropdownMenuItem>
                    )}
                    {canOfferReturn && (
                      <DropdownMenuItem onSelect={() => setReturnSheetOpen(true)}>Devolución</DropdownMenuItem>
                    )}
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </div>
          </div>

          {/* ── Hero financiero (solo crédito) ────────────────────────────── */}
          {isCredit && (
            <div className="mt-6">
              {/* Cards + barra SIEMPRE visibles; al saldar, Deuda=0 verde y barra full verde. */}
              <div className="grid grid-cols-2 gap-4">
                <div className="border rounded-lg p-4">
                  <p className="text-xs uppercase tracking-wide text-muted-foreground">Deuda</p>
                  <p
                    className={cn(
                      "text-2xl font-bold tabular-nums mt-1",
                      debt > 0 ? "text-destructive" : "text-emerald-600 dark:text-emerald-400",
                    )}
                  >
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
                <Progress
                  value={total > 0 ? (paid / total) * 100 : 0}
                  className={cn(
                    "flex-1 h-2",
                    debt <= 0 && "[&_[data-slot=progress-indicator]]:bg-emerald-500",
                  )}
                />
                <span className="text-xs text-muted-foreground tabular-nums shrink-0">
                  {total > 0 ? Math.round((paid / total) * 100) : 0}% &middot; Total {formatMoney(total, config)}
                </span>
              </div>
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
                      const rDate = r.date ? parseNaive(r.date) : null
                      const rDateStr = rDate
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

      {/* Picker de impresora (fallback cuando no hay binding para el docType) */}
      {pickerDialog}

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
          paymentMethods={paymentMethods}
          config={config}
          onSuccess={(data) => {
            setCreditPayOpen(false)
            setCreditReceipt({ encId: data.encId, amount: data.amount })
          }}
        />
      )}

      {/* Post-cobro de crédito: ofrece imprimir el RECIBO del pago (docType
          "receipt") — la factura es la venta original, este es el
          comprobante de dinero que respalda el pago. */}
      {creditReceipt && (
        <TransactionSuccessDialog
          open
          title="Pago registrado"
          amount={formatMoney(creditReceipt.amount, config)}
          closeLabel="Cerrar"
          onPrint={() => handlePrintCreditReceipt(creditReceipt.encId)}
          onClose={() => setCreditReceipt(null)}
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
            const rDate = receiptDetail.date ? parseNaive(receiptDetail.date) : null
            const rDateStr = rDate
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

      {/* Anulación (F6, context/40) — solo se monta con la tx del detalle
          actual resuelta (evita pedir /api/pos/sales-void con un id viejo
          si el cajero cambió de selección mientras el dialog estaba cerrado). */}
      {voidDialogOpen && (
        <PosVoidSaleDialog
          open={voidDialogOpen}
          onOpenChange={setVoidDialogOpen}
          transactionId={detail.transactionId}
          invoiceLabel={docLabel}
          total={total}
          dateLabel={formattedDate}
          onOfferReturn={() => {
            setVoidDialogOpen(false)
            setReturnSheetOpen(true)
          }}
        />
      )}

      {/* Devolución — mismo patrón que components/domain/transactions/transactions-list.tsx
          (panel): parentTransactionId = UUID crudo, no el encId de la lista. */}
      <PosReturnSheet
        open={returnSheetOpen}
        onOpenChange={(v) => {
          setReturnSheetOpen(v)
          if (!v) {
            queryClient.invalidateQueries({ queryKey: ["pos-transaction"] })
            queryClient.invalidateQueries({ queryKey: ["pos-transactions"] })
          }
        }}
        parentTransactionId={detail.transactionId}
      />
    </TooltipProvider>
  )
}
