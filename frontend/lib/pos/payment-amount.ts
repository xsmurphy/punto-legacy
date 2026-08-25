/**
 * Cuánto cobra un toque a un medio de pago — la regla del visor del cobro.
 *
 * El diálogo de cobro tiene UN visor que es display e input a la vez: cuando
 * el cajero no tipeó nada, muestra el restante (es el placeholder, con el
 * mismo tipo y color que el texto tipeado, así que se lee como un monto
 * cargado). De ahí sale la regla:
 *
 *   el monto del pago es lo que dice el visor.
 *
 * Vacío → el restante. Menos que el restante → pago parcial, el cajero sigue
 * sumando medios. Más que el restante → se cobra el restante y la diferencia
 * es vuelto (que el medio acepte vuelto o no lo decide el llamador; acá solo
 * se calcula).
 *
 * Por qué es una función pura y no tres `if` dentro del handler del botón: la
 * regla estaba duplicada por modo, y la copia del modo CRÉDITO se olvidaba del
 * caso "visor vacío" y hacía `return` en silencio — el cajero elegía cliente,
 * veía el total en pantalla, tocaba cualquier medio de pago y no pasaba
 * absolutamente nada (reporte del owner, 2026-08-25). El modo de la venta no
 * cambia de dónde sale el monto; cambia solo si la venta se confirma sola al
 * quedar cubierta. Con una sola implementación no hay dónde volver a
 * bifurcarla, y tiene test.
 */

export interface ResolvedPaymentAmount {
  /** Lo que se registra como pago. Nunca supera el restante. */
  amount: number
  /** Excedente entregado por el cliente. 0 si no entregó de más. */
  change: number
}

/**
 * @param typed     Monto tipeado en el visor (0 = el cajero no tipeó nada).
 * @param remaining Lo que falta cobrar de la venta. Debe ser > 0.
 */
export function resolvePaymentAmount(
  typed: number,
  remaining: number,
): ResolvedPaymentAmount {
  // Un pago nunca puede exceder el restante: el excedente es vuelto, no plata
  // que entra a la venta. Si entrara como monto, el total pagado superaría el
  // total de la venta y el arqueo cerraría con más de lo que se facturó.
  if (typed <= 0 || typed >= remaining) {
    return { amount: remaining, change: Math.max(0, typed - remaining) }
  }
  return { amount: typed, change: 0 }
}
