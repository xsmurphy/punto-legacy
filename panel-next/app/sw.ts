/// <reference lib="WebWorker" />

import { defaultCache } from "@serwist/next/worker"
import type { PrecacheEntry, SerwistGlobalConfig } from "serwist"
import { NetworkFirst, Serwist, StaleWhileRevalidate } from "serwist"

declare global {
  interface WorkerGlobalScope extends SerwistGlobalConfig {
    __SW_MANIFEST: (PrecacheEntry | string)[] | undefined
  }
}

declare const self: ServiceWorkerGlobalScope

const serwist = new Serwist({
  precacheEntries: self.__SW_MANIFEST,
  skipWaiting: true,
  clientsClaim: true,
  navigationPreload: true,
  runtimeCaching: [
    {
      matcher: /^\/api\/pos\/bootstrap/,
      handler: new NetworkFirst({
        cacheName: "pos-bootstrap",
        networkTimeoutSeconds: 3,
        plugins: [{ cacheWillUpdate: async ({ response }) => (response.status < 400 ? response : null) }],
      }),
    },
    {
      matcher: /^\/api\/pos\/items/,
      handler: new StaleWhileRevalidate({
        cacheName: "pos-items",
      }),
    },
    ...defaultCache,
  ],
})

serwist.addEventListeners()
