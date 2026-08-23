/// <reference lib="WebWorker" />

import { defaultCache } from "@serwist/next/worker"
import type { PrecacheEntry, SerwistGlobalConfig } from "serwist"
import {
  CacheableResponsePlugin,
  CacheFirst,
  ExpirationPlugin,
  NetworkFirst,
  Serwist,
  StaleWhileRevalidate,
} from "serwist"

declare global {
  interface WorkerGlobalScope extends SerwistGlobalConfig {
    __SW_MANIFEST: (PrecacheEntry | string)[] | undefined
  }
}

declare const self: ServiceWorkerGlobalScope

/** Home de la caja — la única ruta del POS que siempre tiene documento. */
const POS_HOME = "/pos"

const isPosPath = (pathname: string) =>
  pathname === POS_HOME || pathname.startsWith(`${POS_HOME}/`)

/**
 * Hosts de las fotos del catálogo — el bucket de DO Spaces (o S3) donde
 * `ItemImageService` sube cada imagen. Mismos hosts que `remotePatterns` en
 * `next.config.ts`: si se agrega uno allá, va acá también.
 */
const MEDIA_HOST = /(?:^|\.)(?:digitaloceanspaces\.com|amazonaws\.com)$/i
const IMAGE_EXT = /\.(?:jpe?g|png|webp|avif|gif)$/i

/**
 * Cuidado con los `matcher` de tipo RegExp: serwist los evalúa con
 * `regExp.exec(url.href)` — contra el href COMPLETO (`https://host/api/...`),
 * no contra el pathname. Un patrón anclado como `/^\/api\/pos\/items/` no
 * matchea nunca y la ruta queda muerta en silencio: sin error, sin cache, y
 * sin forma de notarlo hasta que se corta internet.
 *
 * Fue exactamente lo que pasó con las dos rutas `/api/pos/*` que vivían acá
 * (descubierto 2026-08-23): nunca cachearon nada. Por eso los matchers ahora
 * son callbacks sobre `url.pathname`, que dicen lo que parecen decir.
 *
 * `const serwist: Serwist` con el tipo escrito a mano, no por gusto: el plugin
 * `handlerDidError` de más abajo llama a `serwist.matchPrecache()`, así que la
 * constante se referencia dentro de su propio inicializador. Sin la anotación
 * TS entra en inferencia circular (TS7022) y el build se cae.
 */
const serwist: Serwist = new Serwist({
  precacheEntries: self.__SW_MANIFEST,
  skipWaiting: true,
  clientsClaim: true,
  // Navigation preload SE QUEDA prendido. Fue sospechoso de agravar la
  // pantalla de error offline, y no lo es: `StrategyHandler.fetch()` de
  // serwist consume `event.preloadResponse` cuando el request es de
  // navegación, así que en el panel es una ganancia real de latencia. Sin
  // red el preload rechaza y la estrategia cae al cache como corresponde.
  //
  // (Lo que sí es cierto: las navegaciones del POS ya salen del precache
  // cache-first, así que para ellas el preload se dispara y se descarta. Es
  // ruido en consola, no un fallo — y no justifica degradar el panel.)
  navigationPreload: true,
  runtimeCaching: [
    {
      /**
       * Navegaciones del POS (`/pos`, `/pos/ordenes`, …).
       *
       * El grueso de las navegaciones ni llega acá: los documentos del POS
       * están en el precache (ver `posShellRoutes()` en `next.config.ts`) y
       * `PrecacheRoute` se registra ANTES que cualquier `runtimeCaching`, así
       * que `/pos` sale de precache cache-first, instantáneo y con o sin red.
       *
       * Esta ruta cubre lo que el precache no matchea por URL exacta: las
       * navegaciones con query (`/pos?view=hotkeys`, `/pos?hotkeys=edit`) y
       * cualquier subruta futura que todavía no esté precacheada. Online se
       * comporta normal (red primero, con 3s de techo para no dejar al cajero
       * mirando una pantalla en blanco en una conexión moribunda); cuando la
       * red falla, cae al documento precacheado.
       */
      matcher: ({ request, url, sameOrigin }) =>
        sameOrigin && request.mode === "navigate" && isPosPath(url.pathname),
      handler: new NetworkFirst({
        cacheName: "pos-pages",
        networkTimeoutSeconds: 3,
        plugins: [
          // NetworkFirst cachea por URL completa, query incluida: sin techo,
          // cada variante de `?view=…` sería una entrada más. Son un puñado
          // de rutas, 16 sobra.
          new ExpirationPlugin({ maxEntries: 16 }),
          {
            handlerDidError: async ({ request }) => {
              const { pathname } = new URL(request.url)

              // Documento exacto de esa ruta: es el que hidrata bien.
              const exact = await serwist.matchPrecache(pathname)
              if (exact) return exact

              // Subruta sin documento propio. NO servimos el HTML de `/pos`
              // bajo otra URL: el App Router hidrataría el árbol de `/pos`
              // mientras `usePathname()` devuelve la otra ruta, y el layout
              // del POS pintaría el módulo equivocado. Mandamos al cajero a
              // la caja, que sí tiene documento — que es donde lo queremos.
              if (pathname !== POS_HOME) {
                return Response.redirect(
                  new URL(POS_HOME, self.location.origin).href,
                  302,
                )
              }

              // Device que nunca cargó la app (sin precache): no hay nada que
              // servir y la pantalla de error del navegador es la respuesta
              // correcta. Devolver `undefined` deja que el error se propague.
              return undefined
            },
          },
        ],
      }),
    },
    {
      /**
       * Fotos del catálogo (S3 / DO Spaces).
       *
       * CacheFirst y no SWR porque estas URLs son INMUTABLES: la clave es
       * `items/<companyId>/<itemId>/<imageId>.jpg` y cambiar la foto de un
       * ítem genera un `imageId` nuevo, o sea otra URL. Revalidar no puede
       * traer nada distinto — solo gastaría datos y latencia por cada tile de
       * la grilla, en cada arranque de la caja.
       *
       * Sin esta ruta las fotos igual caían en `static-image-assets` de
       * `defaultCache` (su matcher es `/\.(jpg|…)$/` contra el href completo,
       * así que sí matchea un host ajeno), pero con `maxEntries: 64`
       * compartidos con TODAS las imágenes de la app: un catálogo de más de
       * 64 fotos se desalojaba a sí mismo y sin conexión el tile quedaba con
       * el ícono roto. Al ir ANTES de `...defaultCache`, esta gana.
       *
       * `CacheableResponsePlugin([0, 200])` es obligatorio: los `<img>` del
       * catálogo son cross-origin sin CORS, así que la respuesta es OPACA
       * (status 0) y el `cacheWillUpdate` por defecto de CacheFirst la
       * descartaría en silencio. (SWR y NetworkFirst la aceptan solos; este
       * no.)
       *
       * Presupuesto: 300 fotos, 30 días. Las imágenes se suben normalizadas a
       * 1024px JPEG q85 (`ItemImageService`), ~100-200 KB cada una, así que el
       * techo real ronda los 30-60 MB para un catálogo grande — dentro de la
       * cuota típica de un origen, y `purgeOnQuotaError` deja que el
       * navegador la recupere entera antes que romper una escritura de la
       * cola de ventas, que sí es dato que no se puede perder.
       */
      matcher: ({ url, sameOrigin }) =>
        !sameOrigin && MEDIA_HOST.test(url.hostname) && IMAGE_EXT.test(url.pathname),
      handler: new CacheFirst({
        cacheName: "pos-catalog-images",
        plugins: [
          new CacheableResponsePlugin({ statuses: [0, 200] }),
          new ExpirationPlugin({
            maxEntries: 300,
            maxAgeSeconds: 30 * 24 * 60 * 60,
            maxAgeFrom: "last-used",
            purgeOnQuotaError: true,
          }),
        ],
      }),
    },
    {
      // Ficha de ítem del POS (`GET /api/pos/items?id=…`, product-info-dialog).
      // SWR: la ficha se abre muchas veces sobre los mismos ítems y no es dato
      // crítico de emisión.
      //
      // NO hay ruta equivalente para `/api/pos/bootstrap`. El snapshot que
      // permite arrancar la caja sin red vive en IndexedDB
      // (`lib/pos/bootstrap-cache.ts`), no en la Cache API, por dos razones:
      // el bootstrap trae la lista de clientes del comercio (PII) y la Cache
      // API no participa del `moduleLogout()` del device, así que un device
      // desvinculado se quedaría con esa copia; y el fallback tiene que ser
      // legible desde el código que hidrata el store, no un efecto lateral
      // opaco de la capa de red. Un solo dueño del bootstrap offline.
      matcher: ({ url, sameOrigin }) =>
        sameOrigin && url.pathname.startsWith("/api/pos/items"),
      handler: new StaleWhileRevalidate({
        cacheName: "pos-items",
      }),
    },
    ...defaultCache,
  ],
})

serwist.addEventListeners()
