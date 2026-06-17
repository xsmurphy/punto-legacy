"use client"

/**
 * Pantalla de cobro — Slice A3.
 *
 * Abierto por el botón verde de total en CartPanel.
 * Cubre:
 *   - Contado (type=0): multi-método, vuelto en efectivo.
 *   - Crédito (type=3): requiere cliente; se habilita confirmar sin suma completa
 *     (queda a cuenta del cliente).
 *
 * Reglas de layout (fidelidad §6.1 — no hay screenshot legacy de esta pantalla,
 * se sigue el estándar POS: total prominente arriba, métodos al centro, confirmar
 * abajo):
 *   ┌─────────────────────────────────────┐
 *   │  TOTAL A PAGAR (grande)             │
 *   │  Tipo: Contado / Crédito            │
 *   │  [alerta si crédito sin cliente]    │
 *   ├─────────────────────────────────────┤
 *   │  Métodos de pago                    │
 *   │  [+ Agregar método]                 │
 *   │  fila: método ▼  [MoneyInput]  [X]  │
 *   ├─────────────────────────────────────┤
 *   │  Efectivo: vuelto (si aplica)       │
 *   │  Faltante / A cuenta                │
 *   ├─────────────────────────────────────┤
 *   │  [Cancelar]        [Confirmar ✓]    │
 *   └─────────────────────────────────────┘
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A3.
 */

import * as React from "react"
import { Plus, X, Printer, BicepsFlexed } from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Separator } from "@/components/ui/separator"
import { MoneyInput } from "@/components/ui/money-input"
import { cn } from "@/lib/utils"
import { useCartStore, selectCartTotal } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney } from "@/lib/format-money"
import { executeSale } from "@/lib/commands/create-sale"
import type { SalePaymentMethod } from "@/lib/commands/create-sale"
import type { CreateSaleResult } from "@/lib/commands/create-sale"
import { useDrawerStatus } from "@/hooks/use-drawer"

// ── Métodos de pago disponibles ───────────────────────────────────────────────
// Fixtures de config; en Slice A6 vienen del bootstrap del tenant.

export interface PaymentMethodConfig {
  id: string
  label: string
  /** true = calcula vuelto cuando el monto supera el total. */
  hasChange: boolean
}

export const PAYMENT_METHODS: PaymentMethodConfig[] = [
  { id: "efectivo", label: "Efectivo", hasChange: true },
  { id: "tarjeta", label: "Tarjeta", hasChange: false },
  { id: "transferencia", label: "Transferencia", hasChange: false },
  { id: "qr", label: "QR", hasChange: false },
]

// ── Fila de pago (estado local) ───────────────────────────────────────────────

interface PayRow {
  rowId: string
  methodId: string
  amount: number | null
}

function makeRow(methodId = "efectivo"): PayRow {
  return { rowId: crypto.randomUUID(), methodId, amount: null }
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

  // Guard de caja: si el cajón está cerrado, bloquear el cobro.
  const { data: drawerStatus } = useDrawerStatus()
  const drawerClosed = drawerStatus !== undefined && !drawerStatus.isOpen

  // ── Estado de filas de pago ───────────────────────────────────────────────
  const [rows, setRows] = React.useState<PayRow[]>(() => [makeRow()])
  const [phase, setPhase] = React.useState<DialogPhase>("pay")
  const [saleResult, setSaleResult] = React.useState<CreateSaleResult | null>(null)
  const [submitting, setSubmitting] = React.useState(false)
  const [errorMsg, setErrorMsg] = React.useState<string | null>(null)

  // Resetear filas + fase cuando se abre el dialog
  React.useEffect(() => {
    if (open) {
      setRows([makeRow()])
      setPhase("pay")
      setSaleResult(null)
      setErrorMsg(null)
    }
  }, [open])

  // ── Cálculos ──────────────────────────────────────────────────────────────
  const paidTotal = rows.reduce((s, r) => s + (r.amount ?? 0), 0)
  const remaining = total - paidTotal
  const overpaid = paidTotal > total

  // Vuelto: solo se calcula si algún método con hasChange cubre el exceso
  const cashRow = rows.find((r) => {
    const meta = PAYMENT_METHODS.find((m) => m.id === r.methodId)
    return meta?.hasChange && (r.amount ?? 0) > 0
  })
  const cashMeta = cashRow ? PAYMENT_METHODS.find((m) => m.id === cashRow.methodId) : null
  const showChange = cashMeta?.hasChange && overpaid
  const changeAmount = showChange ? paidTotal - total : 0

  // ── Validación para habilitar Confirmar ───────────────────────────────────
  // Contado: suma ≥ total.
  // Crédito: cliente presente (puede confirmar sin completar el pago = queda a cuenta).
  const creditoWithoutCustomer = credito && !customer
  const cashSaleReady = !credito && paidTotal >= total && paidTotal > 0
  const creditSaleReady = credito && !!customer
  const canConfirm = !creditoWithoutCustomer && (cashSaleReady || creditSaleReady) && !submitting && !drawerClosed

  // ── Mutaciones de filas ───────────────────────────────────────────────────
  function addRow() {
    // Default: primer método que no esté ya usado, o efectivo si todos están usados
    const usedIds = new Set(rows.map((r) => r.methodId))
    const next = PAYMENT_METHODS.find((m) => !usedIds.has(m.id))
    setRows((prev) => [...prev, makeRow(next?.id ?? "efectivo")])
  }

  function removeRow(rowId: string) {
    setRows((prev) => {
      const next = prev.filter((r) => r.rowId !== rowId)
      // Siempre mantener al menos una fila
      return next.length === 0 ? [makeRow()] : next
    })
  }

  function updateRowMethod(rowId: string, methodId: string) {
    setRows((prev) =>
      prev.map((r) => (r.rowId === rowId ? { ...r, methodId } : r)),
    )
  }

  function updateRowAmount(rowId: string, amount: number | null) {
    setRows((prev) =>
      prev.map((r) => (r.rowId === rowId ? { ...r, amount } : r)),
    )
  }

  // ── Confirmar venta ───────────────────────────────────────────────────────
  async function handleConfirm() {
    if (!canConfirm) return
    setSubmitting(true)
    setErrorMsg(null)
    try {
      const payments: SalePaymentMethod[] = rows
        .filter((r) => (r.amount ?? 0) > 0)
        .map((r) => ({
          name: r.methodId,
          total: r.amount ?? 0,
        }))

      // Para crédito sin pago inmediato, registrar un pago 0 de "crédito"
      // para que el backend sepa que es a cuenta.
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
        userId: null, // TODO (Slice A6): leer del contexto de sesión de caja
      })

      setSaleResult(result)
      setPhase("success")
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : "Error al confirmar la venta")
    } finally {
      setSubmitting(false)
    }
  }

  // ── Post-éxito: imprimir + limpiar ────────────────────────────────────────
  function handlePrint() {
    // TODO (Slice A5): cablear impresión real (QZ Tray + ticket-builder)
    console.log("[PayDialog] Imprimir ticket — stub, se implementa en Slice A5")
  }

  function handleClose() {
    if (phase === "success") {
      // Limpiar carrito y cerrar
      clear()
    }
    onOpenChange(false)
  }

  // ── Render ────────────────────────────────────────────────────────────────
  return (
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
            rows={rows}
            paidTotal={paidTotal}
            remaining={remaining}
            showChange={showChange ?? false}
            changeAmount={changeAmount}
            canConfirm={canConfirm}
            submitting={submitting}
            errorMsg={errorMsg}
            config={config}
            onAddRow={addRow}
            onRemoveRow={removeRow}
            onUpdateMethod={updateRowMethod}
            onUpdateAmount={updateRowAmount}
            onConfirm={handleConfirm}
            onCancel={handleClose}
          />
        ) : (
          <SuccessPhase
            result={saleResult}
            total={total}
            changeAmount={changeAmount}
            config={config}
            onPrint={handlePrint}
            onClose={handleClose}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

// ── Fase de pago ──────────────────────────────────────────────────────────────

interface PayPhaseProps {
  total: number
  credito: boolean
  customer: ReturnType<typeof useCartStore.getState>["customer"]
  creditoWithoutCustomer: boolean
  drawerClosed: boolean
  rows: PayRow[]
  paidTotal: number
  remaining: number
  showChange: boolean
  changeAmount: number
  canConfirm: boolean
  submitting: boolean
  errorMsg: string | null
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onAddRow: () => void
  onRemoveRow: (rowId: string) => void
  onUpdateMethod: (rowId: string, methodId: string) => void
  onUpdateAmount: (rowId: string, amount: number | null) => void
  onConfirm: () => void
  onCancel: () => void
}

function PayPhase({
  total,
  credito,
  customer,
  creditoWithoutCustomer,
  drawerClosed,
  rows,
  paidTotal,
  remaining,
  showChange,
  changeAmount,
  canConfirm,
  submitting,
  errorMsg,
  config,
  onAddRow,
  onRemoveRow,
  onUpdateMethod,
  onUpdateAmount,
  onConfirm,
  onCancel,
}: PayPhaseProps) {
  return (
    <>
      <DialogHeader className="shrink-0 px-5 pb-3 pt-5">
        <DialogTitle className="sr-only">Cobro</DialogTitle>

        {/* Total a pagar */}
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

        {/* Alerta crédito sin cliente */}
        {creditoWithoutCustomer && (
          <div className="mt-2 rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-center text-xs text-amber-600 dark:text-amber-400">
            Seleccioná un cliente para venta a crédito
          </div>
        )}

        {/* Cliente seleccionado (crédito con cliente) */}
        {credito && customer && (
          <div className="mt-2 rounded-lg border border-border bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
            <span className="font-medium text-foreground">{customer.name}</span>
            {customer.tin && (
              <span className="ml-1.5 text-muted-foreground">{customer.tin}</span>
            )}
          </div>
        )}

        {/* Guard: caja cerrada — bloquea el cobro */}
        {drawerClosed && (
          <div className="mt-2 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-center text-xs text-destructive">
            Abrí la caja antes de cobrar
          </div>
        )}
      </DialogHeader>

      <Separator />

      {/* Métodos de pago */}
      <div className="flex-1 overflow-y-auto px-5 py-4">
        <div className="mb-3 flex items-center justify-between">
          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Métodos de pago
          </span>
          {rows.length < PAYMENT_METHODS.length && (
            <Button
              variant="ghost"
              size="sm"
              className="h-7 gap-1.5 px-2 text-xs text-muted-foreground hover:text-foreground"
              onClick={onAddRow}
            >
              <Plus className="size-3" />
              Agregar método
            </Button>
          )}
        </div>

        <div className="flex flex-col gap-2">
          {rows.map((row, i) => (
            <PaymentRow
              key={row.rowId}
              row={row}
              canRemove={rows.length > 1}
              autoFocus={i === 0 || i === rows.length - 1}
              onUpdateMethod={onUpdateMethod}
              onUpdateAmount={onUpdateAmount}
              onRemove={onRemoveRow}
              onEnter={() => {
                // Enter en el monto: si ya se cubre el total → confirmar;
                // si todavía falta → agregar otro método de pago.
                if (paidTotal >= total && canConfirm) {
                  onConfirm()
                } else {
                  onAddRow()
                  // Nota: el foco al nuevo input queda para el cajero (Tab).
                  // El nuevo row no tiene autoFocus; implementar autofocus
                  // requeriría refs imperativos — se omite por simplicidad.
                }
              }}
            />
          ))}
        </div>

        {/* Vuelto / faltante */}
        <div className="mt-4 space-y-1.5">
          {showChange && (
            <div className="flex items-center justify-between rounded-lg border border-border bg-muted px-3 py-2">
              <span className="text-xs font-semibold text-foreground">Vuelto</span>
              <span className="text-lg font-black tabular-nums text-foreground">
                {formatMoney(changeAmount, config)}
              </span>
            </div>
          )}

          {!credito && remaining > 0 && paidTotal > 0 && (
            <div className="flex items-center justify-between rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2">
              <span className="text-xs font-semibold text-destructive">Faltante</span>
              <span className="text-sm font-bold tabular-nums text-destructive">
                {formatMoney(remaining, config)}
              </span>
            </div>
          )}

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
        </div>

        {/* Error */}
        {errorMsg && (
          <div className="mt-3 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
            {errorMsg}
          </div>
        )}
      </div>

      <Separator />

      {/* Footer: cancelar + confirmar */}
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

// ── Fila de método de pago ────────────────────────────────────────────────────

interface PaymentRowProps {
  row: PayRow
  canRemove: boolean
  /** Autofocus del monto (solo la primera fila, al abrir el modal de cobro). */
  autoFocus?: boolean
  onUpdateMethod: (rowId: string, methodId: string) => void
  onUpdateAmount: (rowId: string, amount: number | null) => void
  onRemove: (rowId: string) => void
  /** Llamado cuando el usuario aprieta Enter en el input de monto. */
  onEnter: () => void
}

function PaymentRow({
  row,
  canRemove,
  autoFocus,
  onUpdateMethod,
  onUpdateAmount,
  onRemove,
  onEnter,
}: PaymentRowProps) {
  return (
    <div className="flex items-center gap-2">
      {/* Selector de método */}
      <Select
        value={row.methodId}
        onValueChange={(val) => onUpdateMethod(row.rowId, val)}
      >
        <SelectTrigger className="w-[140px] shrink-0">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {PAYMENT_METHODS.map((m) => (
            <SelectItem key={m.id} value={m.id}>
              {m.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      {/* MoneyInput para el monto */}
      <MoneyInput
        className="flex-1"
        value={row.amount}
        onChange={(val) => onUpdateAmount(row.rowId, val)}
        placeholder="0"
        aria-label={`Monto ${row.methodId}`}
        autoFocus={autoFocus}
        onKeyDown={(e) => {
          if (e.key === "Enter") {
            e.preventDefault()
            onEnter()
          }
        }}
      />

      {/* Botón eliminar fila */}
      {canRemove ? (
        <Button
          variant="ghost"
          size="icon"
          className="shrink-0 text-muted-foreground hover:text-destructive"
          onClick={() => onRemove(row.rowId)}
          aria-label="Quitar método de pago"
        >
          <X className="size-4" />
        </Button>
      ) : (
        // Placeholder para mantener alineación cuando no se puede eliminar
        <div className="size-9 shrink-0" />
      )}
    </div>
  )
}

// ── Fase de éxito ─────────────────────────────────────────────────────────────

interface SuccessPhaseProps {
  result: CreateSaleResult | null
  total: number
  /** Monto del vuelto; si > 0 se muestra debajo del total. */
  changeAmount: number
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onPrint: () => void
  onClose: () => void
}

function SuccessPhase({ result, total, changeAmount, config, onPrint, onClose }: SuccessPhaseProps) {
  // Cambio 7: Enter en la pantalla de éxito dispara impresión del ticket.
  React.useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key !== "Enter") return
      // Ignorar Enter si el foco está en un campo de texto
      const target = e.target as HTMLElement
      const tag = target.tagName.toLowerCase()
      if (
        tag === "input" ||
        tag === "textarea" ||
        tag === "select" ||
        target.isContentEditable
      ) return
      e.preventDefault()
      onPrint()
    }
    window.addEventListener("keydown", handleKeyDown)
    return () => window.removeEventListener("keydown", handleKeyDown)
  }, [onPrint])

  return (
    // Cambio 4: fondo verde brand + texto oscuro sobre toda la pantalla de éxito
    <div className="flex flex-col items-center gap-5 bg-[#01D7A1] px-6 py-8 text-[#060A0E]">
      {/* Cambio 4: ícono BicepsFlexed en lugar de CheckCircle2 */}
      <BicepsFlexed className="size-16" strokeWidth={1.5} />

      <div className="flex flex-col items-center gap-1 text-center">
        <h2 className="text-xl font-bold">¡Venta confirmada!</h2>
        <p className="text-3xl font-black tabular-nums">
          {formatMoney(total, config)}
        </p>
        {/* Cambio 3: mostrar vuelto si hubo */}
        {changeAmount > 0 && (
          <div className="mt-2 rounded-lg bg-white/15 px-4 py-2 text-center">
            <p className="text-xs uppercase tracking-wider opacity-80">Vuelto</p>
            <p className="text-2xl font-bold tabular-nums">{formatMoney(changeAmount, config)}</p>
          </div>
        )}
        {result?.duplicated && (
          <Badge variant="outline" className="mt-1 text-[10px] opacity-70">
            ya guardada previamente — uid idempotente
          </Badge>
        )}
      </div>

      {/* Cambio 6: botones lado a lado en lugar de columna */}
      <div className="flex w-full gap-3">
        {/* Imprimir ticket — stub (Slice A5) */}
        <Button
          variant="outline"
          className="flex-1 gap-2 border-white/30 bg-transparent hover:bg-white/10"
          onClick={onPrint}
        >
          <Printer className="size-4" />
          Imprimir ticket
        </Button>

        {/* Nueva venta — CTA prominente sobre el verde */}
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
