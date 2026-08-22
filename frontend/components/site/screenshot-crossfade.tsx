"use client"

import * as React from "react"
import Image from "next/image"

import { cn } from "@/lib/utils"

export type Screenshot = { src: string; alt: string }

/**
 * Screenshot del producto que alterna entre variantes (ej. el panel en
 * claro y en oscuro) con un fade lento. Con una sola imagen no monta
 * timers; respeta `prefers-reduced-motion` (se queda en la primera).
 */
export function ScreenshotCrossfade({
  images,
  intervalMs = 5000,
  className,
}: {
  images: Screenshot[]
  intervalMs?: number
  className?: string
}) {
  const [active, setActive] = React.useState(0)

  React.useEffect(() => {
    if (images.length < 2) return
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return
    const id = window.setInterval(
      () => setActive((i) => (i + 1) % images.length),
      intervalMs
    )
    return () => window.clearInterval(id)
  }, [images.length, intervalMs])

  return (
    // Ratio de las capturas (2880×1400): reserva el alto y evita el salto.
    <div className={cn("relative aspect-[2880/1400] w-full", className)}>
      {images.map((image, i) => (
        <Image
          key={image.src}
          src={image.src}
          alt={i === 0 ? image.alt : ""}
          aria-hidden={i !== 0 || undefined}
          fill
          sizes="(max-width: 1024px) 100vw, 1024px"
          className={cn(
            "object-cover transition-opacity duration-1000 ease-in-out",
            i === active ? "opacity-100" : "opacity-0"
          )}
        />
      ))}
    </div>
  )
}
