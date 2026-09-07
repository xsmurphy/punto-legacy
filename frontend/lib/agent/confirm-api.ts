/**
 * Los DOS fetch del embudo de escritura (`/v1/ai/confirm` + `/v1/ai/execute`),
 * sin nada del transporte que los llame.
 *
 * Viven acá porque desde M6 (`context/58`) el embudo tiene DOS clientes: las
 * tools del agente propio (`confirm-tool.ts`, panel y caja) y las tools del
 * server MCP (`app/api/mcp/route.ts`). Lo único que cambia entre ellos es la
 * GUÍA que se le devuelve al modelo —el agente del panel dice "la UI ya muestra
 * la tarjeta", el MCP dice "mostrale el resumen al usuario y esperá su OK"—, no
 * la llamada. Duplicar los fetch habría duplicado también el parseo del error y
 * la forma del wire, que es exactamente lo que después diverge en silencio.
 *
 * El error del backend se devuelve TAL CUAL y no se traduce: del otro lado hay
 * un modelo leyendo, y los mensajes del embudo están escritos para eso — el 403
 * de scope explica cómo emitir la key correcta, el 422 dice qué campo falta. Un
 * "Error 403" genérico le sacaría lo único accionable.
 */

/**
 * Las acciones que el embudo acepta, para DESCRIBÍRSELAS al modelo.
 *
 * La autoridad es `AI_CONFIRM_ALLOWED_ACTIONS` en `api/v1/ai/confirm.php`: acá
 * no se valida nada, y una acción que no esté en el backend vuelve con un 400
 * legible aunque figure en esta lista. Existe porque el error del backend
 * ("Acción no permitida: X") no enumera las válidas, así que sin esto el modelo
 * tendría que adivinar el nombre a fuerza de reintentos.
 *
 * Vive acá y no en `confirm-tool.ts` porque la consumen las DOS superficies —
 * las tools del agente propio y las del server MCP— y tres copias de la misma
 * lista es como se desincroniza.
 */
export const WRITE_ACTIONS = [
  "create_contact",
  "update_contact",
  "create_item",
  "update_item_price",
  "create_user",
  "assign_role",
  "create_category",
  "create_brand",
  "create_tag",
  "create_outlet",
  "update_outlet",
  "create_register",
  "set_fiscal_data",
  "provision_einvoice",
  "tabular_import",
] as const

/** Lo que devuelve `/v1/ai/confirm`: el lote queda registrado, NADA se ejecutó. */
export interface ConfirmRegistration {
  confirmToken: string
  summary: string
  count: number
}

export type ConfirmApiResult<T> = { ok: true; data: T } | { ok: false; error: string }

async function postJson<T>(
  url: string,
  authHeader: string,
  extraHeaders: Record<string, string>,
  body: unknown,
  fallbackError: string,
): Promise<ConfirmApiResult<T>> {
  try {
    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: authHeader, ...extraHeaders },
      body: JSON.stringify(body),
    })
    const bodyText = await res.text()
    console.error(`[agent] ${url}`, res.status, bodyText.slice(0, 300))
    const json = (bodyText ? (JSON.parse(bodyText) as { ok?: boolean; data?: T; error?: string }) : {}) as {
      ok?: boolean
      data?: T
      error?: string
    }
    if (!res.ok || !json.ok) {
      return { ok: false, error: json.error ?? `${fallbackError} (${res.status})` }
    }
    return { ok: true, data: json.data as T }
  } catch (err) {
    // Incluye el JSON.parse de un body que no era JSON (un 502 del proxy, una
    // página de error): el caller necesita algo que decir, no una excepción.
    return { ok: false, error: String(err) }
  }
}

/** Registra el LOTE y devuelve el confirmToken. No ejecuta nada. */
export function postConfirm(
  apiUrl: string,
  authHeader: string,
  extraHeaders: Record<string, string>,
  actions: Array<{ action: string; payload: unknown }>,
  summary: string,
): Promise<ConfirmApiResult<ConfirmRegistration>> {
  return postJson<ConfirmRegistration>(
    `${apiUrl}/v1/ai/confirm`,
    authHeader,
    extraHeaders,
    { actions, summary },
    "Error registrando confirmación",
  )
}

/**
 * Ejecuta el lote atado al token. El token se CONSUME: un reintento con el
 * mismo devuelve 410, y eso es correcto — reintentar a ciegas duplicaría lo que
 * ya se escribió.
 */
export function postExecute(
  apiUrl: string,
  authHeader: string,
  extraHeaders: Record<string, string>,
  confirmToken: string,
): Promise<ConfirmApiResult<unknown>> {
  return postJson<unknown>(
    `${apiUrl}/v1/ai/execute`,
    authHeader,
    extraHeaders,
    { confirmToken },
    "Error ejecutando",
  )
}
