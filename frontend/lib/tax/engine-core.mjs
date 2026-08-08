/**
 * Motor de cálculo de impuestos — implementación única y pura.
 *
 * F1 del plan multi-país (context/38-impuestos-multi-pais.md, sección
 * "Arquitectura propuesta -> B"). Este archivo es la ÚNICA fuente de verdad
 * de la lógica en el lado JS/TS: `engine.ts` importa y tipa exactamente esta
 * implementación (no la duplica). El espejo en PHP es
 * `api/lib/Tax/TaxEngine.php` — mismos nombres de campo, misma estrategia de
 * redondeo, mismos casos de fixtures (`api/lib/Tax/fixtures.json`).
 *
 * Es `.mjs` (JS puro, sin tipos) para que el runner de paridad
 * (`verify-engine.mjs`) lo pueda importar con Node pelado, sin compilar TS
 * ni instalar dependencias.
 *
 * Cero I/O, cero dependencia de catálogo/BD/config global — funciones puras.
 * Nadie consume este motor todavía: el cableado a carrito/SaleService es F2,
 * fuera de alcance de F1.
 */

/**
 * Redondeo half-up (away from zero) determinístico a `decimals` posiciones.
 *
 * Problema: `1.005 * 100` en floating point binario (IEEE-754 double) da
 * `100.49999999999999`, no `100.5` — porque 1.005 no es representable
 * exactamente en binario. Un `Math.round(valor * factor) / factor` ingenuo
 * redondea mal ese borde (da 1.00 en vez de 1.01).
 *
 * Estrategia elegida (debe coincidir matemáticamente con el lado PHP, ver
 * comentario en TaxEngine.php): desplazar la coma decimal usando notación
 * exponencial en STRING (`Number(`${valor}e${decimals}`)`) en lugar de
 * multiplicar el float por `10**decimals`. El parser de literales numéricos
 * del motor JS interpreta "1.005e2" como el decimal exacto 100.5 y busca el
 * double más cercano a ESE valor decimal — evita el error compuesto de una
 * multiplicación flotante intermedia. Después de `Math.round` (que sobre un
 * valor no-negativo redondea .5 hacia arriba, es decir away-from-zero) se
 * desplaza de vuelta con la misma técnica de string-shift.
 *
 * El signo se maneja aparte porque `Math.round` de JS redondea los .5
 * negativos hacia +Infinity (ej. -1.5 -> -1), no away-from-zero. Operamos
 * siempre sobre el valor absoluto y reaplicamos el signo al final para
 * garantizar redondeo half-away-from-zero en ambos sentidos, igual que
 * PHP_ROUND_HALF_UP.
 */
export function roundHalfUp(value, decimals) {
  if (!Number.isFinite(value)) return value
  if (value === 0) return 0
  const sign = value < 0 ? -1 : 1
  const abs = Math.abs(value)
  const shifted = Number(`${abs}e${decimals}`)
  const rounded = Math.round(shifted)
  const result = Number(`${rounded}e-${decimals}`)
  return sign * result
}

/**
 * Calcula impuestos para una lista de líneas de venta.
 *
 * Ver spec cerrada (F1, decisión D1 del owner): el redondeo es POR LÍNEA a
 * `config.decimals`, half-up. Los totales y buckets de `byRate` son la SUMA
 * de las líneas ya redondeadas — nunca se reaplica la fórmula de impuesto
 * sobre el agregado. La única corrección que se aplica sobre la suma es una
 * limpieza de ruido binario (ver `cleanSum` más abajo), no un recálculo.
 *
 * @param {Array<{qty:number, unitPrice:number, discount?:number, taxRate:number, taxKind:('rate'|'exempt'), taxIncluded:boolean}>} lines
 * @param {{decimals:number}} config
 */
export function computeTaxes(lines, config) {
  const decimals = config.decimals
  const outLines = []
  const bucketOrder = []
  const buckets = new Map()

  let totalNet = 0
  let totalTax = 0
  let totalGross = 0

  for (const line of lines) {
    const qty = line.qty
    const unitPrice = line.unitPrice
    const discount = line.discount || 0
    const taxRate = line.taxRate
    const taxKind = line.taxKind
    const taxIncluded = line.taxIncluded

    let net
    let tax
    let gross

    if (taxKind === 'exempt') {
      // Exento: el impuesto es 0 sin importar taxRate (kind es la dimensión,
      // no la tasa). La base entera va al bucket exento.
      const base = roundHalfUp(qty * unitPrice - discount, decimals)
      net = base
      tax = 0
      gross = base
    } else if (taxIncluded) {
      // Precio final (impuesto incluido): el bruto de línea se redondea
      // primero a `decimals` (normaliza qty fraccional / floats), el
      // impuesto se calcula y redondea sobre ese bruto ya normalizado, y el
      // neto se DERIVA por resta (gross - tax) en lugar de redondearse en
      // forma independiente — así net + tax = gross siempre cierra exacto
      // por línea, como exigen los formatos fiscales.
      gross = roundHalfUp(qty * unitPrice - discount, decimals)
      tax = roundHalfUp((gross * taxRate) / (100 + taxRate), decimals)
      net = roundHalfUp(gross - tax, decimals)
    } else {
      // Precio neto (impuesto añadido): análogo al caso anterior pero desde
      // el neto; el bruto se deriva por suma.
      net = roundHalfUp(qty * unitPrice - discount, decimals)
      tax = roundHalfUp((net * taxRate) / 100, decimals)
      gross = roundHalfUp(net + tax, decimals)
    }

    outLines.push({ net, tax, gross })

    const key = taxRate + '|' + taxKind
    if (!buckets.has(key)) {
      buckets.set(key, { taxRate, taxKind, base: 0, tax: 0, gross: 0 })
      bucketOrder.push(key)
    }
    const bucket = buckets.get(key)
    bucket.base += net
    bucket.tax += tax
    bucket.gross += gross

    totalNet += net
    totalTax += tax
    totalGross += gross
  }

  // Limpieza de ruido de representación binaria en las sumas (ej. 10.01 +
  // 5.02 puede dar 15.030000000000001 en floating point aunque ambos sumandos
  // ya estén exactos a `decimals`). NO es recalcular el impuesto sobre el
  // agregado: la suma matemática ya es el valor correcto, esto solo la
  // vuelve a expresar sin el ruido del punto flotante binario.
  const cleanSum = (value) => roundHalfUp(value, decimals)

  const byRate = bucketOrder.map((key) => {
    const bucket = buckets.get(key)
    return {
      taxRate: bucket.taxRate,
      taxKind: bucket.taxKind,
      base: cleanSum(bucket.base),
      tax: cleanSum(bucket.tax),
      gross: cleanSum(bucket.gross),
    }
  })

  return {
    lines: outLines,
    byRate,
    totals: {
      net: cleanSum(totalNet),
      tax: cleanSum(totalTax),
      gross: cleanSum(totalGross),
    },
  }
}
