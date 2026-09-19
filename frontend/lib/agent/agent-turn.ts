import * as Sentry from "@sentry/nextjs"
import { createUIMessageStreamResponse, streamText } from "ai"
import type { LanguageModelUsage, ToolSet } from "ai"
import { debitAiUsage } from "@/lib/ai/billing-gate"
import { truncationMetadata } from "@/lib/agent/truncation"
import {
  HEARTBEAT_MS,
  STREAM_ERROR_COPY,
  TOOL_TIMEOUT_MS,
  guardTools,
  guardUIStream,
  type StreamChunk,
  type StreamEnd,
  type ToolSettled,
} from "@/lib/agent/turn-guard"

/**
 * Motor del turno del asistente — el ÚNICO lugar donde se llama a `streamText`
 * para el chat. Lo usan los dos routes (panel y caja): cada uno arma su prompt,
 * su modelo y sus tools, y le delega todo lo que NO es propio de la superficie:
 *
 *  - timeout por tool (`guardTools`) y señal del turno hacia los fetch;
 *  - tope REAL del turno + latido hacia el navegador (`guardUIStream`) — el
 *    `maxDuration` de los routes no hace nada en `next start`;
 *  - cobro: al terminar normal (`onFinish`) y también al abortarse (`onAbort`),
 *    que antes no cobraba nada de lo ya consumido;
 *  - errores del stream con copy para el usuario (el detalle técnico va al log
 *    y a Sentry, nunca a la pantalla — context/14 §Regla 8);
 *  - un log JSON por turno a stdout, con requestId, tenant, modelo, duración
 *    total y por tool, finishReason y error. Sin esto un turno colgado no dejaba
 *    ningún rastro (así se perdió el diagnóstico de 2026-09-18).
 *
 * Duplicar esto en cada route es exactamente cómo uno de los dos se queda sin
 * alguna de estas garantías la próxima vez que se toque el otro.
 */

type StreamTextParams = Parameters<typeof streamText>[0]

export interface AgentTurnOptions<TOOLS extends ToolSet> {
  surface: "panel" | "pos"
  logPrefix: string
  requestId: string
  companyId: string | null
  modelId: string
  apiUrl: string
  authHeader: string
  /** `req.signal`: si el navegador se va, se deja de generar (y de gastar). */
  requestSignal?: AbortSignal
  turnTimeoutMs: number
  toolTimeoutMs?: number
  heartbeatMs?: number
  /**
   * Controller del turno. Lo crea el route ANTES de armar las tools para
   * pasarle su `signal` al `ToolContext`; si no viene, se crea acá.
   */
  turnController?: AbortController
  model: StreamTextParams["model"]
  system: string
  messages: NonNullable<StreamTextParams["messages"]>
  tools: TOOLS
  stopWhen: StreamTextParams["stopWhen"]
  maxOutputTokens: number
  temperature: number
  experimental_transform?: StreamTextParams["experimental_transform"]
}

interface TurnTrace {
  tools: ToolSettled[]
  steps: number
  finishReason?: string
  error?: string
}

function sumUsage(usages: LanguageModelUsage[]): { tokensIn: number; tokensOut: number } {
  let tokensIn = 0
  let tokensOut = 0
  for (const u of usages) {
    tokensIn += Number(u.inputTokens ?? 0)
    tokensOut += Number(u.outputTokens ?? 0)
  }
  return { tokensIn, tokensOut }
}

function errorText(e: unknown): string {
  if (e instanceof Error) return `${e.name}: ${e.message}`
  return String(e)
}

export function runAgentTurn<TOOLS extends ToolSet>(opts: AgentTurnOptions<TOOLS>): Response {
  const started = Date.now()
  const trace: TurnTrace = { tools: [], steps: 0 }
  const tags = {
    surface: opts.surface,
    requestId: opts.requestId,
    companyId: opts.companyId ?? "unknown",
    model: opts.modelId,
  }

  const turn = opts.turnController ?? new AbortController()
  if (opts.requestSignal) {
    if (opts.requestSignal.aborted) turn.abort()
    else opts.requestSignal.addEventListener("abort", () => turn.abort(), { once: true })
  }

  const debit = (tokensIn: number, tokensOut: number) =>
    debitAiUsage({
      apiUrl: opts.apiUrl,
      authHeader: opts.authHeader,
      tokensIn,
      tokensOut,
      capability: "chat",
      model: opts.modelId,
      requestId: opts.requestId,
      logPrefix: opts.logPrefix,
    })

  const tools = guardTools(opts.tools, {
    timeoutMs: opts.toolTimeoutMs ?? TOOL_TIMEOUT_MS,
    onSettled: (t) => {
      trace.tools.push(t)
      if (t.outcome === "timeout") {
        Sentry.captureMessage(`[agent] tool ${t.tool} timeout`, { level: "warning", tags: { ...tags, tool: t.tool } })
      }
    },
  })

  const result = streamText({
    model: opts.model,
    system: opts.system,
    messages: opts.messages,
    tools,
    stopWhen: opts.stopWhen,
    maxOutputTokens: opts.maxOutputTokens,
    temperature: opts.temperature,
    experimental_transform: opts.experimental_transform,
    abortSignal: turn.signal,
    onStepFinish: () => {
      trace.steps += 1
    },
    onFinish: async ({ totalUsage, finishReason }) => {
      trace.finishReason = finishReason
      const { tokensIn, tokensOut } = sumUsage([totalUsage])
      await debit(tokensIn, tokensOut)
    },
    // Un turno cortado (tope, navegador que se fue) ya consumió los pasos que
    // terminó. Antes no se cobraban: cualquier corte era crédito regalado.
    onAbort: async ({ steps }) => {
      const { tokensIn, tokensOut } = sumUsage(steps.map((s) => s.usage))
      await debit(tokensIn, tokensOut)
    },
    onError: ({ error }) => {
      trace.error = errorText(error)
    },
  })

  const uiStream = result.toUIMessageStream({
    // Corte por `maxOutputTokens` — ver lib/agent/truncation.ts.
    messageMetadata: truncationMetadata,
    onError: (error) => {
      trace.error ??= errorText(error)
      console.error(`${opts.logPrefix} error en el stream del modelo`, error)
      Sentry.captureException(error, { tags })
      return STREAM_ERROR_COPY
    },
  }) as unknown as ReadableStream<StreamChunk>

  const guarded = guardUIStream(uiStream, {
    heartbeatMs: opts.heartbeatMs ?? HEARTBEAT_MS,
    turnTimeoutMs: opts.turnTimeoutMs,
    onChunk: (chunk) => {
      if (chunk.type === "finish" && typeof chunk.finishReason === "string") {
        trace.finishReason = chunk.finishReason
      }
    },
    onTurnTimeout: () => turn.abort(new Error("turn timeout")),
    onClientGone: () => turn.abort(new Error("client gone")),
    onEnd: (end: StreamEnd) => {
      const outcome =
        end.kind === "completed" ? (trace.error ? "error" : "ok") : end.kind === "source-error" ? "error" : end.kind
      if (end.kind === "source-error") trace.error ??= errorText(end.error)
      if (end.kind === "turn-timeout") {
        Sentry.captureMessage(`[agent] turno superó ${opts.turnTimeoutMs}ms`, { level: "error", tags })
      }
      // Una línea JSON por turno: se busca por requestId o companyId en los
      // logs del contenedor.
      console.log(
        JSON.stringify({
          evt: "agent_turn",
          ...tags,
          outcome,
          durationMs: Date.now() - started,
          steps: trace.steps,
          finishReason: trace.finishReason ?? null,
          tools: trace.tools,
          error: trace.error ?? null,
        }),
      )
    },
  })

  return createUIMessageStreamResponse({ stream: guarded as never })
}
