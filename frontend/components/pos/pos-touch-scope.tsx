"use client"

import * as React from "react"

/**
 * Marca `<html>` con `data-pos-touch` mientras el POS está montado.
 *
 * POR QUÉ EN LA RAÍZ Y NO EN EL SHELL
 * -----------------------------------
 * El POS tenía `.pos-scope` en el `SidebarInset` del layout `(pos)/` y
 * `app/globals.css` colgaba de ahí la typography de los campos. Pero ese
 * selector solo alcanza al árbol REAL del shell, y en Radix todo lo que se
 * portalea —diálogos, drawers, dropdowns, popovers, selects, toasts— se
 * monta como hijo directo del `<body>`, hermano del shell, no descendiente.
 *
 * O sea que justo las pantallas donde el cajero más toca (el cobro, el menú
 * principal, los dropdowns de opciones) quedaban FUERA del scope y nunca
 * recibieron una sola regla táctil. Ese es el agujero que cierra este
 * componente: `<html>` es el único ancestro común del shell y de los portales,
 * así que marcándolo un solo selector alcanza a las dos ramas.
 *
 * Por eso `.pos-scope` ya no existe: las dos familias de reglas del POS
 * (typography de los campos y mínimo táctil) cuelgan de este atributo.
 *
 * Se descartó `:has()` en CSS —que expresaría lo mismo sin JS— por
 * costo de evaluación (el motor reevalúa el `:has()` de la raíz ante cualquier
 * mutación del árbol, y el carrito muta en cada tecla) y por previsibilidad:
 * un atributo se ve en el inspector y se puede forzar a mano para probar.
 *
 * El atributo se saca al desmontar para que salir del POS hacia el panel en la
 * misma pestaña (misma SPA, mismo `<html>`) devuelva al panel su densidad
 * normal — subirle el mínimo táctil rompería sus tablas y sus forms.
 *
 * Las reglas viven en `app/globals.css` (§ "POS móvil — mínimo táctil"), no
 * acá: esto solo enciende el interruptor.
 */
export function PosTouchScope() {
  React.useEffect(() => {
    const root = document.documentElement
    root.setAttribute("data-pos-touch", "")
    return () => root.removeAttribute("data-pos-touch")
  }, [])

  return null
}
