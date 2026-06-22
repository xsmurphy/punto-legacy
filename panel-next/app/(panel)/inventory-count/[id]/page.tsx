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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"

import {
  useInventoryCount,
  useSetCountedQty,
  useFinishInventoryCount,
  useCancelInventoryCount,
  type InventoryCountItem,
} from "@/hooks/use-inventory-counts"
import { formatMoney as _formatMoney } from "@/lib/format"

function formatMoney(v: number): string {
  return _formatMoney(v, undefined)
}

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

function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—"
  return new Date(iso).toLocaleString("es-PY", {
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
  const setQty   = useSetCountedQty()
  const [editing, setEditing] = React.useState(false)
  const [value, setValue]     = React.useState<string>(
    item.countedQty !== null ? String(item.countedQty) : ""
  )
  const inputRef = React.useRef<HTMLInputElement>(null)

  React.useEffect(() => {
    if (editing) inputRef.current?.select()
  }, [editing])

  async function commit() {
    setEditing(false)
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

  if (editing) {
    return (
      <Input
        ref={inputRef}
        type="number"
        step="0.0001"
        className="h-7 w-28 text-right"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === "Enter") commit()
          if (e.key === "Escape") {
            setValue(item.countedQty !== null ? String(item.countedQty) : "")
            setEditing(false)
          }
        }}
      />
    )
  }

  return (
    <button
      className="min-w-[4rem] rounded px-2 py-0.5 text-right hover:bg-accent"
      onClick={() => setEditing(true)}
    >
      {item.countedQty !== null ? (
        item.countedQty
      ) : (
        <span className="text-muted-foreground">—</span>
      )}
    </button>
  )
}

export default function InventoryCountDetailPage() {
  const params  = useParams<{ id: string }>()
  const router  = useRouter()
  const id      = params.id

  const { data, isLoading } = useInventoryCount(id)
  const finish  = useFinishInventoryCount()
  const cancel  = useCancelInventoryCount()

  const [search, setSearch] = React.useState("")
  const [page, setPage]     = React.useState(0)
  const PAGE_SIZE = 100

  const session  = data?.session
  const allItems = data?.items ?? []

  const filteredItems = React.useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return allItems
    return allItems.filter(
      (it) =>
        it.name.toLowerCase().includes(q) ||
        (it.sku ?? "").toLowerCase().includes(q)
    )
  }, [allItems, search])

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
      <div className="space-y-4 p-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-4 w-48" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  if (!session) {
    return (
      <div className="p-6">
        <p className="text-muted-foreground">Sesión no encontrada.</p>
        <Button variant="ghost" className="mt-4" onClick={() => router.back()}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Volver
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="icon" onClick={() => router.back()}>
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <Boxes className="h-5 w-5 text-muted-foreground" />
            <h1 className="text-xl font-semibold">Conteo de inventario</h1>
            <Badge variant={STATUS_VARIANT[session.status]}>
              {STATUS_LABEL[session.status]}
            </Badge>
          </div>
          <div className="pl-10 text-sm text-muted-foreground space-y-0.5">
            <p>Iniciado: {formatDate(session.startedAt)} por {session.startedByName ?? session.startedBy}</p>
            {session.finishedAt && (
              <p>Finalizado: {formatDate(session.finishedAt)} por {session.finishedByName ?? session.finishedBy}</p>
            )}
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
      </div>

      <div className="flex items-center gap-2">
        <div className="relative w-full max-w-sm">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            className="pl-8"
            placeholder="Buscar por nombre o SKU..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(0) }}
          />
        </div>
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
              <TableHead className="text-right">Esperado</TableHead>
              <TableHead className="text-right">Contado</TableHead>
              <TableHead className="text-right">Diferencia</TableHead>
              <TableHead className="text-right">Valor ($)</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {pagedItems.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                  {search ? "Sin resultados para la búsqueda" : "Sin artículos en esta sesión"}
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
                  <TableCell className="text-right">{item.expectedQty}</TableCell>
                  <TableCell className="text-right">
                    <CountedQtyCell item={item} countId={id} editable={isInProgress} />
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
