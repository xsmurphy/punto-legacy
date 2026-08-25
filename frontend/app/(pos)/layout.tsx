import * as React from "react"
import { SidebarInset } from "@/components/ui/sidebar"
import { PosSidebarProvider } from "@/components/layout/pos-sidebar-provider"
import { PosAuthGuard } from "@/components/layout/pos-auth-guard"
import { PosSidebar } from "@/components/layout/pos-sidebar"
import { PosModeDialog } from "@/components/register/pos-mode-dialog"
import { InstallPrompt } from "@/components/pos/install-prompt"
import { ChunkErrorListener } from "@/components/pos/chunk-error-listener"
import { PosTouchScope } from "@/components/pos/pos-touch-scope"
import { PosConfigSync } from "@/lib/pos/config-sync"

/**
 * Layout del POS — auth via _jwt (device cookie, 10 años), NO _jwt_panel.
 * PosAuthGuard muestra <DeviceNotConnected /> si no hay cookie _jwt válida
 * (el viejo /pos-pair fue eliminado; el pairing nuevo es invitation-based
 * vía /connect/[id] generado por el admin desde /settings/devices).
 *
 * PosSidebar es un sidebar mínimo (Caja / Guardadas / Bloquear) sin los
 * módulos del panel.
 */
export default function PosLayout({ children }: { children: React.ReactNode }) {
  return (
    <PosSidebarProvider>
      <PosAuthGuard>
        <ChunkErrorListener />
        {/* Marca `<html>` mientras el POS está montado. `pos-scope` (abajo)
            solo alcanza al árbol del shell, y TODO lo que se portalea
            —diálogos, drawers, dropdowns, toasts— cuelga del `<body>`, fuera
            de él: por eso los controles del cobro o del menú no recibían las
            reglas táctiles de la caja. La marca va en la raíz para que las
            alcance a todas. */}
        <PosTouchScope />
        <PosConfigSync />
        <PosSidebar />
        {/* `pos-scope`: globals.css aplica typography táctil (font-size,
            weight, tracking) a inputs/textareas descendientes. Cajero ve
            grande sin tocar cada caller individualmente. */}
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
        <SidebarInset className="pos-scope h-svh overflow-hidden pt-[var(--safe-t)] pl-[var(--safe-l)] pr-[var(--safe-r)] md:h-[calc(100svh-1rem)]">
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
          <InstallPrompt />
        </SidebarInset>
      </PosAuthGuard>
    </PosSidebarProvider>
  )
}
