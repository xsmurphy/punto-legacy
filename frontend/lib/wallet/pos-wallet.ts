/**
 * Wallet desde la CAJA (context/74 F2, D12-D15).
 *
 * Dos operaciones, con reglas de red opuestas — por eso viven separadas:
 *
 *   - CARGAR saldo es una VENTA (la línea `walletLoad` del carrito). Se
 *     factura, se cobra con los medios normales y funciona SIN red como
 *     cualquier venta emitida (D15). No hay endpoint propio: va por
 *     `/v1/sales` o la cola offline.
 *
 *   - PAGAR con saldo es un COMPROBANTE INTERNO de consumo (D12), siempre
 *     ONLINE: el saldo es compartido entre cajas y el chequeo + débito los hace
 *     el servidor en una sola operación (§5). Nunca se aprueba contra un saldo
 *     guardado en el dispositivo (§10).
 *
 * Si el bolsillo no alcanza (D14), la diferencia se cobra como una CARGA
 * automática y después el consumo se paga entero con saldo — así toda factura
 * sale al cargar y ningún consumo emite factura. Lo orquesta `pay-dialog.tsx`.
 *
 * Todo pasa por `posApi` (Bearer del device + `X-Operator-Token`): los permisos
 * `pos.wallet.*` los evalúa el servidor contra la PERSONA del PIN.
 */

import { posApi } from "@/lib/api/pos-client"
import { ApiError } from "@/lib/api-client"
import type { CartLine } from "@/lib/cart/store"
import type { PaymentMethodConfig, PosWalletPocket } from "@/lib/types/pos-bootstrap"

/** Claves de permiso del operador (se bajan con el PIN, `unlock-pin`). */
export const POS_WALLET_LOAD = "pos.wallet.load"
export const POS_WALLET_SPEND = "pos.wallet.spend"

/** Nombre canónico de la línea de carga — el mismo que persiste el servidor. */
export const WALLET_LOAD_ITEM_NAME = "Carga de saldo"

/**
 * El medio "Saldo" del cobro. No es una fila del catálogo de medios de pago
 * del comercio: existe solo con el módulo prendido y lo arma la caja. Nunca
 * viaja en una VENTA (`SaleInput` lo rechaza): el cobro con saldo es otro
 * documento, con su débito atómico.
 */
export const WALLET_PAYMENT_METHOD: PaymentMethodConfig = {
  id: "wallet",
  name: "Saldo",
  hasChange: false,
  requiresIdentifier: false,
  systemKey: "wallet",
}

export interface PosWalletBalance {
  pocketId: string
  name: string
  active: boolean
  balance: number
}

export interface PosWalletBalances {
  contactId: string
  isChild: boolean
  balances: PosWalletBalance[]
}

/** Saldo del cliente por bolsillo. Online siempre (ver docblock del módulo). */
export async function fetchWalletBalances(contactId: string): Promise<PosWalletBalances> {
  const data = await posApi.get<PosWalletBalances>(
    `/v1/pos-wallet?resource=balances&contactId=${encodeURIComponent(contactId)}`,
  )
  return {
    contactId: data.contactId,
    isChild: data.isChild === true,
    balances: (data.balances ?? []).map((b) => ({
      pocketId: b.pocketId,
      name: b.name,
      active: b.active === true,
      balance: Number(b.balance) || 0,
    })),
  }
}

export interface WalletConsumeRequest {
  pocketId: string
  /** El carrito en el shape de venta (`buildSalePayload`), sin pagos ni número. */
  sale: {
    uid: string
    client: string
    sale: unknown[]
    subtotal: number
    discount: number
    note: string | null
    tags: string[]
    timestamp: number
    date: string
  }
}

export interface WalletConsumeResult {
  transactionId: string
  uid: string
  /** Número del comprobante interno de consumo (talonario propio de la caja). */
  invoiceNo: number | null
  total: number
  pocketId: string
  /** Saldo del bolsillo DESPUÉS del débito. */
  balance: number
  duplicated: boolean
}

/** El servidor rechazó el cobro porque el saldo no alcanza. No escribió nada. */
export class WalletInsufficientError extends Error {
  available: number
  constructor(available: number, message: string) {
    super(message)
    this.available = available
    this.name = "WalletInsufficientError"
  }
}

/**
 * Cobra el carrito con saldo: el servidor crea el comprobante interno (saca
 * stock y congela COGS), numera y debita, todo en una transacción. Idempotente
 * por `sale.uid`: reintentar el MISMO cobro devuelve el comprobante original.
 */
export async function consumeWithWallet(req: WalletConsumeRequest): Promise<WalletConsumeResult> {
  try {
    const data = await posApi.post<WalletConsumeResult>(
      "/v1/pos-wallet?resource=consume",
      req as unknown as Parameters<typeof posApi.post>[1],
    )
    return {
      transactionId: data.transactionId,
      uid: data.uid,
      invoiceNo: data.invoiceNo ?? null,
      total: Number(data.total) || 0,
      pocketId: data.pocketId,
      balance: Number(data.balance) || 0,
      duplicated: data.duplicated === true,
    }
  } catch (err) {
    if (err instanceof ApiError && err.status === 409) {
      const env = err.payload as { error?: { message?: string; details?: { code?: string; available?: number } } } | null
      if (env?.error?.details?.code === "INSUFFICIENT_FUNDS") {
        throw new WalletInsufficientError(
          Number(env.error.details.available ?? 0) || 0,
          env.error.message ?? "El saldo del bolsillo no alcanza",
        )
      }
    }
    throw err
  }
}

/**
 * La línea de carga que va al carrito. `itemId` vacío a propósito: el POS no
 * conoce el ítem de sistema y el servidor lo asigna. El impuesto es el del
 * bolsillo, siempre INCLUIDO: el cliente paga X y recibe X de saldo.
 */
export function walletLoadLine(pocket: PosWalletPocket, amount: number): Omit<CartLine, "lineId"> {
  return {
    itemId: "",
    name: `${WALLET_LOAD_ITEM_NAME} · ${pocket.name}`,
    qty: 1,
    unitPrice: amount,
    basePrice: amount,
    priceOverridden: true,
    taxId: pocket.taxId,
    taxIncluded: true,
    walletLoad: { pocketId: pocket.id, pocketName: pocket.name },
  }
}

/** ¿El carrito tiene alguna línea de carga de saldo? */
export function cartHasWalletLoad(lines: Pick<CartLine, "walletLoad">[]): boolean {
  return lines.some((l) => Boolean(l.walletLoad))
}
