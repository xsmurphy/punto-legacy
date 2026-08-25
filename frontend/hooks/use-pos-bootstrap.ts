"use client"

/**
 * Hook del bootstrap del POS — y la ÚNICA puerta por la que el catálogo entra
 * al device, venga de la red o del snapshot offline.
 *
 * Consulta el BFF `/api/pos/bootstrap` (NOT la /api PHP directa). El BFF
 * compone items + clientes + config + cajas en una sola respuesta.
 *
 * Auth: Bearer del device (realm `pos-app`), vía `posApi`
 * (`lib/api/pos-client.ts`) — este hook es exclusivo del POS (nunca lo
 * consume el panel), a diferencia de `useBootstrap` que es multi-realm.
 *
 * La política de "red o snapshot" vive en `lib/pos/bootstrap-source.ts`; acá
 * queda solo el binding con TanStack Query. Desde 2026-08-23 esta es también
 * la query que usa `PosAuthGuard`: antes el guard tenía la suya
 * (`["pos-bootstrap-auth"]`, con un `fetch` crudo) en paralelo a esta, o sea
 * dos requests al endpoint más caro del POS en cada arranque y dos caminos
 * distintos hacia el mismo dato, de los cuales solo uno podía aprender a
 * degradar sin red.
 */

import { useQuery } from "@tanstack/react-query"
import { getDeviceToken } from "@/lib/auth/device-token"
import { fetchPosBootstrap } from "@/lib/pos/bootstrap-source"
import type { PosBootstrap } from "@/lib/types/pos-bootstrap"

export function usePosBootstrap() {
  return useQuery<PosBootstrap>({
    queryKey: ["pos-bootstrap"],
    queryFn: fetchPosBootstrap,
    // `networkMode: "always"` es OBLIGATORIO acá, no una preferencia. Con el
    // default ("online") TanStack Query ni siquiera ejecuta el `queryFn`
    // cuando `navigator.onLine` es false: deja la query en `pending` para
    // siempre. O sea que el fallback a IndexedDB nunca correría justo en el
    // único escenario para el que existe —la caja arrancando sin red— y el
    // POS quedaría colgado en su loading screen.
    networkMode: "always",
    // NO agregar `enabled: getDeviceToken() !== null` acá. Parece la defensa en
    // profundidad obvia —sin token no pidas nada— y rompe la pantalla de
    // reconexión:
    //
    // cuando el admin revoca el device con la caja abierta, `posFetch` cobra el
    // 401 `session_revoked` y llama a `moduleLogout()`, que borra el token,
    // limpia el cache e invalida `["pos-bootstrap"]`. Con el gate puesto, en ese
    // render `enabled` ya es false: la query queda `pending`/`idle` y el
    // invalidate no refetchea nunca. `PosAuthGuard` calcula `hasLocalToken` UNA
    // sola vez (efecto con deps `[]`), así que sigue en `true` y cae en
    // `status === "pending"` → renderiza el POS con catálogo y carrito vacíos, y
    // cada acción fallando, en vez de "Dispositivo no conectado". Solo se
    // recupera con un reload completo.
    //
    // Sin el gate, ese camino es el bueno: refetch → `fetchPosBootstrap` tira
    // `ApiError(401)` sin tocar la red (su propio guard de token) → la query va
    // a `error` → el guard pinta `<DeviceNotConnected>` con el motivo correcto.
    //
    // El fail-closed real no está acá: está en `fetchPosBootstrap` (qué entra al
    // cache y al snapshot) y en `posFetch` (qué viaja a la red). Esta query tiene
    // que poder FALLAR — su estado de error es lo que maneja la UI.
    staleTime: 5 * 60 * 1000,
    // Sin reintentos: `fetchPosBootstrap` ya resuelve el fallo sirviendo el
    // snapshot, y un 401 no se reintenta nunca.
    retry: false,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    // Poll de sesión, heredado de la query de auth que este hook absorbió:
    // detecta la revocación del device sin esperar a la próxima acción del
    // cajero, y es también lo que hace volver el catálogo fresco cuando la
    // red reaparece. Solo mientras haya token — tras `moduleLogout()` el token
    // se borra y esto vuelve `false` para no generar 401s en loop.
    refetchInterval: () => (getDeviceToken() !== null ? 60_000 : false),
  })
}
