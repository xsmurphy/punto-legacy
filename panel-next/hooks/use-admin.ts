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
