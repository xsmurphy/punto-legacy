"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { ArrowLeft, Loader2, Plus, Upload, FileText } from "lucide-react"
import { toast } from "sonner"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card } from "@/components/ui/card"
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
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useCreatePurchase, type PurchaseFormItem } from "@/hooks/use-purchases"
import { usePaymentMethods } from "@/hooks/use-payment-methods"
import { useUploadInvoice, usePendingDraftsCount } from "@/hooks/use-purchase-drafts"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import type { PurchaseCreatePayload } from "@/hooks/use-purchases"
import { formatMoney } from "@/lib/format"
import { DatePicker } from "@/components/date-picker"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Field,
  Total,
  LineRow,
  SupplierPicker,
  emptyLine,
  type FormLine,
} from "@/components/domain/purchases/purchase-form-fields"
import { SupplierDocumentFields } from "@/components/domain/purchases/supplier-document-fields"

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
 *
 * Los combobox de proveedor/producto, la fila de línea (`LineRow`) y los
 * helpers `Field`/`Total`/`emptyLine`/`FormLine` viven en
 * `components/domain/purchases/purchase-form-fields.tsx` — compartidos con
 * `/purchase/drafts/[id]` (pantalla de revisión de borradores OCR/IA), que
 * reusa este MISMO form en vez de reimplementarlo.
 */

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
  // Condición de la factura. 'credit' → la compra queda PENDIENTE (type 4):
  // entra en Cuentas por pagar y Previsiones, y no mueve plata al crearse.
  const [condition, setCondition] = React.useState<"cash" | "credit">("cash")
  const [authNo, setAuthNo] = React.useState("")
  const [authNoDueDate, setAuthNoDueDate] = React.useState("")
  const [invoicePrefix, setInvoicePrefix] = React.useState("")
  const [invoiceNo, setInvoiceNo] = React.useState("")
  const [paymentMethodId, setPaymentMethodId] = React.useState("")
  const [checkNumber, setCheckNumber] = React.useState("")
  const [checkBank, setCheckBank] = React.useState("")
  const [checkDueDate, setCheckDueDate] = React.useState<string>("")
  const [discount, setDiscount] = React.useState<number | null>(null)
  const [note, setNote] = React.useState("")
  const [lines, setLines] = React.useState<FormLine[]>([emptyLine()])

  // Setear outlet por default cuando llega el bootstrap.
  React.useEffect(() => {
    if (!outletId && bootstrap?.activeOutletId) {
      setOutletId(bootstrap.activeOutletId)
    }
  }, [bootstrap?.activeOutletId, outletId])

  const outlets = bootstrap?.outlets ?? []

  // Medios de pago reales del tenant (taxonomy paymentMethod) — mismo catálogo
  // que ventas/POS, resuelve cuenta vía finAccountMap (Parte 2, context/30).
  const { data: paymentMethodsData } = usePaymentMethods()
  const paymentMethods = paymentMethodsData?.paymentMethods ?? []
  const isCredit = condition === "credit"
  const selectedMethod = paymentMethods.find((m) => m.id === paymentMethodId)
  // A crédito no se elige método de pago (no hay pago al crear), así que el
  // bloque de cheque tampoco aplica — sin este `!isCredit` quedaba visible el
  // form de cheque y su validación bloqueaba el submit.
  const isCheckMethod = !isCredit && selectedMethod?.systemKey === "check"

  // Default: primer método (sortOrder) al cargar el catálogo, si no hay elegido.
  React.useEffect(() => {
    if (!paymentMethodId && paymentMethods.length > 0) {
      const sorted = [...paymentMethods].sort((a, b) => (a.sortOrder ?? 999) - (b.sortOrder ?? 999))
      setPaymentMethodId(sorted[0].id)
    }
  }, [paymentMethodId, paymentMethods])

  // Totales reactivos
  const totals = React.useMemo(() => {
    let sub = 0
    let tax = 0
    for (const l of lines) {
      const u = Number(l.units) || 0
      const p = l.price ?? 0
      sub += Math.abs(u * p)
      tax += Number(l.taxValue) || 0
    }
    const disc = discount ?? 0
    return { sub, tax, discount: disc, total: sub - disc }
  }, [lines, discount])

  // Refs por línea para enfocar el primer campo editable cuando se crea una
  // nueva (vía Tab desde Impuesto). Map en lugar de array porque las líneas
  // se identifican por rowId, no por índice.
  const firstFieldRefs = React.useRef<Map<string, HTMLElement>>(new Map())
  const registerFirstField = React.useCallback((rowId: string, el: HTMLElement | null) => {
    if (el) firstFieldRefs.current.set(rowId, el)
    else firstFieldRefs.current.delete(rowId)
  }, [])
  // rowId pendiente de focusear tras un re-render (sino el ref del nuevo row
  // todavía no está registrado en el momento del setLines).
  const pendingFocusRef = React.useRef<string | null>(null)
  React.useLayoutEffect(() => {
    const id = pendingFocusRef.current
    if (!id) return
    const el = firstFieldRefs.current.get(id)
    if (el) {
      el.focus()
      pendingFocusRef.current = null
    }
  })

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
  // Nueva línea hereda taxId e isProduct (modo descripción) de la última.
  // Si no hay líneas previas, default a producto + sin impuesto.
  const addLine = () => {
    setLines((curr) => {
      const last = curr[curr.length - 1]
      const next = emptyLine(last ? last.isProduct : true, last ? (last.taxId ?? "") : "")
      pendingFocusRef.current = next.rowId
      return [...curr, next]
    })
  }

  const canSubmit =
    outletId !== "" &&
    lines.some(
      (l) =>
        (l.isProduct ? !!l.itemId : (l.title ?? "").trim() !== "") &&
        (Number(l.units) || 0) > 0,
    )

  // Resetea el form para cargar otra factura sin salir de la página. Conserva
  // outletId (suelen cargar un lote de la misma sucursal). Enfoca la primera
  // línea para que la carga siga fluida con el teclado.
  const resetForm = () => {
    setSupplierId("")
    setSupplierName("")
    setInvoiceDate(today())
    setDueDate(today())
    setAuthNo("")
    setAuthNoDueDate("")
    setInvoicePrefix("")
    setInvoiceNo("")
    setCheckNumber("")
    setCheckBank("")
    setCheckDueDate("")
    // paymentMethodId se conserva (el lote de compras suele repetir método).
    setDiscount(null)
    setNote("")
    const fresh = emptyLine()
    pendingFocusRef.current = fresh.rowId
    setLines([fresh])
  }

  /** Compra armada y validada, esperando confirmación. null = sin diálogo. */
  const [pendiente, setPendiente] = React.useState<PurchaseCreatePayload | null>(null)

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
        price: l.price ?? 0,
        taxId: l.taxId || undefined,
        taxValue: Number(l.taxValue) || 0,
        packSize: l.isProduct ? Math.max(1, Math.round(Number(l.packSize) || 1)) : undefined,
      }))

    if (isCheckMethod && checkNumber.trim() === "") {
      toast.error("Ingresá el número de cheque.")
      return
    }

    // A crédito el vencimiento es obligatorio: sin él la compra no aparece en
    // Previsiones (el backend también lo rechaza con 422).
    if (isCredit && dueDate.trim() === "") {
      toast.error("Elegí el vencimiento: es obligatorio en una compra a crédito.")
      return
    }

    // Registrar una compra mueve stock y deuda con el proveedor, y el form se
    // resetea de inmediato para cargar la siguiente factura — no hay una
    // pantalla intermedia donde darse cuenta del error. Se confirma antes.
    // El payload se congela ACÁ y el diálogo emite exactamente esto: si se
    // reconstruyera al confirmar, lo que se envía podría no ser lo que se
    // mostró.
    setPendiente({
        supplierId: supplierId || null,
        outletId,
        condition,
        invoiceDate,
        // Al contado no viaja vencimiento: la compra se paga en el momento y
        // guardar un transactionDueDate ahí es dato falso (aparecería con
        // fecha de corte en cualquier lectura de vencimientos).
        dueDate: isCredit ? dueDate : "",
        invoiceNo: invoiceNo || null,
        invoicePrefix,
        authNo,
        authNoDueDate: authNo.trim() !== "" ? authNoDueDate || undefined : undefined,
        // A crédito no se manda método: el pago nace después (pago a proveedor).
        paymentMethodId: isCredit ? undefined : paymentMethodId || undefined,
        ...(isCheckMethod
          ? {
              checkNumber: checkNumber.trim(),
              checkBank: checkBank.trim() || undefined,
              checkDueDate: checkDueDate || undefined,
            }
          : {}),
        discount: discount ?? 0,
        note,
        items,
    })
  }

  /** Emite la compra ya confirmada. */
  async function registrar() {
    if (!pendiente) return
    try {
      await createPurchase.mutateAsync(pendiente)
      // Carga de alto volumen: NO navegamos. Reseteamos el form para cargar la
      // siguiente factura de inmediato. La sucursal se conserva (suelen cargar
      // un lote de la misma); el resto vuelve a default.
      toast.success("Compra registrada — cargá la siguiente")
      setPendiente(null)
      resetForm()
    } catch (err) {
      // El diálogo se cierra igual: el error va al toast y el form conserva los
      // datos para corregir y reintentar.
      setPendiente(null)
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
          <DraftsLink />
          <UploadInvoiceButton outletId={outletId} />
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

      <AlertDialog open={pendiente !== null} onOpenChange={(o) => { if (!o) setPendiente(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Registrar esta compra?</AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div className="space-y-2">
                <p>
                  Va a sumar el stock de los ítems y, si es a crédito, generar la deuda
                  con el proveedor.
                </p>
                <div className="rounded-md border p-3 text-sm text-foreground">
                  <div className="flex justify-between gap-4">
                    <span className="text-muted-foreground">Proveedor</span>
                    <span className="text-right">{supplierName || "Sin proveedor"}</span>
                  </div>
                  <div className="flex justify-between gap-4">
                    <span className="text-muted-foreground">Condición</span>
                    <span>{isCredit ? "Crédito" : "Contado"}</span>
                  </div>
                  <div className="flex justify-between gap-4">
                    <span className="text-muted-foreground">Ítems</span>
                    <span className="tabular-nums">{pendiente?.items.length ?? 0}</span>
                  </div>
                  <div className="mt-1 flex justify-between gap-4 border-t pt-1 font-medium">
                    <span>Total</span>
                    <span className="tabular-nums">{formatMoney(totals.total, bootstrap)}</span>
                  </div>
                </div>
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={createPurchase.isPending}>Volver a revisar</AlertDialogCancel>
            <AlertDialogAction
              disabled={createPurchase.isPending}
              onClick={(e) => { e.preventDefault(); void registrar() }}
            >
              {createPurchase.isPending && <Loader2 className="mr-1.5 size-4 animate-spin" />}
              Registrar compra
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Layout 2-col: izquierda datos generales, derecha items + totales */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[320px_1fr]">
        {/* ── Columna izquierda — datos generales ───────────────────────── */}
        {/* Card soft: fondo gris unificado de cards angostas (context/20 §cards).
            Los campos de adentro suben a fondo sólido por la regla de globals.css
            que cuelga de data-variant="soft" — no se pinta input por input. */}
        <Card variant="soft" className="gap-5 p-4">
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

          <Field label="Condición" id="condition">
            <Select
              value={condition}
              onValueChange={(v) => setCondition(v as "cash" | "credit")}
            >
              <SelectTrigger id="condition">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="cash">Contado</SelectItem>
                <SelectItem value="credit">Crédito</SelectItem>
              </SelectContent>
            </Select>
            {isCredit && (
              <p className="text-xs text-muted-foreground">
                Queda pendiente en Cuentas por pagar hasta que registres el pago.
              </p>
            )}
          </Field>

          {/* Método de pago pegado a Condición (owner 2026-08-08): es dato de
              primera pasada, no puede quedar debajo de los datos de factura.
              Solo aplica al contado — a crédito no hay pago al crear la compra,
              se registra después como pago a proveedor. */}
          {!isCredit && (
            <Field label="Método de pago" id="paymentMethod">
              <Select value={paymentMethodId} onValueChange={setPaymentMethodId}>
                <SelectTrigger id="paymentMethod">
                  <SelectValue placeholder="Seleccionar método" />
                </SelectTrigger>
                <SelectContent>
                  {paymentMethods.map((m) => (
                    <SelectItem key={m.id} value={m.id}>
                      {m.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
          )}

          {/* Vencimiento SOLO en crédito: una compra al contado se paga en el
              momento, no vence (owner 2026-08-08). Al contado la fecha de
              factura ocupa el ancho completo en vez de dejar un hueco. */}
          <div className={cn("grid gap-3", isCredit && "grid-cols-2")}>
            <Field label="Fecha factura" id="invoiceDate">
              <DatePicker
                id="invoiceDate"
                value={invoiceDate}
                onChange={setInvoiceDate}
              />
            </Field>
            {isCredit && (
              <Field label="Vencimiento *" id="dueDate">
                <DatePicker
                  id="dueDate"
                  value={dueDate}
                  onChange={setDueDate}
                />
              </Field>
            )}
          </div>

          <SupplierDocumentFields
            value={{ prefix: invoicePrefix, no: invoiceNo, authNo, authNoDueDate }}
            onChange={(patch) => {
              if (patch.prefix !== undefined) setInvoicePrefix(patch.prefix)
              if (patch.no !== undefined) setInvoiceNo(patch.no)
              if (patch.authNo !== undefined) setAuthNo(patch.authNo)
              if (patch.authNoDueDate !== undefined) setAuthNoDueDate(patch.authNoDueDate)
            }}
          />

          {/* Cheque emitido: banco/nro/vencimiento — nace el fin_check (F1, context/30). */}
          {isCheckMethod && (
            <div className="flex flex-col gap-3 rounded-md border bg-background/40 p-3">
              <div className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                Datos del cheque
              </div>
              <Field label="Número de cheque" id="checkNumber">
                <Input
                  id="checkNumber"
                  value={checkNumber}
                  onChange={(e) => setCheckNumber(e.target.value)}
                  placeholder="Ej. 001234"
                />
              </Field>
              <div className="grid grid-cols-2 gap-2">
                <Field label="Banco" id="checkBank">
                  <Input
                    id="checkBank"
                    value={checkBank}
                    onChange={(e) => setCheckBank(e.target.value)}
                    placeholder="Opcional"
                  />
                </Field>
                <Field label="Vencimiento" id="checkDueDate">
                  <DatePicker
                    id="checkDueDate"
                    value={checkDueDate}
                    onChange={setCheckDueDate}
                    placeholder="Opcional"
                  />
                </Field>
              </div>
            </div>
          )}

          <Field label="Descuento global" id="discount">
            <MoneyInput
              id="discount"
              value={discount}
              onChange={setDiscount}
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
        </Card>

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
              {lines.map((l, idx) => (
                <LineRow
                  key={l.rowId}
                  line={l}
                  isLast={idx === lines.length - 1}
                  onChange={(p) => updateLine(l.rowId, p)}
                  onRemove={() => removeLine(l.rowId)}
                  onTabFromTax={addLine}
                  registerFirstField={(el) => registerFirstField(l.rowId, el)}
                  bootstrap={bootstrap}
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

/** Link a la cola de revisión de borradores OCR, con badge de pendientes. */
function DraftsLink() {
  const pending = usePendingDraftsCount()
  return (
    <Button asChild variant="outline" size="default" className="relative">
      <Link href="/purchase/drafts">
        <FileText className="mr-1.5 size-4" />
        Borradores
        {pending > 0 && (
          <Badge
            variant="secondary"
            className="ml-1.5 h-5 min-w-5 justify-center rounded-full px-1 tabular-nums"
          >
            {pending}
          </Badge>
        )}
      </Link>
    </Button>
  )
}

/**
 * Botón "Subir factura" — dispara un `<input type=file multiple>` oculto.
 * Acepta foto (JPG/PNG/WEBP) o PDF — un PDF entero (aunque tenga varias
 * páginas) se manda tal cual al modelo, sin convertir a imagen. Cada archivo
 * seleccionado crea UN borrador (una factura = un borrador = una compra al
 * aprobar) vía `/api/ocr-invoice` (extracción IA + creación del draft). Sube
 * secuencial para no saturar créditos/red con selecciones grandes, y reporta
 * el resultado agregado al terminar. Navega a `/purchase/drafts` para que el
 * usuario revise lo recién subido.
 */
function UploadInvoiceButton({ outletId }: { outletId: string }) {
  const router = useRouter()
  const inputRef = React.useRef<HTMLInputElement>(null)
  const uploadInvoice = useUploadInvoice()
  const [busy, setBusy] = React.useState(false)

  const onFiles = async (fileList: FileList | null) => {
    if (!fileList || fileList.length === 0) return
    if (!outletId) {
      toast.error("Elegí la sucursal antes de subir facturas.")
      return
    }
    const files = Array.from(fileList)
    setBusy(true)
    let ok = 0
    let failed = 0
    for (const file of files) {
      try {
        await uploadInvoice.mutateAsync({ file, outletId })
        ok++
      } catch (err) {
        failed++
        toast.error(`No se pudo procesar "${file.name}"`, {
          description: err instanceof Error ? err.message : undefined,
        })
      }
    }
    setBusy(false)
    if (ok > 0) {
      toast.success(
        ok === 1 ? "Factura subida — revisá el borrador" : `${ok} facturas subidas — revisá los borradores`,
      )
      router.push("/purchase/drafts")
    }
    if (inputRef.current) inputRef.current.value = ""
  }

  return (
    <>
      <input
        ref={inputRef}
        type="file"
        accept="image/*,application/pdf"
        multiple
        className="hidden"
        onChange={(e) => onFiles(e.target.files)}
      />
      <Button
        type="button"
        variant="outline"
        onClick={() => inputRef.current?.click()}
        disabled={busy}
      >
        {busy ? (
          <Loader2 className="mr-1.5 size-4 animate-spin" />
        ) : (
          <Upload className="mr-1.5 size-4" />
        )}
        Subir factura
      </Button>
    </>
  )
}
