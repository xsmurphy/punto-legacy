/**
 * Mapa del sitio viejo (encom.app) al nuevo (punto.la).
 *
 * Cada URL vieja apunta a su equivalente REAL, no al home: mandar todo a la
 * raíz hace que Google trate el redirect como un soft-404 y se pierda la
 * autoridad que esa página tenía. Lo que no tiene equivalente cae al home
 * como último recurso.
 */

export const LEGACY_PATH_MAP: Record<string, string> = {
  "/": "/",
  "/index.php": "/",

  // Módulos
  "/punto-de-venta.php": "/modulos/punto-de-venta",
  "/panel-de-control.php": "/modulos/panel",
  "/mesas-y-reservas.php": "/modulos/mesas-y-ordenes",
  "/gift-cards.php": "/modulos/gift-cards",
  "/precios.php": "/precios",

  // Rubros con equivalente directo
  "/restaurantes.php": "/para/restaurantes",
  "/bares-y-pubs.php": "/para/bares-y-pubs",
  "/cafes.php": "/para/cafeterias",
  "/retail.php": "/para/tiendas-de-ropa",

  /*
   * Sin equivalente todavía — son los rubros de servicios con turnos, que
   * dependen del módulo de agenda. Van al home hasta que existan sus
   * páginas; cuando se escriban, se apunta cada una acá.
   */
  "/agendamientos.php": "/",
  "/notificaciones.php": "/",
  "/salud.php": "/",
  "/spa.php": "/",
  "/wellness-center.php": "/",
  "/centros-de-estetica.php": "/",
  "/barberias-y-peluquerias.php": "/",
  "/veterinarias.php": "/",
  "/academia-de-baile.php": "/",
  "/yoga-y-pilates.php": "/",
  "/fitness-y-gimnasios.html": "/",
}

/** Destino en punto.la para una ruta del sitio viejo. */
export function resolveLegacyPath(pathname: string): string {
  const clean = pathname.toLowerCase()
  return LEGACY_PATH_MAP[clean] ?? "/"
}
