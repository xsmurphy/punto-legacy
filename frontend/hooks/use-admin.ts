"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { useRouter } from "next/navigation"
import { apiAdmin, AdminApiError } from "@/lib/api-admin"

// ── Tipos ────────────────────────────────────────────────────────────────────

export interface AdminMe {
  id: string
  email: string
  name: string
}

export interface AdminCompanyRow {
  id: string
  name: string
  companyName: string
  status: string
  plan: number | null
  discount: number | null
  smsCredit: number | null
  country: string
  blocked: number
  suspended: number
  planExpired: boolean | null
  epos: number
  ecom: number
  createdAt: string | null
  customersLastUpdate: string | null
  companyLastUpdate: string | null
  owner?: {
    id: string
    name: string
    secondName: string
    email: string
    phone: string
  } | null
  counts?: {
    outlets: number
    registers: number
  }
}

export interface AdminCompanyDetail extends AdminCompanyRow {
  settingName: string
  slug: string
  balance: number
  companyCategoryId: string
  externalCustomerId: string | null
  partialBlock: number
  autoSMSCredit: boolean | null
  planExpired: boolean | null
  isTrial?: boolean | null
  expiresAt?: string | null
  planName: string
  planPrice: number
  moduleData: Record<string, unknown>
  eposData: Record<string, unknown>
  counts: {
    outlets: number
    registers: number
    users: number
    customers: number
  }
}

export interface AdminPlan {
  code: number
  name: string
  price: number
}

export interface AdminUserRow {
  adminId: string
  email: string
  name: string
  status: number
  lastLoginAt: string | null
  createdAt: string | null
}

export interface BillingRequest {
  id: string
  companyId: string
  companyName: string
  requestedPlanCode: number
  currentPlanCode: number | null
  status: string
  note: string | null
  createdAt: string | null
  resolvedAt: string | null
  resolvedBy: string | null
}

// ── Dashboard / Reports ───────────────────────────────────────────────────────

export interface AdminOverview {
  companies: {
    total: number
    active: number
    trial: number
    suspended: number
    cancelled: number
  }
  mrr: number
  arr: number
  newThisMonth: number
  byPlan: Array<{ planCode: number; planName: string; count: number }>
  byCountry: Array<{ country: string; count: number }>
  newPerMonth: Array<{ month: string; count: number }>
  topAiCredits: Array<{ companyId: string; name: string; balance: number }>
  /** F1 — analíticas SaaS (context/34-admin-saas-plan.md). Aproximaciones documentadas en AdminReportsService. */
  saas: {
    tenantsGoodStanding: number
    tenantsTrial: number
    tenantsDelinquent: number
    churnedThisMonth: number
    aiCreditsConsumedThisMonth: number
  }
  series: {
    mrrByMonth: Array<{ month: string; mrr: number }>
    tenantsByMonth: Array<{ month: string; new: number; churned: number }>
    /** Filas dinámicas: {month, total, [capability]: credits}. Ver aiCapabilities para las keys. */
    aiCreditsByMonth: Array<{ month: string; total: number } & Record<string, number | string>>
    gmvByMonth: Array<{ month: string; gmv: number }>
  }
  /** Keys presentes en series.aiCreditsByMonth (fuera de month/total), orden estable. */
  aiCapabilities: string[]
}

export interface AdminPaymentsResult {
  total: number
  count: number
  rows: Array<{
    date: string | null
    amount: number
    invoice: number
    status: number
    companyId: string
    companyName: string
  }>
}

// ── Salud del tenant (F2) ────────────────────────────────────────────────────

export type TenantHealthLevel = "green" | "yellow" | "red"

export interface AdminHealthSummaryRow {
  companyId: string
  name: string
  score: number
  level: TenantHealthLevel
  computedAt: string | null
  topIssue: string | null
}

export interface AdminHealthChecklistItem {
  key: string
  title: string
  detail: string
  priority: "high" | "medium" | "low"
}

export interface AdminHealthHistoryPoint {
  week: string
  score: number
  level: TenantHealthLevel
}

export interface AdminHealthSignals {
  activity: {
    subscore: number
    hadSalesEver: boolean
    daysSinceLastSale: number | null
    sales30d: number
    salesPrev30d: number
  }
  breadth: {
    subscore: number
    modules: Record<string, { active: boolean; used: boolean; count30d: number }>
  }
  depth: {
    subscore: number
    catalogItems: number
    paymentMethodsNonCash: number
    printerBindings: number
    printTemplates: number
    usersNonOwner: number
    outlets: number
    checks: Record<string, boolean>
  }
  team: {
    subscore: number
    totalUsers: number
    activeUsers14d: number
    totalDevices: number
    activeDevices14d: number
  }
  ai: {
    subscore: number
    everUsed: boolean
    consumed7d: number
    consumedPrev7d: number
  }
  commercial: {
    subscore: number
    status: string
    blocked: boolean
    planExpired: boolean
    expiresAt: string | null
    daysToExpire: number | null
  }
}

export interface AdminHealthDetail {
  companyId: string
  score: number
  level: TenantHealthLevel
  computedAt: string | null
  signals: AdminHealthSignals
  checklist: AdminHealthChecklistItem[]
  history: AdminHealthHistoryPoint[]
}

export function useAdminHealthList() {
  return useQuery<AdminHealthSummaryRow[]>({
    queryKey: ["admin", "health"],
    queryFn: () => apiAdmin.get<AdminHealthSummaryRow[]>("/health.php"),
    staleTime: 60 * 1000,
  })
}

export function useAdminHealthDetail(companyId: string) {
  return useQuery<AdminHealthDetail>({
    queryKey: ["admin", "health", companyId],
    queryFn: () => apiAdmin.get<AdminHealthDetail>(`/health.php?companyId=${encodeURIComponent(companyId)}`),
    enabled: !!companyId,
    staleTime: 60 * 1000,
  })
}

export function useAdminRecomputeHealth() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (companyId: string) =>
      apiAdmin.post<AdminHealthDetail>(`/health.php?action=recompute&companyId=${encodeURIComponent(companyId)}`),
    onSuccess: (_res, companyId) => {
      qc.invalidateQueries({ queryKey: ["admin", "health", companyId] })
      qc.invalidateQueries({ queryKey: ["admin", "health"] })
    },
  })
}

// ── Audit ─────────────────────────────────────────────────────────────────────

export interface AdminAuditRow {
  id: string
  adminId: string | null
  adminEmail: string
  action: string
  targetType: string | null
  targetId: string | null
  targetName: string | null
  meta: Record<string, unknown>
  ip: string | null
  createdAt: string | null
}

export interface AdminAuditResult {
  rows: AdminAuditRow[]
  total: number
  page: number
  pageSize: number
}

export interface BillingDetail {
  balance: number
  planCode: number
  planName: string
  planPrice: number
  aiCreditsBalance: number
  aiLedger: Array<{
    id: string
    delta: number
    balanceAfter: number
    reason: string
    tokensIn: number
    tokensOut: number
    createdAt: string | null
  }>
  payments: Array<{
    id: string
    date: string | null
    amount: number
    order: number
    invoice: number
    status: number
  }>
}

// ── Auth ─────────────────────────────────────────────────────────────────────

export function useAdminMe() {
  return useQuery<AdminMe>({
    queryKey: ["admin", "me"],
    queryFn: () => apiAdmin.get<AdminMe>("/me.php"),
    staleTime: 5 * 60 * 1000,
    retry: (failureCount, err) => {
      // No reintentar 401 — el guard redirige a /admin/login.
      if (err instanceof AdminApiError && err.status === 401) return false
      return failureCount < 2
    },
  })
}

export function useAdminLogout() {
  const router = useRouter()
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => apiAdmin.post("/logout"),
    onSettled: () => {
      qc.clear()
      router.replace("/admin/login")
    },
  })
}

// ── Empresas ─────────────────────────────────────────────────────────────────

export function useAdminCompanies(params?: {
  limit?: number
  offset?: number
  q?: string
  status?: string
  plan?: number | string
  blocked?: number | string
  sort?: "health_asc" | "health_desc"
  page?: number
  pageSize?: number
}) {
  const qs = new URLSearchParams()
  if (params?.limit) qs.set("limit", String(params.limit))
  if (params?.offset) qs.set("offset", String(params.offset))
  if (params?.q) qs.set("q", params.q)
  if (params?.status && params.status !== "all") qs.set("status", params.status)
  if (params?.plan != null && params.plan !== "") qs.set("plan", String(params.plan))
  if (params?.blocked != null && params.blocked !== "") qs.set("blocked", String(params.blocked))
  if (params?.sort) qs.set("sort", params.sort)
  if (params?.page != null) qs.set("page", String(params.page))
  if (params?.pageSize != null) qs.set("pageSize", String(params.pageSize))
  const search = qs.toString() ? `?${qs.toString()}` : ""
  return useQuery<{ rows: AdminCompanyRow[]; total: number; limit: number; offset: number; page?: number; pageSize?: number }>({
    queryKey: ["admin", "companies", params],
    queryFn: () =>
      apiAdmin.get(`/companies.php${search}`),
    staleTime: 60 * 1000,
  })
}

export function useAdminCompany(id: string) {
  return useQuery<AdminCompanyDetail>({
    queryKey: ["admin", "company", id],
    queryFn: () => apiAdmin.get(`/companies.php?id=${encodeURIComponent(id)}`),
    enabled: !!id,
    staleTime: 30 * 1000,
  })
}

export function useAdminPlans() {
  return useQuery<{ rows: AdminPlan[] }>({
    queryKey: ["admin", "plans"],
    queryFn: () => apiAdmin.get("/companies.php?plans=1"),
    staleTime: 10 * 60 * 1000,
  })
}

export function useAdminBilling(companyId: string) {
  return useQuery<BillingDetail>({
    queryKey: ["admin", "billing", companyId],
    queryFn: () =>
      apiAdmin.get(`/companies.php?id=${encodeURIComponent(companyId)}&billing=1`),
    enabled: !!companyId,
    staleTime: 30 * 1000,
  })
}

export function useAdminUpdateCompany() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Record<string, unknown> }) =>
      apiAdmin.patch(`/companies.php?id=${encodeURIComponent(id)}`, data),
    onSuccess: (_res, { id }) => {
      qc.invalidateQueries({ queryKey: ["admin", "company", id] })
      qc.invalidateQueries({ queryKey: ["admin", "companies"] })
    },
  })
}

export function useAdminGrantAiCredits() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, delta, reason }: { id: string; delta: number; reason: string }) =>
      apiAdmin.post(
        `/companies.php?id=${encodeURIComponent(id)}&action=grantAiCredits`,
        { delta, reason },
      ),
    onSuccess: (_res, { id }) => {
      qc.invalidateQueries({ queryKey: ["admin", "billing", id] })
      qc.invalidateQueries({ queryKey: ["admin", "company", id] })
    },
  })
}

export function useAdminSetAddons() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({
      id,
      addons,
    }: {
      id: string
      addons: { extraUsers?: number; extraRegisters?: number; extraItems?: number }
    }) =>
      apiAdmin.post(
        `/companies.php?id=${encodeURIComponent(id)}&action=setAddons`,
        addons,
      ),
    onSuccess: (_res, { id }) => {
      qc.invalidateQueries({ queryKey: ["admin", "company", id] })
    },
  })
}

export function useAdminSoftDelete() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) =>
      apiAdmin.del(`/companies.php?id=${encodeURIComponent(id)}&type=soft`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "companies"] })
    },
  })
}

export function useAdminHardDelete() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, confirm }: { id: string; confirm: string }) =>
      apiAdmin.del(
        `/companies.php?id=${encodeURIComponent(id)}&type=hard`,
        { confirm },
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "companies"] })
    },
  })
}

export function useAdminEnterCompany() {
  return useMutation({
    mutationFn: (id: string) =>
      apiAdmin.post<{ redirectUrl: string }>(
        `/companies.php?id=${encodeURIComponent(id)}&action=enter`,
        {},
      ),
  })
}

// ── F3 — módulos, acciones (suspender/extender trial), facturación,
//        actividad y notas del tenant (context/34-admin-saas-plan.md) ────────

export interface AdminModuleState {
  enabled: boolean
}
export type AdminModulesMap = Record<string, AdminModuleState>

export function useAdminModules(companyId: string) {
  return useQuery<AdminModulesMap>({
    queryKey: ["admin", "modules", companyId],
    queryFn: () => apiAdmin.get(`/companies.php?id=${encodeURIComponent(companyId)}&modules=1`),
    enabled: !!companyId,
    staleTime: 30 * 1000,
  })
}

export function useAdminToggleModule() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, key, enabled }: { id: string; key: string; enabled: boolean }) =>
      apiAdmin.post(`/companies.php?id=${encodeURIComponent(id)}&action=toggleModule`, { key, enabled }),
    onSuccess: (_res, { id }) => {
      qc.invalidateQueries({ queryKey: ["admin", "modules", id] })
      // La dimensión "breadth" de salud depende de qué módulos están activos.
      qc.invalidateQueries({ queryKey: ["admin", "health", id] })
    },
  })
}

export function useAdminSuspend() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => apiAdmin.post(`/companies.php?id=${encodeURIComponent(id)}&action=suspend`, {}),
    onSuccess: (_res, id) => {
      qc.invalidateQueries({ queryKey: ["admin", "company", id] })
      qc.invalidateQueries({ queryKey: ["admin", "companies"] })
    },
  })
}

export function useAdminUnsuspend() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => apiAdmin.post(`/companies.php?id=${encodeURIComponent(id)}&action=unsuspend`, {}),
    onSuccess: (_res, id) => {
      qc.invalidateQueries({ queryKey: ["admin", "company", id] })
      qc.invalidateQueries({ queryKey: ["admin", "companies"] })
    },
  })
}

export function useAdminExtendTrial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, days }: { id: string; days: number }) =>
      apiAdmin.post(`/companies.php?id=${encodeURIComponent(id)}&action=extendTrial`, { days }),
    onSuccess: (_res, { id }) => {
      qc.invalidateQueries({ queryKey: ["admin", "company", id] })
      qc.invalidateQueries({ queryKey: ["admin", "companies"] })
      qc.invalidateQueries({ queryKey: ["admin", "health", id] })
    },
  })
}

export interface AdminInvoiceRow {
  id: string
  type: string
  amountUsd: number
  currency: string
  status: string
  provider: string
  providerInvoiceId: string | null
  paidAt: string | null
  createdAt: string | null
}

export interface AdminTenantPlanRequest {
  id: string
  requestedPlanCode: number
  currentPlanCode: number | null
  status: string
  note: string | null
  createdAt: string | null
  resolvedAt: string | null
  resolvedBy: string | null
}

export function useAdminInvoices(companyId: string) {
  return useQuery<{ invoices: AdminInvoiceRow[]; requests: AdminTenantPlanRequest[] }>({
    queryKey: ["admin", "invoices", companyId],
    queryFn: () => apiAdmin.get(`/companies.php?id=${encodeURIComponent(companyId)}&invoices=1`),
    enabled: !!companyId,
    staleTime: 30 * 1000,
  })
}

export interface AdminTenantAuditRow {
  id: string
  userId: string | null
  outletId: string | null
  realm: string | null
  method: string | null
  endpoint: string | null
  targetId: string | null
  meta: Record<string, unknown>
  ip: string | null
  createdAt: string | null
}

export interface AdminTenantAuditResult {
  rows: AdminTenantAuditRow[]
  total: number
  page: number
  pageSize: number
}

export function useAdminTenantAudit(companyId: string, page = 1, pageSize = 30) {
  return useQuery<AdminTenantAuditResult>({
    queryKey: ["admin", "tenantAudit", companyId, page, pageSize],
    queryFn: () =>
      apiAdmin.get(
        `/companies.php?id=${encodeURIComponent(companyId)}&audit=1&page=${page}&pageSize=${pageSize}`,
      ),
    enabled: !!companyId,
    staleTime: 15 * 1000,
  })
}

export interface AdminTenantNote {
  id: string
  companyId: string
  authorId: string
  authorName: string
  authorEmail: string
  body: string
  createdAt: string | null
}

export interface AdminTenantNotesResult {
  rows: AdminTenantNote[]
  total: number
  page: number
  pageSize: number
}

export function useAdminTenantNotes(companyId: string, page = 1, pageSize = 20) {
  return useQuery<AdminTenantNotesResult>({
    queryKey: ["admin", "tenantNotes", companyId, page, pageSize],
    queryFn: () =>
      apiAdmin.get(
        `/tenant-notes.php?companyId=${encodeURIComponent(companyId)}&page=${page}&pageSize=${pageSize}`,
      ),
    enabled: !!companyId,
    staleTime: 15 * 1000,
  })
}

export function useAdminCreateTenantNote() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ companyId, body }: { companyId: string; body: string }) =>
      apiAdmin.post(`/tenant-notes.php`, { companyId, body }),
    onSuccess: (_res, { companyId }) => {
      qc.invalidateQueries({ queryKey: ["admin", "tenantNotes", companyId] })
    },
  })
}

export function useAdminDeleteTenantNote() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id }: { id: string; companyId: string }) =>
      apiAdmin.del(`/tenant-notes.php?id=${encodeURIComponent(id)}`),
    onSuccess: (_res, { companyId }) => {
      qc.invalidateQueries({ queryKey: ["admin", "tenantNotes", companyId] })
    },
  })
}

// ── Solicitudes ───────────────────────────────────────────────────────────────

export function useAdminRequests(status: string = "pending") {
  const qs = status ? `?requests=1&status=${encodeURIComponent(status)}` : "?requests=1"
  return useQuery<BillingRequest[]>({
    queryKey: ["admin", "requests", status],
    queryFn: () => apiAdmin.get(`/companies.php${qs}`),
    staleTime: 30 * 1000,
  })
}

export function useAdminResolveRequest() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({
      requestId,
      approve,
    }: {
      requestId: string
      approve: boolean
      companyId?: string
    }) =>
      apiAdmin.post("/companies.php?action=resolveRequest", {
        requestId,
        approve,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "requests"] })
      qc.invalidateQueries({ queryKey: ["admin", "companies"] })
    },
  })
}

// ── Administradores ───────────────────────────────────────────────────────────

export function useAdminUsers() {
  return useQuery<{ rows: AdminUserRow[] }>({
    queryKey: ["admin", "users"],
    queryFn: () => apiAdmin.get("/users.php"),
    staleTime: 2 * 60 * 1000,
  })
}

/** Helper interno: POST FormData a /api/admin/users.php con manejo robusto de errores. */
async function postAdminUserForm(form: FormData, errorMsg: string): Promise<{ ok: boolean }> {
  const res = await fetch("/api/admin/users.php", {
    method: "POST",
    credentials: "include",
    body: form,
  })
  const text = await res.text()
  let json: { ok: boolean; error?: string }
  try {
    json = JSON.parse(text) as { ok: boolean; error?: string }
  } catch {
    throw new Error(`${errorMsg}: respuesta no-JSON del BFF (${res.status})`)
  }
  if (!json.ok) throw new Error(json.error ?? errorMsg)
  return json
}

export function useAdminCreateUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { email: string; name: string; password: string }) => {
      const form = new FormData()
      form.append("action", "create")
      form.append("email", data.email)
      form.append("name", data.name)
      form.append("password", data.password)
      return postAdminUserForm(form, "Error al crear admin")
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "users"] })
    },
  })
}

export function useAdminUpdateUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { id: string; email: string; name: string; password?: string }) => {
      const form = new FormData()
      form.append("action", "update")
      form.append("id", data.id)
      form.append("email", data.email)
      form.append("name", data.name)
      if (data.password) form.append("password", data.password)
      return postAdminUserForm(form, "Error al actualizar admin")
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "users"] })
    },
  })
}

export function useAdminSetUserStatus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { id: string; status: 0 | 1 }) => {
      const form = new FormData()
      form.append("action", "setStatus")
      form.append("id", data.id)
      form.append("status", String(data.status))
      return postAdminUserForm(form, "Error al cambiar estado")
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "users"] })
    },
  })
}

// ── Dashboard / Reports ───────────────────────────────────────────────────────

export function useAdminOverview() {
  return useQuery<AdminOverview>({
    queryKey: ["admin", "overview"],
    queryFn: () => apiAdmin.get<AdminOverview>("/dashboard.php"),
    staleTime: 2 * 60 * 1000,
  })
}

export function useAdminPayments(from: string, to: string) {
  return useQuery<AdminPaymentsResult>({
    queryKey: ["admin", "payments", from, to],
    queryFn: () =>
      apiAdmin.get<AdminPaymentsResult>(
        `/dashboard.php?resource=payments&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
      ),
    staleTime: 60 * 1000,
  })
}

// ── Audit ─────────────────────────────────────────────────────────────────────

export function useAdminAudit(params?: {
  page?: number
  pageSize?: number
  action?: string
  adminId?: string
}) {
  const qs = new URLSearchParams()
  if (params?.page) qs.set("page", String(params.page))
  if (params?.pageSize) qs.set("pageSize", String(params.pageSize))
  if (params?.action) qs.set("action", params.action)
  if (params?.adminId) qs.set("adminId", params.adminId)
  const search = qs.toString() ? `?${qs.toString()}` : ""
  return useQuery<AdminAuditResult>({
    queryKey: ["admin", "audit", params],
    queryFn: () => apiAdmin.get<AdminAuditResult>(`/audit.php${search}`),
    staleTime: 30 * 1000,
  })
}

// ── Planes (F4 — CRUD, context/34) ──────────────────────────────────────────

export interface AdminPlanFull {
  id: string
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
  maxBrands: number
  aiCreditsMonthly: number
  features: Record<string, boolean>
  archived: boolean
  isDefault: boolean
  tenants?: number
}

// Type alias (no interface): los alias ganan index signature implícita y son
// asignables al `Json` que exige apiAdmin.post — una interface acá obliga a
// castear en cada call-site.
export type AdminPlanInput = {
  name?: string
  type?: string
  price?: number
  duration_days?: number
  max_items?: number
  max_users?: number
  max_customers?: number
  max_outlets?: number
  max_registers?: number
  max_suppliers?: number
  max_categories?: number
  max_brands?: number
  ai_credits_monthly?: number
  features?: Record<string, boolean>
}

export function useAdminPlanCatalog() {
  return useQuery<{ rows: AdminPlanFull[] }>({
    queryKey: ["admin", "plan-catalog"],
    queryFn: () => apiAdmin.get("/plans.php?archived=1"),
    staleTime: 30 * 1000,
  })
}

export function useAdminCreatePlan() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: AdminPlanInput) => apiAdmin.post<{ ok: boolean; plan: AdminPlanFull }>("/plans.php", data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "plan-catalog"] })
      qc.invalidateQueries({ queryKey: ["admin", "plans"] })
    },
  })
}

export function useAdminUpdatePlan() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ code, data }: { code: number; data: AdminPlanInput }) =>
      apiAdmin.patch<{ ok: boolean; plan: AdminPlanFull; versioned: boolean; archivedCode?: number }>(
        `/plans.php?code=${code}`,
        data,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "plan-catalog"] })
      qc.invalidateQueries({ queryKey: ["admin", "plans"] })
    },
  })
}

export function useAdminArchivePlan() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (code: number) => apiAdmin.post(`/plans.php?code=${code}&action=archive`, {}),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "plan-catalog"] })
      qc.invalidateQueries({ queryKey: ["admin", "plans"] })
    },
  })
}

// ── Catálogo de módulos (F4 — kill-switch, context/34) ──────────────────────

export interface AdminModuleCatalogEntry {
  key: string
  price: number
  visibility: "ga" | "beta" | "hidden"
  killswitch: boolean
}

export function useAdminModuleCatalog() {
  return useQuery<{ rows: AdminModuleCatalogEntry[] }>({
    queryKey: ["admin", "module-catalog"],
    queryFn: () => apiAdmin.get("/modules.php"),
    staleTime: 30 * 1000,
  })
}

export function useAdminUpdateModuleCatalog() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { key: string; price?: number; visibility?: string; killswitch?: boolean }) =>
      apiAdmin.post("/modules.php", data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "module-catalog"] })
    },
  })
}

// ── Créditos IA (F4 §3, context/34) ──────────────────────────────────────────

export interface AdminAiModel {
  capability: string
  model: string
  enabled: boolean
  creditsPerKToken: number
  updatedAt: string | null
}

export interface AdminAiPackage {
  packageId: string
  name: string
  credits: number
  price: number
  archived: boolean
  createdAt: string | null
}

export interface AdminAiConsumptionRow {
  companyId: string
  companyName: string
  month: string
  capability: string
  credits: number
}

export interface AdminAiConfig {
  models: AdminAiModel[]
  packages: AdminAiPackage[]
  consumption: AdminAiConsumptionRow[]
}

export function useAdminAiConfig() {
  return useQuery<AdminAiConfig>({
    queryKey: ["admin", "ai-config"],
    queryFn: () => apiAdmin.get("/ai-config.php"),
    staleTime: 30 * 1000,
  })
}

export function useAdminUpsertAiModel() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { capability: string; model: string; enabled?: boolean; creditsPerKToken?: number }) =>
      apiAdmin.post("/ai-config.php", { action: "upsertModel", ...data }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "ai-config"] }),
  })
}

export function useAdminCreateAiPackage() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { name: string; credits: number; price: number }) =>
      apiAdmin.post("/ai-config.php", { action: "createPackage", ...data }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "ai-config"] }),
  })
}

export function useAdminUpdateAiPackage() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { packageId: string; name?: string; credits?: number; price?: number }) =>
      apiAdmin.post("/ai-config.php", { action: "updatePackage", ...data }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "ai-config"] }),
  })
}

export function useAdminArchiveAiPackage() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (packageId: string) => apiAdmin.post("/ai-config.php", { action: "archivePackage", packageId }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "ai-config"] }),
  })
}
