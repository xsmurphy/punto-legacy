import { existsSync, readFileSync } from "node:fs"
import path from "node:path"
import { createElement } from "react"
import { renderToStaticMarkup } from "react-dom/server"
import Markdown from "react-markdown"
import remarkGfm from "remark-gfm"
import { describe, expect, it } from "vitest"

import {
  AYUDA_DIR,
  getArticles,
  getBlocks,
  listArticleFiles,
  readBlockTitles,
} from "@/lib/docs/content"
import {
  REQUIRED_FRONTMATTER,
  articleLinkTarget,
  extractLinkTargets,
  isPanelLink,
  panelLinkPath,
  parseFrontmatter,
  remarkHeadingIds,
} from "@/lib/docs/markdown"
import { routePathname } from "@/lib/navigation/build"
import { PANEL_ROUTES, POS_ROUTES } from "@/lib/navigation/routes"

/**
 * Integridad de `content/ayuda/` — la base del sitio de ayuda y del asistente.
 *
 * Lo que este test existe para impedir: que un artículo mande al comerciante a
 * una sección del panel que ya no está ahí. Los links `panel:` se validan
 * contra el MISMO registro de rutas que arma el sidebar y el buscador del
 * panel, así que mover o renombrar una sección rompe acá hasta que se
 * actualice la ayuda.
 */

const ALL_ROUTES = [...PANEL_ROUTES, ...POS_ROUTES]
const REGISTERED_PATHNAMES = new Set(ALL_ROUTES.map((r) => routePathname(r.to)))
const REGISTERED_HREFS = new Set(ALL_ROUTES.map((r) => r.to))

const files = listArticleFiles()
const articles = getArticles()

describe("content/ayuda", () => {
  it("encuentra los artículos", () => {
    expect(files.length).toBeGreaterThan(0)
  })

  it.each(files)("%s tiene el frontmatter completo", (file) => {
    const { data } = parseFrontmatter(readFileSync(path.join(AYUDA_DIR, file), "utf8"))
    for (const key of REQUIRED_FRONTMATTER) {
      const value = data[key]
      if (key === "keywords") {
        expect(Array.isArray(value) && value.length > 0, `${file}: keywords`).toBe(true)
      } else {
        expect(typeof value === "string" && value.length > 0, `${file}: ${key}`).toBe(true)
      }
    }
  })

  it("los slugs son únicos", () => {
    const slugs = articles.map((a) => a.slug)
    expect(new Set(slugs).size).toBe(slugs.length)
  })

  it("cada artículo pertenece a un bloque con nombre en el README", () => {
    const titles = readBlockTitles()
    for (const a of articles) {
      expect(titles.get(a.blockKey), `${a.file}: bloque ${a.blockKey}`).toBeTruthy()
    }
  })

  it("cada bloque tiene su descripción para el índice del sitio", () => {
    for (const block of getBlocks()) {
      expect(block.description, `bloque ${block.key}: falta en BLOCK_DESCRIPTIONS`).toBeTruthy()
    }
  })

  it.each(files)("%s: los links panel: apuntan a secciones del registro de rutas", (file) => {
    const { body } = parseFrontmatter(readFileSync(path.join(AYUDA_DIR, file), "utf8"))
    for (const href of extractLinkTargets(body).filter(isPanelLink)) {
      const target = panelLinkPath(href)
      expect(REGISTERED_PATHNAMES.has(routePathname(target)), `${file}: ${href}`).toBe(true)
      // Con query (pestaña, tipo de contacto) se exige la entrada exacta: una
      // pestaña renombrada deja el pathname vivo pero el link en el vacío.
      if (target !== routePathname(target)) {
        expect(REGISTERED_HREFS.has(target), `${file}: ${href}`).toBe(true)
      }
    }
  })

  it.each(files)("%s: los links a otros artículos apuntan a archivos existentes", (file) => {
    const { body } = parseFrontmatter(readFileSync(path.join(AYUDA_DIR, file), "utf8"))
    for (const href of extractLinkTargets(body)) {
      const article = articleLinkTarget(href)
      if (!article) continue
      expect(existsSync(path.join(AYUDA_DIR, article.file)), `${file}: ${href}`).toBe(true)
    }
  })

  it.each(articles.map((a) => [a.file, a] as const))(
    "%s: la tabla de contenidos apunta a ids reales del artículo",
    (_file, article) => {
      const html = renderToStaticMarkup(
        createElement(Markdown, {
          remarkPlugins: [remarkGfm, remarkHeadingIds],
          children: article.body,
        }),
      )
      const rendered = Array.from(html.matchAll(/<h[23] id="([^"]+)"/g), (m) => m[1])
      expect(rendered).toEqual(article.headings.map((h) => h.id))
    },
  )
})
