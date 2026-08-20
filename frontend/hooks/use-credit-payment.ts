"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { posApi } from "@/lib/api/pos-client"
import type { HttpClient } from "@/lib/api-client"
import type { ContactType } from "@/hooks/use-contacts"
import type { SupplierDocumentInput } from "@/hooks/use-purchases"

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
  /**
   * 1=cliente (default, kind='credit_payment') | 2=proveedor
   * (kind='purchase_payment'). Omitido = cliente, compat con los call-sites
   * existentes (POS, panel) que solo cobraban crédito de clientes.
   */
  contactType?: ContactType
  /**
   * Número de comprobante + timbrado IMPRESOS en el recibo del PROVEEDOR
   * (context/29 §5, mig 144) — solo aplica cuando `contactType=2`
   * (pago a proveedor); el backend lo ignora en cobro a cliente.
   */
  supplierDoc?: SupplierDocumentInput
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

/** Invalidaciones compartidas — cualquier pago (por factura o distribuido) mueve las mismas queries. */
function invalidatePaymentQueries(qc: ReturnType<typeof useQueryClient>) {
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
  // Panel: /purchase/{id} lee vía usePurchase (["purchases","detail",id]) —
  // mismo motivo que arriba, para que un pago a proveedor baje la deuda sin
  // recargar la página. Prefijo ["purchases"] pega también al listado.
  qc.invalidateQueries({ queryKey: ["purchases"], refetchType: "all" })
  qc.invalidateQueries({ queryKey: ["reports", "purchases"] })
  // El saldo/deuda agregado del contacto (tab Financiero) vive en
  // ["contacts", id, "analytics", type] — invalidamos el prefijo completo.
  qc.invalidateQueries({ queryKey: ["contacts"] })
  // Reporte de cuentas por cobrar/pagar (mismo endpoint que alimenta el
  // diálogo de cobro multi-factura) — sin esto, reabrir el diálogo tras un
  // pago repartido muestra facturas ya saldadas como pendientes.
  qc.invalidateQueries({ queryKey: ["reports", "open_invoices"] })
}

/**
 * Cobro a cliente (POS, mostrador) o pago a proveedor (panel, ficha de
 * contacto / cuentas por pagar) — mismo endpoint, dos realms. El backend
 * (`api/v1/credit-payments.php`) rechaza contactType=2 (proveedor) cuando
 * la request se autentica como device (realm pos-app): el pago a proveedor
 * solo tiene sentido desde panel. Por eso el cliente HTTP importa acá — no
 * es cosmético: `client` decide con qué credencial sale la request (cookie
 * panel vs Bearer device), y eso decide si el backend la deja pasar.
 * Default `posApi` preserva el único call-site POS existente
 * (`components/register/credit-payment-dialog.tsx`, siempre contactType=1).
 * Call-sites panel (`multi-invoice-payment-dialog.tsx`) deben pasar
 * `client: api` explícitamente. Ver invariante de realm en `lib/api-client.ts`.
 */
export function useCreateCreditPayment(opts: { client?: HttpClient } = {}) {
  const client = opts.client ?? posApi
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (vars: CreateCreditPaymentVars) =>
      client.post<CreateCreditPaymentResult>("/v1/credit-payments", {
        action: "create",
        ...vars,
      }),
    onSuccess: () => invalidatePaymentQueries(qc),
  })
}

interface CreateDistributedPaymentVars {
  contactId: string
  /** 1=cliente | 2=proveedor. */
  contactType: ContactType
  /** Monto total del recibo — el backend lo reparte entre las facturas
   *  pendientes de la más vieja a la más nueva (FIFO por transactionDueDate),
   *  saldando completas hasta donde alcance. Cálculo 100% server-side, dentro
   *  de la misma transacción que el lock de las facturas (ver
   *  CreditPaymentService::createDistributed) — nunca se manda un array de
   *  allocations pre-calculado en el cliente para este modo. */
  amount: number
  paymentMethodKey: string
  note?: string
  identifier?: string
  /** Ver `CreateCreditPaymentVarsCommon.supplierDoc`. */
  supplierDoc?: SupplierDocumentInput
}

/**
 * "Monto libre": el operador entrega un monto y el backend decide cómo se
 * reparte entre las facturas abiertas del contacto (la más vieja primero).
 * Mismo recibo único + N vínculos `transaction_link` que `useCreateCreditPayment`
 * con `allocations` — la diferencia es que acá las allocations las calcula
 * `CreditPaymentService::createDistributed()`, no el cliente.
 */
export function useCreateDistributedPayment(opts: { client?: HttpClient } = {}) {
  const client = opts.client ?? posApi
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (vars: CreateDistributedPaymentVars) =>
      client.post<CreateCreditPaymentResult>("/v1/credit-payments", {
        action: "createDistributed",
        ...vars,
      }),
    onSuccess: () => invalidatePaymentQueries(qc),
  })
}
