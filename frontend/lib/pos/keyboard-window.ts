/**
 * La VENTANA VISIBLE del viewport cuando el teclado virtual está abierto.
 *
 * Función pura: recibe tres números del `visualViewport` y devuelve los tres
 * que se publican como variables CSS. Vive separada de
 * `components/pos/keyboard-inset.tsx` por una razón concreta: la aritmética es
 * el lugar donde este bug se rompió tres veces seguidas, y contra el
 * componente solo se podían escribir tests de expresión regular sobre el
 * código fuente. Acá se testea el RESULTADO
 * (`lib/pos/__tests__/keyboard-inset.test.ts`).
 *
 * POR QUÉ NO ALCANZA CON "CUÁNTO TAPA EL TECLADO"
 * -----------------------------------------------
 * Las dos versiones anteriores publicaban un solo número —`--kb-inset`, el
 * alto tapado— y los consumidores lo usaban como `top: 0; bottom:
 * var(--kb-inset)`. Eso describe CUÁNTO, nunca DÓNDE, y en iOS el "dónde" no
 * es cero: WebKit desplaza el viewport VISUAL dentro del de LAYOUT para
 * revelar el campo enfocado, y ese desplazamiento (`offsetTop`) no aparece en
 * ninguna resta de alturas.
 *
 * Medición del owner en su iPhone, PWA instalada, teclado abierto (sonda
 * `?debug=viewport`, 2026-08-31):
 *
 *     html.clientHeight   797     visualViewport.h    441
 *     100dvh / 100svh     797     visualViewport.top  356
 *
 * O sea: de los 797px del viewport de layout, lo que el usuario VE es el tramo
 * [356, 797]. Un `fixed` con `top: 0; bottom: 356px` ocupa [0, 441] — casi
 * exactamente la mitad que iOS scrolleó FUERA de vista, y por eso los círculos
 * del PIN aparecían pegados al borde de arriba y el drawer de nota se salía
 * por arriba de la pantalla. Con `--kb-inset: 0` el elemento ocupa [0, 797] y
 * su centro cae en la zona tapada: el síntoma VIEJO. Las dos versiones fallan
 * por lo mismo — nadie medía el desplazamiento.
 *
 * LO QUE SE PUBLICA
 * -----------------
 * Con `L = html.clientHeight`, `H = visualViewport.height`,
 * `T = visualViewport.offsetTop`:
 *
 *     top    = T             lo tapado/scrolleado por ARRIBA
 *     bottom = L - T - H     lo tapado por ABAJO (el teclado propiamente dicho)
 *     inset  = L - H         el total tapado
 *
 * Con los números de arriba: `top: 356px`, `bottom: 0px`, `inset: 356px`. Un
 * `fixed` con `top: var(--kb-top); bottom: var(--kb-bottom)` cubre exactamente
 * [356, 797], que es lo que se ve.
 *
 * `inset` SE CONSERVA y sigue significando lo mismo: los `max-height` que
 * hacen `calc(100dvh - var(--kb-inset))` son correctos como estaban (alto
 * visible = layout - total tapado). Lo que cambia es que ahora hay con qué
 * POSICIONAR, no solo con qué dimensionar.
 *
 * INVARIANTE: `top + bottom === inset`, siempre. La suma no cambia; cambia el
 * REPARTO. Antes todo se le cargaba a `bottom`, que es el caso de
 * Chrome/Android y el único que funcionaba. Está garantizada por construcción
 * —`bottom` se deriva restando— y no por redondear tres veces por separado.
 */

export type KeyboardWindow = {
  /** Tapado/scrolleado por arriba, en px. `visualViewport.offsetTop`. */
  top: number
  /** Tapado por abajo, en px. El teclado propiamente dicho. */
  bottom: number
  /** Total tapado, en px. `top + bottom`. */
  inset: number
}

/**
 * Piso para considerar que hay un teclado. Las barras del navegador entran y
 * salen con ~60-90px de diferencia y NO son un teclado; ningún teclado virtual
 * mide menos de ~250px, así que 120 separa las dos cosas sin falsos positivos.
 */
export const KEYBOARD_MIN_INSET = 120

const CLOSED: KeyboardWindow = { top: 0, bottom: 0, inset: 0 }

/**
 * @param layoutHeight `document.documentElement.clientHeight` — el alto contra
 *   el que el CSS resuelve `100dvh` y contra el que se posicionan los elementos
 *   `fixed`. NO `window.innerHeight`: en iOS standalone ese valor SIGUE al
 *   viewport visual (medición del owner: innerHeight 441 = vv.height 441), así
 *   que restarlos da 0 siempre y el teclado nunca se detecta.
 * @param visualHeight `visualViewport.height`.
 * @param offsetTop `visualViewport.offsetTop`.
 */
export function keyboardWindow(
  layoutHeight: number,
  visualHeight: number,
  offsetTop: number
): KeyboardWindow {
  const covered = layoutHeight - visualHeight
  // `!(covered > …)` y no `covered <= …` a propósito: así un NaN (viewport aún
  // sin medir) cae del lado cerrado en vez de propagarse a las variables CSS.
  //
  // Debajo del umbral se publican los TRES en cero, incluido `top`. Un
  // `offsetTop` residual sin teclado es el corrimiento que hay que RESTAURAR
  // (lo hace el `scrollTo(0,0)` de `keyboard-inset.tsx`), no un hueco contra el
  // que maquetar.
  if (!(covered > KEYBOARD_MIN_INSET)) return CLOSED

  const inset = Math.round(covered)
  // Clamp a [0, inset]: `offsetTop` puede venir con signo o pasarse por
  // redondeo, y un `top` mayor que el total tapado dejaría un `bottom`
  // negativo que empujaría el layout al revés.
  const top = Math.min(Math.max(Math.round(offsetTop), 0), inset)
  return { top, bottom: inset - top, inset }
}
