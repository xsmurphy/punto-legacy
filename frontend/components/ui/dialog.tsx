"use client"

import * as React from "react"
import { Dialog as DialogPrimitive } from "radix-ui"

import { cn } from "@/lib/utils"
import { isolateOverlaySubmit } from "@/lib/overlay-form-isolation"
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
 * TECLADO VIRTUAL (`--kb-top` / `--kb-bottom` / `--kb-inset`, medidas por
 * `components/pos/keyboard-inset.tsx`) — entra en la misma cuenta que las
 * áreas seguras, y por la misma razón: `dvh` mide el viewport de LAYOUT, que
 * en iOS NO se achica con el teclado abierto. Sin esto, un diálogo centrado se
 * sigue centrando contra la pantalla entera y con el teclado arriba queda medio
 * modal —el campo enfocado incluido— atrás del teclado (reporte del owner
 * 2026-08-25 sobre el buscador de usuarios). Se usa en DOS lugares, con
 * variables distintas porque hacen cosas distintas:
 *
 *   · el `max-h` DIMENSIONA → `--kb-inset` (el total tapado). El alto visible
 *     es `layout - total tapado`, sin importar cómo se reparta.
 *   · el `top` POSICIONA → necesita además `--kb-top`. `calc(50% - inset/2)`
 *     da el centro del hueco medido desde el borde del LAYOUT; el hueco no
 *     empieza ahí. Con la medición del owner (layout 797, visible [356, 797]):
 *     `50% - 356/2` = 220, que está fuera de pantalla por arriba — el modal
 *     "corrido hacia arriba" del 2026-08-31. Sumando `--kb-top`: 356 + 220 =
 *     576, el centro real de lo visible, y el `-translate-y-1/2` lo apoya ahí.
 *
 * `50%` es exactamente `100dvh/2` para un `fixed` (resuelve contra el bloque
 * contenedor inicial), así que la expresión es la misma que
 * `var(--kb-top) + (100dvh - var(--kb-inset))/2` y no depende de que `dvh`
 * coincida con `clientHeight` en un transitorio.
 *
 * Donde no hay teclado las tres valen 0 y la geometría es la de siempre — el
 * panel y el desktop no cambian un pixel.
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
 * aligned con fondo transparente) tampoco lo usan. Bajo `sm` el layout pasa
 * de `grid` a `flex flex-col`: los slots conservan su alto natural y el
 * cuerpo toma el resto con `flex-1`, sin la fila content-sized del grid que
 * dejaba una franja de `bg-popover` contra el borde inferior. Registrado en
 * context/20-design-system.md §10.
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
 * El `submit` de cualquier `<form>` que se monte adentro NO sale del content:
 * ver `lib/overlay-form-isolation.ts` (React propaga por su árbol, no por el
 * del DOM — el portal no aísla nada). Es default del primitive, no opt-in.
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
  onSubmit,
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
          // `max-h`: el 85dvh de siempre, pero nunca más alto que el área
          // segura. Un diálogo centrado no se puede recortar contra el status
          // bar: con `viewport-fit=cover` el 50% del viewport NO es el 50% del
          // área útil, y los diálogos altos (el de cobro pedía 90vh) se comían
          // el notch. Donde los insets valen 0 el `min()` devuelve 85dvh y el
          // desktop queda idéntico.
          "fixed top-[calc(var(--kb-top)+50%-var(--kb-inset)/2)] left-1/2 z-50 grid max-h-[min(85dvh,calc(100dvh-2rem-var(--safe-t)-var(--safe-b)-var(--kb-inset)))] w-full max-w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 gap-6 overflow-y-auto rounded-[min(var(--radius-4xl),24px)] bg-popover p-6 text-sm text-popover-foreground shadow-xl ring-1 ring-foreground/5 duration-100 outline-none sm:max-w-md dark:ring-foreground/10 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95",
          // El padding se va al header/body/footer; el scroll lo administra el
          // <DialogBody>, no el content.
          sectioned && "flex flex-col gap-0 overflow-hidden p-0",
          mobileFullscreen &&
            cn(
              // `inset-0` + `h-auto` en vez de `h-dvh`: el alto lo define la
              // caja (top:0 y bottom:0), no una unidad de viewport. Con
              // `viewport-fit=cover` las unidades vh/dvh son justo lo que
              // cambia de valor según el chrome del sistema, y cualquier
              // diferencia de un par de píxeles deja una franja del overlay
              // asomando contra el borde inferior — el "no baja hasta el final
              // de la pantalla" que reportó el owner (2026-08-25).
              // `top/right/left/bottom` explícitos en vez de `inset-0`: los
              // dos bordes verticales se apoyan en la ventana visible cuando
              // hay teclado (`--kb-top` / `--kb-bottom`), y en los bordes
              // físicos cuando no lo hay. Con `inset-0` + utilidades sueltas el
              // resultado dependía del orden en que Tailwind las emite.
              //
              // El par y no `--kb-inset`: con `top-0` el fullscreen arrancaba
              // en el borde del LAYOUT, que con el teclado abierto está fuera
              // de pantalla por arriba (medición del owner: lo visible es
              // [356, 797] de 797). El alto resultante es el mismo; lo que
              // faltaba era el origen.
              "max-sm:top-[var(--kb-top)] max-sm:right-0 max-sm:bottom-[var(--kb-bottom)] max-sm:left-0 max-sm:h-auto max-sm:max-h-none max-sm:w-auto max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none",
              // `flex flex-col` y NO el `grid` del modal centrado: un
              // fullscreen tiene alto definido (top:0 + bottom en el teclado o
              // el borde), así que el reparto de ese alto es el trabajo del
              // layout. Con `grid` + `content-start` la fila se dimensiona al
              // CONTENIDO —un `h-full` o un `flex-1` adentro no tienen contra
              // qué resolver— y lo que sobra hasta el borde inferior queda
              // pintado con el `bg-popover` del diálogo: el "no baja hasta el
              // final de la pantalla" que reportó el owner (2026-08-25) en el
              // menú del POS. Con `align-content: stretch` el problema es el
              // inverso: header y footer se estiran. Flex column resuelve los
              // dos casos —los slots conservan su alto natural y el cuerpo
              // toma el resto con `flex-1`— y es el mismo layout que ya usaban
              // a mano el shell de módulos (`app/(pos)/pos/layout.tsx`) y el
              // diálogo de transacciones.
              "max-sm:flex max-sm:flex-col",
              // Fullscreen = la superficie toca los cuatro bordes del
              // dispositivo, y además se portalea FUERA del shell del POS, así
              // que no hereda ningún inset: los descuenta acá, una sola vez,
              // sumados al gutter de 24px del modal. Un diálogo que reemplaza
              // este padding (los shells `p-0` que montan un módulo entero con
              // chrome propio) tiene que resetearlo con `max-sm:p-0` y
              // descontar los insets en SU chrome — ver `pos-main-menu.tsx` y
              // `pos-transactions-dialog.tsx`.
              "max-sm:pt-[calc(1.5rem+var(--safe-t))] max-sm:pr-[calc(1.5rem+var(--safe-r))] max-sm:pb-[calc(1.5rem+var(--safe-b))] max-sm:pl-[calc(1.5rem+var(--safe-l))]",
            ),
          className
        )}
        {...props}
        onSubmit={isolateOverlaySubmit(onSubmit)}
      >
        <DialogSectionedContext.Provider value={sectioned}>
          {children}
        </DialogSectionedContext.Provider>
        {showCloseButton && (
          <DialogPrimitive.Close data-slot="dialog-close" asChild>
            <Button
              variant="ghost"
              className={cn(
                "absolute top-4 right-4 bg-secondary",
                // En un modal fullscreen ese `top-4` son 16px desde el BORDE
                // FÍSICO del teléfono, o sea adentro de los ~47px del status
                // bar: la X quedaba tapada por el reloj y, peor, no recibía el
                // toque. Como en varios módulos del POS la X es la ÚNICA
                // salida, el cajero quedaba encerrado (reporte del owner
                // 2026-08-25: "entro al módulo de órdenes y ya no puedo
                // volver"). En un modal centrado el `top-4` es relativo a la
                // caja del modal, que nunca toca el borde, y por eso el ajuste
                // va SOLO en la rama fullscreen.
                mobileFullscreen &&
                  "max-sm:top-[calc(1rem+var(--safe-t))] max-sm:right-[calc(1rem+var(--safe-r))]",
              )}
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
