/**
 * Unidad de cobro del TTS del agente (D3 de `context/80-voz-del-agente.md`).
 *
 * El TTS no consume "tokens": consume CARACTERES de entrada, y no devuelve
 * tokens de salida — devuelve bytes de audio. El ledger de créditos
 * (`ai_credit_ledger`) y el endpoint que lo escribe (`api/v1/ai/debit.php`)
 * cobran por tokens, así que hay dos caminos posibles: bifurcar el ledger con
 * una unidad nueva, o mapear caracteres a "tokens equivalentes" y seguir
 * usando el MISMO camino de débito que el chat. El plan eligió lo segundo: un
 * ledger con dos unidades obliga a que todo lo que lo lee (el desglose de
 * /admin, la reconciliación, el saldo) sepa de las dos, y eso se paga para
 * siempre a cambio de una feature que es un extra.
 *
 * chars/4 es la relación estándar entre caracteres y tokens en texto latino;
 * no pretende ser exacta, pretende ser ESTABLE y auditable: el mismo texto
 * cobra siempre lo mismo y el número se puede recalcular a mano desde el
 * ledger. El precio real por capability se ajusta desde /admin
 * (`ai_model_config.creditsperktoken` de la capability `tts`), que es donde
 * vive esa decisión — no acá.
 */

/**
 * Tope de longitud del texto a leer. No es un límite técnico del proveedor:
 * es de producto. La respuesta de un agente que pasa de esto es un reporte
 * para leer con los ojos, y convertirlo en varios minutos de audio gasta
 * créditos del comercio en algo que nadie escucha entero.
 */
export const MAX_TTS_CHARS = 4000

/** Caracteres por token equivalente. Ver el docblock del módulo. */
export const TTS_CHARS_PER_TOKEN = 4

/**
 * Caracteres → tokens equivalentes para `debitAiUsage`. Siempre hacia arriba:
 * un texto corto cobra 1, nunca 0 (un débito de 0 tokens `debitAiUsage` lo
 * descarta sin llamar al backend, y una lectura gratis es una lectura que no
 * queda registrada en el ledger).
 */
export function charsToEquivalentTokens(text: string): number {
  if (!text) return 0
  return Math.ceil(text.length / TTS_CHARS_PER_TOKEN)
}
