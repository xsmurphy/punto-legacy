"use client"

/**
 * Pantalla de cobro — Slice A3 (visor unificado + auto-confirm).
 *
 * UX: UN visor central grande que funciona simultáneamente como display del
 * remaining y como input numérico editable. Al cubrir el total con un pago,
 * la venta se confirma automáticamente sin pedir segundo click.
 *
 * Fases:
 *   pay     → visor + métodos aplicados + botones
 *   success → pantalla verde de confirmación con vuelto
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A3.
 */

import * as React from "react"
import { X, Printer, BicepsFlexed } from "lucide-react"
import { useQueryClient } from "@tanstack/react-query"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Separator } from "@/components/ui/separator"
import { toast } from "sonner"
import { cn } from "@/lib/utils"
import { useCartStore, selectCartTotal } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney, formatCurrencyAmount } from "@/lib/format-money"
import { buildSalePayload, buildApiPayload } from "@/lib/commands/create-sale"
import type { SalePaymentMethod, CreateSaleResult } from "@/lib/commands/create-sale"
import { ApiError } from "@/lib/api-client"
import { getNextInvoiceNo } from "@/lib/pos/numbering-lease"
import { enqueue, getCount } from "@/lib/pos/offline-queue"
import { useOfflineSyncStore } from "@/lib/pos/offline-sync-store"
import { useDrawerStatus } from "@/hooks/use-drawer"
import type { PaymentMethodConfig } from "@/lib/types/pos-bootstrap"
import { resolveColorBg } from "@/lib/ui/color-palette"
import { PaymentIdentifierDialog } from "./payment-identifier-dialog"
import { GiftcardValidationDialog } from "./giftcard-validation-dialog"
import { api } from "@/lib/api-client"
import { useSettingsCurrencies } from "@/hooks/use-settings"
import { printSale } from "@/lib/hardware/printers"
import { getBindingsForSale } from "@/lib/hardware/printers/binding"
import type { PrinterDocType } from "@/lib/hardware/printers/binding"
import { buildTicketData } from "@/lib/hardware/printers/build-ticket-data"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"
import { usePosRegisterConfig } from "@/hooks/use-pos-config"

// ── Fallback local (mismos datos que el BFF, por si el store aún no hidrata) ──

const FALLBACK_METHODS: PaymentMethodConfig[] = [
  { id: "efectivo", name: "Efectivo", code: "A", hasChange: true, requiresIdentifier: false, isDefault: true, systemKey: "cash" },
  {
    id: "tcredito",
    name: "T. Crédito",
    code: "S",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
    isDefault: true,
  },
  {
    id: "tdebito",
    name: "T. Débito",
    code: "D",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
    isDefault: true,
  },
  {
    id: "giftcard",
    name: "Giftcard",
    code: "G",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Código de giftcard",
    identifierPlaceholder: "Ej. GC-1234-5678",
    isDefault: true,
    systemKey: "giftcard",
  },
]

// ── Métodos secundarios — línea separada debajo de la grilla principal ────────
// Discriminante: systemKey (estable, viene del backend), no el id (taxonomyId
// que varía por tenant). "interno" no tiene hoy un método real en el backend
// (es un flag del carrito, no un medio de pago) — se mantiene por si se
// materializa como taxonomy row con systemKey='internal' a futuro.
const SECONDARY_SYSTEM_KEYS = ["internal", "giftcard"]

// ── Tipo de pago aplicado ─────────────────────────────────────────────────────

interface AppliedPayment {
  rowId: string
  method: PaymentMethodConfig
  amount: number
  identifier: string | null
}

// ── Helpers de display numérico ───────────────────────────────────────────────

function parseDisplay(s: string): number {
  if (!s) return 0
  const digits = s.replace(/\D/g, "")
  return digits ? Number(digits) : 0
}

/**
 * Formatea el raw string del visor como número con separador de miles (PY: punto).
 * Solo opera sobre los dígitos — descarta cualquier otro carácter previo.
 */
function formatDisplayInput(raw: string): string {
  const digits = raw.replace(/\D/g, "")
  if (!digits) return ""
  return Number(digits).toLocaleString("es-PY")
}

// ── Props ─────────────────────────────────────────────────────────────────────

interface PayDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

// ── Estados del diálogo ───────────────────────────────────────────────────────

type DialogPhase = "pay" | "success"

// ── Componente principal ──────────────────────────────────────────────────────

export function PayDialog({ open, onOpenChange }: PayDialogProps) {
  const lines = useCartStore((s) => s.lines)
  const customer = useCartStore((s) => s.customer)
  const credito = useCartStore((s) => s.credito)
  const interno = useCartStore((s) => s.interno)
  const tags = useCartStore((s) => s.tags)
  const quoteParentId = useCartStore((s) => s.quoteParentId)
  const saleDiscount = useCartStore((s) => s.saleDiscount)
  const setQuoteParent = useCartStore((s) => s.setQuoteParent)
  const clear = useCartStore((s) => s.clear)
  const total = useCartStore(selectCartTotal)
  const config = useCatalogStore((s) => s.config)
  const storedMethods = useCatalogStore((s) => s.paymentMethods)

  const paymentMethods = React.useMemo(() => {
    const list = storedMethods.length > 0 ? storedMethods : FALLBACK_METHODS
    // Orden por sortOrder (drag&drop del panel); sin valor cae al final estable.
    return [...list].sort((a, b) => {
      const sa = a.sortOrder ?? Number.MAX_SAFE_INTEGER
      const sb = b.sortOrder ?? Number.MAX_SAFE_INTEGER
      return sa - sb
    })
  }, [storedMethods])

  const { data: currenciesData } = useSettingsCurrencies()
  const currencies = currenciesData?.rows ?? []

  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined)
  const allBindings = bindingsData?.bindings ?? []

  // Guard de caja
  const { data: drawerStatus } = useDrawerStatus()
  const { data: configData } = usePosRegisterConfig(activeRegisterId)
  const controlCaja = configData?.config?.controlCaja ?? true
  const drawerClosed = controlCaja ? (drawerStatus !== undefined && !drawerStatus.isOpen) : false

  const qc = useQueryClient()

  // ── Estado ────────────────────────────────────────────────────────────────
  const [display, setDisplay] = React.useState("")
  const [applied, setApplied] = React.useState<AppliedPayment[]>([])
  const [change, setChange] = React.useState(0)
  const [pendingIdentifier, setPendingIdentifier] = React.useState<{
    method: PaymentMethodConfig
    amount: number
    changeOverride?: number
  } | null>(null)
  const [pendingGiftcard, setPendingGiftcard] = React.useState(false)
  const [phase, setPhase] = React.useState<DialogPhase>("pay")
  const [saleResult, setSaleResult] = React.useState<CreateSaleResult | null>(null)
  const [submitting, setSubmitting] = React.useState(false)
  const [errorMsg, setErrorMsg] = React.useState<string | null>(null)

  const displayRef = React.useRef<HTMLInputElement>(null)

  // Resetear al abrir
  React.useEffect(() => {
    if (open) {
      setDisplay("")
      setApplied([])
      setChange(0)
      setPendingIdentifier(null)
      setPhase("pay")
      setSaleResult(null)
      setErrorMsg(null)
      // autofocus al visor
      setTimeout(() => displayRef.current?.focus(), 50)
    }
  }, [open])

  // ── Derivados ─────────────────────────────────────────────────────────────
  const appliedTotal = applied.reduce((s, r) => s + r.amount, 0)
  const remaining = total - appliedTotal

  // ── Confirmar venta ───────────────────────────────────────────────────────
  async function handleConfirm(
    appliedPayments: AppliedPayment[],
    changeAmount: number,
  ) {
    setSubmitting(true)
    setErrorMsg(null)

    // Obtener número de comprobante del lease (best-effort)
    let leasedInvoiceNo = 0
    try { leasedInvoiceNo = getNextInvoiceNo() } catch { /* NO_LEASE — sin offline numbering */ }

    try {
      const payments: SalePaymentMethod[] = appliedPayments.map((r) => ({
        name: r.method.id,
        total: r.amount,
        ...(r.identifier ? { identifier: r.identifier } : {}),
      }))

      // Venta a crédito sin ningún pago inicial → se registra como crédito total
      // TODO crédito 100%: revisar con el owner si esto aplica cuando se abre
      // el dialog en modo crédito pero el cajero no aplica ningún pago.
      const effectivePayments =
        payments.length === 0 && credito
          ? [{ name: "credito", total: 0 }]
          : payments

      if (lines.length === 0) {
        throw new Error("El carrito está vacío")
      }
      if (credito && !customer) {
        throw new Error("Venta a crédito requiere un cliente seleccionado")
      }
      if (effectivePayments.length === 0) {
        throw new Error("Debe agregar al menos un método de pago")
      }

      // Construir payload para tenerlo disponible tanto para el POST como para el enqueue
      const payload = buildSalePayload({
        lines,
        payments: effectivePayments,
        credito,
        interno,
        customer,
        userId: null,
        tags,
        quoteParentId,
        saleDiscount,
        timezone: config?.timezone,
      })

      let result: CreateSaleResult

      try {
        const apiPayload = buildApiPayload(payload)
        const timeoutPromise = new Promise<never>((_, reject) =>
          setTimeout(() => reject(new Error('fetch timeout')), 5000)
        )
        const raw = await Promise.race([
          api.postLegacy<{ success: boolean; transactionId: string; uid: string; duplicated: boolean }>(
            '/v1/sales',
            apiPayload,
          ),
          timeoutPromise,
        ])
        result = {
          transactionId: raw.transactionId,
          transactionUID: raw.uid,
          invoiceNumber: null,
          total: payload.subtotal,
          duplicated: raw.duplicated === true,
        }
      } catch (fetchErr) {
        // TypeError (network) o timeout → encolar offline
        // ApiError 4xx → NO encolar (error de negocio), relanzar
        // ApiError 5xx → encolar también
        const isNetworkOrTimeout =
          fetchErr instanceof TypeError ||
          (fetchErr instanceof Error && fetchErr.message === 'fetch timeout') ||
          (fetchErr instanceof ApiError && fetchErr.status >= 500)

        if (!isNetworkOrTimeout) {
          // 4xx o error de negocio — mostrar error normal
          throw fetchErr
        }

        // Encolar en IndexedDB
        await enqueue({ clientTempId: payload.uid, leasedInvoiceNo, sale: payload })

        // Stock optimistic
        const catalogItems = useCatalogStore.getState().items
        for (const line of lines) {
          const item = catalogItems.find((i) => i.id === line.itemId)
          if (item && item.stock !== null) {
            useCatalogStore.getState().patchItem({ ...item, stock: item.stock - line.qty })
          }
        }

        // Actualizar contador de pendientes
        const count = await getCount()
        useOfflineSyncStore.getState().setPendingCount(count)

        clear()
        toast.success('Venta guardada — se enviará al volver online')
        return
      }

      setChange(changeAmount)
      setSaleResult(result)

      // Auto-print ESC/POS si hay bindings con autoPrint=true.
      // Venta al contado/crédito SIEMPRE emite Factura — el recibo es el
      // documento del pago de una factura a crédito (regla fiscal), NO un
      // fallback cuando falta el binding "factura".
      const ticketData = buildTicketData({ payload, result, config })
      const saleCategoryIds = [
        ...new Set(ticketData.items.map((i) => i.categoryId).filter((id): id is string => id !== null)),
      ]
      const autoDocType: PrinterDocType = "factura"
      const autoBindings = getBindingsForSale(allBindings, autoDocType, saleCategoryIds).filter(
        (b) => b.autoPrint,
      )
      if (autoBindings.length > 0) {
        printSale({ docType: autoDocType, data: ticketData, bindings: allBindings })
          .then((r) => {
            if (r.failed > 0) {
              toast.warning(
                `${r.failed} impresora(s) fallaron al imprimir${r.errors[0] ? `: ${r.errors[0]}` : ""}`,
              )
            } else if (r.printed > 0) {
              toast.success(`${r.printed} impresora(s) imprimieron`)
            }
          })
          .catch((err) => console.error("[auto-print] Error:", err))
      }

      // Si hubo pago con giftcard, consumirla (fire-and-forget: la venta ya está confirmada)
      const gcPayment = appliedPayments.find((r) => r.method.systemKey === "giftcard")
      if (gcPayment?.identifier && result?.transactionId) {
        void api.post("/v1/giftcards?resource=consume", {
          code: gcPayment.identifier,
          transactionId: result.transactionId,
        }).catch(() => {
          toast.error("Venta confirmada — giftcard pendiente de sincronización. Avisá al soporte.")
        })
      }

      setPhase("success")
      void api.post("/v1/screens?resource=publish", {
        type: "sale-confirmed",
        data: { total, change: changeAmount },
      }).catch(() => {})
      // Invalidar caches afectadas por la venta: dashboard (KPIs/widgets),
      // listado de transacciones, status de caja (montos efectivo).
      void qc.invalidateQueries({ queryKey: ["dashboard-widget"] })
      void qc.invalidateQueries({ queryKey: ["reports", "transactions"] })
      void qc.invalidateQueries({ queryKey: ["bff", "income-chart"] })
      void qc.invalidateQueries({ queryKey: ["drawer", "status"] })
      void qc.invalidateQueries({ queryKey: ["drawer", "summary"] })
    } catch (err) {
      setErrorMsg(
        err instanceof Error ? err.message : "Error al confirmar la venta",
      )
    } finally {
      setSubmitting(false)
    }
  }

  // ── Aplicar un pago ───────────────────────────────────────────────────────
  /**
   * Aplica un pago y, si el remaining llega a 0, confirma la venta
   * automáticamente sin pedir un segundo click.
   *
   * `changeOverride` permite pasar el vuelto calculado externamente (Caso C
   * con hasChange), porque el pago se registra por `remaining` aunque el
   * cajero entregó `parsed` — la diferencia es el vuelto informativo.
   */
  async function applyPayment(
    method: PaymentMethodConfig,
    amount: number,
    identifier: string | null,
    changeOverride?: number,
  ) {
    const newApplied: AppliedPayment[] = [
      ...applied,
      { rowId: crypto.randomUUID(), method, amount, identifier },
    ]
    const newAppliedTotal = newApplied.reduce((s, r) => s + r.amount, 0)
    const newRemaining = total - newAppliedTotal
    // changeOverride tiene prioridad: permite mostrar vuelto cuando el pago
    // registrado es `remaining` pero el cajero entregó más (Caso C hasChange).
    const newChange = changeOverride ?? (newRemaining < 0 ? Math.abs(newRemaining) : 0)

    setApplied(newApplied)
    setDisplay("")

    if (newRemaining <= 0) {
      // Venta cubierta → confirmar automáticamente
      await handleConfirm(newApplied, newChange)
    }
    // Si newRemaining > 0: el cajero sigue agregando pagos
  }

  function tryApplyPayment(
    method: PaymentMethodConfig,
    amount: number,
    changeOverride?: number,
  ) {
    if (method.requiresIdentifier) {
      setPendingIdentifier({ method, amount, changeOverride })
    } else {
      void applyPayment(method, amount, null, changeOverride)
    }
  }

  function handleMethodClick(method: PaymentMethodConfig) {
    if (method.systemKey === "giftcard") {
      setPendingGiftcard(true)
      return
    }

    if (credito) {
      // En crédito: si hay monto tipeado, se aplica como pago parcial.
      // Si no hay monto, un click en método con crédito no aplica nada
      // (la venta a crédito 100% sin cobro inicial se confirma con el botón
      // "Confirmar venta" — TODO crédito 100%: pendiente de definición con el owner).
      const parsed = parseDisplay(display)
      if (parsed > 0) {
        tryApplyPayment(method, parsed)
        setDisplay("")
      }
      return
    }

    if (remaining <= 0) return // ya cubierto

    const parsed = parseDisplay(display)
    const isEmpty = parsed === 0

    if (isEmpty) {
      // CASO A: aplica el restante completo → auto-confirm
      tryApplyPayment(method, remaining)
    } else if (parsed < remaining) {
      // CASO B: pago parcial — el cajero sigue agregando pagos
      tryApplyPayment(method, parsed)
      setDisplay("")
    } else {
      // CASO C: parsed >= remaining
      if (method.hasChange) {
        // El pago se registra por `remaining` (lo que se cobra).
        // El vuelto = parsed - remaining se muestra en la pantalla success.
        tryApplyPayment(method, remaining, parsed - remaining)
      } else {
        toast.info(`${method.name} no acepta vuelto — se aplicó el monto exacto`)
        tryApplyPayment(method, remaining)
      }
      setDisplay("")
    }
  }

  // ── Captura global de keystrokes ──────────────────────────────────────────
  // Cualquier dígito / Backspace / letra de hotkey edita el visor o dispara
  // el método correspondiente sin importar si el focus está en un botón, el
  // body, etc. El input del visor ya maneja sus propios eventos cuando tiene
  // focus — el check de target evita doble disparo.
  React.useEffect(() => {
    if (!open || phase !== "pay") return

    function handleGlobalKey(e: KeyboardEvent) {
      const target = e.target as HTMLElement | null
      // Si el target ya es un input/textarea editable (incluido el visor),
      // dejá que ese elemento maneje el evento normalmente.
      if (target && (target.tagName === "INPUT" || target.tagName === "TEXTAREA")) {
        return
      }

      // Dígito → append al display
      if (/^[0-9]$/.test(e.key)) {
        e.preventDefault()
        setDisplay((prev) => formatDisplayInput(prev + e.key))
        return
      }

      // Backspace: si hay dígitos en el visor, borra uno; si visor vacío,
      // elimina el último pago aplicado.
      if (e.key === "Backspace") {
        e.preventDefault()
        if (display === "") {
          setApplied((prev) => {
            const next = prev.slice(0, -1)
            if (next.length === 0) setChange(0)
            return next
          })
        } else {
          setDisplay((prev) => formatDisplayInput(prev.slice(0, -1)))
        }
        return
      }

      // Hotkey letras (A/S/D…) → dispara el método correspondiente
      const key = e.key.toUpperCase()
      const matched = paymentMethods.find(
        (m) => m.code && m.code.toUpperCase() === key,
      )
      if (matched) {
        e.preventDefault()
        handleMethodClick(matched)
      }
    }

    window.addEventListener("keydown", handleGlobalKey)
    return () => window.removeEventListener("keydown", handleGlobalKey)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, phase, display, paymentMethods])

  // ── Input del visor ───────────────────────────────────────────────────────
  function handleDisplayChange(raw: string) {
    setDisplay(formatDisplayInput(raw))
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    // Backspace con visor vacío → elimina el último applied
    if (e.key === "Backspace" && display === "") {
      e.preventDefault()
      setApplied((prev) => {
        const next = prev.slice(0, -1)
        if (next.length === 0) setChange(0)
        return next
      })
      return
    }

    // Hotkeys de métodos (A/S/D/F…)
    const key = e.key.toUpperCase()
    const matched = paymentMethods.find(
      (m) => m.code && m.code.toUpperCase() === key,
    )
    if (matched) {
      e.preventDefault()
      handleMethodClick(matched)
    }
  }

  // ── Validación (solo para crédito, que no tiene auto-confirm) ────────────
  const creditoWithoutCustomer = credito && !customer
  const creditSaleReady = credito && !!customer && !submitting && !drawerClosed

  function handleCreditConfirm() {
    if (!creditSaleReady) return
    void handleConfirm(applied, 0)
  }

  async function handlePrint() {
    if (!saleResult) return
    // Rebuild minimal payload for reprint — real payload is local to handleConfirm
    const reprPayload = {
      uid: "",
      type: credito ? 3 : 0,
      sale: lines.map((line) => ({
        itemId: line.itemId,
        name: line.name,
        count: line.qty,
        price: line.unitPrice,
        total: line.qty * line.unitPrice,
        discount: 0,
        note: line.note ?? null,
      })),
      payment: [] as import("@/lib/commands/create-sale").SalePaymentMethod[],
      subtotal: saleResult.total,
      tax: 0,
      discount: 0,
      client: customer?.id ?? null,
      user: null,
      note: null,
      interno: false,
      tags: [] as string[],
      date: new Date().toISOString(),
      timestamp: Math.floor(Date.now() / 1000),
    } satisfies import("@/lib/commands/create-sale").CreateSalePayload
    const ticketData = buildTicketData({ payload: reprPayload, result: saleResult, config })
    // Venta al contado/crédito SIEMPRE emite Factura — el recibo es el
    // documento del pago de una factura a crédito (regla fiscal), NO un
    // fallback cuando falta el binding "factura".
    const printCategoryIds = [
      ...new Set(ticketData.items.map((i) => i.categoryId).filter((id): id is string => id !== null)),
    ]
    const printDocType: PrinterDocType = "factura"
    const receiptBindings = getBindingsForSale(allBindings, printDocType, printCategoryIds)
    if (receiptBindings.length > 0) {
      const r = await printSale({ docType: printDocType, data: ticketData, bindings: allBindings })
      if (r.failed > 0) {
        toast.warning(
          `${r.failed} impresora(s) fallaron al reimprimir${r.errors[0] ? `: ${r.errors[0]}` : ""}`,
        )
      } else if (r.printed > 0) {
        toast.success(`${r.printed} impresora(s) reimprimieron`)
      } else {
        toast.warning("Ninguna impresora tiene asignado el documento Factura — asignáselo en Impresoras")
      }
    } else {
      toast.warning("Ninguna impresora tiene asignado el documento Factura — asignáselo en Impresoras")
    }
  }

  function handleClose() {
    if (phase === "success") {
      clear() // clear() ya resetea quoteParentId via initialState
      void api.post("/v1/screens?resource=publish", {
        type: "cart-cleared",
        data: {},
      }).catch(() => {})
    } else {
      // Venta abandonada — limpiar quoteParentId para que la próxima venta no herede el parent
      setQuoteParent(null)
    }
    onOpenChange(false)
  }

  // ── Render ────────────────────────────────────────────────────────────────
  return (
    <>
      <Dialog open={open} onOpenChange={(o) => { if (!o) handleClose() }}>
        <DialogContent
          className={cn(
            "flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0",
            phase === "success" ? "sm:max-w-lg" : "sm:max-w-md",
          )}
        >
          {phase === "pay" ? (
            <PayPhase
              total={total}
              credito={credito}
              customer={customer}
              creditoWithoutCustomer={creditoWithoutCustomer}
              drawerClosed={drawerClosed}
              applied={applied}
              display={display}
              displayRef={displayRef}
              remaining={remaining}
              appliedTotal={appliedTotal}
              creditSaleReady={creditSaleReady}
              submitting={submitting}
              errorMsg={errorMsg}
              config={config}
              paymentMethods={paymentMethods}
              currencies={currencies}
              onDisplayChange={handleDisplayChange}
              onKeyDown={handleKeyDown}
              onMethodClick={handleMethodClick}
              onRemoveApplied={(rowId) =>
                setApplied((prev) => prev.filter((r) => r.rowId !== rowId))
              }
              onCreditConfirm={handleCreditConfirm}
              onCancel={handleClose}
            />
          ) : (
            <SuccessPhase
              result={saleResult}
              total={total}
              changeAmount={change}
              config={config}
              onPrint={handlePrint}
              onClose={handleClose}
            />
          )}
        </DialogContent>
      </Dialog>

      {/* Sub-diálogo de identificador (fuera del diálogo principal para evitar nesting) */}
      <PaymentIdentifierDialog
        open={pendingIdentifier !== null}
        method={pendingIdentifier?.method ?? null}
        amount={pendingIdentifier?.amount ?? 0}
        config={config}
        onConfirm={(identifier) => {
          if (pendingIdentifier) {
            void applyPayment(
              pendingIdentifier.method,
              pendingIdentifier.amount,
              identifier,
              pendingIdentifier.changeOverride,
            )
          }
          setPendingIdentifier(null)
        }}
        onCancel={() => setPendingIdentifier(null)}
      />

      <GiftcardValidationDialog
        open={pendingGiftcard}
        total={total}
        config={config}
        onApply={(code, amount) => {
          const gcMethod = paymentMethods.find((m) => m.systemKey === "giftcard")
          if (gcMethod) {
            void applyPayment(gcMethod, amount, code)
          }
          setPendingGiftcard(false)
        }}
        onCancel={() => setPendingGiftcard(false)}
      />
    </>
  )
}

// ── Fase de pago ──────────────────────────────────────────────────────────────

interface PayPhaseProps {
  total: number
  credito: boolean
  customer: ReturnType<typeof useCartStore.getState>["customer"]
  creditoWithoutCustomer: boolean
  drawerClosed: boolean
  applied: AppliedPayment[]
  display: string
  displayRef: React.RefObject<HTMLInputElement | null>
  remaining: number
  appliedTotal: number
  creditSaleReady: boolean
  submitting: boolean
  errorMsg: string | null
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  paymentMethods: PaymentMethodConfig[]
  currencies: Array<{ ccode: string; code: string; value: number }>
  onDisplayChange: (raw: string) => void
  onKeyDown: (e: React.KeyboardEvent<HTMLInputElement>) => void
  onMethodClick: (method: PaymentMethodConfig) => void
  onRemoveApplied: (rowId: string) => void
  onCreditConfirm: () => void
  onCancel: () => void
}

function PayPhase({
  total,
  credito,
  customer,
  creditoWithoutCustomer,
  drawerClosed,
  applied,
  display,
  displayRef,
  remaining,
  appliedTotal,
  creditSaleReady,
  submitting,
  errorMsg,
  config,
  paymentMethods,
  currencies,
  onDisplayChange,
  onKeyDown,
  onMethodClick,
  onRemoveApplied,
  onCreditConfirm,
  onCancel,
}: PayPhaseProps) {
  const primaryMethods = paymentMethods.filter(
    (m) => !m.systemKey || !SECONDARY_SYSTEM_KEYS.includes(m.systemKey),
  )
  const secondaryMethods = paymentMethods.filter(
    (m) => m.systemKey && SECONDARY_SYSTEM_KEYS.includes(m.systemKey),
  )
  const activeCurrencies = currencies.filter((c) => c.value > 0)
  // El visor muestra lo tipeado si hay algo; si no, muestra el remaining.
  // Esto se logra con placeholder: el input está vacío y el placeholder
  // es el remaining formateado — visualmente se lee como el monto a cobrar.
  const placeholderAmount = remaining > 0 ? remaining : 0
  const placeholderText = formatMoney(placeholderAmount, config)

  return (
    <>
      {/* Header — label + badge de modo */}
      <DialogHeader className="shrink-0 px-5 pb-3 pt-5">
        <DialogTitle className="sr-only">Cobro</DialogTitle>

        <span className="text-xs font-medium uppercase tracking-widest text-muted-foreground">
          {credito ? "Total a pagar · Crédito" : "Total a pagar · Contado"}
        </span>

        {creditoWithoutCustomer && (
          <div className="mt-2 rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-center text-xs text-amber-600 dark:text-amber-400">
            Seleccioná un cliente para venta a crédito
          </div>
        )}

        {credito && customer && (
          <div className="mt-2 rounded-lg border border-border bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
            <span className="font-medium text-foreground">{customer.name}</span>
            {customer.tin && (
              <span className="ml-1.5 text-muted-foreground">{customer.tin}</span>
            )}
          </div>
        )}

        {drawerClosed && (
          <div className="mt-2 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-center text-xs text-destructive">
            Abrí la caja antes de cobrar
          </div>
        )}
      </DialogHeader>

      {/* Visor unificado — display del remaining Y input numérico editable.
          Cuando el cajero no tipeó nada, el placeholder muestra el remaining
          formateado. Al tipear, el visor cambia al monto ingresado en tiempo real.
          Autofocus garantiza que el teclado físico funcione desde el primer gesto. */}
      <div className="shrink-0 px-5 py-4">
        <input
          ref={displayRef}
          type="text"
          inputMode="numeric"
          value={display}
          onChange={(e) => onDisplayChange(e.target.value)}
          onKeyDown={onKeyDown}
          placeholder={placeholderText}
          className={cn(
            "w-full bg-transparent text-center tabular-nums outline-none caret-transparent",
            "text-5xl font-black text-foreground",
            // Placeholder muestra el remaining con el mismo estilo que el texto
            // tipeado — visualmente es un solo visor (no parece un campo vacío)
            "placeholder:text-foreground",
          )}
          aria-label="Monto a cobrar"
        />
      </div>

      {/* Conversión multi-moneda — read-only, debajo del total */}
      {activeCurrencies.length > 0 && (
        <div className="mt-1 flex flex-wrap items-center justify-center gap-x-3 gap-y-0.5 pb-3 text-xs text-muted-foreground">
          {activeCurrencies.map((c) => (
            <span key={c.code} className="tabular-nums">
              {c.code} {formatCurrencyAmount(total / c.value, c.code)}
            </span>
          ))}
        </div>
      )}

      <Separator />

      {/* Cuerpo scrolleable */}
      <div className="flex-1 overflow-y-auto px-5 py-4 space-y-3">

        {/* Pagos ya aplicados */}
        {applied.length > 0 && (
          <div className="space-y-1.5">
            {applied.map((r) => (
              <div
                key={r.rowId}
                className="flex items-center gap-2 rounded-md bg-muted px-3 py-1.5 text-sm"
              >
                <span className="font-medium">{r.method.name}</span>
                {r.identifier && (
                  <span className="text-xs text-muted-foreground">· {r.identifier}</span>
                )}
                <span className="ml-auto tabular-nums font-semibold">
                  {formatMoney(r.amount, config)}
                </span>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-6 text-muted-foreground hover:text-destructive"
                  onClick={() => onRemoveApplied(r.rowId)}
                  aria-label="Quitar pago"
                >
                  <X className="size-3" />
                </Button>
              </div>
            ))}
          </div>
        )}

        {/* Faltante (visible solo cuando hay pagos parciales en contado) */}
        {!credito && remaining > 0 && appliedTotal > 0 && (
          <div className="flex items-center justify-between rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2">
            <span className="text-xs font-semibold text-destructive">Faltante</span>
            <span className="text-sm font-bold tabular-nums text-destructive">
              {formatMoney(remaining, config)}
            </span>
          </div>
        )}

        {/* Queda a cuenta (crédito) */}
        {credito && customer && (
          <div className="flex items-center justify-between rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2">
            <span className="text-xs font-semibold text-amber-600 dark:text-amber-400">
              Queda a cuenta
            </span>
            <span className="text-sm font-bold tabular-nums text-amber-600 dark:text-amber-400">
              {remaining > 0 ? formatMoney(remaining, config) : "—"}
            </span>
          </div>
        )}

        {/* Grilla de métodos principal — 3 cols. */}
        <div className="grid grid-cols-3 gap-1.5">
          {primaryMethods.map((m) => {
            const accent = resolveColorBg(m.color)
            return (
            <Button
              key={m.id}
              variant={m.isDefault ? "default" : "outline"}
              className="h-9 justify-center gap-1.5 border-l-4 px-2 text-xs font-medium"
              style={accent ? { borderLeftColor: accent } : undefined}
              onClick={() => onMethodClick(m)}
              disabled={!credito && remaining <= 0}
            >
              <span className="truncate">{m.name}</span>
              {m.code && (
                <kbd
                  className={cn(
                    "pointer-events-none inline-flex h-4 select-none items-center rounded px-1 font-mono text-[10px] font-medium",
                    m.isDefault
                      ? "border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground/80"
                      : "border border-border/60 bg-background/60 text-muted-foreground",
                  )}
                >
                  {m.code}
                </kbd>
              )}
            </Button>
            )
          })}
        </div>

        {/* Métodos secundarios (Crédito Interno, Giftcard). */}
        {secondaryMethods.length > 0 && (
          <>
            <Separator className="my-0.5" />
            <div className="flex flex-wrap gap-1.5">
              {secondaryMethods.map((m) => {
                const accent = resolveColorBg(m.color)
                return (
                <Button
                  key={m.id}
                  variant="outline"
                  className="h-8 justify-center gap-1.5 border-l-4 px-3 text-xs font-medium text-muted-foreground"
                  style={accent ? { borderLeftColor: accent } : undefined}
                  onClick={() => onMethodClick(m)}
                  disabled={!credito && remaining <= 0}
                >
                  <span className="truncate">{m.name}</span>
                  {m.code && (
                    <kbd className="pointer-events-none inline-flex h-4 select-none items-center rounded border border-border/60 bg-background/60 px-1 font-mono text-[10px] font-medium text-muted-foreground">
                      {m.code}
                    </kbd>
                  )}
                </Button>
                )
              })}
            </div>
          </>
        )}


        {/* Error */}
        {errorMsg && (
          <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
            {errorMsg}
          </div>
        )}
      </div>

      <Separator />

      {/* Footer — solo Cancelar para contado (auto-confirm elimina el segundo click)
          Para crédito, se mantiene "Confirmar venta" porque no hay auto-confirm
          (el cajero puede querer registrar sin cobro inicial). */}
      <div className="shrink-0 flex gap-2 px-5 py-4">
        <Button
          variant="outline"
          className={cn("flex-1", credito ? "flex-1" : "flex-[1]")}
          onClick={onCancel}
          disabled={submitting}
        >
          Cancelar
        </Button>
        {credito && (
          <Button
            disabled={!creditSaleReady}
            onClick={onCreditConfirm}
            className="flex-[2] font-bold transition-all active:scale-[0.98]"
          >
            {submitting ? "Procesando..." : "Confirmar venta"}
          </Button>
        )}
      </div>
    </>
  )
}

// ── Fase de éxito ─────────────────────────────────────────────────────────────

interface SuccessPhaseProps {
  result: CreateSaleResult | null
  total: number
  changeAmount: number
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onPrint: () => void | Promise<void>
  onClose: () => void
}

function SuccessPhase({ result, total, changeAmount, config, onPrint, onClose }: SuccessPhaseProps) {
  React.useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key !== "Enter") return
      const target = e.target as HTMLElement
      const tag = target.tagName.toLowerCase()
      if (
        tag === "input" ||
        tag === "textarea" ||
        tag === "select" ||
        target.isContentEditable
      )
        return
      e.preventDefault()
      onPrint()
    }
    window.addEventListener("keydown", handleKeyDown)
    return () => window.removeEventListener("keydown", handleKeyDown)
  }, [onPrint])

  return (
    <div className="flex flex-col items-center gap-5 bg-[#01D7A1] px-6 py-8 text-[#060A0E]">
      <BicepsFlexed className="size-16" strokeWidth={1.5} />

      <div className="flex flex-col items-center gap-1 text-center">
        <h2 className="text-xl font-bold">¡Venta confirmada!</h2>
        <p className="text-3xl font-black tabular-nums">
          {formatMoney(total, config)}
        </p>
        {changeAmount > 0 && (
          <div className="mt-2 rounded-lg bg-white/15 px-4 py-2 text-center">
            <p className="text-xs uppercase tracking-wider opacity-80">Vuelto</p>
            <p className="text-2xl font-bold tabular-nums">
              {formatMoney(changeAmount, config)}
            </p>
          </div>
        )}
        {result?.duplicated && (
          <Badge variant="outline" className="mt-1 text-[10px] opacity-70">
            ya guardada previamente — uid idempotente
          </Badge>
        )}
      </div>

      <div className="flex w-full gap-3">
        <Button
          variant="outline"
          className="flex-1 gap-2 border-white/30 bg-transparent hover:bg-white/10"
          onClick={onPrint}
        >
          <Printer className="size-4" />
          Imprimir
        </Button>
        <Button
          className="flex-1 bg-white font-bold text-[#060A0E] hover:bg-white/90"
          onClick={onClose}
        >
          Nueva venta
        </Button>
      </div>
    </div>
  )
}
