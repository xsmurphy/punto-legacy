"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { ArrowLeft, Loader2, Plus, Search, Trash2, Package } from "lucide-react"
import { toast } from "sonner"

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
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useContacts, useCreateContact } from "@/hooks/use-contacts"
import { useItems } from "@/hooks/use-items"
import { useTaxes } from "@/hooks/use-taxes"
import { useCreatePurchase, type PurchaseFormItem } from "@/hooks/use-purchases"
import { formatMoney } from "@/lib/format"
import { DatePicker } from "@/components/date-picker"

/**
 * `/purchase` — registro de compra/gasto. Full-page (NO drawer/sheet).
 *
 * Espejo funcional de `panel/a_purchase.php`. Layout 2-col en desktop:
 * columna izquierda con datos generales (proveedor, sucursal, fechas,
 * factura, pago, descuento, nota); columna derecha con líneas de items
 * + totales + acciones. En mobile el stack es vertical (datos arriba,
 * items abajo).
 *
 * Submit OK / Cancelar → navega a /reports/purchases (historial).
 */

interface FormLine extends PurchaseFormItem {
  rowId: string
  isProduct: boolean
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

function today(): string {
  const d = new Date()
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const dd = String(d.getDate()).padStart(2, "0")
  return `${yyyy}-${mm}-${dd}`
}

export default function NewPurchasePage() {
  const router = useRouter()
  const { data: bootstrap } = useBootstrap()
  const createPurchase = useCreatePurchase()

  const [supplierId, setSupplierId] = React.useState("")
  const [supplierName, setSupplierName] = React.useState("")
  const [outletId, setOutletId] = React.useState("")
  const [invoiceDate, setInvoiceDate] = React.useState<string>(today())
  const [dueDate, setDueDate] = React.useState<string>(today())
  const [authNo, setAuthNo] = React.useState("")
  const [invoicePrefix, setInvoicePrefix] = React.useState("")
  const [invoiceNo, setInvoiceNo] = React.useState("")
  const [paymentMethod, setPaymentMethod] = React.useState("cash")
  const [discount, setDiscount] = React.useState("")
  const [note, setNote] = React.useState("")
  const [lines, setLines] = React.useState<FormLine[]>([emptyLine()])

  // Setear outlet por default cuando llega el bootstrap.
  React.useEffect(() => {
    if (!outletId && bootstrap?.activeOutletId) {
      setOutletId(bootstrap.activeOutletId)
    }
  }, [bootstrap?.activeOutletId, outletId])

  const outlets = bootstrap?.outlets ?? []

  // Totales reactivos
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
      router.push("/reports/purchases")
    } catch (err) {
      toast.error("No se pudo registrar la compra", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4">
      {/* Header con breadcrumb + acciones */}
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <Button
            asChild
            variant="ghost"
            size="sm"
            className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
          >
            <Link href="/reports/purchases">
              <ArrowLeft className="size-3.5" />
              Volver al historial
            </Link>
          </Button>
          <h1 className="text-2xl font-semibold">Nueva compra</h1>
          <p className="text-sm text-muted-foreground">
            Registro de factura de compra a un proveedor.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => router.push("/reports/purchases")}
          >
            Cancelar
          </Button>
          <Button type="submit" disabled={!canSubmit || createPurchase.isPending}>
            {createPurchase.isPending && (
              <Loader2 className="mr-1.5 size-4 animate-spin" />
            )}
            Registrar compra
          </Button>
        </div>
      </header>

      {/* Layout 2-col: izquierda datos generales, derecha items + totales */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[320px_1fr]">
        {/* ── Columna izquierda — datos generales ───────────────────────── */}
        <aside className="flex flex-col gap-5 rounded-lg border bg-card p-4">
          <SupplierPicker
            value={supplierId}
            displayName={supplierName}
            onChange={(id, name) => {
              setSupplierId(id)
              setSupplierName(name)
            }}
          />

          <Field label="Sucursal" id="outlet">
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
          </Field>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Fecha factura" id="invoiceDate">
              <DatePicker
                id="invoiceDate"
                value={invoiceDate}
                onChange={setInvoiceDate}
              />
            </Field>
            <Field label="Vencimiento" id="dueDate">
              <DatePicker
                id="dueDate"
                value={dueDate}
                onChange={setDueDate}
              />
            </Field>
          </div>

          <div className="flex flex-col gap-3 rounded-md border bg-background/40 p-3">
            <div className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
              Datos de factura
            </div>
            <Field label="Timbrado / Auth" id="authNo">
              <Input
                id="authNo"
                value={authNo}
                onChange={(e) => setAuthNo(e.target.value)}
                placeholder="Opcional"
              />
            </Field>
            <div className="grid grid-cols-2 gap-2">
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
          </div>

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

          <Field label="Nota / observaciones" id="note">
            <Textarea
              id="note"
              rows={2}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="Opcional"
            />
          </Field>
        </aside>

        {/* ── Columna derecha — items + totales ─────────────────────────── */}
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-2 rounded-lg border bg-card p-4">
            <div className="flex items-center justify-between">
              <Label className="text-base font-semibold">Ítems</Label>
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
            <div className="flex flex-col gap-2">
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

          {/* Totales */}
          <div className="rounded-lg border bg-card p-4 text-sm">
            <Total label="Subtotal" value={formatMoney(totals.sub, bootstrap)} />
            <Total label="Impuestos" value={formatMoney(totals.tax, bootstrap)} />
            <Total
              label="Descuento"
              value={`- ${formatMoney(totals.discount, bootstrap)}`}
            />
            <div className="mt-2 flex items-center justify-between border-t pt-2 text-base font-semibold">
              <span>Total</span>
              <span className="tabular-nums">
                {formatMoney(totals.total, bootstrap)}
              </span>
            </div>
          </div>
        </div>
      </div>
    </form>
  )
}

// ── Sub-componentes ──────────────────────────────────────────────────────

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

interface LineRowProps {
  line: FormLine
  onChange: (patch: Partial<FormLine>) => void
  onRemove: () => void
}

function LineRow({ line, onChange, onRemove }: LineRowProps) {
  const { data: taxes } = useTaxes()
  const taxOptions = taxes?.taxes ?? []

  React.useEffect(() => {
    if (!line.taxId) return
    const t = taxOptions.find((tx) => tx.id === line.taxId)
    if (!t || t.rate === null) return
    const sub = (Number(line.units) || 0) * (Number(line.price) || 0)
    const rate = t.rate
    const calculated = (sub * rate) / (100 + rate)
    onChange({ taxValue: Number(calculated.toFixed(2)) })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [line.units, line.price, line.taxId])

  return (
    <div className="flex flex-col gap-2 rounded border bg-background/40 p-2.5">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Switch
            checked={line.isProduct}
            onCheckedChange={(v) =>
              onChange({
                isProduct: v,
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
        <PopoverContent
          className="w-[--radix-popover-trigger-width] min-w-[260px] p-0"
          align="start"
        >
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
                    Crear proveedor{" "}
                    <span className="font-medium">"{q.trim()}"</span>
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
                    <span className="flex-1 truncate">
                      {r.name ?? "Sin nombre"}
                    </span>
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
      <PopoverContent
        className="w-[--radix-popover-trigger-width] min-w-[280px] p-0"
        align="start"
      >
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
