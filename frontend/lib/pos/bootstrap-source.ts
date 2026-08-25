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
import { getDeviceToken } from "@/lib/auth/device-token"
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

  // Sin token del device NO se pide el bootstrap. Fail-closed, y no una
  // optimización para ahorrar un round-trip.
  //
  // `PosAuthGuard` monta esta query en TODO arranque de `/pos` —también cuando
  // no hay device pareado, porque el hook corre antes de su propio early
  // return— y `posFetch` iba entonces con `credentials: "include"`. En el
  // browser del operador eso significaba que una request SIN Bearer todavía
  // viajaba con la cookie del panel, y el BFF la aceptaba: el POS recibía un 200
  // con forma de panel y sin roster, lo cacheaba en `["pos-bootstrap"]`
  // (staleTime 5 min) y lo persistía como snapshot. Al parear el device, la
  // navegación client-side a `/pos` reusaba ese cache envenenado y el lock
  // screen abría sin PINs (incidente 2026-08-25).
  //
  // Hoy `posFetch` va con `credentials: "omit"` y el BFF no reenvía la cookie
  // (token-only, context/08 §60), así que ese 200 ya no existe. Este guard sigue
  // siendo necesario igual: es el que decide qué entra al cache y al snapshot, y
  // su `ApiError(401)` es lo que lleva a `PosAuthGuard` a la pantalla de
  // reconexión — por eso esta query NO se gatea con `enabled` (ver
  // `hooks/use-pos-bootstrap.ts`).
  //
  // El BFF ya rechaza ese caso con 401, pero el arreglo tiene que estar
  // TAMBIÉN de este lado: es acá donde se decide qué entra al cache y al
  // snapshot, y una respuesta que no corresponde a este device no debe llegar
  // a existir como "el bootstrap del POS". El 401 sintético es el mismo que
  // devolvería el BFF, así que `PosAuthGuard` lo trata igual (device no
  // conectado) sin ninguna rama nueva.
  if (getDeviceToken() === null) {
    throw new ApiError(
      401,
      null,
      "Este dispositivo no está conectado. Pedí un link de conexión desde el panel.",
    )
  }

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
