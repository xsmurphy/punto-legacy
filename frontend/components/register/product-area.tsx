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
 * Hotkeys huérfanos: un hotkey persistido puede apuntar a un itemId/categoryId
 * que esta caja ya no puede ver (item de otra sucursal tras el fix de
 * `outletVisibilityClause`, o borrado/renombrado). El slot se renderiza como
 * `EditableEmptySlot` — igual que cualquier posición libre de la grilla — en
 * vez de un `HotkeyTile` sin nombre/imagen que no responde al tap. La
 * posición (`pos`) NUNCA se recalcula ni se compacta: el resto de la grilla
 * no se corre un pixel (§14 regla #10, memoria muscular del cajero). Tocar
 * "+" en un slot huérfano descarta esa entrada rota (client-side, recién se
 * persiste con "Listo") y abre el asignador para poner un artículo real.
 * Incidente 2026-08-18: una caja con 8/13 hotkeys apuntando a ítems de otra
 * sucursal dejaba la grilla inutilizable — el click en el tile mudo no hacía
 * nada.
 *
 * Ver concepto: Square POS-style quick keys. Legacy: ncmHotKeys (app.js:23583).
 */

import * as React from "react"
import { useRouter, useSearchParams } from "next/navigation"
import { ChevronLeft, Plus, X, Check, Loader2, Info } from "lucide-react"
import { toast } from "sonner"
import {
  DndContext,
  KeyboardSensor,
  MouseSensor,
  TouchSensor,
  closestCenter,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core"
import { CSS } from "@dnd-kit/utilities"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { useCatalogStore } from "@/lib/catalog/store"
import { resolveCategoryName } from "@/lib/catalog/resolve-names"
import { addCatalogItem } from "@/lib/cart/add-catalog-item"
import { useHotkeysStore, hotkeyColorBg, type Hotkey } from "@/lib/hotkeys/store"
import { ColorPicker } from "@/components/ui/color-picker"
import { useHotkeys } from "@/hooks/use-hotkeys"
import { useIsMobile } from "@/hooks/use-mobile"
import { HotkeyAssignDialog } from "@/components/register/hotkey-assign-dialog"
import { GroupItemsDialog } from "@/components/register/group-items-dialog"
import { ProductInfoDialog } from "@/components/register/product-info-dialog"
import { useImageFallback } from "@/components/pos/item-image"
import type { PosItem } from "@/lib/types/pos-bootstrap"

// Grilla de 300 slots (6 columnas × 50 filas), scroll vertical.
//
// Las columnas VISIBLES dependen del ancho: 3 en teléfono y 6 desde tablet
// (pedido del owner 2026-08-25 — con 6 columnas en un teléfono de 360px el
// tile queda en ~49px, más chico que la yema del dedo). El corte es `md`
// (768px), el MISMO breakpoint que `useIsMobile`, así que la grilla y el
// módulo-modal del layout cambian de forma juntos.
//
// La CANTIDAD de slots no cambia con el breakpoint a propósito: un hotkey
// guardado en la posición 250 tiene que seguir existiendo en un teléfono
// (solo cae en otra fila). Derivar SLOTS de las columnas visibles lo haría
// desaparecer de la pantalla chica.
const COLS = 6
const ROWS = 50
const SLOTS = COLS * ROWS

// Clases de la grilla — Tailwind y no `style={{gridTemplateColumns}}`: el
// número de columnas es una decisión de breakpoint, y en CSS no depende de
// que hidrate el JS (sin flash de 6 columnas en el primer paint del móvil).
const GRID_COLS = "grid grid-cols-3 gap-2 md:grid-cols-6"

const DEFAULT_TILE = "#3f4651"

// ── Identidad de los nodos de drag & drop ────────────────────────────────────
// Un mismo slot es arrastrable (si tiene hotkey) y soltable (siempre), y
// dnd-kit exige ids únicos por rol. El número de slot es la única fuente de
// verdad de la posición: se codifica en el id y se vuelve a leer al soltar.
const dragId = (pos: number) => `hk-${pos}`
const dropId = (pos: number) => `slot-${pos}`

function slotOf(id: string | number): number | null {
  const m = /^(?:hk|slot)-(\d+)$/.exec(String(id))
  return m ? Number(m[1]) : null
}

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
  const isMobile = useIsMobile()
  React.useEffect(() => {
    if (searchParams.get("hotkeys") === "edit") {
      setEditing(true)
      // En mobile la grilla vive dentro del módulo-modal del layout, que está
      // abierto justamente por este param. Limpiarlo a secas cerraría el modal
      // y dejaría el editor invisible otra vez: se preserva `?view=hotkeys`,
      // el param estable que mantiene el módulo abierto (ver el layout de /pos).
      router.replace(isMobile ? "/pos?view=hotkeys" : "/pos", { scroll: false })
    }
  }, [searchParams, setEditing, router, isMobile])

  // Vista: grilla de hotkeys, o productos de una categoría (drill-in).
  const [categoryId, setCategoryId] = React.useState<string | null>(null)

  // Slot cuyo diálogo de asignación está abierto (null = cerrado).
  const [assigningSlot, setAssigningSlot] = React.useState<number | null>(null)

  // Grupo de catálogo seleccionado (abre GroupItemsDialog con sus hijos).
  const [groupPicker, setGroupPicker] = React.useState<PosItem | null>(null)

  // Ítem cuya ficha está abierta (ícono de info del tile). null = cerrada.
  const [infoItem, setInfoItem] = React.useState<PosItem | null>(null)

  // ── Drag & drop de la grilla en modo edición ──────────────────────────────
  //
  // Antes esto era el drag&drop NATIVO de HTML5 (`draggable` + onDragStart /
  // onDragOver / onDrop). Esos eventos no existen en touch: ningún navegador
  // móvil los emite, así que en tablet o celular —el caso de uso principal de
  // /pos— la grilla directamente no se podía reordenar (reporte del owner
  // 2026-08-24). No era un bug de estilos ni de hit area: el gesto nunca
  // llegaba al componente.
  //
  // dnd-kit (ya dependencia del proyecto, ver `catalog-manager.tsx`) unifica
  // mouse, touch y teclado sobre pointer events. Los sensores van con
  // activación DISTINTA por plataforma, y la diferencia es la que hace que
  // esto sea usable:
  //
  // - Mouse: activa por distancia (4px, igual que el resto del proyecto). El
  //   arrastre con mouse se siente inmediato, como antes.
  // - Touch: activa por PRESIÓN SOSTENIDA (250ms). Un umbral de distancia acá
  //   le robaría el gesto al scroll — la grilla son 300 slots y el dedo la
  //   recorre deslizando. Con delay los dos gestos conviven: el swipe rápido
  //   scrollea, el long-press levanta el tile.
  //
  // Al activar por delay NO se pone `touch-action: none` en los tiles (lo que
  // mataría el scroll de la grilla); dnd-kit solo bloquea el scroll una vez
  // que el drag ya arrancó.
  const sensors = useSensors(
    useSensor(MouseSensor, { activationConstraint: { distance: 4 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 250, tolerance: 8 } }),
    useSensor(KeyboardSensor),
  )

  const handleDragEnd = React.useCallback(
    (e: DragEndEvent) => {
      const from = slotOf(e.active.id)
      const to = e.over ? slotOf(e.over.id) : null
      if (from === null || to === null || from === to) return
      moveHotkey(from, to)
    },
    [moveHotkey],
  )

  // Índices auxiliares.
  const itemById = React.useMemo(() => {
    const m = new Map<string, PosItem>()
    for (const i of items) m.set(i.id, i)
    return m
  }, [items])

  // Categorías del tenant (context/45: lista propia, ya no derivada de los
  // items — una categoría sin productos ahora existe para la caja también).
  const categories = useCatalogStore((s) => s.categories)
  const categoryMap = React.useMemo(
    () => new Map(categories.map((c) => [c.id, c.name])),
    [categories],
  )

  // Lista para la barra flotante de categorías (orden del bundle: alfabético).
  const categoryList = categories

  const hotkeyAt = React.useMemo(() => {
    const m = new Map<number, Hotkey>()
    for (const h of hotkeys) m.set(h.position, h)
    return m
  }, [hotkeys])

  // Hotkey huérfano: apunta a un itemId/categoryId que esta caja no puede ver
  // (item de otra sucursal, o item/categoría borrada). Se trata como slot
  // vacío reusable — ver docstring del archivo.
  const isOrphanHotkey = React.useCallback(
    (h: Hotkey) => (h.isCategory ? !categoryMap.has(h.itemId) : !itemById.has(h.itemId)),
    [categoryMap, itemById],
  )

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
          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={handleDragEnd}
          >
            <div className={GRID_COLS}>
              {Array.from({ length: SLOTS }).map((_, pos) => {
                const h = hotkeyAt.get(pos)
                if (!h || isOrphanHotkey(h)) {
                  return (
                    <EditableEmptySlot
                      key={pos}
                      pos={pos}
                      editing={editing}
                      onAdd={() => {
                        // Slot huérfano: descartamos la entrada rota antes de
                        // abrir el asignador — el "+" lo reemplaza por un
                        // artículo real, no lo apila. Client-side nomás: recién
                        // se persiste si el cajero llega a "Listo".
                        if (h) removeHotkey(pos)
                        setAssigningSlot(pos)
                      }}
                    />
                  )
                }
                const tileProps = {
                  hotkey: h,
                  item: h.isCategory ? null : itemById.get(h.itemId) ?? null,
                  label: h.isCategory
                    ? resolveCategoryName(h.itemId, categoryMap) ?? "Categoría"
                    : itemById.get(h.itemId)?.name ?? "—",
                  editing,
                  onClick: () => handleHotkeyClick(h),
                  onInfo: setInfoItem,
                  onRemove: () => removeHotkey(pos),
                  onColorChange: (color: string) => setColor(pos, color),
                }
                // Solo el modo edición monta los hooks de dnd — ver `HotkeyCell`.
                return editing ? (
                  <DraggableHotkeyCell key={pos} pos={pos} {...tileProps} />
                ) : (
                  <HotkeyCell key={pos} {...tileProps} />
                )
              })}
            </div>
          </DndContext>
        ) : (
          // ── Productos de la categoría (drill-in) ──
          // Misma grilla que los hotkeys: el drill-in es la MISMA pantalla un
          // nivel adentro, y quedaría raro que cambie de columnas al entrar.
          <div className={GRID_COLS}>
            {categoryItems.map((item) => (
              <ProductTile
                key={item.id}
                item={item}
                onClick={() => handleProductClick(item)}
                onInfo={setInfoItem}
              />
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

      {/* Ficha del producto (ícono de info del tile). */}
      <ProductInfoDialog item={infoItem} onClose={() => setInfoItem(null)} />
    </div>
  )
}

// ── Ícono de info del tile ───────────────────────────────────────────────────

/**
 * Affordance SECUNDARIO de la ficha: se pinta SOBRE el tile, absolute, sin
 * ocupar lugar en el flujo — la grilla no se corre un pixel y el tap principal
 * (agregar al carrito) sigue cubriendo el resto del tile (§14 regla #10:
 * posiciones estables, el cajero opera de memoria).
 *
 * `size="icon"` = 32px de hit area, el mínimo táctil aunque el ícono sea de
 * 16. `stopPropagation` para que abrir la ficha nunca agregue el ítem.
 */
function TileInfoButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <Button
      variant="ghost"
      size="icon"
      aria-label={`Ver ficha de ${label}`}
      onClick={(e) => {
        e.stopPropagation()
        onClick()
      }}
      className="absolute right-0.5 top-0.5 z-10 text-white/75 hover:bg-white/20 hover:text-white"
    >
      <Info />
    </Button>
  )
}

// ── Tile de hotkey (categoría o item) ─────────────────────────────────────────

interface HotkeyTileProps {
  hotkey: Hotkey
  item: PosItem | null
  label: string
  editing: boolean
  onClick: () => void
  /** Abre la ficha. Solo para tiles de artículo — un tile de categoría no tiene ficha. */
  onInfo: (item: PosItem) => void
  onRemove: () => void
  onColorChange: (color: string) => void
}

/**
 * Contenido visual del tile. No sabe nada de drag & drop: las dos capas que lo
 * envuelven (y los hooks de dnd, cuando corresponde) las ponen las celdas de
 * más abajo.
 */
function HotkeyTile({
  hotkey,
  item,
  label,
  editing,
  onClick,
  onInfo,
  onRemove,
  onColorChange,
}: HotkeyTileProps) {
  // `imageOk` y no solo "hay url": sin conexión la foto puede no estar en la
  // cache del SW, y entonces el tile tiene que caer al layout de tabla
  // periódica (abreviatura sobre el color de la tecla) en vez de mostrar el
  // ícono de imagen rota del navegador.
  const [imageOk, onImageError] = useImageFallback(hotkey.isCategory ? null : item?.imageUrl)
  const hasImage = imageOk
  const bg = hotkeyColorBg(hotkey.color) ?? DEFAULT_TILE

  return (
    <>
      <button
        onClick={onClick}
        aria-label={label}
        tabIndex={editing ? -1 : undefined}
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
              onError={onImageError}
              className="absolute inset-0 h-full w-full object-cover"
            />
            <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent" />
          </>
        ) : (
          // Modo tabla periódica: abreviatura grande + nombre abajo.
          // Escala con el tile: en teléfono la grilla es de 3 columnas y el
          // tile mide más del doble, así que la abreviatura sube un paso.
          <span className="text-3xl font-bold leading-none tracking-tight text-white/90 md:text-2xl">
            {abbrev(label)}
          </span>
        )}
        <span
          className={cn(
            // `text-sm` en teléfono por el mismo motivo que la abreviatura: a
            // 11px el nombre quedaba perdido en un tile de 3 columnas. Sigue
            // recortando con `line-clamp-2`, nunca desborda.
            "line-clamp-2 text-sm font-medium leading-tight md:text-[11px]",
            hasImage ? "absolute inset-x-0 bottom-0 z-10 p-2 text-white" : "text-white/85",
          )}
        >
          {label}
        </span>
        <div className="absolute inset-0 bg-white/0 transition-colors group-hover:bg-white/10" />
      </button>

      {/* Ficha del producto. Fuera del <button> (un botón no puede anidar otro)
          y oculta en edición: esa esquina la ocupa el botón de quitar. Las
          categorías no llevan ficha. */}
      {!editing && item && <TileInfoButton label={label} onClick={() => onInfo(item)} />}

      {/* Overlay de edición (solo en modo edición).

          Los controles cortan `pointerdown`: sin eso, mantener apretado el
          botón de quitar o el selector de color 250ms dispararía el sensor
          táctil y el tile se empezaría a arrastrar debajo del dedo en vez de
          responder al control. */}
      {editing && (
        <div className="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-white/30">
          {/* Botón eliminar (arriba-derecha) */}
          <button
            type="button"
            onPointerDown={(e) => e.stopPropagation()}
            onClick={(e) => { e.stopPropagation(); onRemove() }}
            aria-label="Quitar"
            className="pointer-events-auto absolute right-1 top-1 flex size-5 items-center justify-center rounded-full bg-black/60 text-white hover:bg-destructive"
          >
            <X className="size-3" />
          </button>
          {/* Selector de color: pill centrado con fondo oscuro (no pisa el título) */}
          <div
            className="pointer-events-auto absolute inset-x-0 top-1/2 flex -translate-y-1/2 justify-center"
            onPointerDown={(e) => e.stopPropagation()}
          >
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
    </>
  )
}

/**
 * Celda de un slot ocupado en modo VENTA.
 *
 * No monta ningún hook de dnd — y esa es toda la razón por la que existe
 * separada de `DraggableHotkeyCell`. La grilla son 300 slots y esta es la
 * pantalla por defecto de la caja: montar 300 `useDroppable` (más los
 * `useDraggable` de los ocupados) para dejarlos `disabled` es trabajo que en
 * una tablet vieja se paga en cada render de la pantalla más caliente del
 * producto. En modo venta no hay drag posible, así que directamente no se
 * monta nada.
 *
 * Cambiar de modo remonta las celdas (cambia el tipo de componente en esa
 * posición). Es aceptable: entrar y salir de edición es raro, y las celdas no
 * guardan estado propio más allá del fallback de imagen, que resuelve del
 * cache del navegador.
 */
function HotkeyCell(props: HotkeyTileProps) {
  return (
    <div className="relative aspect-square">
      <div className="group relative h-full w-full rounded-xl">
        <HotkeyTile {...props} />
      </div>
    </div>
  )
}

/**
 * Celda de un slot ocupado en modo EDICIÓN: se puede levantar y también recibir
 * otro tile encima (soltar sobre un slot ocupado = swap, ver `moveHotkey`).
 */
function DraggableHotkeyCell({ pos, ...props }: HotkeyTileProps & { pos: number }) {
  const {
    attributes,
    listeners,
    setNodeRef: setDragRef,
    transform,
    isDragging,
  } = useDraggable({ id: dragId(pos) })
  const { setNodeRef: setDropRef, isOver } = useDroppable({ id: dropId(pos) })

  return (
    // Dos capas a propósito: la EXTERNA es la celda de la grilla y nunca se
    // mueve (es el nodo droppable, y mantiene el `aspect-square` que reserva el
    // lugar); la INTERNA es la que se transforma siguiendo al dedo. Si el
    // transform viviera en la celda, levantar un tile recalcularía el grid y
    // toda la grilla se correría — exactamente lo que prohíbe la regla #10 de
    // context/14 (memoria muscular del cajero).
    <div ref={setDropRef} className="relative aspect-square">
      <div
        ref={setDragRef}
        style={{
          transform: CSS.Translate.toString(transform),
          zIndex: isDragging ? 50 : undefined,
          opacity: isDragging ? 0.85 : undefined,
          // Con activación por delay no hace falta `touch-action: none` (que
          // mataría el scroll de la grilla). `manipulation` solo saca el
          // retardo del doble-tap para zoom.
          touchAction: "manipulation",
        }}
        {...listeners}
        {...attributes}
        className={cn(
          "group relative h-full w-full rounded-xl",
          // Señal de destino: un ring, que no ocupa lugar en el layout.
          isOver && !isDragging && "ring-2 ring-primary",
        )}
      >
        <HotkeyTile {...props} />
      </div>
    </div>
  )
}

// ── Tile de producto (vista drill-in de categoría) ────────────────────────────

function ProductTile({
  item,
  onClick,
  onInfo,
}: {
  item: PosItem
  onClick: () => void
  onInfo: (item: PosItem) => void
}) {
  // Igual que en HotkeyTile: si la foto no está (sin conexión y sin cache), el
  // tile vuelve al color por defecto de la grilla — nunca al ícono roto.
  const [imageOk, onImageError] = useImageFallback(item.imageUrl)

  return (
    // El tile pasó de ser un <button> a un <div> con el botón adentro: el ícono
    // de la ficha es otro botón y anidarlos es HTML inválido (el click interno
    // se lo come el externo en varios navegadores).
    <div className="group relative aspect-square">
      <button
        onClick={onClick}
        aria-label={item.name}
        className="relative flex h-full w-full flex-col overflow-hidden rounded-xl transition-all active:scale-95"
        style={imageOk ? undefined : { backgroundColor: DEFAULT_TILE }}
      >
        {imageOk && item.imageUrl && (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={item.imageUrl}
            alt={item.name}
            onError={onImageError}
            className="absolute inset-0 h-full w-full object-cover"
          />
        )}
        <div className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent" />
        <div className="absolute inset-x-0 bottom-0 p-2">
          {/* Misma escala que el label del hotkey: text-sm en la grilla de 3
              columnas del teléfono, 11px desde tablet. */}
          <span className="line-clamp-2 text-left text-sm font-medium leading-tight text-white md:text-[11px]">
            {item.name}
          </span>
        </div>
        {item.isGroup ? (
          // A la izquierda: la esquina derecha es del ícono de la ficha, que
          // está en el mismo lugar en toda la grilla.
          <span className="absolute top-1 left-1 bg-black/60 text-white text-[10px] uppercase px-1.5 py-0.5 rounded font-medium leading-none">
            Grupo
          </span>
        ) : (item.kind === "combo_fijo" || item.kind === "combo_dinamico") ? (
          // Mismo slot que "Grupo" (mutuamente excluyentes) — pista de que el
          // ítem tiene composición para consultar en la ficha (ícono de info)
          // antes de agregarlo. "Despliegue de Combos", tester 2026-08-19.
          <span className="absolute top-1 left-1 bg-black/60 text-white text-[10px] uppercase px-1.5 py-0.5 rounded font-medium leading-none">
            Combo
          </span>
        ) : null}
        <div className="absolute inset-0 bg-white/0 transition-colors group-hover:bg-white/10" />
      </button>

      <TileInfoButton label={item.name} onClick={() => onInfo(item)} />
    </div>
  )
}

// ── Slot vacío ────────────────────────────────────────────────────────────────

function EditableEmptySlot({
  pos,
  editing,
  onAdd,
}: {
  /** Slot que ocupa. Es su identidad como destino de drop. */
  pos: number
  editing: boolean
  onAdd: () => void
}) {
  // En modo venta un slot libre es un rectángulo y nada más: ni botón ni
  // droppable. Son la mayoría de los 300 slots, así que es donde más se nota
  // no montar hooks de dnd (ver `HotkeyCell`).
  if (!editing) {
    return <div className="aspect-square rounded-xl bg-sidebar" />
  }

  return <DroppableEmptySlot pos={pos} onAdd={onAdd} />
}

/** Slot libre en modo edición: destino de drop y atajo para asignar. */
function DroppableEmptySlot({ pos, onAdd }: { pos: number; onAdd: () => void }) {
  const { setNodeRef, isOver } = useDroppable({ id: dropId(pos) })

  return (
    <button
      ref={setNodeRef}
      type="button"
      onClick={onAdd}
      aria-label="Agregar hotkey"
      className={cn(
        "aspect-square rounded-xl border border-dashed border-muted-foreground/30",
        "flex items-center justify-center bg-sidebar transition-colors",
        "hover:border-muted-foreground/60 hover:bg-muted/40",
        // Señal de destino: solo cambia color, no dimensiones (regla #10).
        isOver && "border-solid border-primary bg-muted/60",
      )}
    >
      <Plus className="size-4 text-muted-foreground/50" />
    </button>
  )
}
