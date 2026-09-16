/**
 * Lógica pura del sitio de ayuda sobre el texto de los artículos: frontmatter,
 * headings con id (para la tabla de contenidos) y resolución de links.
 *
 * Sin `fs` a propósito: lo usan el loader de build (`lib/docs/content.ts`), el
 * render del artículo y el test de integridad.
 */

// ── Frontmatter ──────────────────────────────────────────────────────────

export const REQUIRED_FRONTMATTER = [
  "title",
  "slug",
  "modulo",
  "audiencia",
  "keywords",
  "resumen",
] as const

export type FrontmatterValue = string | string[]

export interface ParsedMarkdown {
  data: Record<string, FrontmatterValue>
  body: string
}

/**
 * Frontmatter de los artículos: `clave: valor` y listas en línea
 * `clave: [a, b, c]`. Es el único formato que usa la carpeta (ver su
 * README), así que no se suma un parser YAML completo como dependencia: una
 * línea con otra forma es un error del artículo y el test de integridad la
 * rechaza (queda fuera de `data`).
 */
export function parseFrontmatter(source: string): ParsedMarkdown {
  const match = /^---\r?\n([\s\S]*?)\r?\n---\r?\n?/.exec(source)
  if (!match) return { data: {}, body: source }

  const data: Record<string, FrontmatterValue> = {}
  for (const line of match[1].split(/\r?\n/)) {
    const kv = /^([A-Za-z_][\w-]*):\s*(.*)$/.exec(line)
    if (!kv) continue
    const [, key, raw] = kv
    const value = raw.replace(/\s+#.*$/, "").trim()
    if (value.startsWith("[") && value.endsWith("]")) {
      data[key] = value
        .slice(1, -1)
        .split(",")
        .map((v) => unquote(v.trim()))
        .filter(Boolean)
    } else {
      data[key] = unquote(value)
    }
  }
  return { data, body: source.slice(match[0].length) }
}

function unquote(value: string): string {
  return /^(["']).*\1$/.test(value) ? value.slice(1, -1) : value
}

// ── Headings ─────────────────────────────────────────────────────────────

export interface DocHeading {
  depth: 2 | 3
  text: string
  id: string
}

/** Id de ancla estable y legible: sin acentos, minúsculas, guiones. */
export function slugifyHeading(text: string): string {
  return text
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9ñ]+/g, "-")
    .replace(/^-+|-+$/g, "")
}

/** Asigna ids únicos en orden de aparición (`pasos`, `pasos-2`, …). */
function createIdAllocator() {
  const seen = new Map<string, number>()
  return (text: string) => {
    const base = slugifyHeading(text) || "seccion"
    const count = (seen.get(base) ?? 0) + 1
    seen.set(base, count)
    return count === 1 ? base : `${base}-${count}`
  }
}

/** Texto plano de un heading escrito en Markdown (sin negrita, código ni links). */
function plainHeadingText(raw: string): string {
  return raw
    .replace(/\[([^\]]*)\]\([^)]*\)/g, "$1")
    .replace(/[*_`]/g, "")
    .trim()
}

/**
 * H2 y H3 del artículo, para la tabla de contenidos. Los ids tienen que ser
 * los mismos que pone `remarkHeadingIds` en el HTML — lo cuida el test.
 */
export function extractHeadings(body: string): DocHeading[] {
  const allocate = createIdAllocator()
  const headings: DocHeading[] = []
  let inFence = false
  for (const line of body.split(/\r?\n/)) {
    if (/^\s*(```|~~~)/.test(line)) {
      inFence = !inFence
      continue
    }
    if (inFence) continue
    const m = /^(#{2,3})\s+(.+?)\s*#*\s*$/.exec(line)
    if (!m) continue
    const text = plainHeadingText(m[2])
    headings.push({ depth: m[1].length as 2 | 3, text, id: allocate(text) })
  }
  return headings
}

interface MdNode {
  type: string
  depth?: number
  value?: string
  children?: MdNode[]
  data?: { hProperties?: Record<string, unknown> }
}

function nodeText(node: MdNode): string {
  if (typeof node.value === "string") return node.value
  return (node.children ?? []).map(nodeText).join("")
}

/** Plugin de remark: pone `id` a los H2/H3 con el mismo algoritmo que `extractHeadings`. */
export function remarkHeadingIds() {
  return (tree: MdNode) => {
    const allocate = createIdAllocator()
    const walk = (node: MdNode) => {
      if (node.type === "heading" && (node.depth === 2 || node.depth === 3)) {
        const id = allocate(nodeText(node).trim())
        node.data = { ...node.data, hProperties: { ...node.data?.hProperties, id } }
        return
      }
      node.children?.forEach(walk)
    }
    walk(tree)
  }
}

// ── Links ────────────────────────────────────────────────────────────────

/** Esquema de los links a secciones del panel: `[Ventas › Transacciones](panel:/reports/sales)`. */
export const PANEL_SCHEME = "panel:"

/** Link a otro artículo por nombre de archivo: `30-02-precios.md` o `30-02-precios.md#seccion`. */
const ARTICLE_LINK = /^([\w-]+\.md)(#.*)?$/

/** Todos los destinos de links `[texto](destino)` del cuerpo. */
export function extractLinkTargets(body: string): string[] {
  return Array.from(body.matchAll(/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g), (m) => m[1])
}

export function isPanelLink(href: string): boolean {
  return href.startsWith(PANEL_SCHEME)
}

/** Ruta del panel de un link `panel:` (con query, si la tiene). */
export function panelLinkPath(href: string): string {
  return href.slice(PANEL_SCHEME.length)
}

/** Archivo y ancla de un link a otro artículo, o `null` si no lo es. */
export function articleLinkTarget(href: string): { file: string; hash: string } | null {
  const m = ARTICLE_LINK.exec(href)
  return m ? { file: m[1], hash: m[2] ?? "" } : null
}

export interface LinkContext {
  /** Origen del panel, sin barra final. */
  appUrl: string
  /** Nombre de archivo → slug del artículo. */
  slugByFile: ReadonlyMap<string, string>
}

/**
 * Destino real de un link del artículo, o `null` cuando no hay que tocarlo.
 * Un link a un archivo que no existe devuelve `null` (se renderiza como texto
 * plano): el test de integridad es el que lo hace fallar en CI.
 */
export function resolveDocHref(href: string, ctx: LinkContext): string | null {
  if (isPanelLink(href)) return `${ctx.appUrl}${panelLinkPath(href)}`
  const article = articleLinkTarget(href)
  if (article) {
    const slug = ctx.slugByFile.get(article.file)
    return slug ? `/${slug}${article.hash}` : null
  }
  return href
}
