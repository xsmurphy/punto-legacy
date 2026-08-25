"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import {
  ArrowLeft,
  CheckCircle2,
  XCircle,
  Loader2,
  Search,
  Boxes,
} from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Skeleton } from "@/components/ui/skeleton"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"

import { useCategories } from "@/hooks/use-categories"
import {
  useInventoryCount,
  useSetCountedQty,
  useFinishInventoryCount,
  useCancelInventoryCount,
  type InventoryCountItem,
} from "@/hooks/use-inventory-counts"
import { formatMoney as _formatMoney } from "@/lib/format"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { resolveDateLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"

function formatMoney(v: number): string {
  return _formatMoney(v, undefined)
}

/** Centinelas del <Select>: Radix no acepta value="" en un SelectItem. */
const ALL_CATEGORIES = "__all__"
const NO_CATEGORY    = "__none__"

const STATUS_LABEL: Record<number, string> = {
  0: "Cancelado",
  1: "En progreso",
  2: "Finalizado",
}

const STATUS_VARIANT: Record<number, "default" | "secondary" | "destructive" | "outline"> = {
  0: "destructive",
  1: "default",
  2: "secondary",
}

function formatDate(
  iso: string | null | undefined,
  config: TenantLocaleConfig | null | undefined,
): string {
  if (!iso) return "—"
  return new Date(iso).toLocaleString(resolveDateLocale(config), {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
}

function CountedQtyCell({
  item,
  countId,
  editable,
}: {
  item: InventoryCountItem
  countId: string
  editable: boolean
}) {
  const setQty = useSetCountedQty()
  const toText = (q: number | null) => (q !== null ? String(q) : "")

  const [value, setValue] = React.useState<string>(() => toText(item.countedQty))

  // El input está SIEMPRE montado, así que el valor del server puede cambiar
  // por debajo (nuestra propia mutación, o el realtime si cuenta otra persona).
  // Se sincroniza solo cuando ese valor cambia de verdad — comparar contra el
  // texto tipeado pisaría lo que el cajero está escribiendo en ese momento.
  const lastSynced = React.useRef(item.countedQty)
  React.useEffect(() => {
    if (item.countedQty !== lastSynced.current) {
      lastSynced.current = item.countedQty
      setValue(toText(item.countedQty))
    }
  }, [item.countedQty])

  // Escape descarta: `setValue` no se ve reflejado en el `value` que cierra
  // sobre `commit` en este mismo tick, así que sin este flag el blur que
  // dispara Escape guardaría justo lo que el usuario acaba de descartar.
  const skipCommit = React.useRef(false)

  async function commit() {
    if (skipCommit.current) {
      skipCommit.current = false
      return
    }
    const parsed = parseFloat(value)
    if (isNaN(parsed)) return
    if (parsed === item.countedQty) return

    try {
      await setQty.mutateAsync({ countId, itemId: item.itemId, qty: parsed })
    } catch {
      toast.error("Error al guardar la cantidad")
    }
  }

  if (!editable) {
    return <span>{item.countedQty !== null ? item.countedQty : "—"}</span>
  }

  // Input siempre visible, no click-to-edit: un conteo es tipear una cantidad
  // por fila, y un número que parece texto plano no se lee como editable —
  // había que descubrir el click. Además así se tabula de fila en fila.
  return (
    <Input
      type="number"
      step="0.0001"
      inputMode="decimal"
      aria-label={`Cantidad contada de ${item.name}`}
      placeholder="—"
      className="h-8 w-28 text-right tabular-nums"
      value={value}
      onChange={(e) => setValue(e.target.value)}
      onFocus={(e) => e.currentTarget.select()}
      onBlur={commit}
      onKeyDown={(e) => {
        // Enter guarda y suelta el foco; el blur no vuelve a guardar porque
        // commit() sale temprano cuando el valor no cambió.
        if (e.key === "Enter") {
          e.preventDefault()
          e.currentTarget.blur()
        }
        if (e.key === "Escape") {
          skipCommit.current = true
          setValue(toText(item.countedQty))
          e.currentTarget.blur()
        }
      }}
    />
  )
}

export default function InventoryCountDetailPage() {
  const params  = useParams<{ id: string }>()
  const router  = useRouter()
  const id      = params.id

  const { data, isLoading } = useInventoryCount(id)
  const { data: bootstrap } = useBootstrap()
  const finish  = useFinishInventoryCount()
  const cancel  = useCancelInventoryCount()

  const [search, setSearch] = React.useState("")
  const [categoryFilter, setCategoryFilter] = React.useState<string>(ALL_CATEGORIES)
  const [page, setPage]     = React.useState(0)
  const PAGE_SIZE = 100

  const session  = data?.session
  const allItems = data?.items ?? []

  // Las opciones salen de las líneas de ESTA sesión, no del catálogo: filtrar
  // por una categoría que el conteo no incluye solo daría una tabla vacía.
  const categoryOptions = React.useMemo(() => {
    const byId = new Map<string, string>()
    let hasUncategorized = false
    for (const it of allItems) {
      if (it.categoryId && it.categoryName) byId.set(it.categoryId, it.categoryName)
      else hasUncategorized = true
    }
    const opts = [...byId.entries()]
      .map(([id, name]) => ({ id, name }))
      .sort((a, b) => a.name.localeCompare(b.name, "es"))
    if (hasUncategorized) opts.push({ id: NO_CATEGORY, name: "Sin categoría" })
    return opts
  }, [allItems])

  // Alcance persistido (mig 158) — por qué esta sesión contiene lo que
  // contiene. Los nombres salen del catálogo, no de las líneas: un ítem puede
  // haber entrado por una categoría SECUNDARIA y mostrar otra como principal.
  // `scope` sin claves = sesión anterior a la mig, alcance desconocido.
  const { data: categoriesData } = useCategories()
  const scopeSummary = React.useMemo(() => {
    const scope = session?.scope
    if (!scope || scope.categoryIds === undefined) return null

    const byId = new Map((categoriesData?.categories ?? []).map((c) => [c.id, c.name]))
    const parts: string[] = [
      scope.categoryIds.length === 0
        ? "todas las categorías"
        : scope.categoryIds.map((cid) => byId.get(cid) ?? "categoría eliminada").join(", "),
    ]
    if (scope.includeZeroStock) parts.push("incluye artículos sin stock en la sucursal")
    return parts.join(" · ")
  }, [session?.scope, categoriesData])

  const filteredItems = React.useMemo(() => {
    const q = search.trim().toLowerCase()
    return allItems.filter((it) => {
      if (categoryFilter === NO_CATEGORY) {
        if (it.categoryId) return false
      } else if (categoryFilter !== ALL_CATEGORIES && it.categoryId !== categoryFilter) {
        return false
      }
      if (!q) return true
      return (
        it.name.toLowerCase().includes(q) ||
        (it.sku ?? "").toLowerCase().includes(q)
      )
    })
  }, [allItems, search, categoryFilter])

  const totalPages = Math.ceil(filteredItems.length / PAGE_SIZE)
  const pagedItems = filteredItems.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE)

  const adjustmentsPreview = allItems.filter(
    (it) => it.countedQty !== null && it.difference !== null && it.difference !== 0
  ).length
  const totalCostDeltaPreview = allItems.reduce((sum, it) => {
    if (it.countedQty !== null && it.difference !== null) {
      return sum + it.difference * it.unitCost
    }
    return sum
  }, 0)

  const isInProgress = session?.status === 1

  async function handleFinish() {
    try {
      const result = await finish.mutateAsync({ countId: id })
      toast.success(`Conteo finalizado. ${result.adjustmentsCount} ajuste(s) aplicado(s).`)
      router.refresh()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al finalizar")
    }
  }

  async function handleCancel() {
    try {
      await cancel.mutateAsync({ countId: id })
      toast.success("Sesión cancelada")
      router.push("/inventory-count")
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error al cancelar")
    }
  }

  if (isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-4 w-48" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  if (!session) {
    return (
      <div className="flex flex-col gap-4">
        <p className="text-muted-foreground">Sesión no encontrada.</p>
        <Button variant="ghost" className="w-fit" onClick={() => router.back()}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Volver
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="icon" onClick={() => router.back()}>
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <Boxes className="h-5 w-5 text-muted-foreground" />
            <h1 className="text-2xl font-semibold">Conteo de inventario</h1>
            <Badge variant={STATUS_VARIANT[session.status]}>
              {STATUS_LABEL[session.status]}
            </Badge>
          </div>
          <div className="pl-10 text-sm text-muted-foreground space-y-0.5">
            <p>Iniciado: {formatDate(session.startedAt, bootstrap)} por {session.startedByName ?? session.startedBy}</p>
            {session.finishedAt && (
              <p>Finalizado: {formatDate(session.finishedAt, bootstrap)} por {session.finishedByName ?? session.finishedBy}</p>
            )}
            {scopeSummary && <p>Alcance: {scopeSummary}</p>}
            {session.note && <p>Nota: {session.note}</p>}
          </div>
        </div>

        {isInProgress && (
          <div className="flex gap-2 pl-10 sm:pl-0">
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="outline" size="sm">
                  <XCircle className="mr-2 h-4 w-4" />
                  Cancelar sesión
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>¿Cancelar esta sesión?</AlertDialogTitle>
                  <AlertDialogDescription>
                    Se descartarán todos los conteos ingresados. No se aplicarán ajustes de stock.
                    Esta acción no se puede deshacer.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>No, continuar</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={handleCancel}
                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                  >
                    Sí, cancelar
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>

            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button size="sm">
                  <CheckCircle2 className="mr-2 h-4 w-4" />
                  Finalizar conteo
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Finalizar conteo de inventario</AlertDialogTitle>
                  <AlertDialogDescription asChild>
                    <div className="space-y-2">
                      <p>Se aplicarán los siguientes ajustes al stock:</p>
                      <ul className="list-disc pl-4 text-sm">
                        <li><strong>{adjustmentsPreview}</strong> movimiento(s) de ajuste</li>
                        <li>
                          Diferencia de costo total:{" "}
                          <strong className={totalCostDeltaPreview < 0 ? "text-red-500" : "text-green-600"}>
                            {formatMoney(totalCostDeltaPreview)}
                          </strong>
                        </li>
                      </ul>
                      <p className="text-xs text-muted-foreground">
                        Los artículos no contados (sin cantidad) no generan ajuste.
                      </p>
                    </div>
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancelar</AlertDialogCancel>
                  <AlertDialogAction onClick={handleFinish} disabled={finish.isPending}>
                    {finish.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    Confirmar y aplicar ajustes
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </div>
        )}
      </header>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative w-full max-w-sm">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            className="pl-8"
            placeholder="Buscar por nombre o SKU..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(0) }}
          />
        </div>
        {categoryOptions.length > 1 && (
          <Select
            value={categoryFilter}
            onValueChange={(v) => { setCategoryFilter(v); setPage(0) }}
          >
            <SelectTrigger className="w-[200px]">
              <SelectValue placeholder="Todas las categorías" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL_CATEGORIES}>Todas las categorías</SelectItem>
              {categoryOptions.map((c) => (
                <SelectItem key={c.id} value={c.id}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
        <span className="text-sm text-muted-foreground whitespace-nowrap">
          {filteredItems.length} / {allItems.length} artículos
        </span>
      </div>

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Artículo</TableHead>
              <TableHead>SKU</TableHead>
              <TableHead>Categoría</TableHead>
              <TableHead className="text-right">Esperado</TableHead>
              <TableHead className="text-right">Contado</TableHead>
              <TableHead className="text-right">Diferencia</TableHead>
              <TableHead className="text-right">Valor ($)</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {pagedItems.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                  {search || categoryFilter !== ALL_CATEGORIES
                    ? "Sin resultados para el filtro"
                    : "Sin artículos en esta sesión"}
                </TableCell>
              </TableRow>
            )}
            {pagedItems.map((item) => {
              const diff = item.difference
              const diffColor =
                diff === null || diff === 0
                  ? ""
                  : diff < 0
                  ? "text-red-500"
                  : "text-green-600"

              return (
                <TableRow key={item.itemId}>
                  <TableCell className="font-medium">{item.name}</TableCell>
                  <TableCell className="text-muted-foreground">{item.sku ?? "—"}</TableCell>
                  <TableCell className="text-muted-foreground">{item.categoryName ?? "—"}</TableCell>
                  <TableCell className="text-right">{item.expectedQty}</TableCell>
                  {/* `flex justify-end` en vez de `text-right`: el input es un
                      bloque de ancho fijo y text-align no lo alinea. */}
                  <TableCell>
                    <div className="flex justify-end">
                      <CountedQtyCell item={item} countId={id} editable={isInProgress} />
                    </div>
                  </TableCell>
                  <TableCell className={`text-right font-medium ${diffColor}`}>
                    {diff !== null ? (diff > 0 ? `+${diff}` : String(diff)) : "—"}
                  </TableCell>
                  <TableCell className={`text-right ${diffColor}`}>
                    {diff !== null && diff !== 0 ? formatMoney(diff * item.unitCost) : "—"}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>

      {totalPages > 1 && (
        <div className="flex items-center justify-center gap-2">
          <Button
            variant="outline"
            size="sm"
            disabled={page === 0}
            onClick={() => setPage((p) => p - 1)}
          >
            Anterior
          </Button>
          <span className="text-sm text-muted-foreground">
            Página {page + 1} de {totalPages}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={page >= totalPages - 1}
            onClick={() => setPage((p) => p + 1)}
          >
            Siguiente
          </Button>
        </div>
      )}
    </div>
  )
}
