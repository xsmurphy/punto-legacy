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
  className,
}: {
  variant?: "mark" | "wordmark"
  className?: string
}) {
  if (variant === "mark") {
    // Icon ratio ~1:1 (557 × 558 originales).
    return (
      <span className={cn("relative inline-block size-8", className)} aria-label="Punto">
        <Image
          src="/logos/icon_bg_light.png"
          alt="Punto"
          fill
          sizes="32px"
          priority
          className="dark:hidden object-contain"
        />
        <Image
          src="/logos/icon_bg_dark.png"
          alt="Punto"
          fill
          sizes="32px"
          priority
          className="hidden dark:block object-contain"
        />
      </span>
    )
  }

  // Wordmark ratio ~3:1 (2000 × 684 displayed). Width default = 100px,
  // alto se ajusta solo. Mantenemos box ratio fijo para evitar layout shift.
  return (
    <span
      className={cn("relative inline-block h-7 w-[100px]", className)}
      aria-label="Punto"
    >
      <Image
        src="/logos/logo_bg_light.png"
        alt="Punto"
        fill
        sizes="100px"
        priority
        className="dark:hidden object-contain object-left"
      />
      <Image
        src="/logos/logo_bg_dark.png"
        alt="Punto"
        fill
        sizes="100px"
        priority
        className="hidden dark:block object-contain object-left"
      />
    </span>
  )
}
