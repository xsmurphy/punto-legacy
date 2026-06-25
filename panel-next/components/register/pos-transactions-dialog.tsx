"use client"

/**
 * Modal de listado de transacciones del POS — T1.
 *
 * Layout: Dialog split 2-col (bucket xl = sm:max-w-6xl).
 * Lista con búsqueda debounced, filtro por fecha, paginación manual.
 * Detalle reutiliza useTransaction (BFF /api/pos/transactions/[id]).
 */

import * as React from "react"
import { CalendarIcon, Loader2, MoreHorizontal, Receipt, X } from "lucide-react"
import { format, formatDistanceToNow } from "date-fns"
import { es } from "date-fns/locale"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { Progress } from "@/components/ui/progress"
import { Separator } from "@/components/ui/separator"
import { EmptyState } from "@/components/empty-state"
import { usePosTransactionsList, usePosTransactionDetail } from "@/hooks/use-pos-transactions"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney } from "@/lib/format-money"
import { cn } from "@/lib/utils"
import type { PosTransactionListItem } from "@/lib/types/pos-transactions"
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

type BadgeVariant = "default" | "secondary" | "destructive" | "outline"

function txBadgeVariant(type: number): BadgeVariant {
  if (type === 0) return "default"
  if (type === 3) return "destructive"
  if (type === 9) return "secondary"
  if (type === 7) return "outline"
  return "outline"
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

/**
 * Formato completo para el panel de detalle: "lunes 22 de junio de 2026, 12:59"
 */
function niceDateTimeFull(iso: string): string {
  if (!iso) return "—"
  try {
    const d = new Date(iso.replace(" ", "T"))
    if (Number.isNaN(d.getTime())) return iso
    return format(d, "EEEE d 'de' MMMM 'de' yyyy, HH:mm", { locale: es })
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

  const q = useDebounce(searchInput, 300)
  const date = selectedDate ? format(selectedDate, "yyyy-MM-dd") : ""

  const { flat, isFetching, hasMore, fetchNextPage, error } = usePosTransactionsList({ q, date })

  function handleClose() {
    onOpenChange(false)
    setSearchInput("")
    setSelectedDate(undefined)
    setSelectedId(null)
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      {/* Bucket xl — modal split 2-col (lista + detalle) */}
      <DialogContent className="sm:max-w-6xl p-0 gap-0 overflow-hidden">
        <DialogHeader className="px-6 pt-6 pb-3 border-b">
          <DialogTitle className="text-2xl font-semibold">Transacciones</DialogTitle>
          <DialogDescription className="text-sm text-muted-foreground">
            Últimas operaciones del comercio: ventas, cotizaciones, devoluciones y más. Filtrá por cliente, comprobante o fecha.
          </DialogDescription>
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
        <span className={cn("font-medium truncate", !item.customerName && "text-muted-foreground")}>
          {item.customerName || "Sin nombre"}
        </span>
        <span className="tabular-nums font-medium shrink-0">
          {formatMoney(item.rawTotal, config)}
        </span>
      </div>
      <div className="flex items-center gap-1.5 mt-0.5">
        <span className="text-sm text-muted-foreground">
          {niceDateTime(item.rawDate || item.date)}
        </span>
        {item.invoiceNo && (
          <span className="text-sm text-muted-foreground">
            #{item.invoicePrefix}{item.invoiceNo}
          </span>
        )}
        <Badge variant={txBadgeVariant(item.type)} className="text-[10px] px-1 py-0 h-4">
          {txLabel(item.type)}
        </Badge>
      </div>
    </button>
  )
}

// ── Detalle ───────────────────────────────────────────────────────────────────

function getActionConfig(typeNum: number, debt: number): { label: string; disabled: boolean } {
  if (typeNum === 9) return { label: "Facturar", disabled: true }
  if (typeNum === 3 && debt > 0) return { label: "Pagar", disabled: true }
  return { label: "Duplicar", disabled: false }
}

function TransactionDetail({ encId, onClose }: { encId: string | null; onClose: () => void }) {
  const { data: detail, isLoading } = usePosTransactionDetail(encId)
  const config = useCatalogStore((s) => s.config)
  const addLines = useCartStore((s) => s.addLines)
  const [quotePdfOpen, setQuotePdfOpen] = React.useState(false)

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
    : detail.documentNo

  const items = detail.transactionDatas ?? []
  const payments = detail.pMethods ?? []
  const discount = Number(detail.discount ?? 0)
  const total = Number(detail.total ?? 0)

  const paid = detail.creditPayments?.paid ?? 0
  const debt = detail.creditPayments?.debt ?? 0
  const actionConfig = getActionConfig(typeNum, debt)

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
    onClose()
    toast.success("Items duplicados al carrito")
  }

  function handleReprint() {
    toast.info(`Reimprimir #${docLabel || (detail?.transactionId ?? "")} — abriendo vista de impresión...`)
    window.print()
  }

  // Fecha relativa
  const dateObj = detail.date ? new Date(detail.date.replace(" ", "T")) : null
  const relativeDate = dateObj && !Number.isNaN(dateObj.getTime())
    ? formatDistanceToNow(dateObj, { addSuffix: true, locale: es })
    : null
  const fullDate = dateObj && !Number.isNaN(dateObj.getTime())
    ? niceDateTimeFull(detail.date)
    : null

  // Línea narrativa de pagos
  function buildPaymentLine(): string | null {
    if (payments.length === 0) return null
    if (payments.length === 1) {
      const p = payments[0]
      return `Pagado en ${p.name || p.type} ${formatMoney(p.amount, config)}`
    }
    if (payments.length === 2) {
      const [a, b] = payments
      return `Pagado en ${a.name || a.type} ${formatMoney(a.amount, config)} y ${b.name || b.type} ${formatMoney(b.amount, config)}`
    }
    // 3+ métodos: lista sin montos inline
    const names = payments.map((p) => p.name || p.type)
    const last = names.pop()
    return `Pagado en ${names.join(", ")} y ${last}`
  }

  const paymentLine = buildPaymentLine()

  return (
    <TooltipProvider>
      <div className="flex flex-col h-full min-h-0 border-l overflow-hidden">
        <div className="flex-1 overflow-y-auto p-5 flex flex-col gap-5">

          {/* ── Sección 1: Header ─────────────────────────────────────────── */}
          <div className="flex flex-col gap-1.5">
            {/* Fila 1: badge + número + botones */}
            <div className="flex items-center gap-2">
              <Badge variant={txBadgeVariant(typeNum)}>
                {txLabel(typeNum)}
              </Badge>
              {docLabel && (
                <span className="text-sm text-muted-foreground tabular-nums">#{docLabel}</span>
              )}
              {/* Botones empujados a la derecha */}
              <div className="flex gap-2 ml-auto shrink-0">
                {actionConfig.disabled ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span>
                        <Button variant="outline" size="sm" disabled>
                          {actionConfig.label}
                        </Button>
                      </span>
                    </TooltipTrigger>
                    <TooltipContent>Próximamente</TooltipContent>
                  </Tooltip>
                ) : (
                  <Button variant="outline" size="sm" onClick={handleDuplicate}>
                    {actionConfig.label}
                  </Button>
                )}
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-8">
                      <MoreHorizontal className="size-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={handleReprint}>
                      Reimprimir
                    </DropdownMenuItem>
                    {typeNum === 9 && (
                      <DropdownMenuItem onSelect={() => setQuotePdfOpen(true)}>
                        Ver PDF
                      </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem disabled>Anular</DropdownMenuItem>
                    <DropdownMenuItem disabled>Devolución</DropdownMenuItem>
                    <DropdownMenuItem disabled>Agregar</DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </div>

            {/* Fila 2: cliente · fecha relativa */}
            <p className="text-sm text-muted-foreground">
              {detail.customerName || "Sin cliente"}
              {relativeDate && (
                <>
                  {" · "}
                  {fullDate ? (
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <span className="cursor-default">{relativeDate}</span>
                      </TooltipTrigger>
                      <TooltipContent>{fullDate}</TooltipContent>
                    </Tooltip>
                  ) : (
                    relativeDate
                  )}
                </>
              )}
            </p>
          </div>

          {/* ── Sección 2: Total + Progress ───────────────────────────────── */}
          <div className="flex flex-col gap-2">
            <p className="text-3xl font-bold tabular-nums">
              {formatMoney(total, config)}
            </p>
            {discount > 0 && (
              <p className="text-sm text-muted-foreground">
                Descuento {formatMoney(discount, config)} aplicado
              </p>
            )}
            {isCredit && debt > 0 && (
              <div className="flex flex-col gap-1">
                <Progress value={(paid / total) * 100} className="h-1.5" />
                <p className="text-sm">
                  {paid > 0 && (
                    <span className="text-muted-foreground">
                      Pagado {formatMoney(paid, config)} · {" "}
                    </span>
                  )}
                  <span className="text-destructive">
                    Adeuda {formatMoney(debt, config)}
                  </span>
                </p>
              </div>
            )}
          </div>

          {/* ── Separador ─────────────────────────────────────────────────── */}
          <Separator />

          {/* ── Sección 3: Items ──────────────────────────────────────────── */}
          {items.length > 0 && (
            <div className="divide-y">
              {items.filter((i) => i.status !== 0).map((item, idx) => (
                <div key={`${item.itemId}-${idx}`} className="flex items-center gap-3 py-2.5">
                  <span className="flex-1 text-sm">{item.name}</span>
                  <span className="w-8 shrink-0 text-right text-sm tabular-nums text-muted-foreground">
                    {item.count}
                  </span>
                  <span className="shrink-0 text-sm tabular-nums">
                    {formatMoney(item.total, config)}
                  </span>
                </div>
              ))}
            </div>
          )}

          {/* ── Sección 4: Pagos (narrativa) ──────────────────────────────── */}
          {paymentLine && (
            <p className="text-sm text-muted-foreground">{paymentLine}</p>
          )}

        </div>
      </div>
      {typeNum === 9 && quotePdfOpen && (
        <QuotePrintViewDialog
          tx={detail}
          config={config}
          open={quotePdfOpen}
          onOpenChange={setQuotePdfOpen}
        />
      )}
    </TooltipProvider>
  )
}
