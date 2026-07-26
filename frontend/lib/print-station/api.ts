/**
 * Cliente REST de la Estación de Impresión (P1, context/26-print-station-plan.md).
 *
 * Pega directo a `/v1/*` con el Bearer del device namespaced `print`
 * (`getDeviceToken("print")`) — NUNCA `api-client`, que manda la cookie del
 * panel: un cliente HTTP = un realm (memoria
 * `project_client_per_realm_no_cross_credentials`). Mismo camino que usa el
 * KDS.
 */

import { getDeviceToken } from "@/lib/auth/device-token"
import type { PrintJob, PrinterKind, PrinterTransport, StationPrinter, TransportConfig } from "./types"

const MODULE = "print" as const

function apiBase(): string {
  return process.env.NEXT_PUBLIC_API_URL ?? ""
}

class StationApiError extends Error {}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = getDeviceToken(MODULE)
  if (!token) throw new StationApiError("Dispositivo no pareado")

  const res = await fetch(`${apiBase()}${path}`, {
    ...init,
    headers: {
      ...(init.headers ?? {}),
      Authorization: `Bearer ${token}`,
      ...(init.body ? { "Content-Type": "application/json" } : {}),
    },
  })

  type Envelope = { data?: T; error?: { message?: string } }
  let body: Envelope | null = null
  try {
    body = (await res.json()) as Envelope
  } catch {
    /* respuesta sin JSON */
  }
  if (!res.ok) {
    throw new StationApiError(body?.error?.message ?? `Error ${res.status}`)
  }
  return (body?.data ?? ({} as T)) as T
}

// ── station_printer ────────────────────────────────────────────────────────

/** Impresoras del outlet de esta estación (el realm pos-app ya scopea). */
export async function fetchStationPrinters(): Promise<StationPrinter[]> {
  const data = await request<{ printers?: StationPrinter[] }>("/v1/station-printers")
  return data.printers ?? []
}

export async function registerStationPrinter(input: {
  name: string
  kind: PrinterKind
  transport: PrinterTransport
  transportConfig: TransportConfig
}): Promise<StationPrinter> {
  return request<StationPrinter>("/v1/station-printers", {
    method: "POST",
    body: JSON.stringify(input),
  })
}

// ── print_job ──────────────────────────────────────────────────────────────

/** Jobs `queued` de las impresoras de ESTA estación (el backend filtra por device). */
export async function fetchPendingJobs(): Promise<PrintJob[]> {
  const data = await request<{ jobs?: PrintJob[] }>("/v1/print-jobs?resource=pending")
  return data.jobs ?? []
}

/**
 * CAS queued → printing. Devuelve `{ ok: false, error }` en vez de tirar: en el
 * drenado automático, que otra estación haya tomado el job es lo normal y se
 * saltea sin ruido. El botón "Reintentar" sí muestra el `error` tal cual
 * (un job `failed` con attempts agotados NO vuelve a `queued` — el backend no
 * expone re-encolar y no inventamos endpoints).
 */
export async function claimJob(jobId: string): Promise<{ ok: boolean; error?: string }> {
  try {
    await request<PrintJob>(`/v1/print-jobs?id=${encodeURIComponent(jobId)}&action=claim`, {
      method: "POST",
      body: "{}",
    })
    return { ok: true }
  } catch (err) {
    return { ok: false, error: err instanceof Error ? err.message : String(err) }
  }
}

export async function markJobDone(jobId: string): Promise<void> {
  await request<PrintJob>(`/v1/print-jobs?id=${encodeURIComponent(jobId)}&action=done`, {
    method: "POST",
    body: "{}",
  })
}

export async function markJobFailed(jobId: string, error: string): Promise<void> {
  await request<PrintJob>(`/v1/print-jobs?id=${encodeURIComponent(jobId)}&action=failed`, {
    method: "POST",
    body: JSON.stringify({ error }),
  })
}
