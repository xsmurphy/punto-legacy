/**
 * Textos del KuDE para los BLOQUES DE PLANTILLA — la representación gráfica
 * del Documento Electrónico impresa en el comprobante que configura el
 * comercio (ticket de rollo o factura de hoja).
 *
 * ── Qué vive acá y qué NO ───────────────────────────────────────────────
 *
 * NO vive acá nada que ya resuelva `lib/kude/types.ts`, el módulo del KuDE
 * PDF que Punto renderiza para el portal y el email. Las dos superficies
 * imprimen EL MISMO documento fiscal, así que el CDC y la URL de consulta
 * salen de las mismas funciones (`groupCdc`, `consultationUrl`): si el PDF
 * agrupara el CDC y el ticket lo imprimiera corrido, el comprador tendría dos
 * versiones distintas del mismo código y ninguna forma de saber cuál tipear.
 *
 * Lo que sí es propio de este módulo es la LEYENDA y la frase de consulta —
 * texto que el KuDE PDF compone en su layout y que acá tiene que existir como
 * valor de un bloque suelto que el operador ubica donde quiera.
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
