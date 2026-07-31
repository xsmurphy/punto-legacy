import { z } from "zod"

/**
 * Spec compartida del gráfico que el agente puede generar en el chat
 * (generative UI). Un solo schema para dos lados:
 *
 *  - Server (`app/api/agent/chat/route.ts`): `inputSchema` de la tool
 *    `render_chart` — valida lo que el modelo arma ANTES de que salga del
 *    endpoint.
 *  - Client (`components/agent/agent-chart.tsx`): re-valida el `input` del
 *    tool-part con el MISMO schema antes de renderizar — el dato viene de un
 *    LLM, nunca confiar en el shape sin chequear (ver `safeParse` ahí).
 */

export const chartKindSchema = z.enum(["line", "bar", "area", "donut"])

export const chartValueFormatSchema = z.enum(["money", "number", "percent"])

export const chartSeriesSchema = z.object({
  key: z.string().min(1).describe("Nombre del campo en `data` para esta serie"),
  label: z.string().min(1).describe("Etiqueta legible de la serie (leyenda/tooltip)"),
})

// Fila de datos: xKey (string, ej. período) + una entrada numérica por serie.
// z.union string|number|null porque el modelo puede mandar el valor del eje X
// como string y los valores de series como number — un solo record cubre
// ambos sin dos schemas separados.
export const chartRowSchema = z.record(z.string(), z.union([z.string(), z.number(), z.null()]))

export const chartSpecSchema = z.object({
  kind: chartKindSchema.describe("Tipo de gráfico: line, bar, area o donut"),
  title: z.string().min(1).max(80).describe("Título corto del gráfico"),
  xKey: z.string().min(1).describe("Nombre del campo eje X en las filas de `data` (ej. período)"),
  series: z
    .array(chartSeriesSchema)
    .min(1)
    .max(4)
    .describe("Series a graficar, máximo 4"),
  data: z
    .array(chartRowSchema)
    .min(1)
    .max(60)
    .describe(
      "Filas de datos YA agregadas por período — máx 60 filas. Agregá por mes/semana antes de graficar, nunca mandes filas crudas sin agregar."
    ),
  valueFormat: chartValueFormatSchema
    .optional()
    .default("number")
    .describe("Formato de los valores: money, number o percent"),
})

export type ChartSpec = z.infer<typeof chartSpecSchema>
export type ChartSeries = z.infer<typeof chartSeriesSchema>
