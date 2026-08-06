"use client"

import * as React from "react"
import { Loader2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

import { useItems } from "@/hooks/use-items"
import { useCompleteProductionOrder, useProductionCapacity } from "@/hooks/use-production"
import { useWasteReasons } from "@/hooks/use-waste-reasons"
import type { ProductionOrder } from "@/lib/types/production"

const NO_REASON = "__none__"

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  order: ProductionOrder
}

/**
 * Completar orden: qtyProduced (default = plan), unidades con merma + motivo,
 * y ajuste opcional de la cantidad REAL consumida por insumo
 * (ingredientAdjustments) — pisa el cálculo teórico (qtyPerUnit × plan
 * ajustado por %merma) cuando el usuario mide el consumo real.
 */
export function CompleteProductionDialog({ open, onOpenChange, order }: Props) {
  const complete = useCompleteProductionOrder()
  const { data: capacity } = useProductionCapacity(order.itemId, order.outletId)
  const { data: wasteReasonsData } = useWasteReasons()
  const { data: allItems } = useItems({})

  const nameById = React.useMemo(() => {
    const map = new Map<string, string>()
    for (const it of allItems?.items ?? []) map.set(it.itemId, it.itemName)
    return map
  }, [allItems])

  const [qtyProduced, setQtyProduced] = React.useState(String(order.qtyPlanned))
  const [wasteUnits, setWasteUnits] = React.useState("0")
  const [wasteReasonId, setWasteReasonId] = React.useState(NO_REASON)
  const [adjustments, setAdjustments] = React.useState<Record<string, string>>({})

  React.useEffect(() => {
    if (!open) return
    setQtyProduced(String(order.qtyPlanned))
    setWasteUnits("0")
    setWasteReasonId(NO_REASON)
    setAdjustments({})
  }, [open, order.qtyPlanned])

  async function handleSubmit() {
    const qty = Number(qtyProduced.replace(",", "."))
    if (!(qty >= 0)) return
    const ingredientAdjustments = Object.entries(adjustments)
      .filter(([, v]) => v !== "")
      .map(([itemId, v]) => ({ itemId, actualQty: Number(v.replace(",", ".")) }))
      .filter((a) => Number.isFinite(a.actualQty))

    try {
      await complete.mutateAsync({
        id: order.id,
        values: {
          qtyProduced: qty,
          wasteUnits: wasteUnits ? Number(wasteUnits.replace(",", ".")) : 0,
          wasteReasonId: wasteReasonId === NO_REASON ? null : wasteReasonId,
          ingredientAdjustments: ingredientAdjustments.length ? ingredientAdjustments : undefined,
        },
      })
      toast.success("Producción completada")
      onOpenChange(false)
    } catch (e) {
      toast.error("No se pudo completar la orden", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const wasteNum = Number(wasteUnits.replace(",", "."))
  const needsReason = wasteNum > 0 && wasteReasonId === NO_REASON

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Completar producción</DialogTitle>
          <DialogDescription>
            {order.itemName} — plan: {order.qtyPlanned} unidades.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label>Unidades producidas (OK)</Label>
              <Input
                type="number"
                min="0"
                step="any"
                inputMode="decimal"
                value={qtyProduced}
                onChange={(e) => setQtyProduced(e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label>Unidades con merma</Label>
              <Input
                type="number"
                min="0"
                step="any"
                inputMode="decimal"
                value={wasteUnits}
                onChange={(e) => setWasteUnits(e.target.value)}
              />
            </div>
          </div>

          {wasteNum > 0 && (
            <div className="space-y-1.5">
              <Label>
                Motivo de merma
                <span className="text-destructive"> *</span>
              </Label>
              <Select value={wasteReasonId} onValueChange={setWasteReasonId}>
                <SelectTrigger>
                  <SelectValue placeholder="Elegí un motivo" />
                </SelectTrigger>
                <SelectContent>
                  {(wasteReasonsData?.wasteReasons ?? []).map((r) => (
                    <SelectItem key={r.id} value={r.id}>
                      {r.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}

          {capacity && capacity.ingredients.length > 0 && (
            <div className="space-y-2 rounded-md border p-3">
              <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Consumo real de insumos (opcional)
              </p>
              <p className="text-xs text-muted-foreground">
                Por default se consume lo teórico (cantidad × plan, ajustado por % de
                merma del insumo). Completá acá solo si mediste un consumo distinto.
              </p>
              <div className="space-y-2">
                {capacity.ingredients.map((ing) => {
                  const theoretical = ing.qtyPerUnit * order.qtyPlanned * (1 + ing.wastePercent / 100)
                  return (
                    <div key={ing.itemId} className="flex items-center gap-2">
                      <span className="min-w-0 flex-1 truncate text-sm">
                        {nameById.get(ing.itemId) ?? ing.itemId}
                      </span>
                      <Input
                        type="number"
                        step="any"
                        className="w-28"
                        placeholder={theoretical.toFixed(2)}
                        value={adjustments[ing.itemId] ?? ""}
                        onChange={(e) =>
                          setAdjustments((prev) => ({ ...prev, [ing.itemId]: e.target.value }))
                        }
                      />
                    </div>
                  )
                })}
              </div>
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={complete.isPending}>
            Cancelar
          </Button>
          <Button onClick={handleSubmit} disabled={complete.isPending || needsReason}>
            {complete.isPending && <Loader2 className="size-4 animate-spin" />}
            Completar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
