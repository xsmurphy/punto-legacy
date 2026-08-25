/**
 * Transporte de la cola de operaciones: de una fila de `pendingOps` a la
 * request HTTP que le corresponde.
 *
 * Está aparte del motor (`pending-ops-sync.ts`) porque son dos preguntas
 * distintas: el motor decide QUÉ mandar y en qué orden; este archivo sabe
 * A DÓNDE va cada cosa. El motor se prueba con un transporte falso.
 *
 * Idempotencia — por qué cada operación se puede reenviar sin miedo
 * ────────────────────────────────────────────────────────────────
 * El caso que hay que sobrevivir no es el rechazo del servidor (ese se ve y se
 * maneja) sino el silencioso: la request LLEGÓ y se aplicó, y la respuesta se
 * perdió en el camino. El device no puede distinguirlo de "no llegó", así que
 * reintenta. Cada operación tiene que estar preparada para eso:
 *
 * - **Ajustes** y **hotkeys** son asignaciones de valor (`PUT`): aplicarlas dos
 *   veces deja exactamente el mismo estado que aplicarlas una.
 * - **Apertura y cierre de caja** ya son idempotentes en el backend, y no por
 *   casualidad: `DrawerService::open()` devuelve `'Already Open'` si hay un
 *   turno abierto (con el índice único `uidx_drawer_register_open` detrás por
 *   si dos requests corren juntas) y `close()` devuelve `'Already Closed'`.
 *   Un reenvío es un no-op explícito, no un turno duplicado.
 * - **Extracciones e ingresos** se deduplican por (monto, fecha, caja) en
 *   `addExpense`/`addIncome`. La fecha lleva segundos y viene del momento en
 *   que el cajero operó, así que el reenvío colisiona consigo mismo — que es
 *   justo lo que se busca.
 * - **Alta de impresora** era la única que NO lo era: el `id` lo generaba
 *   `gen_random_uuid()` en el `INSERT`, así que dos envíos daban dos filas.
 *   Ahora el `id` lo genera el cliente y el `INSERT` va con `ON CONFLICT DO
 *   NOTHING` (ver `PrinterBindingService::create`). El segundo envío encuentra
 *   su propia fila y devuelve la misma. Edición y baja son idempotentes por
 *   naturaleza (asignación y borrado por id).
 *
 * Por eso no hay tabla de "operaciones ya vistas" server-side: no hace falta
 * inventar un registro de recibos cuando cada operación puede ser ella misma
 * repetible. El `opId` viaja igual, para poder rastrear una operación en los
 * logs de punta a punta.
 */

import { posFetch } from '@/lib/api/pos-fetch'
import { peekAll } from '@/lib/pos/offline-queue'
import { posApi } from '@/lib/api/pos-client'
import { ApiError } from '@/lib/api-client'
import { PendingOpError } from '@/lib/pos/pending-ops-sync'
import type { PendingOpRow } from '@/lib/pos/pending-ops'
import type {
  DrawerOpPayload,
  HotkeysPayload,
  PosConfigPatch,
  PrinterBindingCreatePayload,
  PrinterBindingDeletePayload,
  PrinterBindingUpdatePayload,
} from '@/lib/pos/local-register-state'

/**
 * Traduce lo que salga mal a la única distinción que el motor entiende.
 *
 * TRANSITORIO es solo lo que puede andar la próxima vez sin que nadie cambie
 * nada: no hubo respuesta (red caída, DNS, timeout) o el servidor se cayó
 * (5xx). Todo lo demás —422 de validación, 403 de permisos, 409— es el
 * servidor diciendo que no: reintentarlo es martillarlo con el mismo payload.
 */
function classify(err: unknown): PendingOpError {
  if (err instanceof PendingOpError) return err

  if (err instanceof ApiError) {
    const transient = err.status >= 500 || err.status === 0
    return new PendingOpError(
      transient ? 'SERVER_ERROR' : `HTTP_${err.status}`,
      err.message,
      transient,
    )
  }

  // `fetch` tira `TypeError` cuando no hay red — el corte de conexión real.
  return new PendingOpError(
    'NETWORK_ERROR',
    err instanceof Error ? err.message : 'Error de red',
    true,
  )
}

/**
 * Los BFF `/api/pos/*` devuelven el envelope `{ ok, data }` con 200 aun en
 * algunos errores de negocio del legacy, así que no alcanza con `res.ok`.
 */
async function posBff(path: string, init: RequestInit): Promise<unknown> {
  const res = await posFetch(path, init)
  const json = (await res.json().catch(() => null)) as
    | { ok?: boolean; data?: unknown; error?: { message?: string } }
    | null
  if (!res.ok || json?.ok === false) {
    throw new ApiError(res.status, json, json?.error?.message ?? `Error ${res.status}`)
  }
  return json?.data
}

/**
 * ¿Ya se puede mandar esta operación?
 *
 * Un solo caso, y es de plata: el CIERRE de caja no sale mientras queden
 * ventas del turno sin sincronizar. Las dos colas son independientes (la de
 * ventas drena en `use-offline-sync.ts`, esta en `use-pending-ops-sync.ts`), así
 * que sin este freno el cierre puede llegar primero: el servidor cerraría el
 * turno sin esas ventas y el arqueo que devuelve saldría corto justo en el
 * número que el cajero está mirando.
 *
 * Solo esperan las ventas que todavía pueden sincronizar. Una venta TERMINAL
 * (`failed` — la caja se la llevó otro device, por ejemplo) no se resuelve
 * sola, y hacer que trabe el cierre para siempre sería cambiar un problema
 * visible por uno peor: el cierre nunca llegaría. Esa venta ya grita por su
 * cuenta en la lista de pendientes.
 *
 * @returns `null` si puede salir, o el motivo de la espera.
 */
export async function canSendPendingOp(row: PendingOpRow): Promise<string | null> {
  if (row.kind !== 'drawerClose') return null
  const sales = await peekAll()
  const unsent = sales.filter((s) => s.status !== 'failed')
  if (unsent.length === 0) return null
  return unsent.length === 1
    ? 'Esperando que se envíe 1 venta del turno'
    : `Esperando que se envíen ${unsent.length} ventas del turno`
}

/**
 * Manda una operación al servidor. Resuelve en éxito, tira `PendingOpError` si
 * no. Lo que resuelve es lo que respondió el servidor: hoy solo lo usa el
 * cierre de caja, que devuelve el arqueo con el que se compara el total local.
 */
export async function sendPendingOp(row: PendingOpRow): Promise<unknown> {
  try {
    switch (row.kind) {
      case 'posConfig': {
        // El body es el PATCH tal cual se encoló — las claves que el cajero
        // tocó y nada más. El merge con lo guardado lo hace el servidor
        // (`PUT /v1/register?resource=config`), que es donde tiene que estar:
        // así un cambio del panel en otra clave sobrevive a este envío.
        await posBff('/api/pos/register-config', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json', 'X-Punto-Op-Id': row.opId },
          body: JSON.stringify({ config: row.payload as PosConfigPatch }),
        })
        return
      }

      case 'hotkeys': {
        const { hotkeys } = row.payload as HotkeysPayload
        await posApi.put('/v1/register?resource=hotkeys', { hotkeys })
        return
      }

      case 'drawerOpen':
      case 'drawerClose':
      case 'drawerExpense':
      case 'drawerIncome': {
        const payload = row.payload as DrawerOpPayload
        const action = {
          drawerOpen: 'open',
          drawerClose: 'close',
          drawerExpense: 'expense',
          drawerIncome: 'income',
        }[row.kind]
        // `date` es la hora en que el cajero operó, NO la de la
        // sincronización. Es lo que hace que el turno quede con su ventana
        // real y que las ventas emitidas dentro de esa ventana caigan en el
        // arqueo correcto (`transactionDate > drawerOpenDate`). Mandar la hora
        // del sync movería la apertura horas adelante y dejaría afuera medio
        // turno.
        // El body lleva SOLO lo que el servidor necesita para aplicar la
        // operación. `payload.localTotals` (el total que este device tenía al
        // cerrar) se queda acá: es para comparar contra la respuesta, no un
        // dato que el servidor deba creer.
        //
        // `counted` sí va: es lo que el cajero declaró haber contado de cada
        // medio (mig 167), y es el hecho que el cierre existe para registrar.
        // Un cierre encolado por una versión anterior no lo trae y se manda
        // igual, sin el campo — el servidor lo lee como "solo efectivo".
        return await posBff('/api/pos/drawer', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Punto-Op-Id': row.opId },
          body: JSON.stringify({
            action,
            amount: payload.amount,
            date: payload.date,
            note: payload.note ?? '',
            ...(row.kind === 'drawerClose' && payload.counted
              ? { counted: payload.counted }
              : {}),
          }),
        })
      }

      case 'printerBindingCreate': {
        const { registerId, binding } = row.payload as PrinterBindingCreatePayload
        // `binding.id` viaja como `id`: el servidor lo usa tal cual, con
        // `ON CONFLICT DO NOTHING`, así que un reenvío devuelve la misma fila
        // en vez de crear una segunda impresora.
        await posApi.post('/v1/printer_binding', {
          action: 'create',
          registerId,
          ...binding,
        } as unknown as Record<string, unknown>)
        return
      }

      case 'printerBindingUpdate': {
        const { id, patch } = row.payload as PrinterBindingUpdatePayload
        await posApi.post('/v1/printer_binding', {
          action: 'update',
          id,
          ...patch,
        } as unknown as Record<string, unknown>)
        return
      }

      case 'printerBindingDelete': {
        const { id } = row.payload as PrinterBindingDeletePayload
        await posApi.post('/v1/printer_binding', { action: 'delete', id })
        return
      }
    }
  } catch (err) {
    throw classify(err)
  }
}
