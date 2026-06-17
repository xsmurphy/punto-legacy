"use client"

/**
 * Pantalla de cobro — Slice A3 (refactor visor central).
 *
 * UX: visor central numérico grande + botones de método. El cajero tipea el
 * monto (o deja vacío para aplicar el restante) y presiona el botón/hotkey
 * del método. Si el método requiere identificador, abre un sub-diálogo.
 *
 * Fases:
 *   pay     → visor + métodos aplicados + botones
 *   success → pantalla verde de confirmación
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A3.
 */

import * as React from "react"
import { X, Printer, BicepsFlexed } from "lucide-react"
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
import { formatMoney } from "@/lib/format-money"
import { executeSale } from "@/lib/commands/create-sale"
import type { SalePaymentMethod, CreateSaleResult } from "@/lib/commands/create-sale"
import { useDrawerStatus } from "@/hooks/use-drawer"
import type { PaymentMethodConfig } from "@/lib/types/pos-bootstrap"
import { PaymentIdentifierDialog } from "./payment-identifier-dialog"

// ── Fallback local (mismos datos que el BFF, por si el store aún no hidrata) ──

const FALLBACK_METHODS: PaymentMethodConfig[] = [
  { id: "efectivo", name: "Efectivo", code: "A", hasChange: true, requiresIdentifier: false },
  {
    id: "tcredito",
    name: "T. Crédito",
    code: "S",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
  },
  {
    id: "tdebito",
    name: "T. Débito",
    code: "D",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
  },
  { id: "transferencia", name: "Transferencia", code: "F", hasChange: false, requiresIdentifier: false },
]

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
  const clear = useCartStore((s) => s.clear)
  const total = useCartStore(selectCartTotal)
  const config = useCatalogStore((s) => s.config)
  const storedMethods = useCatalogStore((s) => s.paymentMethods)

  const paymentMethods =
    storedMethods.length > 0 ? storedMethods : FALLBACK_METHODS

  // Guard de caja
  const { data: drawerStatus } = useDrawerStatus()
  const drawerClosed = drawerStatus !== undefined && !drawerStatus.isOpen

  // ── Estado ────────────────────────────────────────────────────────────────
  const [display, setDisplay] = React.useState("")
  const [applied, setApplied] = React.useState<AppliedPayment[]>([])
  const [change, setChange] = React.useState(0)
  const [pendingIdentifier, setPendingIdentifier] = React.useState<{
    method: PaymentMethodConfig
    amount: number
  } | null>(null)
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

  // ── Aplicar un pago (sin identificador pendiente) ─────────────────────────
  function applyPayment(
    method: PaymentMethodConfig,
    amount: number,
    identifier: string | null,
  ) {
    setApplied((prev) => [
      ...prev,
      { rowId: crypto.randomUUID(), method, amount, identifier },
    ])
  }

  function tryApplyPayment(method: PaymentMethodConfig, amount: number) {
    if (method.requiresIdentifier) {
      setPendingIdentifier({ method, amount })
    } else {
      applyPayment(method, amount, null)
      setDisplay("")
    }
  }

  function handleMethodClick(method: PaymentMethodConfig) {
    if (credito) {
      // En crédito no se cobra (o se cobra parcial opcionalmente) — el visor
      // queda disponible pero no bloqueamos el botón confirmar por monto.
      // Si hay display, se aplica como pago parcial.
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
      // CASO A: aplica el restante completo
      tryApplyPayment(method, remaining)
    } else if (parsed < remaining) {
      // CASO B: pago parcial
      tryApplyPayment(method, parsed)
      setDisplay("")
    } else {
      // CASO C: parsed >= remaining
      if (method.hasChange) {
        setChange(parsed - remaining)
        tryApplyPayment(method, remaining)
      } else {
        toast.info(`${method.name} no acepta vuelto — se aplicó el monto exacto`)
        tryApplyPayment(method, remaining)
      }
      setDisplay("")
    }
  }

  // ── Input del visor ───────────────────────────────────────────────────────
  function handleDisplayChange(raw: string) {
    setDisplay(formatDisplayInput(raw))
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    // Enter con saldo cubierto → confirmar
    if (e.key === "Enter") {
      e.preventDefault()
      if (canConfirm) handleConfirm()
      return
    }

    // Backspace con visor vacío → elimina el último applied
    if (e.key === "Backspace" && display === "") {
      e.preventDefault()
      setApplied((prev) => {
        const next = prev.slice(0, -1)
        // Si se eliminó el último, limpiar vuelto
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

  // ── Validación ────────────────────────────────────────────────────────────
  const creditoWithoutCustomer = credito && !customer
  const cashSaleReady = !credito && remaining <= 0 && appliedTotal > 0
  const creditSaleReady = credito && !!customer
  const canConfirm =
    (cashSaleReady || creditSaleReady) &&
    !creditoWithoutCustomer &&
    !submitting &&
    !drawerClosed

  // ── Confirmar venta ───────────────────────────────────────────────────────
  async function handleConfirm() {
    if (!canConfirm) return
    setSubmitting(true)
    setErrorMsg(null)
    try {
      const payments: SalePaymentMethod[] = applied.map((r) => ({
        name: r.method.id,
        total: r.amount,
        ...(r.identifier ? { identifier: r.identifier } : {}),
      }))

      const effectivePayments =
        payments.length === 0 && credito
          ? [{ name: "credito", total: 0 }]
          : payments

      const result = await executeSale({
        lines,
        payments: effectivePayments,
        credito,
        interno,
        customer,
        userId: null,
      })

      setSaleResult(result)
      setPhase("success")
    } catch (err) {
      setErrorMsg(
        err instanceof Error ? err.message : "Error al confirmar la venta",
      )
    } finally {
      setSubmitting(false)
    }
  }

  function handlePrint() {
    console.log("[PayDialog] Imprimir ticket — stub, se implementa en Slice A5")
  }

  function handleClose() {
    if (phase === "success") {
      clear()
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
              change={change}
              remaining={remaining}
              appliedTotal={appliedTotal}
              canConfirm={canConfirm}
              submitting={submitting}
              errorMsg={errorMsg}
              config={config}
              paymentMethods={paymentMethods}
              onDisplayChange={handleDisplayChange}
              onKeyDown={handleKeyDown}
              onMethodClick={handleMethodClick}
              onRemoveApplied={(rowId) =>
                setApplied((prev) => prev.filter((r) => r.rowId !== rowId))
              }
              onConfirm={handleConfirm}
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
            applyPayment(pendingIdentifier.method, pendingIdentifier.amount, identifier)
            setDisplay("")
          }
          setPendingIdentifier(null)
        }}
        onCancel={() => setPendingIdentifier(null)}
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
  change: number
  remaining: number
  appliedTotal: number
  canConfirm: boolean
  submitting: boolean
  errorMsg: string | null
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  paymentMethods: PaymentMethodConfig[]
  onDisplayChange: (raw: string) => void
  onKeyDown: (e: React.KeyboardEvent<HTMLInputElement>) => void
  onMethodClick: (method: PaymentMethodConfig) => void
  onRemoveApplied: (rowId: string) => void
  onConfirm: () => void
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
  change,
  remaining,
  appliedTotal,
  canConfirm,
  submitting,
  errorMsg,
  config,
  paymentMethods,
  onDisplayChange,
  onKeyDown,
  onMethodClick,
  onRemoveApplied,
  onConfirm,
  onCancel,
}: PayPhaseProps) {
  return (
    <>
      {/* Header */}
      <DialogHeader className="shrink-0 px-5 pb-3 pt-5">
        <DialogTitle className="sr-only">Cobro</DialogTitle>

        <div className="flex flex-col items-center gap-1 pb-1">
          <span className="text-xs font-medium uppercase tracking-widest text-muted-foreground">
            Total a pagar
          </span>
          <span className="text-4xl font-black tabular-nums text-foreground">
            {formatMoney(total, config)}
          </span>
          <Badge
            variant={credito ? "secondary" : "outline"}
            className={cn(
              "mt-0.5 text-[10px] font-bold uppercase tracking-wide",
              credito
                ? "border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400"
                : "border-border bg-muted text-foreground",
            )}
          >
            {credito ? "Crédito" : "Contado"}
          </Badge>
        </div>

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

        {/* Visor central */}
        <input
          ref={displayRef}
          type="text"
          inputMode="numeric"
          value={display}
          onChange={(e) => onDisplayChange(e.target.value)}
          onKeyDown={onKeyDown}
          placeholder="0"
          className="w-full bg-transparent text-center text-5xl font-black tabular-nums outline-none placeholder:text-muted-foreground/30"
          aria-label="Monto a cobrar"
        />

        {/* Vuelto */}
        {change > 0 && (
          <div className="flex items-center justify-between rounded-lg border border-border bg-muted px-3 py-2">
            <span className="text-xs font-semibold">Vuelto</span>
            <span className="text-xl font-black tabular-nums">
              {formatMoney(change, config)}
            </span>
          </div>
        )}

        {/* Faltante */}
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

        {/* Grilla de métodos */}
        <div className="grid grid-cols-2 gap-2">
          {paymentMethods.map((m) => (
            <Button
              key={m.id}
              variant="outline"
              className="h-14 flex-col gap-0.5 text-sm font-semibold"
              onClick={() => onMethodClick(m)}
              disabled={!credito && remaining <= 0}
            >
              <span>{m.name}</span>
              {m.code && (
                <span className="text-xs font-normal text-muted-foreground">
                  ({m.code})
                </span>
              )}
            </Button>
          ))}
        </div>

        {/* Error */}
        {errorMsg && (
          <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
            {errorMsg}
          </div>
        )}
      </div>

      <Separator />

      {/* Footer */}
      <div className="shrink-0 flex gap-2 px-5 py-4">
        <Button
          variant="outline"
          className="flex-1"
          onClick={onCancel}
          disabled={submitting}
        >
          Cancelar
        </Button>
        <Button
          disabled={!canConfirm}
          onClick={onConfirm}
          className="flex-[2] font-bold transition-all active:scale-[0.98]"
        >
          {submitting ? "Procesando..." : "Confirmar venta"}
        </Button>
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
  onPrint: () => void
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
          Imprimir ticket
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
