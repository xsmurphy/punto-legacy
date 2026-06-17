import { TransactionsList } from "@/components/domain/transactions/transactions-list"

export default function PosTransactionsPage() {
  return <TransactionsList backHref="/pos" mode="pos" />
}
