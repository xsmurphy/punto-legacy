"use client"

import * as React from "react"
import Link from "next/link"
import { useParams, useRouter } from "next/navigation"
import { ArrowLeft, ExternalLink, Loader2, Plus, TriangleAlert, FileWarning, FileText } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Input } from "@/components/ui/input"
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePaymentMethods } from "@/hooks/use-payment-methods"
import { useTaxes } from "@/hooks/use-taxes"
import { useFinanceCategories } from "@/hooks/use-finance-categories"
import {
  usePurchaseDraft,
  useApprovePurchaseDraft,
  useRejectPurchaseDraft,
  type ExtractedInvoiceItem,
} from "@/hooks/use-purchase-drafts"
import type {
  PurchaseCondition,
  PurchaseCreatePayload,
  PurchaseFormItem,
} from "@/hooks/use-purchases"
import type { Tax } from "@/lib/types/tax"
import { formatMoney } from "@/lib/format"
import { DatePicker } from "@/components/date-picker"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Field,
  Total,
  LineRow,
  SupplierPicker,
  emptyLine,
  makeRowId,
  type FormLine,
} from "@/components/domain/purchases/purchase-form-fields"
import { SupplierDocumentFields } from "@/components/domain/purchases/supplier-document-fields"

/**
 * Pantalla de revisión de un borrador OCR/IA — context/32-ocr-facturas-compra.md.
 *
 * Reusa el MISMO form de `/purchase` (vía `components/domain/purchases/
 * purchase-form-fields.tsx`) con la imagen de la factura al lado. Los
 * campos se prellenan desde `extracted` (JSON crudo de la IA) la primera
 * vez, o desde `edited` si el borrador ya se guardó antes. "Aprobar" manda
 * el form completo como `edited` y crea la compra real en el mismo request
 * (server-side: `PurchaseDraftService::approve` guarda `edited` y llama
 * `PurchasesService::create` — mismo código que el alta manual).
 */
export default function PurchaseDraftReviewPage() {
  const params = useParams()
  const id = typeof params.id === "string" ? params.id : (params.id?.[0] ?? "")
  const router = useRouter()

  const { data: bootstrap } = useBootstrap()
  const { data: draft, isLoading, error } = usePurchaseDraft(id || null)
  const { data: paymentMethodsData } = usePaymentMethods()
  const paymentMethods = paymentMethodsData?.paymentMethods ?? []
  const { data: taxesData } = useTaxes()
  const taxOptions = taxesData?.taxes ?? []

  const approveDraft = useApprovePurchaseDraft()
  const rejectDraft = useRejectPurchaseDraft()

  const [supplierId, setSupplierId] = React.useState("")
  const [supplierName, setSupplierName] = React.useState("")
  const [outletId, setOutletId] = React.useState("")
  const [invoiceDate, setInvoiceDate] = React.useState("")
  const [dueDate, setDueDate] = React.useState("")
  // Condición de la factura. 'credit' → la compra nace PENDIENTE (type 4):
  // entra en Cuentas por pagar y Previsiones, sin movimiento de caja.
  const [condition, setCondition] = React.useState<PurchaseCondition>("cash")
  const [authNo, setAuthNo] = React.useState("")
  const [authNoDueDate, setAuthNoDueDate] = React.useState("")
  const [invoicePrefix, setInvoicePrefix] = React.useState("")
  const [invoiceNo, setInvoiceNo] = React.useState("")
  const [paymentMethodId, setPaymentMethodId] = React.useState("")
  const [checkNumber, setCheckNumber] = React.useState("")
  const [checkBank, setCheckBank] = React.useState("")
  const [checkDueDate, setCheckDueDate] = React.useState("")
  const [discount, setDiscount] = React.useState<number | null>(null)
  // Categoría de gasto de CABECERA (owner 2026-08-20) — mismo campo que
  // /purchase/page.tsx, ver docblock ahí.
  const [expenseCategoryId, setExpenseCategoryId] = React.useState("")
  const [note, setNote] = React.useState("")
  const [lines, setLines] = React.useState<FormLine[]>([])
  const [rejectOpen, setRejectOpen] = React.useState(false)
  const { data: financeCategories } = useFinanceCategories()
  const expenseCategoryOptions = (financeCategories ?? []).filter(
    (c) => c.kind === "expense" && c.status === 1,
  )

  // Hidrata el form UNA sola vez cuando llega el draft — de `edited` si ya
  // se guardó antes, si no de `extracted` (mapeo IA → shape del form).
  const hydratedRef = React.useRef(false)
  React.useEffect(() => {
    if (hydratedRef.current || !draft) return
    hydratedRef.current = true

    if (draft.edited) {
      const e = draft.edited
      setSupplierId(e.supplierId ?? draft.contactId ?? "")
      setSupplierName(draft.contactName ?? "")
      setOutletId(e.outletId || draft.outletId)
      setInvoiceDate(e.invoiceDate ? e.invoiceDate.slice(0, 10) : today())
      setDueDate(e.dueDate ? e.dueDate.slice(0, 10) : today())
      setCondition(e.condition ?? (draft.extracted?.invoice?.condition === "credito" ? "credit" : "cash"))
      setAuthNo(e.authNo ?? "")
      setAuthNoDueDate(e.authNoDueDate ?? "")
      setInvoicePrefix(e.invoicePrefix ?? "")
      setInvoiceNo(e.invoiceNo !== undefined && e.invoiceNo !== null ? String(e.invoiceNo) : "")
      setPaymentMethodId(e.paymentMethodId ?? "")
      setCheckNumber(e.checkNumber ?? "")
      setCheckBank(e.checkBank ?? "")
      setCheckDueDate(e.checkDueDate ?? "")
      setDiscount(typeof e.discount === "number" ? e.discount : null)
      setNote(e.note ?? "")
      setLines(
        e.items && e.items.length > 0
          ? e.items.map((it) => editedItemToLine(it))
          : [emptyLine(false)],
      )
      return
    }

    const extracted = draft.extracted
    setSupplierId(draft.contactId ?? "")
    setSupplierName(draft.contactName ?? extracted?.supplier?.name ?? "")
    setOutletId(draft.outletId)
    setInvoiceDate(extracted?.invoice?.date ?? today())
    setDueDate(extracted?.invoice?.dueDate ?? extracted?.invoice?.date ?? today())
    // La condición detectada por la IA prellena el selector — el usuario la
    // confirma o la corrige antes de aprobar.
    setCondition(extracted?.invoice?.condition === "credito" ? "credit" : "cash")
    setAuthNo(extracted?.invoice?.timbrado ?? "")
    // timbradoEnd = fin de vigencia del timbrado (el OCR ya lo extrae — antes
    // se descartaba, nunca se leía en ningún lado, ver auditoría mig 144).
    setAuthNoDueDate(extracted?.invoice?.timbradoEnd ?? "")
    const split = splitInvoiceNumber(extracted?.invoice?.number ?? null)
    setInvoicePrefix(split.prefix)
    setInvoiceNo(split.no)
    setDiscount(null)
    setNote("")
    const extractedItems = extracted?.items ?? []
    setLines(
      extractedItems.length > 0
        ? extractedItems.map((it) => extractedItemToLine(it, taxOptions))
        : [emptyLine(false)],
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [draft, taxOptions])

  // Default de método de pago: primer método (sortOrder), igual que /purchase.
  // La condición contado/crédito detectada por la IA no mapea 1:1 a un
  // systemKey del catálogo real de medios de pago — se muestra como dato
  // informativo (badge), no fuerza una selección.
  React.useEffect(() => {
    if (!paymentMethodId && paymentMethods.length > 0) {
      const sorted = [...paymentMethods].sort((a, b) => (a.sortOrder ?? 999) - (b.sortOrder ?? 999))
      setPaymentMethodId(sorted[0].id)
    }
  }, [paymentMethodId, paymentMethods])

  const isCredit = condition === "credit"
  const selectedMethod = paymentMethods.find((m) => m.id === paymentMethodId)
  // A crédito no hay método de pago, así que tampoco hay cheque que cargar.
  const isCheckMethod = !isCredit && selectedMethod?.systemKey === "check"

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

  const firstFieldRefs = React.useRef<Map<string, HTMLElement>>(new Map())
  const registerFirstField = React.useCallback((rowId: string, el: HTMLElement | null) => {
    if (el) firstFieldRefs.current.set(rowId, el)
    else firstFieldRefs.current.delete(rowId)
  }, [])
  const pendingFocusRef = React.useRef<string | null>(null)
  React.useLayoutEffect(() => {
    const rid = pendingFocusRef.current
    if (!rid) return
    const el = firstFieldRefs.current.get(rid)
    if (el) {
      el.focus()
      pendingFocusRef.current = null
    }
  })

  const updateLine = (rowId: string, patch: Partial<FormLine>) => {
    setLines((curr) => curr.map((l) => (l.rowId === rowId ? { ...l, ...patch } : l)))
  }
  const removeLine = (rowId: string) => {
    setLines((curr) => (curr.length === 1 ? [emptyLine(false)] : curr.filter((l) => l.rowId !== rowId)))
  }
  const addLine = () => {
    setLines((curr) => {
      const last = curr[curr.length - 1]
      const next = emptyLine(last ? last.isProduct : false, last ? (last.taxId ?? "") : "")
      pendingFocusRef.current = next.rowId
      return [...curr, next]
    })
  }

  const canApprove =
    outletId !== "" &&
    lines.some(
      (l) => (l.isProduct ? !!l.itemId : (l.title ?? "").trim() !== "") && (Number(l.units) || 0) > 0,
    )

  const buildPayload = (): PurchaseCreatePayload => {
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
        // Solo viaja si el usuario tocó el selector de ESTA línea — mismo
        // criterio que /purchase/page.tsx, ver docblock de FormLine.
        ...(l.expenseCategoryTouched ? { expenseCategoryId: l.expenseCategoryId || null } : {}),
      }))
    return {
      supplierId: supplierId || null,
      outletId,
      condition,
      invoiceDate,
      dueDate,
      invoiceNo: invoiceNo || null,
      invoicePrefix,
      authNo,
      authNoDueDate: authNo.trim() !== "" ? authNoDueDate || undefined : undefined,
      // A crédito el pago se registra después (pago a proveedor, type 5).
      paymentMethodId: isCredit ? undefined : paymentMethodId || undefined,
      ...(isCheckMethod
        ? {
            checkNumber: checkNumber.trim(),
            checkBank: checkBank.trim() || undefined,
            checkDueDate: checkDueDate || undefined,
          }
        : {}),
      discount: discount ?? 0,
      expenseCategoryId: expenseCategoryId || undefined,
      note,
      items,
    }
  }

  const onApprove = async () => {
    if (!canApprove) {
      toast.error("Agregá al menos un ítem con cantidad y producto/descripción.")
      return
    }
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
    try {
      const result = await approveDraft.mutateAsync({ id, edited: buildPayload() })
      if (result.warning) {
        toast.warning(result.warning)
      }
      toast.success(result.alreadyApproved ? "Este borrador ya estaba aprobado" : "Compra registrada")
      if (result.transactionId) {
        router.push(`/purchase/${result.transactionId}`)
      } else {
        router.push("/purchase/drafts")
      }
    } catch (err) {
      toast.error("No se pudo aprobar el borrador", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  const onReject = async () => {
    try {
      await rejectDraft.mutateAsync(id)
      toast.success("Borrador rechazado")
      setRejectOpen(false)
      router.push("/purchase/drafts")
    } catch (err) {
      toast.error("No se pudo rechazar el borrador", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-40 items-center justify-center text-muted-foreground">
        <Loader2 className="mr-2 size-5 animate-spin" />
        Cargando borrador…
      </div>
    )
  }

  if (error || !draft) {
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <EmptyState
          icon={FileWarning}
          title="Borrador no encontrado"
          description="Puede haber sido eliminado o no tenés acceso."
        />
      </div>
    )
  }

  if (draft.status === "approved") {
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <EmptyState
          icon={FileText}
          title="Esta factura ya fue aprobada"
          description="Los datos revisados ya generaron una compra registrada."
          actions={
            draft.transactionId ? (
              <Button asChild size="sm">
                <Link href={`/purchase/${draft.transactionId}`}>Ver compra</Link>
              </Button>
            ) : undefined
          }
        />
      </div>
    )
  }

  if (draft.status === "rejected") {
    return (
      <div className="flex flex-col gap-4">
        <BackLink />
        <EmptyState
          icon={FileWarning}
          title="Este borrador fue rechazado"
          description="No generó ninguna compra. Podés subir la foto de nuevo si fue un error."
        />
      </div>
    )
  }

  const confidence = draft.extracted?.confidence ?? null
  // Detección por extensión del object key — confiable porque el key lo
  // controla el backend al subir (uploadImage() en PurchaseDraftService.php
  // fuerza ".pdf" para PDF y ".jpg" para imágenes). Evita una migración solo
  // para guardar el mime.
  const isPdf = draft.imageUrl?.toLowerCase().endsWith(".pdf") ?? false

  return (
    <div className="flex flex-col gap-4">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Revisar factura</h1>
          <p className="text-sm text-muted-foreground">
            Corregí los datos antes de aprobar — recién ahí se registra la compra.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <AlertDialog open={rejectOpen} onOpenChange={setRejectOpen}>
            <AlertDialogTrigger asChild>
              <Button type="button" variant="outline" disabled={rejectDraft.isPending}>
                Rechazar
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>¿Rechazar este borrador?</AlertDialogTitle>
                <AlertDialogDescription>
                  No se va a registrar ninguna compra. La foto queda guardada como
                  rechazada, sin efecto en stock ni finanzas.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel disabled={rejectDraft.isPending}>Cancelar</AlertDialogCancel>
                <AlertDialogAction
                  onClick={(e) => {
                    e.preventDefault()
                    onReject()
                  }}
                  disabled={rejectDraft.isPending}
                >
                  {rejectDraft.isPending && <Loader2 className="mr-1.5 size-4 animate-spin" />}
                  Rechazar
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
          <Button type="button" onClick={onApprove} disabled={!canApprove || approveDraft.isPending}>
            {approveDraft.isPending && <Loader2 className="mr-1.5 size-4 animate-spin" />}
            Aprobar y registrar compra
          </Button>
        </div>
      </header>

      {draft.error && (
        <Alert variant="destructive">
          <TriangleAlert />
          <AlertTitle>La IA no pudo leer esta factura automáticamente</AlertTitle>
          <AlertDescription>{draft.error} — completá los datos a mano abajo.</AlertDescription>
        </Alert>
      )}
      {!draft.error && confidence !== null && confidence < 0.6 && (
        <Alert>
          <TriangleAlert />
          <AlertTitle>Confianza baja en la lectura ({Math.round(confidence * 100)}%)</AlertTitle>
          <AlertDescription>Revisá con cuidado todos los campos antes de aprobar.</AlertDescription>
        </Alert>
      )}
      {draft.warnings.length > 0 && (
        <Alert>
          <TriangleAlert />
          <AlertTitle>Los montos no cuadran</AlertTitle>
          <AlertDescription>
            <ul className="list-disc pl-4">
              {draft.warnings.map((w) => (
                <li key={w}>{w}</li>
              ))}
            </ul>
          </AlertDescription>
        </Alert>
      )}
      {draft.extracted?.receiverMatchesTenant === false && (
        <Alert>
          <TriangleAlert />
          <AlertTitle>Esta factura no parece estar emitida a nombre de tu empresa</AlertTitle>
          <AlertDescription>
            RUC del receptor detectado: {draft.extracted.receiver?.ruc ?? "no legible"}. Revisá que sea
            la factura correcta antes de aprobar.
          </AlertDescription>
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,420px)_1fr]">
        {/* ── Imagen de la factura ──────────────────────────────────────── */}
        <div className="lg:sticky lg:top-4 lg:self-start">
          {draft.imageUrl ? (
            isPdf ? (
              <div className="flex flex-col gap-2">
                {/* <iframe>: no hay primitive shadcn para visor de PDF, es
                    el elemento nativo mínimo para mostrar el documento. */}
                <iframe
                  src={draft.imageUrl}
                  title="Factura en PDF"
                  className="h-[80vh] w-full rounded-lg border bg-muted"
                />
                <Button asChild variant="outline" size="sm" className="w-fit">
                  <a href={draft.imageUrl} target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="mr-1.5 size-3.5" />
                    Abrir en pestaña nueva
                  </a>
                </Button>
              </div>
            ) : (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={draft.imageUrl}
                alt="Foto de la factura"
                className="w-full rounded-lg border object-contain max-h-[80vh] bg-muted"
              />
            )
          ) : (
            <div className="flex h-64 items-center justify-center rounded-lg border bg-muted text-muted-foreground">
              <FileWarning className="mr-2 size-5" />
              Sin imagen
            </div>
          )}
        </div>

        {/* ── Form (reusa las piezas de /purchase) ──────────────────────── */}
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-5 rounded-lg border bg-card p-4">
              <SupplierPicker
                value={supplierId}
                displayName={supplierName}
                onChange={(sid, name) => {
                  setSupplierId(sid)
                  setSupplierName(name)
                }}
              />
              {draft.extracted?.supplier?.ruc && (
                <p className="-mt-3 text-xs text-muted-foreground">
                  RUC detectado: <span className="font-mono">{draft.extracted.supplier.ruc}</span>
                  {supplierId && supplierId === draft.contactId && " — proveedor matcheado automáticamente"}
                </p>
              )}

              <Field label="Sucursal" id="outlet">
                <Select value={outletId} onValueChange={setOutletId}>
                  <SelectTrigger id="outlet">
                    <SelectValue placeholder="Seleccionar sucursal" />
                  </SelectTrigger>
                  <SelectContent>
                    {(bootstrap?.outlets ?? []).map((o) => (
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
                  onValueChange={(v) => setCondition(v as PurchaseCondition)}
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

              <div className="grid grid-cols-2 gap-3">
                <Field label="Fecha factura" id="invoiceDate">
                  <DatePicker id="invoiceDate" value={invoiceDate} onChange={setInvoiceDate} />
                </Field>
                <Field label={isCredit ? "Vencimiento *" : "Vencimiento"} id="dueDate">
                  <DatePicker id="dueDate" value={dueDate} onChange={setDueDate} />
                </Field>
              </div>

              {draft.extracted?.invoice?.condition && (
                <div className="-mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                  Condición detectada:
                  <Badge variant="outline">
                    {draft.extracted.invoice.condition === "credito" ? "Crédito" : "Contado"}
                  </Badge>
                </div>
              )}
            </div>

            <div className="flex flex-col gap-5 rounded-lg border bg-card p-4">
              <SupplierDocumentFields
                value={{ prefix: invoicePrefix, no: invoiceNo, authNo, authNoDueDate }}
                onChange={(patch) => {
                  if (patch.prefix !== undefined) setInvoicePrefix(patch.prefix)
                  if (patch.no !== undefined) setInvoiceNo(patch.no)
                  if (patch.authNo !== undefined) setAuthNo(patch.authNo)
                  if (patch.authNoDueDate !== undefined) setAuthNoDueDate(patch.authNoDueDate)
                }}
              />

              {/* Método de pago solo al contado: a crédito no hay pago al crear. */}
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
                      <Input id="checkBank" value={checkBank} onChange={(e) => setCheckBank(e.target.value)} placeholder="Opcional" />
                    </Field>
                    <Field label="Vencimiento" id="checkDueDate">
                      <DatePicker id="checkDueDate" value={checkDueDate} onChange={setCheckDueDate} placeholder="Opcional" />
                    </Field>
                  </div>
                </div>
              )}

              <Field label="Descuento global" id="discount">
                <MoneyInput id="discount" value={discount} onChange={setDiscount} />
              </Field>

              <Field label="Categoría de gasto (toda la compra)" id="expenseCategoryId">
                <Select value={expenseCategoryId || "none"} onValueChange={(v) => setExpenseCategoryId(v === "none" ? "" : v)}>
                  <SelectTrigger id="expenseCategoryId">
                    <SelectValue placeholder="Sin categoría" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Sin categoría</SelectItem>
                    {expenseCategoryOptions.map((c) => (
                      <SelectItem key={c.id} value={c.id}>
                        {c.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Atajo: la usa cada línea que no eligió su propia categoría.
                </p>
              </Field>

              <Field label="Nota / observaciones" id="note">
                <Textarea id="note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Opcional" />
              </Field>
            </div>
          </div>

          <div className="flex flex-col gap-2 rounded-lg border bg-card p-4">
            <div className="flex items-center justify-between">
              <Label className="text-base font-semibold">Ítems</Label>
              <Button type="button" size="sm" variant="outline" onClick={addLine}>
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

          <div className="rounded-lg border bg-card p-4 text-sm">
            <Total label="Subtotal" value={formatMoney(totals.sub, bootstrap)} />
            <Total label="Impuestos" value={formatMoney(totals.tax, bootstrap)} />
            <Total label="Descuento" value={`- ${formatMoney(totals.discount, bootstrap)}`} />
            <div className="mt-2 flex items-center justify-between border-t pt-2 text-base font-semibold">
              <span>Total</span>
              <span className="tabular-nums">{formatMoney(totals.total, bootstrap)}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Helpers ──────────────────────────────────────────────────────────────

function BackLink() {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href="/purchase/drafts">
        <ArrowLeft className="size-3.5" />
        Volver a borradores
      </Link>
    </Button>
  )
}

function today(): string {
  const d = new Date()
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, "0")
  const dd = String(d.getDate()).padStart(2, "0")
  return `${yyyy}-${mm}-${dd}`
}

/**
 * "001-001-0001234" → { prefix: "001-001", no: "1234" }. Si no matchea el
 * formato con guiones, y es solo dígitos, va todo a `no`. Si no se puede
 * interpretar, ambos quedan vacíos — el usuario completa a mano (mejor que
 * adivinar mal un dato fiscal).
 */
function splitInvoiceNumber(raw: string | null): { prefix: string; no: string } {
  if (!raw) return { prefix: "", no: "" }
  const trimmed = raw.trim()
  const withDashes = trimmed.match(/^(\d{3}-\d{3})-0*(\d{1,10})$/)
  if (withDashes) return { prefix: withDashes[1], no: withDashes[2] }
  if (/^\d+$/.test(trimmed)) return { prefix: "", no: trimmed.replace(/^0+(?=\d)/, "") }
  return { prefix: "", no: "" }
}

/** ivaRate (0/5/10) de la IA → taxId real del tenant. "0" = sentinel "Sin impuesto" de LineRow. */
function ivaRateToTaxId(rate: 0 | 5 | 10 | null, taxOptions: Tax[]): string {
  if (rate === null) return ""
  if (rate === 0) return "0"
  const match = taxOptions.find((t) => t.rate === rate)
  return match ? match.id : ""
}

function extractedItemToLine(it: ExtractedInvoiceItem, taxOptions: Tax[]): FormLine {
  const units = it.quantity ?? 1
  const price =
    it.unitPrice ?? (it.total !== null && units > 0 ? it.total / units : null)
  return {
    rowId: makeRowId(),
    isProduct: false,
    title: it.description ?? "",
    units,
    price,
    taxId: ivaRateToTaxId(it.ivaRate, taxOptions),
    taxValue: 0,
    packSize: 1,
  }
}

function editedItemToLine(it: PurchaseFormItem): FormLine {
  return {
    rowId: makeRowId(),
    isProduct: !!it.itemId,
    itemId: it.itemId,
    itemName: undefined,
    title: it.title ?? "",
    units: it.units,
    price: it.price,
    taxId: it.taxId ?? "",
    taxValue: it.taxValue ?? 0,
    packSize: it.packSize ?? 1,
  }
}
