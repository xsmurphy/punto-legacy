"use client"

/**
 * ¿La app corre INSTALADA (standalone), y no adentro del navegador?
 *
 * Dos consumidores, y por eso vive acá y no copiado en cada uno:
 *
 *  - `components/layout/device-not-connected.tsx`, que explica por qué la app
 *    instalada no heredó el pareo del navegador (en iOS tienen almacenes
 *    separados).
 *  - `components/screens/screen-fullscreen-toggle.tsx`, que esconde el botón de
 *    pantalla completa: instalada ya ocupa toda la pantalla y el botón no
 *    tendría nada que hacer.
 *
 * Se resuelve en un efecto y arranca en `false` a propósito: `window` no existe
 * en el render del servidor, y decidirlo durante el render daría un mismatch de
 * hidratación.
 *
 * `navigator.standalone` es de iOS (no estándar) y `display-mode` es el camino
 * estándar; se miran los dos porque ninguno cubre todos los navegadores donde
 * esto corre.
 */

import * as React from "react"

export function isStandaloneDisplay(): boolean {
  if (typeof window === "undefined") return false
  const iosStandalone = (window.navigator as Navigator & { standalone?: boolean }).standalone === true
  const displayMode =
    typeof window.matchMedia === "function" &&
    (window.matchMedia("(display-mode: standalone)").matches ||
      window.matchMedia("(display-mode: fullscreen)").matches)
  return iosStandalone || displayMode
}

export function useStandalone(): boolean {
  const [standalone, setStandalone] = React.useState(false)
  React.useEffect(() => {
    setStandalone(isStandaloneDisplay())
  }, [])
  return standalone
}
