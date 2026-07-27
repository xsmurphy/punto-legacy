"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  ContactAnalytics,
  ContactFormValues,
  ContactFull,
  ContactListItem,
  CustomerAddress,
  SoldPack,
} from "@/lib/types/contact"

/**
 * Lista paginada de clientes. El endpoint cap'ea a 1000 server-side.
 * Para tenants con menos de ~1000 contactos el DataTable client-side filtra
 * sobre lo cargado; para tenants más grandes se agrega paginación server-side
 * en un slice futuro (params `limit`, `offset`).
 */
/** Tipo de contacto: 1=cliente, 2=proveedor. Discriminador de la tabla `contact`. */
export type ContactType = 1 | 2

export function useContacts(opts?: { q?: string; status?: string; type?: ContactType }) {
  const type = opts?.type ?? 1
  return useQuery<{
    contacts: ContactListItem[]
    total: number
    limit: number
    offset: number
  }>({
    queryKey: ["contacts", type, opts?.q ?? "", opts?.status ?? ""],
    queryFn: () => {
      const params = new URLSearchParams({ limit: "1000", type: String(type) })
      if (opts?.q) params.set("q", opts.q)
      if (opts?.status !== undefined && opts.status !== "") {
        params.set("status", opts.status)
      }
      return api.get(`/v1/contacts?${params.toString()}`)
    },
    staleTime: 30 * 1000,
  })
}

export function useContact(id: string | undefined) {
  return useQuery<ContactFull>({
    queryKey: ["contacts", id],
    queryFn: () => api.get<ContactFull>(`/v1/contacts?id=${id}`),
    enabled: !!id,
    staleTime: 30 * 1000,
  })
}

/**
 * Analítica de comportamiento del contacto: KPIs, top items, distribuciones
 * temporales, segmento RFM-lite, estado financiero. Alimenta los tabs
 * "Resumen" / "Comportamiento" / "Financiero" del perfil.
 *
 * Reusa el mismo endpoint `/v1/contacts` con `?resource=analytics` —
 * misma cookie de auth, sin BFF intermedio.
 */
export function useContactAnalytics(id: string | undefined, type: ContactType = 1) {
  return useQuery<ContactAnalytics>({
    queryKey: ["contacts", id, "analytics", type],
    queryFn: () =>
      api.get<ContactAnalytics>(`/v1/contacts?id=${id}&resource=analytics&type=${type}`),
    enabled: !!id,
    // Analytics agregadas son caras de computar (varios SQL) y cambian lento;
    // 2 min de cache es suficiente para evitar refetches al navegar entre tabs.
    staleTime: 2 * 60 * 1000,
  })
}

/**
 * Crea un contacto. POST /v1/contacts (verbo REST estándar, no action=create
 * como outlets). Backend valida que name o fiscalName estén presentes; sin
 * uno de los dos → 422.
 */
export function useCreateContact() {
  const qc = useQueryClient()
  return useMutation<ContactFull, Error, { values: ContactFormValues; type?: ContactType }>({
    mutationFn: ({ values, type = 1 }) =>
      api.post<ContactFull>("/v1/contacts", { ...serialize(values), type }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["contacts"] })
    },
  })
}

/**
 * Update parcial. El endpoint es PUT — el patch incluye solo los campos
 * tocados. Acá mandamos el form completo para simplificar; el backend
 * ignora valores sin cambios efectivos (UPDATE rewrites no-ops).
 */
export function useUpdateContact() {
  const qc = useQueryClient()
  return useMutation<ContactFull, Error, { id: string; values: ContactFormValues }>({
    mutationFn: ({ id, values }) =>
      api.put<ContactFull>(`/v1/contacts?id=${id}`, serialize(values)),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["contacts"] })
      qc.invalidateQueries({ queryKey: ["contacts", vars.id] })
    },
  })
}

/**
 * Archive (soft-delete: contactStatus = 0). DELETE /v1/contacts?id=...
 * No es destructivo — el contacto se mantiene en BD y reaparece si se
 * vuelve a poner status=1 desde el form.
 */
export function useArchiveContact() {
  const qc = useQueryClient()
  return useMutation<{ archived: boolean; id: string }, Error, string>({
    mutationFn: (id) => api.del(`/v1/contacts?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["contacts"] })
    },
  })
}

// ── Direcciones de cliente (customerAddress) ─────────────────────────────────

export function useCustomerAddresses(customerId: string | undefined) {
  return useQuery<CustomerAddress[]>({
    queryKey: ["customerAddress", customerId],
    queryFn: () =>
      api.get<CustomerAddress[]>(`/v1/customer_address?customerId=${customerId}`),
    enabled: !!customerId,
    staleTime: 30 * 1000,
  })
}

type AddressBody = {
  customerId: string
  name: string
  address: string
  location: string
  city: string
  /** Referencia verbal ("portón negro, timbre 2") — mig 87. */
  reference?: string
  lat?: number | null
  lng?: number | null
}

export function useAddAddress() {
  const qc = useQueryClient()
  return useMutation<unknown, Error, AddressBody>({
    mutationFn: (body) => api.post("/v1/customer_address", body),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["customerAddress", vars.customerId] })
    },
  })
}

export function useUpdateAddress() {
  const qc = useQueryClient()
  return useMutation<unknown, Error, AddressBody & { addressId: string }>({
    mutationFn: ({ addressId, ...body }) =>
      api.put(`/v1/customer_address?id=${addressId}`, body),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["customerAddress", vars.customerId] })
    },
  })
}

export function useSetDefaultAddress() {
  const qc = useQueryClient()
  return useMutation<unknown, Error, { addressId: string; customerId: string }>({
    mutationFn: ({ addressId, customerId }) =>
      api.put(`/v1/customer_address?id=${addressId}&resource=default`, { customerId }),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["customerAddress", vars.customerId] })
    },
  })
}

export function useDeleteAddress() {
  const qc = useQueryClient()
  return useMutation<unknown, Error, { addressId: string; customerId: string }>({
    mutationFn: ({ addressId, customerId }) =>
      api.del(`/v1/customer_address?id=${addressId}&customerId=${customerId}`),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ["customerAddress", vars.customerId] })
    },
  })
}

/**
 * Packs de servicios vendidos a un contacto. Incluye saldo de componentes y
 * estado (activo / vencido / consumido). El backend hace lazy-expiry on read.
 */
export function useContactPacks(contactId: string | undefined) {
  return useQuery<SoldPack[]>({
    queryKey: ["sold-packs", contactId],
    queryFn: () => api.get<SoldPack[]>(`/v1/sold_pack?contactId=${contactId}`),
    enabled: !!contactId,
    staleTime: 60 * 1000,
  })
}

/** Mapea form values al payload que el endpoint espera (snake-ish keys del legacy). */
function serialize(values: ContactFormValues): Record<string, unknown> {
  // Decidir name vs fiscalName por kind. El backend acepta ambos en el mismo
  // payload pero la convención es: persona → solo `name`; empresa → solo
  // `fiscalName`. Si el user toca el switch persona/empresa, el campo no
  // usado se manda vacío para limpiar (no enviarlo no lo limpia — el PUT
  // ignora ausencias).
  return {
    name: values.kind === "persona" ? values.name : "",
    fiscalName: values.kind === "empresa" ? values.fiscalName : "",
    tin: values.tin,
    ci: values.ci,
    bday: values.bday,
    phone: values.phone ?? "",
    email: values.email,
    note: values.note,
    status: values.status ? 1 : 0,
    priceListId: values.priceListId ?? "",
  }
}
