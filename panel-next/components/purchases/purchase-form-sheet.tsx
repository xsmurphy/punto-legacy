"use client"

import * as React from "react"
import { Loader2, Plus, Search, Trash2, X, Package, Receipt } from "lucide-react"
import { toast } from "sonner"

import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Switch } from "@/components/ui/switch"
import { cn } from "@/lib/utils"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useContacts, useCreateContact } from "@/hooks/use-contacts"
import { useItems } from "@/hooks/use-items"
import { useTaxes } from "@/hooks/use-taxes"
import { useCreatePurchase, type PurchaseFormItem } from "@/hooks/use-purchases"
import { formatMoney } from "@/lib/format"

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Default outletId si el usuario aún no eligió. Viene del bootstrap. */
  defaultOutletId: string
}

interface FormLine extends PurchaseFormItem {
  /** Local rowId estable, sirve como key del map para no re-renderar mal. */
  rowId: string
  /** Si false la línea es "descripción libre" (gasto). True = producto del catálogo. */
  isProduct: boolean
  /** Display del producto seleccionado (cuando isProduct=true y itemId está set). */
  itemName?: string
}

function makeRowId(): string {
  return Math.random().toString(36).slice(2, 10)
}

function emptyLine(isProduct = true): FormLine {
  return {
    rowId: makeRowId(),
    isProduct,
    units: 1,
    price: 0,
    taxId: "",
    taxValue: 0,
  }
}

export function PurchaseFormSheet({ open, onOpenChange, defaultOutletId }: Props) {
  const { data: bootstrap } = useBootstrap()
  const createPurchase = useCreatePurchase()

  const [supplierId, setSupplierId] = React.useState<string>("")
  const [supplierName, setSupplierName] = React.useState<string>("")
  const [outletId, setOutletId] = React.useState<string>(defaultOutletId)
  const [invoiceDate, setInvoiceDate] = React.useState<string>(today())
  const [dueDate, setDueDate] = React.useState<string>(today())
  const [authNo, setAuthNo] = React.useState<string>("")
  const [invoicePrefix, setInvoicePrefix] = React.useState<string>("")
  const [invoiceNo, setInvoiceNo] = React.useState<string>("")
  const [paymentMethod, setPaymentMethod] = React.useState<string>("cash")
  const [discount, setDiscount] = React.useState<string>("")
  const [note, setNote] = React.useState<string>("")
  const [lines, setLines] = React.useState<FormLine[]>([emptyLine()])

  // Sincronizar defaultOutletId si cambia mientras el sheet está cerrado.
  React.useEffect(() => {
    if (!open && defaultOutletId && defaultOutletId !== outletId) {
      setOutletId(defaultOutletId)
    }
  }, [open, defaultOutletId, outletId])

  const outlets = bootstrap?.outlets ?? []

  // Reset al abrir (no al cerrar — la animación se vería raro).
  React.useEffect(() => {
    if (open) {
      setSupplierId("")
      setSupplierName("")
      setOutletId(defaultOutletId)
      setInvoiceDate(today())
      setDueDate(today())
      setAuthNo("")
      setInvoicePrefix("")
      setInvoiceNo("")
      setPaymentMethod("cash")
      setDiscount("")
      setNote("")
      setLines([emptyLine()])
    }
  }, [open, defaultOutletId])

  // ── Totales reactivos ────────────────────────────────────────────────
  const totals = React.useMemo(() => {
    let sub = 0
    let tax = 0
    for (const l of lines) {
      const u = Number(l.units) || 0
      const p = Number(l.price) || 0
      sub += Math.abs(u * p)
      tax += Number(l.taxValue) || 0
    }
    const disc = Number(discount) || 0
    return { sub, tax, discount: disc, total: sub - disc }
  }, [lines, discount])

  // ── Acciones de líneas ────────────────────────────────────────────────
  const updateLine = (rowId: string, patch: Partial<FormLine>) => {
    setLines((curr) =>
      curr.map((l) => (l.rowId === rowId ? { ...l, ...patch } : l)),
    )
  }
  const removeLine = (rowId: string) => {
    setLines((curr) =>
      curr.length === 1 ? [emptyLine()] : curr.filter((l) => l.rowId !== rowId),
    )
  }
  const addLine = () => setLines((curr) => [...curr, emptyLine()])

  // ── Submit ────────────────────────────────────────────────────────────
  const canSubmit =
    outletId !== "" &&
    lines.some(
      (l) =>
        (l.isProduct ? !!l.itemId : (l.title ?? "").trim() !== "") &&
        (Number(l.units) || 0) > 0,
    )

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!canSubmit) {
      toast.error("Agregá al menos un ítem con cantidad y producto/descripción.")
      return
    }
    const items: PurchaseFormItem[] = lines
      .filter((l) => (Number(l.units) || 0) > 0)
      .filter((l) => (l.isProduct ? !!l.itemId : (l.title ?? "").trim() !== ""))
      .map((l) => ({
        itemId: l.isProduct ? l.itemId : undefined,
        title: l.title ?? undefined,
        units: Number(l.units) || 0,
        price: Number(l.price) || 0,
        taxId: l.taxId || undefined,
        taxValue: Number(l.taxValue) || 0,
      }))

    try {
      await createPurchase.mutateAsync({
        supplierId: supplierId || null,
        outletId,
        invoiceDate,
        dueDate,
        invoiceNo: invoiceNo || null,
        invoicePrefix,
        authNo,
        paymentMethod,
        discount: Number(discount) || 0,
        note,
        items,
      })
      toast.success("Compra registrada")
      onOpenChange(false)
    } catch (err) {
      toast.error("No se pudo registrar la compra", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        className="flex w-full flex-col gap-0 p-0 sm:max-w-2xl"
      >
        <form onSubmit={onSubmit} className="flex h-full flex-col">
          <SheetHeader className="border-b px-6 py-4">
            <SheetTitle>Nueva compra</SheetTitle>
            <SheetDescription>
              Registro de factura de compra a un proveedor.
            </SheetDescription>
          </SheetHeader>

          <div className="flex-1 overflow-y-auto px-6 py-4">
            <div className="flex flex-col gap-5">
              {/* Proveedor + sucursal */}
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <SupplierPicker
                  value={supplierId}
                  displayName={supplierName}
                  onChange={(id, name) => {
                    setSupplierId(id)
                    setSupplierName(name)
                  }}
                />
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="outlet">Sucursal</Label>
                  <Select value={outletId} onValueChange={setOutletId}>
                    <SelectTrigger id="outlet">
                      <SelectValue placeholder="Seleccionar sucursal" />
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
              </div>

              {/* Factura */}
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Field label="Timbrado / Auth" id="authNo">
                  <Input
                    id="authNo"
                    value={authNo}
                    onChange={(e) => setAuthNo(e.target.value)}
                    placeholder="Opcional"
                  />
                </Field>
                <Field label="Prefijo" id="invoicePrefix">
                  <Input
                    id="invoicePrefix"
                    value={invoicePrefix}
                    onChange={(e) => setInvoicePrefix(e.target.value)}
                    placeholder="001-001"
                  />
                </Field>
                <Field label="Número" id="invoiceNo">
                  <Input
                    id="invoiceNo"
                    type="number"
                    min={0}
                    step={1}
                    value={invoiceNo}
                    onChange={(e) => setInvoiceNo(e.target.value)}
                    placeholder="0000001"
                  />
                </Field>
              </div>

              {/* Fechas */}
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Fecha factura" id="invoiceDate">
                  <Input
                    id="invoiceDate"
                    type="date"
                    value={invoiceDate}
                    onChange={(e) => setInvoiceDate(e.target.value)}
                  />
                </Field>
                <Field label="Vencimiento" id="dueDate">
                  <Input
                    id="dueDate"
                    type="date"
                    value={dueDate}
                    onChange={(e) => setDueDate(e.target.value)}
                  />
                </Field>
              </div>

              {/* Items */}
              <div className="flex flex-col gap-2">
                <div className="flex items-center justify-between">
                  <Label>Ítems</Label>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={addLine}
                  >
                    <Plus className="mr-1.5 size-3.5" />
                    Agregar línea
                  </Button>
                </div>
                <div className="flex flex-col gap-2 rounded-md border p-2">
                  {lines.map((l) => (
                    <LineRow
                      key={l.rowId}
                      line={l}
                      onChange={(p) => updateLine(l.rowId, p)}
                      onRemove={() => removeLine(l.rowId)}
                    />
                  ))}
                </div>
              </div>

              {/* Pagos / descuento / nota */}
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Método de pago" id="paymentMethod">
                  <Select value={paymentMethod} onValueChange={setPaymentMethod}>
                    <SelectTrigger id="paymentMethod">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="cash">Efectivo</SelectItem>
                      <SelectItem value="card">Tarjeta</SelectItem>
                      <SelectItem value="transfer">Transferencia</SelectItem>
                      <SelectItem value="check">Cheque</SelectItem>
                      <SelectItem value="credit">A crédito</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
                <Field label="Descuento global" id="discount">
                  <Input
                    id="discount"
                    type="number"
                    min={0}
                    step="0.01"
                    value={discount}
                    onChange={(e) => setDiscount(e.target.value)}
                  />
                </Field>
              </div>

              <Field label="Nota / observaciones" id="note">
                <Textarea
                  id="note"
                  rows={2}
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder="Opcional"
                />
              </Field>

              {/* Totales */}
              <div className="rounded-md border bg-muted/30 p-3 text-sm">
                <Total label="Subtotal" value={formatMoney(totals.sub, bootstrap)} />
                <Total label="Impuestos" value={formatMoney(totals.tax, bootstrap)} />
                <Total
                  label="Descuento"
                  value={`- ${formatMoney(totals.discount, bootstrap)}`}
                />
                <div className="mt-2 flex items-center justify-between border-t pt-2 font-medium">
                  <span>Total</span>
                  <span className="tabular-nums">
                    {formatMoney(totals.total, bootstrap)}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <SheetFooter className="flex-row justify-end gap-2 border-t bg-background px-6 py-3">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              Cancelar
            </Button>
            <Button type="submit" disabled={!canSubmit || createPurchase.isPending}>
              {createPurchase.isPending && (
                <Loader2 className="mr-1.5 size-4 animate-spin" />
              )}
              Registrar compra
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  )
}

// ── Helpers JSX ─────────────────────────────────────────────────────────

function Field({
  label,
  id,
  children,
}: {
  label: string
  id?: string
  children: React.ReactNode
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
    </div>
  )
}

function Total({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between py-0.5 text-muted-foreground">
      <span>{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  )
}

// ── Línea de ítem ───────────────────────────────────────────────────────

interface LineRowProps {
  line: FormLine
  onChange: (patch: Partial<FormLine>) => void
  onRemove: () => void
}

function LineRow({ line, onChange, onRemove }: LineRowProps) {
  const { data: taxes } = useTaxes()
  const taxOptions = taxes?.taxes ?? []

  // Auto-calcular taxValue cuando cambian units/price/taxId.
  React.useEffect(() => {
    if (!line.taxId) return
    const t = taxOptions.find((tx) => tx.id === line.taxId)
    if (!t || t.rate === null) return
    const sub = (Number(line.units) || 0) * (Number(line.price) || 0)
    // tax incluido: rate% de sub. (Simplificación — el POS calcula con
    // formula compleja según taxIncluded, postponed para iteración).
    const rate = t.rate
    const calculated = (sub * rate) / (100 + rate)
    onChange({ taxValue: Number(calculated.toFixed(2)) })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [line.units, line.price, line.taxId])

  return (
    <div className="flex flex-col gap-2 rounded border bg-card p-2.5">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Switch
            checked={line.isProduct}
            onCheckedChange={(v) =>
              onChange({
                isProduct: v,
                // Limpiar el campo opuesto al toggle.
                itemId: v ? line.itemId : undefined,
                itemName: v ? line.itemName : undefined,
                title: v ? "" : line.title,
              })
            }
            id={`line-mode-${line.rowId}`}
          />
          <Label
            htmlFor={`line-mode-${line.rowId}`}
            className="text-xs font-medium uppercase tracking-wide text-muted-foreground"
          >
            {line.isProduct ? "Producto" : "Descripción libre"}
          </Label>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          onClick={onRemove}
          aria-label="Eliminar línea"
          className="size-7"
        >
          <Trash2 className="size-3.5 text-muted-foreground" />
        </Button>
      </div>

      <div className="grid grid-cols-12 gap-2">
        <div className="col-span-12 sm:col-span-5">
          {line.isProduct ? (
            <ProductPicker
              value={line.itemId ?? ""}
              displayName={line.itemName ?? ""}
              onChange={(id, name, defaultCost) =>
                onChange({
                  itemId: id,
                  itemName: name,
                  price: defaultCost ?? line.price,
                })
              }
            />
          ) : (
            <Input
              value={line.title ?? ""}
              onChange={(e) => onChange({ title: e.target.value })}
              placeholder="Descripción del gasto"
            />
          )}
        </div>
        <div className="col-span-4 sm:col-span-2">
          <Input
            type="number"
            min={0}
            step="0.001"
            value={line.units}
            onChange={(e) => onChange({ units: Number(e.target.value) })}
            placeholder="Uni."
            inputMode="decimal"
          />
        </div>
        <div className="col-span-4 sm:col-span-2">
          <Input
            type="number"
            min={0}
            step="0.01"
            value={line.price}
            onChange={(e) => onChange({ price: Number(e.target.value) })}
            placeholder="Precio"
            inputMode="decimal"
          />
        </div>
        <div className="col-span-4 sm:col-span-3">
          <Select
            value={line.taxId ?? ""}
            onValueChange={(v) => onChange({ taxId: v })}
          >
            <SelectTrigger>
              <SelectValue placeholder="Impuesto" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="0">Sin impuesto</SelectItem>
              {taxOptions.map((t) => (
                <SelectItem key={t.id} value={t.id}>
                  IVA {t.name}%
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
    </div>
  )
}

// ── Supplier picker (autocomplete + create inline) ────────────────────

function SupplierPicker({
  value,
  displayName,
  onChange,
}: {
  value: string
  displayName: string
  onChange: (id: string, name: string) => void
}) {
  const [open, setOpen] = React.useState(false)
  const [q, setQ] = React.useState("")
  const contacts = useContacts({ q, type: 2 })
  const createContact = useCreateContact()
  const [creatingName, setCreatingName] = React.useState<string | null>(null)

  const rows = contacts.data?.contacts ?? []

  const onSelect = (id: string, name: string) => {
    onChange(id, name)
    setOpen(false)
    setQ("")
  }

  const onCreate = async (name: string) => {
    setCreatingName(name)
    try {
      // Minimal ContactFormValues — kind=empresa con razón social = name. Para
      // un proveedor real se completaría desde /contacts; acá creamos el
      // mínimo viable para poder asociarlo a la compra y seguir editando.
      const res = await createContact.mutateAsync({
        values: {
          kind: "empresa",
          name: "",
          fiscalName: name,
          tin: "",
          ci: "",
          bday: "",
          phone: null,
          email: "",
          note: "",
          status: true,
        } as Parameters<typeof createContact.mutateAsync>[0]["values"],
        type: 2,
      })
      // ContactFull.id es el UUID del nuevo contacto.
      const newId = (res as { id?: string }).id
      if (newId) {
        onSelect(newId, name)
        toast.success(`Proveedor "${name}" creado`)
      }
    } catch (err) {
      toast.error("No se pudo crear el proveedor", {
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setCreatingName(null)
    }
  }

  return (
    <div className="flex flex-col gap-1.5">
      <Label>Proveedor</Label>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            role="combobox"
            className="justify-between font-normal"
          >
            {value && displayName ? (
              <span className="truncate">{displayName}</span>
            ) : (
              <span className="text-muted-foreground">Buscar o crear…</span>
            )}
            <Search className="ml-2 size-4 text-muted-foreground" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[--radix-popover-trigger-width] min-w-[260px] p-0" align="start">
          <Command shouldFilter={false}>
            <CommandInput
              placeholder="Buscar proveedor…"
              value={q}
              onValueChange={setQ}
            />
            <CommandList>
              <CommandEmpty>
                {q.trim() === "" ? (
                  <div className="py-4 text-sm text-muted-foreground">
                    Tipeá para buscar
                  </div>
                ) : creatingName === q.trim() ? (
                  <div className="flex items-center justify-center gap-2 py-4 text-sm text-muted-foreground">
                    <Loader2 className="size-4 animate-spin" /> Creando…
                  </div>
                ) : (
                  <button
                    type="button"
                    onClick={() => onCreate(q.trim())}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                  >
                    <Plus className="size-4" />
                    Crear proveedor <span className="font-medium">"{q.trim()}"</span>
                  </button>
                )}
              </CommandEmpty>
              <CommandGroup>
                {rows.map((r) => (
                  <CommandItem
                    key={r.id}
                    value={r.id}
                    onSelect={() => onSelect(r.id, r.name ?? "")}
                  >
                    <span className="flex-1 truncate">{r.name ?? "Sin nombre"}</span>
                  </CommandItem>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  )
}

// ── Product picker (autocomplete con productos del catálogo) ─────────

function ProductPicker({
  value,
  displayName,
  onChange,
}: {
  value: string
  displayName: string
  onChange: (id: string, name: string, defaultCost?: number) => void
}) {
  const [open, setOpen] = React.useState(false)
  const [q, setQ] = React.useState("")
  const items = useItems({ q })
  const rows = items.data?.items ?? []

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          className="w-full justify-between font-normal"
        >
          {value && displayName ? (
            <span className="truncate">{displayName}</span>
          ) : (
            <span className="text-muted-foreground">Buscar producto…</span>
          )}
          <Package className="ml-2 size-3.5 text-muted-foreground" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] min-w-[280px] p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput
            placeholder="Buscar por nombre o SKU…"
            value={q}
            onValueChange={setQ}
          />
          <CommandList>
            <CommandEmpty>
              {q.trim() === "" ? "Tipeá para buscar" : "Sin resultados"}
            </CommandEmpty>
            <CommandGroup>
              {rows.map((r) => {
                const cost = Number(r.itemCost) || 0
                return (
                  <CommandItem
                    key={r.itemId}
                    value={r.itemId}
                    onSelect={() => {
                      onChange(r.itemId, r.itemName, cost)
                      setOpen(false)
                      setQ("")
                    }}
                  >
                    <div className="flex w-full items-center justify-between gap-2">
                      <div className="min-w-0">
                        <div className="truncate">{r.itemName}</div>
                        {r.itemSKU && (
                          <div className="truncate text-xs text-muted-foreground">
                            {r.itemSKU}
                          </div>
                        )}
                      </div>
                      {cost > 0 && (
                        <div className="text-xs text-muted-foreground tabular-nums">
                          cost: {cost}
                        </div>
                      )}
                    </div>
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

function today(): string {
  const d = new Date()
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const dd = String(d.getDate()).padStart(2, "0")
  return `${yyyy}-${mm}-${dd}`
}
