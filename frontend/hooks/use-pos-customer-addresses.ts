"use client"

/**
 * Hooks de direcciones de cliente para el POS (delivery, context/27-delivery-
 * sla-plan.md PARTE B/D).
 *
 * El device POS corre con Bearer del device (realm `pos-app`), no con la
 * cookie `_jwt_panel` del panel — mismo motivo que `use-pos-outlets.ts` /
 * `use-orders.ts`. Va por `posFetch` contra el BFF `/api/pos/customer-
 * addresses`, que proxea a `/v1/customer_address.php`.
 *
 * NO reusar `useCustomerAddresses`/`useAddAddress` de `hooks/use-contacts.ts`:
 * esos pegan con `api-client` (cookie del panel) — invariante del proyecto
 * "un cliente HTTP = un realm" (memoria `project_client_per_realm_no_cross_
 * credentials`). Mismo backend, query key propia (`["pos","customerAddress",...]`)
 * para no compartir cache con los hooks del panel.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"
import type { CustomerAddress } from "@/lib/types/contact"

async function posJson<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await posFetch(url, init)
  const json = await res.json().catch(() => null)
  if (!res.ok || !json?.ok) {
    throw new Error(json?.error?.message ?? `Error ${res.status}`)
  }
  return json.data as T
}

/** Direcciones cargadas de un cliente — alimenta el selector de delivery del carrito. */
export function useCustomerAddressesPos(customerId: string | undefined | null) {
  return useQuery<CustomerAddress[]>({
    queryKey: ["pos", "customerAddress", customerId],
    queryFn: () =>
      posJson<CustomerAddress[]>(`/api/pos/customer-addresses?customerId=${customerId}`),
    enabled: !!customerId,
    staleTime: 30 * 1000,
  })
}

export interface CreateCustomerAddressPosInput {
  customerId: string
  name: string
  address: string
  location?: string
  city?: string
  /** Referencia verbal ("portón negro, timbre 2") — mig 87. */
  reference?: string
  lat?: number | null
  lng?: number | null
}

/**
 * Alta de dirección desde el diálogo de delivery del carrito ("Nueva
 * dirección"). Devuelve el `id` de la dirección creada
 * (`CustomerAddressService::add` → `RETURNING customerAddressId`) — el
 * diálogo la usa en el acto para snapshotearla en la orden, sin releer la
 * libreta ni adivinar cuál es la nueva.
 */
export function useCreateCustomerAddressPos() {
  const qc = useQueryClient()
  return useMutation<{ ok: boolean; id: string }, Error, CreateCustomerAddressPosInput>({
    mutationFn: (body) =>
      posJson<{ ok: boolean; id: string }>("/api/pos/customer-addresses", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      }),
    onSuccess: (_, vars) => {
      void qc.invalidateQueries({ queryKey: ["pos", "customerAddress", vars.customerId] })
    },
  })
}
