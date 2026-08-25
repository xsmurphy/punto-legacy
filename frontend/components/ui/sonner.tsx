"use client"

import { useTheme } from "next-themes"
import { Toaster as Sonner, type ToasterProps } from "sonner"
import { CircleCheckIcon, InfoIcon, TriangleAlertIcon, OctagonXIcon, Loader2Icon } from "lucide-react"

/**
 * Separación del toast respecto del borde del viewport.
 *
 * Son los defaults de sonner (24px en desktop, 16px en móvil) MÁS el área
 * segura del dispositivo. Sin esto, en la PWA instalada —donde
 * `viewport-fit=cover` hace que la página llegue por debajo del status bar
 * translúcido— los toasts `position="top-center"` del POS aparecían detrás del
 * reloj y la batería (reporte del owner 2026-08-25).
 *
 * Va acá, en el wrapper, y no en cada `<Toaster>`: hay uno en
 * `components/providers.tsx` (panel + POS) y otro en `app/(screen)/layout.tsx`
 * (pantallas de cliente/KDS), y los dos tienen el mismo borde que esquivar.
 *
 * Los cuatro lados y no solo el de arriba: un toast `bottom-*` tiene que
 * esquivar la barra de gestos igual que uno `top-*` esquiva el status bar, y
 * en landscape con notch el que manda es el lateral. Donde no hay inset las
 * variables valen 0 y queda exactamente el default de sonner — el desktop no
 * se mueve un pixel. Ver `app/globals.css` § "Áreas seguras del dispositivo".
 */
const SAFE_OFFSET = {
  top: "calc(24px + var(--safe-t))",
  right: "calc(24px + var(--safe-r))",
  bottom: "calc(24px + var(--safe-b))",
  left: "calc(24px + var(--safe-l))",
}

const SAFE_MOBILE_OFFSET = {
  top: "calc(16px + var(--safe-t))",
  right: "calc(16px + var(--safe-r))",
  bottom: "calc(16px + var(--safe-b))",
  left: "calc(16px + var(--safe-l))",
}

const Toaster = ({ ...props }: ToasterProps) => {
  const { theme = "system" } = useTheme()

  return (
    <Sonner
      theme={theme as ToasterProps["theme"]}
      offset={SAFE_OFFSET}
      mobileOffset={SAFE_MOBILE_OFFSET}
      className="toaster group"
      icons={{
        success: (
          <CircleCheckIcon className="size-4 text-[var(--chart-1)]" />
        ),
        info: (
          <InfoIcon className="size-4 text-[var(--chart-1)]" />
        ),
        warning: (
          <TriangleAlertIcon className="size-4 text-amber-500" />
        ),
        error: (
          <OctagonXIcon className="size-4 text-destructive" />
        ),
        loading: (
          <Loader2Icon className="size-4 animate-spin text-muted-foreground" />
        ),
      }}
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
          "--border-radius": "var(--radius)",
        } as React.CSSProperties
      }
      toastOptions={{
        classNames: {
          toast: "cn-toast",
        },
      }}
      {...props}
    />
  )
}

export { Toaster }
