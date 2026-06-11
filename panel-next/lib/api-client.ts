/**
 * Cliente HTTP para la API compartida de Punto (`/api/v1/*`).
 *
 * Base URL via `NEXT_PUBLIC_API_URL` (cliente) o `API_URL` (server).
 * JWT en cookie `_jwt_panel` emitida sobre `.punto.la` por el backend.
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

const baseUrl = () => {
  const url =
    (typeof window === "undefined"
      ? process.env.API_URL
      : process.env.NEXT_PUBLIC_API_URL) ??
    process.env.NEXT_PUBLIC_API_URL
  if (!url) {
    throw new Error(
      "API base URL missing. Set NEXT_PUBLIC_API_URL (client) or API_URL (server).",
    )
  }
  return url.replace(/\/$/, "")
}

async function request<T>(
  path: string,
  init: RequestInit & { jwt?: string } = {},
): Promise<T> {
  const { jwt, headers, ...rest } = init

  // Content-Type SOLO cuando hay body. Mandarlo en GET convierte la request
  // en "no-simple" (CORS) y triggea preflight OPTIONS innecesario — si el
  // servidor preflight devuelve algo distinto a 204/2xx, la request real
  // nunca arranca y el caller ve un 401/CORS error que NO es el problema
  // real. Bug cazado en login → bootstrap (redirect loop).
  const hasBody = "body" in rest && rest.body !== undefined && rest.body !== null
  const baseHeaders: Record<string, string> = { Accept: "application/json" }
  if (hasBody) {
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
    // El envelope { ok:false, error:{ message, code } } trae el mensaje
    // del backend en error.message — lo propagamos al ApiError para que
    // el caller pueda mostrarlo directo en un toast.
    const envelope = payload as
      | { ok?: boolean; error?: { message?: string; code?: number } }
      | null
    const backendMsg = envelope?.error?.message
    throw new ApiError(
      res.status,
      payload,
      backendMsg ?? `${rest.method ?? "GET"} ${path} → ${res.status}`,
    )
  }

  // Unwrappear el envelope canónico de /api ({ ok:true, data:... }) → data.
  // Si la response no viene wrappeada (ej. endpoint legacy), devolvemos el
  // body crudo. Detectamos por shape: { ok, data } con ok=true.
  const envelope = payload as { ok?: boolean; data?: unknown } | null
  if (envelope && typeof envelope === "object" && envelope.ok === true && "data" in envelope) {
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
  put: <T>(path: string, body?: Json, opts?: { jwt?: string }) =>
    request<T>(path, {
      method: "PUT",
      body: body ? JSON.stringify(body) : undefined,
      ...opts,
    }),
  del: <T>(path: string, opts?: { jwt?: string }) =>
    request<T>(path, { method: "DELETE", ...opts }),
}
