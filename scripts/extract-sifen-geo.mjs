/**
 * Extrae el catálogo geográfico de SIFEN (departamentos / distritos /
 * ciudades) desde el generador de XML de FE-PY y lo escribe como SEED.
 *
 * ── Por qué de ahí ───────────────────────────────────────────────────────
 *
 * FE-PY (https://fepy.punto.la) es el motor de facturación electrónica de
 * Punto, y ese archivo es el catálogo contra el que FE-PY VALIDA los códigos
 * antes de armar el XML. La fuente de verdad de un código fiscal es quien lo
 * valida: un código que sale de otro catálogo y que FE-PY no reconoce es un
 * documento rechazado. Por eso el seed sale de acá y no de una transcripción
 * propia, ni del catálogo de un proveedor intermediario.
 *
 * ── De qué versión salió el seed que está commiteado ─────────────────────
 *
 * Archivo fuente : FE-PY/src/services/constants.service.ts
 * Repo           : /Users/xstian/Dropbox/Factura Electrónica/FE-PY (fuera de este repo)
 * Commit         : 75383a7 ("1.0.276") — último que tocó ese archivo
 * sha256 fuente  : 44aa1de1f82abc8b089229cf1c85885e16277e861479059dfa4aa7983acd39be
 * Conteos        : 18 departamentos / 272 distritos / 6766 ciudades
 *
 * El sha256 del fuente viaja DENTRO del JSON (`_fuente`), así que regenerar
 * desde el mismo archivo produce un seed byte-idéntico salvo `_extraido`. Si
 * el sha cambia, el catálogo de la SET cambió y el diff del seed es revisable
 * fila por fila.
 *
 * ── Cuándo se corre ──────────────────────────────────────────────────────
 *
 * A MANO, nunca en el build ni en un cron: el catálogo cambia cuando la SET
 * publica distritos o ciudades nuevas (años, no sprints), y el repo fuente no
 * es una dependencia de Punto — vive en el disco del owner. El seed
 * commiteado es dato fiscal versionado, no un artefacto de build.
 *
 *   node scripts/extract-sifen-geo.mjs [ruta/a/constants.service.ts]
 *
 * Salida: api/database/seeds/sifen-geo.json (lo lee PHP: `SifenGeoSource`).
 */

import { createHash } from "node:crypto"
import { readFileSync, writeFileSync } from "node:fs"
import { dirname, resolve } from "node:path"
import { fileURLToPath } from "node:url"

const HERE = dirname(fileURLToPath(import.meta.url))

const DEFAULT_SOURCE =
  "/Users/xstian/Dropbox/Factura Electrónica/FE-PY/src/services/constants.service.ts"

const source = process.argv[2] ?? DEFAULT_SOURCE
const out = resolve(HERE, "../api/database/seeds/sifen-geo.json")

const text = readFileSync(source, "utf8")
const sha = createHash("sha256").update(text, "utf8").digest("hex")

/**
 * Aísla el cuerpo de `<nombre> = [ … ];` a nivel de clase. Se corta con el
 * primer `];` a comienzo de línea porque el archivo fuente formatea así todos
 * sus catálogos — un `]` suelto dentro de un literal no aparece nunca.
 */
function arrayBody(name) {
  const start = text.indexOf(`\n  ${name} = [`)
  if (start === -1) throw new Error(`No encontré el array "${name}" en ${source}`)
  const from = text.indexOf("[", start)
  const end = text.indexOf("\n  ];", from)
  if (end === -1) throw new Error(`No encontré el cierre del array "${name}"`)
  return text.slice(from + 1, end)
}

/** `'ASUNCION'` o `"YBY YA'U"` — el fuente alterna comillas según el contenido. */
const STRING = String.raw`(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")`

function unquote(single, double) {
  const raw = single ?? double ?? ""
  return raw.replace(/\\(.)/g, "$1")
}

/** `{ codigo: N, descripcion: '…' }` con un tercer campo opcional (el padre). */
function parseRows(name, parentKey) {
  const body = arrayBody(name)
  const pattern = parentKey
    ? new RegExp(
        String.raw`\{\s*codigo:\s*(\d+)\s*,\s*descripcion:\s*${STRING}\s*,\s*${parentKey}:\s*(\d+)\s*,?\s*\}`,
        "g",
      )
    : new RegExp(
        String.raw`\{\s*codigo:\s*(\d+)\s*,\s*descripcion:\s*${STRING}\s*,?\s*\}`,
        "g",
      )

  const rows = []
  for (const m of body.matchAll(pattern)) {
    const row = { codigo: Number(m[1]), descripcion: unquote(m[2], m[3]) }
    if (parentKey) row[parentKey] = Number(m[4])
    rows.push(row)
  }

  // Un literal que el regex no matchee sería una fila que se pierde en silencio.
  const literales = (body.match(/\{\s*codigo:/g) ?? []).length
  if (literales !== rows.length) {
    throw new Error(
      `"${name}": ${literales} literales en el fuente pero ${rows.length} parseados`,
    )
  }
  return rows
}

const departamentos = parseRows("departamentos", null)
const distritos = parseRows("distritos", "departamento")
const ciudades = parseRows("ciudades", "distrito")

// Integridad referencial: una ciudad cuyo distrito no existe es un domicilio
// que la cascada no puede armar, y se descubriría recién al emitir. Es la
// misma invariante que las FK de la mig 207 enforcean en la BD.
const depCodes = new Set(departamentos.map((d) => d.codigo))
const distCodes = new Set(distritos.map((d) => d.codigo))
const huerfanos = [
  ...distritos.filter((d) => !depCodes.has(d.departamento)).map((d) => `distrito ${d.codigo}`),
  ...ciudades.filter((c) => !distCodes.has(c.distrito)).map((c) => `ciudad ${c.codigo}`),
]
if (huerfanos.length > 0) {
  throw new Error(`Filas huérfanas (${huerfanos.length}): ${huerfanos.slice(0, 10).join(", ")}`)
}

// Códigos duplicados: dos filas con el mismo `codigo` colisionarían en el
// upsert por (countrycode, code) y una taparía a la otra en silencio.
for (const [nombre, filas] of [
  ["departamentos", departamentos],
  ["distritos", distritos],
  ["ciudades", ciudades],
]) {
  const vistos = new Set()
  for (const f of filas) {
    if (vistos.has(f.codigo)) throw new Error(`"${nombre}": código duplicado ${f.codigo}`)
    vistos.add(f.codigo)
  }
}

const byDescripcion = (a, b) => a.descripcion.localeCompare(b.descripcion, "es")

const catalogo = {
  _fuente: `FE-PY/src/services/constants.service.ts sha256:${sha} — extraído con scripts/extract-sifen-geo.mjs`,
  _extraido: new Date().toISOString().slice(0, 10),
  departamentos: departamentos.sort(byDescripcion),
  distritos: distritos.sort(byDescripcion),
  ciudades: ciudades.sort(byDescripcion),
}

writeFileSync(out, JSON.stringify(catalogo) + "\n", "utf8")

const porDistrito = new Map()
for (const c of ciudades) porDistrito.set(c.distrito, (porDistrito.get(c.distrito) ?? 0) + 1)
const porDepartamento = new Map()
for (const d of distritos) porDepartamento.set(d.departamento, (porDepartamento.get(d.departamento) ?? 0) + 1)

console.log(
  [
    `fuente:        ${source}`,
    `sha256:        ${sha}`,
    `departamentos: ${departamentos.length}`,
    `distritos:     ${distritos.length}`,
    `ciudades:      ${ciudades.length}`,
    `max distritos por departamento: ${Math.max(...porDepartamento.values())}`,
    `max ciudades por distrito:      ${Math.max(...porDistrito.values())}`,
    `salida: ${out}`,
  ].join("\n"),
)
