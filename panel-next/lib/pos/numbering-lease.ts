import { api } from '@/lib/api-client'

interface LeaseState {
  from: number
  to: number
  next: number
  leaseId: string
  expiresAt: string
}

const LEASE_KEY = 'pos_numbering_lease'
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
}

export function isLeaseValid(): boolean {
  const lease = loadLease()
  if (!lease) return false
  if (new Date(lease.expiresAt) <= new Date()) return false
  if (lease.next > lease.to) return false
  return true
}

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
    // best-effort, no throw
  } finally {
    refreshing = false
  }
}
