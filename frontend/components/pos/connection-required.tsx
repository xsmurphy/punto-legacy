"use client"

/**
 * Frontera offline EXPLÍCITA del POS.
 *
 * La regla del producto no es "todo funciona sin internet", es más precisa:
 *
 *   - Lo que se EMITE (factura, recibo, remisión, comanda) funciona SIEMPRE
 *     sin conexión. Venta directa, impresión y encolado no consultan red en
 *     ningún punto del camino.
 *   - Lo que necesita ESTADO COMPARTIDO entre cajas (mapa de espacios, órdenes
 *     ajenas, cobro de una orden que abrió otro dispositivo) sí puede exigir
 *     red: dos cajas decidiendo offline sobre el mismo espacio producen un
 *     conflicto que no se puede reconciliar después.
 *
 * Lo que faltaba era decirlo. Sin esto, un módulo de estado compartido offline
 * se quedaba con `data === undefined` y caía en su empty state normal: "No hay
 * espacios abiertos" cuando la verdad es "no puedo saber si hay espacios
 * abiertos". Mentirle al cajero sobre el estado de un espacio es peor que no
 * mostrarle nada.
 *
 * Detección: con `networkMode: "online"` (el default de TanStack Query) una
 * query sin red no falla — queda `fetchStatus: "paused"`, en `pending` para
 * siempre. Por eso `isPaused` es la señal principal y no `isError`; un error
 * de red real (el server contesta mal, o `navigator.onLine` mintió) se cubre
 * con `isError` sin `data`.
 *
 * Deliberadamente NO cubre el caso "ya tengo datos y se cortó la red": si el
 * módulo alcanzó a cargar, sigue mostrando lo que tiene y el pill global
 * avisa que la conexión se cayó. Bloquear ahí sería tapar información que ya
 * está en pantalla.
 *
 * Este aviso es LOCAL al módulo, nunca global: la caja alrededor sigue
 * operativa. La pantalla que tapaba el POS entero ante cualquier fallo de red
 * se eliminó (ver `components/layout/pos-auth-guard.tsx`).
 */

import { CloudOff } from "lucide-react"
import { EmptyState } from "@/components/empty-state"
import { Button } from "@/components/ui/button"

/** Lo mínimo que este helper necesita de un `UseQueryResult`. */
export interface ConnectionGatedQuery {
  isPaused: boolean
  isError: boolean
  data: unknown
  refetch: () => unknown
}

/**
 * `true` cuando el módulo NO tiene con qué pintar estado compartido porque no
 * hay conexión (y nunca llegó a cargarlo en esta sesión).
 */
export function isConnectionBlocked(queries: ConnectionGatedQuery[]): boolean {
  return queries.some((q) => (q.isPaused || q.isError) && q.data === undefined)
}

export function ConnectionRequiredNotice({
  /** Qué necesita conexión, en palabras del cajero: "los espacios", "las órdenes". */
  what,
  onRetry,
}: {
  what: string
  onRetry?: () => void
}) {
  return (
    <div className="flex h-full items-center justify-center p-6">
      <EmptyState
        icon={CloudOff}
        title="Sin conexión"
        description={`Para ver ${what} hace falta internet: es información que se comparte con las otras cajas del comercio. La venta y la impresión siguen funcionando normalmente.`}
        ghost={false}
        actions={
          onRetry ? (
            <Button size="lg" variant="outline" onClick={onRetry}>
              Reintentar
            </Button>
          ) : undefined
        }
      />
    </div>
  )
}
