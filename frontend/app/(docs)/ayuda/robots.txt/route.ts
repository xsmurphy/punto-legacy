import { DOCS_URL } from "@/lib/site/links"

/**
 * `robots.txt` del host de ayuda. `app/robots.ts` es único por app y
 * describe el sitio de marketing y el panel; en el host de ayuda el
 * middleware reescribe `/robots.txt` a esta ruta.
 */
export const dynamic = "force-static"

export function GET() {
  const body = ["User-agent: *", "Allow: /", "", `Sitemap: ${DOCS_URL}/sitemap.xml`, ""].join("\n")
  return new Response(body, { headers: { "Content-Type": "text/plain; charset=utf-8" } })
}
