import type { MetadataRoute } from "next"

import { getArticles } from "@/lib/docs/content"
import { DOCS_URL } from "@/lib/site/links"

/**
 * Sitemap del sitio de ayuda. Se genera en `/ayuda/sitemap.xml`; en el host
 * de ayuda el middleware sirve esa ruta como `/sitemap.xml`.
 */
export default function sitemap(): MetadataRoute.Sitemap {
  return [
    { url: `${DOCS_URL}/`, changeFrequency: "weekly", priority: 1 },
    ...getArticles().map((article) => ({
      url: `${DOCS_URL}/${article.slug}`,
      changeFrequency: "monthly" as const,
      priority: 0.8,
    })),
  ]
}
