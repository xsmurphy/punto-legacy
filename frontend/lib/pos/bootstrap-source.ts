/**
 * De dónde sale el bootstrap del POS: red, o snapshot local.
 *
 * Es la política de degradación offline de la caja, aislada de React a
 * propósito — es la regla más importante del arranque y tiene que poder
 * leerse (y testearse) sin montar un componente. El binding con TanStack
 * Query vive en `hooks/use-pos-bootstrap.ts`.
 *
 * Regla base del producto: lo que se EMITE —factura, recibo, remisión,
 * comanda— funciona SIEMPRE sin internet. Para eso la caja necesita catálogo,
 * cajas, impuestos y plantillas, o sea el bootstrap; así que el bootstrap no
 * puede depender de que haya red.
 */

import { posApi } from "@/lib/api/pos-client"
import { ApiError } from "@/lib/api-client"
import { saveBootstrapSnapshot, loadBootstrapSnapshot } from "@/lib/pos/bootstrap-cache"
import { useOfflineSyncStore } from "@/lib/pos/offline-sync-store"
import type { PosBootstrap } from "@/lib/types/pos-bootstrap"

/**
 * ¿Este fallo habilita caer al snapshot?
 *
 * Sí: fallo de red (`fetch` tira `TypeError`, no `ApiError`) y 5xx — incluido
 * el 502 con el que el BFF reporta que sus upstreams se cayeron. En los dos
 * casos el server no pudo opinar sobre esta sesión.
 *
 * No: 401 (sesión revocada o incompleta) y el resto de los 4xx. Un 403 es el
 * server diciendo "vos no" con conocimiento de causa; degradar ahí dejaría
 * operando con datos viejos a un device que el comercio rechazó.
 */
export function shouldFallBackToCache(err: unknown): boolean {
  if (err instanceof ApiError) return err.status >= 500
  return true
}

/**
 * Devuelve el bootstrap del POS y, como efecto, deja registrado de dónde vino
 * (`catalogFromCache` en `offline-sync-store`) para que la UI pueda avisar que
 * está operando con datos locales.
 *
 * Lanza SOLO cuando no hay red y este device nunca completó un bootstrap: ahí
 * no hay catálogo, ni cajas, ni correlativo, y no hay nada que dejar operar.
 * Es el único caso en que `PosAuthGuard` bloquea la caja.
 */
export async function fetchPosBootstrap(): Promise<PosBootstrap> {
  const { setCatalogSource } = useOfflineSyncStore.getState()
  try {
    const data = await posApi.get<PosBootstrap>("/pos/bootstrap")
    setCatalogSource(false, null)
    // Best-effort y sin await: el arranque online no espera a la escritura del
    // snapshot, que solo sirve para el arranque SIGUIENTE.
    void saveBootstrapSnapshot(data)
    return data
  } catch (err) {
    if (!shouldFallBackToCache(err)) throw err
    const cached = await loadBootstrapSnapshot()
    if (!cached) throw err
    setCatalogSource(true, cached.savedAt)
    return cached.bootstrap
  }
}
