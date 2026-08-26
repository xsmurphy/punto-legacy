"use client"

import * as React from "react"

/**
 * Calibra `--safe-t` / `--safe-b` contra lo que el viewport REALMENTE ocupa.
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
 * NO es una segunda fuente de verdad de las áreas seguras: las variables se
 * siguen definiendo una sola vez en `globals.css` a partir de `env()`. Acá
 * solo se ANULAN cuando medimos que el chrome ya reservó ese espacio, y se
 * devuelven a su valor original (`removeProperty`) apenas vuelve a cubrir.
 *
 * Casos que cubre por construcción:
 *   - PWA instalada con cover OK  → no toca nada, `env()` manda.
 *   - PWA sin cover               → insets a 0 (el sistema ya reservó).
 *   - Safari/Chrome con barras    → insets a 0 (la barra ya ocupa la franja).
 *   - Rotación / cambio de barras → recalibra en `resize`.
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

      // Tolerancia de 2px: redondeos de escala, no chrome.
      const viewportCoversScreen = window.innerHeight >= screenAlong - 2

      if (viewportCoversScreen) {
        // Cover aplicando: las áreas seguras son reales y hay que descontarlas.
        root.style.removeProperty("--safe-t")
        root.style.removeProperty("--safe-b")
      } else {
        // El chrome del sistema ya se quedó con esa franja: descontarla otra
        // vez la contaría dos veces.
        root.style.setProperty("--safe-t", "0px")
        root.style.setProperty("--safe-b", "0px")
      }
    }

    calibrate()
    window.addEventListener("resize", calibrate)
    window.addEventListener("orientationchange", calibrate)
    return () => {
      window.removeEventListener("resize", calibrate)
      window.removeEventListener("orientationchange", calibrate)
      root.style.removeProperty("--safe-t")
      root.style.removeProperty("--safe-b")
    }
  }, [])

  return null
}
