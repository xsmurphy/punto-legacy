/// <reference lib="WebWorker" />

import { defaultCache } from "@serwist/next/worker"
import type { PrecacheEntry, SerwistGlobalConfig } from "serwist"
import { Serwist } from "serwist"

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
      handler: "NetworkFirst",
      options: {
        cacheName: "pos-bootstrap",
        networkTimeoutSeconds: 3,
        expiration: { maxAgeSeconds: 24 * 60 * 60 },
      },
    },
    {
      matcher: /^\/api\/pos\/items/,
      handler: "StaleWhileRevalidate",
      options: { cacheName: "pos-items" },
    },
    ...defaultCache,
  ],
})

serwist.addEventListeners()
