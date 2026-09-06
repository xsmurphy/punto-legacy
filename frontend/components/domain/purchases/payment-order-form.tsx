"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { ArrowLeft, FileCheck, Loader2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import { MoneyInput } from "@/components/ui/money-input"
import { Textarea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { DatePicker } from "@/components/date-picker"
import { EmptyState } from "@/components/empty-state"
import { FormSection } from "@/components/forms/form-section"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useContacts } from "@/hooks/use-contacts"
import { useOutlets } from "@/hooks/use-outlets"
import {
  useCreatePaymentOrder,
  usePendingSupplierInvoices,
  useUpdatePaymentOrder,
  type PaymentOrderLineInput,
} from "@/hooks/use-payment-orders"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"

/**
 * Form de la ORDEN DE PAGO — compartido por el alta (`/ordenes-pago/new`) y la
 * edición del borrador (`/ordenes-pago/[id]/editar`).
 *
 * Es UN componente y no dos páginas parecidas a propósito: las reglas de qué se
 * puede tildar (una factura ya comprometida en otra orden no entra) y de cuánto
 * se puede imputar (nunca más que el saldo VIVO) son las mismas en los dos
 * casos, y el servidor las valida con el mismo `assertLinesPayable()`. Dos
 * copias del picker divergirían: una aprendería una regla nueva y la otra no.
 *
 * Lo que el modo `edit` NO deja cambiar es el proveedor y la sucursal —
 * `PaymentOrderService::update()` tampoco los acepta: el proveedor porque las
 * líneas dejarían de pertenecerle, y la sucursal porque el correlativo ya se
 * asignó contra esa secuencia (context/37).
 */

export interface PaymentOrderFormInitial {
  paymentOrderId: string
  supplierId: string
  outletId: string
  paymentDate: string | null
  notes: string | null
  lines: Array<{ transactionId: string; amount: number }>
}

interface Props {
  /** Ausente = alta. Presente = edición de un borrador. */
  initial?: PaymentOrderFormInitial
}

/** Estado de una fila tildada: monto a imputar (puede ser null mientras se tipea). */
type Selection = Record<string, number | null>

export function PaymentOrderForm({ initial }: Props) {
  const router = useRouter()
  const isEdit = initial !== undefined

  const { data: bootstrap } = useBootstrap()
  const { data: suppliersData } = useContacts({ type: 2 })
  const { data: outletsData } = useOutlets()

  const create = useCreatePaymentOrder()
  const update = useUpdatePaymentOrder()
  const pending = isEdit ? update : create

  const [supplierId, setSupplierId] = React.useState(initial?.supplierId ?? "")
  const [outletId, setOutletId] = React.useState(initial?.outletId ?? "")
  const [paymentDate, setPaymentDate] = React.useState(initial?.paymentDate ?? "")
  const [notes, setNotes] = React.useState(initial?.notes ?? "")
  const [selection, setSelection] = React.useState<Selection>(() => {
    const seed: Selection = {}
    for (const l of initial?.lines ?? []) seed[l.transactionId] = l.amount
    return seed
  })

  const { data: invoicesData, isLoading: loadingInvoices } = usePendingSupplierInvoices(
    supplierId || null,
    initial?.paymentOrderId,
  )
  const invoices = React.useMemo(() => invoicesData?.rows ?? [], [invoicesData])

  // Una sucursal sola no se elige: se preselecciona. El comercio de un solo
  // local no debería tener que contestar una pregunta con una sola respuesta.
  const outlets = React.useMemo(() => outletsData?.rows ?? [], [outletsData])
  React.useEffect(() => {
    if (!isEdit && outletId === "" && outlets.length === 1) setOutletId(outlets[0].id)
  }, [isEdit, outletId, outlets])

  // Cambiar de proveedor invalida lo tildado: las facturas son de otro. En el
  // modo edición el proveedor está fijo, así que este efecto no dispara.
  const prevSupplier = React.useRef(supplierId)
  React.useEffect(() => {
    if (prevSupplier.current !== supplierId) {
      prevSupplier.current = supplierId
      setSelection({})
    }
  }, [supplierId])

  const selectedIds = React.useMemo(
    () => Object.keys(selection).filter((id) => selection[id] !== undefined),
    [selection],
  )
  const total = React.useMemo(
    () => selectedIds.reduce((acc, id) => acc + (selection[id] ?? 0), 0),
    [selectedIds, selection],
  )

  function toggle(transactionId: string, debt: number, checked: boolean) {
    setSelection((prev) => {
      const next = { ...prev }
      // Tildar propone el saldo COMPLETO: pagar la factura entera es el caso
      // abrumadoramente normal, y el pago parcial se escribe encima.
      if (checked) next[transactionId] = debt
      else delete next[transactionId]
      return next
    })
  }

  function setAmount(transactionId: string, amount: number | null) {
    setSelection((prev) => ({ ...prev, [transactionId]: amount }))
  }

  async function handleSubmit() {
    if (!isEdit && !supplierId) {
      toast.error("Elegí el proveedor")
      return
    }
    if (!isEdit && !outletId) {
      toast.error("Elegí la sucursal")
      return
    }
    if (selectedIds.length === 0) {
      toast.error("Tildá al menos una factura")
      return
    }

    const lines: PaymentOrderLineInput[] = []
    for (const id of selectedIds) {
      const amount = selection[id]
      if (amount === null || amount <= 0) {
        toast.error("Cada factura tildada necesita un monto mayor a cero")
        return
      }
      const inv = invoices.find((i) => i.transactionId === id)
      // El servidor revalida contra el saldo VIVO —esta comprobación es solo
      // para no hacer ir y volver un pedido que ya sabemos que va a fallar.
      if (inv && amount > inv.debt) {
        toast.error(
          `El monto de la factura ${inv.invoiceNo || id} supera su saldo (${formatMoney(inv.debt, bootstrap)})`,
        )
        return
      }
      lines.push({ transactionId: id, amount })
    }

    try {
      if (isEdit) {
        await update.mutateAsync({
          id: initial.paymentOrderId,
          lines,
          paymentDate: paymentDate || null,
          notes: notes.trim() || null,
        })
        toast.success("Orden de pago actualizada")
        router.push(`/ordenes-pago/${initial.paymentOrderId}`)
      } else {
        const result = await create.mutateAsync({
          supplierId,
          outletId,
          lines,
          paymentDate: paymentDate || null,
          notes: notes.trim() || null,
        })
        toast.success(`Orden de pago N.º ${result.docNumber} creada`)
        router.push(`/ordenes-pago/${result.paymentOrderId}`)
      }
    } catch (err) {
      toast.error(
        isEdit ? "No se pudo actualizar la orden" : "No se pudo crear la orden",
        { description: err instanceof Error ? err.message : undefined },
      )
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" onClick={() => router.back()}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <h1 className="text-2xl font-semibold">
            {isEdit ? "Editar orden de pago" : "Nueva orden de pago"}
          </h1>
        </div>
        <p className="pl-10 text-sm text-muted-foreground">
          Agrupá las facturas pendientes del proveedor y el monto a imputar a cada una. La orden
          nace en borrador: no paga nada hasta que alguien con autoridad la apruebe.
        </p>
      </header>

      <FormSection title="Datos de la orden">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor="po-supplier">Proveedor</Label>
            <Select value={supplierId} onValueChange={setSupplierId} disabled={isEdit}>
              <SelectTrigger id="po-supplier">
                <SelectValue placeholder="Elegí el proveedor" />
              </SelectTrigger>
              <SelectContent>
                {(suppliersData?.contacts ?? []).map((c) => (
                  <SelectItem key={c.id} value={c.id}>
                    {c.name || c.fullname || "(sin nombre)"}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {isEdit ? (
              <p className="text-sm text-muted-foreground">
                El proveedor no se cambia: las facturas de la orden son suyas. Para pagarle a otro,
                armá una orden nueva.
              </p>
            ) : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="po-outlet">Sucursal</Label>
            <Select value={outletId} onValueChange={setOutletId} disabled={isEdit}>
              <SelectTrigger id="po-outlet">
                <SelectValue placeholder="Elegí la sucursal" />
              </SelectTrigger>
              <SelectContent>
                {outlets.map((o) => (
                  <SelectItem key={o.id} value={o.id}>
                    {o.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {isEdit ? (
              <p className="text-sm text-muted-foreground">
                La sucursal no se cambia: el número de la orden ya se tomó de su secuencia.
              </p>
            ) : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="po-date">Fecha de pago propuesta</Label>
            <DatePicker id="po-date" value={paymentDate} onChange={setPaymentDate} />
            <p className="text-sm text-muted-foreground">
              Cuándo hay que desembolsar. Es del documento, no de las facturas — cada una tiene su
              propio vencimiento.
            </p>
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="po-notes">Nota</Label>
            <Textarea
              id="po-notes"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Opcional"
              rows={3}
            />
          </div>
        </div>
      </FormSection>

      <FormSection
        title="Facturas pendientes"
        description="Tildá las que entran en esta orden. El monto propuesto es el saldo completo; escribilo si vas a pagar parcial."
      >
        {!supplierId ? (
          <EmptyState
            icon={FileCheck}
            title="Elegí un proveedor"
            description="Las facturas de compra a crédito con saldo aparecen acá una vez elegido el proveedor."
          />
        ) : loadingInvoices ? (
          <Skeleton className="h-40 w-full" />
        ) : invoices.length === 0 ? (
          <EmptyState
            icon={FileCheck}
            title="Sin facturas pendientes"
            description="Este proveedor no tiene compras a crédito con saldo. Registrá la compra primero."
          />
        ) : (
          <div className="rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-10" />
                  <TableHead>Comprobante</TableHead>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Vence</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead className="text-right">Saldo</TableHead>
                  <TableHead className="text-right">A pagar</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {invoices.map((inv) => {
                  const checked = selection[inv.transactionId] !== undefined
                  return (
                    <TableRow key={inv.transactionId}>
                      <TableCell>
                        <Checkbox
                          checked={checked}
                          disabled={inv.committed}
                          aria-label={`Incluir la factura ${inv.invoiceNo || inv.transactionId}`}
                          onCheckedChange={(v) => toggle(inv.transactionId, inv.debt, v === true)}
                        />
                      </TableCell>
                      <TableCell className="font-medium">
                        {inv.invoiceNo || "—"}
                        {inv.committed ? (
                          // Marcada, no escondida: si la factura desapareciera
                          // sin explicación, el usuario no entendería por qué
                          // no está una factura que sabe que existe.
                          <span className="block text-sm text-muted-foreground">
                            Ya está en la orden
                            {inv.committedDocNumber !== null ? ` N.º ${inv.committedDocNumber}` : ""}
                          </span>
                        ) : null}
                      </TableCell>
                      <TableCell>{inv.date ? formatDate(inv.date) : "—"}</TableCell>
                      <TableCell>{inv.dueDate ? formatDate(inv.dueDate) : "—"}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMoney(inv.total, bootstrap)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMoney(inv.debt, bootstrap)}
                      </TableCell>
                      <TableCell className="text-right">
                        <MoneyInput
                          className="ml-auto max-w-40 text-right"
                          aria-label={`Monto a pagar de la factura ${inv.invoiceNo || inv.transactionId}`}
                          value={checked ? (selection[inv.transactionId] ?? null) : null}
                          disabled={!checked}
                          onChange={(v) => setAmount(inv.transactionId, v)}
                        />
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
        )}
      </FormSection>

      <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted-foreground">
          {selectedIds.length} factura{selectedIds.length === 1 ? "" : "s"} ·{" "}
          <span className="font-medium text-foreground tabular-nums">
            {formatMoney(total, bootstrap)}
          </span>
        </p>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => router.back()} disabled={pending.isPending}>
            Cancelar
          </Button>
          <Button onClick={handleSubmit} disabled={pending.isPending}>
            {pending.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
            {isEdit ? "Guardar cambios" : "Crear orden"}
          </Button>
        </div>
      </div>
    </div>
  )
}
