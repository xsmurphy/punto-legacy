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
 *   - Bottom: toggles CRÉDITO/INTERNO + botón full-width verde con total.
 *   - Watermark "Punto" en carrito vacío.
 */

import * as React from "react"
import {
  ArrowUpDown,
  Search,
  User,
  MoreHorizontal,
  X,
  Plus,
  Minus,
  Trash2,
  ChevronDown,
  DollarSign,
  Tag,
  MessageSquare,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"
import { useCartStore, selectCartTotal } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney } from "@/lib/format-money"

// ── CartPanel raíz ────────────────────────────────────────────────────────────

export function CartPanel() {
  const lines = useCartStore((s) => s.lines)
  const selectedLineId = useCartStore((s) => s.selectedLineId)
  const customer = useCartStore((s) => s.customer)
  const credito = useCartStore((s) => s.credito)
  const interno = useCartStore((s) => s.interno)
  const total = useCartStore(selectCartTotal)
  const clear = useCartStore((s) => s.clear)
  const toggleCredito = useCartStore((s) => s.toggleCredito)
  const toggleInterno = useCartStore((s) => s.toggleInterno)
  const selectLine = useCartStore((s) => s.selectLine)
  const removeLine = useCartStore((s) => s.removeLine)
  const incQty = useCartStore((s) => s.incQty)
  const decQty = useCartStore((s) => s.decQty)

  const config = useCatalogStore((s) => s.config)

  const selectedLine = lines.find((l) => l.lineId === selectedLineId) ?? null
  const totalValue = total

  return (
    <div className="flex h-full flex-col border-l border-border bg-background">
      {/* ── Toolbar ── */}
      <CartToolbar />

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
                <div key={line.lineId}>
                  <CartLineRow
                    line={line}
                    isActive={isActive}
                    config={config}
                    onSelect={() => selectLine(isActive ? null : line.lineId)}
                  />
                  {isActive && (
                    <ActiveLineControls
                      lineId={line.lineId}
                      qty={line.qty}
                      onInc={() => incQty(line.lineId)}
                      onDec={() => decQty(line.lineId)}
                      onRemove={() => removeLine(line.lineId)}
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
        onToggleCredito={toggleCredito}
        onToggleInterno={toggleInterno}
        total={totalValue}
        lineCount={lines.length}
        config={config}
      />
    </div>
  )
}

// ── Toolbar ───────────────────────────────────────────────────────────────────

function CartToolbar() {
  return (
    <div className="flex items-center justify-between border-b border-border px-2 py-1.5">
      <div className="flex items-center gap-0.5">
        <Button variant="ghost" size="icon-sm" aria-label="Ordenar" title="Ordenar líneas">
          <ArrowUpDown className="size-4" />
        </Button>
        <Button variant="ghost" size="icon-sm" aria-label="Buscar" title="Buscar en el carrito">
          <Search className="size-4" />
        </Button>
        <Button variant="ghost" size="icon-sm" aria-label="Cliente" title="Seleccionar cliente">
          <User className="size-4" />
        </Button>
      </div>
      <Button variant="ghost" size="icon-sm" aria-label="Más opciones" title="Más opciones">
        <MoreHorizontal className="size-4" />
      </Button>
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

// ── Fila de línea del carrito ─────────────────────────────────────────────────

function CartLineRow({
  line,
  isActive,
  config,
  onSelect,
}: {
  line: { lineId: string; name: string; qty: number; unitPrice: number; note?: string }
  isActive: boolean
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onSelect: () => void
}) {
  const subtotal = line.qty * line.unitPrice

  return (
    <button
      onClick={onSelect}
      className={cn(
        "flex w-full items-center gap-2 px-3 py-2 text-left transition-colors",
        isActive
          ? "bg-accent"
          : "hover:bg-muted/50",
      )}
    >
      {/* Badge de cantidad */}
      <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
        {line.qty}
      </span>

      {/* Nombre + subtexto */}
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-foreground">{line.name}</p>
        {line.note && (
          <p className="truncate text-[10px] text-muted-foreground">{line.note}</p>
        )}
        {!line.note && (
          <p className="truncate text-[10px] text-muted-foreground">
            {line.qty > 1 && `x${line.qty} @ `}
            {formatMoney(line.unitPrice, config)}
          </p>
        )}
      </div>

      {/* Precio total */}
      <span className="shrink-0 text-sm font-semibold tabular-nums text-foreground">
        {formatMoney(subtotal, config)}
      </span>
    </button>
  )
}

// ── Controles de línea activa ─────────────────────────────────────────────────

function ActiveLineControls({
  lineId,
  qty,
  onInc,
  onDec,
  onRemove,
}: {
  lineId: string
  qty: number
  onInc: () => void
  onDec: () => void
  onRemove: () => void
}) {
  return (
    <div className="border-b border-border bg-accent/50 px-3 pb-2 pt-1">
      {/* Fila de cantidad */}
      <div className="mb-2 flex items-center justify-center gap-3">
        <Button
          size="icon-sm"
          variant="outline"
          onClick={onInc}
          aria-label="Aumentar cantidad"
          className="size-9 rounded-full"
        >
          <Plus className="size-4" />
        </Button>

        <span className="min-w-[3ch] text-center text-lg font-bold tabular-nums text-foreground">
          x{qty}
        </span>

        <Button
          size="icon-sm"
          variant="outline"
          onClick={onDec}
          aria-label="Disminuir cantidad"
          className="size-9 rounded-full"
        >
          <Minus className="size-4" />
        </Button>

        <Button
          size="icon-sm"
          variant="destructive"
          onClick={onRemove}
          aria-label="Eliminar línea"
          className="size-9 rounded-full"
        >
          <X className="size-4" />
        </Button>
      </div>

      {/* Acciones de línea (stubs) */}
      <div className="flex items-center justify-center gap-2">
        <Button variant="ghost" size="icon-xs" className="rounded-full" aria-label="Opciones de línea" title="Opciones">
          <ChevronDown className="size-3" />
        </Button>
        <Button variant="ghost" size="icon-xs" className="rounded-full" aria-label="Precio manual" title="Precio">
          <DollarSign className="size-3" />
        </Button>
        <Button variant="ghost" size="icon-xs" className="rounded-full" aria-label="Vendedor" title="Vendedor">
          <User className="size-3" />
        </Button>
        <Button variant="ghost" size="icon-xs" className="rounded-full" aria-label="Tag" title="Tag">
          <Tag className="size-3" />
        </Button>
        <Button variant="ghost" size="icon-xs" className="rounded-full" aria-label="Comentario" title="Comentario">
          <MessageSquare className="size-3" />
        </Button>
      </div>
    </div>
  )
}

// ── Carrito vacío ─────────────────────────────────────────────────────────────

function EmptyCart() {
  return (
    <div className="flex h-full flex-col items-center justify-center select-none">
      <span
        className="text-7xl font-black tracking-tight text-muted-foreground/10"
        aria-hidden
      >
        Punto
      </span>
    </div>
  )
}

// ── Bottom: toggles + cobrar ──────────────────────────────────────────────────

function CartBottom({
  credito,
  interno,
  onToggleCredito,
  onToggleInterno,
  total,
  lineCount,
  config,
}: {
  credito: boolean
  interno: boolean
  onToggleCredito: () => void
  onToggleInterno: () => void
  total: number
  lineCount: number
  config: ReturnType<typeof useCatalogStore.getState>["config"]
}) {
  const totalFormatted = formatMoney(total, config)

  return (
    <div className="shrink-0 border-t border-border bg-background p-2 pt-2">
      {/* Toggles CRÉDITO / INTERNO + contador de líneas */}
      <div className="mb-2 flex items-center gap-1.5">
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
        <div className="flex-1" />
        {lineCount > 0 && (
          <span className="text-xs text-muted-foreground">
            {lineCount} {lineCount === 1 ? "ítem" : "ítems"}
          </span>
        )}
      </div>

      {/* Botón cobrar full-width verde (marca Punto) */}
      <Button
        disabled={lineCount === 0}
        className={cn(
          "h-auto w-full rounded-xl px-4 py-3 text-base font-bold transition-all active:scale-[0.98]",
          lineCount === 0
            ? "bg-muted text-muted-foreground hover:bg-muted"
            : "bg-[#01D7A1] text-[#060A0E] hover:bg-[#01D7A1]/90",
        )}
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
