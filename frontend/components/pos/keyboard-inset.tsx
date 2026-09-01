"use client"

import * as React from "react"

import { keyboardWindow } from "@/lib/pos/keyboard-window"

/**
 * Publica en `<html>` la VENTANA VISIBLE del viewport mientras el TECLADO
 * VIRTUAL está abierto, como tres variables en px:
 *
 *     --kb-top      lo tapado/scrolleado por ARRIBA
 *     --kb-bottom   lo tapado por ABAJO (el teclado propiamente dicho)
 *     --kb-inset    el total tapado (`--kb-top` + `--kb-bottom`)
 *
 * Las superficies que se POSICIONAN contra el viewport usan el par
 * (`top: var(--kb-top); bottom: var(--kb-bottom)`); las que solo se
 * DIMENSIONAN siguen usando `--kb-inset`, que no cambió de significado. La
 * aritmética y el porqué del par viven en `lib/pos/keyboard-window.ts`.
 *
 * POR QUÉ NO ALCANZA EL CSS
 * -------------------------
 * `dvh` mide el viewport de LAYOUT. En iOS —y en la PWA instalada, que es como
 * opera la caja— el teclado NO achica ese viewport: se dibuja encima. O sea
 * que un contenedor `100dvh` con el teclado abierto sigue midiendo la pantalla
 * entera y su mitad de abajo queda tapada. Es exactamente lo que reportó el
 * owner (2026-08-25): al buscar un usuario en el teléfono, el campo y los
 * resultados quedaban detrás del teclado.
 *
 * El único lugar donde el navegador cuenta la verdad es `window.visualViewport`
 * (el viewport VISUAL, el que sí se achica). De ahí sale la resta de abajo.
 * `interactive-widget=resizes-content` en el meta viewport resolvería lo mismo
 * declarativamente pero solo en Chrome/Android; iOS lo ignora, y la caja
 * corre en los dos.
 *
 * UNA MEDICIÓN, UN CONSUMIDOR
 * ---------------------------
 * Igual que con las áreas seguras: se mide UNA vez acá y se expone como
 * variable. Ningún call-site vuelve a tocar `visualViewport` — el modal que
 * necesite convivir con el teclado se apoya en el par y listo. El bottom
 * drawer de vaul dejó de ser la excepción: consume las variables como el resto
 * (`components/ui/drawer.tsx`, dirección bottom), y su `repositionInputs` va
 * apagado para que no mueva el drawer una segunda vez.
 *
 * Vive montado desde el layout del POS, junto a `PosTouchScope`, y limpia la
 * variable al desmontar: fuera de la caja nadie la consume.
 */
export function PosKeyboardInset() {
  React.useEffect(() => {
    const vv = window.visualViewport
    if (!vv) return

    const root = document.documentElement
    let frame = 0

    function measure() {
      frame = 0
      if (!vv) return
      // TRES INTENTOS, TRES PREMISAS FALSAS — la historia, porque cada arreglo
      // parecía obvio hasta que llegó la captura siguiente:
      //
      //   1. `innerHeight - vv.height - vv.offsetTop`. En iOS standalone
      //      `innerHeight` SIGUE al viewport VISUAL (medición del owner:
      //      innerHeight 441 = vv.height 441), así que los dos primeros
      //      términos ya eran el mismo número: la cuenta daba 0 y `--kb-inset`
      //      nunca se publicaba. Síntoma: contenido tapado DETRÁS del teclado.
      //   2. `clientHeight - vv.height`, sacando `offsetTop` de la cuenta. La
      //      resta de alturas quedó bien —y sigue siendo la de `inset`—, pero
      //      la conclusión de la que colgaba ("`offsetTop` es POSICIÓN, no
      //      alto, y el body fijado impide que iOS desplace el viewport") era
      //      media verdad: es posición, sí, y precisamente POR ESO hacía falta,
      //      porque sin ella no hay dónde. La medición del owner
      //      (visualViewport.top = 356 con el body ya fijado) probó que el
      //      desplazamiento ocurre igual. Síntoma: todo lo `fixed` corrido
      //      hacia ARRIBA, fuera de pantalla — el opuesto exacto del anterior.
      //   3. Esta. Se publica la VENTANA VISIBLE, no solo cuánto tapa el
      //      teclado. La fórmula, los números del dispositivo y la invariante
      //      `top + bottom === inset` están en `lib/pos/keyboard-window.ts`.
      //
      // El primer argumento es `documentElement.clientHeight` por lo del punto
      // 1: es el alto contra el que el CSS resuelve `100dvh` y contra el que se
      // posicionan los elementos `fixed`, que es exactamente el marco a
      // repartir.
      //
      // Chrome/Android sigue funcionando sin caso especial. Si achica el
      // viewport de LAYOUT (`interactive-widget=resizes-content`),
      // `clientHeight` baja junto con `vv.height`, la diferencia queda bajo el
      // umbral y los tres valores dan 0 — correcto, porque ahí el contenido ya
      // se reacomodó solo. Si no lo achica, `offsetTop` es 0 y todo lo tapado
      // se le carga a `--kb-bottom`, que es literalmente el comportamiento que
      // tenía `--kb-inset` antes de este cambio.
      const { top, bottom, inset } = keyboardWindow(
        document.documentElement.clientHeight,
        vv.height,
        vv.offsetTop
      )
      root.style.setProperty("--kb-top", `${top}px`)
      root.style.setProperty("--kb-bottom", `${bottom}px`)
      root.style.setProperty("--kb-inset", `${inset}px`)

      // Restauración activa del corrimiento de iOS: enfocar un campo puede
      // scrollear la ventana y/o dejar el viewport visual con offset, y ese
      // resto SOBREVIVE al cierre del teclado — la app entera queda corrida
      // hacia arriba (los screenshots del owner 2026-08-26: hasta el overlay
      // de un drawer terminaba ~60pt antes del borde). El body fijado
      // (globals.css) previene la mayor parte; esto limpia lo que quede.
      //
      // Con el teclado ABIERTO no se toca: ahí el desplazamiento no es un resto
      // a limpiar sino la posición real de la ventana visible, y es lo que
      // `--kb-top` publica.
      if (inset === 0 && (window.scrollY !== 0 || vv.offsetTop > 0)) {
        window.scrollTo(0, 0)
      }
    }

    // `resize` y `scroll` del viewport visual son los dos eventos que dispara
    // abrir/cerrar el teclado; se coalescen en un frame porque iOS los emite
    // en ráfaga mientras el teclado sube.
    function schedule() {
      if (!frame) frame = requestAnimationFrame(measure)
    }

    measure()
    vv.addEventListener("resize", schedule)
    vv.addEventListener("scroll", schedule)

    return () => {
      vv.removeEventListener("resize", schedule)
      vv.removeEventListener("scroll", schedule)
      if (frame) cancelAnimationFrame(frame)
      root.style.removeProperty("--kb-top")
      root.style.removeProperty("--kb-bottom")
      root.style.removeProperty("--kb-inset")
    }
  }, [])

  return null
}
