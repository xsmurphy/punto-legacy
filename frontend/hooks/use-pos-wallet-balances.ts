"use client"

import { useQuery } from "@tanstack/react-query"
import { useCatalogStore } from "@/lib/catalog/store"
import { useLockStore } from "@/lib/pos/lock-store"
import { useOnlineStatus } from "@/hooks/use-online-status"
import {
  fetchWalletBalances,
  POS_WALLET_LOAD,
  POS_WALLET_SPEND,
  type PosWalletBalances,
} from "@/lib/wallet/pos-wallet"

/**
 * Saldo del cliente por bolsillo, para la caja (context/74 D7).
 *
 * SOLO online y solo "para mostrar": con la red caída devuelve `offline: true`
 * y ningún dato — no se muestra un saldo de hace un rato como si fuera el de
 * ahora, y el cobro con saldo nunca lee de acá (lo decide el servidor, §5).
 *
 * La key cuelga de `["wallet"]`: el realtime invalida ese prefijo ante
 * cualquier movimiento, así que una carga o un consumo hecho en OTRA caja se
 * refleja solo.
 *
 * Se pide únicamente si el módulo está prendido y el operador puede cargar o
 * cobrar con saldo — el endpoint devolvería 403 igual.
 */
export function usePosWalletBalances(contactId: string | null | undefined): {
  enabled: boolean
  offline: boolean
  data: PosWalletBalances | undefined
  isLoading: boolean
  isError: boolean
} {
  const walletPockets = useCatalogStore((s) => s.config?.walletPockets ?? null)
  const perms = useLockStore((s) => s.operatorPermissions)
  const isOnline = useOnlineStatus()

  const moduleOn = walletPockets !== null
  const canSee = perms.includes(POS_WALLET_SPEND) || perms.includes(POS_WALLET_LOAD)
  const enabled = moduleOn && canSee && Boolean(contactId)

  const query = useQuery<PosWalletBalances>({
    queryKey: ["wallet", "pos-balances", contactId],
    queryFn: () => fetchWalletBalances(contactId as string),
    enabled: enabled && isOnline,
    staleTime: 15_000,
    retry: false,
  })

  return {
    enabled,
    offline: enabled && !isOnline,
    data: isOnline ? query.data : undefined,
    isLoading: query.isLoading,
    isError: query.isError,
  }
}
