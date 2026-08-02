"use client"

/**
 * Hook de arqueo de caja (Drawer) para el POS.
 *
 * Consulta el estado actual del cajón y expone mutaciones para abrir,
 * cerrar, registrar extracción e ingreso. El BFF vive en
 * `/api/pos/drawer/route.ts`, que proxea a `api/v1/drawer.php` y a
 * `action.php` del POS.
 *
 * El endpoint GET devuelve el envelope canónico { ok, data } que ya
 * maneja api-client. Para las mutaciones POST usamos fetch directo
 * (action.php es legacy y devuelve formato distinto al envelope canónico).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"
import { useCatalogStore } from "@/lib/catalog/store"
import { tenantNow } from "@/lib/format-date"

// ── Tipos ─────────────────────────────────────────────────────────────────────

export interface DrawerSummaryRow {
  name: string
  amount: number
}

export interface DrawerSoldProduct {
  name: string
  qty: number
  total: number
}

export interface DrawerSummary {
  /** Filas de detalle: Caja Inicial + métodos de pago + Extracciones + Ingresos */
  list: DrawerSummaryRow[]
  /** Fecha de apertura (ISO) */
  date: string
  /** Total de efectivo = caja inicial + ventas efectivo + ingresos − extracciones */
  subtotal: number
  /** Total general incluyendo todos los métodos de pago */
  total: number
  /** Total de propinas */
  tips: number
  /** Devoluciones (negativo) */
  returns: number
  /** Productos vendidos en la sesión, agrupados por item, ordenado por monto desc (devoluciones restan) */
  soldProducts: DrawerSoldProduct[]
  /** Cantidad de ventas de la sesión (excluye devoluciones/internas). Opcional: default 0 para tolerar un backend sin deployar. */
  salesCount?: number
  /** Clientes distintos atendidos en la sesión. Opcional: default 0. */
  customersCount?: number
  /** Suma de payments no-return de la sesión (antes de sumar caja inicial/ingresos). Opcional: default 0. */
  salesTotal?: number
}

export interface DrawerStatus {
  isOpen: boolean
}

// ── Query keys ────────────────────────────────────────────────────────────────

export const DRAWER_KEYS = {
  status: ["drawer", "status"] as const,
  summary: ["drawer", "summary"] as const,
}

// ── Helpers de fetch ──────────────────────────────────────────────────────────

async function fetchDrawerStatus(): Promise<DrawerStatus> {
  const res = await posFetch("/api/pos/drawer?check=1", { cache: "no-store" })
  if (!res.ok) throw new Error(`Drawer status error ${res.status}`)
  const json = await res.json()
  // La API devuelve { ok, data: { isOpen } } o { closed: 'Closed' }
  if (json?.data?.isOpen !== undefined) {
    return { isOpen: !!json.data.isOpen }
  }
  // Envelope legacy: { success: 'true' } = abierto, { closed: 'Closed' } = cerrado
  if (json?.success === "true") return { isOpen: true }
  if (json?.closed === "Closed") return { isOpen: false }
  // Fallback seguro
  return { isOpen: false }
}

async function fetchDrawerSummary(): Promise<DrawerSummary | null> {
  const res = await posFetch("/api/pos/drawer", { cache: "no-store" })
  if (!res.ok) throw new Error(`Drawer summary error ${res.status}`)
  const json = await res.json()
  // Envelope canónico { ok, data: { list, date, subtotal, total, tips, returns } }
  if (json?.data && json.data.list) return json.data as DrawerSummary
  // Si el cajón está cerrado la API devuelve { ok, data: { closed: true } }
  if (json?.data?.closed) return null
  if (json?.closed === "Closed") return null
  return null
}

async function postDrawerAction(body: Record<string, unknown>): Promise<void> {
  const res = await posFetch("/api/pos/drawer", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
    cache: "no-store",
  })
  if (!res.ok) {
    const text = await res.text().catch(() => "")
    throw new Error(`Drawer action error ${res.status}: ${text.slice(0, 200)}`)
  }
}

// ── Hooks ─────────────────────────────────────────────────────────────────────

/** Verifica si el cajón está abierto. Se invalida automáticamente después de cada mutación. */
export function useDrawerStatus() {
  return useQuery<DrawerStatus>({
    queryKey: DRAWER_KEYS.status,
    queryFn: fetchDrawerStatus,
    staleTime: 30 * 1000, // 30 s
    retry: false,
  })
}

/** Resumen completo del cajón activo (list de filas, totales). null si cerrado. */
export function useDrawerSummary() {
  return useQuery<DrawerSummary | null>({
    queryKey: DRAWER_KEYS.summary,
    queryFn: fetchDrawerSummary,
    staleTime: 30 * 1000,
    retry: false,
  })
}

// ── Mutaciones ────────────────────────────────────────────────────────────────

function useDrawerMutation(action: string) {
  const qc = useQueryClient()
  // TZ del tenant (PosConfig.timezone). Convención de storage: las fechas de
  // caja se guardan en hora LOCAL del tenant, naive — la misma que las ventas
  // (`transactionDate`). Si se usaran toISOString()/hora del device en otra TZ,
  // `transactionDate > drawerOpenDate` excluiría ventas de la misma sesión.
  // tenantNow() cae a la hora local del device si la TZ no llegó del bootstrap.
  const timezone = useCatalogStore((s) => s.config?.timezone)
  return useMutation({
    mutationFn: (vars: { amount?: number; note?: string; date?: string; user?: string }) =>
      postDrawerAction({
        action,
        amount: vars.amount ?? 0,
        note: vars.note ?? "",
        date: vars.date ?? tenantNow(timezone),
        user: vars.user ?? "",
      }),
    onSuccess: () => {
      // Refrescar estado y resumen después de cualquier acción
      qc.invalidateQueries({ queryKey: DRAWER_KEYS.status })
      qc.invalidateQueries({ queryKey: DRAWER_KEYS.summary })
    },
  })
}

/** Abre la caja con el monto inicial. */
export function useOpenDrawer() {
  return useDrawerMutation("open")
}

/** Cierra la caja con el monto contado. */
export function useCloseDrawer() {
  return useDrawerMutation("close")
}

/** Registra una extracción de efectivo (gasto/sangría). */
export function useDrawerExpense() {
  return useDrawerMutation("expense")
}

/** Registra un ingreso de efectivo. */
export function useDrawerIncome() {
  return useDrawerMutation("income")
}
