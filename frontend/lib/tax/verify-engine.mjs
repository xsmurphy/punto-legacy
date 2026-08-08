#!/usr/bin/env node
/**
 * verify-engine.mjs — runner de paridad del lado JS/TS.
 *
 * Corre los MISMOS fixtures que verify_engine.php (api/lib/Tax/fixtures.json
 * es la copia canónica única, no se duplica) contra `computeTaxes` de
 * engine-core.mjs. Comparación con tolerancia 0.
 *
 * Uso: node frontend/lib/tax/verify-engine.mjs
 * Node pelado, sin dependencias, sin paso de compilación (por eso importa
 * el .mjs y no el .ts).
 *
 * Exit code 0 si todo pasa, 1 si algún caso difiere.
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { computeTaxes } from './engine-core.mjs'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const fixturesPath = path.resolve(__dirname, '../../../api/lib/Tax/fixtures.json')

const raw = readFileSync(fixturesPath, 'utf8')
const cases = JSON.parse(raw)

function valuesMatch(expected, actual) {
  if (expected !== null && actual !== null && typeof expected === 'object' && typeof actual === 'object') {
    const expectedKeys = Object.keys(expected)
    const actualKeys = Object.keys(actual)
    if (expectedKeys.length !== actualKeys.length) return false
    for (const key of expectedKeys) {
      if (!(key in actual)) return false
      if (!valuesMatch(expected[key], actual[key])) return false
    }
    return true
  }

  if (typeof expected === 'number' || typeof actual === 'number') {
    return Number(expected) === Number(actual)
  }

  return expected === actual
}

function formatDiff(expected, actual) {
  return `  esperado: ${JSON.stringify(expected)}\n  obtenido: ${JSON.stringify(actual)}\n`
}

let failed = 0
const total = cases.length

cases.forEach((testCase, index) => {
  const description = testCase.description || `caso #${index}`
  const { input, expected } = testCase

  const actual = computeTaxes(input.lines, input.config)

  if (valuesMatch(expected, actual)) {
    console.log(`PASS  [${index}] ${description}`)
    return
  }

  failed++
  console.log(`FAIL  [${index}] ${description}`)
  console.log(formatDiff(expected, actual))
})

const passed = total - failed
console.log(`\n${passed}/${total} casos OK (JS)`)

process.exit(failed > 0 ? 1 : 0)
