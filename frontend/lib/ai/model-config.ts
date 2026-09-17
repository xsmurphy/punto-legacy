/**
 * Lectura del catálogo de modelos IA (`/v1/ai/config`) — fuente ÚNICA.
 *
 * La API responde con el envelope canónico `{ ok, data }` (`apiOk`,
 * api/lib/response.php). Los cinco BFF que elegían modelo (chat del panel,
 * chat de la caja, OCR, drain del OCR y TTS) parseaban el body CRUDO
 * (`body[capability]` en vez de `body.data[capability]`), así que NUNCA
 * encontraban la capability y caían al default hardcodeado en silencio.
 * Nadie lo notó porque los defaults estaban "alineados al seed": el select
 * de modelo de /admin no tenía efecto real (detectado 2026-09-17 porque el
 * TTS fue la primera capability cuyo default necesitaba params distintos).
 *
 * Este helper existe para que ese bug no pueda volver a escribirse por
 * copy-paste: el unwrap del envelope vive acá y en ningún consumidor.
 */

export interface AiModelEntry {
  model: string
  creditsperktoken: number
}

export type AiModelConfig = Record<string, AiModelEntry>

/**
 * Devuelve el map capability → { model, creditsperktoken } del tenant, o `{}`
 * si la API no responde o responde mal — el consumidor SIEMPRE tiene su
 * default de paracaídas, así que acá se falla abierto en el MODELO (el cobro
 * tiene su propio gate fail-closed aparte, `billing-gate.ts`).
 */
export async function fetchAiModelConfig(
  apiUrl: string,
  authHeader: string,
  logPrefix: string,
): Promise<AiModelConfig> {
  try {
    const res = await fetch(`${apiUrl}/v1/ai/config`, {
      headers: { Authorization: authHeader },
    })
    if (!res.ok) {
      console.error(`${logPrefix} ai/config respondió ${res.status}, usando defaults`)
      return {}
    }
    const body = (await res.json()) as { ok?: boolean; data?: AiModelConfig }
    return body?.data ?? {}
  } catch (e) {
    console.error(`${logPrefix} fallo al leer ai/config, usando defaults`, e)
    return {}
  }
}
