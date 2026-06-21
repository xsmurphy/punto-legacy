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
    `Nunca ejecutes una acción mutante sin confirmación explícita del usuario.`

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
          "Stock actual del inventario. Devuelve todos los ítems con su cantidad en stock. " +
          "El LLM filtra por ítem relevante en base a itemQuery.",
        inputSchema: z.object({
          // TODO: el endpoint /v1/reports/stock no acepta filtros por ítem.
          // Se devuelve el listado completo y el LLM filtra. Cuando el endpoint
          // soporte filtros, pasar itemQuery como parámetro.
          itemQuery: z.string().optional().describe("Nombre o SKU del ítem (filtrado client-side por el LLM)"),
        }),
        execute: async () => {
          try {
            const res = await fetch(`${apiUrl}/v1/reports/stock`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
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
            const res = await fetch(`${apiUrl}/v1/transactions?${params}`, { headers: { cookie } })
            if (!res.ok) return { error: `Error ${res.status}` }
            const json = (await res.json()) as { data?: unknown }
            return json?.data ?? json
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
