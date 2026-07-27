/**
 * Comandas pineadas al extremo izquierdo del KDS.
 *
 * El pin es LOCAL DEL DISPOSITIVO (localStorage, no BD) por diseño: es una
 * decisión de ESA pantalla — "yo, en esta estación, estoy trabajando esta
 * comanda y no quiero que se me mueva cuando entren pedidos nuevos". Otra
 * estación no tiene por qué verse afectada, y nada de esto pertenece al
 * tenant. Mismo criterio que `lib/kds/config.ts`.
 *
 * El orden del array ES el orden de despliegue: las pineadas van primero, en
 * el orden en que se pinearon.
 */

const KEY = "punto.kds.pins"

/**
 * Tope duro. Un pin es "no me muevas ESTA"; pinear 50 comandas no tiene
 * sentido operativo y sería otra forma de que el storage crezca sin control.
 */
const MAX_PINS = 24

export function loadKdsPins(): string[] {
  if (typeof window === "undefined") return []
  try {
    const raw = window.localStorage.getItem(KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) return []
    return parsed.filter((v): v is string => typeof v === "string").slice(0, MAX_PINS)
  } catch {
    return []
  }
}

export function saveKdsPins(ids: string[]): void {
  if (typeof window === "undefined") return
  try {
    window.localStorage.setItem(KEY, JSON.stringify(ids.slice(0, MAX_PINS)))
  } catch {
    /* storage lleno / modo privado — el pin es una comodidad, no se rompe la pantalla por esto */
  }
}

export function togglePin(pins: string[], orderId: string): string[] {
  return pins.includes(orderId)
    ? pins.filter((id) => id !== orderId)
    : [...pins, orderId].slice(-MAX_PINS)
}

/**
 * Purga los pins que ya no matchean ninguna orden viva. Sin esto, cada comanda
 * cobrada o cancelada dejaría su id colgado en localStorage para siempre en una
 * pantalla que queda abierta durante días.
 *
 * Devuelve la MISMA referencia si no hay nada que purgar — así el caller puede
 * cortar el re-render / la escritura a storage con una comparación por
 * identidad.
 */
export function purgeKdsPins(pins: string[], liveOrderIds: Set<string>): string[] {
  const next = pins.filter((id) => liveOrderIds.has(id))
  return next.length === pins.length ? pins : next
}

/**
 * Reordena la lista poniendo primero las pineadas (en el orden en que se
 * pinearon) y dejando el resto tal como venía (ya ordenado por tiempo).
 */
export function applyPinOrder<T extends { id: string }>(list: T[], pins: string[]): T[] {
  if (pins.length === 0) return list
  const byId = new Map(list.map((o) => [o.id, o]))
  const pinnedFirst: T[] = []
  for (const id of pins) {
    const found = byId.get(id)
    if (found) pinnedFirst.push(found)
  }
  if (pinnedFirst.length === 0) return list
  const pinnedSet = new Set(pinnedFirst.map((o) => o.id))
  return [...pinnedFirst, ...list.filter((o) => !pinnedSet.has(o.id))]
}
