import Image from "next/image"
import { cn } from "@/lib/utils"

/**
 * Logo oficial de Punto — versiones legacy de panel/images/ (copiadas a public/logos).
 *
 * Variantes:
 *   - `mark`: solo la "o" (icon-only, sidebar collapsed).
 *   - `wordmark`: "punto" completo (sidebar expanded, login, etc).
 *
 * Cada variante tiene par light/dark. El switch se hace con classes Tailwind
 * (`dark:hidden` / `hidden dark:block`) — Next optimiza ambas imágenes
 * pero el browser solo descarga la que va a mostrar (display:none no fetchea).
 *
 * Naming: `bg_light` = el logo es PARA fondo claro (logo oscuro);
 * `bg_dark` = para fondo oscuro (logo claro). El nombre refiere al bg
 * del entorno, no del logo en sí.
 */
export function PuntoLogo({
  variant = "wordmark",
  scheme = "auto",
  className,
}: {
  variant?: "mark" | "wordmark"
  /**
   * "auto" (default): par light/dark según el tema.
   * "on-dark" / "on-light": fuerza una sola versión sin importar el tema —
   * para superficies fijas (ej. sitio de marketing: header sobre el hero de
   * video usa "on-dark"; el resto del sitio, siempre claro, usa "on-light"
   * porque las utilities `dark:` no se neutralizan dentro de `.light`).
   */
  scheme?: "auto" | "on-dark" | "on-light"
  className?: string
}) {
  const auto = scheme === "auto"
  const lightSrc = variant === "mark" ? "/logos/icon_bg_light.png" : "/logos/logo_bg_light.png"
  const darkSrc = variant === "mark" ? "/logos/icon_bg_dark.png" : "/logos/logo_bg_dark.png"
  const box =
    variant === "mark"
      ? // Icon ratio ~1:1 (557 × 558 originales).
        "relative inline-block size-8"
      : // Wordmark ratio ~3:1 (2000 × 684). Box fijo para evitar layout shift.
        "relative inline-block h-7 w-[100px]"
  const fit = cn("object-contain", variant === "wordmark" && "object-left")
  const sizes = variant === "mark" ? "32px" : "100px"

  return (
    <span className={cn(box, className)} aria-label="Punto">
      {(auto || scheme === "on-light") && (
        <Image
          src={lightSrc}
          alt="Punto"
          fill
          sizes={sizes}
          priority
          className={cn(fit, auto && "dark:hidden")}
        />
      )}
      {(auto || scheme === "on-dark") && (
        <Image
          src={darkSrc}
          alt="Punto"
          fill
          sizes={sizes}
          priority
          className={cn(fit, auto && "hidden dark:block")}
        />
      )}
    </span>
  )
}
