"use client"

/**
 * Conteo de stock en la caja (context/63 F1).
 *
 * El cajero elige una de las listas que armó el dueño y la completa. No busca
 * productos sueltos: el alcance es la lista (D3), y eso es lo que hace que el
 * conteo sea repetible turno a turno y comparable entre turnos.
 *
 * ── Es CIEGO, y eso ordena toda la pantalla ────────────────────────────────
 *
 * No se muestra el stock teórico en ningún lado, ni la diferencia, ni un total
 * valorizado. No es que se oculten: el servidor no los manda (D2). Si el
 * cajero ve lo que el sistema espera, escribe lo que el sistema espera, y el
 * conteo deja de medir nada.
 *
 * Consecuencia práctica: la pantalla funciona SIN RED. Todo lo que necesita
 * —la lista, los nombres, los SKU— ya está en el snapshot del bootstrap, y el
 * resultado se encola. El esperado lo resuelve el servidor al aplicar.
 *
 * ── Reglas del POS que gobiernan el layout ─────────────────────────────────
 *
 * - Posiciones estables (§10 de context/14): el pad, la línea de progreso y el
 *   botón de confirmar existen SIEMPRE, en las mismas coordenadas, aunque no
 *   haya artículo seleccionado. Nada aparece o desaparece empujando al resto.
 * - Cantidades con `<NumericPad>`, nunca con un `<Input>` (§11): es la
 *   superficie de captura numérica del POS, con teclado físico incluido.
 * - El impedimento se dice en el CONTROL que impide (botón deshabilitado +
 *   motivo), nunca en una banda.
 */

import * as React from "react"
import { ClipboardCheck, Check } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { EmptyState } from "@/components/empty-state"
import { NumericPad } from "@/components/pos/numeric-pad"
import { FullscreenToggle } from "@/components/pos/fullscreen-toggle"
import { cn } from "@/lib/utils"

import { useCatalogStore } from "@/lib/catalog/store"
import { useLockStore } from "@/lib/pos/lock-store"
import { useSubmitStockCount } from "@/hooks/use-stock-count"

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

  const lists = React.useMemo(() => config?.stockCountLists ?? [], [config])
  const recordOnly = config?.stockCountRecordOnly === true
  const canCount = operatorPermissions.includes("pos.stock.count")

  /**
   * El borrador del conteo en curso, atado a la lista para la que se cargó.
   *
   * Todo junto y con el `listId` adentro por una razón: cambiar de lista tiene
   * que descartar lo cargado (son conteos distintos, arrastrar cantidades de
   * una lista a otra mezclaría dos hechos), y eso se DERIVA comparando ids en
   * el render en vez de sincronizarse con un efecto. Un efecto que llama
   * `setState` para limpiar deja un frame pintado con los datos viejos ya bajo
   * la lista nueva.
   *
   * Vive en la pantalla y no en la cola: un conteo a medias no es una
   * operación, es un borrador. Recién al confirmar se convierte en el hecho
   * que se encola.
   */
  const [draft, setDraft] = React.useState<{
    listId: string
    values: Record<string, number>
    selectedId: string
    padValue: string
  }>({ listId: "", values: {}, selectedId: "", padValue: "0" })

  // Primera lista por default: con una sola, el selector no debería obligar a
  // un toque que no decide nada. Derivado, no un efecto de arranque.
  const listId = draft.listId !== "" ? draft.listId : (lists[0]?.id ?? "")
  const activeList = lists.find((l) => l.id === listId) ?? null

  // El borrador solo vale para SU lista. Con cualquier otra, la pantalla
  // arranca limpia sin haber tenido que borrar nada.
  const forThisList = draft.listId === listId
  const counted = forThisList ? draft.values : {}
  const selectedId = forThisList ? draft.selectedId : ""
  const padValue = forThisList ? draft.padValue : "0"

  const setListId = (next: string) =>
    setDraft({ listId: next, values: {}, selectedId: "", padValue: "0" })
  const setPadValue = (next: string) =>
    setDraft((d) => ({ ...d, listId, padValue: next }))

  // Los ítems de la lista, resueltos contra el catálogo local. Un id que ya no
  // está en el catálogo (artículo dado de baja después de que el dueño armó la
  // lista) se descarta acá: el cajero no puede contar algo que no existe, y el
  // backend lo descartaría igual al aplicar.
  const rows: CountRow[] = React.useMemo(() => {
    if (!activeList) return []
    const byId = new Map(items.map((i) => [i.id, i]))
    return activeList.itemIds
      .map((id) => byId.get(id))
      .filter((i): i is NonNullable<typeof i> => i !== undefined)
      .map((i) => ({ itemId: i.id, name: i.name, sku: i.sku, uom: i.uom }))
  }, [activeList, items])

  const countedCount = rows.filter((r) => r.itemId in counted).length
  const selected = rows.find((r) => r.itemId === selectedId) ?? null

  function selectRow(row: CountRow) {
    const current = counted[row.itemId]
    setDraft({
      listId,
      values: counted,
      selectedId: row.itemId,
      padValue: current !== undefined ? String(current) : "0",
    })
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

    setDraft({
      listId,
      values: next,
      selectedId: pending?.itemId ?? "",
      padValue: "0",
    })
  }

  async function handleFinish() {
    if (!activeList || countedCount === 0 || !outlet) return
    try {
      const result = await submit.mutateAsync({
        // Sin `outletId`: la sucursal la resuelve el servidor del contexto del
        // dispositivo. Acá `outlet` solo sirve para NO dejar contar cuando el
        // device no tiene sucursal (fail-closed), no para nombrarla.
        listId: activeList.id,
        listName: activeList.name,
        itemIds: rows.map((r) => r.itemId),
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
      setDraft({ listId, values: {}, selectedId: "", padValue: "0" })
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

  if (lists.length === 0) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <EmptyState
          icon={ClipboardCheck}
          title="Todavía no hay listas de conteo"
          description="El dueño arma en Ajustes qué artículos se cuentan en el mostrador. Sin una lista, no hay nada que contar acá."
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

        {lists.length > 1 && (
          <Select value={listId} onValueChange={setListId}>
            <SelectTrigger className="w-[220px]">
              <SelectValue placeholder="Elegí una lista" />
            </SelectTrigger>
            <SelectContent>
              {lists.map((l) => (
                <SelectItem key={l.id} value={l.id}>
                  {l.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}

        <FullscreenToggle />
      </header>

      <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-hidden p-4 lg:flex-row">
        <div className="min-h-0 flex-1 overflow-y-auto rounded-md border">
          {rows.length === 0 ? (
            <div className="flex h-full items-center justify-center p-6">
              <EmptyState
                icon={ClipboardCheck}
                title="La lista quedó sin artículos"
                description="Los artículos de esta lista ya no están activos o no tienen control de stock. Revisala en Ajustes."
              />
            </div>
          ) : (
            <ul>
              {rows.map((row) => {
                const qty = counted[row.itemId]
                const isSelected = row.itemId === selectedId
                return (
                  <li key={row.itemId}>
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
                        <p className="truncate text-sm text-muted-foreground">
                          {row.sku ?? "Sin SKU"}
                        </p>
                      </div>
                      {/* Solo lo CONTADO. Nunca el esperado: el conteo es ciego. */}
                      <span
                        className={cn(
                          "shrink-0 text-lg tabular-nums",
                          qty === undefined ? "text-muted-foreground" : "font-semibold",
                        )}
                      >
                        {qty === undefined ? "—" : qty}
                        {qty !== undefined && row.uom ? ` ${row.uom}` : ""}
                      </span>
                    </button>
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
              {selected ? selected.name : "Elegí un artículo de la lista"}
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
                setDraft({ listId, values: counted, selectedId: "", padValue: "0" })
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
