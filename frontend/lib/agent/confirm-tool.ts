import { tool } from "ai"
import { z } from "zod"

/**
 * Tools de acciones mutantes del agente (crear/editar contacto, ítem, usuario,
 * taxonomías, e importación tabular).
 *
 * DISEÑO (2026-06-30): son DOS tools con campos REQUERIDOS, no una sola tool con
 * todo opcional. Motivo: con una tool de campos all-optional, DeepSeek (y otros
 * via OpenRouter) satisface el schema emitiendo `{}` vacío → nunca manda action/
 * payload → el agente muestra el resumen pero "no inserta" (bug reportado). Un
 * schema con campos requeridos obliga al modelo a poblarlos (verificado: una tool
 * con `name`/`price` requeridos se llama correctamente).
 *
 *   register_action(action, payload, summary) → devuelve confirmToken (NO ejecuta)
 *   execute_action(confirmToken)              → ejecuta la acción ya confirmada
 */

// payload con campos EXPLÍCITOS (no z.record/additionalProperties, que los
// modelos no logran poblar). El modelo llena solo los que aplican al `action`.
const payloadSchema = z.object({
  name: z.string().optional().describe("Nombre (contacto, ítem, categoría, marca, etiqueta, usuario)"),
  type: z.number().int().optional().describe("contacto: 1=cliente, 2=proveedor"),
  phone: z.string().optional(),
  email: z.string().optional(),
  note: z.string().optional(),
  id: z.string().optional().describe("id del registro a actualizar (update_*)"),
  kind: z.string().optional().describe("create_item: 'producto'|'servicio'. tabular_import: 'items'|'contacts'"),
  price: z.number().optional().describe("create_item: precio de venta"),
  cost: z.number().optional().describe("create_item: costo"),
  sku: z.string().optional(),
  categoryName: z.string().optional(),
  brandName: z.string().optional(),
  newPrice: z.number().optional().describe("update_item_price: nuevo precio"),
  roleName: z.string().optional().describe("create_user: nombre del rol (no admin)"),
  sessionId: z.string().optional().describe("tabular_import: id de sesión del adjunto"),
  mode: z.string().optional().describe("tabular_import: 'insert'|'update'"),
  mapping: z.record(z.string(), z.string()).nullish().describe("tabular_import: mapeo campo→columna, o null para auto"),
})

async function registerConfirmation(
  cookie: string,
  apiUrl: string,
  action: string,
  payload: unknown,
  summary: string,
) {
  console.error("[agent] register_action input", JSON.stringify({ action, payload, summary }))
  try {
    const res = await fetch(`${apiUrl}/v1/ai/confirm`, {
      method: "POST",
      headers: { "Content-Type": "application/json", cookie },
      body: JSON.stringify({ action, payload, summary }),
    })
    const bodyText = await res.text()
    console.error("[agent] /v1/ai/confirm", res.status, bodyText.slice(0, 300))
    const json = (bodyText ? JSON.parse(bodyText) : {}) as {
      ok?: boolean
      data?: { confirmToken: string; summary: string }
      error?: string
    }
    if (!res.ok || !json.ok) {
      return { error: json.error ?? `Error registrando confirmación (${res.status})` }
    }
    return {
      confirmToken: json.data?.confirmToken,
      summary: json.data?.summary,
      pendingConfirmation: true,
      message: "Acción pendiente de confirmación del usuario. Mostrá el resumen y esperá su aprobación explícita antes de llamar execute_action.",
    }
  } catch (err) {
    return { error: String(err) }
  }
}

async function executeConfirmation(cookie: string, apiUrl: string, confirmToken: string) {
  console.error("[agent] execute_action confirmToken", JSON.stringify(confirmToken))
  try {
    const res = await fetch(`${apiUrl}/v1/ai/execute`, {
      method: "POST",
      headers: { "Content-Type": "application/json", cookie },
      body: JSON.stringify({ confirmToken }),
    })
    const bodyText = await res.text()
    console.error("[agent] /v1/ai/execute", res.status, bodyText.slice(0, 300))
    const json = (bodyText ? JSON.parse(bodyText) : {}) as { ok?: boolean; data?: unknown; error?: string }
    if (!res.ok || !json.ok) {
      return { error: json.error ?? `Error ejecutando (${res.status})` }
    }
    return json.data ?? { ok: true }
  } catch (err) {
    return { error: String(err) }
  }
}

export function makeActionTools(cookie: string, apiUrl: string) {
  return {
    register_action: tool({
      description:
        "Registra una acción mutante (crear/editar contacto, ítem, usuario, categoría, marca, etiqueta, o importación tabular) para que el usuario la confirme. NO la ejecuta: devuelve un confirmToken. Mostrá el summary al usuario y pedí confirmación explícita. Recién cuando confirme, llamá execute_action con ese confirmToken.",
      inputSchema: z.object({
        action: z.string().describe(
          "create_contact | update_contact | create_item | update_item_price | create_user | create_category | create_brand | create_tag | tabular_import"
        ),
        payload: payloadSchema.describe("Datos de la acción — llená los campos que aplican al action"),
        summary: z.string().describe("Resumen legible para mostrar al usuario (ej. 'Crear producto Croqueta de Mandioca a Gs 18.000')"),
      }),
      execute: async ({ action, payload, summary }) =>
        registerConfirmation(cookie, apiUrl, action, payload, summary),
    }),

    execute_action: tool({
      description:
        "Ejecuta una acción YA confirmada por el usuario. Llamala SOLO después de que el usuario confirmó explícitamente, con el confirmToken que devolvió register_action.",
      inputSchema: z.object({
        confirmToken: z.string().describe("Token devuelto por register_action"),
      }),
      execute: async ({ confirmToken }) => executeConfirmation(cookie, apiUrl, confirmToken),
    }),
  }
}
