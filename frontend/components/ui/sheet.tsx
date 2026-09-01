"use client"

import * as React from "react"
import { Dialog as SheetPrimitive } from "radix-ui"

import { cn } from "@/lib/utils"
import { isolateOverlaySubmit } from "@/lib/overlay-form-isolation"
import { Button } from "@/components/ui/button"
import { XIcon } from "lucide-react"

function Sheet({ ...props }: React.ComponentProps<typeof SheetPrimitive.Root>) {
  return <SheetPrimitive.Root data-slot="sheet" {...props} />
}

function SheetTrigger({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Trigger>) {
  return <SheetPrimitive.Trigger data-slot="sheet-trigger" {...props} />
}

function SheetClose({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Close>) {
  return <SheetPrimitive.Close data-slot="sheet-close" {...props} />
}

function SheetPortal({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Portal>) {
  return <SheetPrimitive.Portal data-slot="sheet-portal" {...props} />
}

function SheetOverlay({
  className,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Overlay>) {
  return (
    <SheetPrimitive.Overlay
      data-slot="sheet-overlay"
      className={cn(
        "fixed inset-0 z-50 bg-black/30 duration-100 supports-backdrop-filter:backdrop-blur-sm data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0",
        className
      )}
      {...props}
    />
  )
}

/**
 * El `submit` de cualquier `<form>` que se monte adentro NO sale del content:
 * ver `lib/overlay-form-isolation.ts`. Mismo motivo que en `dialog.tsx` —
 * React propaga por su árbol, no por el del DOM, así que el portal no impide
 * que el form de la página que quedó atrás reciba el submit.
 *
 * TECLADO VIRTUAL — los bordes verticales se apoyan en la ventana visible
 * (`--kb-top` / `--kb-bottom`) y no en el viewport de layout. El Sheet es un
 * portal `fixed` que cuelga del `<body>`: NO hereda el reposicionamiento del
 * body del POS (un body fijado no crea bloque contenedor para sus
 * descendientes fijos), así que `inset-y-0` lo dejaba abarcando la pantalla
 * ENTERA con el teclado abierto — con el header y el título fuera de vista por
 * arriba en el iPhone, donde lo visible es [356, 797] de 797. Es el asistente
 * de la caja (`components/pos/pos-agent-dialog.tsx`), cuyo docblock afirmaba
 * justo lo contrario; corregido el 2026-09-01.
 *
 * `h-auto` en left/right reemplaza al `h-full`: con `position: fixed`, `top` +
 * `height` + `bottom` es una caja sobre-restringida y el navegador descarta
 * `bottom` — el mismo detalle que documenta `app/globals.css`. Con los dos
 * bordes y `auto` el alto sigue siendo definido, así que los hijos con
 * `h-full` resuelven igual. Fuera del POS el par vale `0px` y la geometría es
 * exactamente la de `inset-y-0 h-full`: el panel no cambia un pixel.
 */
function SheetContent({
  className,
  children,
  side = "right",
  showCloseButton = true,
  overlay = true,
  onSubmit,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Content> & {
  side?: "top" | "right" | "bottom" | "left"
  showCloseButton?: boolean
  overlay?: boolean
}) {
  return (
    <SheetPortal>
      {overlay && <SheetOverlay />}
      <SheetPrimitive.Content
        data-slot="sheet-content"
        data-side={side}
        className={cn(
          "fixed z-50 flex flex-col bg-popover bg-clip-padding text-sm text-popover-foreground shadow-xl transition duration-200 ease-in-out data-[side=bottom]:inset-x-0 data-[side=bottom]:bottom-[var(--kb-bottom)] data-[side=bottom]:h-auto data-[side=bottom]:border-t data-[side=left]:top-[var(--kb-top)] data-[side=left]:bottom-[var(--kb-bottom)] data-[side=left]:left-0 data-[side=left]:h-auto data-[side=left]:w-3/4 data-[side=left]:border-r data-[side=right]:top-[var(--kb-top)] data-[side=right]:bottom-[var(--kb-bottom)] data-[side=right]:right-0 data-[side=right]:h-auto data-[side=right]:w-3/4 data-[side=right]:border-l data-[side=top]:inset-x-0 data-[side=top]:top-[var(--kb-top)] data-[side=top]:h-auto data-[side=top]:border-b data-[side=left]:sm:max-w-sm data-[side=right]:sm:max-w-sm data-open:animate-in data-open:fade-in-0 data-[side=bottom]:data-open:slide-in-from-bottom-10 data-[side=left]:data-open:slide-in-from-left-10 data-[side=right]:data-open:slide-in-from-right-10 data-[side=top]:data-open:slide-in-from-top-10 data-closed:animate-out data-closed:fade-out-0 data-[side=bottom]:data-closed:slide-out-to-bottom-10 data-[side=left]:data-closed:slide-out-to-left-10 data-[side=right]:data-closed:slide-out-to-right-10 data-[side=top]:data-closed:slide-out-to-top-10",
          className
        )}
        {...props}
        onSubmit={isolateOverlaySubmit(onSubmit)}
      >
        {children}
        {showCloseButton && (
          <SheetPrimitive.Close data-slot="sheet-close" asChild>
            <Button
              variant="ghost"
              className="absolute top-4 right-4 bg-secondary"
              size="icon-sm"
            >
              <XIcon
              />
              <span className="sr-only">Close</span>
            </Button>
          </SheetPrimitive.Close>
        )}
      </SheetPrimitive.Content>
    </SheetPortal>
  )
}

function SheetHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sheet-header"
      className={cn("flex flex-col gap-1.5 p-6", className)}
      {...props}
    />
  )
}

function SheetFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sheet-footer"
      className={cn("mt-auto flex flex-col gap-2 p-6", className)}
      {...props}
    />
  )
}

function SheetTitle({
  className,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Title>) {
  return (
    <SheetPrimitive.Title
      data-slot="sheet-title"
      className={cn(
        "font-heading text-base font-medium text-foreground",
        className
      )}
      {...props}
    />
  )
}

function SheetDescription({
  className,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Description>) {
  return (
    <SheetPrimitive.Description
      data-slot="sheet-description"
      className={cn("text-sm text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Sheet,
  SheetTrigger,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetFooter,
  SheetTitle,
  SheetDescription,
}
