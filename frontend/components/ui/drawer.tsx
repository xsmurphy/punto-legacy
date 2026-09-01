"use client"

import * as React from "react"
import { Drawer as DrawerPrimitive } from "vaul"

import { cn } from "@/lib/utils"
import { isolateOverlaySubmit } from "@/lib/overlay-form-isolation"

/**
 * `repositionInputs={false}`: vaul trae su propio manejo del teclado virtual
 * —mueve el drawer cuando detecta un campo enfocado— y esta app ya mide el
 * teclado UNA vez en `components/pos/keyboard-inset.tsx` y lo publica como
 * `--kb-inset`. Con los dos activos el drawer se corre dos veces y termina
 * peor que sin nada. El desplazamiento lo hace `DrawerContent` descontando la
 * variable, igual que `dialog.tsx`. Va antes del spread para que un call-site
 * pueda volver a prenderlo si alguna vez hiciera falta.
 */
function Drawer({
  ...props
}: React.ComponentProps<typeof DrawerPrimitive.Root>) {
  return (
    <DrawerPrimitive.Root data-slot="drawer" repositionInputs={false} {...props} />
  )
}

function DrawerTrigger({
  ...props
}: React.ComponentProps<typeof DrawerPrimitive.Trigger>) {
  return <DrawerPrimitive.Trigger data-slot="drawer-trigger" {...props} />
}

function DrawerPortal({
  ...props
}: React.ComponentProps<typeof DrawerPrimitive.Portal>) {
  return <DrawerPrimitive.Portal data-slot="drawer-portal" {...props} />
}

function DrawerClose({
  ...props
}: React.ComponentProps<typeof DrawerPrimitive.Close>) {
  return <DrawerPrimitive.Close data-slot="drawer-close" {...props} />
}

function DrawerOverlay({
  className,
  ...props
}: React.ComponentProps<typeof DrawerPrimitive.Overlay>) {
  return (
    <DrawerPrimitive.Overlay
      data-slot="drawer-overlay"
      className={cn(
        "fixed inset-0 z-50 bg-black/30 supports-backdrop-filter:backdrop-blur-sm data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0",
        className
      )}
      {...props}
    />
  )
}

/**
 * El `submit` de cualquier `<form>` que se monte adentro NO sale del content:
 * ver `lib/overlay-form-isolation.ts`. Mismo motivo que en `dialog.tsx` —
 * React propaga por su árbol, no por el del DOM, así que el portal del drawer
 * no impide que el form de la página que quedó atrás reciba el submit.
 */
function DrawerContent({
  className,
  children,
  onSubmit,
  ...props
}: React.ComponentProps<typeof DrawerPrimitive.Content>) {
  // El drawer bottom apoya en `bottom-0`, o sea sobre la barra de gestos del
  // teléfono: su `p-4` (16px) queda por debajo del indicador (~34px en iPhone)
  // y el último botón del actionsheet se vuelve intocable. El padding inferior
  // se resuelve acá, en el primitive, y no en cada actionsheet — son ~15 y
  // todos comparten el mismo borde. `max()` conserva el p-4 donde no hay inset.
  //
  // Esto NO se encadena con el inset del shell del POS: el drawer se portalea
  // al `<body>`, así que nunca es descendiente del shell y nadie le suma un
  // segundo `--safe-b`. Es la única aplicación del eje inferior en su árbol.
  // La regla completa está en `app/globals.css` § "Áreas seguras del
  // dispositivo".
  //
  // El bottom drawer va PEGADO a los bordes (owner, 2026-08-25: "dejan un
  // espacio entre el borde del smartphone y el drawer"). El margen no venía de
  // un `mx-*` sino del `before:inset-2`: la tarjeta que pinta el fondo estaba
  // 8px adentro por los cuatro lados. Abajo y a los costados pasa a inset-0 y
  // el redondeo queda solo arriba, que es el estándar del bottom sheet. Los
  // laterales y el `top` de los drawers `left`/`right`/`top` no se tocan: esos
  // sí son tarjetas flotantes.
  //
  // Ojo con la distinción que esto hace fácil de romper: PEGADO es el FONDO
  // llegando al borde físico. El contenido sigue corriéndose hacia adentro con
  // los insets (`pb`/`pl`/`pr` de acá abajo) — si al sacar el margen se va
  // también el inset, el último botón vuelve a caer sobre la barra de gestos.
  //
  // TECLADO VIRTUAL
  // El drawer bottom consume las mismas variables que `dialog.tsx`, así el
  // teclado deja de taparle el textarea/input (reporte del owner 2026-08-30:
  // nota de línea, descuentos, montos y numpads quedaban detrás del teclado).
  // vaul NO lo resuelve solo — por eso `repositionInputs` va apagado en el
  // root. Los tres ajustes van juntos y son de la dirección bottom nada más, y
  // cada uno usa la variable que le corresponde:
  //   - `bottom` POSICIONA → `--kb-bottom`, el borde inferior de la ventana
  //     VISIBLE. Con `--kb-inset` (arreglo 2026-09-01) el drawer se apoyaba a
  //     `inset` px del borde del layout, que es un punto muy por encima de lo
  //     que se ve cuando iOS además desplazó el viewport: el drawer de nota se
  //     salía por arriba de la pantalla. En Chrome/Android, donde no hay
  //     desplazamiento, `--kb-bottom` vale exactamente lo que valía
  //     `--kb-inset`, así que ese caso no cambia.
  //   - `max-h` DIMENSIONA → `--kb-inset`, el total tapado: un drawer alto se
  //     saldría por arriba y el `min()` lo recorta contra el alto visible.
  //   - `pb` pregunta "¿hay teclado?" → `--kb-inset`, la única que lo responde
  //     en las dos plataformas. Con el teclado abierto la barra de gestos ya
  //     no está entre el drawer y el dedo, así que el `--safe-b` se descuenta;
  //     el `max(1rem,…)` sigue siendo el piso. Usar `--kb-bottom` acá sería el
  //     error: en el iPhone vale 0 con el teclado ARRIBA (todo lo tapado está
  //     del lado de `--kb-top`) y dejaría 34px muertos sobre el teclado.
  // Las direcciones left/right/top también se apoyan en el par (antes
  // `inset-y-0` / `top-0`): son `fixed` contra el viewport igual que la de
  // bottom, y un borde en el layout viewport queda fuera de pantalla con el
  // teclado abierto. Fuera del POS el par vale 0 y el resultado es idéntico a
  // `inset-y-0`; el `h-auto` de la clase base es lo que deja que los dos
  // bordes definan el alto (caja sobre-restringida — ver `app/globals.css`).
  // La transición es solo de `bottom` porque vaul anima `transform` inline
  // (arrastre y apertura): son propiedades distintas y no se pisan.
  // Fuera del POS las tres valen `0px` (defaults en `globals.css`) y las
  // expresiones colapsan a lo de siempre: nada de esto se ve en desktop ni en
  // el panel.
  return (
    <DrawerPortal data-slot="drawer-portal">
      <DrawerOverlay />
      <DrawerPrimitive.Content
        data-slot="drawer-content"
        className={cn(
          "group/drawer-content fixed z-50 flex h-auto flex-col bg-transparent p-4 text-sm before:absolute before:inset-2 before:-z-10 before:rounded-[min(var(--radius-4xl),24px)] before:border before:border-border before:bg-popover before:shadow-xl data-[vaul-drawer-direction=bottom]:inset-x-0 data-[vaul-drawer-direction=bottom]:bottom-[var(--kb-bottom)] data-[vaul-drawer-direction=bottom]:mt-24 data-[vaul-drawer-direction=bottom]:max-h-[min(80vh,calc(100dvh-var(--kb-inset)-2rem))] data-[vaul-drawer-direction=bottom]:transition-[bottom] data-[vaul-drawer-direction=bottom]:duration-200 data-[vaul-drawer-direction=bottom]:before:inset-x-0 data-[vaul-drawer-direction=bottom]:before:bottom-0 data-[vaul-drawer-direction=bottom]:before:rounded-b-none data-[vaul-drawer-direction=bottom]:pb-[max(1rem,calc(var(--safe-b)-var(--kb-inset)))] data-[vaul-drawer-direction=bottom]:pl-[max(1rem,var(--safe-l))] data-[vaul-drawer-direction=bottom]:pr-[max(1rem,var(--safe-r))] data-[vaul-drawer-direction=left]:top-[var(--kb-top)] data-[vaul-drawer-direction=left]:bottom-[var(--kb-bottom)] data-[vaul-drawer-direction=left]:left-0 data-[vaul-drawer-direction=left]:w-3/4 data-[vaul-drawer-direction=right]:top-[var(--kb-top)] data-[vaul-drawer-direction=right]:bottom-[var(--kb-bottom)] data-[vaul-drawer-direction=right]:right-0 data-[vaul-drawer-direction=right]:w-3/4 data-[vaul-drawer-direction=top]:inset-x-0 data-[vaul-drawer-direction=top]:top-[var(--kb-top)] data-[vaul-drawer-direction=top]:mb-24 data-[vaul-drawer-direction=top]:max-h-[80vh] data-[vaul-drawer-direction=left]:sm:max-w-sm data-[vaul-drawer-direction=right]:sm:max-w-sm",
          className
        )}
        {...props}
        onSubmit={isolateOverlaySubmit(onSubmit)}
      >
        {/* Grabber REAL (`DrawerPrimitive.Handle`), no un div decorativo: vaul
            necesita el `data-vaul-handle` que emite este componente para saber
            qué elemento arrastra el drawer, y sin él la prop `handleOnly` de
            un call-site no tiene efecto (por eso antes se recurría a marcar
            zonas con `data-vaul-no-drag`). Con el handle real, un drawer que
            declara `handleOnly` deja de cerrarse por un tap suelto en el
            contenido y se arrastra desde la barrita, como el resto
            (reporte del owner 2026-08-01, actionsheet de línea del carrito). */}
        <DrawerPrimitive.Handle className="mx-auto mt-4 hidden h-1.5 w-[100px] shrink-0 rounded-full bg-muted group-data-[vaul-drawer-direction=bottom]/drawer-content:block" />
        {children}
      </DrawerPrimitive.Content>
    </DrawerPortal>
  )
}

function DrawerHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="drawer-header"
      className={cn(
        "flex flex-col gap-0.5 p-4 group-data-[vaul-drawer-direction=bottom]/drawer-content:text-center group-data-[vaul-drawer-direction=top]/drawer-content:text-center md:gap-1.5 md:text-left",
        className
      )}
      {...props}
    />
  )
}

function DrawerFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="drawer-footer"
      className={cn("mt-auto flex flex-col gap-2 p-4", className)}
      {...props}
    />
  )
}

function DrawerTitle({
  className,
  ...props
}: React.ComponentProps<typeof DrawerPrimitive.Title>) {
  return (
    <DrawerPrimitive.Title
      data-slot="drawer-title"
      className={cn(
        "font-heading text-base font-medium text-foreground",
        className
      )}
      {...props}
    />
  )
}

function DrawerDescription({
  className,
  ...props
}: React.ComponentProps<typeof DrawerPrimitive.Description>) {
  return (
    <DrawerPrimitive.Description
      data-slot="drawer-description"
      className={cn("text-sm text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Drawer,
  DrawerPortal,
  DrawerOverlay,
  DrawerTrigger,
  DrawerClose,
  DrawerContent,
  DrawerHeader,
  DrawerFooter,
  DrawerTitle,
  DrawerDescription,
}
