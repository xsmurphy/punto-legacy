import * as React from "react"
import type { Viewport } from "next"
import { SidebarInset } from "@/components/ui/sidebar"
import { PosSidebarProvider } from "@/components/layout/pos-sidebar-provider"
import { PosAuthGuard } from "@/components/layout/pos-auth-guard"
import { PosSidebar } from "@/components/layout/pos-sidebar"
import { PosModeDialog } from "@/components/register/pos-mode-dialog"
import { PosAgentDialog } from "@/components/pos/pos-agent-dialog"
import { InstallPrompt } from "@/components/pos/install-prompt"
import { ChunkErrorListener } from "@/components/pos/chunk-error-listener"
import { PosTouchScope } from "@/components/pos/pos-touch-scope"
import { PosKeyboardInset } from "@/components/pos/keyboard-inset"
import { SafeAreaCalibrator } from "@/components/pos/safe-area-calibrator"
import { PosConfigSync } from "@/lib/pos/config-sync"

/**
 * Layout del POS — auth con el Bearer del DEVICE (`lib/auth/device-token.ts`,
 * en localStorage, sin expiración), nunca con la credencial del panel. No hay
 * cookies en juego: el panel también es Bearer desde context/54, y cada uno
 * tiene su propia clave de storage.
 * PosAuthGuard muestra <DeviceNotConnected /> si no hay token de device válido
 * (el viejo /pos-pair fue eliminado; el pairing nuevo es invitation-based
 * vía /connect/[id] generado por el admin desde /settings/devices).
 *
 * PosSidebar es un sidebar mínimo (Caja / Guardadas / Bloquear) sin los
 * módulos del panel.
 */
/**
 * Viewport del POS: pisa el del root layout para FIJAR la escala.
 *
 * El root deja `userScalable` en default (zoom permitido) por accesibilidad y
 * eso es correcto para el panel y el sitio. En la CAJA no: iOS auto-zoomea al
 * enfocar campos menores a 16px y el pinch accidental deja la pantalla
 * zoomeada en medio de una venta — el owner lo reportó explícitamente
 * (2026-08-25, "se queda todo zoomeado"). La caja es una superficie de app,
 * no un documento: escala fija. La legibilidad se resuelve con los tamaños
 * táctiles del propio POS (`[data-pos-touch]`), no con zoom del navegador.
 *
 * ALTURA DEL SHELL: `dvh` menos `--kb-inset` (el detalle, en el JSX de abajo).
 * NO usar `h-full` acá — se probó el 2026-08-26 y
 * COLAPSA: el wrapper de `SidebarProvider` declara `min-h-svh` (un mínimo, no
 * una altura), así que un `height: 100%` del hijo no tiene contra qué resolver
 * y el shell termina midiendo solo su contenido. El gap del iPhone sigue
 * abierto y NO se cierra por acá: aparece también en overlays `fixed inset-0`,
 * o sea que es el viewport de la app el que mide menos que la pantalla. Para
 * diagnosticarlo está `?debug=viewport` (components/pos/viewport-probe.tsx).
 */
export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  maximumScale: 1,
  userScalable: false,
  viewportFit: "cover",
  themeColor: "#0a0a0a",
}

export default function PosLayout({ children }: { children: React.ReactNode }) {
  return (
    <PosSidebarProvider>
      <PosAuthGuard>
        <ChunkErrorListener />
        {/* Marca `<html>` mientras el POS está montado. De ahí cuelgan TODAS
            las reglas propias de la caja (typography de los campos y mínimo
            táctil, en `app/globals.css`): una clase en el shell no alcanzaba
            porque lo que se portalea —diálogos, drawers, dropdowns, toasts—
            cuelga del `<body>`, fuera de ese árbol, y justo el cobro y el menú
            principal quedaban sin las reglas. */}
        <PosTouchScope />
        {/* Publica la ventana visible del viewport mientras el teclado virtual
            está abierto (`--kb-top` / `--kb-bottom` / `--kb-inset`) para que
            los modales con búsqueda no queden ni detrás del teclado ni fuera
            de pantalla por arriba en el teléfono. Ver el docblock de
            `components/pos/keyboard-inset.tsx`. */}
        <PosKeyboardInset />
        {/* Anula `--safe-t`/`--safe-b` cuando el viewport NO cubre la pantalla:
            ahí el chrome del sistema ya reservó esa franja y descontarla otra
            vez la cuenta dos veces (ver docblock del componente). */}
        <SafeAreaCalibrator />
        <PosConfigSync />
        <PosSidebar />
        {/* Áreas seguras del dispositivo — ver la regla completa en
            `app/globals.css` (§ "Áreas seguras del dispositivo").

            El shell se queda con el eje SUPERIOR y los LATERALES: es el
            elemento más externo que pinta fondo contra esos bordes, y sin él
            la toolbar del carrito queda debajo del reloj y la batería en un
            iPhone instalado como PWA.

            El eje INFERIOR ya NO se descuenta acá. Lo hacía (commit 0d14f91b,
            `safe-area` en los cuatro lados) y se sumaba al `p-2` propio de la
            barra del CTA: el botón de cobrar terminaba flotando ~42px sobre el
            borde en vez de apoyar en el límite del área segura, que es lo que
            el owner reportó como "demasiado arriba". El inferior vive ahora en
            `CartBottom` (`components/register/cart-panel.tsx`), que es el
            elemento que realmente apoya en ese borde, y lo combina con su
            propio padding vía `max()` — así en desktop y en tablets sin notch,
            donde el inset es 0, la geometría no cambia ni un pixel.

            En `md` el shell es una tarjeta flotante (`m-2` del primitive):
            su FONDO puede quedar debajo del status bar sin problema —
            justamente eso es lo que se ve como app— y el mismo padding
            alcanza para que el CONTENIDO lo esquive. Donde el inset es 0
            (desktop, tablets sin notch) las tres declaraciones valen 0 y no
            cambia nada. */}
        {/* ALTO DEL SHELL — el teclado virtual se descuenta ACÁ.

            Este consumidor DIMENSIONA, así que usa `--kb-inset` (el total
            tapado) y no el par `--kb-top`/`--kb-bottom`: el alto visible es
            `layout - total tapado`, sin importar cómo se reparta entre arriba
            y abajo. La POSICIÓN ya la resuelve el body fijado de
            `globals.css`, que es de quien el shell cuelga en flujo normal.

            Hace falta igual porque `dvh` mide el viewport de LAYOUT y el
            teclado no lo achica (en iOS se dibuja encima; ver el docblock de
            `keyboard-inset.tsx`): achicar el body no cambia cuánto mide
            `100dvh`. Sin esta resta el shell seguiría midiendo la pantalla
            entera y su mitad de abajo quedaría fuera del área visible — que es
            exactamente el síntoma que el owner vio "en muchas cosas del POS"
            (2026-08-30).

            Repetir el descuento acá NO es el doble-descuento que prohíbe la
            regla de áreas seguras: no son dos restas encadenadas sobre la
            misma caja, son dos cajas —la lámina del documento y el shell—
            midiendo el mismo espacio visible. Encadenarlo sería que un hijo
            del shell volviera a restar; eso no pasa: los demás consumidores
            (dialog, drawer, sheet, lock screen, los command palettes) se
            posicionan `fixed` contra el viewport, fuera de este árbol de
            layout.

            Con el teclado cerrado las variables valen `0px` y las dos
            expresiones colapsan a lo de siempre. */}
        <SidebarInset className="h-[calc(100dvh-var(--kb-inset))] overflow-hidden pt-[var(--safe-t)] pl-[var(--safe-l)] pr-[var(--safe-r)] md:h-[calc(100dvh-1rem-var(--kb-inset))]">
          {/* El trigger mobile del nav de módulos vivía acá como FAB flotante
              abajo a la derecha. Se movió al extremo izquierdo del toolbar del
              carrito (CartToolbar), junto al botón del menú principal, por
              pedido del owner (2026-08-01). Se movió, no se duplicó: dos
              triggers para la misma nav es ruido en una pantalla de teléfono.
              Todas las rutas del grupo (pos) cuelgan de /pos y montan el
              CartPanel, así que el trigger sigue presente en todas. */}
          {children}
          {/* Selector de modo — montado en el layout (no en el sidebar) para
              sobrevivir al cierre del Sheet mobile que contiene su trigger. */}
          <PosModeDialog />
          {/* Asistente IA de la caja (context/59) — montado acá por el MISMO
              motivo que PosModeDialog: su trigger es un item del footer del
              sidebar, que en mobile es un drawer y se desmonta al tocarlo. */}
          <PosAgentDialog />
          <InstallPrompt />
        </SidebarInset>
      </PosAuthGuard>
    </PosSidebarProvider>
  )
}
