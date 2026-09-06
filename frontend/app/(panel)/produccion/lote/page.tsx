"use client"

/**
 * Lote de producción multi-plato (context/70-viandas.md, etapa B).
 *
 * Responde la pregunta del negocio en una sola pantalla: cargás
 * {plato, cantidad} × N y ves, EN VIVO mientras editás, cuánto de cada insumo
 * hace falta EN TOTAL, cuánto hay en el depósito y cuánto falta.
 *
 * Dos decisiones de UI que vale explicar:
 *
 *  - La necesidad se recalcula con un debounce sobre las líneas, no con un
 *    botón "Calcular". El valor de la pantalla es ver el faltante moverse
 *    mientras se decide cuántas viandas cocinar; un botón obliga a un viaje
 *    mental de ida y vuelta por cada cambio de cantidad. El cálculo es lectura
 *    pura en el backend, así que llamarlo seguido no tiene efectos.
 *  - "Falta" muestra un guion, y NO un cero, para un insumo sin control de
 *    inventario (D1 de context/70): sin `onHand` no hay faltante, hay
 *    necesidad total. Un 0 ahí diría "no te falta nada" sobre algo que el
 *    sistema no sabe.
 *
 * ── Traer de órdenes pendientes ─────────────────────────────────────────────
 *
 * El botón "Traer de órdenes pendientes" es el atajo del negocio: en vez de
 * tipear los platos del turno, se precargan desde la cola del KDS ya sumados
 * ("no 100 g de pollo en una comanda y 150 en otra: 250 g para toda la cola").
 *
 * Es una FOTO, no un vivo (D2 de context/70). Lo que llega es la cola del
 * INSTANTE en que se apretó el botón, con su hora a la vista; las líneas
 * quedan editables y un pedido que entre después NO muta el lote ya armado —
 * el operador vuelve a traer si quiere. Por eso no hay ninguna suscripción
 * realtime colgada de esto: un lote que se recalcula solo mientras el cocinero
 * decide cuánto cocinar es peor que uno viejo, porque cambia sin que nadie lo
 * pida y el número que se estaba mirando deja de ser el que se va a producir.
 *
 * Si ya había líneas cargadas se PREGUNTA antes de tocarlas (reemplazar o
 * sumar): pisar en silencio el trabajo manual del operador sería la peor de
 * las dos opciones elegida por él sin enterarse.
 */

import * as React from "react"
import Link from "next/link"
import {
  Check,
  ChevronsUpDown,
  ClipboardList,
  ListPlus,
  Loader2,
  Plus,
  Printer,
  Trash2,
} from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"
import { useOutlets } from "@/hooks/use-outlets"
import { useOutletLocations } from "@/hooks/use-outlet-locations"
import { useItems } from "@/hooks/use-items"
import {
  useConfirmProductionBatch,
  useCreateProductionBatch,
  useOrderDemand,
  useProductionBatchEstimate,
} from "@/hooks/use-production-batches"
import { formatQty } from "@/lib/format-qty"
import { formatTime } from "@/lib/format-date"
import { cn } from "@/lib/utils"
import { printProductionBatchSheet } from "@/lib/hardware/printers/print-production-batch"
import type { ItemKind } from "@/lib/types/item"
import type {
  OrderDemand,
  OrderDemandSource,
  ProductionNeedIngredient,
  ProductionBatchLineInput,
} from "@/lib/types/production-batch"

/** `<Select>` de shadcn no acepta `value=""` — sentinel explícito (context/20 §4). */
const NO_LOCATION = "__none__"

/**
 * Solo los ítems con receta se pueden producir. Se filtra en el cliente sobre
 * el resultado de `useItems` porque el endpoint no expone un filtro por kind —
 * mismo criterio que `new-production-dialog.tsx`.
 */
const PRODUCIBLE_KINDS: ItemKind[] = ["produccion_previa", "produccion_directa"]

interface DraftLine {
  /** Clave estable de React: los itemId pueden repetirse mientras se elige. */
  key: string
  itemId: string | null
  itemName: string | null
  /** String, no number: es lo que hay en el input mientras se tipea. */
  qty: string
  /**
   * De qué órdenes salió esta línea, si vino de "Traer de órdenes pendientes".
   * Se BORRA en cuanto el operador toca el producto o la cantidad: a partir de
   * ahí el desglose ya no describe la línea, y mostrarlo mentiría sobre de
   * dónde salió el número. No se persiste en el lote — es información del
   * momento de armado (D3 de context/70).
   */
  sources?: OrderDemandSource[]
}

function newLine(): DraftLine {
  return { key: Math.random().toString(36).slice(2), itemId: null, itemName: null, qty: "" }
}

function newKey(): string {
  return Math.random().toString(36).slice(2)
}

/** Coma o punto: el operador escribe "2,5" y "2.5" indistintamente. */
function parseQty(raw: string): number {
  const n = Number(raw.replace(",", "."))
  return Number.isFinite(n) && n > 0 ? n : 0
}

/** Una línea vacía del formulario: ni producto ni cantidad. */
function isBlank(line: DraftLine): boolean {
  return !line.itemId && parseQty(line.qty) <= 0
}

export default function ProductionBatchPage() {
  const { data: bootstrap } = useBootstrap()
  const canManage = usePermission("production.manage")

  const { data: outletsData } = useOutlets()
  const outlets = React.useMemo(() => outletsData?.rows ?? [], [outletsData])

  const [outletId, setOutletId] = React.useState<string | null>(null)
  const [locationId, setLocationId] = React.useState<string>(NO_LOCATION)
  const [outputLocationId, setOutputLocationId] = React.useState<string>(NO_LOCATION)
  const [note, setNote] = React.useState("")
  const [lines, setLines] = React.useState<DraftLine[]>([newLine()])
  const [confirmOpen, setConfirmOpen] = React.useState(false)

  // La FOTO de la cola que se trajo (D2): metadatos para mostrarla y para
  // avisar de lo que quedó afuera. `null` = todavía no se trajo nada.
  const [snapshot, setSnapshot] = React.useState<OrderDemand | null>(null)
  const [mergeOpen, setMergeOpen] = React.useState(false)
  const [pendingDemand, setPendingDemand] = React.useState<OrderDemand | null>(null)

  // Sucursal por defecto: la activa del bootstrap. Nunca "la primera de la
  // lista" — la dimensión del POS/panel sale del contexto, no se inventa.
  React.useEffect(() => {
    if (outletId === null && bootstrap?.activeOutletId) {
      setOutletId(bootstrap.activeOutletId)
    }
  }, [bootstrap?.activeOutletId, outletId])

  const { data: locations } = useOutletLocations(outletId)

  // Cambiar de sucursal invalida los depósitos elegidos: son de la anterior.
  // Y también la foto de la cola — las órdenes se filtran por sucursal (D4),
  // así que un "traído a las 11:20" de otra sucursal sería una etiqueta falsa
  // sobre las líneas que quedaron en pantalla.
  React.useEffect(() => {
    setLocationId(NO_LOCATION)
    setOutputLocationId(NO_LOCATION)
    setSnapshot(null)
  }, [outletId])

  const validLines: ProductionBatchLineInput[] = React.useMemo(
    () =>
      lines
        .filter((l) => l.itemId && parseQty(l.qty) > 0)
        .map((l) => ({ itemId: l.itemId as string, qty: parseQty(l.qty) })),
    [lines],
  )

  // Debounce: la necesidad se recalcula mientras se tipea, pero no en cada
  // pulsación. 350 ms es lo que tarda en sentirse "en vivo" sin disparar un
  // request por dígito de una cantidad de tres cifras.
  const [debouncedLines, setDebouncedLines] = React.useState<ProductionBatchLineInput[]>([])
  React.useEffect(() => {
    const t = setTimeout(() => setDebouncedLines(validLines), 350)
    return () => clearTimeout(t)
  }, [validLines])

  const estimatePayload = React.useMemo(
    () =>
      outletId && debouncedLines.length > 0
        ? {
            outletId,
            locationId: locationId === NO_LOCATION ? null : locationId,
            lines: debouncedLines,
          }
        : null,
    [outletId, locationId, debouncedLines],
  )

  const {
    data: estimate,
    isFetching: estimating,
    error: estimateError,
  } = useProductionBatchEstimate(estimatePayload)

  const createBatch = useCreateProductionBatch()
  const confirmBatch = useConfirmProductionBatch()
  const working = createBatch.isPending || confirmBatch.isPending

  // ── Selector de producto por línea ────────────────────────────────────────
  const [itemQuery, setItemQuery] = React.useState("")
  const { data: itemsData } = useItems({ q: itemQuery })
  const producibleItems = React.useMemo(
    () => (itemsData?.items ?? []).filter((i) => PRODUCIBLE_KINDS.includes(i.kind)),
    [itemsData],
  )

  /**
   * Editar una línea la desliga de su origen: el desglose por pedido describía
   * la cantidad que TRAJO la cola, no la que el operador dejó después.
   */
  function updateLine(key: string, patch: Partial<DraftLine>) {
    setLines((prev) =>
      prev.map((l) => (l.key === key ? { ...l, ...patch, sources: undefined } : l)),
    )
  }

  // ── Traer de órdenes pendientes ───────────────────────────────────────────
  const orderDemand = useOrderDemand()

  async function handleBringFromOrders() {
    if (!outletId) return
    try {
      const demand = await orderDemand.mutateAsync(outletId)
      if (demand.lines.length === 0) {
        setSnapshot(demand)
        toast.info("No hay nada pendiente de cocinar en esta sucursal")
        return
      }
      // Si el operador ya venía cargando a mano, la decisión es suya.
      if (lines.some((l) => !isBlank(l))) {
        setPendingDemand(demand)
        setMergeOpen(true)
        return
      }
      applyDemand(demand, "replace")
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "No se pudo traer la cola de órdenes")
    }
  }

  function applyDemand(demand: OrderDemand, mode: "replace" | "append") {
    setSnapshot(demand)
    setLines((prev) => {
      const brought: DraftLine[] = demand.lines.map((l) => ({
        key: newKey(),
        itemId: l.itemId,
        itemName: l.itemName,
        qty: String(l.qty),
        sources: l.sources,
      }))

      if (mode === "replace") {
        return brought
      }

      // Sumar: si el producto ya estaba cargado, se acumula sobre esa línea en
      // vez de dejar dos filas del mismo plato — el lote agrega por producto,
      // y dos líneas de milanesa serían el mismo número contado en dos lugares
      // para el operador que lo mira.
      const merged = prev.filter((l) => !isBlank(l)).map((l) => ({ ...l }))
      for (const line of brought) {
        const existing = merged.find((l) => l.itemId === line.itemId)
        if (existing) {
          existing.qty = String(parseQty(existing.qty) + parseQty(line.qty))
          existing.sources = [...(existing.sources ?? []), ...(line.sources ?? [])]
        } else {
          merged.push(line)
        }
      }
      return merged
    })
    toast.success(
      demand.lines.length === 1
        ? "1 producto traído de la cola"
        : `${demand.lines.length} productos traídos de la cola`,
    )
  }

  function removeLine(key: string) {
    setLines((prev) => (prev.length === 1 ? [newLine()] : prev.filter((l) => l.key !== key)))
  }

  const blockingLine = estimate?.lines.find((l) => !l.producible)
  const canSubmit = !!outletId && validLines.length > 0 && !working && !blockingLine

  async function handleConfirm() {
    if (!outletId) return
    try {
      // Crear y confirmar son dos llamadas a propósito: `create()` deja el lote
      // en borrador con sus órdenes hijas (el papel que va a la cocina) y
      // `confirm()` es lo que mueve el stock. Que el botón haga las dos es una
      // decisión de ESTA pantalla —"producir ahora"—, no del backend, que
      // conserva los dos pasos separados para quien quiera cocinar primero y
      // registrar después.
      const batch = await createBatch.mutateAsync({
        outletId,
        locationId: locationId === NO_LOCATION ? null : locationId,
        outputLocationId: outputLocationId === NO_LOCATION ? null : outputLocationId,
        note: note.trim() || null,
        lines: validLines,
      })
      await confirmBatch.mutateAsync(batch.id)
      toast.success("Lote producido y stock actualizado")
      setLines([newLine()])
      setNote("")
      setSnapshot(null)
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "No se pudo producir el lote")
    } finally {
      setConfirmOpen(false)
    }
  }

  function handlePrint() {
    const outletName = outlets.find((o) => o.id === outletId)?.name ?? null
    const locName = locations?.find((l) => l.id === locationId)?.name ?? null
    const outName = locations?.find((l) => l.id === outputLocationId)?.name ?? null

    printProductionBatchSheet({
      title: "Orden de producción",
      docNumber: null,
      outletName,
      locationName: locName,
      outputLocationName: outName,
      printedAt: new Date().toLocaleString(),
      note: note.trim() || null,
      lines: (estimate?.lines ?? []).map((l) => ({
        itemName: l.itemName ?? l.itemId,
        qty: formatQty(l.qty, bootstrap ?? null),
      })),
      ingredients: (estimate?.ingredients ?? []).map((i) => ({
        itemName: i.itemName ?? i.itemId,
        needed: formatQty(i.needed, bootstrap ?? null),
        // Sin control de inventario no hay saldo que imprimir: el papel dice
        // "sin control", no un cero que el cocinero leería como "no hay".
        onHand: i.tracked ? formatQty(i.onHand ?? 0, bootstrap ?? null) : "sin control",
        missing: i.tracked ? formatQty(i.missing ?? 0, bootstrap ?? null) : "—",
      })),
    })
  }

  const ingredientColumns = React.useMemo<ColumnDef<ProductionNeedIngredient, unknown>[]>(
    () => [
      {
        accessorKey: "itemName",
        header: "Insumo",
        meta: { label: "Insumo" },
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span>{row.original.itemName ?? "—"}</span>
            {!row.original.tracked && (
              <span className="text-xs text-muted-foreground">Sin control de inventario</span>
            )}
          </div>
        ),
      },
      {
        accessorKey: "needed",
        header: "Necesita",
        meta: { label: "Necesita" },
        cell: ({ row }) => (
          <span className="tabular-nums">{formatQty(row.original.needed, bootstrap ?? null)}</span>
        ),
      },
      {
        accessorKey: "onHand",
        header: "Hay",
        meta: { label: "Hay" },
        cell: ({ row }) =>
          row.original.tracked ? (
            <span className="tabular-nums">{formatQty(row.original.onHand ?? 0, bootstrap ?? null)}</span>
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        accessorKey: "missing",
        header: "Falta",
        meta: { label: "Falta" },
        cell: ({ row }) => {
          // D1: sin onHand no hay faltante. Un 0 acá sería una afirmación que
          // el sistema no puede hacer.
          if (!row.original.tracked) {
            return <span className="text-muted-foreground">—</span>
          }
          const missing = row.original.missing ?? 0
          if (missing <= 0) {
            return <Badge variant="secondary">Alcanza</Badge>
          }
          return (
            <Badge variant="destructive" className="tabular-nums">
              {formatQty(missing, bootstrap ?? null)}
            </Badge>
          )
        },
      },
    ],
    [bootstrap],
  )

  const shortages = (estimate?.ingredients ?? []).filter(
    (i) => i.tracked && (i.missing ?? 0) > 0,
  ).length

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Lote de producción</h1>
          <p className="text-sm text-muted-foreground">
            Cargá los platos del turno y mirá cuánto de cada insumo hace falta en total.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" asChild>
            <Link href="/produccion">Ver órdenes</Link>
          </Button>
          <Button
            variant="outline"
            onClick={handlePrint}
            disabled={!estimate || validLines.length === 0}
          >
            <Printer className="size-4" />
            Imprimir orden
          </Button>
          {canManage && (
            <Button onClick={() => setConfirmOpen(true)} disabled={!canSubmit}>
              {working ? <Loader2 className="size-4 animate-spin" /> : <ClipboardList className="size-4" />}
              Producir lote
            </Button>
          )}
        </div>
      </header>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <Card>
          <CardHeader>
            <CardTitle>Qué se produce</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-2">
                <Label htmlFor="lote-outlet">Sucursal</Label>
                <Select value={outletId ?? ""} onValueChange={setOutletId}>
                  <SelectTrigger id="lote-outlet">
                    <SelectValue placeholder="Elegí una sucursal" />
                  </SelectTrigger>
                  <SelectContent>
                    {outlets.map((o) => (
                      <SelectItem key={o.id} value={o.id}>
                        {o.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="flex flex-col gap-2">
                <Label htmlFor="lote-location">Insumos desde</Label>
                <Select value={locationId} onValueChange={setLocationId} disabled={!outletId}>
                  <SelectTrigger id="lote-location">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NO_LOCATION}>Toda la sucursal</SelectItem>
                    {(locations ?? []).map((l) => (
                      <SelectItem key={l.id} value={l.id}>
                        {l.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="flex flex-col gap-2 sm:col-span-2">
                <Label htmlFor="lote-output">Terminado a</Label>
                <Select
                  value={outputLocationId}
                  onValueChange={setOutputLocationId}
                  disabled={!outletId}
                >
                  <SelectTrigger id="lote-output">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NO_LOCATION}>Depósito por defecto</SelectItem>
                    {(locations ?? []).map((l) => (
                      <SelectItem key={l.id} value={l.id}>
                        {l.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="flex flex-col gap-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <Label>Platos del lote</Label>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleBringFromOrders}
                  disabled={!outletId || orderDemand.isPending}
                >
                  {orderDemand.isPending ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <ListPlus className="size-4" />
                  )}
                  Traer de órdenes pendientes
                </Button>
              </div>

              {/* La FOTO, declarada (D2): qué se trajo, de cuándo, y qué quedó
                  afuera. Sin la hora, el operador no tiene cómo saber si la
                  cola que está mirando es de hace un minuto o de hace una
                  hora. */}
              {snapshot && (
                <p className="text-xs text-muted-foreground">
                  Cola al momento de traer: {formatTime(snapshot.takenAt)} ·{" "}
                  {snapshot.orderCount === 1
                    ? "1 pedido pendiente"
                    : `${snapshot.orderCount} pedidos pendientes`}
                  . Los pedidos que entren después no se suman solos.
                  {snapshot.skippedFreeText > 0 && (
                    <>
                      {" "}
                      {snapshot.skippedFreeText === 1
                        ? "1 línea sin producto de catálogo"
                        : `${snapshot.skippedFreeText} líneas sin producto de catálogo`}{" "}
                      quedaron afuera: no tienen receta que explotar.
                    </>
                  )}
                  {snapshot.truncated && " La cola es muy larga y vino incompleta."}
                </p>
              )}

              {lines.map((line) => (
                <LineRow
                  key={line.key}
                  line={line}
                  items={producibleItems}
                  query={itemQuery}
                  onQueryChange={setItemQuery}
                  onChange={(patch) => updateLine(line.key, patch)}
                  onRemove={() => removeLine(line.key)}
                />
              ))}
              <Button
                variant="outline"
                size="sm"
                className="self-start"
                onClick={() => setLines((prev) => [...prev, newLine()])}
              >
                <Plus className="size-4" />
                Agregar plato
              </Button>
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor="lote-note">Nota</Label>
              <Textarea
                id="lote-note"
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="Opcional: indicaciones para la cocina."
                rows={2}
              />
            </div>

            {blockingLine && (
              <p className="text-sm text-destructive">
                {blockingLine.itemName ?? "Un producto del lote"}: {blockingLine.reason}
              </p>
            )}
            {estimateError && (
              <p className="text-sm text-destructive">{estimateError.message}</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between gap-2">
            <CardTitle>Insumos necesarios</CardTitle>
            {estimating && <Loader2 className="size-4 animate-spin text-muted-foreground" />}
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
              {estimate?.batchCapacity !== null && estimate?.batchCapacity !== undefined ? (
                <span>
                  Con el stock actual el lote entra{" "}
                  <span className="font-medium text-foreground tabular-nums">
                    {formatQty(estimate.batchCapacity, bootstrap ?? null)}
                  </span>{" "}
                  {estimate.batchCapacity === 1 ? "vez" : "veces"}.
                </span>
              ) : (
                <span>Ningún insumo con control de inventario limita este lote.</span>
              )}
              {shortages > 0 && (
                <Badge variant="destructive">
                  {shortages} {shortages === 1 ? "insumo falta" : "insumos faltan"}
                </Badge>
              )}
            </div>

            <DataTable
              tableId="production-batch-needs"
              data={estimate?.ingredients ?? []}
              columns={ingredientColumns}
              getRowId={(row) => row.itemId}
              isLoading={estimating && !estimate}
              searchPlaceholder="Buscar insumo…"
              exportFileName="insumos-del-lote"
              emptyMessage={
                <EmptyState
                  icon={ClipboardList}
                  title="Todavía no hay nada que calcular"
                  description="Agregá al menos un plato con su cantidad y la necesidad aparece acá."
                  showMarquee={false}
                  className="border-0 py-6"
                />
              }
            />
          </CardContent>
        </Card>
      </div>

      {/* Ya había líneas cargadas: la decisión es del operador, no del código.
          Reemplazar y sumar son las dos respuestas legítimas —"armé mal, traé
          la cola" y "esto va además de lo que cargué"— y elegir una por él en
          silencio le borraría trabajo o le duplicaría cantidades. */}
      <AlertDialog
        open={mergeOpen}
        onOpenChange={(open) => {
          setMergeOpen(open)
          // Cancelar (o cerrar con Escape) descarta la foto: dejarla en state
          // la haría reaparecer con datos viejos si el diálogo se reabriera.
          if (!open) setPendingDemand(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Ya hay platos cargados</AlertDialogTitle>
            <AlertDialogDescription>
              La cola tiene{" "}
              {pendingDemand?.lines.length === 1
                ? "1 producto pendiente"
                : `${pendingDemand?.lines.length ?? 0} productos pendientes`}
              . ¿Reemplazo lo que hay en pantalla o lo sumo a lo que ya cargaste?
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            {/* Las dos son acciones del diálogo, así que las dos son
                `<AlertDialogAction>` — el cierre lo maneja Radix. "Sumar" va
                `asChild` sobre un Button `outline` solo para que no compitan
                dos botones primarios en el mismo footer. */}
            <AlertDialogAction asChild>
              <Button
                variant="outline"
                onClick={() => {
                  if (pendingDemand) applyDemand(pendingDemand, "append")
                }}
              >
                Sumar
              </Button>
            </AlertDialogAction>
            <AlertDialogAction
              onClick={() => {
                if (pendingDemand) applyDemand(pendingDemand, "replace")
              }}
            >
              Reemplazar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Producir el lote</AlertDialogTitle>
            <AlertDialogDescription>
              Se van a descontar los insumos y a acreditar los productos terminados en la
              sucursal elegida.{" "}
              {shortages > 0
                ? `Ojo: ${shortages} insumo(s) no alcanzan y el stock puede quedar en negativo.`
                : "El stock alcanza para todo el lote."}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={handleConfirm} disabled={working}>
              Producir
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

/** Una línea {plato, cantidad}. Extraída para que el picker no se re-monte. */
function LineRow({
  line,
  items,
  query,
  onQueryChange,
  onChange,
  onRemove,
}: {
  line: DraftLine
  items: { itemId: string; itemName: string; itemSKU: string | null }[]
  query: string
  onQueryChange: (v: string) => void
  onChange: (patch: Partial<DraftLine>) => void
  onRemove: () => void
}) {
  const [open, setOpen] = React.useState(false)

  return (
    <div className="flex items-center gap-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant="outline"
            role="combobox"
            className="min-w-0 flex-1 justify-between font-normal"
          >
            <span className="truncate">{line.itemName ?? "Buscar producto con receta…"}</span>
            <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
          {/* shouldFilter={false}: el filtrado lo hace el servidor vía `q`. */}
          <Command shouldFilter={false}>
            <CommandInput
              value={query}
              onValueChange={onQueryChange}
              placeholder="Buscar por nombre o SKU…"
            />
            <CommandList>
              <CommandEmpty>Sin resultados</CommandEmpty>
              <CommandGroup>
                {items.map((item) => (
                  <CommandItem
                    key={item.itemId}
                    value={item.itemId}
                    onSelect={() => {
                      onChange({ itemId: item.itemId, itemName: item.itemName })
                      setOpen(false)
                    }}
                  >
                    <Check
                      className={cn(
                        "mr-2 size-4",
                        line.itemId === item.itemId ? "opacity-100" : "opacity-0",
                      )}
                    />
                    <span className="flex-1">{item.itemName}</span>
                    {item.itemSKU && (
                      <span className="text-xs text-muted-foreground">{item.itemSKU}</span>
                    )}
                  </CommandItem>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>

      {/* Cantidad, no dinero: `<Input>` con parseo coma/punto, igual que el
          resto del panel. `<MoneyInput>` es para montos. */}
      <Input
        type="number"
        min="0"
        step="any"
        inputMode="decimal"
        className="w-24"
        value={line.qty}
        onChange={(e) => onChange({ qty: e.target.value })}
        placeholder="0"
        aria-label="Cantidad"
      />

      <LineOrigin line={line} />

      <Button variant="ghost" size="icon" onClick={onRemove} aria-label="Quitar plato">
        <Trash2 className="size-4" />
      </Button>
    </div>
  )
}

/**
 * De qué pedidos salió la cantidad de una línea traída de la cola (D3 de
 * context/70). Tooltip y no una columna: es información de AUDITORÍA —"¿de
 * dónde salieron estas 6 milanesas?"— que se consulta cuando un número
 * sorprende, no en cada mirada a la pantalla.
 *
 * Si la línea también tiene cantidad cargada a mano (el operador sumó la cola
 * sobre lo que ya había), el resto se declara en vez de repartirlo entre los
 * pedidos: atribuirle a un pedido una cantidad que no pidió sería exactamente
 * el error que este desglose existe para evitar.
 */
function LineOrigin({ line }: { line: DraftLine }) {
  // `useBootstrap` es una query cacheada: pedirla acá no dispara request ni
  // prop-drilling de `bootstrap` a través de `LineRow`. Las cantidades salen
  // por `formatQty` como en todo el resto de la pantalla — el separador
  // decimal es del tenant, no de JS.
  const { data: bootstrap } = useBootstrap()
  const sources = line.sources ?? []
  if (sources.length === 0) {
    // El hueco se reserva igual: sin esto, la fila de una línea con origen y
    // la de una sin él tendrían el input de cantidad en posiciones distintas.
    return <div className="w-16 shrink-0" aria-hidden />
  }

  const fromOrders = sources.reduce((acc, s) => acc + s.qty, 0)
  const manual = parseQty(line.qty) - fromOrders

  return (
    <div className="w-16 shrink-0">
      <Tooltip>
        <TooltipTrigger asChild>
          <Badge variant="secondary" tabIndex={0} className="cursor-default font-normal">
            {sources.length === 1 ? "1 pedido" : `${sources.length} pedidos`}
          </Badge>
        </TooltipTrigger>
        <TooltipContent align="end" className="max-w-64">
          <ul className="flex flex-col gap-0.5">
            {sources.map((s, i) => (
              <li key={`${s.orderId}-${i}`} className="tabular-nums">
                {s.orderNumber !== null ? `#${s.orderNumber}` : s.orderId.slice(0, 8)} ×{" "}
                {formatQty(s.qty, bootstrap ?? null)}
              </li>
            ))}
            {manual > 0.0001 && (
              <li className="tabular-nums">
                Cargado a mano × {formatQty(manual, bootstrap ?? null)}
              </li>
            )}
          </ul>
        </TooltipContent>
      </Tooltip>
    </div>
  )
}
