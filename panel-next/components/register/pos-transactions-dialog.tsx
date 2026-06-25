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
import { format } from "date-fns"
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
import { Separator } from "@/components/ui/separator"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
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
import { CreditPaymentDialog } from "@/components/register/credit-payment-dialog"

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
  if (typeNum === 9) return { label: "Facturar", action: "invoice", disabled: true }
  return { label: "Duplicar", action: "duplicate", disabled: false }
}

function TransactionDetail({ encId, onClose }: { encId: string | null; onClose: () => void }) {
  const { data: detail, isLoading } = usePosTransactionDetail(encId)
  const config = useCatalogStore((s) => s.config)
  const addLines = useCartStore((s) => s.addLines)
  const [quotePdfOpen, setQuotePdfOpen] = React.useState(false)
  const [creditPayOpen, setCreditPayOpen] = React.useState(false)

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
  const subtotal = discount > 0 ? total + discount : total

  const debt = detail.creditPayments?.debt ?? 0
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
    onClose()
    toast.success("Items duplicados al carrito")
  }

  function handlePrimaryAction() {
    if (primary.action === "pay") {
      setCreditPayOpen(true)
    } else if (primary.action === "duplicate") {
      handleDuplicate()
    }
    // "invoice" es disabled — no llega acá
  }

  function handleReprint() {
    toast.info(`Reimprimir #${docLabel || (detail?.transactionId ?? "")} — abriendo vista de impresión...`)
    window.print()
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

          {/* ── Title row ─────────────────────────────────────────────────── */}
          <div className="flex items-center justify-between gap-3">
            <h2 className="text-base font-semibold truncate">
              Comprobante #{docLabel || (detail.transactionId ?? "")}
            </h2>
            <div className="flex items-center gap-2 shrink-0">
              {/* Botón primario */}
              {primary.disabled ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span>
                      <Button
                        variant="default"
                        size="sm"
                        disabled
                        className="opacity-60"
                      >
                        {primary.label}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent>Próximamente</TooltipContent>
                </Tooltip>
              ) : (
                <Button
                  variant="default"
                  size="sm"
                  onClick={handlePrimaryAction}
                >
                  {primary.label}
                </Button>
              )}

              {/* Botón secundario Duplicar (solo cuando primary es Pagar o Facturar) */}
              {showSecondaryDuplicate && (
                <Button variant="outline" size="sm" onClick={handleDuplicate}>
                  Duplicar
                </Button>
              )}

              {/* Menú de acciones adicionales */}
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

          {/* ── Cabecera 2-col: Cliente + Detalles ────────────────────────── */}
          <div className="grid grid-cols-2 gap-6 mt-4">
            {/* Columna cliente */}
            <div>
              <p className="text-xs font-medium text-muted-foreground mb-1">Cliente</p>
              <p className="text-sm">
                {detail.customerName || (
                  <span className="text-muted-foreground">Sin asignar</span>
                )}
              </p>
            </div>

            {/* Columna detalles */}
            <div>
              <p className="text-xs font-medium text-muted-foreground mb-1">Detalles</p>
              <dl className="text-sm space-y-1">
                <div className="flex justify-between gap-4">
                  <dt className="text-muted-foreground">Fecha</dt>
                  <dd className="tabular-nums">{formattedDate}</dd>
                </div>
                <div className="flex justify-between gap-4">
                  <dt className="text-muted-foreground">Tipo</dt>
                  <dd>{txLabel(typeNum)}</dd>
                </div>
                {docLabel && (
                  <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Documento</dt>
                    <dd className="tabular-nums">{docLabel}</dd>
                  </div>
                )}
              </dl>
            </div>
          </div>

          {/* ── Items table ───────────────────────────────────────────────── */}
          <Separator className="my-4" />
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Concepto</TableHead>
                <TableHead className="w-16 text-right">Cant.</TableHead>
                <TableHead className="text-right">Importe</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.filter((i) => i.status !== 0).length === 0 ? (
                <TableRow>
                  <TableCell colSpan={3} className="text-sm text-muted-foreground py-4">
                    Sin items
                  </TableCell>
                </TableRow>
              ) : (
                items.filter((i) => i.status !== 0).map((item, idx) => (
                  <TableRow key={`${item.itemId}-${idx}`}>
                    <TableCell>{item.name}</TableCell>
                    <TableCell className="text-right tabular-nums">{item.count}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      Gs {formatMoney(item.total, config)}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>

          {/* ── Totals block ──────────────────────────────────────────────── */}
          <Separator className="my-4" />
          <div className="ml-auto max-w-xs">
            <dl className="space-y-1 text-sm">
              {discount > 0 && (
                <>
                  <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Subtotal</dt>
                    <dd className="tabular-nums">Gs {formatMoney(subtotal, config)}</dd>
                  </div>
                  <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Descuento</dt>
                    <dd className="tabular-nums text-destructive">-Gs {formatMoney(discount, config)}</dd>
                  </div>
                </>
              )}
            </dl>
            <Separator className="my-2" />
            <div className="flex justify-between gap-4 text-sm font-semibold">
              <span>Total</span>
              <span className="tabular-nums">Gs {formatMoney(total, config)}</span>
            </div>
          </div>

          {/* ── Pagos ─────────────────────────────────────────────────────── */}
          <Separator className="my-4" />
          <h3 className="text-sm font-semibold mb-2">Pagos</h3>
          {payments.length === 0 ? (
            <p className="text-sm text-muted-foreground">Sin pagos registrados</p>
          ) : (
            <div className="space-y-1 text-sm">
              {payments.map((p, i) => (
                <div key={i} className="flex justify-between">
                  <span>{p.name || p.type || "—"}</span>
                  <span className="tabular-nums">Gs {formatMoney(p.amount, config)}</span>
                </div>
              ))}
            </div>
          )}

          {/* ── Status final (crédito) ────────────────────────────────────── */}
          {isCredit && debt > 0 && (
            <>
              <Separator className="mt-4" />
              <div className="flex justify-between items-center mt-4 mb-2">
                <span className="text-sm font-medium">Pendiente</span>
                <span className="text-base font-semibold tabular-nums text-destructive">
                  Gs {formatMoney(debt, config)}
                </span>
              </div>
            </>
          )}
          {isCredit && debt === 0 && (
            <>
              <Separator className="mt-4" />
              <div className="flex justify-between items-center mt-4 mb-2">
                <span className="text-sm font-medium">Estado</span>
                <span className="text-base font-semibold tabular-nums text-emerald-600">Pagado</span>
              </div>
            </>
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
    </TooltipProvider>
  )
}
