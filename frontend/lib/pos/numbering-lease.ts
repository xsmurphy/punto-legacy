import { posApi as api } from '@/lib/api/pos-client'
import { useNumberingLeaseStore } from '@/lib/pos/numbering-lease-store'

interface LeaseState {
  from: number
  to: number
  next: number
  leaseId: string
  expiresAt: string
}

const LEASE_KEY = 'pos_numbering_lease'
/**
 * Umbral de "quedan pocos". Dispara DOS cosas al cruzarlo: un refresh
 * best-effort en segundo plano (ya existía) y, desde el hallazgo escalado
 * por el owner (2026-08-16, "no puede salir una venta sin número de
 * factura"), una señal VISIBLE para el cajero (`useNumberingLeaseStore`,
 * consumida por `OfflineBanner`) — antes el aviso era mudo: el refresh
 * corría solo, y si fallaba (sin internet) el cajero se enteraba recién
 * cuando el lease llegaba a cero, a mitad de servicio.
 */
const LOW_WATER_MARK = 20
let refreshing = false

function loadLease(): LeaseState | null {
  try {
    const raw = localStorage.getItem(LEASE_KEY)
    if (!raw) return null
    return JSON.parse(raw) as LeaseState
  } catch {
    return null
  }
}

function saveLease(lease: LeaseState): void {
  localStorage.setItem(LEASE_KEY, JSON.stringify(lease))
  syncStoreFromLease(lease)
}

/** Espeja el lease (localStorage, no reactivo) al store de Zustand (reactivo,
 *  para que `OfflineBanner` pueda leerlo) — se llama en cada punto donde el
 *  lease cambia: carga inicial, consumo, refresh. */
function syncStoreFromLease(lease: LeaseState | null): void {
  if (!lease || new Date(lease.expiresAt) <= new Date()) {
    useNumberingLeaseStore.getState().setRemaining(0)
    return
  }
  const remaining = Math.max(0, lease.to - lease.next + 1)
  useNumberingLeaseStore.getState().setRemaining(remaining)
}

/** Llamar una vez al montar el POS (`NumberingLeaseRunner`) para que el
 *  banner tenga el estado real ANTES de la primera venta — sin esto, el
 *  store arranca en `null` (desconocido) hasta el primer `getNextInvoiceNo`. */
export function primeLeaseStatus(): void {
  syncStoreFromLease(loadLease())
}

export function isLeaseValid(): boolean {
  const lease = loadLease()
  if (!lease) return false
  if (new Date(lease.expiresAt) <= new Date()) return false
  if (lease.next > lease.to) return false
  return true
}

/**
 * Consume el siguiente número del lease local. Lanza `NO_LEASE` si no hay
 * lease vigente — el CALLER decide qué hacer con eso (context/08 §53
 * escalado 2026-08-16: para un documento fiscal, NO_LEASE bloquea la
 * emisión, nunca se traga en silencio). Dispara un refresh best-effort en
 * segundo plano en ambos casos (agotado, o por debajo del umbral).
 */
export function getNextInvoiceNo(): number {
  const lease = loadLease()
  if (!lease || new Date(lease.expiresAt) <= new Date() || lease.next > lease.to) {
    void refreshLease()
    throw new Error('NO_LEASE')
  }
  const no = lease.next
  saveLease({ ...lease, next: no + 1 })
  if (lease.to - no < LOW_WATER_MARK) {
    void refreshLease()
  }
  return no
}

/**
 * Renueva el arriendo. Antes el ÚNICO caller era `getNextInvoiceNo` (dentro
 * de una venta) — un dispositivo que arranca offline, o que no vende nada
 * en 24h, nunca tenía chance de pedir números hasta que una venta real lo
 * disparaba y ya era tarde. Ahora también se llama proactivamente: al
 * montar el POS con conexión (`NumberingLeaseRunner`) y al abrir la caja
 * (`useOpenDrawer`), para que "quedarse sin números" sea un caso rarísimo,
 * no el modo normal de operar offline.
 */
export async function refreshLease(count = 100): Promise<void> {
  if (refreshing) return
  refreshing = true
  try {
    const data = await api.post<{ from: number; to: number; leaseId: string; expiresAt: string }>(
      '/v1/numbering/lease',
      { count },
    )
    if (!data || typeof data.from !== 'number') return
    saveLease({
      from: data.from,
      to: data.to,
      next: data.from,
      leaseId: data.leaseId,
      expiresAt: data.expiresAt,
    })
  } catch {
    // best-effort, no throw — el estado visible (store) ya refleja el lease
    // viejo (o 0 si no había ninguno); un refresh fallido no lo empeora.
  } finally {
    refreshing = false
  }
}
