"use client"

/**
 * TransactionEditDialog — edición de una transacción (venta/crédito/cotización)
 * desde el panel. Extraído de `PanelEditView` (transactions-list.tsx) al migrar
 * el detalle a la página dedicada `/transactions/{id}` (F2,
 * context/39-detalle-transaccion.md) — antes vivía embebido como una vista
 * alternativa del mismo Dialog que mostraba el detalle; ahora es su propio
 * Dialog, abierto desde el botón "Editar" de la página.
 */

import * as React from "react"
import { useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Textarea } from "@/components/ui/textarea"
import { Lock, X } from "lucide-react"

import { useContacts } from "@/hooks/use-contacts"
import { useOutlets } from "@/hooks/use-outlets"
import { useTeamMembers } from "@/hooks/use-team"
import { usePaymentMethods } from "@/hooks/use-payment-methods"
import { api } from "@/lib/api-client"
import type { TxDetailFull } from "@/hooks/use-reports"

interface TransactionEditDialogProps {
  transactionId: string
  detail: TxDetailFull
  open: boolean
  onOpenChange: (open: boolean) => void
}

type EditForm = {
  date: string
  dueDate: string
  note: string
  customerId: string
  outletId: string
  invoiceNo: string
  userId: string
  responsibleId: string
  transactionType: number
  payments: Array<{ type: string; name: string; total: number }>
  items: Array<{ itemSoldId: string; itemSoldUnits: number; itemSoldTotal: number; itemName: string }>
}

function initEditForm(detail: TxDetailFull): EditForm {
  const tx = detail.transaction
  const rawDate = tx.transactionDate ?? ""
  const date = rawDate.includes("T") ? rawDate.slice(0, 16) : rawDate.replace(" ", "T").slice(0, 16)
  const dueDate = tx.transactionDueDate ?? ""
  return {
    date,
    dueDate: dueDate.length >= 10 ? dueDate.slice(0, 10) : dueDate,
    note: tx.transactionNote ?? "",
    customerId: tx.customerId ?? "",
    outletId: tx.outletId ?? "",
    invoiceNo: tx.invoiceNo ? String(tx.invoiceNo) : "",
    userId: tx.userId ?? "",
    responsibleId: tx.responsibleId ?? "",
    transactionType: tx.transactionType,
    payments: (tx.transactionPaymentType ?? []).map((p) => ({ type: p.type, name: p.name, total: p.total })),
    items: detail.items.map((i) => ({
      itemSoldId: i.itemSoldId,
      itemSoldUnits: i.itemSoldUnits,
      itemSoldTotal: i.itemSoldTotal,
      itemName: i.itemName,
    })),
  }
}

export function TransactionEditDialog({ transactionId, detail, open, onOpenChange }: TransactionEditDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Editar transacción</DialogTitle>
        </DialogHeader>
        {/*
         * Radix desmonta `DialogContent` (y todo lo que cuelga de acá) al
         * cerrarse (sin `forceMount`) — montar el form SOLO cuando `open`
         * es true garantiza un `useState` inicializado fresco desde
         * `detail` en cada apertura, sin necesitar un useEffect que llame
         * setState (cascading renders, regla react-hooks/set-state-in-effect).
         */}
        {open && <EditDialogBody transactionId={transactionId} detail={detail} onOpenChange={onOpenChange} />}
      </DialogContent>
    </Dialog>
  )
}

function EditDialogBody({
  transactionId,
  detail,
  onOpenChange,
}: {
  transactionId: string
  detail: TxDetailFull
  onOpenChange: (open: boolean) => void
}) {
  const queryClient = useQueryClient()
  const [saving, setSaving] = React.useState(false)
  const [form, setForm] = React.useState<EditForm>(() => initEditForm(detail))

  const { data: contactsData } = useContacts({ type: 1 })
  const { data: outletsData } = useOutlets()
  const { data: teamData } = useTeamMembers({ status: "1" })
  // Métodos de pago del TENANT (realm panel — /v1/payment-methods), no del
  // device (useCatalogStore): el panel no hidrata el bootstrap del POS, así
  // que ese store queda vacío acá. Mismo criterio ya resuelto en
  // CreditPaymentDialog (ver su comentario) — se aplica también en este
  // formulario de edición para no reintroducir el mismo bug.
  const { data: pmData } = usePaymentMethods()
  const paymentMethods = pmData?.paymentMethods ?? []

  async function handleSave() {
    setSaving(true)
    try {
      const isQuote = detail.transaction.transactionType === 9
      const body = {
        date: form.date.replace("T", " ") + ":00",
        dueDate: form.dueDate || null,
        note: form.note || null,
        customerId: form.customerId || null,
        outletId: form.outletId || null,
        invoiceNo: form.invoiceNo ? Number(form.invoiceNo) : null,
        userId: form.userId || null,
        responsibleId: form.responsibleId || null,
        transactionType: form.transactionType,
        payments: isQuote ? [] : form.payments.map((p) => ({ type: p.type, total: p.total })),
        items: form.items.map((i) => ({
          itemSoldId: i.itemSoldId,
          itemSoldUnits: Number(i.itemSoldUnits),
          itemSoldTotal: i.itemSoldTotal,
        })),
        tags: detail.transaction.meta?.tags ?? [],
      }
      await api.put(`/v1/reports/transactions?id=${transactionId}`, body)
      toast.success("Transacción actualizada")
      queryClient.invalidateQueries({ queryKey: ["reports", "transactions"] })
      queryClient.invalidateQueries({ queryKey: ["transaction-detail", transactionId] })
      onOpenChange(false)
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Error al guardar"
      toast.error(msg)
    } finally {
      setSaving(false)
    }
  }

  function updatePayment(index: number, field: "type" | "name" | "total", value: string | number | null) {
    setForm((prev) => {
      const payments = prev.payments.map((p, i) => (i === index ? { ...p, [field]: value } : p))
      return { ...prev, payments }
    })
  }

  function removePayment(index: number) {
    setForm((prev) => ({ ...prev, payments: prev.payments.filter((_, i) => i !== index) }))
  }

  function addPayment() {
    const defaultMethod = paymentMethods[0]
    setForm((prev) => ({
      ...prev,
      payments: [
        ...prev.payments,
        { type: defaultMethod?.id ?? "", name: defaultMethod?.name ?? "", total: 0 },
      ],
    }))
  }

  function updateItem(index: number, field: "itemSoldUnits" | "itemSoldTotal", value: number | null) {
    setForm((prev) => {
      const items = prev.items.map((it, i) => (i === index ? { ...it, [field]: value } : it))
      return { ...prev, items }
    })
  }

  const tx = detail.transaction
  const isCredit = form.transactionType === 3
  const isQuote = tx.transactionType === 9

  /**
   * Factura emitida: contado (0) o crédito (3). Ya salió con timbrado, número y
   * un contenido declarado, así que nada de eso se toca — corregir una factura
   * emitida es emitir una nota de crédito, no editarla. El backend lo rechaza
   * igual; acá se bloquea para que no se llegue a intentar.
   *
   * La cotización no es documento fiscal: se sigue editando entera.
   */
  const esFiscal = !isQuote
  const canEditType = !esFiscal && (tx.transactionType === 0 || tx.transactionType === 3)
  const canEditItems = tx.transactionType === 0 || tx.transactionType === 3 || isQuote

  return (
    <>
      <div className="flex flex-col gap-5 py-2">
        {esFiscal && (
          <div className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
            <Lock className="mt-0.5 size-4 shrink-0 text-amber-600" />
            <p className="text-muted-foreground">
              Es una factura emitida: el cliente, los ítems, los importes, las formas
              de pago y la numeración no se pueden modificar. Para corregirla, emití
              una nota de crédito. Sí podés cambiar el vendedor, el responsable, el
              usuario de cada ítem, la nota y las etiquetas.
            </p>
          </div>
        )}
        {canEditType && (
          <div className="flex flex-col gap-1.5">
            <Label>Tipo</Label>
            <Select
              value={String(form.transactionType)}
              onValueChange={(v) => setForm((prev) => ({ ...prev, transactionType: Number(v) }))}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="0">Contado</SelectItem>
                <SelectItem value="3">Crédito</SelectItem>
              </SelectContent>
            </Select>
          </div>
        )}

        <div className="flex flex-col gap-1.5">
          <Label>Fecha</Label>
          <Input
            type="datetime-local"
            value={form.date}
            disabled={esFiscal}
            onChange={(e) => setForm((prev) => ({ ...prev, date: e.target.value }))}
          />
        </div>

        {isCredit && (
          <div className="flex flex-col gap-1.5">
            <Label>Fecha de vencimiento</Label>
            <Input
              type="date"
              value={form.dueDate}
              onChange={(e) => setForm((prev) => ({ ...prev, dueDate: e.target.value }))}
            />
          </div>
        )}

        <div className="flex flex-col gap-1.5">
          <Label>Nro. de documento</Label>
          <Input
            type="number"
            value={form.invoiceNo}
            disabled={esFiscal}
            onChange={(e) => setForm((prev) => ({ ...prev, invoiceNo: e.target.value }))}
            placeholder="—"
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <Label>Cliente</Label>
          <Select
            value={form.customerId}
            disabled={esFiscal}
            onValueChange={(v) => setForm((prev) => ({ ...prev, customerId: v }))}
          >
            <SelectTrigger>
              <SelectValue placeholder="Seleccionar cliente..." />
            </SelectTrigger>
            <SelectContent>
              {(contactsData?.contacts ?? []).map((c) => (
                <SelectItem key={c.id} value={c.id}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-1.5">
          <Label>Sucursal</Label>
          <Select
            value={form.outletId}
            disabled={esFiscal}
            onValueChange={(v) => setForm((prev) => ({ ...prev, outletId: v }))}
          >
            <SelectTrigger>
              <SelectValue placeholder="Seleccionar sucursal..." />
            </SelectTrigger>
            <SelectContent>
              {(outletsData?.rows ?? []).map((o) => (
                <SelectItem key={o.id} value={o.id}>
                  {o.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-1.5">
          <Label>Vendedor</Label>
          <Select
            value={form.userId || "__none__"}
            onValueChange={(v) => setForm((prev) => ({ ...prev, userId: v === "__none__" ? "" : v }))}
          >
            <SelectTrigger>
              <SelectValue placeholder="Seleccionar vendedor..." />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__none__">Sin asignar</SelectItem>
              {(teamData?.users ?? []).map((m) => (
                <SelectItem key={m.id} value={m.id}>
                  {m.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-1.5">
          <Label>Responsable</Label>
          <Select
            value={form.responsibleId || "__none__"}
            onValueChange={(v) => setForm((prev) => ({ ...prev, responsibleId: v === "__none__" ? "" : v }))}
          >
            <SelectTrigger>
              <SelectValue placeholder="Seleccionar responsable..." />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__none__">Sin asignar</SelectItem>
              {(teamData?.users ?? []).map((m) => (
                <SelectItem key={m.id} value={m.id}>
                  {m.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-1.5">
          <Label>Nota</Label>
          <Textarea
            value={form.note}
            onChange={(e) => setForm((prev) => ({ ...prev, note: e.target.value }))}
            rows={3}
          />
        </div>

        {canEditItems && form.items.length > 0 && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Items</p>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-20">Cant.</TableHead>
                  <TableHead>Articulo</TableHead>
                  <TableHead className="w-32">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {form.items.map((item, i) => (
                  <TableRow key={item.itemSoldId}>
                    <TableCell>
                      <Input
                        type="text"
                        inputMode="numeric"
                        value={item.itemSoldUnits}
                        disabled={esFiscal}
                        onChange={(e) => updateItem(i, "itemSoldUnits", Number(e.target.value))}
                        className="w-16"
                      />
                    </TableCell>
                    <TableCell className="text-sm">{item.itemName}</TableCell>
                    <TableCell>
                      <MoneyInput
                        value={item.itemSoldTotal}
                        disabled={esFiscal}
                        onChange={(v) => updateItem(i, "itemSoldTotal", v)}
                      />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </section>
        )}

        {!isQuote && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Pagos</p>
            <div className="flex flex-col gap-2">
              {form.payments.map((p, i) => (
                <div key={i} className="flex items-center gap-2">
                  {paymentMethods.length > 0 ? (
                    <Select
                      value={p.type}
                      disabled={esFiscal}
                      onValueChange={(v) => {
                        const m = paymentMethods.find((pm) => pm.id === v)
                        setForm((prev) => {
                          const payments = prev.payments.map((pay, idx) =>
                            idx === i ? { ...pay, type: v, name: m?.name ?? v } : pay,
                          )
                          return { ...prev, payments }
                        })
                      }}
                    >
                      <SelectTrigger className="flex-1">
                        <SelectValue placeholder="Método de pago..." />
                      </SelectTrigger>
                      <SelectContent>
                        {paymentMethods.map((m) => (
                          <SelectItem key={m.id} value={m.id}>
                            {m.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  ) : (
                    <span className="flex-1 text-sm px-3 py-2 rounded-md border border-border bg-muted/40 text-foreground">
                      {p.name || p.type.slice(0, 8)}
                    </span>
                  )}
                  <div className="w-32">
                    <MoneyInput value={p.total} onChange={(v) => updatePayment(i, "total", v)} />
                  </div>
                  <Button variant="ghost" size="icon" onClick={() => removePayment(i)} type="button">
                    <X className="size-4" />
                  </Button>
                </div>
              ))}
              <Button variant="outline" size="sm" className="w-fit" onClick={addPayment} type="button">
                + Agregar método
              </Button>
            </div>
          </section>
        )}
      </div>

      <DialogFooter>
        <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
          Cancelar
        </Button>
        <Button onClick={handleSave} disabled={saving}>
          {saving ? "Guardando..." : "Guardar"}
        </Button>
      </DialogFooter>
    </>
  )
}
