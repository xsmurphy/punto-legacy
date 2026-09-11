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
import {
  Ban,
  CalendarIcon,
  ChevronLeft,
  Download,
  FileCheck,
  Filter,
  Loader2,
  MoreHorizontal,
  Receipt,
  X,
} from "lucide-react"
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
import { useOnlineStatus } from "@/hooks/use-online-status"
import { useIsMobile } from "@/hooks/use-mobile"
import { ActionMenu } from "@/components/ui/action-menu"
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
// Agrupado del CDC — el MISMO helper que usan el ticket y el KuDE en PDF.
import { groupCdc } from "@/lib/einvoice/kude"
import { triggerDownload } from "@/lib/download-blob"
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
// Anulación de venta: MISMO componente que el detalle del panel. Lo que
// cambia por realm entra por props (`transport`, `formatAmount`), no por una
// copia — ver el docblock de components/domain/transactions/void-sale-dialog.tsx.
import { VoidSaleDialog } from "@/components/domain/transactions/void-sale-dialog"
import {
  isCreditSale,
  isQuote,
  isReturn,
  isVoided,
  saleTypeLabel,
} from "@/lib/domain/sale-type"

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Anulada por `SaleVoidService` (`voidedAt`) o por la anulación legacy
 * (tipo 7). Para el cajero es lo mismo, así que la fila las pinta igual.
 */
function rowIsVoided(item: PosTransactionListItem): boolean {
  return Boolean(item.voidedAt) || isVoided(item.type)
}

function chipStyle(item: PosTransactionListItem): string {
  if (rowIsVoided(item)) return "bg-muted text-muted-foreground line-through"
  if (isQuote(item.type)) return "bg-secondary text-secondary-foreground border"
  if (isReturn(item.type) || isVoided(item.type)) return "bg-muted text-muted-foreground"
  if (isCreditSale(item.type)) {
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
  const isMobile = useIsMobile()
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
      {/* `max-sm:p-0` desactiva el padding que `mobileFullscreen` pone por
          default: este diálogo administra su propio chrome (`p-0` + header con
          borde + grid a ancho completo), así que el inset superior lo descuenta
          el header, no el content. La X de cerrar la reposiciona el primitive
          sola cuando el modal es fullscreen — antes caía dentro del status bar
          y no había forma de cerrar el diálogo en el teléfono (reporte del
          owner 2026-08-25). Ver `app/globals.css` § "Áreas seguras del
          dispositivo". */}
      <DialogContent
        mobileFullscreen
        // TECLADO VIRTUAL. Radix enfoca el primer elemento focuseable al abrir
        // y acá ese es el buscador: en el teléfono el módulo arrancaba con
        // medio viewport tapado por el teclado, sobre un listado que el cajero
        // entra a MIRAR, no a filtrar (reporte del owner 2026-08-25). En
        // desktop el autofocus sí es lo que se quiere —se abre y se tipea— así
        // que solo se cancela bajo el breakpoint. El foco va al content, que
        // Radix ya monta con `tabIndex={-1}`: sin eso quedaría en el `<body>`
        // y ni ESC ni el Tab arrancarían dentro del diálogo.
        onOpenAutoFocus={(e) => {
          if (!isMobile) return
          e.preventDefault()
          ;(e.currentTarget as HTMLElement | null)?.focus()
        }}
        // El reparto del alto en fullscreen (header fijo + cuerpo que toma lo
        // que sobra) lo hace el primitive: `mobileFullscreen` pasa a `flex
        // flex-col` bajo `sm`. Acá vivía a mano hasta que el mismo síntoma
        // —una franja de `bg-popover` contra el borde inferior— apareció en el
        // menú del POS y el layout se subió al wrapper.
        className="sm:max-w-6xl p-0 max-sm:p-0 gap-0 overflow-hidden"
      >
        {/* `pt` con `--safe-t` solo en móvil: acá el header apoya en el borde
            superior del dispositivo. En desktop es un modal centrado y el
            `pt-6` de siempre alcanza. */}
        <DialogHeader className="px-6 pt-6 pb-3 border-b shrink-0 max-sm:pt-[calc(1.5rem+var(--safe-t))]">
          <DialogTitle className="text-2xl font-semibold">Transacciones</DialogTitle>
        </DialogHeader>
        {/* Mobile: 1 columna, navega entre lista <-> detalle según selectedId.
            Desktop (>=md): split 2-col clásico. */}
        {/* En móvil (fullscreen) este bloque toma el alto que sobra y su fila
            se estira: sin `max-h` de viewport, que es lo que dejaba la franja
            contra el borde. `pb` con `--safe-b` porque es lo último del modal,
            así que la última fila del listado termina arriba del indicador de
            gestos y no debajo. De `sm` para arriba vuelve el modal centrado con
            su tope de siempre. */}
        <div className="grid grid-cols-1 md:grid-cols-[1fr_1.2fr] max-h-[90dvh] md:max-h-[80dvh] min-h-0 max-sm:max-h-none max-sm:min-h-0 max-sm:flex-1 max-sm:grid-rows-[minmax(0,1fr)] max-sm:pb-[var(--safe-b)]">
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
        {/* `items-center` + misma superficie en los tres controles: el campo
            relleno sin borde (`bg-input/50`) al lado de dos botones `outline`
            se leía como un control de otra familia —y más alto— aunque los
            tres midan lo mismo (44px en móvil por el mínimo táctil de
            `globals.css`, `h-8` en desktop). Reporte del owner 2026-08-25. La
            fila se mantiene en UNA sola línea a propósito: es la barra de
            filtros del listado, no un formulario. */}
        <div className="flex items-center gap-2">
          <Input
            placeholder="Buscar por cliente, RUC/CI, comprobante o ID"
            value={searchInput}
            onChange={(e) => onSearchChange(e.target.value)}
            className="min-w-0 flex-1 border-border bg-background dark:bg-transparent"
          />
          <Popover open={calendarOpen} onOpenChange={onCalendarOpenChange}>
            <PopoverTrigger asChild>
              <Button variant="outline" className="gap-1.5 shrink-0 max-sm:h-11">
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
              <Button variant="outline" className="gap-1.5 shrink-0 max-sm:h-11">
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
  const voided = rowIsVoided(item)

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
        <span
          className={cn(
            "text-sm font-semibold tabular-nums shrink-0",
            // El monto de una venta anulada ya no entra a la caja: se tacha en
            // vez de esconderse, porque el cajero necesita reconocer la venta
            // por su importe para saber que es la que anuló.
            voided && "text-muted-foreground line-through",
          )}
        >
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
        {/* El chip ocupa SIEMPRE el mismo lugar (§10, posiciones estables): en
            una venta anulada dice "Anulada" EN LUGAR del tipo, no además. El
            tipo de una venta que ya no existe no es lo que el cajero necesita
            leer, y agregar un segundo chip correría el resto de la fila. */}
        <Badge variant="secondary" className={cn("shrink-0", chipStyle(item))}>
          {voided ? "Anulada" : saleTypeLabel(item.type)}
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
  const [downloadingKude, setDownloadingKude] = React.useState(false)
  // El KuDE se baja del servidor: sin red no hay PDF que entregar. Se consulta
  // acá para poder decir el motivo EN el control, no después del intento.
  const online = useOnlineStatus()

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
  const isSaleType = typeNum === 0 || typeNum === 3
  // Devoluciones vigentes (backend: `returns` del detalle). El menú tiene que
  // ofrecer exactamente lo que el servidor aceptaría, no menos y no más:
  //   - "Anular" se rechaza con HAS_RETURNS si hay CUALQUIER devolución
  //     vigente (SaleVoidService) — se oculta con count > 0.
  //   - "Devolución" se rechaza si no queda cupo — se oculta con
  //     fullyReturned.
  // El default conservador ante un backend viejo que no mande `returns` es
  // seguir ofreciendo: el guard que MANDA es el del servidor, que igual
  // rechaza; ocultar de más dejaría al cajero sin la acción legítima.
  const returnsCount = detail.returns?.count ?? 0
  const fullyReturned = detail.returns?.fullyReturned === true
  const canOfferVoid = isSaleType && !isVoided && returnsCount === 0
  const canOfferReturn = isSaleType && !isVoided && !fullyReturned

  const items = detail.transactionDatas ?? []
  const payments = detail.pMethods ?? []
  const discount = Number(detail.discount ?? 0)
  const total = Number(detail.total ?? 0)

  const debt = detail.creditPayments?.debt ?? 0
  const paid = total - debt
  const primary = getPrimaryAction(typeNum, debt)

  // ── Factura electrónica ───────────────────────────────────────────────────
  //
  // El documento VIGENTE de esta venta. El backend los devuelve por fecha desc,
  // pero "el más reciente" no alcanza: una reemisión fallida deja el `error`
  // arriba y el documento bueno abajo, así que se descarta lo reemplazado
  // (`supersededBy`) igual que hace el portal del cliente.
  //
  // Lista vacía = nunca se encoló (tenant sin FE, emisión automática apagada,
  // cliente sin RUC con el filtro puesto) y entonces NO se pinta nada — no es
  // un error y la caja no tiene nada que hacer al respecto.
  //
  // La caja NO ofrece emitir: emitir es una acción de gestión (vive en el panel,
  // detrás de `einvoice.manage`). Acá solo se consulta y se descarga.
  const einvoiceDocs = detail.einvoiceDocuments ?? []
  const einvoiceDoc = einvoiceDocs.find((d) => d.supersededBy === null) ?? einvoiceDocs[0] ?? null

  // ¿Se le puede entregar al cliente? Lo decide el BACKEND con el mismo
  // predicado que aplican el email y el portal (`deliveryBlocker`). La pantalla
  // lo traduce; no lo recalcula. Si lo recalculara, volvería a existir el caso
  // de ofrecer una descarga que el endpoint rechaza con 409.
  // ¿Hay documento que la anulación pueda cancelar? `issued` y nada más: es
  // exactamente el estado que busca `SaleVoidService` (con `superseded_by IS
  // NULL`) para cancelar en cascada. Un documento `sending` no tiene qué
  // cancelar todavía y prometerlo sería mentirle al cajero.
  const einvoiceCancelable = einvoiceDoc?.status === "issued"

  const kudeBlocker = einvoiceDoc?.deliveryBlocker ?? null
  const canDeliverKude = einvoiceDoc !== null && kudeBlocker === null

  // Motivo LEGIBLE del bloqueo, o null si no hay bloqueo.
  //
  // FAIL-CLOSED: el `default` del switch cubre cualquier código que este front
  // todavía no conozca (uno nuevo en el backend, o `not_found`). Un motivo que
  // no sabemos traducir sigue siendo un motivo — dejar el botón habilitado
  // "porque no reconozco el código" es justo al revés de lo que corresponde
  // sobre un documento fiscal.
  //
  // La conectividad se evalúa acá y no en el backend porque es una condición
  // del dispositivo: el KuDE se baja del servidor.
  //
  // Va como TEXTO VISIBLE además de deshabilitar el botón: el POS es táctil y
  // un tooltip no se abre con el dedo, así que el impedimento tiene que leerse
  // sin hover.
  function blockerLabel(code: string): string {
    switch (code) {
      case "numbering_mismatch":
        return "El número del documento no coincide con el del comprobante que se le entregó al cliente. No se puede entregar."
      case "superseded":
        return "Este documento fue reemplazado por una reemisión."
      case "cancelled":
        return "El documento está anulado: no se puede entregar como comprobante."
      case "not_issued":
        return "El documento salió pero todavía no volvió su CDC."
      case "sifen_rejected":
        return "SIFEN rechazó este documento, así que no se puede entregar como factura."
      case "sifen_pending":
        return "SIFEN todavía no lo aprobó. Vas a poder entregarlo cuando figure como aprobado."
      default:
        return "Este documento no se puede entregar todavía."
    }
  }
  const kudeBlockedReason: string | null =
    kudeBlocker !== null
      ? blockerLabel(kudeBlocker)
      : canDeliverKude && !online
        ? "Sin conexión. El KuDE se descarga del servidor."
        : null

  // Dos superficies, y el corte es "¿es un problema que el comercio tiene que
  // resolver?". El rechazo de SIFEN y la discrepancia de numeración lo son —
  // en el segundo caso, además, el CDC que muestra el documento es el de OTRA
  // operación, así que NO se pinta. El resto (en emisión, anulada, reemplazada)
  // es estado, no falla.
  const einvoiceFailed =
    einvoiceDoc !== null &&
    (einvoiceDoc.status === "error" ||
      kudeBlocker === "sifen_rejected" ||
      kudeBlocker === "numbering_mismatch")
  // El título sale del ESTADO REAL, no de un booleano "se puede o no se puede":
  // una factura anulada o reemplazada no está "en emisión", y decirlo en la
  // pantalla que mira el cajero es afirmar algo falso sobre un documento
  // fiscal. "Emitida" además se reserva para cuando se puede entregar —
  // `status` no alcanza, hay un caso registrado de un documento `issued` con
  // CDC válido que SIFEN rechazó después.
  const einvoiceCardTitle =
    kudeBlocker === "cancelled"
      ? "Factura electrónica anulada"
      : kudeBlocker === "superseded"
        ? "Factura electrónica reemplazada"
        : canDeliverKude
          ? "Factura electrónica emitida"
          : "Factura electrónica en emisión"

  async function handleDownloadKude(docId: string) {
    setDownloadingKude(true)
    try {
      const blob = await posApi.getBlob(
        `/pos/einvoice/kude?id=${encodeURIComponent(docId)}`,
      )
      triggerDownload(blob, `kude-${docLabel || docId}.pdf`)
    } catch (err) {
      // El backend manda el motivo real en el envelope (409 "todavía no está
      // listo" / "no se emitió"); se muestra tal cual en vez de un genérico.
      toast.error(err instanceof Error ? err.message : "No se pudo descargar el KuDE")
    } finally {
      setDownloadingKude(false)
    }
  }

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
    // Facturar es una acción de venta: la caja pasa a modo venta. Sin esto el
    // carrito se cargaba pero el modo seguía siendo el que estaba (cotización
    // sticky, típicamente), así que el CTA seguía diciendo "Cotizar" y el
    // cajero generaba otra cotización en vez de cobrar.
    useCartStore.getState().beginSale()
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
          {/* pr-8: el botón de cerrar del Dialog es `absolute top-4 right-4
              size-7` (components/ui/dialog.tsx) y flota SOBRE el contenido —
              ocupa hasta 44px desde el borde derecho. Los 24px del `p-6` no
              alcanzan y la X caía encima del total (reporte del owner). El
              cierre queda donde va (arriba a la derecha); lo que se corrige es
              el espacio que el header le reserva: 24 + 32 = 56px de gutter
              derecho, 12px de aire. Vale para los DOS consumidores de
              TransactionDetail — el split de `PosTransactionsDialog` y el
              standalone de `PosTransactionDetailDialog` — porque en ambos el
              panel de detalle va pegado al borde derecho del modal. */}
          {/* En teléfono el header apila: el nombre toma el ancho completo (2
              líneas si hace falta) y el monto+acciones bajan a su propia fila
              — lado a lado quedaba "BENITEZ MARTI…" con todo comprimido
              (screenshot del owner 2026-08-26). En desktop, el lado a lado de
              siempre. */}
          <div className="flex items-start justify-between gap-3 pr-8 max-sm:flex-col">
            <div className="min-w-0 flex-1 max-sm:w-full max-sm:flex-none">
              <h2 className="text-xl font-semibold sm:truncate max-sm:line-clamp-2">
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
                  <span>{saleTypeLabel(typeNum)}</span>
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
            <div className="shrink-0 flex flex-col items-end gap-2 max-sm:w-full max-sm:flex-row max-sm:items-center max-sm:justify-between">
              <p className="text-2xl font-bold tabular-nums">{formatMoney(total, config)}</p>
              {/* Split button.

                  Altos EXPLÍCITOS por breakpoint en los dos lados
                  (`max-sm:h-11` / `max-sm:size-11`): la primera versión
                  confiaba en `items-stretch` + el mínimo táctil global, pero
                  ese mínimo alcanzaba al CTA y no al trigger de icono — el
                  "Facturar" salía gigante con el `...` chico y solapado
                  (reporte del owner 2026-08-25, dos veces). Con las clases en
                  cada botón no depende de ninguna regla global. */}
              <div className="inline-flex items-stretch">
                {primary.disabled ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span>
                        <Button size="sm" className="rounded-r-none border-r-0 opacity-60 max-sm:h-11" disabled>
                          {primary.label}
                        </Button>
                      </span>
                    </TooltipTrigger>
                    <TooltipContent>Próximamente</TooltipContent>
                  </Tooltip>
                ) : (
                  <Button
                    size="sm"
                    className="rounded-r-none border-r-0 max-sm:h-11"
                    onClick={handlePrimaryAction}
                  >
                    {primary.label}
                  </Button>
                )}
                <ActionMenu
                  title="Acciones de la transacción"
                  trigger={
                    <Button size="icon-sm" className="rounded-l-none max-sm:size-11" aria-label="Más acciones">
                      <MoreHorizontal className="size-4" />
                    </Button>
                  }
                  actions={[
                    {
                      label: "Duplicar",
                      hidden: !showSecondaryDuplicate,
                      onSelect: handleDuplicate,
                    },
                    { label: "Reimprimir", onSelect: handleReprint },
                    {
                      label: "Ver PDF",
                      hidden: typeNum !== 9,
                      onSelect: () => setQuotePdfOpen(true),
                    },
                    {
                      label: "Devolución",
                      hidden: !canOfferReturn,
                      onSelect: () => setReturnSheetOpen(true),
                    },
                    {
                      label: "Anular",
                      variant: "destructive",
                      hidden: !canOfferVoid,
                      onSelect: () => setVoidDialogOpen(true),
                    },
                  ]}
                />
              </div>
            </div>
          </div>

          {/* ── Hero financiero (solo crédito) ────────────────────────────── */}
          {isCredit && (
            <div className="mt-6">
              {/* Cards + barra SIEMPRE visibles; al saldar, Deuda=0 verde y barra full verde. */}
              {/* `gap-3` + padding y cuerpo menores en teléfono: con dos
                  columnas fijas y `text-2xl`, un monto de 7+ dígitos no entra
                  en la mitad del ancho y se parte contra el borde de la tarjeta
                  (reporte del owner 2026-08-26). El monto además baja un
                  escalón de tamaño bajo `sm` — la jerarquía la sigue dando el
                  color, no el cuerpo. Se mantienen las DOS columnas: apilarlas
                  empujaría los ítems fuera de la primera pantalla. */}
              <div className="grid grid-cols-2 gap-3 sm:gap-4">
                <div className="min-w-0 border rounded-lg p-3 sm:p-4">
                  <p className="text-xs uppercase tracking-wide text-muted-foreground">Deuda</p>
                  <p
                    className={cn(
                      "mt-1 text-xl font-bold tabular-nums break-words sm:text-2xl",
                      debt > 0 ? "text-destructive" : "text-emerald-600 dark:text-emerald-400",
                    )}
                  >
                    {formatMoney(debt, config)}
                  </p>
                </div>
                <div className="min-w-0 border rounded-lg p-3 sm:p-4">
                  <p className="text-xs uppercase tracking-wide text-muted-foreground">Pagado</p>
                  <p className="mt-1 text-xl font-bold tabular-nums break-words text-muted-foreground sm:text-2xl">
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

          {/* ── Notas de crédito ─────────────────────────────────────────────
              Las devoluciones de esta venta, que es lo que el cajero necesita
              ver parado en la factura: si ya se devolvió algo y por cuánto.
              El backend ya las mandaba (`creditNotes`, derivadas por
              `transaction_link` kind 'return') — el POS no las pintaba.

              Bloque condicional y al final del contenido, igual que el de
              factura electrónica: aparece solo cuando hay algo que mostrar y
              no empuja ningún control que ya estuviera en pantalla (§10,
              posiciones estables). */}
          {detail.creditNotes && detail.creditNotes.length > 0 && (
            <div className="mt-4 rounded-lg bg-muted/40 p-4">
              <h3 className="mb-2 text-sm font-medium text-muted-foreground">
                Notas de crédito ({detail.creditNotes.length})
              </h3>
              <div className="divide-y divide-border/60">
                {detail.creditNotes.map((cn) => {
                  const cnDate = cn.transactionDate ? parseNaive(cn.transactionDate) : null
                  return (
                    <div key={cn.transactionId} className="flex items-center justify-between gap-3 py-2 text-sm">
                      <div className="min-w-0 flex-1">
                        <span className="block truncate">
                          {cn.invoiceNo ? `Nota de crédito #${cn.invoiceNo}` : "Nota de crédito"}
                        </span>
                        <span className="text-xs text-muted-foreground tabular-nums">
                          {cnDate ? format(cnDate, "d MMM, HH:mm", { locale: es }) : "—"}
                        </span>
                      </div>
                      {/* El total de una devolución se guarda NEGATIVO
                          (`transactionTotal` = -500). Se muestra en positivo
                          con el signo adelante: "-Gs 500" y no "Gs -500". */}
                      <span className="shrink-0 tabular-nums text-destructive">
                        -{formatMoney(Math.abs(cn.transactionTotal), config)}
                      </span>
                    </div>
                  )
                })}
              </div>
            </div>
          )}

          {/* ── Factura electrónica ───────────────────────────────────────────
              Va ÚLTIMO, después de Pagos: es el bloque condicional del detalle
              y desde acá no puede empujar ningún control que ya estuviera en
              pantalla (regla #10, posiciones estables). Las acciones fijas de
              la transacción viven en el header y no se tocan — el botón del
              KuDE es del bloque, no de la barra.

              Ni el CDC ni el PDF prueban validez fiscal: hay un caso registrado
              de un documento con CDC válido que SIFEN rechazó después y cuyo
              KuDE se descargaba igual (ver EInvoiceService::reconcile). El
              único campo que dice si vale es `sifen_status`, que no viaja al
              POS — por eso el copy dice "emitida" y nunca "válida". */}
          {einvoiceDoc && !einvoiceFailed && (
            <div className="mt-4 rounded-lg bg-muted/40 p-4">
              <div className="flex items-start gap-3">
                <FileCheck className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div className="min-w-0 flex-1">
                  <h3 className="text-sm font-medium">{einvoiceCardTitle}</h3>
                  {einvoiceDoc.cdc ? (
                    /* CDC agrupado de a cuatro con el MISMO helper que usan el
                       ticket y el KuDE en PDF: es requisito de legibilidad de
                       la norma, y compartir la función es lo que garantiza que
                       se lea igual en las tres superficies. */
                    <p className="mt-1 break-all font-mono text-xs text-muted-foreground">
                      {groupCdc(einvoiceDoc.cdc)}
                    </p>
                  ) : (
                    <p className="mt-1 text-xs text-muted-foreground">
                      El documento salió pero todavía no volvió su CDC.
                    </p>
                  )}
                  {/* El motivo va siempre que exista — antes colgaba de que
                      hubiera CDC, que hoy es inocuo (el texto de `not_issued`
                      está duplicado en la rama de arriba) pero deja mudo a
                      cualquier bloqueo futuro que conviva con CDC vacío. */}
                  {kudeBlockedReason && kudeBlocker !== "not_issued" && (
                    <p className="mt-1 text-xs text-muted-foreground">{kudeBlockedReason}</p>
                  )}
                </div>
              </div>
              <Button
                variant="outline"
                size="sm"
                className="mt-3 w-full gap-1.5 max-sm:h-11"
                disabled={kudeBlockedReason !== null || downloadingKude}
                onClick={() => handleDownloadKude(einvoiceDoc.id)}
              >
                {downloadingKude ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <Download className="size-4" />
                )}
                Descargar KuDE
              </Button>
            </div>
          )}

          {/* Falla que el comercio tiene que resolver. Se muestra el ESTADO;
              el motivo de SIFEN es diagnóstico del comercio y no se le pasa al
              comprador (R1 de context/28 §F7). Tampoco se ofrece reemitir ni
              reintentar: eso es gestión y vive en el panel.

              El CDC NO se pinta en este bloque: con numeración discrepante, el
              CDC del documento es el de OTRA operación — mostrarlo al lado de
              esta venta es el error que el bloqueo viene a evitar. */}
          {einvoiceDoc && einvoiceFailed && (
            <div className="mt-4 rounded-lg border border-destructive/40 p-4">
              <div className="flex items-start gap-3">
                <Ban className="mt-0.5 size-4 shrink-0 text-destructive" />
                <div className="min-w-0 flex-1">
                  <h3 className="text-sm font-medium">
                    {einvoiceDoc.status === "error"
                      ? "La factura electrónica no se pudo emitir"
                      : kudeBlocker === "numbering_mismatch"
                        ? "La numeración del documento no coincide"
                        : "SIFEN rechazó la factura electrónica"}
                  </h3>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {einvoiceDoc.status === "error"
                      ? (einvoiceDoc.errorMessage ??
                        "El motor de facturación la rechazó y no informó el motivo.")
                      : blockerLabel(kudeBlocker ?? "")}
                  </p>
                  {einvoiceDoc.status === "error" && einvoiceDoc.attempts > 0 && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      Intentos automáticos: {einvoiceDoc.attempts}
                    </p>
                  )}
                </div>
              </div>
            </div>
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
        <VoidSaleDialog
          open={voidDialogOpen}
          onOpenChange={setVoidDialogOpen}
          transactionId={detail.transactionId}
          invoiceLabel={docLabel}
          total={total}
          dateLabel={formattedDate}
          formatAmount={(v) => formatMoney(v, config)}
          // Bearer del device — NUNCA el cliente del panel (`api`), que es el
          // cruce de realms del invariante de hooks/use-sale-void.ts.
          transport={{ client: posApi, path: "/pos/sales-void" }}
          einvoiceIssued={einvoiceCancelable}
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
