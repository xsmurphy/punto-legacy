/**
 * Resuelve el país del comercio (`settings.country`) server-side, para sesgar
 * el autocomplete de dirección. Mismo patrón que
 * `app/api/agent/chat/route.ts` (fetch a `/v1/settings` con la cookie del
 * panel reenviada) — fail-open: si no hay cookie, el fetch falla o el owner
 * no tiene país configurado, devuelve `null` y el caller busca sin bias de
 * país (autocomplete sigue funcionando, solo pierde el sesgo).
 *
 * `/v1/settings` solo acepta el realm `panel` (cookie `_jwt_panel`), no el
 * Bearer de device POS — pero el POS vive fusionado dentro de `frontend`
 * (mismo origen que el panel), así que la cookie del panel viaja igual en
 * las requests del operador logueado. Si no viaja (device kiosco sin sesión
 * de panel en el mismo browser), simplemente no hay bias — no es un path
 * crítico.
 */
export async function getTenantCountry(cookie: string): Promise<string | null> {
  const apiUrl = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? ""
  if (!apiUrl || !cookie) return null
  try {
    const res = await fetch(`${apiUrl}/v1/settings`, {
      headers: { cookie },
      signal: AbortSignal.timeout(2500),
    })
    if (!res.ok) return null
    const json = (await res.json()) as { data?: Record<string, unknown> } & Record<string, unknown>
    const s = (json.data ?? json) as Record<string, unknown>
    const country = String(s.country ?? "").trim()
    return country || null
  } catch {
    return null
  }
}
