"use client"

/**
 * Conteo de stock en la caja (context/63 F1).
 *
 * El cajero arma el alcance acá mismo —busca los artículos del sector que va
 * a contar y carga las cantidades— porque el conteo lo hace él (D10, owner
 * 2026-09-10; reemplaza a las listas fijas que armaba el dueño en Ajustes).
 *
 * Requiere el switch `stockCountFromRegister` del comercio ADEMÁS del permiso
 * `pos.stock.count` del operador: uno dice si acá se puede contar, el otro si
 * esta persona puede. El servidor exige los dos.
 *
 * La búsqueda va contra el catálogo LOCAL: el conteo es offline-nativo, así
 * que elegir qué contar tampoco puede depender de la red.
 *
 * ── Dos modos, y el que manda lo decide el SERVIDOR ────────────────────────
 *
 * CIEGO (default): no se muestra el stock teórico en ningún lado, ni la
 * diferencia. No es que se oculten — el servidor no los manda (D2). Si el
 * cajero ve lo que el sistema espera, escribe lo que el sistema espera, y el
 * conteo deja de medir nada.
 *
 * ABIERTO (F2): la persona tiene `inventory.count.open` y cuenta con el teórico
 * y la diferencia a la vista, como en el panel. La pantalla NO evalúa ese
 * permiso —ni lo conoce: no lleva prefijo `pos.` y no baja al dispositivo—;
 * pregunta por el teórico y el modo es lo que conteste el servidor. Esa
 * dirección importa: el filtrado del dato es del servidor, así que la respuesta
 * y la decisión son la misma fuente.
 *
 * ── Sin red se cuenta a ciegas ─────────────────────────────────────────────
 *
 * El conteo CIEGO sigue siendo offline-nativo y no se toca: todo lo que
 * necesita —la lista, los nombres, los SKU— ya está en el snapshot del
 * bootstrap y el resultado se encola.
 *
 * El teórico del modo abierto es ONLINE por decisión de la F2, así que sin red
 * el conteo ARRANCA CIEGO y se le dice al operador por qué, con esa palabra —
 * no con un error genérico. Un teórico viejo sería peor que ninguno: el
 * operador ajustaría lo contado contra un número que ya no es cierto y firmaría
 * una diferencia inventada.
 *
 * Y el modo se resuelve UNA vez por lista: si la red se cae con el conteo ya
 * cargado, los números que se mostraron SE QUEDAN. Borrarlos a mitad de camino
 * dejaría al cajero contando bajo reglas que cambiaron sin que él hiciera nada.
 *
 * ── Reglas del POS que gobiernan el layout ─────────────────────────────────
 *
 * - Posiciones estables (§10 de context/14): el pad, la línea de progreso y el
 *   botón de confirmar existen SIEMPRE, en las mismas coordenadas, aunque no
 *   haya artículo seleccionado. Nada aparece o desaparece empujando al resto.
 * - Cantidades con `<NumericPad>`, nunca con un `<Input>` (§11): es la
 *   superficie de captura numérica del POS, con teclado físico incluido.
 * - El impedimento se dice en el CONTROL que impide (botón deshabilitado +
 *   motivo), nunca en una banda. El MODO no es un impedimento sino un estado,
 *   así que va en un indicador único del encabezado que existe siempre, con el
 *   motivo en su tooltip — nada que aparezca y empuje el layout.
 */

import * as React from "react"
import { ClipboardCheck, Check, Plus, X } from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { EmptyState } from "@/components/empty-state"
import { formatQty } from "@/lib/format-qty"
import { NumericPad } from "@/components/pos/numeric-pad"
import { FullscreenToggle } from "@/components/pos/fullscreen-toggle"
import { cn } from "@/lib/utils"

import { useCatalogStore } from "@/lib/catalog/store"
import { useLockStore } from "@/lib/pos/lock-store"
import { useStockCountExpected, useSubmitStockCount } from "@/hooks/use-stock-count"

/** Fila de la lista: el ítem resuelto contra el catálogo del snapshot. */
interface CountRow {
  itemId: string
  name: string
  sku: string | null
  uom: string | null
}

export default function ConteoPage() {
  const items = useCatalogStore((s) => s.items)
  const config = useCatalogStore((s) => s.config)
  const outlet = useCatalogStore((s) => s.outlet)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const operatorPermissions = useLockStore((s) => s.operatorPermissions)
  const submit = useSubmitStockCount()

  const canGenerate = config?.stockCountFromRegister === true
  const recordOnly = config?.stockCountRecordOnly === true
  const canCount = operatorPermissions.includes("pos.stock.count")
  /**
   * PISO de conteo ciego del comercio. NO decide el modo —eso lo hace el
   * servidor, por persona— y por eso no se usa para mostrar ni esconder un
   * número. Sirve solo para redactar el motivo cuando no hay red: es lo único
   * que distingue "no hay teórico porque acá se cuenta a ciegas" de "no hay
   * teórico porque se cayó la conexión".
   *
   * `undefined` = `/api` anterior a la F2 (el BFF ya lo normaliza a `true`).
   */
  const shopBlind = config?.stockCountBlind

  /**
   * El borrador del conteo en curso, atado a la lista para la que se cargó.
   *
   * `itemIds` es la selección que armó el cajero: qué se cuenta lo decide él
   * (owner 2026-09-10). Sacar un artículo de la selección se lleva su cantidad
   * — contar algo y después decidir que no formaba parte del conteo no puede
   * dejar el número colgado.
   *
   * Vive en la pantalla y no en la cola: un conteo a medias no es una
   * operación, es un borrador. Recién al confirmar se convierte en el hecho
   * que se encola.
   */
  const [draft, setDraft] = React.useState<{
    itemIds: string[]
    values: Record<string, number>
    selectedId: string
    padValue: string
  }>({ itemIds: [], values: {}, selectedId: "", padValue: "0" })

  const counted = draft.values
  const selectedId = draft.selectedId
  const padValue = draft.padValue

  const setPadValue = (next: string) =>
    setDraft((d) => ({ ...d, padValue: next }))

  /** Agrega artículos a la selección, sin duplicar ni perder lo ya cargado. */
  const addItems = React.useCallback((ids: string[]) => {
    setDraft((d) => {
      const seen = new Set(d.itemIds)
      const added = ids.filter((id) => id !== "" && !seen.has(id))
      if (added.length === 0) return d
      return { ...d, itemIds: [...d.itemIds, ...added] }
    })
  }, [])

  /** Saca un artículo de la selección y su cantidad con él. */
  const removeItem = React.useCallback((id: string) => {
    setDraft((d) => {
      const { [id]: _dropped, ...rest } = d.values
      return {
        ...d,
        itemIds: d.itemIds.filter((x) => x !== id),
        values: rest,
        selectedId: d.selectedId === id ? "" : d.selectedId,
        padValue: d.selectedId === id ? "0" : d.padValue,
      }
    })
  }, [])

  // La selección resuelta contra el catálogo local, en el orden en que el
  // cajero la armó — reordenarla movería las filas bajo su dedo mientras
  // carga. Un id que ya no está en el catálogo se descarta: no se puede contar
  // algo que no existe, y el backend lo descartaría igual al aplicar.
  const rows: CountRow[] = React.useMemo(() => {
    const byId = new Map(items.map((i) => [i.id, i]))
    return draft.itemIds
      .map((id) => byId.get(id))
      .filter((i): i is NonNullable<typeof i> => i !== undefined)
      .map((i) => ({ itemId: i.id, name: i.name, sku: i.sku, uom: i.uom }))
  }, [draft.itemIds, items])

  const rowIds = React.useMemo(() => rows.map((r) => r.itemId), [rows])

  // ── Modo de conteo, resuelto contra el servidor (F2) ──────────────────────
  //
  // Se pide UNA vez por lista y no se refresca (ver `useStockCountExpected`).
  // Mientras no haya respuesta la pantalla se comporta como ciega: mostrar
  // números a medio resolver sería peor que no mostrarlos.
  const expectedQuery = useStockCountExpected(
    rowIds,
    canCount && canGenerate && rowIds.length > 0 && Boolean(outlet),
  )
  const mode = expectedQuery.data
  const isOpen = mode?.mode === "open"
  const expected = mode?.mode === "open" ? mode.expected : null
  /**
   * ¿Todavía se está resolviendo, o ya no se va a resolver nunca?
   *
   * Sin esta distinción el indicador se queda clavado en "Resolviendo modo"
   * cuando la query ni siquiera corre —el caso real es un device sin sucursal
   * asignada, que no puede contar y por eso no pregunta—, y una etiqueta que
   * promete un modo que no va a llegar miente peor que la etiqueta segura.
   * `fetchStatus === "fetching"` es lo único que afirma que hay una consulta en
   * vuelo; con la query deshabilitada es `"idle"`.
   */
  const resolvingMode = expectedQuery.fetchStatus === "fetching"

  /**
   * Etiqueta y motivo del indicador de modo.
   *
   * El caso interesante es el de abajo: sin red no sabemos si ESTA persona
   * habría contado abierto —el permiso lo evalúa el servidor y no baja al
   * dispositivo—, pero sí sabemos el PISO del comercio, que sí baja en el
   * bootstrap. Con el piso APAGADO todos cuentan abierto, así que la falta de
   * red le sacó algo concreto y hay que decírselo. Con el piso PRENDIDO lo más
   * probable es que contara a ciegas igual: se nombra la falta de conexión en
   * el motivo, pero sin anunciarla como una pérdida que quizá no ocurrió.
   */
  const modeBadge: { label: string; reason: string } = (() => {
    if (!mode) {
      return resolvingMode
        ? { label: "Resolviendo modo", reason: "Estamos consultando en qué modo se cuenta." }
        : {
            // Fail-closed también en la etiqueta: sin respuesta, la pantalla se
            // comporta como ciega, así que eso es lo que dice.
            label: "Conteo ciego",
            reason: "El stock teórico no se muestra mientras se cuenta.",
          }
    }
    if (mode.mode === "open") {
      return {
        label: "Con stock teórico",
        reason: "Vas a ver el stock teórico y la diferencia mientras cargás las cantidades.",
      }
    }
    if (mode.reason === "offline" && shopBlind === false) {
      return {
        label: "Conteo ciego — sin conexión",
        reason:
          "Arrancó ciego: sin conexión no se puede traer el stock teórico. Contá igual — el conteo se registra y se envía cuando vuelva la conexión.",
      }
    }
    if (mode.reason === "offline") {
      return {
        label: "Conteo ciego",
        reason:
          "En este comercio el stock teórico no se muestra mientras se cuenta. Además ahora no hay conexión para consultarlo.",
      }
    }
    return {
      label: "Conteo ciego",
      reason: "En este comercio el stock teórico no se muestra mientras se cuenta.",
    }
  })()

  const countedCount = rows.filter((r) => r.itemId in counted).length
  const selected = rows.find((r) => r.itemId === selectedId) ?? null

  function selectRow(row: CountRow) {
    const current = counted[row.itemId]
    setDraft((d) => ({
      ...d,
      selectedId: row.itemId,
      padValue: current !== undefined ? String(current) : "0",
    }))
  }

  /** Guarda la cantidad del artículo activo y salta al siguiente sin cargar. */
  function confirmQty() {
    if (!selected) return
    const qty = Number(padValue)
    if (!Number.isFinite(qty) || qty < 0) {
      toast.error("Cantidad inválida")
      return
    }
    const next = { ...counted, [selected.itemId]: qty }

    // Avanzar al siguiente SIN cargar, empezando después del actual: es el
    // recorrido natural del mostrador y evita que el cajero tenga que buscar
    // con el dedo cuál le falta.
    const startAt = rows.findIndex((r) => r.itemId === selected.itemId) + 1
    const ordered = [...rows.slice(startAt), ...rows.slice(0, startAt)]
    const pending = ordered.find((r) => !(r.itemId in next))

    setDraft((d) => ({
      ...d,
      values: next,
      selectedId: pending?.itemId ?? "",
      padValue: "0",
    }))
  }

  async function handleFinish() {
    if (rows.length === 0 || countedCount === 0 || !outlet) return
    try {
      const result = await submit.mutateAsync({
        // Sin `outletId`: la sucursal la resuelve el servidor del contexto del
        // dispositivo. Acá `outlet` solo sirve para NO dejar contar cuando el
        // device no tiene sucursal (fail-closed), no para nombrarla.
        // Sin lista fija detrás: el alcance lo armó el cajero, así que el
        // conteo se identifica por los artículos que mandó y no por el id de
        // una lista de la config. El nombre es lo que va a leer el dueño en el
        // historial del panel.
        listId: "",
        listName: "Conteo de la caja",
        itemIds: rowIds,
        rows: Object.entries(counted).map(([itemId, qty]) => ({ itemId, qty })),
        registerId: activeRegisterId || null,
        countedAt: new Date().toISOString(),
        note: null,
      })

      if (result.queued) {
        toast.success("Conteo registrado", {
          description: "Se va a enviar solo cuando vuelva la conexión.",
        })
      } else if (result.applied === false) {
        toast.success("Conteo registrado", {
          description: "Quedó guardado con sus diferencias. El stock no se modificó.",
        })
      } else {
        toast.success("Conteo finalizado", {
          description:
            result.adjustmentsCount === 0
              ? "No hubo diferencias que ajustar."
              : `Se ajustaron ${result.adjustmentsCount} artículo(s).`,
        })
      }

      // Conteo cerrado: el borrador se descarta entero. La lista elegida se
      // conserva — lo más probable es que el próximo conteo sea de la misma.
      // La selección se conserva: lo más probable es que el próximo conteo
      // sea del mismo sector del mostrador.
      setDraft((d) => ({ ...d, values: {}, selectedId: "", padValue: "0" }))
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo registrar el conteo")
    }
  }

  // ── Estados en los que no hay conteo posible ──────────────────────────────
  //
  // Se resuelven ANTES del layout: son pantallas distintas, no un layout con
  // partes apagadas. La regla de posiciones estables gobierna la pantalla de
  // trabajo, que es donde el cajero tiene memoria muscular.

  if (!canCount) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <EmptyState
          icon={ClipboardCheck}
          title="No tenés permiso para contar stock"
          description="Pedile a un encargado que te habilite el conteo desde Ajustes → Roles."
        />
      </div>
    )
  }

  if (!canGenerate) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <EmptyState
          icon={ClipboardCheck}
          title="Los conteos desde la caja están apagados"
          description="El dueño los habilita en Ajustes → Punto de venta. Mientras tanto, el inventario se cuenta desde el panel."
        />
      </div>
    )
  }

  // Fail-closed sobre la sucursal: el conteo ajusta el stock DE UNA sucursal, y
  // sin esa dimensión no hay forma de saber cuál. No se inventa ("la primera
  // activa") — se bloquea y se dice.
  const finishBlockedReason = !outlet
    ? "Este dispositivo no tiene una sucursal asignada"
    : countedCount === 0
      ? "Cargá al menos una cantidad para poder finalizar"
      : null

  return (
    <div className="flex h-full flex-col">
      <header className="flex shrink-0 items-center gap-3 border-b p-4">
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-semibold">Conteo de stock</h1>
          {/* Altura constante: la línea existe siempre, diga lo que diga. */}
          <p className="text-sm text-muted-foreground">
            {countedCount} de {rows.length} artículos cargados
            {recordOnly ? " · no modifica el stock" : ""}
          </p>
        </div>

        {/* Indicador ÚNICO del modo, arriba de la toolbar y SIEMPRE presente:
            no aparece ni desaparece, solo cambia de texto. El modo es un
            ESTADO, no un impedimento, así que no va sobre el botón de
            finalizar —ahí vive lo que bloquea— ni en una banda que empuje el
            layout. El motivo va en el tooltip para que la línea no crezca.

            Que la etiqueta cambie de ancho NO mueve ningún control (§10): el
            bloque del título es `flex-1`, así que es él —y no el `<Select>` ni
            el botón de pantalla completa— el que absorbe la diferencia. Los
            hermanos de la derecha quedan anclados a su borde. */}
        <Tooltip>
          {/* `span` envolvente por el mismo motivo que en el footer: el
              trigger necesita una ref y `<Badge>` no la reenvía. */}
          <TooltipTrigger asChild>
            <span className="shrink-0">
              <Badge variant={isOpen ? "outline" : "secondary"}>{modeBadge.label}</Badge>
            </span>
          </TooltipTrigger>
          <TooltipContent>{modeBadge.reason}</TooltipContent>
        </Tooltip>

        {/* El cajero arma el alcance acá mismo. El buscador va contra el
            catálogo que el device ya tiene, así que funciona sin red — que es
            la condición de todo el conteo en la caja. */}
        <ItemPicker items={items} selected={draft.itemIds} onAdd={addItems} />

        <FullscreenToggle />
      </header>

      <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-hidden p-4 lg:flex-row">
        <div className="min-h-0 flex-1 overflow-y-auto rounded-md border">
          {rows.length === 0 ? (
            <div className="flex h-full items-center justify-center p-6">
              <EmptyState
                icon={ClipboardCheck}
                title="Elegí qué contar"
                description="Agregá los artículos del sector que vas a contar. Podés buscarlos por nombre o por SKU."
              />
            </div>
          ) : (
            <ul>
              {rows.map((row) => {
                const qty = counted[row.itemId]
                const isSelected = row.itemId === selectedId

                // Teórico y diferencia solo existen en modo abierto. Un ítem
                // que el servidor no devolvió (quedó fuera del alcance porque
                // se dio de baja o perdió el control de stock) se marca como NO
                // DISPONIBLE en vez de mostrarse en cero: cero es un dato, y
                // acá no lo hay.
                // `formatQty` y no un `String(n)`: los separadores salen del
                // tenant (§7 de context/14) y el tope de 3 decimales corta de
                // paso el ruido binario de la resta — `2.4 - 2.1` en
                // JavaScript es `0.30000000000000004`, y un cajero que lee eso
                // en el mostrador no ve un decimal, ve un sistema roto.
                const exp = expected ? expected[row.itemId] : undefined
                const expText = !isOpen
                  ? ""
                  : exp === undefined
                    ? " · Teórico no disponible"
                    : ` · Teórico ${formatQty(exp, config)}`
                const diff = isOpen && exp !== undefined && qty !== undefined ? qty - exp : null

                return (
                  <li key={row.itemId} className="relative">
                    {/* `h-16` y no el alto default: la fila se toca con el dedo
                        en una tablet apoyada en el mostrador (§2 habilita el
                        override con razón documentada). */}
                    <button
                      type="button"
                      onClick={() => selectRow(row)}
                      className={cn(
                        "flex h-16 w-full items-center gap-3 border-b px-4 text-left transition-colors",
                        isSelected ? "bg-accent" : "hover:bg-accent/50",
                      )}
                    >
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{row.name}</p>
                        {/* El teórico va acá, en la línea secundaria, y no en
                            una columna propia: así el número CONTADO no cambia
                            de posición entre un modo y el otro. */}
                        <p className="truncate text-sm text-muted-foreground">
                          {row.sku ?? "Sin SKU"}
                          {expText}
                        </p>
                      </div>
                      {/* Lo CONTADO arriba y la diferencia abajo. Las dos líneas
                          existen siempre —la de abajo queda vacía en modo
                          ciego— para que lo contado no se mueva verticalmente
                          al cambiar de modo. */}
                      <div className="flex shrink-0 flex-col items-end justify-center">
                        <span
                          className={cn(
                            "text-lg tabular-nums",
                            qty === undefined ? "text-muted-foreground" : "font-semibold",
                          )}
                        >
                          {qty === undefined ? "—" : qty}
                          {qty !== undefined && row.uom ? ` ${row.uom}` : ""}
                        </span>
                        <span
                          className={cn(
                            "h-5 text-sm tabular-nums",
                            // Faltante en `destructive`; sobrante en el color
                            // de texto normal. Token, no un color de paleta:
                            // §5 de context/14 no admite `text-red-500`.
                            diff !== null && diff < 0
                              ? "text-destructive"
                              : "text-muted-foreground",
                          )}
                        >
                          {diff === null
                            ? ""
                            : diff > 0
                              ? `+${formatQty(diff, config)}`
                              : formatQty(diff, config)}
                        </span>
                      </div>
                    </button>
                    {/* Fuera del <button> de la fila y no adentro: un botón
                        dentro de otro no es HTML válido y los dos clicks se
                        pelean. Absoluto sobre la fila, que es `relative`. */}
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      aria-label={`Sacar ${row.name} del conteo`}
                      className="absolute right-1 top-1 size-7 text-muted-foreground"
                      onClick={() => removeItem(row.itemId)}
                    >
                      <X className="size-4" />
                    </Button>
                  </li>
                )
              })}
            </ul>
          )}
        </div>

        {/* El pad vive SIEMPRE acá, con o sin artículo elegido: es la regla de
            posiciones estables. Sin selección queda inerte y el encabezado lo
            dice, en vez de desaparecer y correr todo lo demás. */}
        <div className="flex w-full shrink-0 flex-col gap-3 lg:w-[320px]">
          <div className="rounded-md border p-3">
            <p className="truncate text-sm font-medium">
              {selected ? selected.name : "Elegí un artículo para cargar"}
            </p>
          </div>

          <div className={cn(!selected && "pointer-events-none opacity-50")}>
            <NumericPad
              mode="decimal"
              value={padValue}
              onChange={setPadValue}
              onConfirm={confirmQty}
              // ESC suelta el artículo sin tocar lo ya cargado: cancelar la
              // captura de una cantidad no puede borrar el conteo.
              onCancel={() =>
                setDraft((d) => ({ ...d, selectedId: "", padValue: "0" }))
              }
            />
          </div>
        </div>
      </div>

      <footer className="flex shrink-0 items-center justify-end gap-3 border-t p-4">
        <Tooltip>
          <TooltipTrigger asChild>
            {/* `span` envolvente: un botón realmente deshabilitado no recibe
                eventos de puntero y el tooltip —que es donde vive el motivo—
                nunca se mostraría. El impedimento se explica en el control que
                impide, no en una banda. */}
            <span>
              <Button
                size="lg"
                onClick={handleFinish}
                disabled={finishBlockedReason !== null || submit.isPending}
              >
                <Check className="mr-2 size-4" />
                {recordOnly ? "Registrar conteo" : "Finalizar y ajustar"}
              </Button>
            </span>
          </TooltipTrigger>
          {finishBlockedReason && <TooltipContent>{finishBlockedReason}</TooltipContent>}
        </Tooltip>
      </footer>
    </div>
  )
}

/**
 * Buscador para armar el alcance del conteo (owner 2026-09-10).
 *
 * Va contra `items` del catálogo LOCAL y no contra la API: el conteo en la
 * caja es offline-nativo, así que elegir qué contar tampoco puede depender de
 * la red. El catálogo del device ya tiene nombre y SKU de todo lo que se
 * vende, que es exactamente lo que hace falta para buscar.
 *
 * Multi-selección sin cerrar: agregar veinte artículos de un sector no puede
 * costar veinte aperturas del popover. Un artículo ya elegido se muestra
 * tildado y su fila lo saca — así el mismo control agrega y quita.
 */
function ItemPicker({
  items,
  selected,
  onAdd,
}: {
  items: Array<{ id: string; name: string; sku?: string | null }>
  selected: string[]
  onAdd: (ids: string[]) => void
}) {
  const [open, setOpen] = React.useState(false)
  const chosen = React.useMemo(() => new Set(selected), [selected])

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="outline" className="shrink-0">
          <Plus className="mr-2 size-4" />
          Agregar artículos
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[min(28rem,90vw)] p-0" align="end">
        <Command>
          <CommandInput placeholder="Buscar por nombre o SKU…" />
          <CommandList>
            <CommandEmpty>No se encontró ningún artículo.</CommandEmpty>
            <CommandGroup>
              {items.map((item) => {
                const isChosen = chosen.has(item.id)
                return (
                  <CommandItem
                    key={item.id}
                    // `value` alimenta el filtro del Command: sin el SKU acá,
                    // buscar por código no encuentra nada.
                    value={`${item.name} ${item.sku ?? ""}`}
                    onSelect={() => onAdd([item.id])}
                  >
                    <Check
                      className={cn(
                        "mr-2 size-4",
                        isChosen ? "opacity-100" : "opacity-0",
                      )}
                    />
                    <span className="truncate">{item.name}</span>
                    {item.sku ? (
                      <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                        {item.sku}
                      </span>
                    ) : null}
                  </CommandItem>
                )
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
