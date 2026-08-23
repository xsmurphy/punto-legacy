/// <reference lib="WebWorker" />

import { defaultCache } from "@serwist/next/worker"
import type { PrecacheEntry, SerwistGlobalConfig } from "serwist"
import { Serwist, StaleWhileRevalidate } from "serwist"

declare global {
  interface WorkerGlobalScope extends SerwistGlobalConfig {
    __SW_MANIFEST: (PrecacheEntry | string)[] | undefined
  }
}

declare const self: ServiceWorkerGlobalScope

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
 */
const serwist = new Serwist({
  precacheEntries: self.__SW_MANIFEST,
  skipWaiting: true,
  clientsClaim: true,
  navigationPreload: true,
  runtimeCaching: [
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
