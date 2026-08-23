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
 * GUTTER CANÓNICO DE LOS MODALES — 24px (`px-6`).
 *
 * Es el mismo 24px del `p-6` que trae `DialogContent` por default. Un modal
 * que administra su propio layout (header fijo + cuerpo scrolleable + footer)
 * NO puede verse más apretado que uno normal: el aire lateral es el mismo en
 * los dos casos. Definido acá y no en cada call-site — antes cada diálogo
 * `p-0` reponía el padding a ojo (`px-3`, `px-4`, `px-5`) y el resultado era
 * el contenido pegado al borde que reportó el owner el 2026-08-23.
 *
 * Ver `context/20-design-system.md` §4 "Modales — padding y secciones".
 */
const DIALOG_GUTTER_X = "px-6"

/**
 * Gutter para un cuerpo `flush` (tabla/listado a ancho completo): el separador
 * y el hover de la fila cruzan de lado a lado, pero el CONTENIDO de la fila
 * respeta el mismo gutter de 24px que el header. Se aplica sobre la primera y
 * la última celda de cada `<tr>`, y sobre cualquier fila no tabular que se
 * marque con `data-slot="dialog-row"`.
 *
 * Los selectores descendentes ganan por especificidad (0,2,1 vs 0,1,0), así
 * que pisan el `px-4` que las celdas ya traen sin necesidad de tocarlas.
 */
const DIALOG_FLUSH_GUTTER =
  "[&_tr>*:first-child]:pl-6 [&_tr>*:last-child]:pr-6 [&_[data-slot=dialog-row]]:px-6"

/**
 * ¿Este `DialogContent` está en modo `sectioned`? Lo consumen
 * `DialogHeader` / `DialogBody` / `DialogFooter` para reponer el padding que
 * el `p-0` del content les sacó. Context y no prop para que el call-site no
 * tenga que repetirlo en cada sección.
 */
const DialogSectionedContext = React.createContext(false)

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
 *
 * `sectioned` (OPT-IN, default false): el diálogo administra su propio layout
 * vertical — header fijo, cuerpo scrolleable, footer fijo. Reemplaza al combo
 * `className="flex flex-col gap-0 overflow-hidden p-0"` que los call-sites
 * repetían a mano, y —lo importante— hace que `DialogHeader`, `DialogBody` y
 * `DialogFooter` repongan solos el gutter de 24px. El bug que originó esto:
 * con `p-0` el padding quedaba a cargo del call-site, cada uno elegía un valor
 * distinto (`px-3`/`px-4`/`px-5`) o se olvidaba, y el contenido terminaba
 * pegado al borde (reporte del owner 2026-08-23, diálogo "Ventas pendientes de
 * sincronizar").
 *
 * NO uses `sectioned` en command palettes (`components/ui/command.tsx`, el
 * buscador de productos/clientes) ni en los shells fullscreen que montan un
 * módulo entero adentro del modal (`app/(pos)/pos/layout.tsx`,
 * `pos-main-menu`, `settings`): ahí el `p-0` es deliberado y el gutter lo pone
 * el contenido. Esos siguen pasando `p-0` por `className`.
 */
function DialogContent({
  className,
  children,
  showCloseButton = true,
  mobileFullscreen = false,
  sectioned = false,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & {
  showCloseButton?: boolean
  mobileFullscreen?: boolean
  sectioned?: boolean
}) {
  return (
    <DialogPortal>
      <DialogOverlay />
      <DialogPrimitive.Content
        data-slot="dialog-content"
        className={cn(
          "fixed top-1/2 left-1/2 z-50 grid max-h-[85dvh] w-full max-w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 gap-6 overflow-y-auto rounded-[min(var(--radius-4xl),24px)] bg-popover p-6 text-sm text-popover-foreground shadow-xl ring-1 ring-foreground/5 duration-100 outline-none sm:max-w-md dark:ring-foreground/10 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95",
          // El padding se va al header/body/footer; el scroll lo administra el
          // <DialogBody>, no el content.
          sectioned && "flex flex-col gap-0 overflow-hidden p-0",
          mobileFullscreen &&
            "max-sm:top-0 max-sm:left-0 max-sm:h-dvh max-sm:max-h-dvh max-sm:w-full max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:content-start max-sm:rounded-none",
          className
        )}
        {...props}
      >
        <DialogSectionedContext.Provider value={sectioned}>
          {children}
        </DialogSectionedContext.Provider>
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
  const sectioned = React.useContext(DialogSectionedContext)
  return (
    <div
      data-slot="dialog-header"
      className={cn(
        "flex flex-col gap-1.5",
        // En modo `sectioned` el content es `p-0`: el header repone el gutter.
        // `pb-4` y no `pb-6` porque abajo suele venir un `<Separator/>` o un
        // `border-b`, y el aire visual lo completa el `py` del cuerpo.
        sectioned && cn("shrink-0 pt-6 pb-4", DIALOG_GUTTER_X),
        className
      )}
      {...props}
    />
  )
}

/**
 * Cuerpo scrolleable de un diálogo `sectioned`. Es el que faltaba: los
 * call-sites metían un `<div className="flex-1 overflow-y-auto">` pelado y el
 * contenido quedaba a 0px del borde.
 *
 * `flush` — para tablas y listados a ancho completo. El contenedor NO lleva
 * padding lateral (así el `divide-y`, el `border-b` del header de la tabla y
 * el hover de la fila cruzan de lado a lado, que es como se ve bien un
 * listado), pero el contenido de cada fila igual respira los 24px del gutter.
 * En una tabla sale solo; en un listado de `<li>`/`<div>` marcá cada fila con
 * `data-slot="dialog-row"`.
 *
 * Fuera de un content `sectioned` no hace nada (el `p-6` del content ya puso
 * el aire), así que es seguro usarlo siempre.
 */
function DialogBody({
  className,
  flush = false,
  ...props
}: React.ComponentProps<"div"> & { flush?: boolean }) {
  const sectioned = React.useContext(DialogSectionedContext)
  return (
    <div
      data-slot="dialog-body"
      className={cn(
        sectioned && "min-h-0 flex-1 overflow-y-auto",
        sectioned &&
          (flush ? DIALOG_FLUSH_GUTTER : cn("py-4", DIALOG_GUTTER_X)),
        className
      )}
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
  const sectioned = React.useContext(DialogSectionedContext)
  return (
    <div
      data-slot="dialog-footer"
      className={cn(
        "flex flex-col-reverse gap-2 sm:flex-row sm:justify-end",
        sectioned && cn("shrink-0 pt-4 pb-6", DIALOG_GUTTER_X),
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
  DialogBody,
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
