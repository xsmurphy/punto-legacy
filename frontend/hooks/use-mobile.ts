import * as React from "react"

const MOBILE_BREAKPOINT = 768

/**
 * Detección de CAPACIDAD táctil (pointer: coarse), no de tamaño de pantalla.
 * Distinta de useIsMobile a propósito: una tablet POS >768px sigue siendo
 * táctil, y un desktop angosto con mouse no lo es. Usar esta para decidir
 * si el dispositivo puede tipear con teclado físico (numpads, atajos).
 */
export function useIsCoarsePointer() {
  const [coarse, setCoarse] = React.useState(false)

  React.useEffect(() => {
    const mql = window.matchMedia("(pointer: coarse)")
    const onChange = () => setCoarse(mql.matches)
    mql.addEventListener("change", onChange)
    setCoarse(mql.matches)
    return () => mql.removeEventListener("change", onChange)
  }, [])

  return coarse
}

export function useIsMobile() {
  const [isMobile, setIsMobile] = React.useState<boolean | undefined>(undefined)

  React.useEffect(() => {
    const mql = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`)
    const onChange = () => {
      setIsMobile(window.innerWidth < MOBILE_BREAKPOINT)
    }
    mql.addEventListener("change", onChange)
    setIsMobile(window.innerWidth < MOBILE_BREAKPOINT)
    return () => mql.removeEventListener("change", onChange)
  }, [])

  return !!isMobile
}
