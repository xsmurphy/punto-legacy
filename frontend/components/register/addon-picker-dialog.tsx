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
 *   - `qtyMode` decide QUÉ cuentan `minSelect`/`maxSelect` (mig 203):
 *       · `"options"`  — opciones DISTINTAS elegidas (modo histórico).
 *       · `"quantity"` — la SUMA de cantidades del grupo. Es la "caja surtida
 *         de 100 empanadas": el cajero reparte los sabores como quiera y lo
 *         único que se topea es el total.
 *   - `maxQty` limita la repetición de UNA misma opción; `null` = sin tope
 *     propio, manda el del grupo.
 *   - `isLocked` va marcada y no se puede desmarcar; `isDefault` arranca
 *     marcada pero se puede quitar.
 *
 * CANTIDAD A MANO, no solo + / − (pedido del owner 2026-09-07: "si quiero 50
 * empanadas de un sabor voy a tener que presionar 50 veces"). Cada opción que
 * puede pasar de 1 muestra su cantidad como un CONTROL: tocarla abre el
 * `<NumericPadDialog>` y se tipea "50" de una.
 *
 * Se escribe en el PAD y no en un `<Input inputMode="numeric">` a propósito
 * (§14 §11): en el POS toda cantidad se captura con `<NumericPad>`, nunca con
 * el teclado del sistema. La rama de input nativo se intentó el 2026-08-25, el
 * owner la declaró regresión el mismo día y hay un guard test
 * (`lib/pos/__tests__/numeric-capture.test.ts`) para que no vuelva. El pedido
 * —tipear la cantidad en vez de tocar cincuenta veces— se cumple igual, y con
 * teclas del tamaño de un dedo en vez de un campo de 64px.
 *
 * BUSCADOR: los grupos con más de `LIST_FILTER_THRESHOLD` opciones ganan un
 * filtro por nombre arriba de la lista (mismo pedido del owner). Filtra lo que
 * se PINTA, nunca lo elegido: una opción que queda fuera del filtro sigue en la
 * selección y se confirma igual — por eso `handleConfirm` itera todas las
 * opciones y no las visibles.
 *
 * TECLADO (context/pos: la caja se opera sin mouse): flechas ↑/↓ mueven entre
 * las opciones VISIBLES —de todos los grupos, en orden—, Espacio marca/desmarca,
 * +/− ajustan la cantidad de la opción enfocada, un DÍGITO abre el pad con ese
 * dígito ya tipeado (el camino de teclado para "50", equivalente a tocar la
 * cantidad), Enter confirma si la selección es válida, ESC cancela. Con el foco
 * dentro del buscador de un grupo esas teclas son del campo: ahí solo Enter
 * confirma, para no secuestrar el tipeo.
 *
 * TOUCH: cada opción es un botón `size="lg"` de ancho completo (§14 §2.2).
 */

import * as React from "react"
import { Check, Loader2, Minus, Plus, Search } from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Skeleton } from "@/components/ui/skeleton"
import { Separator } from "@/components/ui/separator"
import { cn } from "@/lib/utils"
import { formatAmount, formatMoney } from "@/lib/format-money"
import { LIST_FILTER_THRESHOLD, matchesText } from "@/lib/catalog/search"
import { useCatalogStore } from "@/lib/catalog/store"
import { useAddonPickerStore } from "@/lib/cart/addon-picker-store"
import { useCartStore, type CartLineAddon } from "@/lib/cart/store"
import { NumericPadDialog } from "@/components/pos/numeric-pad-dialog"
import { useItemAddonsPos, type PosAddonGroup, type PosAddonOption } from "@/hooks/use-item-addons-pos"

/** Cantidad elegida por optionId. 0 / ausente = no elegida. */
type QtyByOption = Record<string, number>

/** ¿El grupo topea la SUMA de cantidades en vez de la variedad? (mig 203) */
function isByQuantity(group: PosAddonGroup): boolean {
  return group.qtyMode === "quantity"
}

/**
 * Tope de repetición de UNA opción, sin mirar el grupo. `null` en la BD =
 * sin tope propio; `undefined` es un bootstrap cacheado antes de la mig 203,
 * donde la columna era NOT NULL DEFAULT 1 — se trata como ese 1, que es lo
 * que esas filas efectivamente valían.
 */
function ownMaxQty(option: PosAddonOption): number {
  if (option.maxQty === null) return Number.POSITIVE_INFINITY
  return option.maxQty ?? 1
}

/** Opciones distintas elegidas de un grupo. */
function countSelected(group: PosAddonGroup, qty: QtyByOption): number {
  return group.options.reduce((n, o) => n + ((qty[o.id] ?? 0) > 0 ? 1 : 0), 0)
}

/** Suma de cantidades elegidas de un grupo. */
function sumQty(group: PosAddonGroup, qty: QtyByOption): number {
  return group.options.reduce((n, o) => n + (qty[o.id] ?? 0), 0)
}

/**
 * El número que `minSelect`/`maxSelect` acotan en ESTE grupo — variedad o
 * unidades según `qtyMode`. Mismo criterio que el validador server-side, en
 * una sola función para que el modal no pueda divergir por un `if` olvidado.
 */
function countedForLimits(group: PosAddonGroup, qty: QtyByOption): number {
  return isByQuantity(group) ? sumQty(group, qty) : countSelected(group, qty)
}

/**
 * Tope EFECTIVO de una opción: el suyo propio y, en un grupo por cantidad, lo
 * que quede libre del tope del grupo. Sin esto, doce sabores sin tope propio
 * dejarían armar una caja de mil empanadas con un cartel que dice 100.
 */
function effectiveMaxQty(
  group: PosAddonGroup,
  option: PosAddonOption,
  qty: QtyByOption,
): number {
  const own = ownMaxQty(option)
  if (!isByQuantity(group) || group.maxSelect === null) return own
  const others = sumQty(group, qty) - (qty[option.id] ?? 0)
  return Math.min(own, Math.max(0, group.maxSelect - others))
}

/** Texto de la regla del grupo, visible junto al nombre. */
function groupRule(group: PosAddonGroup): string {
  const { minSelect, maxSelect } = group
  if (isByQuantity(group)) {
    if (minSelect > 0 && maxSelect !== null && minSelect === maxSelect) {
      return `Repartí ${minSelect} unidades`
    }
    if (minSelect > 0 && maxSelect !== null) return `Entre ${minSelect} y ${maxSelect} unidades`
    if (minSelect > 0) return `Mínimo ${minSelect} unidades`
    if (maxSelect !== null) return `Hasta ${maxSelect} unidades`
    return "Cantidad libre"
  }
  if (minSelect === 1 && maxSelect === 1) return "Elegí 1"
  if (minSelect > 0 && maxSelect !== null && minSelect === maxSelect) return `Elegí ${minSelect}`
  if (minSelect > 0 && maxSelect !== null) return `Elegí entre ${minSelect} y ${maxSelect}`
  if (minSelect > 0) return `Mínimo ${minSelect}`
  if (maxSelect !== null) return `Hasta ${maxSelect}`
  return "Opcional"
}

/** Estado inicial: las locked/default marcadas, o lo que ya tenía la línea. */
function initialQty(groups: PosAddonGroup[], existing: CartLineAddon[]): QtyByOption {
  const byOption: QtyByOption = {}
  const hadSelection = existing.length > 0
  const existingQty = new Map(existing.map((s) => [s.optionId, s.qty]))

  for (const group of groups) {
    for (const option of group.options) {
      const cap = ownMaxQty(option)
      if (option.isLocked) {
        // Fija: siempre presente, aunque la línea guardada no la trajera (un
        // grupo puede haberse vuelto obligatorio después de aparcar la venta).
        byOption[option.id] = Math.max(1, Math.min(cap, existingQty.get(option.id) ?? 1))
        continue
      }
      const saved = existingQty.get(option.id)
      if (saved !== undefined) {
        byOption[option.id] = Math.max(1, Math.min(cap, saved))
      } else if (!hadSelection && option.isDefault) {
        byOption[option.id] = 1
      }
    }
  }
  return byOption
}

/** ¿El evento salió de un campo de texto? Ahí las teclas son del campo. */
function isTextInput(target: EventTarget | null): boolean {
  return target instanceof HTMLElement && target.tagName === "INPUT"
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
  /** Texto del buscador de cada grupo (solo los que superan el umbral). */
  const [filters, setFilters] = React.useState<Record<string, string>>({})
  /** Opción cuya cantidad se está tipeando en el pad. `null` = pad cerrado. */
  const [qtyPadFor, setQtyPadFor] = React.useState<
    { group: PosAddonGroup; option: PosAddonOption } | null
  >(null)
  const [qtyPadDraft, setQtyPadDraft] = React.useState("0")
  // Índice de la opción enfocada dentro de la lista PLANA VISIBLE (todos los
  // grupos en orden) — es lo que hace que las flechas crucen de un grupo al
  // siguiente sin que el cajero tenga que tabular.
  const [focusIndex, setFocusIndex] = React.useState(0)
  const rowRefs = React.useRef<(HTMLButtonElement | null)[]>([])
  const optionsRef = React.useRef<HTMLDivElement | null>(null)
  /**
   * El foco se mueve por programa SOLO cuando lo pidió el teclado (o al abrir).
   * Sin esta compuerta, tipear en un buscador de grupo acorta la lista, el
   * efecto de foco se vuelve a disparar y le roba el cursor al campo en la
   * segunda letra.
   */
  const focusRequested = React.useRef(false)

  const open = pendingItem !== null

  /** Todas las opciones, en el orden en que se pintan. Base de la selección. */
  const flatAll = React.useMemo(
    () => groups.flatMap((group) => group.options.map((option) => ({ group, option }))),
    [groups],
  )

  /** Solo lo que el filtro de cada grupo deja ver — la lista que navega el teclado. */
  const flatVisible = React.useMemo(
    () =>
      groups.flatMap((group) => {
        const q = filters[group.id] ?? ""
        return group.options
          .filter((option) => matchesText(option.itemName, q))
          .map((option) => ({ group, option }))
      }),
    [groups, filters],
  )

  // Reset al abrir / al llegar los grupos. `initialSelections` viene del store
  // (línea que se edita) o vacío (alta).
  React.useEffect(() => {
    if (!open) return
    setQty(initialQty(groups, initialSelections))
    setFilters({})
    setQtyPadFor(null)
    setFocusIndex(0)
    focusRequested.current = true
  }, [open, groups, initialSelections])

  // Foco en la opción activa: arranca en la primera (autofocus) y sigue a las
  // flechas. `preventScroll` no: en una lista larga el cajero necesita que la
  // opción enfocada entre en pantalla.
  React.useEffect(() => {
    if (!open) return
    if (!focusRequested.current) return
    focusRequested.current = false
    rowRefs.current[focusIndex]?.focus()
  }, [open, focusIndex])

  // Si el filtro achica la lista por debajo del índice enfocado, se reencuadra
  // sin robar el foco (la compuerta de arriba sigue cerrada).
  React.useEffect(() => {
    setFocusIndex((i) => Math.min(i, Math.max(0, flatVisible.length - 1)))
  }, [flatVisible.length])

  const basePrice = pendingItem?.price ?? 0
  const addonsTotal = React.useMemo(
    () =>
      flatAll.reduce((sum, { option }) => sum + option.priceDelta * (qty[option.id] ?? 0), 0),
    [flatAll, qty],
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
      const counted = countedForLimits(group, qty)
      const unit = isByQuantity(group) ? "unidades" : ""
      if (counted < group.minSelect) {
        if (isByQuantity(group)) {
          return `Faltan ${group.minSelect - counted} ${unit} en ${group.name}`
        }
        return group.minSelect === 1
          ? `Falta elegir ${group.name}`
          : `Faltan ${group.minSelect - counted} en ${group.name}`
      }
      if (group.maxSelect !== null && counted > group.maxSelect) {
        return `${group.name} admite hasta ${group.maxSelect}${unit ? ` ${unit}` : ""} — quitá ${counted - group.maxSelect}`
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
      if (isByQuantity(group)) {
        // Por cantidad no hay tope de variedad: marcar es "arrancá en 1", y el
        // techo lo pone lo que quede libre del total del grupo.
        if (effectiveMaxQty(group, option, next) < 1) return prev
        next[option.id] = 1
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

  /**
   * Fija la cantidad de una opción a un valor absoluto — es lo que usa el campo
   * numérico y, vía `bumpQty`, también los botones + / −. Un solo lugar donde
   * se aplican los topes, así el campo no puede dejar pasar lo que el botón
   * frena.
   */
  function setOptionQty(group: PosAddonGroup, option: PosAddonOption, target: number) {
    setQty((prev) => {
      const next = { ...prev }
      const cap = effectiveMaxQty(group, option, next)

      if (target <= 0) {
        // Una opción fija no baja de 1 (context/41: siempre elegida).
        if (option.isLocked) {
          next[option.id] = 1
          return next
        }
        delete next[option.id]
        return next
      }
      if (cap < 1) return prev

      // Radio con repetición ("elegí 1 opción, hasta N unidades"): escribir una
      // cantidad en otra opción la reemplaza, igual que tocarla.
      const wasSelected = (next[option.id] ?? 0) > 0
      if (!wasSelected && !isByQuantity(group) && group.maxSelect === 1) {
        for (const o of group.options) {
          if (!o.isLocked) delete next[o.id]
        }
      }
      next[option.id] = Math.min(target, cap)
      return next
    })
  }

  /**
   * Abre el pad para tipear la cantidad de una opción. `seed` es el dígito que
   * el cajero apretó desde el teclado — así "5" "0" "Enter" es el mismo gesto
   * que tocar la cantidad y tipear 50, sin pasar por el mouse.
   */
  function openQtyPad(group: PosAddonGroup, option: PosAddonOption, seed?: string) {
    setQtyPadDraft(seed ?? String(qty[option.id] ?? 0))
    setQtyPadFor({ group, option })
  }

  function confirmQtyPad() {
    if (!qtyPadFor) return
    const parsed = Math.round(Number(qtyPadDraft))
    setOptionQty(qtyPadFor.group, qtyPadFor.option, Number.isFinite(parsed) ? parsed : 0)
    setQtyPadFor(null)
  }

  function bumpQty(group: PosAddonGroup, option: PosAddonOption, delta: number) {
    const current = qty[option.id] ?? 0
    if (current === 0 && delta > 0) {
      // Primero se marca (mismo gesto que Espacio), después se repite.
      toggleOption(group, option)
      return
    }
    if (current === 0) return
    if (current + delta < 1) return // bajar de 1 es desmarcar → Espacio
    setOptionQty(group, option, current + delta)
  }

  function handleConfirm() {
    if (!pendingItem || !canConfirm) return
    // `flatAll`, no `flatVisible`: el filtro es de la VISTA. Una opción elegida
    // y después escondida por el buscador se cobra igual.
    const selections: CartLineAddon[] = flatAll
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
    // Dentro de un campo de texto (buscador del grupo o cantidad) las teclas
    // son del campo: las flechas mueven el cursor, "-" y "+" se tipean y
    // Espacio escribe un espacio. Solo Enter sigue confirmando — es el gesto
    // de "ya está, agregalo" que el cajero espera después de tipear "50".
    if (isTextInput(e.target)) {
      if (e.key === "Enter") {
        e.preventDefault()
        handleConfirm()
      }
      return
    }

    if (flatVisible.length === 0) return
    const entry = flatVisible[focusIndex]
    // Espacio/±/Enter solo cuando el foco está en la lista de opciones. Con el
    // foco en el footer, Espacio y Enter tienen que activar el botón enfocado
    // (Cancelar es Cancelar) — interceptarlos siempre hacía que tabular hasta
    // "Cancelar" y apretar Enter confirmara la venta.
    const inOptions = optionsRef.current?.contains(e.target as Node) ?? false

    if (e.key === "ArrowDown" || e.key === "ArrowRight") {
      e.preventDefault()
      focusRequested.current = true
      setFocusIndex((i) => Math.min(flatVisible.length - 1, i + 1))
      return
    }
    if (e.key === "ArrowUp" || e.key === "ArrowLeft") {
      e.preventDefault()
      focusRequested.current = true
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
      if (entry) bumpQty(entry.group, entry.option, 1)
      return
    }
    if (e.key === "-" || e.key === "_") {
      e.preventDefault()
      if (entry) bumpQty(entry.group, entry.option, -1)
      return
    }
    // Un dígito sobre una opción abre el pad ya empezado: es el camino de
    // teclado para "50" — sin él, tipear la cantidad exigiría el mouse.
    if (/^[0-9]$/.test(e.key)) {
      e.preventDefault()
      if (entry && (isByQuantity(entry.group) || ownMaxQty(entry.option) > 1)) {
        openQtyPad(entry.group, entry.option, e.key)
      }
      return
    }
    if (e.key === "Enter") {
      e.preventDefault()
      handleConfirm()
    }
  }

  return (
    <>
    <Dialog open={open} onOpenChange={(o) => { if (!o) close() }}>
      {/* mobileFullscreen: mismo criterio que la ficha de producto — es un
          modal de CONTENIDO (lista de opciones que puede ser larga), no un
          modal chico de interacción (§14 §2.2). */}
      <DialogContent className="sm:max-w-2xl" mobileFullscreen onKeyDown={handleKeyDown}>
        <DialogHeader>
          <DialogTitle>{pendingItem?.name ?? "Add-ons"}</DialogTitle>
          <DialogDescription>
            {editingLineId
              ? pendingItem?.kind === "combo_dinamico"
                ? "Cambiá la composición de este combo"
                : "Cambiá los add-ons de esta línea"
              : pendingItem?.kind === "combo_dinamico"
                ? "Armá el combo — elegí de cada grupo"
                : "Elegí los add-ons del producto"}
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
              const byQuantity = isByQuantity(group)
              const counted = countedForLimits(group, qty)
              const atCap = group.maxSelect !== null && counted >= group.maxSelect
              const filter = filters[group.id] ?? ""
              const showFilter = group.options.length > LIST_FILTER_THRESHOLD
              const visibleOptions = group.options.filter((o) => matchesText(o.itemName, filter))

              return (
                <div key={group.id} className="flex flex-col gap-2">
                  <div className="flex items-baseline justify-between gap-3">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      {group.name}
                    </p>
                    {/* Contador SIEMPRE presente en modo cantidad y en el mismo
                        lugar: es la referencia que el cajero mira mientras
                        reparte ("37 de 100"), no puede aparecer y desaparecer
                        (§14 §10, posiciones estables). */}
                    <p className="text-sm text-muted-foreground">
                      {groupRule(group)}
                      {byQuantity ? (
                        <>
                          {" · "}
                          <span
                            className={cn("tabular-nums", atCap && "font-semibold text-foreground")}
                          >
                            {group.maxSelect !== null
                              ? `${counted} de ${group.maxSelect}`
                              : `${counted}`}
                          </span>
                          {atCap && <span className="ml-1.5">tope alcanzado</span>}
                        </>
                      ) : group.maxSelect !== null && group.maxSelect > 1 ? (
                        ` · ${counted}/${group.maxSelect}`
                      ) : (
                        ""
                      )}
                    </p>
                  </div>

                  {/* Buscador del grupo — listados largos (owner 2026-09-07).
                      No autoenfoca: en tablet levantaría el teclado del OS
                      encima de la lista que el cajero quiere ver. */}
                  {showFilter && (
                    <div className="relative">
                      <Search
                        className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                      />
                      <Input
                        value={filter}
                        onChange={(e) =>
                          setFilters((prev) => ({ ...prev, [group.id]: e.target.value }))
                        }
                        placeholder={`Buscar en ${group.name}`}
                        aria-label={`Buscar en ${group.name}`}
                        autoComplete="off"
                        className="h-11 pl-9"
                      />
                    </div>
                  )}

                  <div className="flex flex-col gap-1.5">
                    {showFilter && visibleOptions.length === 0 && (
                      <p className="px-1 py-3 text-sm text-muted-foreground">
                        Ninguna opción coincide con &quot;{filter}&quot;.
                      </p>
                    )}
                    {visibleOptions.map((option) => {
                      const index = flatVisible.findIndex((f) => f.option.id === option.id)
                      const optionQty = qty[option.id] ?? 0
                      const isSelected = optionQty > 0
                      const isRadio = !byQuantity && group.maxSelect === 1
                      const cap = effectiveMaxQty(group, option, qty)
                      // El campo existe SIEMPRE que la opción pueda pasar de 1
                      // — en modo cantidad, siempre. Que apareciera al marcar
                      // movería los controles de lugar entre estados (§14 §10).
                      const showQtyField = byQuantity || ownMaxQty(option) > 1
                      // En modo cantidad se tipea directo; en modo opciones hay
                      // que marcar primero (el gesto que los cajeros ya tienen).
                      const qtyEnabled = byQuantity || isSelected
                      const plusBlocked = optionQty >= cap
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

                          {/* Stepper + campo editable. Los + / − solos obligan a
                              50 toques para 50 empanadas (owner 2026-09-07), así
                              que la cantidad se puede escribir. */}
                          {showQtyField && (
                            <div className="flex shrink-0 items-center gap-1">
                              <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="size-10"
                                aria-label={`Menos ${option.itemName}`}
                                disabled={optionQty <= 1}
                                tabIndex={-1}
                                onClick={() => bumpQty(group, option, -1)}
                              >
                                <Minus className="size-4" />
                              </Button>
                              {/* La cantidad ES el control: tocarla abre el
                                  pad y se tipea "50" de una. No es un
                                  `<Input>` — §14 §11: en el POS la captura
                                  numérica va siempre por `<NumericPad>`. */}
                              <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                className="h-10 w-16 px-0 text-base font-semibold tabular-nums"
                                aria-label={`Cantidad de ${option.itemName}`}
                                disabled={!qtyEnabled}
                                tabIndex={-1}
                                onClick={() => openQtyPad(group, option)}
                              >
                                {optionQty}
                              </Button>
                              <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="size-10"
                                aria-label={`Más ${option.itemName}`}
                                // El motivo del bloqueo se lee arriba, en el
                                // contador del grupo ("100 de 100 · tope
                                // alcanzado") — no es un botón muerto sin
                                // explicación.
                                title={
                                  plusBlocked
                                    ? byQuantity && group.maxSelect !== null && atCap
                                      ? `Ya son ${group.maxSelect} en ${group.name}`
                                      : `Máximo ${cap} de ${option.itemName}`
                                    : undefined
                                }
                                disabled={plusBlocked}
                                tabIndex={-1}
                                onClick={() => bumpQty(group, option, 1)}
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

    {/* Pad de cantidad de UNA opción. Hermano del modal, no hijo: así su
        overlay queda por encima y las teclas del pad no pasan por el
        `onKeyDown` del selector. El tope efectivo va en el título — si el
        cajero tipea 500 en una caja de 100, `setOptionQty` lo recorta y el
        contador del grupo lo explica, pero verlo ANTES es mejor que
        descubrirlo después. */}
    {qtyPadFor && (
        <NumericPadDialog
          open
          onClose={() => setQtyPadFor(null)}
          title={(() => {
            const cap = effectiveMaxQty(qtyPadFor.group, qtyPadFor.option, qty)
            return Number.isFinite(cap)
              ? `${qtyPadFor.option.itemName} (hasta ${cap})`
              : qtyPadFor.option.itemName
          })()}
          // Unidades enteras: `CartLineAddon.qty` es entero y el server lo
          // castea a int. Sin shift — media empanada no existe.
          mode="int"
          value={qtyPadDraft}
          onValueChange={setQtyPadDraft}
        onConfirm={confirmQtyPad}
      />
    )}
    </>
  )
}
