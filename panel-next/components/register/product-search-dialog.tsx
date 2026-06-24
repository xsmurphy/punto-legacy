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
import { usePosUIStore } from "@/lib/ui/store"
import { searchItems } from "@/lib/catalog/search"
import { formatMoney } from "@/lib/format-money"
import type { PosItem } from "@/lib/types/pos-bootstrap"
import { cn } from "@/lib/utils"
import { EmptyState } from "@/components/empty-state"
import { SearchX } from "lucide-react"

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
  const inputRef = React.useRef<HTMLInputElement>(null)

  const items = useCatalogStore((s) => s.items)
  const config = useCatalogStore((s) => s.config)
  const addItem = useCartStore((s) => s.addItem)
  // Query en el store para que persista al cerrar y reabrir el modal.
  const query = usePosUIStore((s) => s.itemSearchQuery)
  const setQuery = usePosUIStore((s) => s.setItemSearchQuery)
  const clearQuery = usePosUIStore((s) => s.clearItemSearchQuery)

  // Estado de vista de hijos de un grupo (null = vista de resultados normal).
  const [viewingGroup, setViewingGroup] = React.useState<PosItem | null>(null)

  // Solo autofocus al abrir — no limpiamos el query para preservar la búsqueda.
  React.useEffect(() => {
    if (open) {
      const id = setTimeout(() => inputRef.current?.focus(), 50)
      return () => clearTimeout(id)
    }
  }, [open])

  // Vacío = sin lista (solo el input). Solo busca cuando hay texto.
  // Excluye hijos de grupos del top-level de resultados.
  const trimmed = query.trim()
  const results = React.useMemo(
    () => (trimmed ? searchItems(items.filter((i) => i.parentId === null), trimmed, 50) : []),
    [items, trimmed],
  )

  const groupChildItems = React.useMemo(
    () => (viewingGroup ? items.filter((i) => i.parentId === viewingGroup.id) : []),
    [viewingGroup, items],
  )

  function handleSelect(item: PosItem) {
    if (item.isGroup) {
      setViewingGroup(item)
      return
    }
    addItem({ id: item.id, name: item.name, price: item.price })
    // No cerramos el modal — el cajero puede seguir agregando productos.
    // Limpiamos la búsqueda y devolvemos el foco al input para el siguiente artículo.
    clearQuery()
    setTimeout(() => inputRef.current?.focus(), 0)
  }

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) setViewingGroup(null); onOpenChange(v) }}>
      <DialogContent
        className="top-[10vh] flex max-h-[80vh] translate-y-0 flex-col gap-3 border-none bg-transparent p-0 shadow-none ring-0 sm:max-w-lg"
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
        {(trimmed.length > 0 || viewingGroup) && (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl bg-popover shadow-lg">
            {viewingGroup ? (
              <>
                <div className="flex items-center gap-2 px-4 py-3 border-b border-border shrink-0">
                  <button
                    type="button"
                    onClick={() => setViewingGroup(null)}
                    className="text-sm text-muted-foreground hover:text-foreground transition-colors"
                  >
                    ← Volver
                  </button>
                  <span className="text-sm font-medium">{viewingGroup.name}</span>
                </div>
                {groupChildItems.length === 0 ? (
                  <EmptyState
                    icon={SearchX}
                    title="Sin artículos"
                    description="Este grupo no tiene artículos asociados."
                    showMarquee={false}
                  />
                ) : (
                  <ul role="listbox" aria-label={`Artículos de ${viewingGroup.name}`} className="overflow-y-auto py-1">
                    {groupChildItems.map((item) => (
                      <ProductResultRow
                        key={item.id}
                        item={item}
                        config={config}
                        onSelect={() => handleSelect(item)}
                      />
                    ))}
                  </ul>
                )}
              </>
            ) : results.length === 0 ? (
              <EmptyState
                icon={SearchX}
                title="Sin resultados"
                description={`Ningún producto coincide con "${trimmed}".`}
                showMarquee={false}
              />
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
          <p className="truncate text-sm font-medium text-foreground flex items-center gap-1.5">
            <span className="truncate">{item.name}</span>
            {item.isGroup && (
              <span className="shrink-0 rounded bg-muted px-1 py-0.5 text-[10px] font-medium uppercase text-muted-foreground leading-none">
                Grupo
              </span>
            )}
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
