/**
 * Cliente JSON del POS — el envelope canónico `{ ok, data, error }` leído en UN
 * solo lugar, para TODOS los hooks del POS.
 *
 * ── Por qué existe ─────────────────────────────────────────────────────────
 *
 * Esta función estaba copiada byte por byte en cinco hooks
 * (`use-pos-spaces`, `use-pos-config`, `use-pos-customer-addresses`,
 * `use-space-settlement`, `use-orders`). Cuatro de esas copias tiraban
 * `new Error(message)` a secas y perdían el `status` HTTP y el `error.details`
 * del envelope. La quinta (`use-orders`) los conservaba, porque la anulación de
 * un ítem los necesitaba para decirle al cajero cuántos minutos pasaron y a
 * quién llamar.
 *
 * Eso convertía "el rechazo del server explica qué hacer" en una propiedad de
 * UN hook y no del POS. Se notó al gatear la cancelación de una MESA con el
 * mismo `OrderCancelGate`: el 422 llegaba con `details`, `use-pos-spaces` los
 * tiraba, y el cajero volvía a leer "no se pudo" pelado. Arreglar esa copia
 * habría dejado tres más esperando el mismo bug — es el caso literal de la
 * regla 5 de `CLAUDE.md` (atacar el wrapper compartido, no el call-site).
 *
 * `PosApiError` extiende `Error`, así que todo consumidor que ya lee
 * `err.message` sigue funcionando igual: la información extra es ADITIVA.
 */

import { posFetch } from "@/lib/api/pos-fetch"

/**
 * Detalles estructurados que el backend adjunta a un rechazo (`apiError(...,
 * $details)` → `error.details` en el envelope). Hoy los usa la anulación de
 * comanda para explicar POR QUÉ no se pudo: sin
 * `windowMinutes`/`elapsedMinutes` el cajero solo leería "no se pudo", que en
 * una caja termina en una llamada al encargado para averiguar qué pasó.
 */
export interface PosApiErrorDetails {
  code?: string
  windowMinutes?: number
  elapsedMinutes?: number
  [key: string]: unknown
}

/**
 * Error de una request del POS, con el `status` HTTP y los `details` del
 * envelope.
 *
 * Existe porque `new Error(message)` pierde justo lo que la UI necesita para
 * decir algo accionable: un 403 (falta el permiso) y un 422 (ventana de
 * anulación vencida) llegaban indistinguibles al call-site, que solo podía
 * repetir el texto del server.
 */
export class PosApiError extends Error {
  readonly status: number
  readonly details: PosApiErrorDetails | null

  constructor(message: string, status: number, details: PosApiErrorDetails | null = null) {
    super(message)
    this.name = "PosApiError"
    this.status = status
    this.details = details
  }
}

/** GET/POST contra `/api/pos/*` devolviendo `data`, o tirando `PosApiError`. */
export async function posJson<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await posFetch(url, init)
  const json = await res.json().catch(() => null)
  if (!res.ok || !json?.ok) {
    throw new PosApiError(
      json?.error?.message ?? `Error ${res.status}`,
      res.status,
      (json?.error?.details as PosApiErrorDetails | undefined) ?? null,
    )
  }
  return json.data as T
}
