import Link from "next/link"
import { ArrowLeft } from "lucide-react"

import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

/**
 * BackLink — el "Volver a …" del panel. UNO solo (context/84 T2, owner
 * 2026-09-18).
 *
 * La forma es la que ya tenían los reportes y el detalle de transacción
 * (`transactions/[id]`): `Button` ghost chico, con fondo al pasar el mouse.
 * Estaba copiado en 22 archivos y en algunos se había degradado a un `<Link>`
 * pelado sin hover de fondo (ficha de cliente, de persona) o a un ícono solo;
 * cada ficha se veía volver distinto.
 *
 * Prohibido definir un `BackLink`/`BackButton` local: lo impide el guard
 * `lib/ui/__tests__/entity-detail-structure.test.ts`.
 *
 * `label` vacío = solo la flecha (lo usa el listado de órdenes cuando se abre
 * desde la caja, donde el texto sobra). En ese caso `aria-label` es
 * obligatorio para que el botón tenga nombre.
 */
export function BackLink({
  href,
  label,
  ariaLabel,
  className,
}: {
  href: string
  label: string
  ariaLabel?: string
  className?: string
}) {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className={cn("-ml-2 h-7 w-fit text-xs text-muted-foreground hover:text-foreground", className)}
    >
      <Link href={href} aria-label={label ? undefined : (ariaLabel ?? "Volver")}>
        <ArrowLeft className="size-3.5" />
        {label}
      </Link>
    </Button>
  )
}
