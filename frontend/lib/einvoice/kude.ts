/**
 * Textos del KuDE para los BLOQUES DE PLANTILLA — la representación gráfica
 * del Documento Electrónico impresa en el comprobante que configura el
 * comercio (ticket de rollo o factura de hoja).
 *
 * ── Qué vive acá y qué NO ───────────────────────────────────────────────
 *
 * Este es el ÚNICO módulo del KuDE en el frontend. El PDF del KuDE no lo
 * arma Punto: lo devuelve el motor de facturación electrónica ya renderizado,
 * y lo que queda de este lado es lo que el comercio imprime en SU comprobante
 * — la leyenda, la frase de consulta, el CDC y la URL de verificación.
 *
 * Que el CDC y la URL de consulta salgan de UNA función compartida
 * (`groupCdc`, `consultationUrl`) es la razón de ser del módulo: todas las
 * superficies imprimen EL MISMO documento fiscal, y si el rollo agrupara el
 * CDC y la hoja lo imprimiera corrido, el comprador tendría dos versiones
 * distintas del mismo código y ninguna forma de saber cuál tipear.
 *
 * ── Por qué son constantes y no texto que el comercio escribe ────────────
 *
 * El KuDE es un documento REGULADO: la leyenda es texto normativo, no copy. Si
 * cada comercio la tipeara en el campo "texto" de un bloque cualquiera, cada
 * plantilla tendría su propia variante con su propia errata.
 *
 * Que sean constantes NO contradice "lo que se imprime lo decide la
 * plantilla": la plantilla sigue decidiendo SI el bloque sale y DÓNDE. Lo
 * único que deja de ser negociable es qué dice, igual que `document_number`
 * no deja elegir de dónde sale el número.
 */

/**
 * Leyenda obligatoria del KuDE. Va tal cual, en mayúsculas: lo que el
 * comprador tiene en la mano es una representación gráfica, y el documento
 * fiscal real es el XML firmado.
 *
 * Verificada contra un KuDE real y legal
 * (`context/refs/kude-ejemplo-balloon-party.pdf`).
 */
export const KUDE_LEYENDA =
  "ESTE DOCUMENTO ES UNA REPRESENTACIÓN GRÁFICA DE UN DOCUMENTO ELECTRÓNICO (XML)"

/**
 * Invitación a verificar. `{doc}` se reemplaza por el nombre del documento y
 * `{url}` por la URL de consulta, que NO se escribe a mano: se DERIVA de la
 * cadena del QR que devolvió la emisión (`consultationUrl`), misma regla que
 * ya fijó el KuDE PDF — un dominio de la SET hardcodeado en el código es un
 * dato fiscal que nadie revisa cuando cambia.
 */
export const KUDE_CONSULTA_TEXT =
  "Consulte la validez de esta {doc} con el número CDC impreso abajo en: {url}"

/**
 * Nombre del documento electrónico para la frase de consulta ("Consulte la
 * validez de esta FACTURA ELECTRÓNICA...").
 *
 * Mapa propio y no `DOC_NUMBER_LABELS` de `blocks.ts`: ese resuelve rótulos de
 * NÚMERO ("Factura Nro.:") y sirve para otra cosa. Reusarlo produciría
 * "Consulte la validez de esta Factura Nro.:".
 *
 * El default es genérico a propósito: llamarle "factura" a algo que no lo es,
 * en un documento fiscal, es peor que no nombrarlo.
 */
const KUDE_DOC_NAMES: Record<string, string> = {
  invoice: "FACTURA ELECTRÓNICA",
  factura: "FACTURA ELECTRÓNICA",
  credit: "FACTURA ELECTRÓNICA",
  sale: "FACTURA ELECTRÓNICA",
  receipt: "FACTURA ELECTRÓNICA",
  return: "NOTA DE CRÉDITO ELECTRÓNICA",
  delivery: "NOTA DE REMISIÓN ELECTRÓNICA",
}

export function kudeDocumentName(docType: string): string {
  return KUDE_DOC_NAMES[docType] ?? "DOCUMENTO ELECTRÓNICO"
}

/**
 * CDC en once grupos de cuatro posiciones — MT §13.4.4. Es un requisito de
 * legibilidad de la norma, no una decisión estética.
 */
export function groupCdc(cdc: string): string {
  const clean = (cdc || "").replace(/\s+/g, "")

  return (clean.match(/.{1,4}/g) ?? []).join(" ")
}

/**
 * URL de consulta pública del DE. Se DERIVA de la cadena del QR que devolvió
 * la emisión (su origen + path, sin los parámetros): no se escribe a mano un
 * dominio de la SET en el código, que es un dato fiscal que nadie revisa
 * cuando cambia.
 */
export function consultationUrl(qrData: string | null): string | null {
  if (!qrData) return null
  try {
    const url = new URL(qrData)
    return `${url.origin}${url.pathname}`
  } catch {
    return null
  }
}
