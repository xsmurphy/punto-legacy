/**
 * Default del slot `modal` del layout (panel). Se renderea cuando NO hay
 * intercepting route activo — i.e. cualquier URL que no sea `/settings` ni
 * otra ruta interceptada. Devuelve null porque no queremos pintar nada
 * cuando no hay modal abierto.
 *
 * Requerido por Next App Router: sin este archivo, navegar a una URL fuera
 * del slot tira un error de "missing default for parallel route".
 */
export default function ModalSlotDefault() {
  return null
}
