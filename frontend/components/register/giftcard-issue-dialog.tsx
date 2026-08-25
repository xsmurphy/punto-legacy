"use client"

/**
 * Dialog de EMISIÓN de gift card (F2 giftcard-issue-flow).
 *
 * Se abre al clickear un item de catálogo kind="giftcard" (ver
 * lib/cart/add-catalog-item.ts → useGiftcardIssueStore). Captura monto,
 * código (auto-generado, editable), beneficiario opcional y vencimiento
 * opcional, y recién ahí agrega la línea al carrito con `giftcard` metadata
 * (lib/cart/store.ts::CartLine.giftcard).
 *
 * NO confundir con el CANJE de una gift card existente como pago
 * (`giftcard-validation-dialog.tsx`, usado en pay-dialog.tsx) — ese es un
 * flujo totalmente distinto (débito de saldo, no emisión).
 *
 * Montado una sola vez en el layout del POS (mismo patrón que <PayDialog>).
 */

import * as React from "react"
import { Check, ChevronsUpDown, RefreshCw, X } from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Label } from "@/components/ui/label"
import { MoneyInput } from "@/components/ui/money-input"
import { DatePicker } from "@/components/date-picker"
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
import { cn } from "@/lib/utils"
import { useGiftcardIssueStore } from "@/lib/cart/giftcard-issue-store"
import { useCartStore } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { searchCustomers } from "@/lib/catalog/search"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"

/** Código alfanumérico corto, legible al dictarlo por teléfono (sin 0/O/1/I). */
function generateGiftcardCode(): string {
  const alphabet = "23456789ABCDEFGHJKMNPQRSTUVWXYZ"
  const bytes = new Uint8Array(8)
  crypto.getRandomValues(bytes)
  let out = ""
  for (const b of bytes) out += alphabet[b % alphabet.length]
  return `GC-${out}`
}

export function GiftcardIssueDialog() {
  const pendingItem = useGiftcardIssueStore((s) => s.pendingItem)
  const close = useGiftcardIssueStore((s) => s.close)
  const addLines = useCartStore((s) => s.addLines)
  const customers = useCatalogStore((s) => s.customers)

  const [amount, setAmount] = React.useState<number | null>(null)
  const [code, setCode] = React.useState("")
  const [expiresAt, setExpiresAt] = React.useState("")
  const [note, setNote] = React.useState("")
  const [beneficiary, setBeneficiary] = React.useState<PosCustomer | null>(null)
  const [benefQuery, setBenefQuery] = React.useState("")
  const [benefOpen, setBenefOpen] = React.useState(false)

  const open = pendingItem !== null

  React.useEffect(() => {
    if (open && pendingItem) {
      setAmount(pendingItem.price > 0 ? pendingItem.price : null)
      setCode(generateGiftcardCode())
      setExpiresAt("")
      setNote("")
      setBeneficiary(null)
      setBenefQuery("")
    }
  }, [open, pendingItem])

  const benefResults = React.useMemo(
    () => (benefQuery.trim() ? searchCustomers(customers, benefQuery, 15) : customers.slice(0, 15)),
    [customers, benefQuery],
  )

  const canConfirm = !!pendingItem && !!amount && amount > 0 && code.trim() !== ""

  function handleConfirm() {
    if (!pendingItem || !canConfirm || !amount) return
    addLines([
      {
        itemId: pendingItem.id,
        name: pendingItem.name,
        qty: 1,
        unitPrice: amount,
        giftcard: {
          code: code.trim(),
          beneficiaryContactId: beneficiary?.id ?? null,
          beneficiaryName: beneficiary?.name ?? null,
          expiresAt: expiresAt || null,
          note: note.trim() || null,
        },
      },
    ])
    close()
  }

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) close() }}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Emitir gift card</DialogTitle>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="gc-amount">Monto</Label>
            <MoneyInput id="gc-amount" value={amount} onChange={setAmount} autoFocus />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="gc-code">Código</Label>
            <div className="flex gap-2">
              <Input
                id="gc-code"
                value={code}
                onChange={(e) => setCode(e.target.value.toUpperCase())}
                // El campo es texto libre (el cajero puede borrar el
                // autogenerado y tipear otro) — maxLength espeja la columna
                // `giftcard.code VARCHAR(64)` (mig 44) para no depender de
                // que el backend trunque o rechace un insert con un mensaje
                // críptico. La unicidad real la garantiza el backend
                // (SaleService::issueGiftCard + índice único case-insensitive,
                // mig 125): este input no puede ser la única defensa.
                maxLength={64}
                className="flex-1 font-mono uppercase"
              />
              <Button
                type="button"
                variant="outline"
                size="icon"
                title="Generar otro código"
                aria-label="Generar otro código"
                onClick={() => setCode(generateGiftcardCode())}
              >
                <RefreshCw className="size-4" />
              </Button>
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label>Beneficiario (opcional)</Label>
            {beneficiary ? (
              <div className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                <span className="truncate font-medium">{beneficiary.name}</span>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  aria-label="Quitar beneficiario"
                  onClick={() => setBeneficiary(null)}
                >
                  <X className="size-3.5" />
                </Button>
              </div>
            ) : (
              <Popover open={benefOpen} onOpenChange={setBenefOpen}>
                <PopoverTrigger asChild>
                  <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={benefOpen}
                    className="w-full justify-between font-normal text-muted-foreground"
                  >
                    Buscar cliente…
                    <ChevronsUpDown className="size-4 opacity-50" />
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
                  <Command shouldFilter={false}>
                    <CommandInput
                      placeholder="Buscar por nombre, teléfono o RUC…"
                      value={benefQuery}
                      onValueChange={setBenefQuery}
                    />
                    <CommandList>
                      <CommandEmpty>Sin resultados.</CommandEmpty>
                      <CommandGroup>
                        {benefResults.map((c) => (
                          <CommandItem
                            key={c.id}
                            value={c.id}
                            onSelect={() => {
                              setBeneficiary(c)
                              setBenefOpen(false)
                            }}
                          >
                            <Check className={cn("mr-2 size-4 opacity-0")} />
                            <span className="truncate">{c.name}</span>
                          </CommandItem>
                        ))}
                      </CommandGroup>
                    </CommandList>
                  </Command>
                </PopoverContent>
              </Popover>
            )}
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="gc-expires">Vencimiento (opcional)</Label>
            <DatePicker id="gc-expires" value={expiresAt} onChange={setExpiresAt} />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="gc-note">Nota (opcional)</Label>
            {/* Comentario libre → Textarea, nunca un Input de una línea: en el
                POS todo campo de nota se escribe con el dedo y se relee antes
                de emitir (regla del owner 2026-08-25). */}
            <Textarea
              id="gc-note"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={3}
            />
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={close}>
            Cancelar
          </Button>
          <Button type="button" disabled={!canConfirm} onClick={handleConfirm}>
            Agregar al carrito
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
