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
        {children}
      </DrawerContent>
    )
  }

  return (
    <DialogContent
      data-slot="responsive-dialog-content"
      className={className}
      showCloseButton={showCloseButton}
      {...props}
    >
      {children}
    </DialogContent>
  )
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
  ResponsiveDialogClose,
  ResponsiveDialogContent,
  ResponsiveDialogDescription,
  ResponsiveDialogFooter,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
  ResponsiveDialogTrigger,
}
