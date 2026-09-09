/**
 * Número a letras en español, para el bloque `nums_to_words` ("Son: …") de
 * las plantillas de impresión.
 *
 * Hasta hoy ese bloque resolvía `null` a propósito: no había conversión y un
 * total en letras INCORRECTO en un comprobante fiscal es peor que ninguno.
 * El efecto colateral era que, desde que un bloque sin valor imprime su
 * título solo (decisión del owner 2026-09-04), la factura salía con un "Son:"
 * pelado — que es lo que el owner reportó el 2026-09-09.
 *
 * NO nombra la moneda. "Son: veinte mil", no "Son: veinte mil guaraníes":
 * el bootstrap trae el CÓDIGO de la moneda (`PYG`, `ARS`), no su nombre en
 * palabras ni su plural, y no hay tabla de la que sacarlo. Inventar "pesos"
 * a partir del código sería adivinar en un documento fiscal, y hardcodear
 * "guaraníes" contradice que nada esté atado a Paraguay. Si el comercio
 * quiere nombrar la moneda, la escribe en el TÍTULO del bloque ("Son
 * guaraníes:"), que es suyo y ya se imprime delante del valor.
 *
 * Género: masculino, la forma que se usa cuando no se nombra la unidad
 * ("veintiún mil", "un millón"). Con moneda femenina no concordaría, pero
 * como no la nombramos, no se plantea.
 */

const UNIDADES = [
  "cero", "uno", "dos", "tres", "cuatro", "cinco", "seis", "siete", "ocho",
  "nueve", "diez", "once", "doce", "trece", "catorce", "quince", "dieciséis",
  "diecisiete", "dieciocho", "diecinueve", "veinte", "veintiuno", "veintidós",
  "veintitrés", "veinticuatro", "veinticinco", "veintiséis", "veintisiete",
  "veintiocho", "veintinueve",
]

const DECENAS = [
  "", "", "", "treinta", "cuarenta", "cincuenta", "sesenta", "setenta",
  "ochenta", "noventa",
]

const CENTENAS = [
  "", "ciento", "doscientos", "trescientos", "cuatrocientos", "quinientos",
  "seiscientos", "setecientos", "ochocientos", "novecientos",
]

/** 0-999. `apocope` convierte el "uno" final en "un" (va antes de mil/millón). */
function tresCifras(n: number, apocope: boolean): string {
  if (n === 0) return ""
  if (n === 100) return "cien"

  const c = Math.floor(n / 100)
  const resto = n % 100
  const partes: string[] = []
  if (c > 0) partes.push(CENTENAS[c])

  if (resto > 0) {
    if (resto < 30) {
      // "veintiún mil" y "un millón", pero "veintiuno" / "uno" sueltos.
      if (apocope && resto === 1) partes.push("un")
      else if (apocope && resto === 21) partes.push("veintiún")
      else partes.push(UNIDADES[resto])
    } else {
      const d = Math.floor(resto / 10)
      const u = resto % 10
      if (u === 0) {
        partes.push(DECENAS[d])
      } else {
        const unidad = apocope && u === 1 ? "un" : UNIDADES[u]
        partes.push(`${DECENAS[d]} y ${unidad}`)
      }
    }
  }
  return partes.join(" ")
}

/**
 * Cuenta de un grupo (0-999999) con apócope en su última unidad, para usar
 * delante de "millones"/"mil": "veintiún", "treinta y un", "ciento un".
 */
function grupoConApocope(n: number): string {
  if (n < 1000) return tresCifras(n, true)
  const miles = Math.floor(n / 1000)
  const resto = n % 1000
  const cabeza = miles === 1 ? "mil" : `${tresCifras(miles, true)} mil`
  return resto > 0 ? `${cabeza} ${tresCifras(resto, true)}` : cabeza
}

/**
 * Parte entera en letras. Soporta hasta billones (10^12) — más que eso
 * devuelve `null` en vez de una frase inventada: es un comprobante.
 */
function enteroEnLetras(n: number): string | null {
  if (!Number.isFinite(n) || n < 0) return null
  if (n === 0) return "cero"
  if (n >= 1e12) return null

  const millones = Math.floor(n / 1e6)
  const miles = Math.floor((n % 1e6) / 1000)
  const resto = n % 1000

  const partes: string[] = []

  if (millones > 0) {
    // El apócope alcanza a TODO el grupo, no solo al millón exacto:
    // "veintiún millones", "treinta y un millones". Por eso se arma con
    // `grupoConApocope` y no con `enteroEnLetras`, que resuelve el último
    // grupo como número suelto ("veintiuno") — ahí salía "veintiuno
    // millones".
    partes.push(millones === 1 ? "un millón" : `${grupoConApocope(millones)} millones`)
  }
  if (miles > 0) {
    // "mil", nunca "un mil".
    partes.push(miles === 1 ? "mil" : `${tresCifras(miles, true)} mil`)
  }
  if (resto > 0) partes.push(tresCifras(resto, false))

  return partes.join(" ")
}

/**
 * Monto en letras, con la primera en mayúscula.
 *
 * `decimals`: cuántos decimales maneja la moneda del tenant (0 para las que
 * no los usan). Con decimales y centavos > 0 agrega la forma contable
 * "con NN/100", que es la que se lee en una factura — no "con cincuenta
 * centavos", porque "centavo" tampoco es el nombre de la fracción en toda
 * moneda.
 *
 * Devuelve `null` cuando no puede expresar el número (negativo, no finito,
 * fuera de rango): el bloque queda sin valor, que es el comportamiento que
 * ya tenía.
 */
export function amountToWordsEs(amount: number, decimals: number): string | null {
  if (!Number.isFinite(amount) || amount < 0) return null

  const factor = decimals > 0 ? 10 ** decimals : 1
  const redondeado = Math.round(amount * factor) / factor
  const entero = Math.floor(redondeado)
  const centavos = Math.round((redondeado - entero) * factor)

  const letras = enteroEnLetras(entero)
  if (letras === null) return null

  const conCentavos =
    decimals > 0 && centavos > 0
      ? `${letras} con ${String(centavos).padStart(decimals, "0")}/${factor}`
      : letras

  return conCentavos.charAt(0).toUpperCase() + conCentavos.slice(1)
}
