import { existsSync, readdirSync, readFileSync } from "node:fs"
import path from "node:path"
import { describe, expect, it } from "vitest"

/**
 * GUARD — estructura de las fichas de entidad y del chrome compartido del
 * panel (context/84 §3 y §10.2, owner 2026-09-18).
 *
 * El pedido del owner, textual: que el usuario, al entrar a cualquier
 * entidad, SEPA dónde ver el resumen y dónde editar, sin redescubrir
 * secciones. Y que no vuelva a pasar. La regla la impone la API de
 * `EntityShell` (`components/page/entity-shell.tsx`: `summary` y `data`
 * obligatorios, Resumen 1ª, Datos 2ª, Guardar solo en Datos); este test
 * impone que se USE, y que no reaparezcan las piezas que la rompían:
 *
 *  G2 — toda página de ficha (`app/(panel)/**\/[id]/page.tsx` que no sea un
 *       documento) usa `EntityShell`, directo o a través de un componente
 *       que importa (ej. la ficha de cliente delega en `ContactDetailView`).
 *  G3 — `KpiCard` no existe y nadie lo importa: los KPIs son `StatTile`.
 *  G4 — nadie define un `BackLink`/`BackButton` propio ni arma un "volver" con
 *       `<ArrowLeft` a mano: el único es `components/page/back-link.tsx`.
 *  G5 — nada de MAYÚSCULAS armadas a mano (`uppercase` en un className) en
 *       títulos o labels. La única mayúscula permitida es la que trae un
 *       primitive (`StatTile`, fuera del alcance por path).
 *
 * Mismo patrón que `lib/tenant-locale/__tests__/no-hardcoded-paraguay.test.ts`:
 * escaneo estático, cada excepción en una allowlist CON MOTIVO, y asserts
 * simétricos — una excepción que ya no hace falta también rompe el test, para
 * que la excusa no sobreviva a su propio fix.
 */

// lib/ui/__tests__ → raíz de `frontend/`
const FRONTEND_ROOT = path.resolve(import.meta.dirname, "..", "..", "..")
const PANEL_DIR = "app/(panel)"

const SKIP_DIRS = new Set(["node_modules", ".next", ".git", "dist", "build", "coverage"])

function listFiles(rel: string, exts = [".ts", ".tsx"], out: string[] = []): string[] {
  let entries
  try {
    entries = readdirSync(path.join(FRONTEND_ROOT, rel), { withFileTypes: true })
  } catch {
    return out
  }
  for (const e of entries) {
    const child = path.posix.join(rel, e.name)
    if (e.isDirectory()) {
      if (!SKIP_DIRS.has(e.name)) listFiles(child, exts, out)
    } else if (exts.includes(path.extname(e.name))) {
      out.push(child)
    }
  }
  return out
}

function read(rel: string): string {
  return readFileSync(path.join(FRONTEND_ROOT, rel), "utf8")
}

/** Código sin comentarios — un comentario que EXPLICA la regla no la rompe. */
function stripComments(src: string): string {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, " "))
    .split("\n")
    .map((l) => l.replace(/(^|[^:"'`])\/\/.*$/, "$1"))
    .join("\n")
}

function hitsByLine(file: string, re: RegExp): number[] {
  return stripComments(read(file))
    .split("\n")
    .flatMap((line, i) => (re.test(line) ? [i + 1] : []))
}

// ── G2 — fichas usan EntityShell ────────────────────────────────────────────

/**
 * Páginas `[id]` que NO son fichas. Documentos: tienen su patrón tipo factura
 * (context/84 §6) y su propio armazón (`DocumentShell`, a crear).
 */
const NOT_ENTITY_DETAIL: Record<string, string> = {
  "app/(panel)/transactions/[id]/page.tsx": "Documento (venta, cotización, recibo) — context/84 §6",
  "app/(panel)/orders/[id]/page.tsx": "Documento (orden) — context/84 §6",
  "app/(panel)/purchase/[id]/page.tsx": "Documento (compra) — context/84 §6",
  "app/(panel)/purchase/drafts/[id]/page.tsx": "Documento (borrador de compra) — context/84 §6",
  "app/(panel)/ordenes-pago/[id]/page.tsx": "Documento (orden de pago) — context/84 §6",
  "app/(panel)/remisiones/[id]/page.tsx": "Documento (remisión) — context/84 §6",
  "app/(panel)/stock-transfer/[id]/page.tsx": "Documento (transferencia de stock) — context/84 §6",
  "app/(panel)/inventory-count/[id]/page.tsx": "Documento (conteo de inventario) — context/84 §6",
  // context/84 §13 la cuenta como FICHA, pero el alcance de la pasada del
  // 2026-09-18 (brief del owner) la dejó afuera junto con los documentos: una
  // lista de precios es una tabla de reglas, sin Resumen propio todavía. Al
  // migrarla, esta línea se borra y el guard la empieza a exigir.
  "app/(panel)/settings/price-lists/[id]/page.tsx":
    "Ficha pendiente — fuera del alcance de la pasada 2026-09-18 (context/84 §13)",
}

function importsEntityShell(src: string): boolean {
  return /from\s+["']@\/components\/page\/entity-shell["']/.test(src)
}

/** Resuelve un import `@/…` a un archivo del frontend, si existe. */
function resolveAlias(spec: string): string | null {
  const base = spec.replace(/^@\//, "")
  for (const cand of [`${base}.tsx`, `${base}.ts`, `${base}/index.tsx`, `${base}/index.ts`]) {
    if (existsSync(path.join(FRONTEND_ROOT, cand))) return cand
  }
  return null
}

function usesEntityShell(file: string): boolean {
  const src = read(file)
  if (importsEntityShell(src)) return true
  // Un nivel de indirección: la página delega la ficha en un componente.
  for (const m of src.matchAll(/from\s+["'](@\/[^"']+)["']/g)) {
    const dep = resolveAlias(m[1])
    if (dep && importsEntityShell(read(dep))) return true
  }
  return false
}

// ── G4 — un solo BackLink ───────────────────────────────────────────────────

const BACK_LINK_FILE = "components/page/back-link.tsx"

/** `<ArrowLeft` legítimo: no es un "volver" de página. */
const ARROW_LEFT_ALLOWLIST: Record<string, string> = {
  "app/(panel)/finanzas/conciliacion/page.tsx":
    "No navega: cierra la sesión de conciliación abierta y vuelve a la lista de cuentas de la MISMA página (estado local, sin ruta a la que volver)",
}

// ── G5 — mayúsculas a mano ──────────────────────────────────────────────────

/**
 * Dónde se busca: el panel y los componentes de las fichas y dominios que
 * monta. Los primitives (`components/ui`, `components/stat-tile.tsx`) quedan
 * afuera por path: su mayúscula es parte del componente, no del call-site.
 */
const UPPERCASE_SCAN_DIRS = [
  PANEL_DIR,
  "components/domain",
  "components/items",
  "components/employees",
  "components/outlets",
]

/** `uppercase` legítimo, con motivo. */
const UPPERCASE_ALLOWLIST: Record<string, string> = {}

const UPPERCASE_CLASS = /\buppercase\b/
function isClassContext(line: string): boolean {
  return /className|cn\(|cva\(|class=/.test(line)
}

// ── Tests ───────────────────────────────────────────────────────────────────

describe("fichas de entidad (context/84 §3)", () => {
  const detailPages = listFiles(PANEL_DIR, [".tsx"]).filter((f) => f.endsWith("/[id]/page.tsx"))

  it("toda página de ficha usa EntityShell (G2)", () => {
    const offenders = detailPages.filter((f) => !(f in NOT_ENTITY_DETAIL) && !usesEntityShell(f))
    expect(offenders, "Fichas sin EntityShell — ver context/84 §3").toEqual([])
  })

  it("la lista de páginas que NO son ficha no apunta a archivos inexistentes", () => {
    const stale = Object.keys(NOT_ENTITY_DETAIL).filter((f) => !detailPages.includes(f))
    expect(stale).toEqual([])
  })

  it("ninguna página exceptuada usa ya EntityShell (la excusa sobra)", () => {
    const stale = Object.keys(NOT_ENTITY_DETAIL).filter(
      (f) => detailPages.includes(f) && usesEntityShell(f),
    )
    expect(stale).toEqual([])
  })
})

describe("KpiCard murió (G3)", () => {
  it("el archivo no existe", () => {
    expect(existsSync(path.join(FRONTEND_ROOT, "components/domain/contacts/kpi-card.tsx"))).toBe(false)
  })

  it("nadie lo importa ni lo usa", () => {
    const offenders = [...listFiles("app"), ...listFiles("components"), ...listFiles("hooks"), ...listFiles("lib")]
      .filter((f) => !f.includes("__tests__"))
      .filter((f) => /kpi-card|\bKpiCard\b/.test(stripComments(read(f))))
    expect(offenders, "Usá StatTile (components/stat-tile.tsx)").toEqual([])
  })
})

describe("un solo BackLink (G4)", () => {
  const files = [...listFiles("app"), ...listFiles("components")]

  it("nadie define un BackLink/BackButton propio", () => {
    const offenders = files
      .filter((f) => f !== BACK_LINK_FILE)
      .filter((f) =>
        /(function|const|let)\s+(BackLink|BackButton)\b/.test(stripComments(read(f))),
      )
    expect(offenders, `El único es ${BACK_LINK_FILE}`).toEqual([])
  })

  it("nadie arma un 'volver' con <ArrowLeft a mano en el panel", () => {
    const scan = [...listFiles(PANEL_DIR), ...listFiles("components/domain"), ...listFiles("components/reports")]
    const offenders = scan
      .filter((f) => f !== BACK_LINK_FILE && !(f in ARROW_LEFT_ALLOWLIST))
      .filter((f) => hitsByLine(f, /<ArrowLeft\b/).length > 0)
    expect(offenders, `Usá <BackLink> de ${BACK_LINK_FILE}`).toEqual([])
  })

  it("la allowlist de <ArrowLeft no se pudre", () => {
    const stale = Object.keys(ARROW_LEFT_ALLOWLIST).filter(
      (f) => !existsSync(path.join(FRONTEND_ROOT, f)) || hitsByLine(f, /<ArrowLeft\b/).length === 0,
    )
    expect(stale).toEqual([])
  })
})

describe("sin mayúsculas a mano en títulos y labels (G5)", () => {
  const files = UPPERCASE_SCAN_DIRS.flatMap((d) => listFiles(d, [".tsx"]))

  function uppercaseHits(f: string): number[] {
    return stripComments(read(f))
      .split("\n")
      .flatMap((line, i) => (UPPERCASE_CLASS.test(line) && isClassContext(line) ? [i + 1] : []))
  }

  it("ningún className con `uppercase` fuera de la allowlist", () => {
    const offenders = files
      .filter((f) => !(f in UPPERCASE_ALLOWLIST))
      .flatMap((f) => uppercaseHits(f).map((l) => `${f}:${l}`))
    expect(offenders, "Títulos con CardTitle/FormSection/h2, labels con Label — context/84 T3/T4").toEqual([])
  })

  it("la allowlist de mayúsculas no se pudre", () => {
    const stale = Object.keys(UPPERCASE_ALLOWLIST).filter(
      (f) => !files.includes(f) || uppercaseHits(f).length === 0,
    )
    expect(stale).toEqual([])
  })
})
