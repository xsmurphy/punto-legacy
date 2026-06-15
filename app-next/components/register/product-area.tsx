"use client"

/**
 * Área izquierda del register — grid de categorías/productos + barra inferior.
 *
 * Layout (fidelidad §6.1):
 *   - Grid de tiles: (a) categoría = color sólido + abreviatura estilo tabla
 *     periódica + label; (b) producto = imagen/color + nombre overlay.
 *   - Slots vacíos = tiles placeholder gris.
 *   - Barra inferior scrolleable: botón back circular + chips de categoría.
 *   - FAB "+" abajo-izquierda (stub).
 *   - Info de sesión: avatar + outlet + caja + versión.
 */

import * as React from "react"
import Image from "next/image"
import { Plus, ChevronLeft, User } from "lucide-react"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { fixtureCategories } from "@/lib/catalog/fixtures"
import type { PosItem } from "@/lib/types/pos-bootstrap"

// ── Paleta de colores de categoría ───────────────────────────────────────────
// Mapeamos categoryId a color. Los fixtures ya traen el color directamente.

type CategoryMeta = (typeof fixtureCategories)[number]

function getCategoryMeta(categoryId: string | null): CategoryMeta | null {
  if (!categoryId) return null
  return fixtureCategories.find((c) => c.id === categoryId) ?? null
}

// ── Tile de categoría ─────────────────────────────────────────────────────────

function CategoryTile({
  category,
  onClick,
  isActive,
}: {
  category: CategoryMeta
  onClick: () => void
  isActive: boolean
}) {
  return (
    <button
      onClick={onClick}
      className={cn(
        "group relative flex aspect-square flex-col items-start justify-between overflow-hidden rounded-xl p-2.5 transition-all active:scale-95",
        isActive && "ring-2 ring-white/60",
      )}
      style={{ backgroundColor: category.color }}
      aria-label={category.name}
    >
      {/* Abreviatura estilo tabla periódica */}
      <span className="text-3xl font-bold leading-none tracking-tight text-white/90">
        {category.abbrev}
      </span>
      {/* Label abajo */}
      <span className="line-clamp-2 text-left text-[11px] font-medium leading-tight text-white/80">
        {category.name}
      </span>
      {/* Overlay de hover */}
      <div className="absolute inset-0 bg-white/0 transition-colors group-hover:bg-white/10" />
    </button>
  )
}

// ── Tile de producto ──────────────────────────────────────────────────────────

function ProductTile({
  item,
  onClick,
}: {
  item: PosItem
  onClick: () => void
}) {
  const catMeta = getCategoryMeta(item.categoryId)
  const bgColor = catMeta?.color ?? "#6b7280"

  return (
    <button
      onClick={onClick}
      className="group relative flex aspect-square flex-col overflow-hidden rounded-xl transition-all active:scale-95"
      aria-label={item.name}
    >
      {/* Fondo: imagen o color de categoría */}
      {item.imageUrl ? (
        <Image
          src={item.imageUrl}
          alt={item.name}
          fill
          sizes="(max-width: 768px) 33vw, 20vw"
          className="object-cover"
        />
      ) : (
        <div className="absolute inset-0" style={{ backgroundColor: bgColor }} />
      )}

      {/* Gradiente overlay abajo */}
      <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent" />

      {/* Nombre overlay */}
      <div className="absolute inset-x-0 bottom-0 p-2">
        <span className="line-clamp-2 text-left text-[11px] font-medium leading-tight text-white">
          {item.name}
        </span>
      </div>

      {/* Hover overlay */}
      <div className="absolute inset-0 bg-white/0 transition-colors group-hover:bg-white/10" />
    </button>
  )
}

// ── Tile placeholder ─────────────────────────────────────────────────────────

function PlaceholderTile() {
  return (
    <div className="aspect-square rounded-xl bg-muted/40 dark:bg-muted/20" />
  )
}

// ── Grid principal ────────────────────────────────────────────────────────────

type GridView = "categories" | { categoryId: string }

export function ProductArea() {
  const items = useCatalogStore((s) => s.items)
  const addItem = useCartStore((s) => s.addItem)

  const [view, setView] = React.useState<GridView>("categories")

  const activeCategoryId =
    view === "categories" ? null : view.categoryId

  // Items de la categoría activa
  const visibleItems = React.useMemo(() => {
    if (view === "categories") return []
    return items.filter((i) => i.categoryId === view.categoryId)
  }, [view, items])

  // Número de slots del grid (múltiplo de la cantidad de columnas) para slots vacíos
  const COLS = 5
  const ROWS_MIN = 3
  const MIN_TILES = COLS * ROWS_MIN

  const handleCategoryClick = (categoryId: string) => {
    setView({ categoryId })
  }

  const handleBack = () => {
    setView("categories")
  }

  const handleProductClick = (item: PosItem) => {
    addItem({ id: item.id, name: item.name, price: item.price })
  }

  // ── Render del grid ────────────────────────────────────────────────────────

  const renderGrid = () => {
    if (view === "categories") {
      const cats = fixtureCategories
      const filled = cats.length
      const total = Math.max(MIN_TILES, Math.ceil(filled / COLS) * COLS)
      return (
        <div
          className="grid flex-1 gap-2 p-3"
          style={{ gridTemplateColumns: `repeat(${COLS}, 1fr)` }}
        >
          {cats.map((cat) => (
            <CategoryTile
              key={cat.id}
              category={cat}
              isActive={activeCategoryId === cat.id}
              onClick={() => handleCategoryClick(cat.id)}
            />
          ))}
          {Array.from({ length: total - filled }).map((_, i) => (
            <PlaceholderTile key={`ph-${i}`} />
          ))}
        </div>
      )
    }

    const filled = visibleItems.length
    const total = Math.max(MIN_TILES, Math.ceil(filled / COLS) * COLS)
    return (
      <div
        className="grid flex-1 gap-2 p-3"
        style={{ gridTemplateColumns: `repeat(${COLS}, 1fr)` }}
      >
        {visibleItems.map((item) => (
          <ProductTile
            key={item.id}
            item={item}
            onClick={() => handleProductClick(item)}
          />
        ))}
        {Array.from({ length: total - filled }).map((_, i) => (
          <PlaceholderTile key={`ph-${i}`} />
        ))}
      </div>
    )
  }

  return (
    <div className="relative flex h-full flex-col overflow-hidden">
      {/* ── Grid scrolleable ── */}
      <div className="flex-1 overflow-y-auto">{renderGrid()}</div>

      {/* ── CategoryBar ── */}
      <CategoryBar
        activeId={activeCategoryId}
        onBack={handleBack}
        onSelect={handleCategoryClick}
        showBack={view !== "categories"}
      />

      {/* ── FAB "+" ── */}
      <div className="absolute bottom-14 left-3 z-10">
        <Button
          size="icon"
          variant="secondary"
          className="size-10 rounded-full shadow-md"
          aria-label="Agregar producto"
        >
          <Plus className="size-5" />
        </Button>
      </div>

      {/* ── Info de sesión ── */}
      <SessionInfo />
    </div>
  )
}

// ── CategoryBar ───────────────────────────────────────────────────────────────

function CategoryBar({
  activeId,
  onBack,
  onSelect,
  showBack,
}: {
  activeId: string | null
  onBack: () => void
  onSelect: (id: string) => void
  showBack: boolean
}) {
  return (
    <div className="flex h-11 shrink-0 items-center gap-2 border-t border-border bg-background/80 px-2 backdrop-blur-sm">
      {/* Botón back circular */}
      <Button
        size="icon"
        variant={showBack ? "outline" : "ghost"}
        className="size-8 shrink-0 rounded-full"
        onClick={onBack}
        disabled={!showBack}
        aria-label="Volver a categorías"
      >
        <ChevronLeft className="size-4" />
      </Button>

      {/* Chips de categorías — scrolleable */}
      <div className="flex flex-1 gap-1.5 overflow-x-auto py-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {fixtureCategories.map((cat) => (
          <button
            key={cat.id}
            onClick={() => onSelect(cat.id)}
            className={cn(
              "flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors",
              activeId === cat.id
                ? "text-white"
                : "bg-muted text-muted-foreground hover:bg-muted/80",
            )}
            style={activeId === cat.id ? { backgroundColor: cat.color } : undefined}
          >
            {cat.name}
          </button>
        ))}
      </div>
    </div>
  )
}

// ── Info de sesión ─────────────────────────────────────────────────────────────

function SessionInfo() {
  return (
    <div className="absolute bottom-12 right-3 flex items-center gap-2 rounded-lg bg-background/70 px-2.5 py-1.5 backdrop-blur-sm">
      <div className="flex size-6 items-center justify-center rounded-full bg-muted">
        <User className="size-3.5 text-muted-foreground" />
      </div>
      <div className="flex flex-col leading-none">
        <span className="text-[10px] font-medium text-foreground">
          Central · Caja Principal
        </span>
        <span className="text-[9px] text-muted-foreground">v0.1.0-alpha</span>
      </div>
    </div>
  )
}
