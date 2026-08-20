"use client"

import * as React from "react"
import Image from "next/image"

import { cn } from "@/lib/utils"

export type BackdropImage = { src: string; alt?: string }

/**
 * Fondo del hero: una o varias fotos que se cruzan con fade lento.
 * Con una sola imagen no monta timers. Respeta `prefers-reduced-motion`
 * (se queda en la primera foto).
 */
export function HeroBackdrop({
  images,
  intervalMs = 7000,
  className,
}: {
  images: BackdropImage[]
  intervalMs?: number
  className?: string
}) {
  const [active, setActive] = React.useState(0)

  React.useEffect(() => {
    if (images.length < 2) return
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches
    if (reduced) return
    const id = window.setInterval(
      () => setActive((i) => (i + 1) % images.length),
      intervalMs,
    )
    return () => window.clearInterval(id)
  }, [images.length, intervalMs])

  return (
    <div aria-hidden className={cn("absolute inset-0", className)}>
      {images.map((image, i) => (
        <Image
          key={image.src}
          src={image.src}
          alt=""
          fill
          priority={i === 0}
          sizes="100vw"
          className={cn(
            "object-cover transition-opacity duration-[2000ms] ease-in-out",
            i === active ? "opacity-100" : "opacity-0",
          )}
        />
      ))}
    </div>
  )
}
