"use client"

/**
 * ResponsiveDialog — Dialog centrado en desktop, bottom drawer en mobile/tablet.
 *
 * Implementa el refinamiento de `context/14-ui-conventions.md` §2.2 (owner,
 * 2026-08-01): los modales CHICOS DE INTERACCIÓN del POS se abren de abajo
 * hacia arriba bajo el breakpoint, y siguen siendo Dialog centrado —idéntico a
 * hoy— arriba de él. El Drawer LATERAL para contenido denso sigue prohibido;
 * este wrapper es la ÚNICA forma legítima de usar el Drawer inferior: NUNCA
 * importar `@/components/ui/drawer` desde un call-site.
 *
 * CUÁNDO USARLO
 *   Modales chicos de interacción: descuento (global y por línea), numpads
 *   (cantidad, apertura/cierre de caja, movimientos), nota de venta,
 *   etiquetas, lista de precios, título de venta guardada.
 *
 * CUÁNDO NO
 *   - Modales de CONTENIDO / listados / módulos de ruta del POS →
 *     `<Dialog>` con `mobileFullscreen` (ver docstring de dialog.tsx).
 *   - Command palettes / buscadores flotantes top-aligned → `<Dialog>` pelado.
 *   - Paneles laterales densos → PROHIBIDO (anti-patrón vigente §2.2).
 *   - Confirmaciones → `<AlertDialog>` (primitive distinto; todavía no tiene
 *     variante responsive).
 *
 * API
 *   Espejo de `components/ui/dialog.tsx`. Cambiar los imports `Dialog*` por
 *   `ResponsiveDialog*` alcanza para migrar un call-site; el contenido no se
 *   toca (los children del POS ya traen sus touch targets `size="lg"`, este
 *   wrapper no re-estiliza nada adentro).
 *
 *   `className` en `ResponsiveDialogContent` se aplica SOLO a la rama Dialog:
 *   lo que pasan los call-sites es ancho/posición/padding de una caja centrada
 *   (`sm:max-w-md`, `max-w-sm`, `p-0`) y nada de eso tiene sentido en un drawer
 *   que ocupa el ancho completo y administra su propio layout. Para tocar la
 *   rama drawer existe `drawerClassName`.
 *
 * TECLADO VIRTUAL
 *   vaul reposiciona el drawer solo cuando el teclado se abre. No agregar
 *   hacks de `visualViewport` acá ni en los call-sites.
 */

import * as React from "react"

import { cn } from "@/lib/utils"
import { useIsMobile } from "@/hooks/use-mobile"
import {
  Dialog,
  DialogBody,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import {
  Drawer,
  DrawerClose,
  DrawerContent,
  DrawerDescription,
  DrawerFooter,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
} from "@/components/ui/drawer"

/**
 * Breakpoint del cambio Dialog ↔ Drawer. Hoy delega en `useIsMobile`
 * (768px, `hooks/use-mobile.ts`): cubre teléfonos y tablets chicas en
 * portrait. Si el owner quiere que las tablets grandes también abran de
 * abajo, este es el ÚNICO lugar a tocar — cambiar por un `useMediaQuery`
 * propio con el ancho nuevo.
 */
function useIsBottomDrawerViewport() {
  return useIsMobile()
}

const ResponsiveDialogContext = React.createContext(false)

function useIsDrawerBranch() {
  return React.useContext(ResponsiveDialogContext)
}

function ResponsiveDialog({
  children,
  ...props
}: React.ComponentProps<typeof Dialog>) {
  const isDrawer = useIsBottomDrawerViewport()
  const Root = isDrawer ? Drawer : Dialog

  return (
    <ResponsiveDialogContext.Provider value={isDrawer}>
      <Root data-slot="responsive-dialog" {...props}>
        {children}
      </Root>
    </ResponsiveDialogContext.Provider>
  )
}

function ResponsiveDialogTrigger(
  props: React.ComponentProps<typeof DialogTrigger>
) {
  const Trigger = useIsDrawerBranch() ? DrawerTrigger : DialogTrigger
  return <Trigger {...props} />
}

function ResponsiveDialogClose(
  props: React.ComponentProps<typeof DialogClose>
) {
  const Close = useIsDrawerBranch() ? DrawerClose : DialogClose
  return <Close {...props} />
}

function ResponsiveDialogContent({
  className,
  drawerClassName,
  showCloseButton = true,
  sectioned = false,
  children,
  ...props
}: React.ComponentProps<typeof DialogContent> & {
  /** Clases para la rama drawer. `className` va SOLO a la rama Dialog. */
  drawerClassName?: string
}) {
  const isDrawer = useIsDrawerBranch()

  if (isDrawer) {
    return (
      <DrawerContent
        data-slot="responsive-dialog-content"
        // `mx-auto max-w-lg`: en tablets chicas el drawer a ancho completo se
        // ve estirado; centrado y acotado matchea el drawer de opciones del POS.
        className={cn("mx-auto max-w-lg", drawerClassName)}
        {...props}
      >
        {/* GUTTER DE LA RAMA DRAWER (2026-08-23).
            `DrawerContent` trae `p-4`, pero el panel visible se dibuja con
            `before:inset-2` — o sea que esos 16px arrancan 8px afuera del
            borde que ve el usuario y el contenido queda a 8px del borde. Los
            16px de este wrapper completan los 24px visibles: el MISMO gutter
            que la rama Dialog. Va acá y no en cada slot porque el cuerpo de
            estos modales son children pelados, sin componente propio.

            `sectioned` lo saltea: ese call-site declara que administra su
            propio padding (ej. NumericPadDialog, con header/body/footer
            `px-6` propios) y sumarle el wrapper lo dejaría con doble aire. */}
        {sectioned ? (
          children
        ) : (
          <div className="flex min-h-0 flex-1 flex-col px-4 pb-4">
            {children}
          </div>
        )}
      </DrawerContent>
    )
  }

  return (
    <DialogContent
      data-slot="responsive-dialog-content"
      className={className}
      showCloseButton={showCloseButton}
      sectioned={sectioned}
      {...props}
    >
      {children}
    </DialogContent>
  )
}

/**
 * Cuerpo del modal. En la rama Dialog delega en `<DialogBody>` (que solo pone
 * padding si el content es `sectioned`); en la rama drawer no hace falta
 * padding lateral porque lo puso el wrapper de `ResponsiveDialogContent`.
 */
function ResponsiveDialogBody({
  className,
  flush,
  ...props
}: React.ComponentProps<typeof DialogBody>) {
  const isDrawer = useIsDrawerBranch()

  if (isDrawer) {
    return (
      <div
        data-slot="responsive-dialog-body"
        className={cn("min-h-0 flex-1 overflow-y-auto", className)}
        {...props}
      />
    )
  }
  return <DialogBody className={className} flush={flush} {...props} />
}

function ResponsiveDialogHeader({
  className,
  ...props
}: React.ComponentProps<"div">) {
  const isDrawer = useIsDrawerBranch()

  if (isDrawer) {
    // `text-left`: el DrawerHeader de shadcn centra el texto bajo `md`, pero
    // los modales migrados vienen del Dialog (alineado a la izquierda) y no
    // deben cambiar de aspecto al cruzar el breakpoint.
    return <DrawerHeader className={cn("px-0 text-left", className)} {...props} />
  }
  return <DialogHeader className={className} {...props} />
}

function ResponsiveDialogFooter({
  className,
  ...props
}: React.ComponentProps<typeof DialogFooter>) {
  const isDrawer = useIsDrawerBranch()

  if (isDrawer) {
    // `flex-col-reverse`: preserva el orden que ya tenía el DialogFooter bajo
    // `sm` (acción primaria arriba, cancelar abajo).
    return (
      <DrawerFooter
        className={cn("flex-col-reverse px-0 pb-0", className)}
        {...props}
      />
    )
  }
  return <DialogFooter className={className} {...props} />
}

function ResponsiveDialogTitle(
  props: React.ComponentProps<typeof DialogTitle>
) {
  const Title = useIsDrawerBranch() ? DrawerTitle : DialogTitle
  return <Title {...props} />
}

function ResponsiveDialogDescription(
  props: React.ComponentProps<typeof DialogDescription>
) {
  const Description = useIsDrawerBranch()
    ? DrawerDescription
    : DialogDescription
  return <Description {...props} />
}

export {
  ResponsiveDialog,
  ResponsiveDialogBody,
  ResponsiveDialogClose,
  ResponsiveDialogContent,
  ResponsiveDialogDescription,
  ResponsiveDialogFooter,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
  ResponsiveDialogTrigger,
}
