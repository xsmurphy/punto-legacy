"use client"

/**
 * Modal de confirmación post-transacción unificado.
 *
 * Toda transacción del POS (venta, orden, cotización) termina en esta misma
 * pantalla de éxito — es donde el cajero decide si imprime según la operación.
 * Extraído del `SuccessPhase` inline del pay-dialog (venta) para reusarlo
 * desde cualquier flujo (context/20 §7).
 *
 * - `TransactionSuccessView`: presentacional puro (verde de marca #01D7A1,
 *   BicepsFlexed, tipografía). Se monta tanto dentro del `DialogContent` del
 *   pay-dialog (fase success) como dentro del wrapper standalone de abajo.
 * - `TransactionSuccessDialog`: wrapper `<Dialog>` para flujos que no viven
 *   dentro de un dialog propio (orden en cart-panel, cotización en el drawer
 *   de opciones).
 *
 * El brand verde #01D7A1 / texto #060A0E son hex de marca — excepción
 * documentada a "solo tokens" (context/14 §5, context/20 §3).
 */

import * as React from "react"
import { Printer, BicepsFlexed } from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

// ── Vista presentacional ──────────────────────────────────────────────────────

export interface TransactionSuccessViewProps {
  /** Título grande (ej. "¡Venta confirmada!"). */
  title: string
  /** Monto principal, YA formateado por el caller (ej. "Gs. 45.000"). */
  amount: string
  /** Vuelto YA formateado. Si se pasa, se muestra el bloque "Vuelto". */
  changeAmount?: string
  /** Nodo opcional debajo del monto (ej. badge "ya guardada — uid idempotente"). */
  badge?: React.ReactNode
  /** Label del botón de imprimir. Default "Imprimir". */
  printLabel?: string
  /** Label del botón de cierre (ej. "Nueva venta", "Continuar"). */
  closeLabel: string
  onPrint: () => void | Promise<void>
  onClose: () => void
}

export function TransactionSuccessView({
  title,
  amount,
  changeAmount,
  badge,
  printLabel = "Imprimir",
  closeLabel,
  onPrint,
  onClose,
}: TransactionSuccessViewProps) {
  // Enter global = imprimir, salvo que el foco esté en un campo editable
  // (mismo guard de inputs que el SuccessPhase original). El listener vive solo
  // mientras la vista está montada (modal abierto).
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
      void onPrint()
    }
    window.addEventListener("keydown", handleKeyDown)
    return () => window.removeEventListener("keydown", handleKeyDown)
  }, [onPrint])

  return (
    <div className="flex flex-col items-center gap-5 bg-[#01D7A1] px-6 py-8 text-[#060A0E]">
      <BicepsFlexed className="size-16" strokeWidth={1.5} />

      <div className="flex flex-col items-center gap-1 text-center">
        <h2 className="text-xl font-bold">{title}</h2>
        <p className="text-3xl font-black tabular-nums">{amount}</p>
        {changeAmount && (
          <div className="mt-2 rounded-lg bg-white/15 px-4 py-2 text-center">
            <p className="text-xs uppercase tracking-wider opacity-80">Vuelto</p>
            <p className="text-2xl font-bold tabular-nums">{changeAmount}</p>
          </div>
        )}
        {badge && <div className="mt-1">{badge}</div>}
      </div>

      <div className="flex w-full gap-3">
        <Button
          variant="outline"
          className="flex-1 gap-2 border-white/30 bg-transparent hover:bg-white/10"
          onClick={() => void onPrint()}
        >
          <Printer className="size-4" />
          {printLabel}
        </Button>
        <Button
          className="flex-1 bg-white font-bold text-[#060A0E] hover:bg-white/90"
          onClick={onClose}
        >
          {closeLabel}
        </Button>
      </div>
    </div>
  )
}

// ── Wrapper standalone ────────────────────────────────────────────────────────

export interface TransactionSuccessDialogProps
  extends TransactionSuccessViewProps {
  open: boolean
}

export function TransactionSuccessDialog({
  open,
  onClose,
  ...viewProps
}: TransactionSuccessDialogProps) {
  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose() }}>
      <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
        {/* DialogTitle sr-only para accesibilidad (Radix lo exige); el título
            visible lo pinta el <h2> de la vista con su tipografía propia. */}
        <DialogTitle className="sr-only">{viewProps.title}</DialogTitle>
        <TransactionSuccessView onClose={onClose} {...viewProps} />
      </DialogContent>
    </Dialog>
  )
}
