import { createOpenRouter } from "@openrouter/ai-sdk-provider"
import { streamText, tool, convertToModelMessages, stepCountIs } from "ai"
import { z } from "zod"
import type { UIMessage } from "ai"

export const runtime = "nodejs"
export const maxDuration = 60

export async function POST(req: Request) {
  const apiKey = process.env.OPENROUTER_API_KEY
  if (!apiKey) {
    return Response.json({ error: "OPENROUTER_API_KEY no configurada" }, { status: 500 })
  }

  const cookie = req.headers.get("cookie") ?? ""

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
    const configRes = await fetch(`${process.env.API_URL}/v1/ai/config`, {
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

  const openrouter = createOpenRouter({ apiKey })
  const model = openrouter(modelId)

  const today = new Date().toISOString().slice(0, 10)
  const system = `Sos el asistente de ${companyName}${outletName ? ` (sucursal ${outletName})` : ""} dentro de Punto, un sistema de punto de venta. Hoy es ${today}. Ayudás a consultar y analizar datos del negocio. Respondé siempre en español. Sé conciso y claro. Cuando necesites datos de ventas usá las tools disponibles.`

  const modelMessages = await convertToModelMessages(messages)

  const result = streamText({
    model,
    system,
    messages: modelMessages,
    stopWhen: stepCountIs(5),
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
              `${process.env.API_URL}/v1/reports/summary_year?y=${year}`,
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
    },
  })

  return result.toUIMessageStreamResponse()
}
