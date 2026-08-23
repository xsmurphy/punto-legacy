"use client"

/**
 * Imagen de catálogo con fallback.
 *
 * Existe porque una imagen que no carga —el caso normal sin conexión, cuando
 * todavía no pasó por la cache del service worker— dejaba el ícono de imagen
 * rota del navegador y el `alt` desnudo sobre el tile (owner, 2026-08-23). Un
 * `<img>` que falla no se puede ocultar con CSS: hay que enterarse del error y
 * renderizar otra cosa.
 *
 * El fallback no lo inventa este componente: lo pasa el call-site, porque cada
 * lugar ya tiene su placeholder del design system (el tile de hotkey pinta la
 * abreviatura sobre el color de la tecla; el tile de producto, el color por
 * defecto de la grilla). Acá solo vive la máquina de estados.
 *
 * El estado de error se guarda como "qué URL falló", no como un booleano: los
 * tiles se reciclan al cambiar de categoría, y con un booleano un ítem
 * heredaría el "falló" del anterior (y al revés). Comparando contra la URL
 * actual, el reset es automático.
 */

import * as React from "react"

export function ItemImage({
  src,
  alt,
  className,
  fallback,
}: {
  src: string | null | undefined
  alt: string
  className?: string
  /** Qué pintar si no hay `src` o si la imagen no carga. */
  fallback: React.ReactNode
}) {
  const [failedSrc, setFailedSrc] = React.useState<string | null>(null)

  if (!src || failedSrc === src) return <>{fallback}</>

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={src} alt={alt} className={className} onError={() => setFailedSrc(src)} />
  )
}

/**
 * ¿Hay que reservar el layout de "con foto"? Los tiles cambian de estructura
 * (gradiente + label abajo vs. abreviatura grande), así que necesitan saber si
 * la imagen cargó ANTES de decidir el layout, no solo dentro del `<img>`.
 *
 * Devuelve el par `[usable, onError]`: `usable` es "hay una url y todavía no
 * falló". El call-site sigue renderizando el `<img>` él mismo con ese
 * `onError`, y `ItemImage` queda para los casos simples (una foto, un
 * placeholder, sin cambio de layout alrededor).
 */
export function useImageFallback(src: string | null | undefined): [boolean, () => void] {
  // Misma clave que en `ItemImage`: el error es de ESTA url. Sin eso, un tile
  // reciclado en otra categoría arrastraría el fallback del ítem anterior.
  const [failedSrc, setFailedSrc] = React.useState<string | null>(null)
  const onError = React.useCallback(() => setFailedSrc(src ?? null), [src])
  return [Boolean(src) && failedSrc !== src, onError]
}
