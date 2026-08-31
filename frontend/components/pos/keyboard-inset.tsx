"use client"

import * as React from "react"

/**
 * Publica en `<html>` cuánto alto le está comiendo el TECLADO VIRTUAL a la
 * pantalla, como `--kb-inset` (px). Las superficies flotantes lo descuentan
 * igual que descuentan las áreas seguras — ver `components/ui/dialog.tsx`.
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
 * necesite convivir con el teclado descuenta `var(--kb-inset)` en su alto y
 * listo. El bottom drawer de vaul dejó de ser la excepción: consume la
 * variable como el resto (`components/ui/drawer.tsx`, dirección bottom), y su
 * `repositionInputs` va apagado para que no mueva el drawer una segunda vez.
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
      // Lo tapado = ALTURA del layout viewport menos ALTURA del visual. Nada
      // más: los dos términos son alturas y la diferencia es exactamente el
      // alto del teclado.
      //
      // `vv.offsetTop` NO va acá, y restarlo era el bug (capturas del owner,
      // 2026-08-31: el teclado tapaba el PIN y la nota de venta, y NINGUNA
      // pantalla se movía). `offsetTop` es POSICIÓN —cuánto desplazó el
      // navegador el viewport visual dentro del de layout—, no alto. Cuando
      // iOS desplaza para revelar el campo enfocado, ese valor crece hasta casi
      // lo que mide el teclado, así que la resta se cancelaba sola: `covered`
      // caía por debajo del umbral, el inset quedaba en 0 y todo el sistema
      // —diálogos, drawers, shell— descontaba cero JUSTO cuando más importaba.
      // El comentario anterior afirmaba lo contrario de lo que hacía el código.
      //
      // El minuendo es `documentElement.clientHeight`, NO `window.innerHeight`.
      //
      // Medición del owner en su iPhone, PWA instalada, con el teclado abierto
      // (sonda `?debug=viewport`, 2026-08-31):
      //
      //     innerHeight        441      html.clientHeight   797
      //     visualViewport.h   441      100dvh              797
      //
      // `innerHeight` SIGUE al viewport visual en iOS standalone: vale lo mismo
      // que `vv.height`, así que restarlos da 0 siempre y el inset nunca se
      // publicaba. Ese fue el error de origen, y explica por qué el arreglo
      // anterior —sacar `offsetTop` de la cuenta— no podía cambiar nada: los
      // dos términos ya eran el mismo número.
      //
      // `clientHeight` del `<html>` es la altura contra la que el CSS resuelve
      // `100dvh` y contra la que se posicionan los elementos `fixed`, que es
      // exactamente el marco del que hay que descontar. Acá: 797 - 441 = 356,
      // el alto real del teclado.
      //
      // Sirve igual para el navegador que SÍ achica su viewport de layout al
      // abrir el teclado (`interactive-widget=resizes-content`): ahí
      // `clientHeight` baja junto con `vv.height`, la diferencia da ~0 y el
      // resultado es correcto, porque en ese caso el contenido ya se reacomodó
      // solo y no hay nada que descontar. El `max(0, …)` cubre el redondeo.
      const layoutHeight = document.documentElement.clientHeight
      const covered = Math.max(0, layoutHeight - vv.height)
      // Umbral: las barras del navegador entran y salen con ~60-90px de
      // diferencia y NO son un teclado. Ningún teclado virtual mide menos de
      // ~250px, así que 120 separa las dos cosas sin falsos positivos.
      const inset = covered > 120 ? Math.round(covered) : 0
      root.style.setProperty("--kb-inset", `${inset}px`)

      // Restauración activa del corrimiento de iOS: enfocar un campo puede
      // scrollear la ventana y/o dejar el viewport visual con offset, y ese
      // resto SOBREVIVE al cierre del teclado — la app entera queda corrida
      // hacia arriba (los screenshots del owner 2026-08-26: hasta el overlay
      // de un drawer terminaba ~60pt antes del borde). El body fijado
      // (globals.css) previene la mayor parte; esto limpia lo que quede.
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
      root.style.removeProperty("--kb-inset")
    }
  }, [])

  return null
}
