"use client"

/**
 * Hotkeys — grilla de accesos rápidos de la caja (pantalla por defecto).
 *
 * Renderiza la config de `useHotkeysStore` (espejo de register.data.hotkeys):
 * cada slot apunta a un artículo (click → al carrito) o una categoría
 * (click → entra a ver sus productos). Tiles con imagen de fondo o, sin
 * imagen, color sólido + abreviatura (modo "tabla periódica").
 *
 * Modo edición (chunk 2): +/✕/color/drag&drop. Por ahora solo vista.
 *
 * Ver concepto: Square POS-style quick keys. Legacy: ncmHotKeys (app.js:23583).
 */

import * as React from "react"
import Image from "next/image"
import { ChevronLeft } from "lucide-react"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { useHotkeysStore, hotkeyColorBg, type Hotkey } from "@/lib/hotkeys/store"
import type { PosItem } from "@/lib/types/pos-bootstrap"

// Grilla 6 columnas × 50 filas (300 slots), scroll vertical.
const COLS = 6
const ROWS = 50
const SLOTS = COLS * ROWS

const DEFAULT_TILE = "#3f4651"

function abbrev(name: string): string {
  const t = name.trim()
  if (!t) return "?"
  const parts = t.split(/\s+/)
  if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase()
  return t.slice(0, 2)
}

export function ProductArea() {
  const items = useCatalogStore((s) => s.items)
  const hotkeys = useHotkeysStore((s) => s.hotkeys)
  const addItem = useCartStore((s) => s.addItem)

  // Vista: grilla de hotkeys, o productos de una categoría (drill-in).
  const [categoryId, setCategoryId] = React.useState<string | null>(null)

  // Índices auxiliares.
  const itemById = React.useMemo(() => {
    const m = new Map<string, PosItem>()
    for (const i of items) m.set(i.id, i)
    return m
  }, [items])

  // Categorías derivadas de los items (id → nombre).
  const categoryName = React.useMemo(() => {
    const m = new Map<string, string>()
    for (const i of items) {
      if (i.categoryId && !m.has(i.categoryId)) {
        m.set(i.categoryId, i.categoryName ?? "Categoría")
      }
    }
    return m
  }, [items])

  const hotkeyAt = React.useMemo(() => {
    const m = new Map<number, Hotkey>()
    for (const h of hotkeys) m.set(h.position, h)
    return m
  }, [hotkeys])

  const categoryItems = React.useMemo(
    () => (categoryId ? items.filter((i) => i.categoryId === categoryId) : []),
    [categoryId, items],
  )

  const handleHotkeyClick = (h: Hotkey) => {
    if (h.isCategory) {
      setCategoryId(h.itemId)
      return
    }
    const item = itemById.get(h.itemId)
    if (item) addItem({ id: item.id, name: item.name, price: item.price })
  }

  const handleProductClick = (item: PosItem) => {
    addItem({ id: item.id, name: item.name, price: item.price })
  }

  return (
    <div className="relative flex h-full flex-col overflow-hidden bg-sidebar">
      <div className="flex-1 overflow-y-auto p-3">
        {categoryId === null ? (
          // ── Grilla de hotkeys ──
          <div
            className="grid gap-2"
            style={{ gridTemplateColumns: `repeat(${COLS}, minmax(0, 1fr))` }}
          >
            {Array.from({ length: SLOTS }).map((_, pos) => {
              const h = hotkeyAt.get(pos)
              if (!h) return <EmptySlot key={pos} />
              return (
                <HotkeyTile
                  key={pos}
                  hotkey={h}
                  item={h.isCategory ? null : itemById.get(h.itemId) ?? null}
                  label={
                    h.isCategory
                      ? categoryName.get(h.itemId) ?? "Categoría"
                      : itemById.get(h.itemId)?.name ?? "—"
                  }
                  onClick={() => handleHotkeyClick(h)}
                />
              )
            })}
          </div>
        ) : (
          // ── Productos de la categoría (drill-in) ──
          <div
            className="grid gap-2"
            style={{ gridTemplateColumns: `repeat(${COLS}, minmax(0, 1fr))` }}
          >
            {categoryItems.map((item) => (
              <ProductTile key={item.id} item={item} onClick={() => handleProductClick(item)} />
            ))}
            {categoryItems.length === 0 && (
              <p className="col-span-full py-10 text-center text-sm text-muted-foreground">
                Sin productos en esta categoría.
              </p>
            )}
          </div>
        )}
      </div>

      {/* Botón "volver a hotkeys" — solo dentro de una categoría. */}
      {categoryId !== null && (
        <div className="absolute bottom-3 left-3 z-10">
          <Button
            variant="secondary"
            className="gap-2 rounded-full shadow-lg"
            onClick={() => setCategoryId(null)}
          >
            <ChevronLeft className="size-4" />
            Volver
          </Button>
        </div>
      )}
    </div>
  )
}

// ── Tile de hotkey (categoría o item) ─────────────────────────────────────────

function HotkeyTile({
  hotkey,
  item,
  label,
  onClick,
}: {
  hotkey: Hotkey
  item: PosItem | null
  label: string
  onClick: () => void
}) {
  const hasImage = !hotkey.isCategory && item?.imageUrl
  const bg = hotkeyColorBg(hotkey.color) ?? DEFAULT_TILE

  return (
    <button
      onClick={onClick}
      aria-label={label}
      className="group relative flex aspect-square flex-col items-start justify-between overflow-hidden rounded-xl p-2.5 text-left transition-all active:scale-95"
      style={hasImage ? undefined : { backgroundColor: bg }}
    >
      {hasImage && item?.imageUrl ? (
        <>
          <Image src={item.imageUrl} alt={label} fill sizes="20vw" className="object-cover" />
          <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent" />
        </>
      ) : (
        // Modo tabla periódica: abreviatura grande + nombre abajo.
        <span className="text-2xl font-bold leading-none tracking-tight text-white/90">
          {abbrev(label)}
        </span>
      )}
      <span
        className={cn(
          "line-clamp-2 text-[11px] font-medium leading-tight",
          hasImage ? "absolute inset-x-0 bottom-0 z-10 p-2 text-white" : "text-white/85",
        )}
      >
        {label}
      </span>
      <div className="absolute inset-0 bg-white/0 transition-colors group-hover:bg-white/10" />
    </button>
  )
}

// ── Tile de producto (vista drill-in de categoría) ────────────────────────────

function ProductTile({ item, onClick }: { item: PosItem; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      aria-label={item.name}
      className="group relative flex aspect-square flex-col overflow-hidden rounded-xl transition-all active:scale-95"
      style={item.imageUrl ? undefined : { backgroundColor: DEFAULT_TILE }}
    >
      {item.imageUrl && (
        <Image src={item.imageUrl} alt={item.name} fill sizes="20vw" className="object-cover" />
      )}
      <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent" />
      <div className="absolute inset-x-0 bottom-0 p-2">
        <span className="line-clamp-2 text-left text-[11px] font-medium leading-tight text-white">
          {item.name}
        </span>
      </div>
      <div className="absolute inset-0 bg-white/0 transition-colors group-hover:bg-white/10" />
    </button>
  )
}

// ── Slot vacío ────────────────────────────────────────────────────────────────

function EmptySlot() {
  return <div className="aspect-square rounded-xl bg-muted/30 dark:bg-muted/15" />
}
