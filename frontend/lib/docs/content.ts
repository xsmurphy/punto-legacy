import { readdirSync, readFileSync } from "node:fs"
import path from "node:path"

import {
  extractHeadings,
  parseFrontmatter,
  type DocHeading,
} from "@/lib/docs/markdown"

/**
 * Carga de los artículos de ayuda desde `content/ayuda/*.md`.
 *
 * Corre solo en build (las páginas del sitio son estáticas): la carpeta es la
 * fuente única que comparten el sitio y el asistente de atención, así que el
 * sitio no guarda copia de nada — lee los mismos archivos.
 */

export const AYUDA_DIR = path.join(process.cwd(), "content", "ayuda")

/** Archivo de artículo: `NN-MM-slug.md`. El `NN` es el bloque. */
const ARTICLE_FILE = /^(\d{2})-(\d{2})-[\w-]+\.md$/

export interface DocArticle {
  file: string
  slug: string
  title: string
  modulo: string
  audiencia: string
  keywords: string[]
  resumen: string
  /** Prefijo del bloque (`"20"`). */
  blockKey: string
  body: string
  headings: DocHeading[]
}

export interface DocBlock {
  key: string
  title: string
  /** Una línea que dice de qué trata la sección. Ver `BLOCK_DESCRIPTIONS`. */
  description: string
  articles: DocArticle[]
}

/**
 * Descripción de cada bloque, para el índice del sitio.
 *
 * El título sale del README de la carpeta y el orden de los nombres de archivo:
 * la estructura del sitio siempre se deriva del contenido. Acá está lo único
 * que los archivos no traen — una línea por sección. Un bloque nuevo sin
 * entrada rompe el test de integridad, así que esto no se desincroniza.
 */
export const BLOCK_DESCRIPTIONS: Record<string, string> = {
  "10": "Qué es Punto, cómo entrar por primera vez y quién puede hacer cada cosa.",
  "20": "Cobrar, abrir y cerrar la caja, tomar pedidos y seguir vendiendo sin internet.",
  "30": "Cargar lo que vendés, ponerle precio y llevar el control de las existencias.",
  "40": "Registrar lo que le comprás a tus proveedores.",
  "50": "Tus clientes y proveedores, y el seguimiento de lo que te deben.",
  "60": "Qué mirar en cada reporte para entender cómo viene el negocio.",
  "70": "Emitir comprobantes electrónicos y entregárselos a tu cliente.",
  "80": "Sucursales, cajas, impresoras y cómo salen tus impresiones.",
}

const asString = (v: unknown) => (typeof v === "string" ? v : "")
const asList = (v: unknown) => (Array.isArray(v) ? v.map(String) : [])

let cache: { articles: DocArticle[]; blocks: DocBlock[] } | null = null

/** Nombres de los bloques, tomados de la tabla del README de la carpeta. */
export function readBlockTitles(dir = AYUDA_DIR): Map<string, string> {
  const readme = readFileSync(path.join(dir, "README.md"), "utf8")
  const titles = new Map<string, string>()
  for (const m of readme.matchAll(/^\|\s*`(\d{2})-x`\s*\|\s*([^|]+?)\s*\|\s*$/gm)) {
    titles.set(m[1], m[2])
  }
  return titles
}

export function listArticleFiles(dir = AYUDA_DIR): string[] {
  return readdirSync(dir)
    .filter((f) => ARTICLE_FILE.test(f))
    .sort()
}

function loadAll() {
  if (cache) return cache

  const titles = readBlockTitles()
  const articles = listArticleFiles().map((file): DocArticle => {
    const { data, body } = parseFrontmatter(readFileSync(path.join(AYUDA_DIR, file), "utf8"))
    return {
      file,
      slug: asString(data.slug),
      title: asString(data.title),
      modulo: asString(data.modulo),
      audiencia: asString(data.audiencia),
      keywords: asList(data.keywords),
      resumen: asString(data.resumen),
      blockKey: file.slice(0, 2),
      body,
      headings: extractHeadings(body),
    }
  })

  const blocks: DocBlock[] = []
  for (const article of articles) {
    let block = blocks.find((b) => b.key === article.blockKey)
    if (!block) {
      block = {
        key: article.blockKey,
        title: titles.get(article.blockKey) ?? "",
        description: BLOCK_DESCRIPTIONS[article.blockKey] ?? "",
        articles: [],
      }
      blocks.push(block)
    }
    block.articles.push(article)
  }

  cache = { articles, blocks }
  return cache
}

/** Artículos en el orden de lectura (el del nombre de archivo). */
export function getArticles(): DocArticle[] {
  return loadAll().articles
}

export function getBlocks(): DocBlock[] {
  return loadAll().blocks
}

export function getArticle(slug: string): DocArticle | undefined {
  return getArticles().find((a) => a.slug === slug)
}

export function getNeighbors(slug: string): { prev?: DocArticle; next?: DocArticle } {
  const articles = getArticles()
  const i = articles.findIndex((a) => a.slug === slug)
  if (i === -1) return {}
  return { prev: articles[i - 1], next: articles[i + 1] }
}

export function getSlugByFile(): Map<string, string> {
  return new Map(getArticles().map((a) => [a.file, a.slug]))
}

// ── Índice de búsqueda ───────────────────────────────────────────────────

/** Lo que baja al cliente para el buscador: sin el cuerpo del artículo. */
export interface DocSearchEntry {
  slug: string
  title: string
  block: string
  resumen: string
  /**
   * Keywords del frontmatter + resumen + H2. `paletteScore` exige que cada
   * palabra buscada aparezca en el título o acá, así que sumar el resumen y
   * los subtítulos amplía lo que se encuentra sin aflojar ese criterio.
   */
  keywords: string[]
}

export function buildSearchIndex(): DocSearchEntry[] {
  const { blocks } = loadAll()
  return blocks.flatMap((block) =>
    block.articles.map((a) => ({
      slug: a.slug,
      title: a.title,
      block: block.title,
      resumen: a.resumen,
      keywords: [
        ...a.keywords,
        a.resumen,
        ...a.headings.filter((h) => h.depth === 2).map((h) => h.text),
      ],
    })),
  )
}
