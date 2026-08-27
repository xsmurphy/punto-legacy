/**
 * Gate de créditos IA — compartido entre los BFF que llaman OpenRouter
 * (`app/api/agent/chat/route.ts`, `app/api/ocr-invoice/route.ts`).
 *
 * P1 de code review (2026-07-31): el gate estaba duplicado call-site por
 * call-site y era FAIL-OPEN — si `/v1/ai/balance` o `/v1/ai/debit` tiraban
 * error/timeout, la llamada a OpenRouter procedía igual, sin gate ni débito
 * (créditos gratis / gasto sin cobrar). Wrapper único con dos mitades:
 *
 *   - `assertAiCredits` (pre-gate, ANTES de gastar la llamada a OpenRouter):
 *     FAIL-CLOSED. Si no podemos confirmar que hay saldo, NO dejamos pasar.
 *   - `debitAiUsage` (post-débito, con tokens reales ya gastados): best-effort.
 *     Si falla, la respuesta al usuario NO se rompe (ya se le entregó el
 *     resultado), pero queda un log accionable con requestId para reconciliar
 *     manualmente contra `ai_credit_ledger` — nunca se pierde en silencio.
 *
 * Ambas rutas comparten el MISMO fetch a `/v1/ai/balance` / `/v1/ai/debit`
 * (`api/v1/ai/balance.php`, `api/v1/ai/debit.php`) vía el Bearer del panel —
 * la company se resuelve server-side ahí, no la conocemos en el BFF.
 */

export class AiCreditsError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message)
    this.name = "AiCreditsError"
  }
}

/**
 * Pre-gate: verifica balance de créditos ANTES de llamar a OpenRouter.
 *
 * FAIL-CLOSED: cualquier falla al verificar (red, timeout, status no-ok,
 * JSON inválido) corta con `AiCreditsError` — nunca deja pasar la llamada
 * sin gate. Distinto de "sin créditos" (402): acá es "no pudimos confirmar"
 * (503), para que el caller pueda mostrar un mensaje distinto ("reintentá")
 * en vez del banner de "Sin créditos disponibles".
 *
 * Lanza `AiCreditsError` si el gate no pasa; no retorna nada si pasa.
 */
export async function assertAiCredits(params: { apiUrl: string; authHeader: string; logPrefix: string }): Promise<void> {
  const { apiUrl, authHeader, logPrefix } = params

  let balRes: Response
  try {
    balRes = await fetch(`${apiUrl}/v1/ai/balance`, { headers: { Authorization: authHeader } })
  } catch (e) {
    console.error(`${logPrefix} fallo de red al verificar balance, fail-closed (no procede)`, e)
    throw new AiCreditsError("No se pudo verificar el saldo de créditos, reintentá", 503)
  }

  if (!balRes.ok) {
    console.error(`${logPrefix} ai/balance respondió ${balRes.status}, fail-closed (no procede)`)
    throw new AiCreditsError("No se pudo verificar el saldo de créditos, reintentá", 503)
  }

  let balData: { data?: { balance: number }; balance?: number }
  try {
    balData = (await balRes.json()) as { data?: { balance: number }; balance?: number }
  } catch (e) {
    console.error(`${logPrefix} respuesta de ai/balance no es JSON válido, fail-closed (no procede)`, e)
    throw new AiCreditsError("No se pudo verificar el saldo de créditos, reintentá", 503)
  }

  const balance = balData?.data?.balance ?? balData?.balance ?? 0
  if (balance <= 0) {
    throw new AiCreditsError("Sin créditos", 402)
  }
}

/**
 * Post-débito: descuenta créditos por el uso real (tokens de la respuesta ya
 * generada). Best-effort — si falla, NO propaga (la respuesta ya fue
 * entregada al usuario, no tiene sentido romperla acá), pero deja un log
 * explícito con requestId/capability/tokens para reconciliar manualmente
 * contra `ai_credit_ledger`. Antes esto se perdía en un catch mudo.
 */
export async function debitAiUsage(params: {
  apiUrl: string
  authHeader: string
  tokensIn: number
  tokensOut: number
  capability: string
  model: string
  requestId: string
  logPrefix: string
}): Promise<void> {
  const { apiUrl, authHeader, tokensIn, tokensOut, capability, model, requestId, logPrefix } = params
  if (tokensIn + tokensOut <= 0) return

  const reconcileHint = `requestId=${requestId} capability=${capability} model=${model} tokensIn=${tokensIn} tokensOut=${tokensOut} — reconciliar manualmente contra ai_credit_ledger`

  try {
    const res = await fetch(`${apiUrl}/v1/ai/debit`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: authHeader },
      body: JSON.stringify({ tokensIn, tokensOut, capability, model, requestId }),
    })
    if (!res.ok) {
      console.error(`${logPrefix} debit falló (status ${res.status}) — ${reconcileHint}`)
    }
  } catch (e) {
    console.error(`${logPrefix} debit falló (red/timeout) — ${reconcileHint}`, e)
  }
}
