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
import { formatMoney } from "@/lib/format-money"
import { enqueueOp } from "@/lib/pos/pending-ops"
import type { PendingOpKind } from "@/lib/pos/pending-ops"
import {
  loadLocalDrawerState,
  resolveDrawerState,
  saveLocalDrawerState,
  type LocalDrawerState,
} from "@/lib/pos/local-register-state"

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
  /**
   * Solo los métodos de pago reales (sin Caja Inicial / Extracciones / Ingresos),
   * ya filtrados por el backend en `composeSummary` — nunca filtrar `list` por
   * nombre acá. Opcional: default `[]` para tolerar un backend sin deployar.
   */
  paymentBreakdown?: DrawerSummaryRow[]
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
  /**
   * Apertura del turno en curso según lo que este device sabe (naive
   * tenant-local). Solo la conoce si la apertura la hizo él; `null` si el
   * turno se abrió desde otro lado y todavía no se pudo leer el resumen.
   */
  openDate: string | null
  /**
   * `true` = la respuesta salió del estado local, no del servidor. Es lo que
   * le permite a la pantalla de Control de Caja decir de dónde viene lo que
   * está mostrando en vez de afirmarlo a secas.
   */
  fromCache: boolean
}

/** Un bucket horario. `hour` es naive tenant-local: "2026-08-02 14:00". */
export interface DrawerHourlyRow {
  hour: string
  salesTotal: number
  salesCount: number
}

/**
 * Ventas por hora de la caja, en tres series independientes.
 * Resource aparte del summary — ver `api/v1/drawer.php?resource=hourlyStats`.
 *
 * `shift` es la ventana del TURNO (puede abarcar varios días calendario);
 * `today`/`yesterday` son días calendario locales del tenant. Con un turno
 * dentro de hoy, `shift` ≈ `today` — es la misma plata vista de dos formas,
 * no se suman.
 */
export interface DrawerHourlyStats {
  timezone?: string
  /** Backend sin deployar → `[]` (el consumidor cae a hoy/ayer). */
  shift: DrawerHourlyRow[]
  today: DrawerHourlyRow[]
  yesterday: DrawerHourlyRow[]
}

// ── Query keys ────────────────────────────────────────────────────────────────

export const DRAWER_KEYS = {
  status: ["drawer", "status"] as const,
  summary: ["drawer", "summary"] as const,
  hourly: ["drawer", "hourlyStats"] as const,
}

// ── Helpers de fetch ──────────────────────────────────────────────────────────

async function fetchDrawerStatusFromServer(): Promise<boolean> {
  const res = await posFetch("/api/pos/drawer?check=1", { cache: "no-store" })
  if (!res.ok) throw new Error(`Drawer status error ${res.status}`)
  const json = await res.json()
  // La API devuelve { ok, data: { isOpen } } o { closed: 'Closed' }
  if (json?.data?.isOpen !== undefined) return !!json.data.isOpen
  // Envelope legacy: { success: 'true' } = abierto, { closed: 'Closed' } = cerrado
  if (json?.success === "true") return true
  if (json?.closed === "Closed") return false
  // Fallback seguro
  return false
}

/**
 * ¿Está la caja abierta? Con red o sin ella.
 *
 * Antes, sin conexión, esto tiraba y el `?? false` de los consumidores decía
 * "caja cerrada" — con el turno abierto desde la mañana y el cajero cobrando.
 * Ahora la respuesta se compone: verdad del servidor si se pudo leer (y se
 * cachea), último cache si no, y encima las aperturas y cierres que están en
 * cola. Esa última capa es la que hace que abrir la caja sin red se vea
 * abierta al instante y que siga abierta después de reiniciar la tablet.
 */
async function fetchDrawerStatus(registerId: string): Promise<DrawerStatus> {
  let serverState: LocalDrawerState | null = null
  try {
    const isOpen = await fetchDrawerStatusFromServer()
    // El `?check=1` solo dice sí/no. La fecha de apertura, cuando la sabemos,
    // viene de una apertura hecha por este device — se conserva mientras el
    // servidor siga diciendo que el turno está abierto.
    const prev = await loadLocalDrawerState(registerId)
    serverState = {
      isOpen,
      openDate: isOpen ? (prev?.openDate ?? null) : null,
      openAmount: isOpen ? (prev?.openAmount ?? 0) : 0,
    }
    // Se cachea la verdad del SERVIDOR, sin lo pendiente aplicado: lo
    // pendiente vive en su propia cola y se aplica al leer. Mezclarlos acá
    // haría que una operación descartada quedara igual pegada al cache.
    await saveLocalDrawerState(registerId, serverState)
  } catch {
    // Sin respuesta: `resolveDrawerState` cae al cache local.
  }
  const resolved = await resolveDrawerState(registerId, serverState)
  return { isOpen: resolved.isOpen, openDate: resolved.openDate, fromCache: serverState === null }
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

async function fetchDrawerHourlyStats(): Promise<DrawerHourlyStats> {
  const res = await posFetch("/api/pos/drawer?resource=hourlyStats", { cache: "no-store" })
  if (!res.ok) throw new Error(`Drawer hourlyStats error ${res.status}`)
  const json = await res.json()
  const data = json?.data ?? {}
  // Caja cerrada → { closed: true, shift: [], today: [], yesterday: [] }.
  // Backend sin deployar → sin `data.today`/`data.shift` ⇒ arrays vacíos (el
  // chart cae al hint en vez de romper).
  return {
    timezone: data.timezone,
    shift: Array.isArray(data.shift) ? (data.shift as DrawerHourlyRow[]) : [],
    today: Array.isArray(data.today) ? (data.today as DrawerHourlyRow[]) : [],
    yesterday: Array.isArray(data.yesterday) ? (data.yesterday as DrawerHourlyRow[]) : [],
  }
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
  const registerId = useCatalogStore((s) => s.activeRegisterId)
  return useQuery<DrawerStatus>({
    queryKey: [...DRAWER_KEYS.status, registerId],
    queryFn: () => fetchDrawerStatus(registerId),
    staleTime: 30 * 1000, // 30 s
    retry: false,
  })
}

/**
 * Resumen completo del cajón activo (list de filas, totales). null si cerrado.
 *
 * A diferencia del estado, el resumen NO se cachea para leerlo sin red, y es
 * una decisión, no un olvido: es el total del turno según el servidor, y un
 * total viejo mostrado en una pantalla de arqueo se lee como el total de
 * ahora. Sin conexión la query falla y Control de Caja lo dice — el cierre a
 * ciegas es preferible a un número que parece completo y no lo está. Ver
 * `context/51`.
 */
export function useDrawerSummary() {
  return useQuery<DrawerSummary | null>({
    queryKey: DRAWER_KEYS.summary,
    queryFn: fetchDrawerSummary,
    staleTime: 30 * 1000,
    retry: false,
  })
}

/**
 * Ventas por hora del turno (hoy) y del día local anterior (ayer), misma caja.
 * `enabled` para no pedirlo con la caja cerrada.
 */
export function useDrawerHourlyStats(enabled = true) {
  return useQuery<DrawerHourlyStats>({
    queryKey: DRAWER_KEYS.hourly,
    queryFn: fetchDrawerHourlyStats,
    staleTime: 60 * 1000,
    retry: false,
    enabled,
  })
}

// ── Mutaciones ────────────────────────────────────────────────────────────────

/**
 * Texto con el que la operación aparece en la lista de pendientes. Se congela
 * al encolar porque cuando el cajero la mire —tal vez tras un rechazo, tal vez
 * al día siguiente— el estado del que se derivaría ya no va a existir.
 */
type MoneyConfig = Parameters<typeof formatMoney>[1]
const DRAWER_OP_LABEL: Record<string, (amount: number, cfg: MoneyConfig) => string> = {
  open: (a, c) => `Abrir caja — ${formatMoney(a, c)}`,
  close: (a, c) => `Cerrar caja — ${formatMoney(a, c)} contados`,
  expense: (a, c) => `Extracción de efectivo — ${formatMoney(a, c)}`,
  income: (a, c) => `Ingreso de efectivo — ${formatMoney(a, c)}`,
}

/** Acción del endpoint → operación de la cola. */
const DRAWER_OP_KIND: Record<string, PendingOpKind> = {
  open: "drawerOpen",
  close: "drawerClose",
  expense: "drawerExpense",
  income: "drawerIncome",
}

function useDrawerMutation(action: string, onMutated?: () => void) {
  const qc = useQueryClient()
  const registerId = useCatalogStore((s) => s.activeRegisterId)
  const fmtConfig = useCatalogStore((s) => s.config)
  // TZ del tenant (PosConfig.timezone). Convención de storage: las fechas de
  // caja se guardan en hora LOCAL del tenant, naive — la misma que las ventas
  // (`transactionDate`). Si se usaran toISOString()/hora del device en otra TZ,
  // `transactionDate > drawerOpenDate` excluiría ventas de la misma sesión.
  // tenantNow() cae a la hora local del device si la TZ no llegó del bootstrap.
  const timezone = useCatalogStore((s) => s.config?.timezone)
  return useMutation({
    mutationFn: async (vars: { amount?: number; note?: string; date?: string; user?: string }) => {
      const amount = vars.amount ?? 0
      const note = vars.note ?? ""
      // La fecha es la del MOMENTO EN QUE SE OPERÓ, no la del envío. Es el
      // invariante de siempre (memoria `project_transaction_required_dimensions`)
      // y sin red se vuelve la diferencia entre un turno bien delimitado y uno
      // que empieza tres horas tarde y deja medio arqueo afuera.
      const date = vars.date ?? tenantNow(timezone)

      const enqueueOffline = async (): Promise<void> => {
        await enqueueOp({
          kind: DRAWER_OP_KIND[action],
          // Canal propio y estrictamente ordenado: aplicar un cierre antes de
          // la apertura que lo precede no es un desorden cosmético, es un
          // arqueo mal armado.
          stream: "drawer",
          registerId,
          payload: { amount, date, note },
          label: DRAWER_OP_LABEL[action](amount, fmtConfig),
          // Sin `mergePayload`: dos aperturas o dos extracciones son DOS
          // hechos distintos del turno, no una corrección de la anterior.
        })
      }

      if (typeof navigator !== "undefined" && !navigator.onLine) return enqueueOffline()
      try {
        await postDrawerAction({ action, amount, note, date, user: vars.user ?? "" })
      } catch (err) {
        // Solo el corte de red se encola (fetch tira `TypeError`). Un rechazo
        // del servidor —permiso, caja no seleccionada— es una respuesta y le
        // tiene que llegar al cajero como error.
        if (err instanceof TypeError) return enqueueOffline()
        throw err
      }
    },
    onSuccess: () => {
      // Refrescar estado y resumen después de cualquier acción
      qc.invalidateQueries({ queryKey: DRAWER_KEYS.status })
      qc.invalidateQueries({ queryKey: DRAWER_KEYS.summary })
      qc.invalidateQueries({ queryKey: DRAWER_KEYS.hourly })
      onMutated?.()
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
