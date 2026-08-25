/**
 * Gate de cierre de turno — "no se cierra con órdenes o espacios abiertos".
 *
 * Regla OPCIONAL del comercio (owner 2026-08-25). Este módulo es la parte PURA:
 * los tipos, la normalización del payload y los textos. Sin React, sin fetch,
 * sin IndexedDB — mismo criterio que `local-shift-total.ts`, y por el mismo
 * motivo: la lógica que decide qué ve el cajero se puede verificar sin montar
 * un POS.
 *
 * El hook que lo consulta es `useShiftCloseBlockers` (`hooks/use-drawer.ts`).
 * La regla de verdad vive en el servidor (`api/lib/services/ShiftCloseGate.php`);
 * esto es la UX que la anticipa.
 *
 * Alcance SUCURSAL, no caja: `space_session` no tiene columna de caja y
 * `pos_order.registerid` no lo filtra nadie. Está argumentado en el docblock
 * del servicio PHP y en `context/51-configuracion-offline-de-la-caja.md` §8.
 */

export type ShiftCloseBlockerOrder = {
  id: string
  number: number | null
  status: string
  source: string
  /** Nombre del espacio (alias de la ocupación, o el nombre fijo), si la orden es de uno. */
  space: string | null
}

export type ShiftCloseBlockerSpace = {
  id: string
  name: string
  status: string
}

export type ShiftCloseBlockers = {
  /**
   * ¿El comercio prendió la regla? Distingue "no hay nada abierto" de "esta
   * regla no aplica acá": son dos botones habilitados por motivos distintos y
   * el segundo no muestra ningún aviso.
   */
  enabled: boolean
  orderCount: number
  spaceCount: number
  total: number
  orders: ShiftCloseBlockerOrder[]
  spaces: ShiftCloseBlockerSpace[]
  /** El detalle viene acotado por el servidor; los conteos son el total real. */
  truncated: boolean
}

export const EMPTY_SHIFT_CLOSE_BLOCKERS: ShiftCloseBlockers = {
  enabled: false,
  orderCount: 0,
  spaceCount: 0,
  total: 0,
  orders: [],
  spaces: [],
  truncated: false,
}

/**
 * Normaliza lo que devolvió el servidor.
 *
 * Los arrays se validan de verdad (`Array.isArray`) y no por spread: un
 * `orders: null` de una respuesta a medias reventaría el `.map()` del JSX, y
 * la pantalla donde eso pasaría es la del arqueo.
 */
export function parseShiftCloseBlockers(raw: unknown): ShiftCloseBlockers {
  if (!raw || typeof raw !== "object") return EMPTY_SHIFT_CLOSE_BLOCKERS
  const d = raw as Partial<ShiftCloseBlockers>
  const orders = Array.isArray(d.orders) ? d.orders : []
  const spaces = Array.isArray(d.spaces) ? d.spaces : []
  return {
    enabled: d.enabled === true,
    // Los conteos se creen al servidor (puede haber más filas que detalle),
    // pero si no vinieron se cae a lo que sí se puede contar.
    orderCount: typeof d.orderCount === "number" ? d.orderCount : orders.length,
    spaceCount: typeof d.spaceCount === "number" ? d.spaceCount : spaces.length,
    total:
      typeof d.total === "number"
        ? d.total
        : (typeof d.orderCount === "number" ? d.orderCount : orders.length) +
          (typeof d.spaceCount === "number" ? d.spaceCount : spaces.length),
    orders,
    spaces,
    truncated: d.truncated === true,
  }
}

/**
 * Por qué no se puede cerrar, en una línea.
 *
 * Lo usan el tooltip del botón deshabilitado y el título del aviso, así que
 * los dos dicen exactamente lo mismo — un tooltip que contradiga al bloque de
 * abajo es peor que no tener tooltip.
 */
export function shiftCloseBlockedSummary(b: ShiftCloseBlockers): string {
  const partes: string[] = []
  if (b.orderCount > 0) {
    partes.push(b.orderCount === 1 ? "1 orden abierta" : `${b.orderCount} órdenes abiertas`)
  }
  if (b.spaceCount > 0) {
    partes.push(b.spaceCount === 1 ? "1 espacio abierto" : `${b.spaceCount} espacios abiertos`)
  }
  if (partes.length === 0) return "No se puede cerrar el turno."
  return `No se puede cerrar el turno: la sucursal tiene ${partes.join(" y ")}.`
}

/** Etiqueta de una orden en la lista: "Orden #14 — Mesa 3" / "Orden sin número". */
export function blockerOrderLabel(o: ShiftCloseBlockerOrder): string {
  const base = o.number === null ? "Orden sin número" : `Orden #${o.number}`
  return o.space ? `${base} — ${o.space}` : base
}

/** Etiqueta de un espacio: el nombre, y si ya pidió la cuenta se dice. */
export function blockerSpaceLabel(s: ShiftCloseBlockerSpace): string {
  return s.status === "bill_requested" ? `${s.name} — cuenta pedida` : s.name
}
