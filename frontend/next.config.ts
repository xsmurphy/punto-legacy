import type { NextConfig } from "next"
import withSerwistInit from "@serwist/next"
import { withSentryConfig } from "@sentry/nextjs"

const withSerwist = withSerwistInit({
  swSrc: "app/sw.ts",
  swDest: "public/sw.js",
  cacheOnNavigation: true,
  reloadOnOnline: false,
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
