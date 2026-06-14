/**
 * Shapes de `/v1/billing` — mirror exacto de BillingService.
 *
 * GET /v1/billing          → BillingSummary
 * GET /v1/billing?resource=plans → { plans: BillingPlan[] }
 * GET /v1/billing?resource=ai-ledger → { rows: AiLedgerEntry[] }
 * POST /v1/billing (action=requestPlanChange) → RequestPlanChangeResult
 */

export interface BillingPlanInfo {
  code: number
  name: string
  type: string
  price: number
  durationDays: number
}

export interface BillingUsageRow {
  key: string
  label: string
  used: number
  /** 0 = ilimitado (no mostrar barra). */
  max: number
}

export interface BillingTrial {
  isTrial: boolean
  expiresAt: string | null
  expired: boolean
}

export interface BillingAiCredits {
  balance: number
  monthly: number
}

export interface BillingAddon {
  key: string
  label: string
  qty: number
  unitPrice: number
}

export interface BillingPayment {
  id: string
  date: string | null
  amount: number
  order: number
  invoice: number
  /** 0 = pendiente, 1 = pagado, 2 = reembolsado */
  status: number
}

export interface BillingSummary {
  plan: BillingPlanInfo
  usage: BillingUsageRow[]
  trial: BillingTrial
  balance: number
  smsCredit: number
  aiCredits: BillingAiCredits
  addons: BillingAddon[]
  payments: BillingPayment[]
}

export interface BillingPlan {
  code: number
  name: string
  type: string
  price: number
  durationDays: number
  maxItems: number
  maxUsers: number
  maxCustomers: number
  maxOutlets: number
  maxRegisters: number
  maxSuppliers: number
  maxCategories: number
  aiCreditsMonthly: number
  features: Record<string, unknown>
  isDefault: boolean
}

export interface AiLedgerEntry {
  id: string
  delta: number
  balanceAfter: number
  reason: string
  tokensIn: number
  tokensOut: number
  createdAt: string | null
}

export interface RequestPlanChangeResult {
  ok: boolean
  requestId: string
}
