"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { posApi as api } from "@/lib/api/pos-client"

/** Un renglón de imputación: cuánto de ESTE recibo va a cada factura. */
export interface CreditPaymentAllocation {
  parentTransactionId: string
  amount: number
}

interface CreateCreditPaymentVarsCommon {
  paymentMethodKey: string
  // paymentMethodName resuelto server-side — no enviar al backend
  note?: string
  // Identificador del pago (nº de operación, voucher) para métodos que lo exigen
  identifier?: string
}

/** Forma vieja — un recibo, una factura. La sigue usando el POS (no tocar ese call-site). */
interface CreateCreditPaymentVarsLegacy extends CreateCreditPaymentVarsCommon {
  parentTransactionId: string
  amount: number
  allocations?: never
}

/**
 * Forma nueva (mig 123) — un recibo repartido en VARIAS facturas del mismo
 * cliente. El backend (`api/v1/credit-payments.php`) acepta ambas formas tal
 * cual llegan en el body, así que un solo hook cubre los dos casos sin lógica
 * de traducción acá — el mutationFn solo hace spread de `vars`.
 */
interface CreateCreditPaymentVarsMulti extends CreateCreditPaymentVarsCommon {
  allocations: CreditPaymentAllocation[]
  parentTransactionId?: never
  amount?: never
}

type CreateCreditPaymentVars = CreateCreditPaymentVarsLegacy | CreateCreditPaymentVarsMulti

interface CreateCreditPaymentResult {
  id: string
  /** Id del recibo (transacción type=5) en formato enc() — usar con `/pos/transactions/[encId]`. */
  encId: string
  /** Monto de ESTE pago (no el acumulado). */
  amount: number
  parentComplete: boolean
  paid: number
  debtRemaining: number
  /**
   * Presente cuando el pago se hizo vía `allocations` (mig 123) — el detalle
   * por factura saldada. Ausente (undefined) en la forma legacy de 1 factura.
   */
  allocations?: Array<{
    parentTransactionId: string
    amount: number
    parentComplete: boolean
    debtRemaining: number
  }>
}

export function useCreateCreditPayment() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (vars: CreateCreditPaymentVars) =>
      api.post<CreateCreditPaymentResult>("/v1/credit-payments", {
        action: "create",
        ...vars,
      }),
    onSuccess: () => {
      // refetchType:"all" fuerza el refetch aunque la query esté inactiva: el
      // diálogo de pago (modal) tapa el detalle, así que la query del detalle
      // puede no estar "activa" al confirmar → sin esto se marca stale pero NO
      // refetchea, y la deuda mostrada no baja hasta reabrir.
      // Usamos el prefijo ["pos-transaction"] (sin id) para pegarle al detalle
      // sea cual sea el formato de id.
      qc.invalidateQueries({ queryKey: ["pos-transactions"], refetchType: "all" })
      qc.invalidateQueries({ queryKey: ["pos-transaction"], refetchType: "all" })
      qc.invalidateQueries({ queryKey: ["transactions"], refetchType: "all" })
      qc.invalidateQueries({ queryKey: ["reports", "transactions"] })
      // Panel: /transactions/{id} lee vía useTransactionDetail
      // (["transaction-detail", id]) — hook distinto al de POS, agregado sin
      // invalidación acá hasta ahora → la deuda no bajaba en el dialog del panel
      // sin recargar. Prefijo sin id para pegarle sea cual sea la tx abierta.
      qc.invalidateQueries({ queryKey: ["transaction-detail"], refetchType: "all" })
      // El saldo/deuda agregado del contacto (tab Financiero) vive en
      // ["contacts", id, "analytics", type] — invalidamos el prefijo completo.
      qc.invalidateQueries({ queryKey: ["contacts"] })
      // Reporte de cuentas por cobrar (mismo endpoint que alimenta el diálogo
      // de cobro multi-factura) — sin esto, reabrir el diálogo tras un pago
      // repartido muestra facturas ya saldadas como pendientes.
      qc.invalidateQueries({ queryKey: ["reports", "open_invoices"] })
    },
  })
}
