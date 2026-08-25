/**
 * Copy de la confirmación de "liberar el espacio sin cobro".
 *
 * La operación es una sola (`action=cancel` → `SpaceSessionService::cancel`,
 * que cancela EN CASCADA las órdenes activas) pero hoy se dispara desde dos
 * lugares: el botón "Cancelar sesión" del `SpaceSessionDialog` y el ítem
 * "Cerrar espacio" del menú de acciones del tile. Lo que el cajero tiene que
 * leer antes de confirmar —cuántas órdenes se van a cancelar— es idéntico en
 * los dos, y es justo el dato que hace destructiva a la acción.
 *
 * Vive acá y no duplicado en cada call-site porque el número de órdenes es la
 * mitad del mensaje: una copia que se olvide de mencionarlas convierte un
 * "vas a cancelar 3 órdenes" en un "listo" silencioso.
 *
 * Los LABELS sí difieren a propósito entre los dos lugares ("Cancelar sesión"
 * vs "Cerrar espacio", pedido del owner en el mockup del menú) — lo que se
 * comparte es la advertencia, no el nombre del botón.
 */

/** Estados en los que una orden todavía cuenta como activa (espejo de `ACTIVE_ORDER_STATUSES`). */
export function countActiveOrders(
  orders: { status: string }[],
  activeStatuses: readonly string[],
): number {
  return orders.filter((o) => activeStatuses.includes(o.status)).length
}

/**
 * Advertencia de la confirmación. Con órdenes activas dice cuántas se pierden;
 * sin ellas, que el espacio queda libre sin cobro.
 */
export function cancelSessionDescription(activeOrderCount: number): string {
  if (activeOrderCount > 0) {
    const noun = activeOrderCount === 1 ? "orden activa" : "órdenes activas"
    return `Se cancelarán ${activeOrderCount} ${noun} y el espacio quedará libre, sin cobro.`
  }
  return "El espacio quedará libre, sin cobro."
}
