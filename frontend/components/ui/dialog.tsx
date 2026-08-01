"use client"

import * as React from "react"
import { Dialog as DialogPrimitive } from "radix-ui"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { XIcon } from "lucide-react"

function Dialog({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Root>) {
  return <DialogPrimitive.Root data-slot="dialog" {...props} />
}

function DialogTrigger({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Trigger>) {
  return <DialogPrimitive.Trigger data-slot="dialog-trigger" {...props} />
}

function DialogPortal({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Portal>) {
  return <DialogPrimitive.Portal data-slot="dialog-portal" {...props} />
}

function DialogClose({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Close>) {
  return <DialogPrimitive.Close data-slot="dialog-close" {...props} />
}

function DialogOverlay({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
  return (
    <DialogPrimitive.Overlay
      data-slot="dialog-overlay"
      className={cn(
        "fixed inset-0 isolate z-50 bg-black/30 duration-100 supports-backdrop-filter:backdrop-blur-sm data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0",
        className
      )}
      {...props}
    />
  )
}

/**
 * ⚠ `max-h-[85dvh] overflow-y-auto` es un DEFAULT del primitive, no un detalle
 * de un diálogo puntual: sin esto NINGÚN modal de la app scrollea, y el
 * contenido que no entra en pantalla queda inalcanzable — incluidos los
 * botones de acción del final. Se reportó en el detalle de orden del POS y en
 * el alta de direcciones del panel; era el mismo bug en los dos lados.
 *
 * `dvh` y no `vh`: en móvil la barra del navegador cambia el alto visible y
 * `vh` deja el corte fuera de la pantalla real.
 *
 * Un diálogo que administra su propio scroll interno (ej. `pay-dialog`, con su
 * cuerpo scrolleable y footer fijo) simplemente pasa sus clases por
 * `className` y ganan: `cn()` mergea con tailwind-merge y lo específico pisa al
 * default.
 *
 * `mobileFullscreen` (OPT-IN, default false): bajo `sm` el content ocupa la
 * pantalla entera. SOLO para modales de CONTENIDO — listados, módulos de
 * ruta del POS, paneles de dos columnas — donde el centrado con
 * `max-h-[85dvh]` dejaba poco alto útil con el teclado virtual abierto
 * (reporte del owner 2026-08-01). Los modales chicos tipo confirmación
 * (nota de venta, lista de precios, modificador de precio, descuento,
 * alerts) se quedan CENTRADOS como siempre — hacerlos fullscreen fue una
 * regresión reportada el mismo día: no mezclar los dos tipos. Los dialogs
 * tipo command-palette (buscador de productos/clientes, flotantes top-
 * aligned con fondo transparente) tampoco lo usan. `content-start` evita
 * que `align-content: stretch` del grid estire header/footer en el alto
 * completo. Registrado en context/20-design-system.md §10.
 */
function DialogContent({
  className,
  children,
  showCloseButton = true,
  mobileFullscreen = false,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & {
  showCloseButton?: boolean
  mobileFullscreen?: boolean
}) {
  return (
    <DialogPortal>
      <DialogOverlay />
      <DialogPrimitive.Content
        data-slot="dialog-content"
        className={cn(
          "fixed top-1/2 left-1/2 z-50 grid max-h-[85dvh] w-full max-w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 gap-6 overflow-y-auto rounded-[min(var(--radius-4xl),24px)] bg-popover p-6 text-sm text-popover-foreground shadow-xl ring-1 ring-foreground/5 duration-100 outline-none sm:max-w-md dark:ring-foreground/10 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95",
          mobileFullscreen &&
            "max-sm:top-0 max-sm:left-0 max-sm:h-dvh max-sm:max-h-dvh max-sm:w-full max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:content-start max-sm:rounded-none",
          className
        )}
        {...props}
      >
        {children}
        {showCloseButton && (
          <DialogPrimitive.Close data-slot="dialog-close" asChild>
            <Button
              variant="ghost"
              className="absolute top-4 right-4 bg-secondary"
              size="icon-sm"
            >
              <XIcon
              />
              <span className="sr-only">Close</span>
            </Button>
          </DialogPrimitive.Close>
        )}
      </DialogPrimitive.Content>
    </DialogPortal>
  )
}

function DialogHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="dialog-header"
      className={cn("flex flex-col gap-1.5", className)}
      {...props}
    />
  )
}

function DialogFooter({
  className,
  showCloseButton = false,
  children,
  ...props
}: React.ComponentProps<"div"> & {
  showCloseButton?: boolean
}) {
  return (
    <div
      data-slot="dialog-footer"
      className={cn(
        "flex flex-col-reverse gap-2 sm:flex-row sm:justify-end",
        className
      )}
      {...props}
    >
      {children}
      {showCloseButton && (
        <DialogPrimitive.Close asChild>
          <Button variant="outline">Close</Button>
        </DialogPrimitive.Close>
      )}
    </div>
  )
}

function DialogTitle({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Title>) {
  return (
    <DialogPrimitive.Title
      data-slot="dialog-title"
      className={cn(
        "font-heading text-base leading-none font-medium",
        className
      )}
      {...props}
    />
  )
}

function DialogDescription({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Description>) {
  return (
    <DialogPrimitive.Description
      data-slot="dialog-description"
      className={cn(
        "text-sm text-muted-foreground *:[a]:underline *:[a]:underline-offset-3 *:[a]:hover:text-foreground",
        className
      )}
      {...props}
    />
  )
}

export {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogOverlay,
  DialogPortal,
  DialogTitle,
  DialogTrigger,
}
