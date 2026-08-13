"use client"

import * as React from "react"
import { Loader2, Package, Plus } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Textarea } from "@/components/ui/textarea"
import { EmptyState } from "@/components/empty-state"

import { useOutlets } from "@/hooks/use-outlets"
import { useOutletLocations } from "@/hooks/use-outlet-locations"
import {
  useAdjustItemStock,
  useItemStockMovements,
  useLastPurchasePrice,
  type StockBreakdown,
} from "@/hooks/use-item-stock"
import { Badge } from "@/components/ui/badge"
import { formatInt, formatMoney } from "@/lib/format"
import { cn } from "@/lib/utils"
import { STOCK_STATUS_CLASS, STOCK_STATUS_LABEL, stockStatus } from "@/lib/stock-status"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatDateTime } from "@/lib/format-date"

const PAGE_SIZE = 20

export function ItemStockTab({
  itemId,
  minStock,
  maxStock,
}: {
  itemId: string
  /** Umbrales del ítem, para el semáforo y la referencia bajo el saldo.
   *  Llegan del detalle ya cargado — pedirlos de nuevo acá sería una request
   *  extra por un dato que la página ya tiene. */
  minStock?: number | null
  maxStock?: number | null
}) {
  const { data: bootstrap } = useBootstrap()
  const [offset, setOffset] = React.useState(0)
  const { data, isLoading } = useItemStockMovements(itemId, { limit: PAGE_SIZE, offset })
  const { data: lastPurchase } = useLastPurchasePrice(itemId)
  const [adjustOpen, setAdjustOpen] = React.useState(false)

  const summary = data?.summary
  const breakdown = data?.breakdown
  const total = data?.total ?? 0
  const items = data?.items ?? []

  const estado = stockStatus({ qty: summary?.qty, min: minStock, max: maxStock, tracked: true })

  return (
    <div className="flex flex-col gap-6">
      {/* CUÁNTO HAY, primero y grande. Antes el tab abría con precio de compra,
          costo promedio y stock valorizado —tres cifras en dinero— y las
          unidades no aparecían en ningún lado. El valorizado importa una vez al
          mes; cuántas unidades quedan se mira todos los días. */}
      <Card>
        <CardContent className="p-5">
          <div className="flex flex-wrap items-end justify-between gap-4">
            <div>
              <div className="text-xs uppercase tracking-wide text-muted-foreground">
                Stock actual
              </div>
              <div className="mt-1 flex items-baseline gap-3">
                {summary ? (
                  <span className={cn("text-4xl font-semibold tabular-nums", estado && STOCK_STATUS_CLASS[estado])}>
                    {formatInt(summary.qty, bootstrap)}
                  </span>
                ) : (
                  <Skeleton className="h-10 w-28" />
                )}
                {estado && estado !== "ok" && (
                  <Badge variant={estado === "quiebre" ? "destructive" : "secondary"}>
                    {STOCK_STATUS_LABEL[estado]}
                  </Badge>
                )}
              </div>
              {(minStock != null || maxStock != null) && (
                <div className="mt-1.5 text-xs text-muted-foreground tabular-nums">
                  Mínimo {minStock != null ? formatInt(minStock, bootstrap) : "—"}
                  {" · "}
                  Máximo {maxStock != null ? formatInt(maxStock, bootstrap) : "—"}
                </div>
              )}
            </div>

            {/* Las cifras en dinero pasan a segundo plano: siguen estando, pero
                ya no compiten con la que se viene a buscar. */}
            <div className="flex flex-wrap gap-x-8 gap-y-2">
              <MiniStat
                label="Costo promedio"
                value={summary ? formatMoney(summary.avgCost, bootstrap) : undefined}
              />
              <MiniStat
                label="Precio de compra"
                value={lastPurchase ? formatMoney(lastPurchase.price, bootstrap) : undefined}
              />
              <MiniStat
                label="Stock valorizado"
                value={summary ? formatMoney(summary.totalValue, bootstrap) : undefined}
              />
            </div>
          </div>
        </CardContent>
      </Card>

      <StockBreakdownCard breakdown={breakdown} isLoading={isLoading} bootstrap={bootstrap} />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-3">
          <CardTitle className="text-base font-semibold tracking-tight">Ajustes e historial</CardTitle>
          <Button size="sm" onClick={() => setAdjustOpen(true)}>
            <Plus className="size-4" />
            Ajustar stock
          </Button>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex flex-col gap-2">
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
            </div>
          ) : items.length === 0 ? (
            <EmptyState
              icon={Package}
              title="Sin movimientos"
              description="Este ítem todavía no tiene ventas, compras ni ajustes de stock registrados."
              ghost={false}
            />
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Fecha</TableHead>
                    <TableHead>Origen</TableHead>
                    <TableHead>Sucursal</TableHead>
                    <TableHead className="text-right">Cantidad</TableHead>
                    <TableHead className="text-right">Saldo</TableHead>
                    <TableHead>Nota</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {items.map((m) => (
                    <TableRow key={m.id}>
                      <TableCell className="whitespace-nowrap text-sm">
                        {m.date ? formatDateTime(m.date, "d MMM yyyy HH:mm") : "—"}
                      </TableCell>
                      <TableCell className="text-sm">{sourceLabel(m.source)}</TableCell>
                      <TableCell className="text-sm">
                        {m.outletName}
                        {m.locationName ? ` · ${m.locationName}` : ""}
                      </TableCell>
                      <TableCell
                        className={`text-right tabular-nums ${m.delta >= 0 ? "text-emerald-600" : "text-destructive"}`}
                      >
                        {m.delta >= 0 ? "+" : ""}
                        {m.delta}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{m.stockOnHand}</TableCell>
                      <TableCell className="max-w-56 truncate text-sm text-muted-foreground">
                        {m.note || "—"}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              {total > PAGE_SIZE && (
                <div className="mt-3 flex items-center justify-between text-sm text-muted-foreground">
                  <span>
                    {offset + 1}–{Math.min(offset + PAGE_SIZE, total)} de {total}
                  </span>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={offset === 0}
                      onClick={() => setOffset((o) => Math.max(0, o - PAGE_SIZE))}
                    >
                      Anterior
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={offset + PAGE_SIZE >= total}
                      onClick={() => setOffset((o) => o + PAGE_SIZE)}
                    >
                      Siguiente
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <AdjustStockDialog itemId={itemId} open={adjustOpen} onOpenChange={setAdjustOpen} />
    </div>
  )
}

/** Traduce `stockSource` (columna cruda del ledger) a texto legible del panel. */
function sourceLabel(source: string): string {
  const labels: Record<string, string> = {
    sale: "Venta",
    purchase: "Compra",
    adjustment: "Ajuste manual",
    production: "Producción",
    transfer: "Transferencia",
    return: "Devolución",
    "inventory-count": "Conteo de inventario",
  }
  return labels[source] ?? source
}

/** Cifra secundaria: misma información que antes, sin robarle la atención al
 *  saldo. Sin card propia — tres cards iguales daban a entender que las tres
 *  cifras pesaban lo mismo. */
function MiniStat({ label, value }: { label: string; value: string | undefined }) {
  return (
    <div>
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="mt-0.5 text-base font-medium tabular-nums">
        {value ?? <Skeleton className="h-5 w-20" />}
      </div>
    </div>
  )
}

/**
 * Dónde está el stock: una fila por sucursal con su detalle por depósito.
 *
 * Faltaba por completo. Con varias sucursales, un total de 40 no dice si hay 40
 * donde se necesitan o 39 en una y 1 en la otra.
 */
function StockBreakdownCard({
  breakdown,
  isLoading,
  bootstrap,
}: {
  breakdown: StockBreakdown | undefined
  isLoading: boolean
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const outlets = breakdown?.outlets ?? []

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">Dónde está el stock</CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex flex-col gap-2">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
          </div>
        ) : outlets.length === 0 ? (
          <EmptyState
            icon={Package}
            title="Sin stock en ninguna sucursal"
            description="Cuando entre mercadería por una compra o un ajuste, va a aparecer acá."
            ghost={false}
          />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Sucursal</TableHead>
                <TableHead>Depósito</TableHead>
                <TableHead className="text-right">Cantidad</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {outlets.map((o) => (
                <React.Fragment key={o.outletId}>
                  <TableRow className="bg-muted/40">
                    <TableCell className="font-medium">{o.outletName}</TableCell>
                    <TableCell className="text-muted-foreground">Total sucursal</TableCell>
                    <TableCell className="text-right font-semibold tabular-nums">
                      {formatInt(o.qty, bootstrap)}
                    </TableCell>
                  </TableRow>
                  {/* El detalle por depósito solo aporta cuando hay más de uno:
                      con uno solo repetiría la fila de la sucursal. */}
                  {o.locations.length > 1 &&
                    o.locations.map((l) => (
                      <TableRow key={`${o.outletId}-${l.locationId ?? "principal"}`}>
                        <TableCell />
                        <TableCell className="text-sm text-muted-foreground">
                          {l.locationName ?? "Depósito principal"}
                        </TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">
                          {formatInt(l.qty, bootstrap)}
                        </TableCell>
                      </TableRow>
                    ))}
                </React.Fragment>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}

function AdjustStockDialog({
  itemId,
  open,
  onOpenChange,
}: {
  itemId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []
  const adjust = useAdjustItemStock(itemId)

  const [outletId, setOutletId] = React.useState("")
  const [locationId, setLocationId] = React.useState("")
  const [type, setType] = React.useState<"+" | "-">("+")
  const [qty, setQty] = React.useState("")
  const [unitCost, setUnitCost] = React.useState<number | null>(null)
  const [reason, setReason] = React.useState("")

  // Sin selección explícita, cae en la primera sucursal — el comercio típico
  // tiene 1-2. Derivado en vez de sincronizado por efecto: `outlets` llega
  // async (query), así que no hay valor sincrónico posible en el mount.
  const effectiveOutletId = outletId || outlets[0]?.id || ""

  const { data: locations } = useOutletLocations(effectiveOutletId || null)

  function reset() {
    setLocationId("")
    setType("+")
    setQty("")
    setUnitCost(null)
    setReason("")
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    const qtyNum = Number(qty)
    if (!effectiveOutletId) {
      toast.error("Elegí una sucursal")
      return
    }
    if (!qtyNum || qtyNum <= 0) {
      toast.error("Ingresá una cantidad mayor a 0")
      return
    }
    if (!reason.trim()) {
      toast.error("Ingresá el motivo del ajuste")
      return
    }

    adjust.mutate(
      {
        outletId: effectiveOutletId,
        locationId: locationId || null,
        type,
        qty: qtyNum,
        unitCost,
        reason: reason.trim(),
      },
      {
        onSuccess: () => {
          toast.success("Stock ajustado")
          reset()
          onOpenChange(false)
        },
        onError: (err) => toast.error("No se pudo ajustar el stock", { description: err.message }),
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">Ajustar stock</DialogTitle>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="adjust-outlet">Sucursal</Label>
              <Select value={effectiveOutletId} onValueChange={(v) => { setOutletId(v); setLocationId("") }}>
                <SelectTrigger id="adjust-outlet">
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
            <div className="space-y-1.5">
              <Label htmlFor="adjust-location">Depósito (opcional)</Label>
              <Select value={locationId} onValueChange={setLocationId} disabled={!effectiveOutletId}>
                <SelectTrigger id="adjust-location">
                  <SelectValue placeholder="Depósito principal" />
                </SelectTrigger>
                <SelectContent>
                  {(locations ?? []).map((l) => (
                    <SelectItem key={l.id} value={l.id}>
                      {l.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div className="space-y-1.5">
              <Label htmlFor="adjust-type">Tipo</Label>
              <Select value={type} onValueChange={(v) => setType(v as "+" | "-")}>
                <SelectTrigger id="adjust-type">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="+">Ingreso (+)</SelectItem>
                  <SelectItem value="-">Egreso (-)</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="adjust-qty">Cantidad</Label>
              <Input
                id="adjust-qty"
                type="number"
                min="0"
                step="any"
                value={qty}
                onChange={(e) => setQty(e.target.value)}
                placeholder="0"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="adjust-cost">Costo unitario (opcional)</Label>
              <MoneyInput id="adjust-cost" value={unitCost} onChange={setUnitCost} placeholder="0" />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="adjust-reason">Motivo</Label>
            <Textarea
              id="adjust-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Ej.: conteo físico, merma, ingreso sin remito"
              rows={2}
            />
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={adjust.isPending}>
              {adjust.isPending && <Loader2 className="size-4 animate-spin" />}
              Guardar ajuste
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
