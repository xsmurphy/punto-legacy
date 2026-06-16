"use client"

/**
 * Modal de búsqueda de productos — Slice A2.
 *
 * Layout §6.1 (Búsqueda de productos):
 *   - Barra de búsqueda grande arriba.
 *   - Lista de resultados: avatar circular (imagen o inicial) + badge de stock
 *     (rojo si negativo, verde si positivo/cero) + nombre + categoría + precio.
 *   - Búsqueda instantánea local vía searchItems() — cero round-trips.
 *   - Click en resultado → addItem al carrito + cierra modal.
 *
 * Ver context/16-app-next-rewrite.md §6.1 y §7 Slice A2.
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Avatar, AvatarImage, AvatarFallback } from "@/components/ui/avatar"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { searchItems } from "@/lib/catalog/search"
import { formatMoney } from "@/lib/format-money"
import type { PosItem } from "@/lib/types/pos-bootstrap"
import { cn } from "@/lib/utils"

// ── Props ─────────────────────────────────────────────────────────────────────

interface ProductSearchDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

// ── Component ─────────────────────────────────────────────────────────────────

export function ProductSearchDialog({
  open,
  onOpenChange,
}: ProductSearchDialogProps) {
  const [query, setQuery] = React.useState("")
  const inputRef = React.useRef<HTMLInputElement>(null)

  const items = useCatalogStore((s) => s.items)
  const config = useCatalogStore((s) => s.config)
  const addItem = useCartStore((s) => s.addItem)

  // Reset query when dialog closes/opens; autofocus on open.
  React.useEffect(() => {
    if (open) {
      setQuery("")
      // Small delay so the dialog animation completes before focusing.
      const id = setTimeout(() => inputRef.current?.focus(), 50)
      return () => clearTimeout(id)
    }
  }, [open])

  // Vacío = sin lista (solo el input). Solo busca cuando hay texto.
  const trimmed = query.trim()
  const results = React.useMemo(
    () => (trimmed ? searchItems(items, trimmed, 50) : []),
    [items, trimmed],
  )

  function handleSelect(item: PosItem) {
    addItem({ id: item.id, name: item.name, price: item.price })
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="top-[10vh] flex max-h-[80vh] translate-y-0 flex-col gap-3 border-none bg-transparent p-0 shadow-none sm:max-w-lg"
        showCloseButton={false}
      >
        <DialogHeader className="sr-only">
          <DialogTitle>Buscar producto</DialogTitle>
          <DialogDescription>
            Escribí el nombre, SKU o categoría del producto.
          </DialogDescription>
        </DialogHeader>

        {/* ── Pill del input (separado del listado) ── */}
        <div className="shrink-0 rounded-full bg-popover px-6 py-4 shadow-lg">
          <Input
            ref={inputRef}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Buscar productos o servicios"
            className="h-auto rounded-full border-0 bg-transparent px-0 text-center text-lg font-semibold shadow-none placeholder:font-semibold placeholder:text-muted-foreground focus-visible:ring-0"
            autoComplete="off"
            aria-label="Buscar producto"
          />
        </div>

        {/* ── Resultados: panel separado (gap del padre) ── */}
        {trimmed.length > 0 && (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl bg-popover shadow-lg">
            {results.length === 0 ? (
              <p className="py-10 text-center text-sm text-muted-foreground">
                Sin resultados para “{trimmed}”.
              </p>
            ) : (
              <ul role="listbox" aria-label="Resultados de búsqueda" className="overflow-y-auto py-1">
                {results.map((item) => (
                  <ProductResultRow
                    key={item.id}
                    item={item}
                    config={config}
                    onSelect={() => handleSelect(item)}
                  />
                ))}
              </ul>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

// ── Fila de resultado ─────────────────────────────────────────────────────────

function ProductResultRow({
  item,
  config,
  onSelect,
}: {
  item: PosItem
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onSelect: () => void
}) {
  // Inicial para el fallback del avatar (primera letra del nombre).
  const initial = item.name.trim()[0]?.toUpperCase() ?? "?"

  // Badge de stock: solo si trackInventory y stock no es null.
  const showStock = item.trackInventory && item.stock !== null
  const stockNegative = item.stock !== null && item.stock < 0

  return (
    <li role="option" aria-selected={false}>
      <button
        onClick={onSelect}
        className={cn(
          "flex w-full items-center gap-3 px-4 py-2.5 text-left",
          "transition-colors hover:bg-muted/50 active:bg-muted",
          "focus-visible:outline-none focus-visible:bg-muted/50",
        )}
      >
        {/* Avatar circular con imagen o inicial */}
        <Avatar size="default" className="shrink-0">
          {item.imageUrl && (
            <AvatarImage src={item.imageUrl} alt={item.name} />
          )}
          <AvatarFallback className="text-sm font-semibold">
            {initial}
          </AvatarFallback>
        </Avatar>

        {/* Badge de stock */}
        {showStock && (
          <Badge
            variant={stockNegative ? "destructive" : "default"}
            className={cn(
              "shrink-0 tabular-nums",
              !stockNegative && "bg-emerald-500 text-white",
            )}
          >
            {item.stock}
          </Badge>
        )}

        {/* Nombre + categoría */}
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium text-foreground">
            {item.name}
          </p>
          {item.categoryName && (
            <p className="truncate text-xs text-muted-foreground">
              › {item.categoryName}
            </p>
          )}
        </div>

        {/* Precio a la derecha */}
        <span className="shrink-0 text-sm font-semibold tabular-nums text-foreground">
          {formatMoney(item.price, config)}
        </span>
      </button>
    </li>
  )
}
