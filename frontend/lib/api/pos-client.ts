/**
 * Cliente HTTP del POS. Mismo contrato que `lib/api-client.ts`
 * (`api.get/post/put/patch/del/postForm/postLegacy`, unwrap del envelope
 * `{ ok, data }`, `ApiError` en respuestas no-ok) pero autenticado con el
 * Bearer del device (`posFetch`) en vez de la cookie `_jwt_panel` del panel.
 *
 * Por qué existe: el cliente define el contexto de auth — `api-client` es
 * SOLO panel (cookie, sin Bearer); mezclar el Bearer del device ahí (crutch
 * viejo) hacía que el browser del operador (panel cookie + device pareado)
 * autenticara requests del PANEL como DEVICE → outlet scope equivocado
 * (bug real: espacios creados en la sucursal incorrecta del device, no la
 * elegida en el panel). Los call-sites del POS que hoy pegan contra el
 * catch-all `/api/v1/*` (no `/api/pos/*` dedicado con `requireBearer`) usan
 * este cliente para seguir mandando el Bearer explícitamente, con el mismo
 * shape de API que ya conocían de `api-client`.
 */

import { posFetch } from "@/lib/api/pos-fetch"
import { ApiError } from "@/lib/api-client"

type Json = Record<string, unknown> | unknown[]

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const hasBody = "body" in init && init.body !== undefined && init.body !== null
  const isMultipart = hasBody && typeof FormData !== "undefined" && init.body instanceof FormData
  const baseHeaders: Record<string, string> = { Accept: "application/json" }
  if (hasBody && !isMultipart) {
    baseHeaders["Content-Type"] = "application/json"
  }

  const res = await posFetch(`/api${path}`, {
    ...init,
    cache: "no-store",
    headers: { ...baseHeaders, ...init.headers },
  })

  const text = await res.text()
  const payload = text ? safeJson(text) : null

  if (!res.ok) {
    const envelope = payload as
      | { ok?: boolean; error?: { message?: string; code?: number | string } }
      | null
    const backendMsg = envelope?.error?.message
    throw new ApiError(
      res.status,
      payload,
      backendMsg ?? `${init.method ?? "GET"} ${path} → ${res.status}`,
    )
  }

  const envelope = payload as { ok?: boolean; data?: unknown } | null
  if (envelope && typeof envelope === "object" && envelope.ok === true && "data" in envelope) {
    return envelope.data as T
  }
  // Un envelope canónico SIN `data` no se devuelve tal cual: hacerlo entrega el
  // SOBRE como si fuera el contenido, y el consumidor recibe un objeto que
  // existe pero no tiene ningún campo — el detalle de una transacción se pinta
  // entonces como "Sin cliente / Gs 0 / sin items" en vez de fallar (reporte
  // del owner 2026-08-26). Un cliente HTTP no puede fabricar un cuerpo vacío
  // que parezca un dato válido: si el sobre no trae contenido, es un error.
  if (envelope && typeof envelope === "object" && "ok" in envelope) {
    throw new ApiError(
      res.status,
      payload,
      `${init.method ?? "GET"} ${path} → respuesta sin datos (ok=${String(envelope.ok)})`,
    )
  }
  return payload as T
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

export const posApi = {
  get: <T>(path: string) => request<T>(path, { method: "GET" }),
  post: <T>(path: string, body?: Json) =>
    request<T>(path, {
      method: "POST",
      body: body ? JSON.stringify(body) : undefined,
    }),
  /** POST con FormData — para uploads multipart. NO setea Content-Type (lo hace fetch). */
  postForm: <T>(path: string, form: FormData) =>
    request<T>(path, { method: "POST", body: form }),
  /**
   * POST a endpoints PHP legacy que leen $_POST['data'][0] con el payload
   * como string JSON — mismo patrón que `api.postLegacy` de `api-client.ts`.
   */
  postLegacy: <T>(path: string, payload: Record<string, unknown>) => {
    const form = new FormData()
    form.append("data[]", JSON.stringify(payload))
    return request<T>(path, { method: "POST", body: form })
  },
  put: <T>(path: string, body?: Json) =>
    request<T>(path, {
      method: "PUT",
      body: body ? JSON.stringify(body) : undefined,
    }),
  patch: <T>(path: string, body?: Json) =>
    request<T>(path, {
      method: "PATCH",
      body: body ? JSON.stringify(body) : undefined,
    }),
  del: <T>(path: string) => request<T>(path, { method: "DELETE" }),
}
