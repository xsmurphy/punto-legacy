import { readdirSync, readFileSync } from "node:fs"
import path from "node:path"
import { describe, expect, it } from "vitest"

import { PARAGUAY_LITERAL_ALLOWLIST } from "@/lib/tenant-locale/allowlist"

/**
 * GUARD — ningún literal paraguayo suelto en el código.
 *
 * Regla del owner (2026-08-25), textual: «no debe haber "Gs" hardcodeado.. el
 * sistema debe adaptarse dinámicamente al país seleccionado nunca asumir que
 * está en Paraguay siempre», escalada el mismo día a «esto es grave y hay que
 * solucionar ya, esto no puede pasar en ningún sector del sistema».
 *
 * El problema que este test existe para impedir: Punto se vende fuera de
 * Paraguay, y un default paraguayo NO falla ruidosamente. No tira excepción,
 * no rompe el build, no aparece en un log — imprime el ticket con el símbolo
 * equivocado, o formatea la fecha al revés, y nadie se entera hasta que lo ve
 * el cliente del tenant. El caso que disparó todo esto fue
 * `app/(screen)/checkout/`: el visor que mira el CLIENTE del comercio decía
 * "Total a pagar en Gs" y formateaba con `Intl.NumberFormat("es-PY")`,
 * para todos los tenants del mundo.
 *
 * Arreglar los ~130 call-sites de una vez no alcanza: el 131 entra la semana
 * que viene y nadie se entera, porque el síntoma es invisible desde adentro.
 * Por eso el barrido viene con guard.
 *
 * CÓMO FUNCIONA: se recorre el árbol de `frontend/` y `api/`, se busca cada
 * patrón paraguayo (símbolo, código ISO, locale, país, TZ, prefijo
 * telefónico) y se exige que cada hit esté en `PARAGUAY_LITERAL_ALLOWLIST`
 * (`lib/tenant-locale/allowlist.ts`), que pide un MOTIVO escrito por entrada.
 * Un literal nuevo rompe el test hasta que su autor lo resuelva con un
 * resolver de `lib/tenant-locale.ts` o lo excuse a propósito y por escrito.
 *
 * Los tres asserts son simétricos (mismo patrón que
 * `lib/navigation/__tests__/routes-coverage.test.ts`):
 *  1. Ningún literal real queda fuera de la allowlist.
 *  2. La allowlist no se pudre: no hay entradas para archivos inexistentes.
 *  3. La allowlist no se pudre: no hay entradas para archivos que ya no
 *     tienen ningún literal (excusas que sobrevivieron a su propio fix).
 */

// lib/tenant-locale/__tests__ → raíz de `frontend/` → raíz del repo
const FRONTEND_ROOT = path.resolve(import.meta.dirname, "..", "..", "..")
const REPO_ROOT = path.resolve(FRONTEND_ROOT, "..")

/** Subárboles escaneados, relativos a la raíz del repo. */
const SCAN_ROOTS = ["frontend", "api"]

/**
 * `.sql` está incluido a propósito: las migraciones definen funciones de
 * Postgres (`period_is_closed`, `fn_tenant_wall_clock`) que truncan fechas con
 * una TZ literal adentro. Es el rincón donde un default paraguayo es más
 * difícil de ver y más caro de arreglar, porque una migración ya aplicada no
 * se corrige editándola.
 */
const SCAN_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx", ".php", ".sql", ".json"])

/** Archivos generados: enormes y sin valor para este guard. */
const SKIP_FILES = new Set([
  "package-lock.json",
  "pnpm-lock.yaml",
  "composer.lock",
  "skills-lock.json",
  "tsconfig.tsbuildinfo",
])

/** Directorios que nunca se escanean (dependencias, artefactos de build). */
const SKIP_DIRS = new Set([
  "node_modules",
  ".next",
  ".git",
  "dist",
  "build",
  "coverage",
  "vendor",
  ".turbo",
])

/**
 * Formas de asumir Paraguay.
 *
 * Cada patrón cubre una DIMENSIÓN distinta, porque son fallas independientes:
 * un archivo puede tener la moneda bien y la fecha mal. Los nombres son los
 * que aparecen en el mensaje de error, así que describen el problema, no la
 * regex.
 */
const PARAGUAY_PATTERNS: { name: string; re: RegExp }[] = [
  // Símbolo de moneda como literal de string o como texto JSX (`>Gs<`).
  // Se exige que esté solo o pegado a un punto ("Gs."), para no marcar
  // palabras que casualmente contengan esas letras.
  { name: 'símbolo "Gs"', re: /(["'`>])\s*Gs\.?\s*(["'`<])/ },
  { name: 'símbolo "₲"', re: /₲/ },
  // Código ISO 4217 de Paraguay.
  { name: 'código "PYG"', re: /\bPYG\b/ },
  // Locale paraguayo — el argumento de Intl.* / toLocale*.
  { name: 'locale "es-PY"', re: /\bes[-_]PY\b/ },
  // Zona horaria.
  { name: 'TZ "America/Asuncion"', re: /America\/Asuncion/ },
  // País como literal de string. NO matchea `=== "PY"` ni `!== "PY"`: comparar
  // contra PY es un GATE legítimo (activar una feature fiscal paraguaya), lo
  // que está prohibido es ASIGNAR PY como default.
  { name: 'país "PY" como default', re: /(?<![=!]=\s)(["'])PY\1/ },
  // Prefijo telefónico internacional de Paraguay.
  { name: 'prefijo telefónico 595', re: /(["'])\+?595\1/ },
]

function listFiles(dir: string, out: string[] = []): string[] {
  let entries
  try {
    entries = readdirSync(dir, { withFileTypes: true })
  } catch {
    return out
  }
  for (const entry of entries) {
    if (entry.name.startsWith(".") && entry.name !== ".claude") {
      if (SKIP_DIRS.has(entry.name)) continue
    }
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      if (SKIP_DIRS.has(entry.name)) continue
      listFiles(full, out)
    } else if (
      SCAN_EXTENSIONS.has(path.extname(entry.name)) &&
      !SKIP_FILES.has(entry.name)
    ) {
      out.push(full)
    }
  }
  return out
}

/**
 * Quita los comentarios de una línea antes de buscar el patrón.
 *
 * Un comentario que EXPLICA por qué algo ya no asume Paraguay ("antes esto
 * decía Gs") es exactamente lo que queremos que la gente escriba — sería
 * absurdo que el guard lo prohibiera. Solo se mira código ejecutable.
 *
 * Es un stripper de línea, no un parser: no entiende comentarios de bloque
 * multilínea ni strings que contengan "//". Alcanza de sobra para el uso
 * (los literales prohibidos son cortos y viven en una sola línea) y el costo
 * de equivocarse es un falso negativo aislado, no un falso positivo que
 * frene el build de alguien.
 */
function stripComments(line: string): string {
  return line
    .replace(/^\s*\*.*$/, "") // cuerpo de un bloque /** ... */
    .replace(/\/\/.*$/, "")
    .replace(/\/\*.*?\*\//g, "")
    .replace(/^\s*#.*$/, "") // comentario PHP con #
    // Comentario SQL. Anclado a inicio de línea a propósito: un `--` suelto en
    // medio de una línea puede ser un decremento (`i--`) o dos signos menos,
    // y no vale la pena distinguirlos para ganar un caso que no existe.
    .replace(/^\s*--.*$/, "")
}

interface Hit {
  file: string
  line: number
  pattern: string
  text: string
}

function scan(): Hit[] {
  const hits: Hit[] = []
  for (const root of SCAN_ROOTS) {
    for (const file of listFiles(path.join(REPO_ROOT, root))) {
      const rel = path.relative(REPO_ROOT, file)
      let content
      try {
        content = readFileSync(file, "utf8")
      } catch {
        continue
      }
      const lines = content.split("\n")
      for (let i = 0; i < lines.length; i++) {
        const code = stripComments(lines[i])
        if (!code.trim()) continue
        for (const { name, re } of PARAGUAY_PATTERNS) {
          if (re.test(code)) {
            hits.push({ file: rel, line: i + 1, pattern: name, text: code.trim().slice(0, 120) })
          }
        }
      }
    }
  }
  return hits
}

const allHits = scan()
const allowedFiles = new Set(Object.keys(PARAGUAY_LITERAL_ALLOWLIST))
const filesWithHits = new Set(allHits.map((h) => h.file))

describe("sin hardcodeo de Paraguay", () => {
  it("encuentra archivos para escanear (sanity)", () => {
    // Si el layout del repo cambia y el walker deja de encontrar archivos,
    // los asserts de abajo pasarían vacíos y el guard sería decorativo —
    // exactamente el modo de falla que este test existe para evitar.
    const scanned = SCAN_ROOTS.flatMap((r) => listFiles(path.join(REPO_ROOT, r)))
    expect(scanned.length).toBeGreaterThan(500)
  })

  it("no deja ningún literal paraguayo fuera de la allowlist", () => {
    const offenders = allHits.filter((h) => !allowedFiles.has(h.file))
    const detail = offenders
      .map((h) => `  ${h.file}:${h.line}  [${h.pattern}]  ${h.text}`)
      .join("\n")
    expect(
      offenders.length,
      offenders.length === 0
        ? ""
        : `\n\nSe encontraron ${offenders.length} literal(es) paraguayo(s) nuevos:\n\n${detail}\n\n` +
            `Punto se vende fuera de Paraguay y estos defaults no fallan ruidosamente:\n` +
            `imprimen el símbolo equivocado o la fecha al revés, y se descubre cuando\n` +
            `lo ve el cliente del tenant.\n\n` +
            `Resolvelo con los resolvers de 'lib/tenant-locale.ts':\n` +
            `  resolveCurrencyLabel(config) → etiqueta de moneda del tenant\n` +
            `  resolveNumberLocale(config)  → separador de miles\n` +
            `  resolveDateLocale(config)    → locale de fecha (d/m/y vs m/d/y)\n` +
            `  resolveTimeZone(config)      → TZ IANA del tenant\n` +
            `  resolvePhoneCountry(config)  → país para parsear teléfonos\n\n` +
            `Si de verdad es una excepción legítima (catálogo de países, seed,\n` +
            `fixture, feature gateada por país, o los libros de Punto S.A. en\n` +
            `'/admin' y la landing), agregala a 'lib/tenant-locale/allowlist.ts'\n` +
            `CON EL MOTIVO ESCRITO. No la borres de acá en silencio.\n`,
    ).toBe(0)
  })

  it("no acumula entradas de allowlist para archivos que ya no existen", () => {
    const stale = [...allowedFiles].filter((f) => {
      try {
        readFileSync(path.join(REPO_ROOT, f), "utf8")
        return false
      } catch {
        return true
      }
    })
    expect(
      stale,
      `Estas entradas de la allowlist apuntan a archivos inexistentes. Borralas:\n${stale.join("\n")}`,
    ).toEqual([])
  })

  it("no acumula entradas de allowlist para archivos que ya no tienen literales", () => {
    const unnecessary = [...allowedFiles].filter(
      (f) => !filesWithHits.has(f) && !PARAGUAY_LITERAL_ALLOWLIST[f].startsWith("PENDIENTE"),
    )
    expect(
      unnecessary,
      `Estos archivos ya no tienen literales paraguayos: la excusa sobrevivió a su\n` +
        `propio fix. Sacalos de la allowlist para que el guard vuelva a cubrirlos:\n${unnecessary.join("\n")}`,
    ).toEqual([])
  })
})
