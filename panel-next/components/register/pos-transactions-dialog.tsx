"use client"

/**
 * Modal de listado de transacciones del POS — T1.
 *
 * Layout: Dialog fullscreen con dos columnas: lista (izquierda) + detalle (derecha).
 * Listado con búsqueda debounced, filtro por fecha, paginación manual.
 * Detalle reutiliza useTransaction (BFF /api/pos/transactions/[id]).
 */

import * as React from "react"
import { CalendarIcon, MoreHorizontal, X } from "lucide-react"
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
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { Card, CardContent } from "@/components/ui/card"
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
  3: "Credito",
  6: "Devolucion",
  7: "Anulada",
  9: "Cotizacion",
  10: "Envio",
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

function niceDateTime(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString("es", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  })
}

function niceDateTimeFull(iso: string): string {
  if (!iso) return "—"
  try {
    return new Intl.DateTimeFormat("es", {
      day: "numeric",
      month: "long",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso.replace(" ", "T")))
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
      <DialogContent className="max-w-[95vw] w-[95vw] h-[90vh] p-0 overflow-hidden">
        <DialogHeader className="sr-only">
          <DialogTitle>Transacciones</DialogTitle>
        </DialogHeader>
        <div className="grid grid-cols-[1fr_1.2fr] h-full min-h-0">
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
    <div className="flex flex-col h-full min-h-0 border-r">
      {/* Header sticky */}
      <div className="sticky top-0 z-10 bg-background border-b px-4 pt-4 pb-3 flex flex-col gap-2">
        <h2 className="text-base font-semibold">Transacciones</h2>
        <div className="flex gap-2">
          <Input
            placeholder="Buscar por cliente, comprobante o ID..."
            value={searchInput}
            onChange={(e) => onSearchChange(e.target.value)}
            className="flex-1 h-8 text-sm"
          />
          <Popover open={calendarOpen} onOpenChange={onCalendarOpenChange}>
            <PopoverTrigger asChild>
              <Button variant="outline" size="sm" className="h-8 gap-1.5 shrink-0">
                <CalendarIcon className="size-3.5" />
                <span className="text-xs">
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
              size="sm"
              className="h-8 w-8 p-0 shrink-0"
              onClick={onDateClear}
            >
              <X className="size-3.5" />
            </Button>
          )}
        </div>
      </div>

      {/* Lista */}
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

        {!isFetching && items.length === 0 && !error && (
          <p className="text-muted-foreground text-center py-8 text-sm">
            No hay transacciones
          </p>
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
              variant="ghost"
              className="w-full text-sm"
              onClick={onFetchNext}
              disabled={isFetching}
            >
              {isFetching ? "Cargando..." : "Cargar mas"}
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
        "w-full text-left px-4 py-2.5 border-b transition-colors hover:bg-accent",
        selected && "bg-accent",
      )}
      onClick={() => onSelect(item.id)}
    >
      <div className="flex items-center justify-between gap-2">
        <span className={cn("font-semibold text-sm truncate", !item.customerName && "text-muted-foreground")}>
          {item.customerName || "Sin nombre"}
        </span>
        <span className="tabular-nums text-sm shrink-0">
          {formatMoney(item.rawTotal, config)}
        </span>
      </div>
      <div className="flex items-center gap-1.5 mt-0.5">
        <span className="text-xs text-muted-foreground">
          {niceDateTime(item.rawDate || item.date)}
        </span>
        {item.invoiceNo && (
          <span className="text-xs text-muted-foreground">
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
        <p className="text-muted-foreground text-sm">Selecciona una transaccion</p>
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

  const debt = detail.creditPayments?.debt ?? 0
  const actionConfig = getActionConfig(typeNum, debt)

  function handleDuplicate() {
    const validItems = items.filter((i) => i.status !== 0)
    if (validItems.length === 0) {
      toast.error("La transaccion no tiene items para duplicar")
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
    toast.info(`Reimprimir #${docLabel || (detail?.transactionId ?? "")} — abriendo vista de impresion...`)
    window.print()
  }

  return (
    <TooltipProvider>
      <div className="flex flex-col h-full min-h-0 border-l">
        <div className="flex-1 overflow-y-auto p-5 flex flex-col gap-5">
          {/* Header */}
          <div className="flex flex-col gap-2">
            <div className="flex items-start justify-between gap-2">
              <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                  <Badge variant={txBadgeVariant(typeNum)}>
                    {txLabel(typeNum).toUpperCase()}
                  </Badge>
                  {docLabel && (
                    <span className="text-xs text-muted-foreground">#{docLabel}</span>
                  )}
                </div>
                <p className="text-2xl font-bold leading-tight">
                  {detail.name || "Sin nombre"}
                </p>
              </div>
              {/* Acciones */}
              <div className="flex gap-2 shrink-0">
                {actionConfig.disabled ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span>
                        <Button variant="outline" size="sm" disabled>
                          {actionConfig.label}
                        </Button>
                      </span>
                    </TooltipTrigger>
                    <TooltipContent>Proximamente</TooltipContent>
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
                    <DropdownMenuItem disabled>
                      Anular
                    </DropdownMenuItem>
                    <DropdownMenuItem disabled>
                      Devolucion
                    </DropdownMenuItem>
                    <DropdownMenuItem disabled>
                      Agregar
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </div>
            {detail.date && (
              <p className="text-xs text-muted-foreground">{niceDateTimeFull(detail.date)}</p>
            )}
          </div>

          {/* Crédito: cards pagado/deuda */}
          {isCredit && detail.creditPayments && (
            <div className="grid grid-cols-2 gap-3">
              <Card>
                <CardContent className="pt-4 pb-4">
                  <p className="text-xs text-muted-foreground uppercase tracking-wide mb-1">Pagado</p>
                  <p className="text-xl font-bold tabular-nums">
                    {formatMoney(detail.creditPayments.paid, config)}
                  </p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="pt-4 pb-4">
                  <p className="text-xs text-muted-foreground uppercase tracking-wide mb-1">Deuda</p>
                  <p className="text-xl font-bold tabular-nums text-destructive">
                    {formatMoney(detail.creditPayments.debt, config)}
                  </p>
                </CardContent>
              </Card>
            </div>
          )}

          {/* Items */}
          {items.length > 0 && (
            <section>
              <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
                Items
              </p>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-12">Cant.</TableHead>
                    <TableHead>Nombre</TableHead>
                    <TableHead className="text-right">Precio</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {items.filter((i) => i.status !== 0).map((item, idx) => (
                    <TableRow key={`${item.itemId}-${idx}`}>
                      <TableCell className="tabular-nums">{item.count}</TableCell>
                      <TableCell>{item.name}</TableCell>
                      <TableCell className="tabular-nums text-right">
                        {formatMoney(item.total, config)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </section>
          )}

          {/* Descuento */}
          {discount > 0 && (
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Descuento</span>
              <span className="tabular-nums text-yellow-600">-{formatMoney(discount, config)}</span>
            </div>
          )}

          {/* Total */}
          <p className="text-3xl font-bold text-right tabular-nums">
            {formatMoney(total, config)}
          </p>

          {/* Pagos */}
          {payments.length > 0 && (
            <section>
              <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
                Pagos
              </p>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Metodo</TableHead>
                    <TableHead>Identificador</TableHead>
                    <TableHead className="text-right">Monto</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {payments.map((p, idx) => (
                    <TableRow key={idx}>
                      <TableCell>{p.name || p.type}</TableCell>
                      <TableCell className="text-muted-foreground text-xs">
                        {p.extra && p.extra !== p.type ? p.extra : (p.UID || "—")}
                      </TableCell>
                      <TableCell className="tabular-nums text-right">
                        {formatMoney(p.amount, config)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </section>
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
