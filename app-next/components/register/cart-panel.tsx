"use client"

/**
 * Panel derecho del register — carrito de venta.
 *
 * Fidelidad §6.1:
 *   - Toolbar de iconos arriba (stubs).
 *   - Chip de cliente (display solo por ahora).
 *   - Lista de líneas: badge cantidad + nombre + precio.
 *   - Línea activa: nombre grande + controles +/x1/−/X roja + acciones de línea.
 *   - Tacho (vaciar carrito).
 *   - Bottom: toggles CRÉDITO/INTERNO + IVA + botón full-width verde con total.
 *   - Watermark con logo Punto en carrito vacío.
 */

import * as React from "react"
import {
  Menu,
  Search,
  User,
  MoreHorizontal,
  X,
  Plus,
  Minus,
  Trash2,
  Percent,
  DollarSign,
  Tag,
  MessageSquare,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import {
  useCartStore,
  selectCartTotal,
  selectCartIva,
  type CartLine,
} from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney, formatAmount } from "@/lib/format-money"
import { ProductSearchDialog } from "@/components/register/product-search-dialog"
import { CustomerDialog } from "@/components/register/customer-dialog"
import { PayDialog } from "@/components/register/pay-dialog"
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

  const config = useCatalogStore((s) => s.config)

  // ── Estado de apertura de modales ─────────────────────────────────────────
  const [productSearchOpen, setProductSearchOpen] = React.useState(false)
  const [customerDialogOpen, setCustomerDialogOpen] = React.useState(false)
  const [payDialogOpen, setPayDialogOpen] = React.useState(false)

  const totalValue = total

  // Click afuera de la línea activa → deseleccionar (vuelve al detalle default).
  // El ref envuelve la línea activa + sus tools; si el pointerdown cae afuera,
  // se cierra. Clickear otra línea: deselecciona y su onClick la selecciona.
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
      <ProductSearchDialog
        open={productSearchOpen}
        onOpenChange={setProductSearchOpen}
      />
      <CustomerDialog
        open={customerDialogOpen}
        onOpenChange={setCustomerDialogOpen}
      />
      <PayDialog
        open={payDialogOpen}
        onOpenChange={setPayDialogOpen}
      />

      {/* ── Toolbar ── */}
      <CartToolbar
        onSearchClick={() => setProductSearchOpen(true)}
        onCustomerClick={() => setCustomerDialogOpen(true)}
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

      {/* ── Tacho (vaciar) ── */}
      {lines.length > 0 && (
        <div className="flex justify-center py-2">
          <Button
            variant="ghost"
            size="icon-sm"
            onClick={clear}
            className="text-muted-foreground hover:text-destructive"
            aria-label="Vaciar carrito"
          >
            <Trash2 className="size-4" />
          </Button>
        </div>
      )}

      {/* ── Bottom: toggles + botón cobrar ── */}
      <CartBottom
        credito={credito}
        interno={interno}
        ivaRemoved={ivaRemoved}
        iva={iva}
        onToggleCredito={toggleCredito}
        onToggleInterno={toggleInterno}
        onToggleIva={toggleIva}
        total={totalValue}
        lineCount={lines.length}
        config={config}
        onPayClick={() => setPayDialogOpen(true)}
      />
    </div>
  )
}

// ── Toolbar ───────────────────────────────────────────────────────────────────

function CartToolbar({
  onSearchClick,
  onCustomerClick,
}: {
  onSearchClick: () => void
  onCustomerClick: () => void
}) {
  return (
    <div className="flex items-center border-b border-border px-1 py-1.5">
      {/* Slot 1: Menú de módulos (stub) */}
      <div className="flex flex-1 justify-center">
        <Button
          variant="ghost"
          size="icon"
          aria-label="Menú de módulos"
          title="Menú de módulos"
          onClick={() => {
            // TODO: abre menú flotante de módulos (Slice posterior) — ver guía visual del owner
          }}
        >
          <Menu className="size-5" />
        </Button>
      </div>

      {/* Slot 2: Buscar producto */}
      <div className="flex flex-1 justify-center">
        <Button
          variant="ghost"
          size="icon"
          aria-label="Buscar producto"
          title="Buscar producto"
          onClick={onSearchClick}
        >
          <Search className="size-5" />
        </Button>
      </div>

      {/* Slot 3: Seleccionar cliente */}
      <div className="flex flex-1 justify-center">
        <Button
          variant="ghost"
          size="icon"
          aria-label="Seleccionar cliente"
          title="Seleccionar cliente"
          onClick={onCustomerClick}
        >
          <User className="size-5" />
        </Button>
      </div>

      {/* Slot 4: Más opciones */}
      <div className="flex flex-1 justify-center">
        <Button
          variant="ghost"
          size="icon"
          aria-label="Más opciones"
          title="Más opciones"
        >
          <MoreHorizontal className="size-5" />
        </Button>
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
    <div className="flex items-center gap-2 border-b border-border px-3 py-1.5">
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
//
// Espejo del `.main` del legacy (#itemLineTpl en app/index.php:4003):
//   <badge qty> Nombre del producto       <amount sin currency>
//                 <iconos+texto del subtítulo>
//
// Si line.discount > 0 → borde izquierdo amarillo (el `b-l b-3x b-warning`
// del itemsToTable original). Iconos del subtítulo: usuario, tags, nota —
// se renderizan SOLO cuando existen (no mostrar slots vacíos).

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
        // Borde izquierdo amarillo cuando hay descuento — espejo del
        // `b-l b-3x b-warning` del template legacy.
        hasDiscount && "border-l-[3px] border-l-yellow-500",
      )}
    >
      {/* Círculo de cantidad — bg oscuro (NO blanco). Tinte amarillo cuando
          hay descuento, para hacer eco al borde izquierdo. */}
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

      {/* Nombre + subtexto (iconos solo si hay datos asociados) */}
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

      {/* Precio total — sin currency. El "Gs" sólo aparece en el botón de
          cobrar (convención del owner 2026-06-16). */}
      <span className="shrink-0 text-sm font-semibold tabular-nums text-foreground">
        {formatAmount(subtotal, config)}
      </span>
    </button>
  )
}

// ── Fila expandida (seleccionada) ─────────────────────────────────────────────
//
// Reemplaza completamente el `.main` del row colapsado por:
//   - Header centrado con el nombre del item (gris/muted).
//   - Grid 4 columnas full-width sin redondeos: [+] [xN] [−] [×]
//   - Grid 5 columnas full-width sin redondeos para herramientas:
//     [discount] [price] [user] [tags] [note]
//
// Click en `xN` (TODO C3): abre el NumPad para editar la cantidad.
// Click en `price` / `discount` (TODO C3): abre el NumPad correspondiente.
// Click en `user` / `tags` / `note` (TODO C2): abre BottomSheet con el
// formulario respectivo. Por ahora son no-op visualmente correctos.

function CartRowExpanded({
  line,
  onInc,
  onDec,
  onRemove,
}: {
  line: CartLine
  onInc: () => void
  onDec: () => void
  onRemove: () => void
}) {
  return (
    <div className="border-y border-border bg-accent/40">
      {/* Header — solo el nombre, centrado, gris (igual al mock del owner). */}
      <div className="flex items-center justify-center px-3 py-2.5">
        <span className="truncate text-sm font-medium text-muted-foreground">
          {line.name}
        </span>
      </div>

      {/* Fila de cantidad: 4 botones full-width, sin redondeo, separados por
          un divider sutil. El × ocupa el cuarto slot con bg destructivo. */}
      <div className="grid grid-cols-4 gap-px bg-border">
        <ToolbarSquare onClick={onInc} aria-label="Aumentar cantidad" title="Aumentar">
          <Plus className="size-5" />
        </ToolbarSquare>
        <ToolbarSquare
          aria-label="Modificar cantidad"
          title="Modificar cantidad"
          onClick={() => {
            // TODO (C3): abrir NumPad para editar cantidad.
          }}
        >
          <span className="text-base font-bold tabular-nums">
            x{line.qty}
          </span>
        </ToolbarSquare>
        <ToolbarSquare onClick={onDec} aria-label="Disminuir cantidad" title="Disminuir">
          <Minus className="size-5" />
        </ToolbarSquare>
        <ToolbarSquare
          onClick={onRemove}
          aria-label="Eliminar línea"
          title="Eliminar"
          variant="destructive"
        >
          <X className="size-5" />
        </ToolbarSquare>
      </div>

      {/* Fila de herramientas: 5 botones full-width, sin redondeo. */}
      <div className="grid grid-cols-5 gap-px bg-border">
        <ToolbarSquare
          aria-label="Descuento"
          title="Descuento"
          onClick={() => {
            // TODO (C3): abrir NumPad % para descuento de línea.
          }}
        >
          <Percent className="size-4" />
        </ToolbarSquare>
        <ToolbarSquare
          aria-label="Precio manual"
          title="Precio"
          onClick={() => {
            // TODO (C3): abrir NumPad para editar precio unitario.
          }}
        >
          <DollarSign className="size-4" />
        </ToolbarSquare>
        <ToolbarSquare
          aria-label="Vendedor"
          title="Vendedor"
          onClick={() => {
            // TODO (C2): abrir BottomSheet con selector de vendedor.
          }}
        >
          <User className="size-4" />
        </ToolbarSquare>
        <ToolbarSquare
          aria-label="Etiquetas"
          title="Etiquetas"
          onClick={() => {
            // TODO (C2): abrir BottomSheet con tags.
          }}
        >
          <Tag className="size-4" />
        </ToolbarSquare>
        <ToolbarSquare
          aria-label="Comentario"
          title="Comentario"
          onClick={() => {
            // TODO (C2): abrir BottomSheet con textarea de nota.
          }}
        >
          <MessageSquare className="size-4" />
        </ToolbarSquare>
      </div>
    </div>
  )
}

// Botón cuadrado full-width sin redondeos — base de las toolbars expandidas.
// El gap-px del grid contenedor pinta los dividers (bg-border en el grid +
// bg-card en cada celda).
function ToolbarSquare({
  children,
  onClick,
  variant = "default",
  ...rest
}: {
  children: React.ReactNode
  onClick?: () => void
  variant?: "default" | "destructive"
} & Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, "onClick" | "children">) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "flex h-12 items-center justify-center transition-colors",
        variant === "destructive"
          ? "bg-destructive/15 text-destructive hover:bg-destructive/25"
          : "bg-card text-foreground hover:bg-muted",
      )}
      {...rest}
    >
      {children}
    </button>
  )
}

// ── Carrito vacío ─────────────────────────────────────────────────────────────

function EmptyCart() {
  return (
    <div className="flex h-full flex-col items-center justify-center select-none">
      <span aria-hidden className="opacity-[0.12]">
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
  total: number
  lineCount: number
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onPayClick: () => void
}) {
  const totalFormatted = formatMoney(total, config)
  const ivaFormatted = formatMoney(iva, config)

  return (
    <div className="shrink-0 border-t border-border bg-background p-2 pt-2">
      {/* Toggles CRÉDITO / INTERNO / IVA — centrados y distribuidos */}
      <div className="mb-2 flex items-center justify-center gap-3">
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
        {/* X <valor IVA> — botón que elimina el IVA informativo */}
        <Button
          variant="ghost"
          size="icon-xs"
          onClick={onToggleIva}
          aria-label={ivaRemoved ? "Restaurar IVA" : "Eliminar IVA"}
          className={cn(
            "flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wide h-auto",
            ivaRemoved
              ? "text-muted-foreground/50"
              : "text-muted-foreground hover:text-foreground",
          )}
        >
          <X className="size-3" />
          <span>{ivaFormatted}</span>
        </Button>
      </div>

      {/* Botón cobrar full-width — colores DEFAULT del Button shadcn (primary).
          Convención del proyecto (panel-next): los botones no usan el verde de
          marca; usan los tokens neutros del theme. Acá solo overrideamos
          tamaño/padding para hacerlo prominente — los colores los pone shadcn. */}
      <Button
        disabled={lineCount === 0}
        onClick={lineCount > 0 ? onPayClick : undefined}
        className="h-auto w-full rounded-xl px-4 py-4 text-2xl font-bold transition-all active:scale-[0.98]"
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
          ? "border-[#01D7A1] bg-[#01D7A1]/20 text-[#01D7A1]"
          : "border-border bg-transparent text-muted-foreground hover:border-muted-foreground",
      )}
    >
      {label}
    </button>
  )
}
