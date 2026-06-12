"use client"

import * as React from "react"
import { toast } from "sonner"
import { Loader2 } from "lucide-react"

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
import { MoneyInput } from "@/components/ui/money-input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  useBulkEditItems,
  useTaxonomiesByType,
  type BulkEditPatch,
} from "@/hooks/use-items"
import { useOutlets } from "@/hooks/use-outlets"
import type { ItemListItem } from "@/lib/types/item"

interface Props {
  open: boolean
  items: ItemListItem[]
  onClose: () => void
  onSuccess?: () => void
}

/**
 * Diálogo de edición masiva. Solo manda al backend los campos que el usuario
 * tocó (los que quedaron `null`/`""` se omiten del patch). Esto evita pisar
 * datos sin querer cuando solo se quiere cambiar un campo.
 *
 * El form vive en `BulkEditForm`, montado únicamente cuando `open=true`. Así
 * cada apertura empieza con estado fresco sin necesidad de useEffect de reset.
 */
export function BulkEditDialog({ open, items, onClose, onSuccess }: Props) {
  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-w-2xl">
        {open && <BulkEditForm items={items} onClose={onClose} onSuccess={onSuccess} />}
      </DialogContent>
    </Dialog>
  )
}

function BulkEditForm({
  items,
  onClose,
  onSuccess,
}: {
  items: ItemListItem[]
  onClose: () => void
  onSuccess?: () => void
}) {
  const bulk = useBulkEditItems()
  const { data: outlets } = useOutlets()
  const { data: categories } = useTaxonomiesByType("category")
  const { data: brands } = useTaxonomiesByType("brand")
  const { data: taxes } = useTaxonomiesByType("tax")

  const [price, setPrice] = React.useState<number | null>(null)
  const [adjustPercent, setAdjustPercent] = React.useState<string>("")
  const [taxId, setTaxId] = React.useState<string>("")
  const [categoryId, setCategoryId] = React.useState<string>("")
  const [brandId, setBrandId] = React.useState<string>("")
  const [outletId, setOutletId] = React.useState<string>("")
  const [discount, setDiscount] = React.useState<string>("")
  const [uom, setUom] = React.useState<string>("")
  const [waste, setWaste] = React.useState<string>("")
  const [commission, setCommission] = React.useState<string>("")
  const [commissionType, setCommissionType] = React.useState<"none" | "percent" | "amount">("none")
  const [ecom, setEcom] = React.useState<string>("")

  const handleSubmit = async () => {
    const patch: BulkEditPatch = {}
    let priceAdjustPercent: number | null = null

    if (price !== null) patch.itemPrice = price
    if (adjustPercent.trim() !== "") {
      const p = Number(adjustPercent)
      if (!Number.isFinite(p)) {
        toast.error("Ajuste % inválido")
        return
      }
      priceAdjustPercent = p
    }
    if (taxId) patch.taxId = taxId
    if (categoryId) patch.categoryId = categoryId
    if (brandId) patch.brandId = brandId
    if (outletId) patch.outletId = outletId === "__all__" ? null : outletId
    if (discount.trim() !== "") patch.itemDiscount = Number(discount) || null
    if (uom.trim() !== "") patch.itemUOM = uom.trim()
    if (waste.trim() !== "") patch.itemWaste = Number(waste) || 0
    if (commission.trim() !== "" && commissionType !== "none") {
      patch.itemComissionPercent = Number(commission) || 0
      patch.itemComissionType = commissionType === "percent" ? "0" : "1"
    }
    if (ecom !== "") patch.itemEcom = ecom === "1" ? 1 : 0

    const hasPatch = Object.keys(patch).length > 0 || priceAdjustPercent !== null
    if (!hasPatch) {
      toast.error("Completá al menos un campo para aplicar")
      return
    }
    if (price !== null && priceAdjustPercent !== null) {
      toast.error("Elegí precio fijo O ajuste %, no ambos")
      return
    }

    try {
      const r = await bulk.mutateAsync({
        itemIds: items.map((i) => i.itemId),
        patch,
        priceAdjustPercent,
      })
      toast.success(
        `${r.updated} ${r.updated === 1 ? "artículo actualizado" : "artículos actualizados"}` +
          (r.skipped > 0 ? ` · ${r.skipped} omitido(s)` : ""),
      )
      onSuccess?.()
      onClose()
    } catch (e) {
      toast.error("No se pudo aplicar la edición masiva", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const submitting = bulk.isPending

  return (
    <>
        <DialogHeader>
          <DialogTitle>Edición masiva</DialogTitle>
          <DialogDescription>
            {items.length} {items.length === 1 ? "artículo seleccionado" : "artículos seleccionados"}.
            Lo que completes acá se aplica a todos. Los campos vacíos se ignoran.
          </DialogDescription>
        </DialogHeader>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 max-h-[60vh] overflow-y-auto pr-1">
          <div className="space-y-1.5">
            <Label htmlFor="bulk-price">Precio</Label>
            <MoneyInput
              id="bulk-price"
              value={price}
              onChange={setPrice}
              placeholder="—"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="bulk-adjust">Ajuste de precio %</Label>
            <Input
              id="bulk-adjust"
              type="number"
              value={adjustPercent}
              onChange={(e) => setAdjustPercent(e.target.value)}
              placeholder="ej: 5 (+5%) o -10 (−10%)"
            />
          </div>

          <div className="space-y-1.5">
            <Label>Impuesto</Label>
            <Select value={taxId} onValueChange={setTaxId}>
              <SelectTrigger>
                <SelectValue placeholder="No cambiar" />
              </SelectTrigger>
              <SelectContent>
                {(taxes ?? []).map((t) => (
                  <SelectItem key={t.id} value={t.id}>
                    {t.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label>Categoría</Label>
            <Select value={categoryId} onValueChange={setCategoryId}>
              <SelectTrigger>
                <SelectValue placeholder="No cambiar" />
              </SelectTrigger>
              <SelectContent>
                {(categories ?? []).map((c) => (
                  <SelectItem key={c.id} value={c.id}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label>Marca</Label>
            <Select value={brandId} onValueChange={setBrandId}>
              <SelectTrigger>
                <SelectValue placeholder="No cambiar" />
              </SelectTrigger>
              <SelectContent>
                {(brands ?? []).map((b) => (
                  <SelectItem key={b.id} value={b.id}>
                    {b.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label>Sucursal</Label>
            <Select value={outletId} onValueChange={setOutletId}>
              <SelectTrigger>
                <SelectValue placeholder="No cambiar" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">Todas las sucursales</SelectItem>
                {(outlets?.rows ?? []).map((o) => (
                  <SelectItem key={o.id} value={o.id}>
                    {o.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="bulk-discount">Descuento %</Label>
            <Input
              id="bulk-discount"
              type="number"
              value={discount}
              onChange={(e) => setDiscount(e.target.value)}
              placeholder="0"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="bulk-uom">Unidad de medida</Label>
            <Input
              id="bulk-uom"
              value={uom}
              onChange={(e) => setUom(e.target.value)}
              placeholder="ej: kg, un, lt"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="bulk-waste">Merma %</Label>
            <Input
              id="bulk-waste"
              type="number"
              value={waste}
              onChange={(e) => setWaste(e.target.value)}
              placeholder="0"
            />
          </div>

          <div className="space-y-1.5">
            <Label>Comisión</Label>
            <div className="flex gap-2">
              <Input
                type="number"
                value={commission}
                onChange={(e) => setCommission(e.target.value)}
                placeholder="0"
                className="flex-1"
              />
              <Select
                value={commissionType}
                onValueChange={(v) => setCommissionType(v as typeof commissionType)}
              >
                <SelectTrigger className="w-[100px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">—</SelectItem>
                  <SelectItem value="percent">%</SelectItem>
                  <SelectItem value="amount">$</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-1.5 sm:col-span-2">
            <Label>Mostrar en tienda online</Label>
            <Select value={ecom} onValueChange={setEcom}>
              <SelectTrigger>
                <SelectValue placeholder="No cambiar" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="1">Sí</SelectItem>
                <SelectItem value="0">No</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={onClose} disabled={submitting}>
            Cancelar
          </Button>
          <Button onClick={handleSubmit} disabled={submitting}>
            {submitting && <Loader2 className="size-4 animate-spin" />}
            Aplicar a {items.length}
          </Button>
        </DialogFooter>
    </>
  )
}
