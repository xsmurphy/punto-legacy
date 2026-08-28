"use client"

import * as React from "react"
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
import { MoneyInput } from "@/components/ui/money-input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useCreateItem, useTaxonomiesByType } from "@/hooks/use-items"
import { useTaxes } from "@/hooks/use-taxes"
import { emptyItemValues, type ItemFull, type ItemKind } from "@/lib/types/item"

/**
 * Alta rápida de un ítem SIN salir de la pantalla donde hizo falta.
 *
 * Nace del formulario de compras: al cargar una factura aparece un producto que
 * todavía no está en el catálogo, y mandar al usuario a `/items/new` le hace
 * perder la factura a medio cargar. Acá crea lo mínimo, sigue cargando, y
 * completa la ficha después si quiere.
 *
 * Lo que NO hace: reemplazar el alta completa. Solo pide lo que se necesita para
 * que el ítem exista y se pueda comprar — el resto queda en los defaults de
 * `emptyItemValues()`, la MISMA fuente que usa el form completo (por eso vive en
 * `lib/types/item.ts` y no acá: dos juegos de defaults divergen sin que nadie
 * lo note).
 */
export function QuickCreateItemDialog({
  open,
  onOpenChange,
  initialName,
  outletId,
  onCreated,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  /** Lo que el usuario ya tipeó en el buscador — no se lo hacemos escribir dos veces. */
  initialName?: string
  /** Sucursal de la compra: el ítem necesita al menos una asignada. */
  outletId?: string
  onCreated: (item: { id: string; name: string; taxId?: string }) => void
}) {
  const createItem = useCreateItem()
  const { data: taxes } = useTaxes()
  const { data: categories } = useTaxonomiesByType("category")

  const [name, setName] = React.useState("")
  const [kind, setKind] = React.useState<ItemKind>("producto")
  const [sku, setSku] = React.useState("")
  const [price, setPrice] = React.useState<number | null>(null)
  const [taxId, setTaxId] = React.useState("")
  const [categoryId, setCategoryId] = React.useState("")

  const taxOptions = taxes?.taxes ?? []

  // Al abrir: arranca con lo tipeado en el buscador y el primer impuesto del
  // tenant (mismo criterio que la línea de compra).
  React.useEffect(() => {
    if (!open) return
    setName(initialName ?? "")
    setKind("producto")
    setSku("")
    setPrice(null)
    setCategoryId("")
    setTaxId(taxOptions[0]?.id ?? "")
    // `taxOptions[0]?.id` en las deps: si `useTaxes()` todavía no resolvió al
    // abrir el diálogo, sin esto el ítem nacía sin impuesto y nadie lo notaba.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialName, taxOptions[0]?.id])

  const canSave = name.trim() !== "" && !createItem.isPending

  const onSubmit = async () => {
    if (!canSave) return
    try {
      const created = (await createItem.mutateAsync({
        ...emptyItemValues(),
        kind,
        name: name.trim(),
        sku: sku.trim(),
        price,
        taxId,
        categoryId,
        // Un ítem sin sucursal no es visible para comprar ni vender. Usamos la
        // de la compra en curso; si no hay, el backend resuelve su default.
        outletIds: outletId ? [outletId] : [],
      })) as ItemFull
      const id = created?.itemId ?? ""
      if (!id) throw new Error("El servidor no devolvió el artículo creado")
      toast.success(`"${name.trim()}" creado`)
      // `taxId` viaja para que la línea de compra herede el impuesto que el
      // usuario acaba de elegir acá, sin tener que volver a fijarlo.
      onCreated({ id, name: name.trim(), taxId })
      onOpenChange(false)
    } catch (err) {
      toast.error("No se pudo crear el artículo", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Nuevo artículo</DialogTitle>
          <DialogDescription>
            Cargá lo mínimo para seguir con la factura. Podés completar la ficha
            después desde Artículos.
          </DialogDescription>
        </DialogHeader>

        <div className="grid grid-cols-12 gap-3">
          <div className="col-span-12 flex flex-col gap-1.5 sm:col-span-8">
            <Label htmlFor="qc-name">Nombre</Label>
            <Input
              id="qc-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Nombre del artículo"
              autoFocus
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault()
                  void onSubmit()
                }
              }}
            />
          </div>
          <div className="col-span-12 flex flex-col gap-1.5 sm:col-span-4">
            <Label htmlFor="qc-kind">Tipo</Label>
            <Select value={kind} onValueChange={(v) => setKind(v as ItemKind)}>
              <SelectTrigger id="qc-kind">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="producto">Producto</SelectItem>
                <SelectItem value="servicio">Servicio</SelectItem>
                <SelectItem value="insumo">Insumo</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="col-span-12 flex flex-col gap-1.5 sm:col-span-4">
            <Label htmlFor="qc-sku">SKU</Label>
            <Input
              id="qc-sku"
              value={sku}
              onChange={(e) => setSku(e.target.value)}
              placeholder="Opcional"
            />
          </div>
          <div className="col-span-12 flex flex-col gap-1.5 sm:col-span-4">
            {/* Precio de VENTA. El costo lo fija la compra que se está
                cargando, así que no se pide acá. */}
            <Label htmlFor="qc-price">Precio de venta</Label>
            <MoneyInput
              id="qc-price"
              value={price}
              onChange={setPrice}
              placeholder="Opcional"
            />
          </div>
          <div className="col-span-12 flex flex-col gap-1.5 sm:col-span-4">
            <Label htmlFor="qc-tax">Impuesto</Label>
            <Select value={taxId} onValueChange={setTaxId}>
              <SelectTrigger id="qc-tax">
                <SelectValue placeholder="Impuesto" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="0">Sin impuesto</SelectItem>
                {taxOptions.map((t) => (
                  <SelectItem key={t.id} value={t.id}>
                    {t.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="col-span-12 flex flex-col gap-1.5">
            <Label htmlFor="qc-category">Categoría</Label>
            <Select
              value={categoryId || "none"}
              onValueChange={(v) => setCategoryId(v === "none" ? "" : v)}
            >
              <SelectTrigger id="qc-category">
                <SelectValue placeholder="Sin categoría" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="none">Sin categoría</SelectItem>
                {(categories ?? []).map((c) => (
                  <SelectItem key={c.id} value={c.id}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="button" onClick={onSubmit} disabled={!canSave}>
            {createItem.isPending ? "Creando…" : "Crear y usar"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
