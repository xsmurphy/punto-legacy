"use client"

import * as React from "react"
import {
  HEARTBEAT_MS,
  hydrateTenancy,
  refreshTenancy,
} from "@/lib/pos/register-tenancy"
import { useTenancyStore } from "@/lib/pos/tenancy-store"

/**
 * Mantiene viva la tenencia de esta caja (context/29 §4,
 * `api/v1/register/claim.php`) y su copia local con vigencia
 * (`lib/pos/register-tenancy.ts`).
 *
 * Qué cambió y por qué (incidente 2026-08-23)
 * ───────────────────────────────────────────
 * Antes esto era un `useQuery` disparado UNA sola vez al entrar al workspace,
 * cuyo resultado nadie leía: el 409 quedaba en el cache de react-query y el
 * POS seguía como si nada. Con conexión el gate igual funcionaba, porque
 * `sales.php` devolvía su propio 409 al cobrar; SIN conexión no había gate
 * ninguno, así que el cajero vendía, imprimía, y el rechazo llegaba recién al
 * sincronizar — con el cliente ya afuera.
 *
 * Ahora el claim no es una query cacheada: es la fuente que ESCRIBE el grant
 * persistido que el device consulta para decidir si puede emitir, con o sin
 * red. Tres disparadores:
 *
 *   1. Al montar — primero hidrata el grant guardado (así un arranque offline
 *      tiene veredicto en el primer frame, sin esperar a ninguna request) y
 *      recién después intenta confirmarlo contra el servidor.
 *   2. Latido cada `HEARTBEAT_MS` — renueva `confirmedAt` para que el TTL de
 *      12 h solo empiece a correr cuando la red se cae de verdad. Sin red, el
 *      latido re-evalúa el grant guardado contra el reloj, que es lo que hace
 *      que la caja pase a `stale` sola al cruzar el TTL.
 *   3. Evento `online` — recuperar la conexión reconfirma en el acto, sin
 *      esperar hasta 5 minutos al próximo latido.
 *
 * La pérdida de tenencia estando ONLINE no depende de este latido:
 * `use-realtime-sync.ts` reacciona a la entity `register-lease` y llama
 * `refreshTenancy()` en el momento en que el admin libera la caja.
 *
 * ESTE HOOK NUNCA TOMA LA CAJA (2026-09-01)
 * ─────────────────────────────────────────
 * Los tres disparadores de arriba —y también el montaje— van con
 * `acquire: false`: preguntan, no adquieren. Hasta este cambio no existía la
 * distinción y cada latido tomaba la caja libre de paso, así que un POS
 * abierto se la volvía a llevar apenas otro dispositivo la soltaba: el cajero
 * del segundo aparato veía la caja liberada y seguía sin poder facturar,
 * perdiendo una carrera de 5 minutos contra un timer ajeno. La caja se toma
 * por un acto del cajero (el botón de `RegisterTakenPhase` en
 * `pay-dialog.tsx`), nunca por un efecto de montar una pantalla.
 *
 * La corrección de producto del owner (2026-08-20) sigue en pie: la tenencia
 * NO bloquea el workspace. Este hook no gatea ningún render — catálogo,
 * carrito, cotizaciones, órdenes y clientes funcionan igual sin tenencia. Lo
 * único que se bloquea es EMITIR un documento con numeración fiscal, y ese
 * gate vive en `PayDialog`.
 */
export function useRegisterClaim(registerId: string | null | undefined) {
  const setRefreshing = useTenancyStore((s) => s.setRefreshing)

  // Ref y no dependencia del efecto: `registerId` no cambia en la vida de un
  // workspace montado, y leerlo por ref evita reinstalar el intervalo si la
  // identidad del string cambiara por un refetch del bootstrap.
  const registerIdRef = React.useRef(registerId)
  registerIdRef.current = registerId

  const confirm = React.useCallback(
    async (opts?: { forceNetwork?: boolean }) => {
      const id = registerIdRef.current
      if (!id) return
      // Sin red no se pregunta: se re-evalúa lo guardado contra el reloj, que
      // es exactamente lo que puede cambiar el veredicto offline (cruzar el
      // TTL). Pedirlo igual solo generaría un fetch fallido por latido.
      const online = typeof navigator === "undefined" || navigator.onLine
      if (!online && !opts?.forceNetwork) {
        await hydrateTenancy(id)
        return
      }
      setRefreshing(true)
      try {
        // `acquire: false` explícito y no por default: es la propiedad que
        // define a este hook (ver el docblock), no un detalle que se pueda
        // perder si el default del helper cambia.
        await refreshTenancy(id, { acquire: false })
      } finally {
        setRefreshing(false)
      }
    },
    [setRefreshing],
  )

  React.useEffect(() => {
    if (!registerId) return
    let cancelled = false

    async function boot() {
      // Hidratar SIEMPRE primero: el veredicto del grant guardado tiene que
      // estar en el store antes de que el cajero pueda abrir el cobro, aunque
      // el claim de red tarde o no vuelva nunca.
      await hydrateTenancy(registerId as string)
      if (cancelled) return
      await confirm()
    }
    void boot()

    return () => {
      cancelled = true
    }
  }, [registerId, confirm])

  React.useEffect(() => {
    if (!registerId) return
    const interval = setInterval(() => {
      void confirm()
    }, HEARTBEAT_MS)
    return () => clearInterval(interval)
  }, [registerId, confirm])

  React.useEffect(() => {
    if (!registerId) return
    const handler = () => {
      void confirm({ forceNetwork: true })
    }
    window.addEventListener("online", handler)
    return () => window.removeEventListener("online", handler)
  }, [registerId, confirm])

  return { confirm }
}
