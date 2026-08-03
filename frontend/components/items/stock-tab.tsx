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
} from "@/hooks/use-item-stock"
import { formatMoney } from "@/lib/format"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatDateTime } from "@/lib/format-date"

const PAGE_SIZE = 20

export function ItemStockTab({ itemId }: { itemId: string }) {
  const { data: bootstrap } = useBootstrap()
  const [offset, setOffset] = React.useState(0)
  const { data, isLoading } = useItemStockMovements(itemId, { limit: PAGE_SIZE, offset })
  const { data: lastPurchase } = useLastPurchasePrice(itemId)
  const [adjustOpen, setAdjustOpen] = React.useState(false)

  const summary = data?.summary
  const total = data?.total ?? 0
  const items = data?.items ?? []

  return (
    <div className="flex flex-col gap-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StockKpi
          label="Precio de compra"
          value={lastPurchase ? formatMoney(lastPurchase.price, bootstrap) : undefined}
        />
        <StockKpi label="Costo promedio" value={summary ? formatMoney(summary.avgCost, bootstrap) : undefined} />
        <StockKpi
          label="Valor total del stock"
          value={summary ? formatMoney(summary.totalValue, bootstrap) : undefined}
        />
      </div>

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

function StockKpi({ label, value }: { label: string; value: string | undefined }) {
  return (
    <Card>
      <CardContent className="p-4 text-center">
        <div className="text-xs uppercase tracking-wide text-muted-foreground">{label}</div>
        <div className="mt-1 text-2xl font-semibold tabular-nums">
          {value ?? <Skeleton className="mx-auto h-7 w-24" />}
        </div>
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
