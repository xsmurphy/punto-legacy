import crypto from "node:crypto"
import fs from "node:fs"
import path from "node:path"
import type { NextConfig } from "next"
import withSerwistInit from "@serwist/next"
import { withSentryConfig } from "@sentry/nextjs"

/**
 * Documentos del POS que van al PRECACHE del service worker.
 *
 * El manifest que arma `@serwist/next` sale de globear `public/` y
 * `.next/static` — o sea: assets, cero HTML. Sin esto, recargar `/pos` sin
 * red no tiene NADA que servir y el navegador pinta su pantalla de error
 * ("You're offline"). El único documento que podía salvarla era el que la
 * estrategia genérica `others` de `defaultCache` hubiera guardado en alguna
 * navegación anterior — una lotería por dispositivo, y encima purgable por
 * la recuperación de ChunkLoadError.
 *
 * La lista se DERIVA del árbol de rutas, no se escribe a mano: agregar
 * `app/(pos)/pos/<algo>/page.tsx` la incluye sola. Si alguna vez hay una
 * ruta dinámica (`[id]`) bajo `/pos` se saltea — no hay un documento único
 * que precachear, y precachear el de un id concreto sería servirle a un
 * cajero el estado de otro.
 *
 * Precondición: todas estas rutas son `○ (Static)` en el build (árbol 100%
 * client component, sin `cookies()`/`headers()`), así que el HTML es el
 * mismo para todos los comercios y no lleva PII.
 */
function posShellRoutes(): string[] {
  const root = path.join(__dirname, "app", "(pos)", "pos")
  const routes: string[] = []

  const walk = (dir: string, route: string) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (entry.isDirectory()) {
        // `[id]` dinámica, `_private`, `@slot`, `(group)` → no son un
        // documento precacheable con URL fija.
        if (/^[[_@(]/.test(entry.name)) continue
        walk(path.join(dir, entry.name), `${route}/${entry.name}`)
      } else if (/^page\.(tsx|ts|jsx|js)$/.test(entry.name)) {
        routes.push(route)
      }
    }
  }

  walk(root, "/pos")
  return routes.sort()
}

/**
 * Revisión del shell precacheado. Tiene que cambiar en cada build: si no, un
 * deploy nuevo deja el HTML viejo en el precache apuntando a chunks con hash
 * que ya no existen (ChunkLoadError en loop). El SHA del commit si el
 * builder lo expone; si no, la marca de tiempo del build.
 */
const SHELL_REVISION =
  process.env.SOURCE_COMMIT?.slice(0, 16) ??
  process.env.GIT_COMMIT_SHA?.slice(0, 16) ??
  Date.now().toString(36)

/**
 * TRAMPA de `@serwist/next`: `additionalPrecacheEntries` no se SUMA al glob de
 * `public/` — lo REEMPLAZA. El plugin hace, literal:
 *
 *   let resolved = additionalPrecacheEntries
 *   if (!resolved) resolved = globSync(globPublicPatterns, { cwd: publicDir })
 *
 * O sea que pasar una sola entrada propia deja fuera del precache TODO
 * `public/`: iconos de la PWA, logos, imágenes del sitio. Falla en silencio —
 * el build sale verde y el agujero recién se ve sin internet.
 *
 * Por eso replicamos acá el glob que el plugin haría (mismos `ignore`, misma
 * revisión = hash md5 del archivo) y le concatenamos los documentos del POS.
 */
function publicPrecacheEntries(): { url: string; revision: string }[] {
  const publicDir = path.join(__dirname, "public")
  const entries: { url: string; revision: string }[] = []

  const walk = (dir: string) => {
    for (const entry of fs.readdirSync(dir)) {
      // `glob` no matchea dotfiles sin `dot: true`, y el plugin no lo pasa:
      // sin esto precachearíamos cosas como `public/.gitkeep`.
      if (entry.startsWith(".")) continue
      const abs = path.join(dir, entry)
      // `statSync` y no `withFileTypes`: el glob del plugin usa `follow: true`,
      // así que un symlink a directorio se recorre igual.
      if (fs.statSync(abs).isDirectory()) {
        walk(abs)
        continue
      }
      const rel = path.relative(publicDir, abs).split(path.sep).join("/")
      // Mismos `ignore` que el plugin: el propio SW, su sourcemap y el worker
      // auxiliar de `cacheOnNavigation`.
      if (rel === "sw.js" || rel === "sw.js.map") continue
      if (/^swe-worker-.*\.js$/.test(rel)) continue
      entries.push({
        url: `/${rel}`,
        revision: crypto.createHash("md5").update(fs.readFileSync(abs)).digest("hex"),
      })
    }
  }

  walk(publicDir)
  return entries
}

const withSerwist = withSerwistInit({
  swSrc: "app/sw.ts",
  swDest: "public/sw.js",
  // `cacheOnNavigation` parchea `history.pushState` para bajar cada página
  // navegada a la cache `pages`... que NADIE lee: la ruta de `defaultCache`
  // que la leería matchea con `request.headers.get("Content-Type")`, header
  // que una navegación GET no manda nunca (el request manda `Accept`; el
  // `Content-Type` es de la respuesta). Era un fetch completo del documento
  // por cada cambio de módulo del POS, escrito en una cache muerta.
  // El dueño del shell offline ahora es el precache, uno solo.
  cacheOnNavigation: false,
  reloadOnOnline: false,
  additionalPrecacheEntries: [
    ...publicPrecacheEntries(),
    ...posShellRoutes().map((url) => ({ url, revision: SHELL_REVISION })),
  ],
})

const nextConfig: NextConfig = {
  output: "standalone",
  turbopack: {
    root: __dirname,
  },
  // Whitelist de hosts para next/image. DO Spaces (S3-compatible) + AWS S3 genéricos.
  // En las imágenes de items usamos `unoptimized` igual — el backend ya las redimensiona —
  // pero el remotePatterns es requerido por Next aunque se opte por unoptimized.
  images: {
    remotePatterns: [
      { protocol: "https", hostname: "**.digitaloceanspaces.com" },
      { protocol: "https", hostname: "**.amazonaws.com" },
    ],
  },
  // Rename mesas→espacios (context/15-espacios-module-plan.md): rutas viejas
  // redirigen a las nuevas para no romper bookmarks/links existentes.
  async redirects() {
    return [
      { source: "/pos/mesas", destination: "/pos/espacios", permanent: true },
      {
        source: "/settings/tables",
        destination: "/settings/espacios",
        permanent: true,
      },
    ]
  },
  // `/favicon.ico` servía el HTML del panel con 404: el proyecto usa
  // `app/icon.png` y no existe ningún `.ico`. Cualquier cliente que pida esa
  // ruta por convención —y hay varios que no parsean HTML— se quedaba sin
  // ícono, y encima descargando 10 KB de markup.
  //
  // Se REESCRIBE al PNG en vez de generar un `.ico`: los clientes miran el
  // `Content-Type`, no la extensión, y así no entra al repo un binario que
  // nadie puede revisar en un diff. (Enfoque de la sesión de Fish, que tenía
  // el mismo síntoma.)
  //
  // Rewrite y no redirect: un 301 obliga a un segundo request y algunos
  // clientes de íconos no lo siguen.
  async rewrites() {
    return [{ source: "/favicon.ico", destination: "/icon.png" }]
  },
}

// withSentryConfig: solo sube sourcemaps si hay SENTRY_AUTH_TOKEN (CI con auth).
// Sin token, silent + sin upload → el build NO se rompe si no hay credenciales/DSN.
// La instrumentación de runtime (instrumentation*.ts) ya está gateada por DSN aparte.
const hasSentryAuth = Boolean(process.env.SENTRY_AUTH_TOKEN)

export default withSentryConfig(withSerwist(nextConfig), {
  silent: !process.env.CI,
  org: process.env.SENTRY_ORG,
  project: process.env.SENTRY_PROJECT,
  authToken: process.env.SENTRY_AUTH_TOKEN,
  sourcemaps: {
    disable: !hasSentryAuth,
  },
  // Sin auth token no se suben sourcemaps; el build queda igual de liviano.
  disableLogger: true,
})
