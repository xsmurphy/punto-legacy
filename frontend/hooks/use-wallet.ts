"use client"

import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  WalletAdjustPayload,
  WalletBalance,
  WalletMovement,
  WalletMovementsPage,
  WalletPocket,
  WalletPocketPayload,
} from "@/lib/types/wallet"

/**
 * Wallet multi-nivel (context/74) — `/v1/wallet`.
 *
 * Todas las keys cuelgan de `["wallet"]`: el realtime (`use-realtime-sync.ts`)
 * invalida ese prefijo entero ante cualquier movimiento o cambio de bolsillo,
 * venga del panel o —desde la F2— de una caja.
 *
 * Sin DELETE de bolsillos: tienen historia y se desactivan (ver el endpoint).
 */

export function useWalletPockets(options?: { enabled?: boolean }) {
  return useQuery<{ pockets: WalletPocket[] }>({
    queryKey: ["wallet", "pockets"],
    queryFn: () => api.get("/v1/wallet?resource=pockets"),
    staleTime: 5 * 60 * 1000,
    enabled: options?.enabled ?? true,
  })
}

export function useCreateWalletPocket() {
  const qc = useQueryClient()
  return useMutation<WalletPocket, Error, WalletPocketPayload>({
    mutationFn: (body) =>
      api.post<WalletPocket>("/v1/wallet?resource=pockets", body as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["wallet"] })
    },
  })
}

export function useUpdateWalletPocket() {
  const qc = useQueryClient()
  return useMutation<WalletPocket, Error, { id: string; values: Partial<WalletPocketPayload> }>({
    mutationFn: ({ id, values }) =>
      api.put<WalletPocket>(
        `/v1/wallet?resource=pockets&id=${encodeURIComponent(id)}`,
        values as unknown as Record<string, unknown>,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["wallet"] })
    },
  })
}

export function useWalletBalances(contactId: string | null | undefined, options?: { enabled?: boolean }) {
  return useQuery<{ balances: WalletBalance[] }>({
    queryKey: ["wallet", "balances", contactId],
    queryFn: () => api.get(`/v1/wallet?resource=balances&contactId=${encodeURIComponent(contactId ?? "")}`),
    enabled: !!contactId && (options?.enabled ?? true),
  })
}

/** Movimientos del más nuevo al más viejo, paginados por cursor (`seq`). */
export function useWalletMovements(
  contactId: string | null | undefined,
  options?: { enabled?: boolean; pageSize?: number },
) {
  const pageSize = options?.pageSize ?? 50
  return useInfiniteQuery<WalletMovementsPage>({
    queryKey: ["wallet", "movements", contactId, pageSize],
    initialPageParam: null as number | null,
    queryFn: ({ pageParam }) => {
      const sp = new URLSearchParams({
        resource: "movements",
        contactId: contactId ?? "",
        limit: String(pageSize),
      })
      if (typeof pageParam === "number") sp.set("beforeSeq", String(pageParam))
      return api.get(`/v1/wallet?${sp.toString()}`)
    },
    getNextPageParam: (last) => last.nextBeforeSeq ?? undefined,
    enabled: !!contactId && (options?.enabled ?? true),
  })
}

export function useAdjustWallet() {
  const qc = useQueryClient()
  return useMutation<WalletMovement, Error, WalletAdjustPayload>({
    mutationFn: (body) =>
      api.post<WalletMovement>("/v1/wallet?resource=adjust", body as unknown as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["wallet"] })
    },
  })
}
