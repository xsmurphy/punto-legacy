"use client"

import * as React from "react"
import { Loader2, Package, Plus, Search, Trash2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
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
import { useContacts, useCreateContact } from "@/hooks/use-contacts"
import { useItems } from "@/hooks/use-items"
import { useTaxes } from "@/hooks/use-taxes"
import { api } from "@/lib/api-client"
import { MoneyInput } from "@/components/ui/money-input"
import { formatMoney } from "@/lib/format"
import type { Bootstrap } from "@/lib/types/bootstrap"
import type { PurchaseFormItem } from "@/hooks/use-purchases"

/**
 * Piezas compartidas del form de compra (`/purchase` — alta manual, y
 * `/purchase/drafts/[id]` — revisión de borradores OCR/IA). Extraído de
 * `/purchase/page.tsx` para no duplicar los combobox de proveedor/producto
 * (búsqueda async + alta inline) ni el cálculo de impuesto por línea — la
 * pantalla de revisión reusa el MISMO form, no una reimplementación.
 */

export interface FormLine extends Omit<PurchaseFormItem, "price"> {
  rowId: string
  isProduct: boolean
  itemName?: string
  /** null mientras el usuario aún no tipea — MoneyInput lo maneja como vacío. */
  price: number | null
}

export function makeRowId(): string {
  return Math.random().toString(36).slice(2, 10)
}

export function emptyLine(isProduct = true, taxId = ""): FormLine {
  return {
    rowId: makeRowId(),
    isProduct,
    units: 1,
    price: null,
    taxId,
    taxValue: 0,
    packSize: 1,
  }
}

export function Field({
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

export function Total({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between py-0.5 text-muted-foreground">
      <span>{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  )
}

export interface LineRowProps {
  line: FormLine
  isLast: boolean
  onChange: (patch: Partial<FormLine>) => void
  onRemove: () => void
  onTabFromTax: () => void
  registerFirstField: (el: HTMLElement | null) => void
  bootstrap?: Pick<Bootstrap, "currency" | "decimal" | "thousand">
}

export function LineRow({
  line,
  isLast,
  onChange,
  onRemove,
  onTabFromTax,
  registerFirstField,
  bootstrap,
}: LineRowProps) {
  const { data: taxes } = useTaxes()
  const taxOptions = taxes?.taxes ?? []

  // Línea sin impuesto elegido → default al PRIMER impuesto del tenant.
  // La lista viene ordenada por sortOrder (drag&drop en Settings → Catálogo →
  // Impuestos), así el comercio decide cuál es su default arrastrándolo arriba.
  // No pisa nada explícito: taxId "0" ("Sin impuesto") o un id real son truthy.
  const firstTaxId = taxOptions[0]?.id
  React.useEffect(() => {
    if (line.taxId || !firstTaxId) return
    onChange({ taxId: firstTaxId })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [line.taxId, firstTaxId])

  React.useEffect(() => {
    if (!line.taxId) return
    const t = taxOptions.find((tx) => tx.id === line.taxId)
    if (!t || t.rate === null) return
    const sub = (Number(line.units) || 0) * (line.price ?? 0)
    const rate = t.rate
    const calculated = (sub * rate) / (100 + rate)
    onChange({ taxValue: Number(calculated.toFixed(2)) })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [line.units, line.price, line.taxId])

  // Tab desde el SelectTrigger de Impuesto en la ÚLTIMA línea → crea una
  // nueva línea en lugar de salir del form. Shift+Tab usa el back-nav default.
  const onTaxKeyDown = (e: React.KeyboardEvent<HTMLButtonElement>) => {
    if (!isLast) return
    if (e.key !== "Tab" || e.shiftKey) return
    e.preventDefault()
    onTabFromTax()
  }

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
        <div
          className={
            line.isProduct
              ? "col-span-12 sm:col-span-3"
              : "col-span-12 sm:col-span-5"
          }
        >
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
              triggerRef={registerFirstField}
            />
          ) : (
            <Input
              ref={(el) => registerFirstField(el)}
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
        {line.isProduct && (
          <div className="col-span-4 sm:col-span-2">
            <Input
              type="number"
              min={1}
              step="1"
              value={line.packSize ?? 1}
              onChange={(e) =>
                onChange({ packSize: Math.max(1, Math.round(Number(e.target.value) || 1)) })
              }
              placeholder="U x paq."
              inputMode="numeric"
              aria-label="Unidades por paquete/caja"
            />
          </div>
        )}
        <div className="col-span-4 sm:col-span-2">
          <MoneyInput
            value={line.price}
            onChange={(v) => onChange({ price: v })}
            placeholder="Precio"
          />
          {line.isProduct && (line.packSize ?? 1) > 1 && line.price !== null && (
            <p className="mt-1 text-xs text-muted-foreground">
              {formatMoney(line.price / (line.packSize ?? 1), bootstrap)} c/u
            </p>
          )}
        </div>
        <div className="col-span-4 sm:col-span-3">
          <Select
            value={line.taxId ?? ""}
            onValueChange={(v) => onChange({ taxId: v })}
          >
            <SelectTrigger onKeyDown={onTaxKeyDown}>
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

export function SupplierPicker({
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

export function ProductPicker({
  value,
  displayName,
  onChange,
  triggerRef,
}: {
  value: string
  displayName: string
  onChange: (id: string, name: string, defaultCost?: number) => void
  triggerRef?: (el: HTMLElement | null) => void
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
          ref={triggerRef}
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
              {rows.map((r) => (
                <CommandItem
                  key={r.itemId}
                  value={r.itemId}
                  onSelect={async () => {
                    setOpen(false)
                    setQ("")
                    // Setear nombre primero con precio 0; el último precio de
                    // compra real llega en el segundo update tras el fetch.
                    onChange(r.itemId, r.itemName, 0)
                    try {
                      const { price } = await api.get<{ price: number }>(
                        `/v1/items?id=${r.itemId}&resource=last-purchase-price`,
                      )
                      onChange(r.itemId, r.itemName, price || 0)
                    } catch {
                      // Si falla el lookup, queda en 0 (estado seteado arriba).
                    }
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
                  </div>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
