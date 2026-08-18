"use client"

/**
 * Modal de búsqueda de productos — Slice A2.
 *
 * Layout §6.1 (Búsqueda de productos):
 *   - Barra de búsqueda grande arriba.
 *   - Lista de resultados: avatar circular (imagen o inicial) + badge de stock
 *     (rojo si negativo, verde si positivo/cero) + nombre + categoría + precio.
 *   - Búsqueda instantánea local vía searchItems() — cero round-trips.
 *   - Click en la fila → addItem al carrito (no cierra el modal).
 *   - Click en el avatar → abre la ficha del producto (`ProductInfoDialog`,
 *     2026-08-18). Mismo patrón que el ícono de info de los tiles de Hotkeys
 *     (`product-area.tsx`, `TileInfoButton`): el avatar sale del `<button>`
 *     de la fila —anidar botones es HTML inválido— y se convierte en su
 *     propio control con `stopPropagation`, para que abrir la ficha nunca
 *     agregue el ítem al carrito por error.
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
import { useCategoryBrandMaps, resolveCategoryName, resolveBrandName } from "@/lib/catalog/resolve-names"
import { addCatalogItem } from "@/lib/cart/add-catalog-item"
import { usePosUIStore } from "@/lib/ui/store"
import { searchItems } from "@/lib/catalog/search"
import { formatMoney } from "@/lib/format-money"
import type { PosItem } from "@/lib/types/pos-bootstrap"
import { cn } from "@/lib/utils"
import { EmptyState } from "@/components/empty-state"
import { SearchX, Info } from "lucide-react"
import { ProductInfoDialog } from "@/components/register/product-info-dialog"

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
  const { categoryMap, brandMap } = useCategoryBrandMaps()
  // Query en el store para que persista al cerrar y reabrir el modal.
  const query = usePosUIStore((s) => s.itemSearchQuery)
  const setQuery = usePosUIStore((s) => s.setItemSearchQuery)

  // Estado de vista de hijos de un grupo (null = vista de resultados normal).
  const [viewingGroup, setViewingGroup] = React.useState<PosItem | null>(null)
  // Ficha de producto abierta desde el avatar de una fila. `null` = cerrada.
  const [infoItem, setInfoItem] = React.useState<PosItem | null>(null)

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
    addCatalogItem(item)
    // No cerramos el modal ni reseteamos la búsqueda: el cajero puede seguir
    // agregando varios productos de la misma lista de resultados. Mantenemos el
    // foco en el input por si quiere refinar o escanear el siguiente.
    setTimeout(() => inputRef.current?.focus(), 0)
  }

  return (
    <>
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
                        categoryName={resolveCategoryName(item.categoryId, categoryMap)}
                        brandName={resolveBrandName(item.brandId, brandMap)}
                        onSelect={() => handleSelect(item)}
                        onInfo={() => setInfoItem(item)}
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
                    categoryName={resolveCategoryName(item.categoryId, categoryMap)}
                    brandName={resolveBrandName(item.brandId, brandMap)}
                    onSelect={() => handleSelect(item)}
                    onInfo={() => setInfoItem(item)}
                  />
                ))}
              </ul>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>

    {/* Ficha del producto, abierta desde el avatar de la fila. */}
    <ProductInfoDialog item={infoItem} onClose={() => setInfoItem(null)} />
    </>
  )
}

// ── Fila de resultado ─────────────────────────────────────────────────────────

function ProductResultRow({
  item,
  config,
  categoryName,
  brandName,
  onSelect,
  onInfo,
}: {
  item: PosItem
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  categoryName: string | null
  brandName: string | null
  onSelect: () => void
  /** Abre la ficha del producto. */
  onInfo: () => void
}) {
  // Inicial para el fallback del avatar (primera letra del nombre).
  const initial = item.name.trim()[0]?.toUpperCase() ?? "?"

  // Badge de stock: solo si trackInventory y stock no es null.
  const showStock = item.trackInventory && item.stock !== null
  const stockNegative = item.stock !== null && item.stock < 0

  return (
    // `relative`: el botón de la ficha se posiciona absolute sobre el hueco
    // del avatar (ver más abajo) — mismo recurso que `TileInfoButton` en
    // `product-area.tsx`, sin ocupar lugar propio en el flujo de la fila.
    <li role="option" aria-selected={false} className="relative">
      <button
        onClick={onSelect}
        className={cn(
          "flex w-full items-center gap-3 px-4 py-2.5 text-left",
          "transition-colors hover:bg-muted/50 active:bg-muted",
          "focus-visible:outline-none focus-visible:bg-muted/50",
        )}
      >
        {/* Hueco del avatar: el avatar real es un botón aparte (ver abajo) —
            anidar botones es HTML inválido. Mismo tamaño para que el resto
            de la fila no se mueva un pixel. */}
        <span className="size-8 shrink-0" aria-hidden />

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
          {(categoryName || brandName) && (
            <p className="truncate text-xs text-muted-foreground">
              {categoryName}
              {categoryName && brandName && " · "}
              {brandName}
            </p>
          )}
        </div>

        {/* Precio a la derecha */}
        <span className="shrink-0 text-sm font-semibold tabular-nums text-foreground">
          {formatMoney(item.price, config)}
        </span>
      </button>

      {/* Avatar circular con imagen o inicial — control propio: click abre
          la ficha del producto, con `stopPropagation` para que nunca agregue
          el ítem al carrito. Badge de info en la esquina como affordance
          (el avatar solo no sugiere "tocar acá para ver más"). */}
      <button
        type="button"
        onClick={(e) => { e.stopPropagation(); onInfo() }}
        aria-label={`Ver ficha de ${item.name}`}
        className="absolute left-4 top-1/2 -translate-y-1/2 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <Avatar size="default" className="shrink-0">
          {item.imageUrl && (
            <AvatarImage src={item.imageUrl} alt={item.name} />
          )}
          <AvatarFallback className="text-sm font-semibold">
            {initial}
          </AvatarFallback>
        </Avatar>
        <span className="absolute -bottom-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full border border-background bg-muted text-muted-foreground">
          <Info className="size-2.5" />
        </span>
      </button>
    </li>
  )
}
