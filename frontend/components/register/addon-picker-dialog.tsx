"use client"

/**
 * Modal de selección de add-ons (F4, context/41-addons-y-combos.md).
 *
 * Se abre al tocar un producto con `hasAddons` (el intercept vive en el wrapper
 * `lib/cart/add-catalog-item.ts`, así entra igual desde la grilla, la búsqueda
 * y el scanner) y al reabrirlo desde una línea del carrito para reemplazar lo
 * elegido. Montado UNA sola vez en `cart-panel.tsx`, junto a
 * `<GiftcardIssueDialog>`.
 *
 * PRECIO: el modal muestra base + Σ (priceDelta × qty) en vivo y esa suma entra
 * al carrito como `unitPrice` de la línea. NO es cosmético: el server no deriva
 * el total de la venta del detalle de líneas (ver el docblock de
 * `SaleService::expandAddonSelections`), así que el recargo tiene que estar en
 * el precio de la línea o el add-on se vende gratis. El server igual revalida
 * las opciones y recalcula los deltas contra la BD al vender: lo que se manda
 * es `optionId` + `qty`, nunca plata.
 *
 * REGLAS (espejo exacto de `AddonService::validateSelections`):
 *   - `minSelect`/`maxSelect` cuentan OPCIONES DISTINTAS elegidas.
 *   - `maxQty` limita la repetición de UNA misma opción.
 *   - `isLocked` va marcada y no se puede desmarcar; `isDefault` arranca
 *     marcada pero se puede quitar.
 *
 * TECLADO (context/pos: la caja se opera sin mouse): flechas ↑/↓ mueven entre
 * opciones —de todos los grupos, en orden—, Espacio marca/desmarca, +/− ajustan
 * la cantidad de la opción enfocada, Enter confirma si la selección es válida,
 * ESC cancela. El foco arranca en la primera opción.
 *
 * TOUCH: cada opción es un botón `size="lg"` de ancho completo (§14 §2.2).
 */

import * as React from "react"
import { Check, Loader2, Minus, Plus } from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { Separator } from "@/components/ui/separator"
import { cn } from "@/lib/utils"
import { formatAmount, formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import { useAddonPickerStore } from "@/lib/cart/addon-picker-store"
import { useCartStore, type CartLineAddon } from "@/lib/cart/store"
import { useItemAddonsPos, type PosAddonGroup, type PosAddonOption } from "@/hooks/use-item-addons-pos"

/** Cantidad elegida por optionId. 0 / ausente = no elegida. */
type QtyByOption = Record<string, number>

/** Texto de la regla del grupo, visible junto al nombre. */
function groupRule(group: PosAddonGroup): string {
  const { minSelect, maxSelect } = group
  if (minSelect === 1 && maxSelect === 1) return "Elegí 1"
  if (minSelect > 0 && maxSelect !== null && minSelect === maxSelect) return `Elegí ${minSelect}`
  if (minSelect > 0 && maxSelect !== null) return `Elegí entre ${minSelect} y ${maxSelect}`
  if (minSelect > 0) return `Mínimo ${minSelect}`
  if (maxSelect !== null) return `Hasta ${maxSelect}`
  return "Opcional"
}

/** Opciones distintas elegidas de un grupo (criterio del validador server-side). */
function countSelected(group: PosAddonGroup, qty: QtyByOption): number {
  return group.options.reduce((n, o) => n + ((qty[o.id] ?? 0) > 0 ? 1 : 0), 0)
}

/** Estado inicial: las locked/default marcadas, o lo que ya tenía la línea. */
function initialQty(groups: PosAddonGroup[], existing: CartLineAddon[]): QtyByOption {
  const byOption: QtyByOption = {}
  const hadSelection = existing.length > 0
  const existingQty = new Map(existing.map((s) => [s.optionId, s.qty]))

  for (const group of groups) {
    for (const option of group.options) {
      if (option.isLocked) {
        // Fija: siempre presente, aunque la línea guardada no la trajera (un
        // grupo puede haberse vuelto obligatorio después de aparcar la venta).
        byOption[option.id] = Math.max(1, Math.min(option.maxQty, existingQty.get(option.id) ?? 1))
        continue
      }
      const saved = existingQty.get(option.id)
      if (saved !== undefined) {
        byOption[option.id] = Math.max(1, Math.min(option.maxQty, saved))
      } else if (!hadSelection && option.isDefault) {
        byOption[option.id] = 1
      }
    }
  }
  return byOption
}

export function AddonPickerDialog() {
  const pendingItem = useAddonPickerStore((s) => s.pendingItem)
  const editingLineId = useAddonPickerStore((s) => s.editingLineId)
  const initialSelections = useAddonPickerStore((s) => s.initialSelections)
  const close = useAddonPickerStore((s) => s.close)
  const config = useCatalogStore((s) => s.config)

  const { data, isPending, isError, refetch, isFetching } = useItemAddonsPos(pendingItem?.id ?? null)

  const groups = React.useMemo(
    () => (data?.groups ?? []).filter((g) => g.options.length > 0),
    [data],
  )

  const [qty, setQty] = React.useState<QtyByOption>({})
  // Índice de la opción enfocada dentro de la lista PLANA (todos los grupos en
  // orden) — es lo que hace que las flechas crucen de un grupo al siguiente sin
  // que el cajero tenga que tabular.
  const [focusIndex, setFocusIndex] = React.useState(0)
  const rowRefs = React.useRef<(HTMLButtonElement | null)[]>([])
  const optionsRef = React.useRef<HTMLDivElement | null>(null)

  const open = pendingItem !== null

  /** Lista plana [grupo, opción] en el orden en que se pintan. */
  const flat = React.useMemo(
    () => groups.flatMap((group) => group.options.map((option) => ({ group, option }))),
    [groups],
  )

  // Reset al abrir / al llegar los grupos. `initialSelections` viene del store
  // (línea que se edita) o vacío (alta).
  React.useEffect(() => {
    if (!open) return
    setQty(initialQty(groups, initialSelections))
    setFocusIndex(0)
  }, [open, groups, initialSelections])

  // Foco en la opción activa: arranca en la primera (autofocus) y sigue a las
  // flechas. `preventScroll` no: en una lista larga el cajero necesita que la
  // opción enfocada entre en pantalla.
  React.useEffect(() => {
    if (!open) return
    rowRefs.current[focusIndex]?.focus()
  }, [open, focusIndex, flat.length])

  const basePrice = pendingItem?.price ?? 0
  const addonsTotal = React.useMemo(
    () =>
      flat.reduce((sum, { option }) => sum + option.priceDelta * (qty[option.id] ?? 0), 0),
    [flat, qty],
  )
  const livePrice = basePrice + addonsTotal

  /**
   * Primera regla incumplida — el motivo que se muestra junto al botón.
   *
   * Se validan mínimos Y máximos. El máximo no alcanza con bloquearlo en
   * `toggleOption`: al REABRIR una línea aparcada, las selecciones vienen de lo
   * guardado y el grupo puede haberse achicado en el panel desde entonces. Sin
   * este chequeo el cajero confirmaba sin tocar nada y la venta reventaba con
   * un 422 de `AddonService::validateSelections` recién al cobrar.
   */
  const blockingReason = React.useMemo(() => {
    for (const group of groups) {
      const selected = countSelected(group, qty)
      if (selected < group.minSelect) {
        return group.minSelect === 1
          ? `Falta elegir ${group.name}`
          : `Faltan ${group.minSelect - selected} en ${group.name}`
      }
      if (group.maxSelect !== null && selected > group.maxSelect) {
        return `${group.name} admite hasta ${group.maxSelect} — quitá ${selected - group.maxSelect}`
      }
    }
    return null
  }, [groups, qty])

  const canConfirm = !isPending && !isError && blockingReason === null

  function toggleOption(group: PosAddonGroup, option: PosAddonOption) {
    if (option.isLocked) return // fija: no se puede quitar (D1/D2, context/41)
    setQty((prev) => {
      const next = { ...prev }
      const isSelected = (next[option.id] ?? 0) > 0
      if (isSelected) {
        delete next[option.id]
        return next
      }
      // Grupo de una sola opción (radio): elegir reemplaza a la anterior, salvo
      // las fijas, que no se sacan nunca.
      if (group.maxSelect === 1) {
        for (const o of group.options) {
          if (!o.isLocked) delete next[o.id]
        }
      } else if (group.maxSelect !== null && countSelected(group, next) >= group.maxSelect) {
        // Tope alcanzado: no se marca nada más. El contador del encabezado ya
        // muestra cuántas van sobre el máximo.
        return prev
      }
      next[option.id] = 1
      return next
    })
  }

  function bumpQty(option: PosAddonOption, delta: number) {
    if (option.maxQty <= 1) return
    setQty((prev) => {
      const current = prev[option.id] ?? 0
      if (current === 0) return prev // primero se marca, después se repite
      const target = current + delta
      if (target < 1) return prev // bajar de 1 es desmarcar → se hace con Espacio
      if (target > option.maxQty) return prev
      return { ...prev, [option.id]: target }
    })
  }

  function handleConfirm() {
    if (!pendingItem || !canConfirm) return
    const selections: CartLineAddon[] = flat
      .filter(({ option }) => (qty[option.id] ?? 0) > 0)
      .map(({ option }) => ({
        optionId: option.id,
        qty: qty[option.id] as number,
        itemId: option.itemId,
        name: option.itemName,
        priceDelta: option.priceDelta,
      }))

    if (editingLineId) {
      useCartStore.getState().setLineSelections(editingLineId, selections)
    } else {
      useCartStore.getState().addItem({
        id: pendingItem.id,
        name: pendingItem.name,
        price: pendingItem.price,
        kind: pendingItem.kind,
        discountPercent: pendingItem.discountPercent,
        taxId: pendingItem.taxId,
        taxIncluded: pendingItem.taxIncluded,
        selections,
      })
    }
    close()
  }

  /**
   * Teclado de la caja. Se maneja en el contenedor y no opción por opción para
   * que las flechas crucen los grupos; Espacio y Enter se interceptan con
   * `preventDefault` para que el botón enfocado no dispare además su click
   * (Espacio activaría el toggle dos veces y Enter marcaría en vez de cobrar).
   */
  function handleKeyDown(e: React.KeyboardEvent) {
    if (flat.length === 0) return
    const entry = flat[focusIndex]
    // Espacio/±/Enter solo cuando el foco está en la lista de opciones. Con el
    // foco en el footer, Espacio y Enter tienen que activar el botón enfocado
    // (Cancelar es Cancelar) — interceptarlos siempre hacía que tabular hasta
    // "Cancelar" y apretar Enter confirmara la venta.
    const inOptions = optionsRef.current?.contains(e.target as Node) ?? false

    if (e.key === "ArrowDown" || e.key === "ArrowRight") {
      e.preventDefault()
      setFocusIndex((i) => Math.min(flat.length - 1, i + 1))
      return
    }
    if (e.key === "ArrowUp" || e.key === "ArrowLeft") {
      e.preventDefault()
      setFocusIndex((i) => Math.max(0, i - 1))
      return
    }
    if (!inOptions) return

    if (e.key === " " || e.key === "Spacebar") {
      e.preventDefault()
      if (entry) toggleOption(entry.group, entry.option)
      return
    }
    if (e.key === "+" || e.key === "=") {
      e.preventDefault()
      if (entry) bumpQty(entry.option, 1)
      return
    }
    if (e.key === "-" || e.key === "_") {
      e.preventDefault()
      if (entry) bumpQty(entry.option, -1)
      return
    }
    if (e.key === "Enter") {
      e.preventDefault()
      handleConfirm()
    }
  }

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) close() }}>
      {/* mobileFullscreen: mismo criterio que la ficha de producto — es un
          modal de CONTENIDO (lista de opciones que puede ser larga), no un
          modal chico de interacción (§14 §2.2). */}
      <DialogContent className="sm:max-w-2xl" mobileFullscreen onKeyDown={handleKeyDown}>
        <DialogHeader>
          <DialogTitle>{pendingItem?.name ?? "Add-ons"}</DialogTitle>
          <DialogDescription>
            {editingLineId ? "Cambiá los add-ons de esta línea" : "Elegí los add-ons del producto"}
          </DialogDescription>
        </DialogHeader>

        <div ref={optionsRef} className="flex max-h-[55dvh] flex-col gap-5 overflow-y-auto">
          {isPending && (
            <div className="flex flex-col gap-2">
              <Skeleton className="h-5 w-40" />
              <Skeleton className="h-12 w-full" />
              <Skeleton className="h-12 w-full" />
            </div>
          )}

          {isError && (
            <div className="flex flex-col items-start gap-3">
              <p className="text-sm text-muted-foreground">
                No se pudieron cargar los add-ons de este producto.
              </p>
              <Button variant="outline" size="lg" onClick={() => void refetch()} disabled={isFetching}>
                {isFetching && <Loader2 className="size-4 animate-spin" aria-hidden />}
                Reintentar
              </Button>
            </div>
          )}

          {!isPending && !isError && groups.length === 0 && (
            <p className="text-sm text-muted-foreground">
              Este producto ya no tiene add-ons configurados. Se agrega tal cual.
            </p>
          )}

          {!isPending &&
            !isError &&
            groups.map((group) => {
              const selected = countSelected(group, qty)
              return (
                <div key={group.id} className="flex flex-col gap-2">
                  <div className="flex items-baseline justify-between gap-3">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      {group.name}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {groupRule(group)}
                      {group.maxSelect !== null && group.maxSelect > 1
                        ? ` · ${selected}/${group.maxSelect}`
                        : ""}
                    </p>
                  </div>

                  <div className="flex flex-col gap-1.5">
                    {group.options.map((option) => {
                      const index = flat.findIndex((f) => f.option.id === option.id)
                      const optionQty = qty[option.id] ?? 0
                      const isSelected = optionQty > 0
                      const isRadio = group.maxSelect === 1
                      return (
                        <div key={option.id} className="flex items-center gap-1.5">
                          <Button
                            ref={(el) => { rowRefs.current[index] = el }}
                            type="button"
                            variant={isSelected ? "secondary" : "outline"}
                            size="lg"
                            // Touch target de cajero en tablet + el foco de
                            // teclado tiene que verse a un metro de distancia.
                            className={cn(
                              "h-14 flex-1 justify-between gap-3 px-4 text-left",
                              isSelected && "border-primary",
                              option.isLocked && "opacity-100",
                            )}
                            role={isRadio ? "radio" : "checkbox"}
                            aria-checked={isSelected}
                            // Las fijas no se desmarcan, pero siguen siendo
                            // navegables: el cajero tiene que poder leerlas y,
                            // si admiten repetición, subirles la cantidad.
                            aria-disabled={option.isLocked || undefined}
                            tabIndex={index === focusIndex ? 0 : -1}
                            onFocus={() => setFocusIndex(index)}
                            onClick={() => toggleOption(group, option)}
                          >
                            <span className="flex min-w-0 items-center gap-2.5">
                              <span
                                className={cn(
                                  "flex size-5 shrink-0 items-center justify-center border",
                                  isRadio ? "rounded-full" : "rounded-sm",
                                  isSelected
                                    ? "border-primary bg-primary text-primary-foreground"
                                    : "border-input",
                                )}
                                aria-hidden
                              >
                                {isSelected && <Check className="size-3.5" />}
                              </span>
                              <span className="truncate font-medium">{option.itemName}</span>
                              {option.isLocked && (
                                <span className="shrink-0 text-xs text-muted-foreground">Fijo</span>
                              )}
                            </span>
                            {option.priceDelta > 0 && (
                              <span className="shrink-0 text-sm font-semibold tabular-nums">
                                + {formatMoney(option.priceDelta, config)}
                              </span>
                            )}
                          </Button>

                          {/* Stepper de repetición. Existe SIEMPRE que la
                              opción admita más de una (deshabilitado si no
                              está marcada): que aparezca al marcar movería los
                              botones de lugar entre estados (§14 §10). */}
                          {option.maxQty > 1 && (
                            <div className="flex shrink-0 items-center gap-1">
                              <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="size-10"
                                aria-label={`Menos ${option.itemName}`}
                                disabled={optionQty <= 1}
                                tabIndex={-1}
                                onClick={() => bumpQty(option, -1)}
                              >
                                <Minus className="size-4" />
                              </Button>
                              <span className="min-w-6 text-center text-sm font-semibold tabular-nums">
                                {optionQty}
                              </span>
                              <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="size-10"
                                aria-label={`Más ${option.itemName}`}
                                disabled={!isSelected || optionQty >= option.maxQty}
                                tabIndex={-1}
                                onClick={() => bumpQty(option, 1)}
                              >
                                <Plus className="size-4" />
                              </Button>
                            </div>
                          )}
                        </div>
                      )
                    })}
                  </div>
                </div>
              )
            })}
        </div>

        <Separator />

        <div className="flex items-baseline justify-between gap-3">
          <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Precio
          </span>
          <span className="text-2xl font-semibold tabular-nums">
            {formatMoney(livePrice, config)}
            {addonsTotal > 0 && (
              <span className="ml-2 text-sm font-normal text-muted-foreground">
                base {formatAmount(basePrice, config)} + {formatAmount(addonsTotal, config)}
              </span>
            )}
          </span>
        </div>

        <DialogFooter className="flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          {/* El motivo ocupa su lugar siempre (sin motivo, un espacio vacío):
              que aparezca y desaparezca movería los botones de abajo (§14 §10). */}
          <p className="min-h-5 text-sm text-muted-foreground" role="status">
            {blockingReason}
          </p>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Button variant="outline" size="lg" className="w-full sm:w-auto" onClick={close}>
              Cancelar
            </Button>
            <Button size="lg" className="w-full sm:w-auto" disabled={!canConfirm} onClick={handleConfirm}>
              {editingLineId ? "Guardar" : "Agregar"}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
