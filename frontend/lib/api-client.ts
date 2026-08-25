/**
 * Cliente HTTP del frontend. Hace requests SIEMPRE al BFF del propio
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
 *
 * INVARIANTE DE REALM (decisión de arquitectura, 2026-07-19 — `context/08` §60):
 * **cookie = panel, Bearer = device.** Un cliente HTTP habla UN realm. Este
 * cliente es SOLO panel: autentica por cookie (`credentials: "include"`,
 * `_jwt_panel`) y NUNCA manda `Authorization`. El POS usa el suyo
 * (`lib/api/pos-client.ts` / `lib/api/pos-fetch.ts`), que adjunta el Bearer y
 * no manda cookies (`credentials: "omit"`).
 *
 * Por qué: el browser del operador tiene cookie de panel Y token de device
 * (caja pareada en la misma máquina). Si este cliente adjuntara el Bearer como
 * fallback, el resolver del server —que da precedencia al Bearer— autenticaría
 * la request de PANEL como DEVICE → outlet scope equivocado (bug real: espacios
 * creados en la sucursal del device, no la elegida en el panel).
 *
 * Hasta 2026-08-25 este archivo ofrecía una opción `jwt` que seteaba
 * `Authorization: Bearer` — contradecía la decisión de arriba y no la usaba
 * NINGÚN call-site. Se eliminó: la puerta que no debe existir se cierra, no se
 * deja abierta sin usar. No reintroducirla.
 */

import { VIEW_SCOPE_KEY } from "@/hooks/use-view-scope"

type Json = Record<string, unknown> | unknown[]

/**
 * Contrato mínimo compartido entre `api` (panel, cookie) y `posApi`
 * (POS, Bearer — `lib/api/pos-client.ts`). Hooks consumidos por AMBOS
 * realms (bootstrap, price-lists, printer-bindings, tags, settings) reciben
 * el cliente por parámetro en vez de importar `api` a fuego — así el
 * call-site del POS inyecta `posApi` y el del panel sigue con el default
 * `api`, sin duplicar la lógica de la query/mutation. Ver invariante de
 * realm en el docblock del archivo.
 */
export interface HttpClient {
  get: <T>(path: string) => Promise<T>
  post: <T>(path: string, body?: Json) => Promise<T>
  postForm: <T>(path: string, form: FormData) => Promise<T>
  put: <T>(path: string, body?: Json) => Promise<T>
  patch: <T>(path: string, body?: Json) => Promise<T>
  del: <T>(path: string) => Promise<T>
}

export class ApiError extends Error {
  status: number
  payload: unknown

  // Asignación explícita en el cuerpo, NO parameter properties
  // (`constructor(public status: number, ...)`): el strip-only mode nativo
  // de Node 22 (usado por el runner de impresión, ver
  // frontend/lib/hardware/printers/verify_chain/run.mjs) no soporta esa
  // sintaxis — ERR_UNSUPPORTED_TYPESCRIPT_SYNTAX, tumbaba las 94
  // comprobaciones de impresión del arnés sin que nadie lo notara. Mismo
  // comportamiento en runtime, solo cambia dónde se declaran los campos.
  constructor(status: number, payload: unknown, message: string) {
    super(message)
    this.status = status
    this.payload = payload
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
  init: RequestInit = {},
): Promise<T> {
  const { headers, ...rest } = init

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
  // Acá NO se setea `Authorization`. Este cliente es cookie-pura por decisión
  // de arquitectura — ver el docblock del módulo y `context/08` §60.

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
    // El view-scope de sucursal viaja en el header `X-Outlet-Id`, NO en la URL.
    // Sin no-store el browser puede servir del HTTP cache una respuesta de la
    // sucursal anterior (misma URL) al cambiar de scope → los reportes "no se
    // actualizan". Forzamos red siempre: estos reads son per-outlet/per-tenant.
    cache: "no-store",
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
      | { ok?: boolean; error?: { message?: string; code?: number | string } }
      | null
    const backendMsg = envelope?.error?.message
    // Emitir evento global para que AuthSentinel lo capture — cubre todos los
    // 401 del api-client, no solo el de useBootstrap.
    if (res.status === 401 && typeof window !== "undefined") {
      const backendCode = envelope?.error?.code
      // Este cliente es SOLO panel: un 401 acá significa que la COOKIE del panel
      // murió (expiró a las 24h, se cerró sesión, se revocó) — nunca dice nada
      // sobre la sesión del device.
      //
      // Antes, un heurístico por prefijo de path ("/v1/sales", "/v1/transactions",
      // …) asumía "401 en ruta POS = device muerto" y llamaba `moduleLogout()`,
      // que borra el Bearer del device de localStorage. Como esos endpoints usan
      // `apiAuthPosContext()` (SOLO Bearer) y este cliente NUNCA manda Bearer, el
      // 401 era inevitable: guardar una cotización o anular una venta desde el POS
      // desemparejaba la caja y mostraba "Dispositivo no conectado" con la sesión
      // del device viva en la BD (incidente 2026-07-29, repetido).
      //
      // La ÚNICA señal válida de device muerto es `code: "session_revoked"` del
      // server, y se maneja donde corresponde: `lib/api/pos-fetch.ts`, por donde
      // pasa todo el tráfico autenticado con Bearer. Este cliente no es dueño de
      // esa credencial y no la toca.
      window.dispatchEvent(
        new CustomEvent("api:unauthorized", {
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
  get: <T>(path: string) => request<T>(path, { method: "GET" }),
  post: <T>(path: string, body?: Json) =>
    request<T>(path, {
      method: "POST",
      body: body ? JSON.stringify(body) : undefined,
    }),
  /** POST con FormData — para uploads multipart. NO setea Content-Type (lo hace fetch). */
  postForm: <T>(path: string, form: FormData) =>
    request<T>(path, {
      method: "POST",
      body: form,
    }),
  /**
   * POST a endpoints PHP legacy que leen $_POST['data'][0] con el payload
   * como string JSON (patrón de action.php?action=processData y sales.php).
   * Manda FormData con data[]=<JSON.stringify(payload)>.
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
  /** URL absoluta para descargas directas (CSV/PDF). */
  url: (path: string) => `${baseUrl()}${path}`,
}
