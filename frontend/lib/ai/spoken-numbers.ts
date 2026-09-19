/**
 * Números formateados → palabras en español, para la voz del agente.
 *
 * El TTS (pago y nativo) lee "1.500.000" como "uno punto cinco cero cero
 * punto cero cero cero" (reporte del owner 2026-09-19): el separador de miles
 * le suena a punto decimal repetido. Antes de sintetizar se reemplaza cada
 * número CON separadores por su lectura ("un millón quinientos mil"). Los
 * números sin separador ("2026", "15") se dejan: los motores ya los leen bien.
 *
 * El separador se deduce de la ESTRUCTURA, no de la configuración del tenant:
 * grupos de exactamente tres dígitos son miles ("1.500.000", "1,500,000"), un
 * último grupo de uno o dos dígitos es la parte decimal ("1.234,56",
 * "1,234.56", "12,5"). Así sirve para cualquier país sin pasarle el locale.
 */

const UNIDADES = [
  "cero", "uno", "dos", "tres", "cuatro", "cinco", "seis", "siete", "ocho", "nueve",
  "diez", "once", "doce", "trece", "catorce", "quince", "dieciséis", "diecisiete",
  "dieciocho", "diecinueve", "veinte", "veintiuno", "veintidós", "veintitrés",
  "veinticuatro", "veinticinco", "veintiséis", "veintisiete", "veintiocho", "veintinueve",
]
const DECENAS = ["", "", "veinte", "treinta", "cuarenta", "cincuenta", "sesenta", "setenta", "ochenta", "noventa"]
const CENTENAS = [
  "", "ciento", "doscientos", "trescientos", "cuatrocientos", "quinientos",
  "seiscientos", "setecientos", "ochocientos", "novecientos",
]

/** 0..999. `apocope`: "un" en vez de "uno" (delante de mil / millón). */
function hastaMil(n: number, apocope: boolean): string {
  if (n === 0) return ""
  if (n === 100) return "cien"
  const c = Math.floor(n / 100)
  const r = n % 100
  const partes: string[] = []
  if (c > 0) partes.push(CENTENAS[c])
  if (r > 0) {
    if (r < 30) {
      let w = UNIDADES[r]
      if (apocope) {
        if (r === 1) w = "un"
        else if (r === 21) w = "veintiún"
      }
      partes.push(w)
    } else {
      const d = Math.floor(r / 10)
      const u = r % 10
      let w = DECENAS[d]
      if (u > 0) w += ` y ${apocope && u === 1 ? "un" : UNIDADES[u]}`
      partes.push(w)
    }
  }
  return partes.join(" ")
}

/**
 * Entero no negativo → palabras. Cubre hasta 999.999.999.999 (miles de
 * millones); más grande que eso devuelve null y el número queda como estaba.
 */
export function integerToSpanishWords(n: number): string | null {
  if (!Number.isInteger(n) || n < 0 || n >= 1_000_000_000_000) return null
  if (n === 0) return "cero"

  const millones = Math.floor(n / 1_000_000)
  const restoMillon = n % 1_000_000
  const miles = Math.floor(restoMillon / 1000)
  const unidades = restoMillon % 1000

  const partes: string[] = []
  if (millones > 0) {
    // "un millón", "dos millones", "mil millones", "veintiún millones".
    const w = millones === 1 ? "un" : integerToSpanishWordsApocope(millones)
    partes.push(`${w} ${millones === 1 ? "millón" : "millones"}`)
  }
  if (miles > 0) {
    partes.push(miles === 1 ? "mil" : `${hastaMil(miles, true)} mil`)
  }
  if (unidades > 0) {
    partes.push(hastaMil(unidades, false))
  }
  return partes.join(" ")
}

/** Como `integerToSpanishWords` pero con apócope al final ("veintiún", "ciento un"). */
function integerToSpanishWordsApocope(n: number): string {
  const miles = Math.floor(n / 1000)
  const unidades = n % 1000
  const partes: string[] = []
  if (miles > 0) partes.push(miles === 1 ? "mil" : `${hastaMil(miles, true)} mil`)
  if (unidades > 0) partes.push(hastaMil(unidades, true))
  return partes.join(" ")
}

/**
 * Un número formateado ("1.500.000", "1,234.56", "12,5", "-475.000") → su
 * lectura. Null si no se pudo interpretar (queda el texto original).
 */
export function formattedNumberToWords(raw: string): string | null {
  const negative = raw.startsWith("-")
  const body = negative ? raw.slice(1) : raw
  const m = /^(\d{1,3}(?:[.,]\d{3})+|\d+)(?:([.,])(\d{1,2}))?$/.exec(body)
  if (!m) return null
  const intDigits = m[1].replace(/[.,]/g, "")
  const intWords = integerToSpanishWords(Number(intDigits))
  if (intWords === null) return null

  let out = intWords
  if (m[3] !== undefined) {
    const dec = m[3]
    // "0,05" → "cero coma cero cinco": con cero adelante se leen los dígitos,
    // si no "coma cinco" diría otra cosa.
    const decWords = dec.startsWith("0")
      ? dec.split("").map((d) => UNIDADES[Number(d)]).join(" ")
      : integerToSpanishWords(Number(dec))
    out += ` coma ${decWords}`
  }
  return negative ? `menos ${out}` : out
}

/**
 * Número con separador de miles o decimal, no pegado a otros dígitos ni a
 * "/" (una fecha "18/09/2026" no es un número). El signo va aparte para no
 * comerse el guion de "1-7 May".
 */
const FORMATTED_NUMBER = /(^|[^\d/.,-])(-?)(\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?|\d+[.,]\d{1,2})(?![\d/]|[.,]\d)/g

/** Reemplaza en un texto todos los números con separadores por su lectura. */
export function spellFormattedNumbers(text: string): string {
  return text.replace(FORMATTED_NUMBER, (whole, before: string, sign: string, num: string) => {
    const words = formattedNumberToWords(`${sign}${num}`)
    return words === null ? whole : `${before}${words}`
  })
}
