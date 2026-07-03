"use client"

import * as React from "react"
import { CheckCircle2, XCircle, ListChecks } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"

/**
 * Render determinístico de las tool-parts mutantes del agente (register_action
 * / execute_action).
 *
 * POR QUÉ (2026-07-02): antes, el front descartaba (`return null`) toda parte
 * no-texto, así que la ÚNICA forma que tenía el modelo de mostrar el resumen
 * de una confirmación era narrarlo en prosa — DeepSeek degeneraba ese texto
 * (lo repetía, alucinaba fences de código vacíos `{}`). La solución de raíz no
 * es pedirle "mejor" al modelo que redacte bien: es dejar de depender de su
 * prosa para algo que la UI puede — y debe — renderizar de forma determinística
 * a partir de datos estructurados (el input/output real de la tool-call).
 */

// ── register_action ──────────────────────────────────────────────────────

interface RegisterActionItem {
  action?: string
  payload?: Record<string, unknown>
}

interface RegisterActionInput {
  actions?: RegisterActionItem[]
  summary?: string
}

interface RegisterActionOutput {
  confirmToken?: string
  summary?: string
  count?: number
  error?: string
  pendingConfirmation?: boolean
}

const ACTION_LABELS: Record<string, string> = {
  create_contact: "Crear contacto",
  update_contact: "Editar contacto",
  create_item: "Crear ítem",
  update_item_price: "Cambiar precio",
  create_user: "Crear usuario",
  create_category: "Crear categoría",
  create_brand: "Crear marca",
  create_tag: "Crear etiqueta",
  tabular_import: "Importar archivo",
}

function actionLine(item: RegisterActionItem): string {
  const label = ACTION_LABELS[item.action ?? ""] ?? item.action ?? "Acción"
  const name = (item.payload?.name as string | undefined)
    ?? (item.payload?.id as string | undefined)
  return name ? `${label} — ${name}` : label
}

/**
 * Tarjeta de confirmación para un lote de `register_action`. Los botones
 * envían texto plano al chat ("Sí" / "No") — el flujo de confirmación sigue
 * siendo conversacional (el modelo interpreta y llama execute_action), pero
 * ahora el usuario tiene un control de un click en vez de tipear.
 */
export function RegisterActionCard({
  input,
  output,
  onConfirm,
  onCancel,
  disabled,
}: {
  input: RegisterActionInput | undefined
  output: RegisterActionOutput | undefined
  onConfirm: () => void
  onCancel: () => void
  disabled?: boolean
}) {
  const actions = input?.actions ?? []
  const summary = output?.summary || input?.summary

  if (output?.error) {
    return (
      <Card size="sm" className="w-full max-w-[95%] border-destructive/30">
        <CardContent className="flex items-start gap-2 text-sm text-destructive">
          <XCircle className="mt-0.5 size-4 shrink-0" />
          <span>{output.error}</span>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card size="sm" className="w-full max-w-[95%]">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-sm">
          <ListChecks className="size-4 text-muted-foreground" />
          {summary || "Confirmar acción"}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {actions.length > 0 && (
          <ul className="space-y-1 text-sm text-muted-foreground">
            {actions.map((item, i) => (
              <li key={i} className="flex items-baseline gap-1.5">
                <span className="text-foreground/40">·</span>
                <span>{actionLine(item)}</span>
              </li>
            ))}
          </ul>
        )}
        <div className="flex gap-2">
          <Button size="sm" onClick={onConfirm} disabled={disabled}>
            Confirmar
          </Button>
          <Button size="sm" variant="outline" onClick={onCancel} disabled={disabled}>
            Cancelar
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

// ── execute_action ───────────────────────────────────────────────────────

interface ExecuteActionResultItem {
  action?: string
  ok?: boolean
  error?: string
}

interface ExecuteActionOutput {
  results?: ExecuteActionResultItem[]
  okCount?: number
  failCount?: number
  error?: string
  // Shapes de resultado single-action legacy (create_user, tabular_import,
  // etc. sin el wrapper results[]) — se muestran como éxito genérico.
  [key: string]: unknown
}

/** Resumen determinístico del resultado de `execute_action` (creados/fallidos). */
export function ExecuteActionSummary({ output }: { output: ExecuteActionOutput | undefined }) {
  if (!output) return null

  if (output.error) {
    return (
      <Card size="sm" className="w-full max-w-[95%] border-destructive/30">
        <CardContent className="flex items-start gap-2 text-sm text-destructive">
          <XCircle className="mt-0.5 size-4 shrink-0" />
          <span>{output.error}</span>
        </CardContent>
      </Card>
    )
  }

  const results = output.results
  if (!Array.isArray(results)) {
    // Shape legacy (una sola acción, ej. create_user con tempPassword): el
    // texto del modelo ya lo presenta con su formato dedicado — no duplicar.
    return null
  }

  const okCount = output.okCount ?? results.filter((r) => r.ok).length
  const failCount = output.failCount ?? results.filter((r) => !r.ok).length
  const failed = results.filter((r) => !r.ok)

  return (
    <Card size="sm" className="w-full max-w-[95%]">
      <CardContent className="space-y-2 text-sm">
        <div className="flex items-center gap-2">
          <CheckCircle2 className="size-4 text-emerald-600" />
          <span>
            {okCount} {okCount === 1 ? "acción completada" : "acciones completadas"}
            {failCount > 0 && `, ${failCount} con error`}
          </span>
        </div>
        {failed.length > 0 && (
          <ul className="space-y-1 text-muted-foreground">
            {failed.map((r, i) => (
              <li key={i} className="flex items-baseline gap-1.5">
                <XCircle className="mt-0.5 size-3.5 shrink-0 text-destructive" />
                <span>{ACTION_LABELS[r.action ?? ""] ?? r.action}: {r.error}</span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

// ── defensas de texto (dedupe + strip de fences vacíos) ──────────────────

/**
 * DeepSeek (vía OpenRouter) a veces degenera y: (a) repite el mismo párrafo
 * de texto en parts consecutivas, o (b) alucina un fence de código vacío
 * (```\n{}\n``` o similar) sin contenido real. Estas defensas normalizan esa
 * salida ANTES de pasarla a MessageMarkdown — no reemplazan el fix del
 * prompt (que le pide no hacerlo), son la red de seguridad para cuando el
 * modelo lo hace igual.
 */

/**
 * true si el texto es un fence de código vacío o con solo `{}`/whitespace.
 * Usado inline por ambos renderers (agent-chat-content.tsx y chat/page.tsx)
 * junto con un chequeo de "texto igual/contenido en el anterior" para el
 * dedupe de parts consecutivas — la lógica de dedupe en sí queda en cada
 * call-site porque necesita el `idx` del `.map()` para la key de React.
 */
export function isEmptyCodeFence(text: string): boolean {
  const trimmed = text.trim()
  const fenceMatch = trimmed.match(/^```[a-zA-Z]*\n?([\s\S]*?)\n?```$/)
  if (!fenceMatch) return false
  const inner = fenceMatch[1].trim()
  return inner === "" || inner === "{}"
}
