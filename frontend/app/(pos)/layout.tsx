import * as React from "react"
import type { Viewport } from "next"
import { SidebarInset } from "@/components/ui/sidebar"
import { PosSidebarProvider } from "@/components/layout/pos-sidebar-provider"
import { PosAuthGuard } from "@/components/layout/pos-auth-guard"
import { PosSidebar } from "@/components/layout/pos-sidebar"
import { PosModeDialog } from "@/components/register/pos-mode-dialog"
import { InstallPrompt } from "@/components/pos/install-prompt"
import { ChunkErrorListener } from "@/components/pos/chunk-error-listener"
import { PosTouchScope } from "@/components/pos/pos-touch-scope"
import { PosKeyboardInset } from "@/components/pos/keyboard-inset"
import { SafeAreaCalibrator } from "@/components/pos/safe-area-calibrator"
import { PosConfigSync } from "@/lib/pos/config-sync"
import { PosAgentChat } from "@/components/pos/pos-agent-chat"

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
 * ALTURA DEL SHELL: `h-dvh`. NO usar `h-full` acá — se probó el 2026-08-26 y
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
        {/* Publica `--kb-inset` (lo que tapa el teclado virtual) para que los
            modales con búsqueda no queden detrás del teclado en el teléfono.
            Ver el docblock de `components/pos/keyboard-inset.tsx`. */}
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
        <SidebarInset className="h-dvh overflow-hidden pt-[var(--safe-t)] pl-[var(--safe-l)] pr-[var(--safe-r)] md:h-[calc(100dvh-1rem)]">
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
          {/* Asistente IA — mismo motivo que PosModeDialog para vivir acá: su
              trigger está en el sidebar, que en mobile es un Sheet que se
              cierra al tocarlo. Ver el docblock del componente. */}
          <PosAgentChat />
          <InstallPrompt />
        </SidebarInset>
      </PosAuthGuard>
    </PosSidebarProvider>
  )
}
