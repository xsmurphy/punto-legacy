import type { MetadataRoute } from "next"

import { SITE_URL } from "@/lib/site/links"

/**
 * El mismo container sirve el sitio (punto.la) y la aplicación
 * (app.punto.la): se indexa el marketing y se bloquea todo lo operativo.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      {
        userAgent: "*",
        allow: "/",
        disallow: [
          "/api/",
          "/pos",
          "/admin",
          "/settings",
          "/connect",
          "/login",
          "/signup",
          "/transactions",
          "/reports",
        ],
      },
    ],
    sitemap: `${SITE_URL}/sitemap.xml`,
  }
}
