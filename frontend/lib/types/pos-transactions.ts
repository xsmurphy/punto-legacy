/**
 * Tipos para el modal de transacciones del POS (T1).
 *
 * PosTransactionListItem — shape real devuelto por getMainList (con los campos
 * nuevos rawDate, rawTotal, customerName, invoiceNo, invoicePrefix, hasMore).
 *
 * PosTransactionDetail — re-exporta TransactionDetail del hook existente para
 * que el dialog use un único tipo.
 */

export interface PosTransactionListItem {
  id: string
  title: string
  customerName: string
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
  borderColor: string
}

export interface PosTransactionsListResponse {
  date: string | null
  listName: string
  transactionsList: PosTransactionListItem[]
  footBtn: string
  hasMore: boolean
}
