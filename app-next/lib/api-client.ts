/**
 * Cliente HTTP del POS (app-next).
 *
 * Hace requests SIEMPRE al BFF del propio Next app
 * (Route Handler en `app/api/v1/[...path]/route.ts`), que reenvía a la
 * `/api` compartida. Patrón: Front → BFF → API → BD.
 *
 * El front llama por path RELATIVO (`/v1/contacts`) → el `baseUrl()` devuelve
 * `/api` → `/api/v1/contacts` → matchea el catch-all del BFF. Same-origin,
 * sin CORS, cookie `_jwt` viaja sola (realm pos-app).
 *
 * NUNCA apuntar al API_URL directo desde el browser. El BFF es el único
 * punto de salida al backend PHP.
 */

type Json = Record<string, unknown> | unknown[]

export class ApiError extends Error {
  constructor(
    public status: number,
    public payload: unknown,
    message: string,
  ) {
    super(message)
    this.name = "ApiError"
  }
}

/**
 * Browser: BFF same-origin (`/api`).
 * Server (SSR/Route Handler): API_URL directo para no hacer loop por el BFF.
 */
const baseUrl = () => {
  if (typeof window === "undefined") {
    const url = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
    if (!url) {
      throw new Error("API base URL missing (server). Set API_URL.")
    }
    return url.replace(/\/$/, "")
  }
  // Cliente — BFF same-origin. Sin env var necesaria en el browser.
  return "/api"
}

async function request<T>(
  path: string,
  init: RequestInit & { jwt?: string } = {},
): Promise<T> {
  const { jwt, headers, ...rest } = init

  const hasBody = "body" in rest && rest.body !== undefined && rest.body !== null
  const isMultipart =
    hasBody && typeof FormData !== "undefined" && rest.body instanceof FormData
  const baseHeaders: Record<string, string> = { Accept: "application/json" }
  if (hasBody && !isMultipart) {
    baseHeaders["Content-Type"] = "application/json"
  }
  if (jwt) {
    baseHeaders.Authorization = `Bearer ${jwt}`
  }

  const res = await fetch(`${baseUrl()}${path}`, {
    ...rest,
    credentials: "include",
    headers: {
      ...baseHeaders,
      ...headers,
    },
  })

  const text = await res.text()
  const payload = text ? safeJson(text) : null

  if (!res.ok) {
    const envelope = payload as
      | { ok?: boolean; error?: { message?: string; code?: number } }
      | null
    const backendMsg = envelope?.error?.message
    throw new ApiError(
      res.status,
      payload,
      backendMsg ?? `${(rest.method as string | undefined) ?? "GET"} ${path} → ${res.status}`,
    )
  }

  // Unwrap el envelope canónico { ok: true, data: ... } → data.
  const envelope = payload as { ok?: boolean; data?: unknown } | null
  if (
    envelope &&
    typeof envelope === "object" &&
    envelope.ok === true &&
    "data" in envelope
  ) {
    return envelope.data as T
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

export const api = {
  get: <T>(path: string, opts?: { jwt?: string }) =>
    request<T>(path, { method: "GET", ...opts }),

  post: <T>(path: string, body?: Json, opts?: { jwt?: string }) =>
    request<T>(path, {
      method: "POST",
      body: body ? JSON.stringify(body) : undefined,
      ...opts,
    }),

  postForm: <T>(path: string, form: FormData, opts?: { jwt?: string }) =>
    request<T>(path, { method: "POST", body: form, ...opts }),

  put: <T>(path: string, body?: Json, opts?: { jwt?: string }) =>
    request<T>(path, {
      method: "PUT",
      body: body ? JSON.stringify(body) : undefined,
      ...opts,
    }),

  del: <T>(path: string, opts?: { jwt?: string }) =>
    request<T>(path, { method: "DELETE", ...opts }),

  /** URL absoluta para descargas directas. */
  url: (path: string) => `${baseUrl()}${path}`,
}
