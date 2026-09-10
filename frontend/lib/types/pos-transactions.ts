/**
 * Tipos para el modal de transacciones del POS (T1).
 *
 * PosTransactionListItem — shape real devuelto por getMainList (con los campos
 * nuevos rawDate, rawTotal, customerName, customerDoc, invoiceNo,
 * invoicePrefix, hasMore).
 *
 * PosTransactionDetail — re-exporta TransactionDetail del hook existente para
 * que el dialog use un único tipo.
 */

export interface PosTransactionListItem {
  id: string
  title: string
  customerName: string
  /** RUC (contactTIN) si existe, si no CI (contactCI) — string vacío si el cliente no tiene ninguno. */
  customerDoc: string
  date: string
  rawDate: string
  docNumber: string
  invoiceNo: string
  invoicePrefix: string
  amount: string
  rawTotal: number
  debt: number
  label: string
  type: number
  /**
   * ISO de la anulación, "" si la venta está vigente. Una venta anulada por
   * `SaleVoidService` CONSERVA su `type` (0/3) — no pasa a 7, que es la
   * anulación legacy —, así que este campo es el único discriminante que
   * tiene la fila del listado.
   */
  voidedAt: string
  borderColor: string
}

export interface PosTransactionsListResponse {
  date: string | null
  listName: string
  transactionsList: PosTransactionListItem[]
  footBtn: string
  hasMore: boolean
}
