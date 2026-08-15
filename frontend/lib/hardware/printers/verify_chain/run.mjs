#!/usr/bin/env node
// run.mjs — arnés de verificación de IMPRESIÓN sobre la MISMA venta que
// `api/lib/Sales/verify_chain/run_sale_chain.php` acaba de persistir.
//
// Reusa los resolvers y builders REALES — nada reimplementado:
//   - `buildTicketDataFromTransaction` (build-ticket-data.ts): el mismo
//     wrapper que usa el POS para reimprimir un ticket desde la transacción
//     persistida.
//   - `BLOCK_VALUE_RESOLVERS` / `ITEM_FIELD_RESOLVERS` (blocks.ts): la MISMA
//     tabla que usan render-template.ts (ESC/POS) y html-renderer.ts
//     (fallback navegador) para resolver el valor de cada bloque.
//
// Corre con el runtime nativo de TS de Node (>=22.6) — sin tsx/ts-node, sin
// paso de build. `alias-loader.mjs` resuelve el import "@/..." (mismo mapeo
// que tsconfig `paths`) porque Node no lo hace solo fuera de Next.js.
//
// Entrada: los dumps JSON que escribió el runner PHP en
// `<tmp>/punto-verify-chain/<caseId>.json` (uno por caso, con la venta YA
// persistida — transactionDatas es el `meta.transactionDetails` real, con
// taxId/taxRate/taxKind/taxAmount/taxNet CONGELADOS por SaleService).
//
// Uso: node --experimental-loader=./lib/hardware/printers/verify_chain/alias-loader.mjs \
//        lib/hardware/printers/verify_chain/run.mjs
// (ver verify_chain/run.sh, que corre PHP primero y esto después)
//
// Exit code 0 si todo pasa, 1 si algo difiere.

import { readdirSync, readFileSync } from "node:fs"
import os from "node:os"
import path from "node:path"
import { buildTicketDataFromTransaction } from "../build-ticket-data.ts"
import { BLOCK_VALUE_RESOLVERS, ITEM_FIELD_RESOLVERS, groupItemsByTaxRate, formatMoney } from "../blocks.ts"

const dumpDir = path.join(os.tmpdir(), "punto-verify-chain")

let files
try {
  files = readdirSync(dumpDir).filter((f) => f.endsWith(".json"))
} catch {
  console.error(`No se encontró ${dumpDir} — corré primero run_sale_chain.php (ver run.sh)`)
  process.exit(1)
}
if (files.length === 0) {
  console.error(`${dumpDir} está vacío — corré primero run_sale_chain.php (ver run.sh)`)
  process.exit(1)
}

let failures = 0
let total = 0

function check(label, expected, actual) {
  total++
  const ok = expected === actual
  if (ok) {
    console.log(`  PASS  ${label}`)
    return
  }
  failures++
  console.log(`  FAIL  ${label}`)
  console.log(`        esperado: ${JSON.stringify(expected)}`)
  console.log(`        obtenido: ${JSON.stringify(actual)}`)
}

for (const file of files.sort()) {
  const dump = JSON.parse(readFileSync(path.join(dumpDir, file), "utf8"))
  console.log(`\n=== impresión: ${dump.caseId} ===`)

  const data = buildTicketDataFromTransaction(dump.transaction, null, "receipt")

  // ── Bloques por tasa: item_total_by_rate / subtotal_by_rate / iva_by_rate,
  //    y el agregado iva_total — sobre el desglose que arma groupItemsByTaxRate
  //    (espejo TS de SaleService::groupTaxByRate) a partir de TicketItem[]. ──
  const buckets = groupItemsByTaxRate(data.items)
  for (const expBucket of dump.expectedByRate) {
    const bucket = buckets.find((b) => b.rate === expBucket.rate && b.kind === expBucket.kind)
    const label = `rate=${expBucket.rate} kind=${expBucket.kind}`
    if (!bucket) {
      total++
      failures++
      console.log(`  FAIL  bucket ${label}: no encontrado en groupItemsByTaxRate`)
      continue
    }
    // Los bloques por-tasa se buscan por taxId (block.text guarda el taxId
    // que el operador eligió al armar la plantilla, siempre una tasa REAL
    // del catálogo del tenant). bucket.taxId===null solo pasa en el caso de
    // aislamiento multi-tenant (línea sin catálogo resuelto) — no hay forma
    // de configurar un bloque contra "null" en el editor real, así que ese
    // bucket queda fuera de este check (no es un bug: es el mismo "hueco de
    // dato, no de bloque" que documenta blocks.ts).
    if (bucket.taxId === null) {
      console.log(`  SKIP  bloques por-tasa para ${label}: bucket sin taxId (no configurable en una plantilla real)`)
      continue
    }
    const block = { text: bucket.taxId }
    check(`subtotal_by_rate ${label}`, formatMoney(expBucket.base), BLOCK_VALUE_RESOLVERS.subtotal_by_rate(data, block))
    check(`iva_by_rate ${label}`, formatMoney(expBucket.amount), BLOCK_VALUE_RESOLVERS.iva_by_rate(data, block))
    check(`item_total_by_rate ${label}`, formatMoney(expBucket.base + expBucket.amount), BLOCK_VALUE_RESOLVERS.item_total_by_rate(data, block))
  }
  check("iva_total", formatMoney(dump.expectedTotals.tax), BLOCK_VALUE_RESOLVERS.iva_total(data))
  check("total", formatMoney(dump.expectedTotals.gross), BLOCK_VALUE_RESOLVERS.total(data))
  check("subtotal", formatMoney(dump.expectedTotals.gross), BLOCK_VALUE_RESOLVERS.subtotal(data))

  // ── HALLAZGO — formatMoney() (blocks.ts) hardcodea Intl.NumberFormat
  //    "es-PY"/PYG para TODA la app, sin mirar la moneda/decimals del tenant
  //    (TicketData no lleva currency). Para un tenant decimals=2 esto no es
  //    cosmético: PYG formatea con 0 decimales, así que los centavos se
  //    PIERDEN en el ticket impreso (114.84 → "Gs. 115", ni siquiera trunca,
  //    REDONDEA). No se compara contra otro formateo "correcto" inventado —
  //    se extraen los dígitos del string ya impreso y se confirma que
  //    representan el monto real; si no coinciden, es la pérdida de
  //    precisión, no un problema de tolerancia del test. */
  if (dump.decimals === 2) {
    const printed = BLOCK_VALUE_RESOLVERS.total(data)
    const digitsOnly = printed.replace(/[^\d.,]/g, "").replace(",", ".")
    const parsed = Number.parseFloat(digitsOnly)
    total++
    if (Math.abs(parsed - dump.expectedTotals.gross) < 0.005) {
      console.log(`  PASS  total impreso conserva los centavos (${printed})`)
    } else {
      failures++
      console.log("  FAIL  total impreso (BUG conocido, ver reporte): formatMoney() hardcodea PYG/0-decimales")
      console.log(`        esperado (monto real): ${dump.expectedTotals.gross}`)
      console.log(`        impreso: "${printed}" (interpretado como ${parsed})`)
    }
  }

  // ── Bloques de línea: item_tax / item_tax_amount / item_price_notax ────
  dump.expectedLines.forEach((expLine, i) => {
    const item = data.items[i]
    if (!item) {
      total++
      failures++
      console.log(`  FAIL  línea ${i} (${expLine.itemSku}): no está en TicketData.items`)
      return
    }
    const label = `línea ${i} (${expLine.itemSku})`
    const expTaxLabel = expLine.expected.tax === 0 && item.taxKind === "exempt" ? "Exento" : `${item.taxRate}%`
    check(`${label} item_tax`, expTaxLabel, ITEM_FIELD_RESOLVERS.item_tax(item))
    check(`${label} item_tax_amount`, formatMoney(expLine.expected.tax), ITEM_FIELD_RESOLVERS.item_tax_amount(item))
    const expNetPerUnit = item.qty !== 0 ? formatMoney(expLine.expected.net / item.qty) : null
    check(`${label} item_price_notax`, expNetPerUnit, ITEM_FIELD_RESOLVERS.item_price_notax(item))

    // item_discount: HALLAZGO — el resolver formatea `item.discount` como
    // dinero, pero el valor persistido en esa key es el % efectivo de la
    // línea (frontend/lib/commands/create-sale.ts:331), no el monto
    // (`totalDiscount`, mig del campo). Con descuento > 0 este check FALLA
    // a propósito — no se ajusta el esperado para taparlo (ver reporte).
    if (expLine.discount > 0) {
      check(`${label} item_discount (BUG conocido, ver reporte)`, formatMoney(expLine.discount), ITEM_FIELD_RESOLVERS.item_discount(item))
    }
  })
}

console.log(`\n${total - failures}/${total} assertions OK (impresión)`)
process.exit(failures > 0 ? 1 : 0)
