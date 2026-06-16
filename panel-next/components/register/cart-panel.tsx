"use client"

/**
 * Panel derecho del register — carrito de venta.
 *
 * Fusión 2026-06-16 (POS dentro de panel-next):
 *   - El sidebar izquierdo es el AppSidebar del panel (NO un icon-rail propio).
 *   - La toolbar superior con las acciones propias de la caja (buscar
 *     producto / cliente / menú de acciones de venta) vuelve a vivir DENTRO
 *     del CartPanel — es lo único "propio de la caja".
 *   - Cart row expandido NO copia la grilla 4+5 cols del legacy. Usa
 *     stepper [−][qty][+] + acciones rápidas (vendedor / quitar / más).
 *   - Botón cobrar: `rounded-full` verde brand `bg-brand`.
 *   - Apertura de dialogs vía `usePosUIStore`.
 */

import * as React from "react"
import {
  User,
  X,
  Plus,
  Minus,
  Percent,
  DollarSign,
  Tag,
  MessageSquare,
  MoreHorizontal,
  Search,
  LayoutGrid,
  Move,
  Palette,
  Check,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Drawer,
  DrawerContent,
  DrawerHeader,
  DrawerTitle,
} from "@/components/ui/drawer"
import { QtyEditDialog } from "@/components/register/qty-edit-dialog"
import { cn } from "@/lib/utils"
import {
  useCartStore,
  selectCartTotal,
  selectCartIva,
  type CartLine,
} from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { formatMoney, formatAmount } from "@/lib/format-money"
import { usePosUIStore } from "@/lib/ui/store"
import { ProductSearchDialog } from "@/components/register/product-search-dialog"
import { CustomerDialog } from "@/components/register/customer-dialog"
import { PayDialog } from "@/components/register/pay-dialog"
import { SaleOptionsDrawer } from "@/components/register/sale-options-drawer"
import { PosMainMenu } from "@/components/register/pos-main-menu"
import { PuntoLogo } from "@/components/layout/punto-logo"

// ── CartPanel raíz ────────────────────────────────────────────────────────────

export function CartPanel() {
  const lines = useCartStore((s) => s.lines)
  const selectedLineId = useCartStore((s) => s.selectedLineId)
  const customer = useCartStore((s) => s.customer)
  const credito = useCartStore((s) => s.credito)
  const interno = useCartStore((s) => s.interno)
  const ivaRemoved = useCartStore((s) => s.ivaRemoved)
  const total = useCartStore(selectCartTotal)
  const iva = useCartStore(selectCartIva)
  const clear = useCartStore((s) => s.clear)
  const toggleCredito = useCartStore((s) => s.toggleCredito)
  const toggleInterno = useCartStore((s) => s.toggleInterno)
  const toggleIva = useCartStore((s) => s.toggleIva)
  const selectLine = useCartStore((s) => s.selectLine)
  const removeLine = useCartStore((s) => s.removeLine)
  const incQty = useCartStore((s) => s.incQty)
  const decQty = useCartStore((s) => s.decQty)
  const setQty = useCartStore((s) => s.setQty)

  const config = useCatalogStore((s) => s.config)

  // Modo edición de hotkeys: el panel de venta muestra una guía en su lugar.
  const editingHotkeys = useHotkeysStore((s) => s.editing)

  // Estado de dialogs — compartido con el PosSidebar via store global.
  const searchOpen = usePosUIStore((s) => s.searchOpen)
  const setSearchOpen = usePosUIStore((s) => s.setSearchOpen)
  const customerOpen = usePosUIStore((s) => s.customerOpen)
  const setCustomerOpen = usePosUIStore((s) => s.setCustomerOpen)
  const payOpen = usePosUIStore((s) => s.payOpen)
  const setPayOpen = usePosUIStore((s) => s.setPayOpen)

  const totalValue = total

  // Click afuera de la línea activa → deseleccionar (vuelve al detalle default).
  const activeRef = React.useRef<HTMLDivElement>(null)
  React.useEffect(() => {
    if (!selectedLineId) return
    function onPointerDown(e: PointerEvent) {
      if (activeRef.current && !activeRef.current.contains(e.target as Node)) {
        selectLine(null)
      }
    }
    document.addEventListener("pointerdown", onPointerDown)
    return () => document.removeEventListener("pointerdown", onPointerDown)
  }, [selectedLineId, selectLine])

  return (
    <div className="flex h-full flex-col border-l border-border bg-background">
      {/* ── Modales ── */}
      <ProductSearchDialog open={searchOpen} onOpenChange={setSearchOpen} />
      <CustomerDialog open={customerOpen} onOpenChange={setCustomerOpen} />
      <PayDialog open={payOpen} onOpenChange={setPayOpen} />

      {editingHotkeys ? (
        <HotkeyEditGuide />
      ) : (
        <>
      {/* ── Toolbar propia de la caja (buscar / cliente / acciones) ── */}
      <CartToolbar
        onSearch={() => setSearchOpen(true)}
        onCustomer={() => setCustomerOpen(true)}
        onCancelSale={clear}
      />

      {/* ── Chip de cliente ── */}
      <CustomerChip customer={customer} />

      {/* ── Lista de líneas ── */}
      <div className="flex-1 overflow-y-auto">
        {lines.length === 0 ? (
          <EmptyCart />
        ) : (
          <div className="flex flex-col">
            {lines.map((line) => {
              const isActive = line.lineId === selectedLineId
              return (
                <div key={line.lineId} ref={isActive ? activeRef : undefined}>
                  {isActive ? (
                    <CartRowExpanded
                      line={line}
                      onInc={() => incQty(line.lineId)}
                      onDec={() => decQty(line.lineId)}
                      onSetQty={(q) => setQty(line.lineId, q)}
                      onRemove={() => removeLine(line.lineId)}
                    />
                  ) : (
                    <CartRowCollapsed
                      line={line}
                      config={config}
                      onSelect={() => selectLine(line.lineId)}
                    />
                  )}
                </div>
              )
            })}
          </div>
        )}
      </div>

      {/* ── Bottom: toggles + botón cobrar ── */}
      <CartBottom
        credito={credito}
        interno={interno}
        ivaRemoved={ivaRemoved}
        iva={iva}
        onToggleCredito={toggleCredito}
        onToggleInterno={toggleInterno}
        onToggleIva={toggleIva}
        onClear={clear}
        total={totalValue}
        lineCount={lines.length}
        config={config}
        onPayClick={() => setPayOpen(true)}
      />
        </>
      )}
    </div>
  )
}

// ── Guía del modo edición de hotkeys ──────────────────────────────────────────

function HotkeyEditGuide() {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-7 px-6 text-center">
      <div className="flex flex-col items-center gap-2">
        <span className="flex size-12 items-center justify-center rounded-full bg-muted">
          <LayoutGrid className="size-6 text-muted-foreground" />
        </span>
        <h2 className="text-base font-semibold text-foreground">
          Editando Hotkeys
        </h2>
        <p className="max-w-[15rem] text-sm text-muted-foreground">
          Configurá la grilla de la caja. Tus cambios se guardan al tocar “Listo”.
        </p>
      </div>

      <ul className="flex w-full max-w-xs flex-col gap-3.5 text-left">
        <GuideStep icon={Plus} text="Tocá un slot vacío para agregar un artículo o categoría." />
        <GuideStep icon={Move} text="Arrastrá un hotkey para moverlo de lugar." />
        <GuideStep icon={Palette} text="Elegí un color para los hotkeys sin imagen." />
        <GuideStep icon={X} text="Tocá la ✕ de un hotkey para quitarlo." />
        <GuideStep icon={Check} text="Tocá “Listo”, arriba a la izquierda, para guardar." />
      </ul>
    </div>
  )
}

function GuideStep({
  icon: Icon,
  text,
}: {
  icon: React.ComponentType<{ className?: string }>
  text: string
}) {
  return (
    <li className="flex items-start gap-3">
      <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-muted">
        <Icon className="size-4 text-muted-foreground" />
      </span>
      <span className="text-sm text-foreground">{text}</span>
    </li>
  )
}

// ── Toolbar de la caja ────────────────────────────────────────────────────────
//
// Única UI "propia de la caja" en el shell del panel: buscar producto, asignar
// cliente y un menú "..." con acciones de venta. Los iconos van alineados a la
// derecha, estilo sobrio (ghost), consistente con el panel.

function CartToolbar({
  onSearch,
  onCustomer,
  onCancelSale,
}: {
  onSearch: () => void
  onCustomer: () => void
  onCancelSale: () => void
}) {
  // 4 botones distribuidos proporcionalmente a lo largo del toolbar (cada uno
  // ocupa un cuarto, centrado) — espejo del col-xs-3 del legacy.
  return (
    <div className="flex h-14 shrink-0 items-center px-1">
      <div className="flex flex-1 justify-center">
        <PosMainMenu />
      </div>
      <div className="flex flex-1 justify-center">
        <Button
          variant="ghost"
          size="icon"
          className="size-9"
          onClick={onSearch}
          aria-label="Buscar producto"
        >
          <Search className="size-5" />
        </Button>
      </div>
      <div className="flex flex-1 justify-center">
        <Button
          variant="ghost"
          size="icon"
          className="size-9"
          onClick={onCustomer}
          aria-label="Cliente"
        >
          <User className="size-5" />
        </Button>
      </div>
      <div className="flex flex-1 justify-center">
        <SaleOptionsDrawer onCancelSale={onCancelSale} />
      </div>
    </div>
  )
}

// ── Chip de cliente ───────────────────────────────────────────────────────────

function CustomerChip({
  customer,
}: {
  customer: ReturnType<typeof useCartStore.getState>["customer"]
}) {
  const setCustomer = useCartStore((s) => s.setCustomer)

  if (!customer) return null

  return (
    <div className="flex items-center gap-2 px-3 py-1.5">
      <div className="flex-1 min-w-0">
        <p className="truncate text-xs font-medium text-foreground">{customer.name}</p>
        {customer.tin && (
          <p className="text-[10px] text-muted-foreground">{customer.tin}</p>
        )}
      </div>
      <Button
        variant="ghost"
        size="icon-xs"
        onClick={() => setCustomer(null)}
        aria-label="Quitar cliente"
      >
        <X className="size-3" />
      </Button>
    </div>
  )
}

// ── Fila colapsada (no seleccionada) ──────────────────────────────────────────

function CartRowCollapsed({
  line,
  config,
  onSelect,
}: {
  line: CartLine
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onSelect: () => void
}) {
  const subtotal = line.qty * line.unitPrice
  const hasDiscount = (line.discount ?? 0) > 0
  const hasSeller = Boolean(line.sellerId)
  const hasTags = (line.tags?.length ?? 0) > 0
  const hasNote = Boolean(line.note && line.note.trim().length > 0)
  const showSubtitle = hasSeller || hasTags || hasNote

  return (
    <button
      onClick={onSelect}
      className={cn(
        "flex w-full items-center gap-2.5 px-3 py-2.5 text-left transition-colors hover:bg-muted/50",
        hasDiscount && "border-l-[3px] border-l-yellow-500",
      )}
    >
      <span
        className={cn(
          "flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-semibold tabular-nums",
          hasDiscount
            ? "border-yellow-500/40 bg-yellow-500/15 text-yellow-500"
            : "border-border bg-muted/40 text-muted-foreground",
        )}
      >
        {line.qty}
      </span>

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-foreground">{line.name}</p>
        {showSubtitle && (
          <div className="mt-0.5 flex items-center gap-2 text-[10px] text-muted-foreground">
            {hasSeller && (
              <span className="inline-flex items-center gap-1" title="Vendedor asignado">
                <User className="size-3" aria-hidden />
              </span>
            )}
            {hasTags && (
              <span className="inline-flex items-center gap-1" title="Etiquetas">
                <Tag className="size-3" aria-hidden />
              </span>
            )}
            {hasNote && (
              <span className="inline-flex items-center gap-1 truncate" title={line.note}>
                <MessageSquare className="size-3 shrink-0" aria-hidden />
                <span className="truncate">{line.note}</span>
              </span>
            )}
          </div>
        )}
      </div>

      <span className="shrink-0 text-sm font-semibold tabular-nums text-foreground">
        {formatAmount(subtotal, config)}
      </span>
    </button>
  )
}

// ── Fila expandida (seleccionada) ─────────────────────────────────────────────
//
// Diseño post-pivote (2026-06-16): NO grilla 4+5 del legacy.
// Header con nombre + stepper [−][qty][+] + acciones rápidas (vendedor/quitar/más).
// "más" abre Drawer (no Dropdown). Qty es clickable → numpad.

function CartRowExpanded({
  line,
  onInc,
  onDec,
  onSetQty,
  onRemove,
}: {
  line: CartLine
  onInc: () => void
  onDec: () => void
  onSetQty: (qty: number) => void
  onRemove: () => void
}) {
  const [qtyOpen, setQtyOpen] = React.useState(false)
  const [moreOpen, setMoreOpen] = React.useState(false)

  return (
    <div className="bg-accent/40 px-3 py-3">
      {/* Header — nombre del item en negrita. */}
      <div className="mb-2 text-center">
        <span className="truncate text-sm font-bold text-foreground">
          {line.name}
        </span>
      </div>

      {/* Layout: [−][qty][+]  ......  [Vendedor] [Quitar] [⋯]
          Botones cuadrados con fondo levemente más oscuro que el panel. */}
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-1.5">
          <LineToolButton onClick={onDec} aria-label="Disminuir cantidad">
            <Minus className="size-4" />
          </LineToolButton>
          {/* Cantidad clickable → numpad para tipear cantidades grandes. */}
          <button
            type="button"
            onClick={() => setQtyOpen(true)}
            aria-label="Editar cantidad"
            className={cn(
              "min-w-[2.5rem] rounded-md border border-border bg-muted px-2 py-0.5 text-center text-lg font-semibold tabular-nums",
              "transition-colors hover:bg-muted/70 active:bg-muted/60",
            )}
          >
            {line.qty}
          </button>
          <LineToolButton onClick={onInc} aria-label="Aumentar cantidad">
            <Plus className="size-4" />
          </LineToolButton>
        </div>

        <div className="flex items-center gap-1.5">
          <LineToolButton
            onClick={() => {
              // TODO (C2): abrir selector de vendedor.
            }}
            aria-label="Asignar vendedor"
          >
            <User className="size-4" />
          </LineToolButton>
          <LineToolButton
            onClick={onRemove}
            aria-label="Quitar de la venta"
            className="text-muted-foreground hover:border-destructive hover:text-destructive"
          >
            <X className="size-4" />
          </LineToolButton>
          <LineToolButton
            onClick={() => setMoreOpen(true)}
            aria-label="Más opciones"
          >
            <MoreHorizontal className="size-4" />
          </LineToolButton>
        </div>
      </div>

      {/* Numpad de cantidad. */}
      <QtyEditDialog
        open={qtyOpen}
        initialQty={line.qty}
        itemName={line.name}
        onConfirm={(q) => {
          onSetQty(q)
          setQtyOpen(false)
        }}
        onClose={() => setQtyOpen(false)}
      />

      {/* Más opciones — drawer inferior (no dropdown). Título = nombre del ítem. */}
      <Drawer open={moreOpen} onOpenChange={setMoreOpen}>
        <DrawerContent className="mx-auto max-w-lg">
          <DrawerHeader className="pb-2">
            <DrawerTitle className="truncate">{line.name}</DrawerTitle>
          </DrawerHeader>
          <div className="grid grid-cols-2 gap-2 p-4 pt-2 sm:grid-cols-3">
            <LineActionTile icon={DollarSign} label="Modificar precio" onClick={() => setMoreOpen(false)} />
            <LineActionTile icon={Percent} label="Aplicar descuento" onClick={() => setMoreOpen(false)} />
            <LineActionTile icon={Tag} label="Etiquetas" onClick={() => setMoreOpen(false)} />
            <LineActionTile icon={MessageSquare} label="Comentario" onClick={() => setMoreOpen(false)} />
          </div>
        </DrawerContent>
      </Drawer>
    </div>
  )
}

function LineToolButton({
  children,
  className,
  ...rest
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      type="button"
      className={cn(
        "flex size-8 items-center justify-center rounded-md border border-border bg-muted",
        "text-foreground transition-colors hover:bg-muted/70 active:bg-muted/60",
        "disabled:pointer-events-none disabled:opacity-50",
        className,
      )}
      {...rest}
    >
      {children}
    </button>
  )
}

function LineActionTile({
  icon: Icon,
  label,
  onClick,
}: {
  icon: React.ComponentType<{ className?: string }>
  label: string
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "flex flex-col items-center justify-center gap-2 rounded-xl border border-border bg-muted/30 px-3 py-4",
        "text-center transition-colors hover:bg-muted active:scale-[0.98]",
      )}
    >
      <Icon className="size-5 text-foreground" />
      <span className="text-xs font-medium text-foreground">{label}</span>
    </button>
  )
}

// ── Carrito vacío ─────────────────────────────────────────────────────────────

function EmptyCart() {
  return (
    <div className="flex h-full flex-col items-center justify-center select-none">
      <span aria-hidden className="flex justify-center opacity-[0.12]">
        <PuntoLogo variant="wordmark" className="h-14 w-[200px]" />
      </span>
    </div>
  )
}

// ── Bottom: toggles + cobrar ──────────────────────────────────────────────────

function CartBottom({
  credito,
  interno,
  ivaRemoved,
  iva,
  onToggleCredito,
  onToggleInterno,
  onToggleIva,
  onClear,
  total,
  lineCount,
  config,
  onPayClick,
}: {
  credito: boolean
  interno: boolean
  ivaRemoved: boolean
  iva: number
  onToggleCredito: () => void
  onToggleInterno: () => void
  onToggleIva: () => void
  onClear: () => void
  total: number
  lineCount: number
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onPayClick: () => void
}) {
  const totalFormatted = formatMoney(total, config)
  const ivaFormatted = formatMoney(iva, config)

  return (
    <div className="shrink-0 bg-background p-2 pt-2">
      {/* Toggles CRÉDITO / INTERNO / IVA — distribuidos de forma pareja */}
      <div className="mb-2 flex items-center justify-center gap-2">
        <ToggleChip
          label="CRÉDITO"
          active={credito}
          onClick={onToggleCredito}
        />
        <ToggleChip
          label="INTERNO"
          active={interno}
          onClick={onToggleInterno}
        />
        <button
          onClick={onToggleIva}
          aria-label={ivaRemoved ? "Restaurar IVA" : "Eliminar IVA"}
          className={cn(
            "rounded-full border px-2.5 py-0.5 text-[10px] font-bold tracking-wide transition-colors",
            ivaRemoved
              ? "border-border bg-transparent text-muted-foreground/50"
              : "border-border bg-transparent text-muted-foreground hover:border-muted-foreground hover:text-foreground",
          )}
        >
          {ivaFormatted}
        </button>
        {lineCount > 0 && (
          <button
            onClick={onClear}
            aria-label="Vaciar carrito"
            className="rounded-full border border-destructive/40 bg-transparent px-2.5 py-0.5 text-[10px] font-bold tracking-wide text-destructive transition-colors hover:bg-destructive/10"
          >
            VACIAR
          </button>
        )}
      </div>

      {/* Botón cobrar — pill neutro del design system (Button default, --primary).
          `rounded-full` 100% corner radius; solo override de tamaño. */}
      <Button
        disabled={lineCount === 0}
        onClick={lineCount > 0 ? onPayClick : undefined}
        className="h-auto w-full rounded-full px-4 py-3 text-3xl font-bold active:scale-[0.98]"
        aria-label={`Cobrar ${totalFormatted}`}
      >
        {lineCount === 0 ? "Sin items" : totalFormatted}
      </Button>
    </div>
  )
}

// ── Toggle chip ───────────────────────────────────────────────────────────────

function ToggleChip({
  label,
  active,
  onClick,
}: {
  label: string
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      onClick={onClick}
      className={cn(
        "rounded-full border px-2.5 py-0.5 text-[10px] font-bold tracking-wide transition-colors",
        active
          ? "border-brand bg-brand/20 text-brand"
          : "border-border bg-transparent text-muted-foreground hover:border-muted-foreground",
      )}
    >
      {label}
    </button>
  )
}
