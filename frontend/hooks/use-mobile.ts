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

const MOBILE_QUERY = `(max-width: ${MOBILE_BREAKPOINT - 1}px)`

let mobileMql: MediaQueryList | null = null

function getMobileMql(): MediaQueryList {
  if (mobileMql === null) mobileMql = window.matchMedia(MOBILE_QUERY)
  return mobileMql
}

function subscribeMobile(onStoreChange: () => void) {
  const mql = getMobileMql()
  mql.addEventListener("change", onStoreChange)
  return () => mql.removeEventListener("change", onStoreChange)
}

/**
 * ¿El viewport es de teléfono (< 768px)?
 *
 * `useSyncExternalStore` y no `useState` + `useEffect`: la versión con effect
 * devolvía `false` en el PRIMER paint de cada montaje y el valor real recién en
 * el commit siguiente. Para un componente que se monta junto con la página eso
 * no se nota, pero TODO lo que se monta tarde —el contenido de un dialog al
 * abrirse, por ejemplo— pintaba un frame con el layout de desktop y después
 * saltaba al de teléfono. Con el store externo, un montaje posterior a la
 * hidratación lee `matchMedia` en el render y acierta desde el primer frame.
 *
 * El snapshot de servidor sigue siendo `false` — idéntico a lo que hacía el
 * `useState(undefined)` durante SSR/hidratación, así que no introduce mismatch.
 */
export function useIsMobile() {
  return React.useSyncExternalStore(
    subscribeMobile,
    () => getMobileMql().matches,
    () => false,
  )
}
