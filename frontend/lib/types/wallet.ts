/**
 * Wallet multi-nivel (context/74). Shapes de `/v1/wallet`.
 */

export type WalletMovementType = "load" | "transfer" | "spend" | "refund" | "adjust"

export interface WalletPocket {
  id: string
  name: string
  active: boolean
  createdAt: string
  /** Impuesto con el que se FACTURAN las cargas del bolsillo (mig 234). null = sin impuesto. */
  taxId: string | null
  taxName: string | null
  taxRate: number
  taxKind: "rate" | "exempt"
}

/** Valor del select para "sin impuesto" (un SelectItem no admite ""). */
export const WALLET_POCKET_NO_TAX = "none"

export interface WalletPocketPayload {
  name: string
  active: boolean
  /** Id del impuesto, o `WALLET_POCKET_NO_TAX`. El hook lo traduce al contrato del endpoint. */
  taxId: string
}

export interface WalletBalance {
  pocketId: string
  name: string
  active: boolean
  balance: number
}

export interface WalletMovement {
  id: string
  seq: number
  pocketId: string
  pocketName: string
  type: WalletMovementType
  amount: number
  balanceAfter: number
  billingMode: "A" | "B" | null
  transferGroupId: string | null
  counterpartId: string | null
  counterpartName: string | null
  sourceType: string | null
  sourceId: string | null
  reason: string | null
  actorId: string
  actorName: string | null
  createdAt: string
}

export interface WalletMovementsPage {
  movements: WalletMovement[]
  nextBeforeSeq: number | null
}

export interface WalletAdjustPayload {
  contactId: string
  pocketId: string
  /** Con signo: positivo suma, negativo resta. */
  amount: number
  reason: string
}
