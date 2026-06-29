/**
 * Cliente HTTP del realm ADMIN.
 *
 * Módulo separado de `api-client.ts` (realm tenant) para garantizar que
 * ningún page del tenant pueda accidentalmente llamar endpoints de admin
 * y viceversa. Nunca importar este módulo desde `(panel)/*`.
 *
 * Base path: `/api/admin/` → BFF catch-all → panel/API/v1/admin/*.php
 * Cookie: `_jwt_admin` (HttpOnly, solo la lee el BFF server-side).
 */

type Json = Record<string, unknown> | unknown[]

export class AdminApiError extends Error {
  constructor(
    public status: number,
    public payload: unknown,
    message: string,
  ) {
    super(message)
    this.name = "AdminApiError"
  }
}

const adminBaseUrl = () => {
  // Siempre same-origin — el BFF vive en /api/admin/.
  // No usamos API_URL directo: el BFF del admin maneja el cookie forwarding.
  return "/api/admin"
}

async function request<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const { headers, ...rest } = init

  const hasBody = "body" in rest && rest.body !== undefined && rest.body !== null
  const isMultipart = hasBody && typeof FormData !== "undefined" && rest.body instanceof FormData
  const baseHeaders: Record<string, string> = { Accept: "application/json" }
  if (hasBody && !isMultipart) {
    baseHeaders["Content-Type"] = "application/json"
  }

  const res = await fetch(`${adminBaseUrl()}${path}`, {
    ...rest,
    credentials: "include", // envía las cookies HttpOnly (el BFF lee _jwt_admin)
    headers: {
      ...baseHeaders,
      ...headers,
    },
  })

  const text = await res.text()
  const payload = text ? safeJson(text) : null

  if (!res.ok) {
    const envelope = payload as
      | { ok?: boolean; error?: { message?: string; code?: number } | string }
      | null
    let backendMsg: string | undefined
    if (envelope?.error) {
      backendMsg =
        typeof envelope.error === "string"
          ? envelope.error
          : (envelope.error as { message?: string }).message
    }
    throw new AdminApiError(
      res.status,
      payload,
      backendMsg ?? `${rest.method ?? "GET"} ${path} → ${res.status}`,
    )
  }

  // Unwrap del envelope canónico { ok:true, data:... }.
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

export const apiAdmin = {
  get: <T>(path: string) => request<T>(path, { method: "GET" }),
  post: <T>(path: string, body?: Json) =>
    request<T>(path, {
      method: "POST",
      body: body ? JSON.stringify(body) : undefined,
    }),
  patch: <T>(path: string, body?: Json) =>
    request<T>(path, {
      method: "PATCH",
      body: body ? JSON.stringify(body) : undefined,
    }),
  del: <T>(path: string, body?: Json) =>
    request<T>(path, {
      method: "DELETE",
      body: body ? JSON.stringify(body) : undefined,
    }),
}
