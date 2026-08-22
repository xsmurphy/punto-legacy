import type { MetadataRoute } from "next"

import { SITE_URL } from "@/lib/site/links"
import { modulosVisibles } from "@/lib/site/modulos"
import { RUBROS } from "@/lib/site/rubros"

/** Solo las páginas públicas del sitio de marketing. */
export default function sitemap(): MetadataRoute.Sitemap {
  const url = (path: string) => `${SITE_URL}${path}`

  return [
    { url: url("/"), changeFrequency: "monthly", priority: 1 },
    { url: url("/precios"), changeFrequency: "monthly", priority: 0.9 },
    { url: url("/contacto"), changeFrequency: "yearly", priority: 0.5 },
    ...modulosVisibles().map((m) => ({
      url: url(`/modulos/${m.slug}`),
      changeFrequency: "monthly" as const,
      priority: 0.8,
    })),
    ...RUBROS.map((r) => ({
      url: url(`/para/${r.slug}`),
      changeFrequency: "monthly" as const,
      priority: 0.7,
    })),
  ]
}
