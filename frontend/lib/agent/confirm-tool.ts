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
 *
 * CONFIGURACIÓN DE LA CUENTA (2026-09-01, context/66 F1): el agente además
 * crea sucursales y cajas y le cambia el rol a un usuario existente — D1 del
 * owner, "ventas no las hace el bot, el resto sí". Las tres acciones son
 * PANEL-ONLY: el backend las rechaza bajo realm `pos-app` (AgentActor), porque
 * configurar el comercio no es tarea de cajero. Los campos obligatorios de cada
 * una los valida `/v1/ai/confirm` ANTES de emitir el confirmToken, así el
 * modelo recibe el "te falta el timbrado" a tiempo para repreguntarlo en vez de
 * mostrar un resumen que iba a fallar.
 *
 * COMPARTIDAS CON LA CAJA (2026-08-31): el asistente del POS usa ESTAS MISMAS
 * dos tools, no una copia. Lo único que cambia entre superficies son los
 * headers: el panel manda su Bearer y nada más, y la caja manda además el
 * `X-Operator-Token` que prueba QUIÉN está operando — sin él el backend
 * rechaza la escritura (ver `extraHeaders` en `makeActionTools`). Duplicar
 * estas definiciones habría duplicado también los schemas y las descripciones,
 * que son la parte que hay que ajustar contra un modelo real.
 */

// payload con campos EXPLÍCITOS (no z.record/additionalProperties, que los
// modelos no logran poblar). El modelo llena solo los que aplican al `action`.
const payloadSchema = z.object({
  name: z.string().optional().describe("Nombre (contacto, ítem, categoría, marca, etiqueta, usuario, sucursal, caja)"),
  type: z.number().int().optional().describe("contacto: 1=cliente, 2=proveedor"),
  phone: z.string().optional(),
  email: z.string().optional(),
  note: z.string().optional(),
  // Dirección default del contacto (create_contact / update_contact). El
  // backend la crea junto con el contacto — no es un paso aparte. `lat`/`lng`
  // van como NÚMEROS: la columna es DECIMAL y el propio panel los tipa
  // `number` (contact-detail-view.tsx), así que un string acá desalinearía al
  // agente del resto del sistema.
  address: z.string().optional().describe("create_contact y update_contact: calle y número de la dirección (ej. 'Av. España 1234')"),
  city: z.string().optional().describe("create_contact y update_contact: ciudad de la dirección"),
  location: z.string().optional().describe("create_contact y update_contact: barrio o zona de la dirección"),
  lat: z.number().optional().describe("create_contact y update_contact: latitud decimal de la dirección (ej. -25.2867). Solo si la sabés con certeza — NUNCA inventes ni estimes coordenadas. Va SIEMPRE junto con lng: una sola de las dos se rechaza"),
  lng: z.number().optional().describe("create_contact y update_contact: longitud decimal de la dirección (ej. -57.3333). Va SIEMPRE junto con lat"),
  id: z.string().optional().describe("id del registro a actualizar (update_*), o del usuario al que se le cambia el rol (assign_role)"),
  kind: z.string().optional().describe("create_item: 'producto'|'servicio'. tabular_import: 'items'|'contacts'"),
  price: z.number().optional().describe("create_item: precio de venta"),
  cost: z.number().optional().describe("create_item: costo"),
  sku: z.string().optional(),
  categoryName: z.string().optional(),
  brandName: z.string().optional(),
  newPrice: z.number().optional().describe("update_item_price: nuevo precio"),
  roleName: z.string().optional().describe("create_user y assign_role: nombre del rol tal como existe en el comercio (ej. 'Cajero', 'Encargado'). No admin. Si no sabés qué roles hay, mirá los usuarios existentes antes de proponer la acción"),
  outletId: z.string().optional().describe("create_register: id de la sucursal donde va la caja"),
  outletName: z.string().optional().describe("create_register: nombre de la sucursal donde va la caja, si no tenés el id (ej. 'Central')"),
  timbrado: z.string().optional().describe("create_register: número de timbrado que la SET le autorizó a la caja, solo dígitos. OBLIGATORIO para crear una caja — si el usuario no lo dio, pedíselo antes de registrar la acción"),
  expeditionPoint: z.string().optional().describe("create_register: establecimiento y punto de expedición de la caja, formato EEE-PPP (ej. 001-001). OBLIGATORIO. Dos cajas NO pueden tener el mismo punto de expedición con el mismo timbrado — si el usuario abre varias cajas, pedile uno distinto para cada una"),
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
    "create_contact | update_contact | create_item | update_item_price | create_user | assign_role | create_category | create_brand | create_tag | create_outlet | create_register | tabular_import"
  ),
})

async function registerConfirmation(
  authHeader: string,
  apiUrl: string,
  extraHeaders: Record<string, string>,
  actions: Array<{ action: string; payload: unknown }>,
  summary: string,
) {
  console.error("[agent] register_action input", JSON.stringify({ actions, summary }))
  try {
    const res = await fetch(`${apiUrl}/v1/ai/confirm`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: authHeader, ...extraHeaders },
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

async function executeConfirmation(
  authHeader: string,
  apiUrl: string,
  extraHeaders: Record<string, string>,
  confirmToken: string,
) {
  console.error("[agent] execute_action confirmToken", JSON.stringify(confirmToken))
  try {
    const res = await fetch(`${apiUrl}/v1/ai/execute`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: authHeader, ...extraHeaders },
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

/**
 * @param extraHeaders headers adicionales para los DOS fetches. La caja manda
 *   acá su `X-Operator-Token`: es la prueba de identidad de la persona que
 *   tipeó el PIN, y sin ella `/v1/ai/confirm` y `/v1/ai/execute` responden 403
 *   bajo realm `pos-app` (el Bearer del device es del mueble, no de nadie). El
 *   panel no pasa nada: ahí la credencial YA es la persona. Nunca se manda una
 *   cookie por acá — la caja es token-only (context/08 §60).
 */
export function makeActionTools(
  authHeader: string,
  apiUrl: string,
  extraHeaders: Record<string, string> = {},
) {
  return {
    register_action: tool({
      description:
        "Registra un LOTE de una o más acciones mutantes (crear/editar contacto, ítem, usuario, categoría, marca, etiqueta; cambiarle el rol a un usuario; crear una sucursal o una caja; o importación tabular) para que el usuario las confirme JUNTAS. NO las ejecuta: devuelve un confirmToken. Si el usuario pidió varios ítems (ej. 'creá Sprite, Coca Zero y Coca Cola'), agrupá TODAS las acciones en un solo llamado con actions=[...] — nunca llames register_action varias veces para un mismo pedido. La UI muestra el resumen como tarjeta — no lo repitas en texto. Recién cuando el usuario confirme, llamá execute_action con ese confirmToken.",
      inputSchema: z.object({
        actions: z.array(actionItemSchema).min(1).describe("Lote de acciones a confirmar juntas (mínimo 1)"),
        summary: z.string().describe("Resumen legible del LOTE completo para mostrar al usuario (ej. 'Crear 3 productos: Sprite, Coca Zero, Coca Cola')"),
      }),
      execute: async ({ actions, summary }) => {
        // Re-anidar plano → {action, payload} que espera el backend, intacto.
        const nested = actions.map(({ action, ...fields }) => ({ action, payload: fields }))
        return registerConfirmation(authHeader, apiUrl, extraHeaders, nested, summary)
      },
    }),

    execute_action: tool({
      description:
        "Ejecuta el LOTE de acciones YA confirmado por el usuario. Llamala SOLO después de que el usuario confirmó explícitamente, con el confirmToken que devolvió register_action.",
      inputSchema: z.object({
        confirmToken: z.string().describe("Token devuelto por register_action"),
      }),
      execute: async ({ confirmToken }) =>
        executeConfirmation(authHeader, apiUrl, extraHeaders, confirmToken),
    }),
  }
}
