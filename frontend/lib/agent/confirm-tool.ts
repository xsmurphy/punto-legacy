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
 * DISEÑO (2026-07-02): register_action recibe SIEMPRE un array `actions` (mínimo
 * 1), nunca una acción suelta. Motivo: pedir "crear Sprite, Coca Zero y Coca
 * Cola" generaba 3 llamadas a register_action → 3 confirmaciones separadas. Con
 * el array, el modelo agrupa todo el lote en una sola llamada → un solo
 * confirmToken → una sola confirmación → execute_action ejecuta el lote entero.
 *
 *   register_action(actions[], summary) → devuelve confirmToken (NO ejecuta)
 *   execute_action(confirmToken)        → ejecuta TODAS las acciones del lote
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

// Una acción individual del lote — schema PLANO: `action` + los campos de
// payload al MISMO nivel (sin objeto `payload` anidado). Motivo (2026-07-07):
// el nesting `{action, payload:{...}}` dentro de `actions[]` hacía que el modelo
// (vía OpenRouter) emitiera mal el primer tool-call → el AI SDK lo rechazaba por
// validación → el modelo narraba "problema técnico, ajuste en el formato" + `{}`
// y recién el 2do intento validaba. Aplanando, el primer intento valida.
// El wire format al backend (`/v1/ai/confirm`) sigue siendo {action, payload}:
// se re-anida en el `execute` de register_action (ver abajo).
const actionItemSchema = payloadSchema.extend({
  action: z.string().describe(
    "create_contact | update_contact | create_item | update_item_price | create_user | create_category | create_brand | create_tag | tabular_import"
  ),
})

async function registerConfirmation(
  cookie: string,
  apiUrl: string,
  actions: Array<{ action: string; payload: unknown }>,
  summary: string,
) {
  console.error("[agent] register_action input", JSON.stringify({ actions, summary }))
  try {
    const res = await fetch(`${apiUrl}/v1/ai/confirm`, {
      method: "POST",
      headers: { "Content-Type": "application/json", cookie },
      body: JSON.stringify({ actions, summary }),
    })
    const bodyText = await res.text()
    console.error("[agent] /v1/ai/confirm", res.status, bodyText.slice(0, 300))
    const json = (bodyText ? JSON.parse(bodyText) : {}) as {
      ok?: boolean
      data?: { confirmToken: string; summary: string; count: number }
      error?: string
    }
    if (!res.ok || !json.ok) {
      return { error: json.error ?? `Error registrando confirmación (${res.status})` }
    }
    return {
      confirmToken: json.data?.confirmToken,
      summary: json.data?.summary,
      count: json.data?.count,
      pendingConfirmation: true,
      message: "Acción(es) pendiente(s) de confirmación del usuario. La UI ya muestra el resumen — NO lo repitas en texto. Esperá su aprobación explícita antes de llamar execute_action.",
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
        "Registra un LOTE de una o más acciones mutantes (crear/editar contacto, ítem, usuario, categoría, marca, etiqueta, o importación tabular) para que el usuario las confirme JUNTAS. NO las ejecuta: devuelve un confirmToken. Si el usuario pidió varios ítems (ej. 'creá Sprite, Coca Zero y Coca Cola'), agrupá TODAS las acciones en un solo llamado con actions=[...] — nunca llames register_action varias veces para un mismo pedido. La UI muestra el resumen como tarjeta — no lo repitas en texto. Recién cuando el usuario confirme, llamá execute_action con ese confirmToken.",
      inputSchema: z.object({
        actions: z.array(actionItemSchema).min(1).describe("Lote de acciones a confirmar juntas (mínimo 1)"),
        summary: z.string().describe("Resumen legible del LOTE completo para mostrar al usuario (ej. 'Crear 3 productos: Sprite, Coca Zero, Coca Cola')"),
      }),
      execute: async ({ actions, summary }) => {
        // Re-anidar plano → {action, payload} que espera el backend, intacto.
        const nested = actions.map(({ action, ...fields }) => ({ action, payload: fields }))
        return registerConfirmation(cookie, apiUrl, nested, summary)
      },
    }),

    execute_action: tool({
      description:
        "Ejecuta el LOTE de acciones YA confirmado por el usuario. Llamala SOLO después de que el usuario confirmó explícitamente, con el confirmToken que devolvió register_action.",
      inputSchema: z.object({
        confirmToken: z.string().describe("Token devuelto por register_action"),
      }),
      execute: async ({ confirmToken }) => executeConfirmation(cookie, apiUrl, confirmToken),
    }),
  }
}
