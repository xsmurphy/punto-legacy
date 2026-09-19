import { readdirSync, readFileSync } from "node:fs"
import path from "node:path"
import { describe, expect, it } from "vitest"

/**
 * GUARD — títulos de sección canónicos (context/84 T4/T5, §11 C1/C2/C7; owner
 * 2026-09-18).
 *
 *  T4 — dentro de una card el título es `<CardTitle>` A SECAS: su className
 *       puede traer LAYOUT (flex, gap, justify, truncate), nunca tipografía
 *       (`text-<tamaño>`, `font-<peso>`, `tracking-*`, `uppercase`). El
 *       tamaño lo decide el primitive (`components/ui/card.tsx`); un override
 *       por call-site es lo que dejó ~50 títulos de card con 4 tamaños
 *       distintos. Si un "título" chico parecía necesario, casi siempre era
 *       el label de un KPI: eso es `StatTile` (`components/stat-tile.tsx`).
 *  T5 — sin iconos en títulos: ni en `CardTitle` ni en h1/h2/h3. Se detecta
 *       un componente de `lucide-react` importado por el archivo y usado
 *       dentro del título.
 *
 * Mismo patrón que `entity-detail-structure.test.ts`: escaneo estático,
 * excepción solo en allowlist CON MOTIVO, y la allowlist no se pudre.
 *
 * `BigMetricCard` (dashboard, `app/(panel)/page.tsx`) conserva su estilo por
 * decisión del owner, pero NO necesita allowlist: no usa `CardTitle` ni un
 * heading — su label es un `div` con la flecha de tendencia como indicador.
 */

// lib/ui/__tests__ → raíz de `frontend/`
const FRONTEND_ROOT = path.resolve(import.meta.dirname, "..", "..", "..")

const SKIP_DIRS = new Set(["node_modules", ".next", ".git", "dist", "build", "coverage", "__tests__"])

/** Dónde se busca: el panel y los componentes que monta. */
const SCAN_DIRS = ["app/(panel)", "components"]

/**
 * Fuera de alcance por path: primitives (su estilo ES la regla), y
 * superficies que no son el panel (POS, admin, pantallas, docs, sitio).
 */
const EXCLUDED_PREFIXES = [
  "components/ui/",
  "components/pos/",
  "components/register/", // UI de la caja (/pos)
  "components/admin/",
  "components/screens/",
  "components/docs/",
  "components/site/",
  "components/magicui/",
  "components/hero115.tsx",
]

/** Títulos con override legítimo, con motivo. Vacía a propósito. */
const TITLE_ALLOWLIST: Record<string, string> = {}

function listFiles(rel: string, out: string[] = []): string[] {
  let entries
  try {
    entries = readdirSync(path.join(FRONTEND_ROOT, rel), { withFileTypes: true })
  } catch {
    return out
  }
  for (const e of entries) {
    const child = path.posix.join(rel, e.name)
    if (e.isDirectory()) {
      if (!SKIP_DIRS.has(e.name)) listFiles(child, out)
    } else if (e.name.endsWith(".tsx")) {
      out.push(child)
    }
  }
  return out
}

/** Código sin comentarios — un comentario que EXPLICA la regla no la rompe. */
function stripComments(src: string): string {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, " "))
    .split("\n")
    .map((l) => l.replace(/(^|[^:"'`])\/\/.*$/, "$1"))
    .join("\n")
}

const TYPOGRAPHY =
  /(^|[\s"'`{(])(text-(xs|sm|base|lg|\d?xl|\[[^\]]+\])|font-(thin|extralight|light|normal|medium|semibold|bold|extrabold|black)|tracking-[\w[\].-]+|uppercase)(?=$|[\s"'`})])/

/** Índice del `>` que cierra el tag abierto en `start`, respetando `{...}`. */
function endOfOpeningTag(src: string, start: number): number {
  let depth = 0
  let quote: string | null = null
  for (let i = start; i < src.length; i++) {
    const c = src[i]
    if (quote) {
      if (c === quote) quote = null
      continue
    }
    if (depth === 0 && (c === '"' || c === "'")) quote = c
    else if (c === "{") depth++
    else if (c === "}") depth--
    else if (c === ">" && depth === 0) return i
  }
  return -1
}

function lucideImports(src: string): Set<string> {
  const names = new Set<string>()
  for (const m of src.matchAll(/import\s*\{([^}]*)\}\s*from\s*["']lucide-react["']/g)) {
    for (const part of m[1].split(",")) {
      const alias = part.trim().split(/\s+as\s+/).pop()?.trim()
      if (alias && /^[A-Z]/.test(alias)) names.add(alias)
    }
  }
  return names
}

function lineOf(src: string, idx: number): number {
  return src.slice(0, idx).split("\n").length
}

interface Hit {
  file: string
  line: number
  rule: "T4" | "T5"
}

function titleHits(file: string): Hit[] {
  const src = stripComments(readFileSync(path.join(FRONTEND_ROOT, file), "utf8"))
  const icons = lucideImports(src)
  const hits: Hit[] = []
  for (const m of src.matchAll(/<(CardTitle|h[1-3])\b/g)) {
    const tag = m[1]
    const start = m.index!
    const close = endOfOpeningTag(src, start)
    if (close < 0) continue
    const opening = src.slice(start, close + 1)
    const line = lineOf(src, start)

    if (tag === "CardTitle") {
      const cls = opening.match(/className=(\{[\s\S]*\}|"[^"]*"|'[^']*')/)
      if (cls && TYPOGRAPHY.test(cls[1])) hits.push({ file, line, rule: "T4" })
    }

    if (opening.endsWith("/>")) continue
    const end = src.indexOf(`</${tag}>`, close)
    if (end < 0) continue
    const body = src.slice(close + 1, end)
    const usesIcon = [...body.matchAll(/<([A-Z]\w*)\b/g)].some((c) => icons.has(c[1]))
    if (usesIcon) hits.push({ file, line, rule: "T5" })
  }
  return hits
}

const files = SCAN_DIRS.flatMap((d) => listFiles(d)).filter(
  (f) => !EXCLUDED_PREFIXES.some((p) => f.startsWith(p)),
)

describe("títulos de sección canónicos (context/84 T4/T5)", () => {
  const all = files.flatMap(titleHits)

  it("ningún CardTitle con tipografía en className (T4)", () => {
    const offenders = all
      .filter((h) => h.rule === "T4" && !(h.file in TITLE_ALLOWLIST))
      .map((h) => `${h.file}:${h.line}`)
    expect(
      offenders,
      "CardTitle a secas; si era el label de un KPI, usá StatTile — context/84 T4",
    ).toEqual([])
  })

  it("ningún título (CardTitle, h1/h2/h3) con icono lucide (T5)", () => {
    const offenders = all
      .filter((h) => h.rule === "T5" && !(h.file in TITLE_ALLOWLIST))
      .map((h) => `${h.file}:${h.line}`)
    expect(offenders, "Sin iconos en títulos — context/84 T5").toEqual([])
  })

  it("la allowlist no se pudre", () => {
    const stale = Object.keys(TITLE_ALLOWLIST).filter(
      (f) => !files.includes(f) || titleHits(f).length === 0,
    )
    expect(stale).toEqual([])
  })
})
