"use client"

import * as React from "react"

/**
 * Calibra `--safe-b` contra lo que el viewport REALMENTE ocupa.
 *
 * EL PROBLEMA QUE RESUELVE. `env(safe-area-inset-*)` solo hay que descontarlo
 * cuando la página se dibuja POR DEBAJO del chrome del sistema — es decir,
 * cuando `viewport-fit=cover` está aplicando. Si no aplica (el navegador ya
 * recorta el viewport y reserva la franja del home indicator por su cuenta),
 * descontarlo otra vez lo cuenta DOS VECES: el sistema reserva ~34pt y el CTA
 * agrega otros ~34pt de padding. Es el "doble safe area" que reportó el owner
 * (2026-08-26): un hueco del doble del indicador, presente en todas las
 * pantallas del POS —incluidos overlays `fixed inset-0`— y que sobrevivió a
 * reinstalar la PWA.
 *
 * iOS no expone ningún flag que diga "cover está aplicando". Lo que sí se
 * puede medir es la consecuencia: con cover, el viewport ocupa la pantalla
 * entera; sin cover, mide menos. Eso es exactamente lo que compara este
 * componente, y por eso el ajuste es una MEDICIÓN, no una hipótesis sobre el
 * dispositivo.
 *
 * SOLO EL EJE INFERIOR. La primera versión anulaba también `--safe-t` y dejó
 * los iconos de la toolbar DEBAJO del reloj y la batería (owner, 2026-08-26).
 * Ese error probó algo valioso: arriba el viewport SÍ se extiende bajo el
 * status bar —o sea `cover` está aplicando— y el recorte es únicamente abajo.
 * El eje superior no se toca nunca: su `env()` es correcto.
 *
 * NO es una segunda fuente de verdad de las áreas seguras: la variable se
 * sigue definiendo una sola vez en `globals.css` a partir de `env()`. Acá solo
 * se ANULA mientras midamos que el chrome ya reservó ese espacio, y se
 * devuelve a su valor original (`removeProperty`) apenas el viewport cubra.
 *
 * Casos que cubre por construcción:
 *   - Viewport que llega al borde  → no toca nada, `env()` manda.
 *   - Viewport recortado abajo     → `--safe-b` a 0 (el sistema ya reservó).
 *   - Safari/Chrome con barras     → `--safe-b` a 0 (la barra ocupa la franja).
 *   - Rotación / cambio de barras  → recalibra en `resize`.
 */
export function SafeAreaCalibrator() {
  React.useEffect(() => {
    const root = document.documentElement

    function calibrate() {
      const sw = window.screen?.width ?? 0
      const sh = window.screen?.height ?? 0
      if (!sw || !sh) return

      // `screen.height` en iOS NO rota: es la dimensión física. Se compara
      // contra el lado que corresponde a la orientación actual.
      const portrait = window.innerHeight >= window.innerWidth
      const screenAlong = portrait ? Math.max(sw, sh) : Math.min(sw, sh)

      // Cuánto le falta al viewport para llegar al borde FÍSICO. Tolerancia de
      // 2px por redondeos de escala.
      const missing = screenAlong - window.innerHeight

      if (missing > 2) {
        // Al viewport le falta pantalla: el chrome del sistema ya reservó esa
        // franja, así que descontar `--safe-b` otra vez la cuenta DOS VECES.
        root.style.setProperty("--safe-b", "0px")
      } else {
        root.style.removeProperty("--safe-b")
      }
    }

    calibrate()
    window.addEventListener("resize", calibrate)
    window.addEventListener("orientationchange", calibrate)
    return () => {
      window.removeEventListener("resize", calibrate)
      window.removeEventListener("orientationchange", calibrate)
      root.style.removeProperty("--safe-b")
    }
  }, [])

  return null
}
