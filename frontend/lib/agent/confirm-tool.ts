import { tool } from "ai"
import { z } from "zod"

// Approach permisivo: un único z.object con todos los campos optional.
// z.union/discriminatedUnion rompe en algunos providers de OpenRouter (DeepSeek,
// Gemini) que no soportan oneOf en JSON Schema.
//
// `payload` se define con campos EXPLÍCITOS (no z.record/additionalProperties):
// los modelos de tool-calling (DeepSeek incluido) no logran poblar un objeto de
// propiedades libres y terminan no llamando la tool (síntoma: el agente muestra
// el resumen de confirmación pero "no inserta" + filtra texto del protocolo).
// Un schema concreto le da al modelo exactamente qué campos llenar por acción.
const payloadSchema = z.object({
  // contactos / nombres genéricos (item, categoría, marca, etiqueta, usuario)
  name: z.string().optional().describe("Nombre del registro (contacto, ítem, categoría, marca, etiqueta, usuario)"),
  type: z.number().int().optional().describe("contacto: 1=cliente, 2=proveedor"),
  phone: z.string().optional(),
  email: z.string().optional(),
  note: z.string().optional(),
  id: z.string().optional().describe("id del registro a actualizar (update_*)"),
  // ítems
  kind: z.string().optional().describe("create_item: 'producto'|'servicio'. tabular_import: 'items'|'contacts'"),
  price: z.number().optional().describe("create_item: precio de venta"),
  cost: z.number().optional().describe("create_item: costo"),
  sku: z.string().optional(),
  categoryName: z.string().optional(),
  brandName: z.string().optional(),
  newPrice: z.number().optional().describe("update_item_price: nuevo precio"),
  // usuarios
  roleName: z.string().optional().describe("create_user: nombre del rol (no admin)"),
  // importación tabular
  sessionId: z.string().optional().describe("tabular_import: id de sesión del adjunto"),
  mode: z.string().optional().describe("tabular_import: 'insert'|'update'"),
  mapping: z.record(z.string(), z.string()).nullish().describe("tabular_import: mapeo campo→columna, o null para auto"),
})

const confirmInputSchema = z.object({
  // Si viene confirmToken, se ejecuta; si no, se registra la confirmación
  confirmToken: z.string().optional().describe("Token de confirmación previo (solo para execute)"),

  // Campos para registrar una nueva confirmación (cuando NO hay confirmToken)
  action: z.string().optional().describe(
    "Acción a ejecutar: create_contact | update_contact | create_item | update_item_price | create_user | create_category | create_brand | create_tag | tabular_import"
  ),
  payload: payloadSchema.optional().describe("Datos de la acción (llená solo los campos que aplican al action)"),
  summary: z.string().optional().describe("Descripción legible para el usuario"),
})

export function makeConfirmTool(cookie: string, apiUrl: string) {
  return tool({
    description:
      "Herramienta de confirmación de acciones del agente. " +
      "Cuando el usuario quiere crear o modificar datos (contactos, ítems, usuarios, categorías, marcas, etiquetas), " +
      "primero llamá esta tool con action+payload+summary para generar un confirmToken y presentárselo al usuario. " +
      "Cuando el usuario confirme, llamá de nuevo con confirmToken para ejecutar la acción.",
    inputSchema: confirmInputSchema,
    execute: async ({ confirmToken, action, payload, summary }) => {
      if (confirmToken) {
        // Ejecutar acción ya confirmada
        try {
          const res = await fetch(`${apiUrl}/v1/ai/execute`, {
            method: "POST",
            headers: { "Content-Type": "application/json", cookie },
            body: JSON.stringify({ confirmToken }),
          })
          const json = (await res.json()) as { ok?: boolean; data?: unknown; error?: string }
          if (!res.ok || !json.ok) {
            return { error: json.error ?? `Error ejecutando (${res.status})` }
          }
          return json.data ?? { ok: true }
        } catch (err) {
          return { error: String(err) }
        }
      }

      if (!action || !payload || !summary) {
        return { error: "Se requiere action, payload y summary para registrar una confirmación" }
      }

      // Registrar confirmación pendiente
      try {
        const res = await fetch(`${apiUrl}/v1/ai/confirm`, {
          method: "POST",
          headers: { "Content-Type": "application/json", cookie },
          body: JSON.stringify({ action, payload, summary }),
        })
        const json = (await res.json()) as {
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
          message: "Acción pendiente de confirmación del usuario. Mostrá el resumen y esperá su aprobación.",
        }
      } catch (err) {
        return { error: String(err) }
      }
    },
  })
}
