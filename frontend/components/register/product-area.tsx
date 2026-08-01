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
import { useRouter, useSearchParams } from "next/navigation"
import { ChevronLeft, Plus, X, Check, Loader2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { useCatalogStore } from "@/lib/catalog/store"
import { addCatalogItem } from "@/lib/cart/add-catalog-item"
import { useHotkeysStore, hotkeyColorBg, type Hotkey } from "@/lib/hotkeys/store"
import { ColorPicker } from "@/components/ui/color-picker"
import { useHotkeys } from "@/hooks/use-hotkeys"
import { HotkeyAssignDialog } from "@/components/register/hotkey-assign-dialog"
import { GroupItemsDialog } from "@/components/register/group-items-dialog"
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
  const editing = useHotkeysStore((s) => s.editing)
  const setEditing = useHotkeysStore((s) => s.setEditing)
  const removeHotkey = useHotkeysStore((s) => s.removeHotkey)
  const setColor = useHotkeysStore((s) => s.setColor)
  const moveHotkey = useHotkeysStore((s) => s.moveHotkey)
  const clearAll = useHotkeysStore((s) => s.clearAll)

  const { saveHotkeys, isSaving } = useHotkeys()

  // Modo edición pedido por URL (?hotkeys=edit): el menú navega con este
  // param en vez de solo setear el store — el intent sobrevive el hard
  // reload por version-skew post-deploy (el chunk/RSC del build viejo fuerza
  // navegación dura y el store zustand muere; el param no — reporte del
  // owner 2026-08-01: "elegí HotKeys y abrió en modo venta"). Se consume una
  // vez y se limpia de la URL para que un refresh manual no re-entre a
  // edición.
  const searchParams = useSearchParams()
  const router = useRouter()
  React.useEffect(() => {
    if (searchParams.get("hotkeys") === "edit") {
      setEditing(true)
      router.replace("/pos", { scroll: false })
    }
  }, [searchParams, setEditing, router])

  // Vista: grilla de hotkeys, o productos de una categoría (drill-in).
  const [categoryId, setCategoryId] = React.useState<string | null>(null)

  // Slot cuyo diálogo de asignación está abierto (null = cerrado).
  const [assigningSlot, setAssigningSlot] = React.useState<number | null>(null)

  // Grupo de catálogo seleccionado (abre GroupItemsDialog con sus hijos).
  const [groupPicker, setGroupPicker] = React.useState<PosItem | null>(null)

  // Drag & drop nativo: posición de origen.
  const dragFrom = React.useRef<number | null>(null)

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

  // Lista para la barra flotante de categorías (preserva el orden de aparición).
  const categoryList = React.useMemo(
    () => Array.from(categoryName.entries()).map(([id, name]) => ({ id, name })),
    [categoryName],
  )

  const hotkeyAt = React.useMemo(() => {
    const m = new Map<number, Hotkey>()
    for (const h of hotkeys) m.set(h.position, h)
    return m
  }, [hotkeys])

  const categoryItems = React.useMemo(
    () =>
      categoryId
        ? items.filter((i) => i.categoryId === categoryId && i.parentId === null)
        : [],
    [categoryId, items],
  )

  const groupChildren = React.useMemo(
    () => (groupPicker ? items.filter((i) => i.parentId === groupPicker.id) : []),
    [groupPicker, items],
  )

  // Al salir del modo edición, salir también del drill-in si hubiera.
  React.useEffect(() => {
    if (!editing) setCategoryId(null)
  }, [editing])

  const handleHotkeyClick = (h: Hotkey) => {
    // En modo edición los clicks no hacen nada (los controles de edición
    // están en el overlay; el click en el tile base se ignora).
    if (editing) return
    if (h.isCategory) {
      setCategoryId(h.itemId)
      return
    }
    const item = itemById.get(h.itemId)
    if (item) {
      if (item.isGroup) {
        setGroupPicker(item)
        return
      }
      addCatalogItem(item)
    }
  }

  const handleProductClick = (item: PosItem) => {
    if (item.isGroup) {
      setGroupPicker(item)
      return
    }
    addCatalogItem(item)
  }

  /** Guardar y salir del modo edición. */
  async function handleDone() {
    setEditing(false)
    try {
      await saveHotkeys()
      toast.success("Hotkeys guardados")
    } catch {
      toast.error("No se pudieron guardar los cambios")
    }
  }

  return (
    <div className="relative flex h-full flex-col overflow-hidden">
      {/* ── Barra de edición ───────────────────────────────────────────── */}
      {editing && (
        <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border bg-background/80 px-3 py-2 backdrop-blur-sm">
          <p className="text-xs font-medium text-muted-foreground">
            Modo edición — arrastrá para mover
          </p>
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="text-destructive hover:text-destructive"
              onClick={clearAll}
            >
              Vaciar todo
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="gap-1.5"
              onClick={handleDone}
              disabled={isSaving}
            >
              {isSaving ? (
                <Loader2 className="size-3.5 animate-spin" />
              ) : (
                <Check className="size-3.5" />
              )}
              Listo
            </Button>
          </div>
        </div>
      )}

      <div className="flex-1 overflow-y-auto p-3 pb-20">
        {categoryId === null ? (
          // ── Grilla de hotkeys ──
          <div
            className="grid gap-2"
            style={{ gridTemplateColumns: `repeat(${COLS}, minmax(0, 1fr))` }}
          >
            {Array.from({ length: SLOTS }).map((_, pos) => {
              const h = hotkeyAt.get(pos)
              if (!h) {
                return (
                  <EditableEmptySlot
                    key={pos}
                    editing={editing}
                    onAdd={() => setAssigningSlot(pos)}
                    onDragOver={(e) => { if (editing) e.preventDefault() }}
                    onDrop={() => {
                      if (!editing || dragFrom.current === null) return
                      moveHotkey(dragFrom.current, pos)
                      dragFrom.current = null
                    }}
                  />
                )
              }
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
                  editing={editing}
                  onClick={() => handleHotkeyClick(h)}
                  onRemove={() => removeHotkey(pos)}
                  onColorChange={(color) => setColor(pos, color)}
                  onDragStart={() => { dragFrom.current = pos }}
                  onDragOver={(e) => { if (editing) e.preventDefault() }}
                  onDrop={() => {
                    if (!editing || dragFrom.current === null) return
                    moveHotkey(dragFrom.current, pos)
                    dragFrom.current = null
                  }}
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

      {/* Barra flotante de categorías — pill oscura con scroll horizontal.
          Ancho fijo (full del producto-area) para que se vea consistente
          aunque haya 1 sola categoría o ninguna. Oculta en modo edición. */}
      {!editing && categoryList.length > 0 && (
        <div className="pointer-events-none absolute inset-x-0 bottom-3 z-10 flex px-3">
          <div className="pointer-events-auto flex w-full items-center gap-2 rounded-full bg-[#22252A] py-1.5 pl-1.5 pr-3 shadow-lg">
            {/* Botón circular back: vuelve a hotkeys cuando hay drill-in. */}
            <button
              type="button"
              onClick={() => setCategoryId(null)}
              disabled={categoryId === null}
              aria-label="Volver a hotkeys"
              className={cn(
                "flex size-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-white transition-colors",
                categoryId !== null ? "hover:bg-white/20" : "opacity-40",
              )}
            >
              <ChevronLeft className="size-5" />
            </button>
            {/* Lista scrolleable de categorías — crece para llenar el ancho restante. */}
            <div
              className="flex flex-1 items-center gap-1 overflow-x-auto whitespace-nowrap"
              style={{ scrollbarWidth: "none" }}
            >
              {categoryList.map((cat) => {
                const active = cat.id === categoryId
                return (
                  <button
                    key={cat.id}
                    type="button"
                    onClick={() => setCategoryId(cat.id)}
                    className={cn(
                      "shrink-0 rounded-full px-3 py-1.5 text-sm font-bold transition-colors",
                      active
                        ? "bg-white text-neutral-900"
                        : "text-white/80 hover:text-white",
                    )}
                  >
                    {cat.name}
                  </button>
                )
              })}
            </div>
          </div>
        </div>
      )}

      {/* Diálogo de asignación de slot vacío. */}
      <HotkeyAssignDialog
        position={assigningSlot}
        onClose={() => setAssigningSlot(null)}
      />

      {/* Diálogo de selección de hijo para grupos de catálogo. */}
      <GroupItemsDialog
        group={groupPicker}
        items={groupChildren}
        onClose={() => setGroupPicker(null)}
        onPick={(it) => addCatalogItem(it)}
      />
    </div>
  )
}

// ── Tile de hotkey (categoría o item) ─────────────────────────────────────────

function HotkeyTile({
  hotkey,
  item,
  label,
  editing,
  onClick,
  onRemove,
  onColorChange,
  onDragStart,
  onDragOver,
  onDrop,
}: {
  hotkey: Hotkey
  item: PosItem | null
  label: string
  editing: boolean
  onClick: () => void
  onRemove: () => void
  onColorChange: (color: string) => void
  onDragStart: () => void
  onDragOver: (e: React.DragEvent) => void
  onDrop: () => void
}) {
  const hasImage = !hotkey.isCategory && item?.imageUrl
  const bg = hotkeyColorBg(hotkey.color) ?? DEFAULT_TILE

  return (
    <div
      className="group relative aspect-square"
      draggable={editing}
      onDragStart={onDragStart}
      onDragOver={onDragOver}
      onDrop={onDrop}
    >
      <button
        onClick={onClick}
        aria-label={label}
        className={cn(
          "relative flex h-full w-full flex-col items-start justify-between overflow-hidden rounded-xl p-2.5 text-left transition-all",
          editing ? "cursor-grab active:cursor-grabbing" : "active:scale-95",
        )}
        style={hasImage ? undefined : { backgroundColor: bg }}
      >
        {hasImage && item?.imageUrl ? (
          <>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={item.imageUrl}
              alt={label}
              className="absolute inset-0 h-full w-full object-cover"
            />
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

      {/* Overlay de edición (solo en modo edición) */}
      {editing && (
        <div className="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-white/30">
          {/* Botón eliminar (arriba-derecha) */}
          <button
            type="button"
            onClick={(e) => { e.stopPropagation(); onRemove() }}
            aria-label="Quitar"
            className="pointer-events-auto absolute right-1 top-1 flex size-5 items-center justify-center rounded-full bg-black/60 text-white hover:bg-destructive"
          >
            <X className="size-3" />
          </button>
          {/* Selector de color: pill centrado con fondo oscuro (no pisa el título) */}
          <div className="pointer-events-auto absolute inset-x-0 top-1/2 flex -translate-y-1/2 justify-center">
            <div className="rounded-full bg-black/70 px-2 py-1.5 shadow-lg">
              <ColorPicker
                value={hotkey.color}
                onChange={onColorChange}
                variant="overlay"
                stopPropagation
              />
            </div>
          </div>
        </div>
      )}
    </div>
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
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={item.imageUrl}
          alt={item.name}
          className="absolute inset-0 h-full w-full object-cover"
        />
      )}
      <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent" />
      <div className="absolute inset-x-0 bottom-0 p-2">
        <span className="line-clamp-2 text-left text-[11px] font-medium leading-tight text-white">
          {item.name}
        </span>
      </div>
      {item.isGroup && (
        <span className="absolute top-1 right-1 bg-black/60 text-white text-[10px] uppercase px-1.5 py-0.5 rounded font-medium leading-none">
          Grupo
        </span>
      )}
      <div className="absolute inset-0 bg-white/0 transition-colors group-hover:bg-white/10" />
    </button>
  )
}

// ── Slot vacío ────────────────────────────────────────────────────────────────

function EditableEmptySlot({
  editing,
  onAdd,
  onDragOver,
  onDrop,
}: {
  editing: boolean
  onAdd: () => void
  onDragOver: (e: React.DragEvent) => void
  onDrop: () => void
}) {
  if (!editing) {
    return <div className="aspect-square rounded-xl bg-sidebar" />
  }

  return (
    <button
      type="button"
      onClick={onAdd}
      onDragOver={onDragOver}
      onDrop={onDrop}
      aria-label="Agregar hotkey"
      className={cn(
        "aspect-square rounded-xl border border-dashed border-muted-foreground/30",
        "flex items-center justify-center bg-sidebar transition-colors",
        "hover:border-muted-foreground/60 hover:bg-muted/40",
      )}
    >
      <Plus className="size-4 text-muted-foreground/50" />
    </button>
  )
}
