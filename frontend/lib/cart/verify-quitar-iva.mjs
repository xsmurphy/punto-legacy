#!/usr/bin/env node
/**
 * verify-quitar-iva.mjs — casos de cálculo del neteo "quitar IVA" multi-tasa.
 *
 * Mismo patrón que `lib/tax/verify-engine.mjs`: Node pelado, sin dependencias
 * ni paso de compilación (Node 22.6+ despoja los tipos de `.ts` al importarlo,
 * y el único import de `allocate-discounts.ts` es `import type`). Se importa
 * la implementación REAL — nada se reescribe acá, si no el test no probaría
 * nada.
 *
 * Uso: node frontend/lib/cart/verify-quitar-iva.mjs
 * Exit code 0 si todo pasa, 1 si algún caso difiere.
 *
 * Qué protege (regresión de 2026-08-22): `lineGross` dividía TODA línea por
 * 1.10 (`TAX_RATE = 0.10`). Con ítems al 5% o al 3% —los hay vendidos en
 * producción— el neteo descontaba de más. Ahora usa la tasa de la línea, y
 * respeta exento / impuesto-no-incluido.
 *
 * El caso MIXTO además verifica el invariante que une carrito y backend:
 *   total del carrito  ==  subtotal - discount del payload  ==  lo que
 *   persiste `transaction` (transactionTotal - transactionDiscount).
 */

import { lineGross, allocateLineDiscounts, EXEMPT_LINE_TAX } from './allocate-discounts.ts'
import { computeTaxes } from '../tax/engine-core.mjs'

const INCLUDED = (rate) => ({ rate, kind: 'rate', included: true })
const ADDED = (rate) => ({ rate, kind: 'rate', included: false })
const EXEMPT = EXEMPT_LINE_TAX

/** Lo que hacía la fórmula vieja, para la tabla del reporte. */
const legacyGross = (raw) => Math.round(raw / 1.1)

/** Espejo exacto de `lineSubtotal` (lib/cart/store.ts) sobre la misma función. */
function cartLineSubtotal(line, ivaRemoved) {
  if (line.voucher) return 0
  const factor = 1 - (line.discount ?? 0) / 100
  return lineGross(line.qty * line.unitPrice * factor, ivaRemoved, line.tax)
}

let failed = 0
function check(label, actual, expected) {
  const ok = actual === expected
  if (!ok) failed++
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}: ${actual}${ok ? '' : ` (esperado ${expected})`}`)
}

// ── 1. Neteo por línea, una tasa a la vez ───────────────────────────────────
console.log('\n── Neteo de una línea de 25.000 con "quitar IVA" ──')
const unit = 25000
const single = [
  ['10% incluido', INCLUDED(10), 22727],
  ['5%  incluido', INCLUDED(5), 23810],
  ['3%  incluido', INCLUDED(3), 24272],
  ['exento      ', EXEMPT, 25000],
  ['10% AÑADIDO ', ADDED(10), 25000],
]
for (const [label, tax, expected] of single) {
  const actual = lineGross(unit, true, tax)
  check(`${label} → neto`, actual, expected)
  console.log(`        (fórmula vieja ÷1,10 daba ${legacyGross(unit)}; delta ${actual - legacyGross(unit)})`)
}

// Sin "quitar IVA" nada cambia, sea cual sea la tasa.
console.log('\n── Sin "quitar IVA" el precio de lista no se toca ──')
for (const [label, tax] of single) {
  check(`${label} → bruto`, lineGross(unit, false, tax), unit)
}

// ── 2. Venta MIXTA con descuento de venta ───────────────────────────────────
// 10% + 5% + exento, "quitar IVA" activo, descuento de venta del 10% que
// alcanza a las tres líneas.
console.log('\n── Venta mixta (10% + 5% + exento), quitar IVA + descuento de venta 10% ──')
const lines = [
  { lineId: 'a', qty: 1, unitPrice: 25000, tax: INCLUDED(10) },
  { lineId: 'b', qty: 2, unitPrice: 12000, tax: INCLUDED(5) },
  { lineId: 'c', qty: 1, unitPrice: 8000, tax: EXEMPT },
]
const saleDiscount = { value: 10, mode: 'percent', lineIds: ['a', 'b', 'c'] }
const ivaRemoved = true

const allocations = allocateLineDiscounts(lines, saleDiscount, ivaRemoved)

// Lado CARRITO: suma de subtotales de línea menos el descuento de venta,
// calculado como lo hace `selectCartTotal`.
const linesSubtotal = lines.reduce((s, l) => s + cartLineSubtotal(l, ivaRemoved), 0)
const saleDiscountAmount = Math.round((linesSubtotal * 10) / 100)
const cartTotal = Math.max(0, linesSubtotal - saleDiscountAmount)

// Lado PAYLOAD/BACKEND: `subtotal` = Σ total de los ítems (bruto),
// `discount` = Σ totalDiscount. `transactionTotal - transactionDiscount` es
// lo que queda registrado como cobrado.
const payloadSubtotal = allocations.reduce((s, a) => s + a.gross, 0)
const payloadDiscount = allocations.reduce((s, a) => s + a.totalDiscount, 0)
const persistedNet = payloadSubtotal - payloadDiscount

lines.forEach((l, i) => {
  console.log(
    `  línea ${l.lineId} (${l.tax.kind === 'exempt' ? 'exenta' : l.tax.rate + '%'}, ${l.qty} × ${l.unitPrice}): ` +
      `bruto ${allocations[i].gross}, descuento ${allocations[i].totalDiscount}`,
  )
})
console.log(`  subtotal payload = ${payloadSubtotal} | descuento = ${payloadDiscount}`)
check('total del carrito', cartTotal, 48226)
check('subtotal - descuento persistido', persistedNet, cartTotal)

// El bruto de cada línea es el de SU tasa, no el de 1,10 para todas.
check('bruto línea 10%', allocations[0].gross, 22727)
check('bruto línea 5% (×2)', allocations[1].gross, 22857)
check('bruto línea exenta', allocations[2].gross, 8000)

// ── 3. Coherencia con el motor de impuestos del backend ─────────────────────
// Con `ivaRemoved` el backend fuerza rate=0/exempt y toma como base el `total`
// de cada línea (SaleService::enrichWithTaxes). El impuesto tiene que ser 0 y
// la base tiene que cerrar EXACTAMENTE contra el neto del carrito.
console.log('\n── Espejo del backend con quitar IVA (rate=0 / exempt) ──')
const backend = computeTaxes(
  allocations.map((a) => ({
    qty: 1,
    unitPrice: a.gross,
    discount: a.totalDiscount,
    taxRate: 0,
    taxKind: 'exempt',
    taxIncluded: true,
  })),
  { decimals: 0 },
)
check('impuesto que congela el backend', backend.totals.tax, 0)
check('base gravada del backend == total del carrito', backend.totals.net, cartTotal)

// ── 4. Misma venta SIN quitar IVA: el impuesto sale por tasa real ───────────
console.log('\n── Misma venta sin quitar IVA (control multi-tasa) ──')
const allocPlain = allocateLineDiscounts(lines, saleDiscount, false)
const backendPlain = computeTaxes(
  lines.map((l, i) => ({
    qty: 1,
    unitPrice: allocPlain[i].gross,
    discount: allocPlain[i].totalDiscount,
    taxRate: l.tax.rate,
    taxKind: l.tax.kind,
    taxIncluded: l.tax.included,
  })),
  { decimals: 0 },
)
const cartPlain = lines.reduce((s, l) => s + cartLineSubtotal(l, false), 0)
const plainTotal = cartPlain - Math.round((cartPlain * 10) / 100)
check('total del carrito sin quitar IVA', plainTotal, 51300)
check('base + impuesto == total', backendPlain.totals.net + backendPlain.totals.tax, plainTotal)
console.log(`  IVA por tasa: ${JSON.stringify(backendPlain.byRate)}`)

// ── 5. Base del motor: `total`, no `count × price` ──────────────────────────
// Regresión del punto 4: el backend armaba la línea del motor con
// `qty = count, unitPrice = price`. `price` es el UNITARIO ya redondeado, así
// que `count × price` no vuelve a dar `total` — que es lo que se persiste en
// `itemSold.itemSoldTotal`. La base gravada de `toTaxObj` quedaba desalineada
// de la plata registrada. Ahora el motor recibe `qty = 1, unitPrice = total`.
console.log('\n── Base del motor: `total` vs `count × price` ──')
const bLine = lines[1] // 2 × 12.000 al 5% incluido
const bUnitNet = lineGross(bLine.unitPrice, true, bLine.tax)
const bByUnit = bLine.qty * bUnitNet
const bByTotal = allocations[1].gross
console.log(`  price unitario neteado = ${bUnitNet} → count × price = ${bByUnit}`)
console.log(`  total de la línea (lo que se persiste) = ${bByTotal}`)
check('la base vieja divergía del total persistido', bByUnit !== bByTotal, true)
check('la base nueva es el total persistido', bByTotal, 22857)

console.log(failed === 0 ? '\nTODOS LOS CASOS PASARON' : `\n${failed} CASO(S) FALLARON`)
process.exit(failed === 0 ? 0 : 1)
