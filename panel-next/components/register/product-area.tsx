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
import { ChevronLeft } from "lucide-react"
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
      {/* ── Grid scrolleable ── (ocupa todo, sin barra fija abajo) */}
      <div className="flex-1 overflow-y-auto pb-20">{renderGrid()}</div>

      {/* ── CategoryBar flotante ──
          El FAB de "Menú de módulos" se removió: su contenido (Hotkeys, Mesas,
          Calendario, Órdenes…) vive ahora en el sidebar contextual del POS
          (ver panelNav/posNav en panel-auth-guard). La barra de categorías
          ocupa toda la fila. */}
      <div className="absolute bottom-3 left-3 right-3 z-10 flex items-center">
        <FloatingCategoryBar
          activeId={activeCategoryId}
          onBack={handleBack}
          onSelect={handleCategoryClick}
          showBack={view !== "categories"}
        />
      </div>
    </div>
  )
}

// ── FloatingCategoryBar ───────────────────────────────────────────────────────
// Barra de categorías FLOTANTE: pill rounded-full con backdrop-blur, NO border-t
// fija al fondo. Vive en la misma línea horizontal que el FAB (ver ProductArea).

function FloatingCategoryBar({
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
    <div className="flex h-12 flex-1 items-center gap-2 rounded-full border border-border bg-background/80 px-1.5 shadow-lg backdrop-blur-md">
      {/* Botón back circular */}
      <Button
        size="icon"
        variant={showBack ? "secondary" : "ghost"}
        className="size-9 shrink-0 rounded-full"
        onClick={onBack}
        disabled={!showBack}
        aria-label="Volver a categorías"
      >
        <ChevronLeft className="size-4" />
      </Button>

      {/* Chips de categorías — scrolleable horizontal, scrollbar oculta */}
      <div className="flex flex-1 gap-1.5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {fixtureCategories.map((cat) => (
          <Button
            key={cat.id}
            type="button"
            size="sm"
            variant="ghost"
            onClick={() => onSelect(cat.id)}
            className={cn(
              "h-8 shrink-0 rounded-full px-3 text-xs font-medium",
              activeId === cat.id
                ? "text-white hover:text-white"
                : "text-muted-foreground hover:text-foreground",
            )}
            style={activeId === cat.id ? { backgroundColor: cat.color } : undefined}
          >
            {cat.name}
          </Button>
        ))}
      </div>
    </div>
  )
}

