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
      // Lo tapado = lo que hay entre el borde de abajo del viewport visual y el
      // borde de abajo del de layout. `offsetTop` entra en la cuenta porque
      // iOS desplaza el viewport visual hacia arriba para revelar el campo
      // enfocado, y sin restarlo la medición se queda corta justo cuando más
      // importa.
      const covered = window.innerHeight - vv.height - vv.offsetTop
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
