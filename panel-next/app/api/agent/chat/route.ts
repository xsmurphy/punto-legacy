import { createOpenRouter } from "@openrouter/ai-sdk-provider"
import { streamText, tool, convertToModelMessages, stepCountIs } from "ai"
import { z } from "zod"
import type { UIMessage } from "ai"
import { makeConfirmTool } from "@/lib/agent/confirm-tool"

export const runtime = "nodejs"
export const maxDuration = 60

export async function POST(req: Request) {
  const apiKey = process.env.OPENROUTER_API_KEY
  if (!apiKey) {
    return Response.json({ error: "OPENROUTER_API_KEY no configurada" }, { status: 500 })
  }

  const cookie = req.headers.get("cookie") ?? ""
  const apiUrl = process.env.API_URL ?? ""

  let body: {
    messages?: UIMessage[]
    companyName?: string
    outletName?: string
  }
  try {
    body = await req.json()
  } catch {
    return Response.json({ error: "Body inválido" }, { status: 400 })
  }

  const { messages = [], companyName = "", outletName = "" } = body

  // Elegir modelo desde la config del tenant (fail-safe: deepseek por defecto)
  let modelId = "deepseek/deepseek-chat"
  try {
    const configRes = await fetch(`${apiUrl}/v1/ai/config`, {
      headers: { cookie },
    })
    if (configRes.ok) {
      const config = (await configRes.json()) as Record<
        string,
        { model: string; creditsperktoken: number }
      >
      if (config?.chat?.model) {
        modelId = config.chat.model
      }
    }
  } catch {
    // ignorar — fallback al default
  }

  // Gate: verificar balance antes de llamar al modelo
  try {
    const balRes = await fetch(`${apiUrl}/v1/ai/balance`, {
      headers: { cookie },
    })
    if (balRes.ok) {
      const balData = (await balRes.json()) as { data?: { balance: number }; balance?: number }
      const balance = balData?.data?.balance ?? (balData as { balance?: number })?.balance ?? 0
      if (balance <= 0) {
        return Response.json({ error: "Sin créditos" }, { status: 402 })
      }
    }
  } catch {
    // ignorar — si no podemos verificar, dejamos pasar (fail-open)
  }

  const openrouter = createOpenRouter({ apiKey })
  const model = openrouter(modelId)

  const today = new Date().toISOString().slice(0, 10)
  const system =
    `Sos el asistente de ${companyName}${outletName ? ` (sucursal ${outletName})` : ""} dentro de Punto, un sistema de punto de venta. Hoy es ${today}. Ayudás a consultar y analizar datos del negocio, y también podés crear o modificar registros cuando el usuario lo pide. Respondé siempre en español. Sé conciso y claro. Cuando necesites datos usá las tools disponibles.\n\n` +
    `Para acciones que modifican datos (crear contacto, ítem, usuario, categoría, marca, etiqueta, o cambiar precio): ` +
    `1) Llamá la tool "confirm_action" con action+payload+summary para generar un token de confirmación. ` +
    `2) Mostrá el resumen al usuario y pedí confirmación explícita. ` +
    `3) Solo cuando el usuario confirme, llamá "confirm_action" de nuevo con el confirmToken para ejecutar. ` +
    `Nunca ejecutes una acción mutante sin confirmación explícita del usuario.\n\n` +
    `CUANDO la acción "create_user" devuelva tempPassword, presentá la respuesta EXACTAMENTE con este formato (sin texto adicional antes ni después, sin "te muestro", sin disculpas):\n\n` +
    `🔐 **{userDisplayName}**\n\n` +
    `**Usuario:** {login}\n` +
    `**Contraseña:** {tempPassword}\n\n` +
    `⏳ Esta contraseña se ocultará en 60 segundos por seguridad. Guardala antes.\n\n` +
    `NO repitas la contraseña en otros mensajes. NO la escribas en explicaciones largas. NO inventes que el sistema "borra" mensajes — solo esta única respuesta es sensible y el cliente la oculta automáticamente.\n\n` +
    `## Importación de archivos tabulares\n\n` +
    `Cuando el message del usuario incluya "[Adjuntos]" con un sessionId tabular:\n\n` +
    `1. Identificá si el usuario quiere importar los datos (frases como "importá esto", "carga estos clientes", "agregá estos productos", "subí este archivo", "importar", etc.)\n` +
    `2. Si sí: determiná el kind correcto:\n` +
    `   - Si mencionan "clientes", "proveedores", "contactos" → kind="contacts"\n` +
    `   - Si mencionan "productos", "artículos", "items", "inventario" → kind="items"\n` +
    `   - Si no está claro, preguntá: "¿Son artículos/productos o contactos (clientes/proveedores)?"\n` +
    `3. Determiná el mapping: si los headers del archivo ya coinciden con los canónicos del importer, mapping=null. Si no, construí el mapping {campoCanónico: columnaDelArchivo}.\n` +
    `4. Determiná el mode: default "insert". Si el usuario dice "actualizar", "modificar precios", "sincronizar" → mode="update".\n` +
    `5. Llamá confirm_action con action="tabular_import", payload={sessionId, kind, mapping, mode}, summary="Importar N filas a [artículos/contactos] (modo [insert/update])".\n` +
    `6. Esperá la confirmación explícita del usuario antes de proceder.\n` +
    `7. Cuando el usuario confirme, llamá confirm_action con {confirmToken} para ejecutar.\n` +
    `8. Reportá el resultado: "Se importaron X artículos/contactos. Y actualizados. Z errores." Si hay errores, listalos.`

  const modelMessages = await convertToModelMessages(messages)

  const result = streamText({
    model,
    system,
    messages: modelMessages,
    stopWhen: stepCountIs(10),
    onFinish: async ({ usage }) => {
      const tokensIn  = Number(usage.inputTokens  ?? 0)
      const tokensOut = Number(usage.outputTokens ?? 0)
      if (tokensIn === 0 && tokensOut === 0) return
      try {
        await fetch(`${apiUrl}/v1/ai/debit`, {
          method: "POST",
          headers: { "Content-Type": "application/json", cookie },
          body: JSON.stringify({
            tokensIn,
            tokensOut,
            capability: "chat",
            model: modelId,
          }),
        })
      } catch (e) {
        console.error("[agent] debit failed", e)
      }
    },
    tools: {
      get_sales_summary: tool({
        description:
          "Resumen anual de ventas, gastos y devoluciones por mes. Usar cuando el usuario pregunte por ventas, ingresos, egresos o resultados de un año.",
        inputSchema: z.object({
          year: z.number().int().describe("Año a consultar, ej. 2025"),
        }),
        execute: async ({ year }) => {
          try {
            const res = await fetch(
              `${apiUrl}/v1/reports/summary_year?y=${year}`,
              { headers: { cookie } }
            )
            if (!res.ok) {
              return { error: `No se pudo obtener el reporte (${res.status})` }
            }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_contacts: tool({
        description: "Lista contactos (clientes o proveedores) del negocio. Útil para buscar un cliente o proveedor por nombre.",
        inputSchema: z.object({
          type: z.enum(["1", "2"]).describe("1 = clientes, 2 = proveedores"),
          q: z.string().optional().describe("Búsqueda por nombre"),
          limit: z.number().int().optional().default(20),
        }),
        execute: async ({ type, q, limit }) => {
          try {
            const params = new URLSearchParams({ type, limit: String(limit ?? 20) })
            if (q) params.set("q", q)
            const res = await fetch(`${apiUrl}/v1/contacts?${params}`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_items: tool({
        description: "Lista ítems (productos o servicios) del catálogo. Útil para buscar precios, costos o existencia de un producto.",
        inputSchema: z.object({
          q: z.string().optional().describe("Búsqueda por nombre o SKU"),
          limit: z.number().int().optional().default(20),
        }),
        execute: async ({ q, limit }) => {
          try {
            const params = new URLSearchParams({ limit: String(limit ?? 20) })
            if (q) params.set("q", q)
            const res = await fetch(`${apiUrl}/v1/items?${params}`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_stock: tool({
        description:
          "Stock actual de un artículo por sucursal. Buscá por nombre o SKU.",
        inputSchema: z.object({
          itemQuery: z.string().min(1).describe("Nombre o SKU del ítem a buscar"),
        }),
        execute: async ({ itemQuery }) => {
          try {
            // El endpoint /v1/reports/stock no filtra server-side todavía.
            // Filtramos en este handler ANTES de devolver al LLM para no
            // mandarle el listado entero (puede ser miles de filas → muchos
            // tokens). El filtro es case-insensitive sobre name y sku.
            const res = await fetch(`${apiUrl}/v1/reports/stock`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            const raw = (json?.data ?? json) as unknown
            const rows = Array.isArray(raw)
              ? raw
              : Array.isArray((raw as { rows?: unknown[] })?.rows)
                ? (raw as { rows: unknown[] }).rows
                : []
            const q = itemQuery.toLowerCase()
            const filtered = rows.filter((r) => {
              const o = r as { name?: string; sku?: string; itemName?: string; itemSKU?: string }
              return (
                (o.name ?? o.itemName ?? "").toLowerCase().includes(q) ||
                (o.sku ?? o.itemSKU ?? "").toLowerCase().includes(q)
              )
            })
            return { matches: filtered.slice(0, 20), totalMatches: filtered.length }
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_categories: tool({
        description: "Lista categorías de productos del negocio.",
        inputSchema: z.object({}),
        execute: async () => {
          try {
            const res = await fetch(`${apiUrl}/v1/categories`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_brands: tool({
        description: "Lista marcas de productos del negocio.",
        inputSchema: z.object({}),
        execute: async () => {
          try {
            const res = await fetch(`${apiUrl}/v1/brands`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_tags: tool({
        description: "Lista etiquetas de productos del negocio.",
        inputSchema: z.object({}),
        execute: async () => {
          try {
            const res = await fetch(`${apiUrl}/v1/tags`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_users: tool({
        description: "Lista usuarios/empleados del equipo del negocio.",
        inputSchema: z.object({
          q: z.string().optional().describe("Búsqueda por nombre"),
        }),
        execute: async ({ q }) => {
          try {
            const params = new URLSearchParams()
            if (q) params.set("q", q)
            const res = await fetch(`${apiUrl}/v1/users?${params}`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_drawers: tool({
        description: "Lista cajas/turnos del período. Útil para consultar cierres de caja.",
        inputSchema: z.object({
          from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
          to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
        }),
        execute: async ({ from, to }) => {
          try {
            const params = new URLSearchParams()
            if (from) params.set("from", from)
            if (to) params.set("to", to)
            const res = await fetch(`${apiUrl}/v1/reports/drawers?${params}`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_transactions: tool({
        description: "Lista ventas/transacciones del período. Útil para consultar movimientos.",
        inputSchema: z.object({
          from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
          to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
          limit: z.number().int().optional().default(50),
        }),
        execute: async ({ from, to, limit }) => {
          try {
            const params = new URLSearchParams({ limit: String(limit ?? 50) })
            if (from) params.set("from", from)
            if (to) params.set("to", to)
            const res = await fetch(`${apiUrl}/v1/reports/transactions?${params}`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_top_products: tool({
        description: "Lista los productos más vendidos del período. Útil para responder 'top N productos vendidos'.",
        inputSchema: z.object({
          from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
          to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
          limit: z.number().int().optional().default(10),
        }),
        execute: async ({ from, to, limit }) => {
          try {
            const params = new URLSearchParams({ view: "general" })
            if (from) params.set("from", from)
            if (to) params.set("to", to)
            const res = await fetch(`${apiUrl}/v1/reports/products?${params}`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            const raw = (json?.data ?? json) as unknown
            const rows = Array.isArray(raw)
              ? raw
              : Array.isArray((raw as { rows?: unknown[] })?.rows)
                ? (raw as { rows: unknown[] }).rows
                : []
            return rows.slice(0, limit ?? 10)
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_outlets: tool({
        description: "Lista sucursales del negocio.",
        inputSchema: z.object({}),
        execute: async () => {
          try {
            const res = await fetch(`${apiUrl}/v1/outlets`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      get_settings: tool({
        description: "Configuración general del negocio (nombre, moneda, impuestos, etc.).",
        inputSchema: z.object({}),
        execute: async () => {
          try {
            const res = await fetch(`${apiUrl}/v1/settings`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
          } catch (err) {
            return { error: String(err) }
          }
        },
      }),

      confirm_action: makeConfirmTool(cookie, apiUrl),
    },
  })

  return result.toUIMessageStreamResponse()
}
