/**
 * Cliente HTTP del panel-next. Hace requests SIEMPRE al BFF del propio
 * Next app (Route Handler en `app/api/v1/[...path]/route.ts`), que reenvía
 * a `api.punto.la`. Patrón: Front → BFF → API → BD (decisión owner
 * 2026-06-13).
 *
 * Antes apuntaba directo a `NEXT_PUBLIC_API_URL` → expuesto el backend al
 * browser + CORS + cookies cross-subdomain frágiles + sin logging server-side.
 *
 * El client llama por path RELATIVO (`/v1/contacts`) — el `baseUrl()`
 * devuelve `/api` y se concatena → `/api/v1/contacts` que matchea el
 * catch-all del BFF. Same-origin, sin CORS, cookie viaja sola.
 */

import { VIEW_SCOPE_KEY } from "@/hooks/use-view-scope"

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
 * Cliente: usa el BFF same-origin (`/api`). Server (SSR/Route Handler que
 * importe este módulo): usa `API_URL` directo para no hacer un loop por
 * el BFF dentro del mismo proceso.
 */
const baseUrl = () => {
  if (typeof window === "undefined") {
    const url = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
    if (!url) {
      throw new Error("API base URL missing (server). Set API_URL.")
    }
    return url.replace(/\/$/, "")
  }
  // Cliente — BFF same-origin. Sin env var necesaria.
  return "/api"
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
  const isMultipart = hasBody && typeof FormData !== "undefined" && rest.body instanceof FormData
  const baseHeaders: Record<string, string> = { Accept: "application/json" }
  if (hasBody && !isMultipart) {
    // No setear Content-Type para FormData — fetch lo arma con el boundary correcto.
    baseHeaders["Content-Type"] = "application/json"
  }
  if (jwt) {
    baseHeaders.Authorization = `Bearer ${jwt}`
  }

  // View-scope override del outlet: si el usuario eligió una sucursal o
  // "Todas" desde el dropdown del logo, mandamos `X-Outlet-Id` para que
  // bootstrap.php (realm panel) sobreescriba el `oid` del JWT al filtrar.
  //   - "all" → backend setea OUTLET_ID='' → modo consolidado
  //   - UUID  → backend valida pertenencia al tenant + override del oid
  //   - null  → no mandamos el header → backend usa el oid del JWT
  // Solo en el browser — en server (SSR/route handler) no aplica.
  if (typeof window !== "undefined") {
    const scopeRaw = window.localStorage.getItem(VIEW_SCOPE_KEY)
    if (scopeRaw && scopeRaw !== "") {
      baseHeaders["X-Outlet-Id"] = scopeRaw
    }
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
    // Emitir evento global para que AuthSentinel lo capture — cubre todos los
    // 401 del api-client, no solo el de useBootstrap.
    if (res.status === 401 && typeof window !== "undefined") {
      const backendCode = envelope?.error?.code
      // TODO(arquitectura): cuando migremos los endpoints POS a /v1/pos/* prefix,
      // esto puede simplificarse a un único startsWith check.
      const isPosRoute =
        path.startsWith("/api/pos/") ||
        path.startsWith("/v1/pos/") ||
        // Endpoints POS migrados a apiAuthPosContext (commit e008922)
        path.startsWith("/v1/sales") ||
        path.startsWith("/v1/parked-sales") ||
        path.startsWith("/v1/credit-payments") ||
        path.startsWith("/v1/transactions")
      window.dispatchEvent(
        new CustomEvent(isPosRoute ? "pos:unauthorized" : "api:unauthorized", {
          detail: { path, message: backendMsg ?? "", code: backendCode },
        }),
      )
    }
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
  /** POST con FormData — para uploads multipart. NO setea Content-Type (lo hace fetch). */
  postForm: <T>(path: string, form: FormData, opts?: { jwt?: string }) =>
    request<T>(path, {
      method: "POST",
      body: form,
      ...opts,
    }),
  /**
   * POST a endpoints PHP legacy que leen $_POST['data'][0] con el payload
   * como string JSON (patrón de action.php?action=processData y sales.php).
   * Manda FormData con data[]=<JSON.stringify(payload)>.
   */
  postLegacy: <T>(
    path: string,
    payload: Record<string, unknown>,
    opts?: { jwt?: string },
  ) => {
    const form = new FormData()
    form.append("data[]", JSON.stringify(payload))
    return request<T>(path, { method: "POST", body: form, ...opts })
  },
  put: <T>(path: string, body?: Json, opts?: { jwt?: string }) =>
    request<T>(path, {
      method: "PUT",
      body: body ? JSON.stringify(body) : undefined,
      ...opts,
    }),
  patch: <T>(path: string, body?: Json, opts?: { jwt?: string }) =>
    request<T>(path, {
      method: "PATCH",
      body: body ? JSON.stringify(body) : undefined,
      ...opts,
    }),
  del: <T>(path: string, opts?: { jwt?: string }) =>
    request<T>(path, { method: "DELETE", ...opts }),
  /** URL absoluta para descargas directas (CSV/PDF). */
  url: (path: string) => `${baseUrl()}${path}`,
}
