"use client"

/**
 * Layout del workspace de la caja.
 *
 * El bloque DERECHO (carrito / venta) es persistente — vive en este layout,
 * así que se mantiene montado (y conserva su estado) mientras el bloque
 * IZQUIERDO cambia según la ruta:
 *   /pos            → grilla de hotkeys (ProductArea)
 *   /pos/espacios   → módulo Espacios
 *   /pos/ordenes    → módulo Órdenes
 *   /pos/calendario → módulo Calendario
 *
 * Auth: el outletId+registerId del device viven en la fila `device`, decididos
 * por el admin al generar el link de invitación. NO hay selector runtime — el
 * device opera siempre con el contexto fijo de su pairing. Para cambiar caja,
 * el admin revoca el device y genera un link nuevo (o el operador lo cambia
 * desde Ajustes del POS, slice futuro).
 *
 * Responsive: en mobile el carrito ocupa la pantalla entera y el bloque
 * izquierdo NO se pinta en la grilla — los módulos de ruta se abren como modal
 * fullscreen por encima del carrito (ver MODULE_TITLES abajo). Antes quedaban
 * simplemente ocultos: en un teléfono, navegar a /pos/espacios mostraba el
 * carrito y nada más (reporte del owner 2026-08-01).
 */

/**
 * Rutas hijas que son "módulos": en mobile se muestran dentro de un Dialog
 * fullscreen. `/pos` (la grilla de hotkeys) NO está acá — es la home del
 * workspace y en mobile su lugar lo ocupa el carrito; se abre como módulo bajo
 * demanda vía query param (ver `wantsHotkeysModule`).
 *
 * El título es para el DialogTitle (a11y): el módulo trae su propio header
 * visual, así que el del Dialog va sr-only.
 */
const MODULE_TITLES: Record<string, string> = {
  "/pos/ordenes": "Órdenes",
  "/pos/espacios": "Espacios",
  "/pos/calendario": "Calendario",
  "/pos/guardadas": "Ventas guardadas",
  "/pos/transactions": "Transacciones",
}

function moduleTitleFor(pathname: string): string | null {
  if (pathname === "/pos") return null
  const key = Object.keys(MODULE_TITLES).find((p) => pathname.startsWith(p))
  return key ? MODULE_TITLES[key] : "Módulo"
}

/**
 * En `/pos` la grilla de hotkeys también se abre como módulo-modal en mobile,
 * pedida por query param. Dos params la piden y AMBOS valen:
 *   ?view=hotkeys   → el item "HotKeys" del nav de módulos (solo mobile).
 *   ?hotkeys=edit   → el menú principal, que entra directo al editor.
 * Si `hotkeys=edit` no abriera el modal, en un teléfono el editor quedaría
 * montado en el bloque izquierdo (que en mobile no se pinta) e invisible.
 * `ProductArea` consume `hotkeys=edit` y limpia la URL preservando
 * `view=hotkeys` en mobile, así el modal no se cierra solo.
 */
function wantsHotkeysModule(search: { get(key: string): string | null }): boolean {
  return search.get("view") === "hotkeys" || search.get("hotkeys") === "edit"
}

import * as React from "react"
import { usePathname, useRouter, useSearchParams } from "next/navigation"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog"
import { cn } from "@/lib/utils"
import { useIsMobile } from "@/hooks/use-mobile"
import { CartPanel } from "@/components/register/cart-panel"
import { LockScreen } from "@/components/register/lock-screen"
import { PosLoadingScreen } from "@/components/register/pos-loading-screen"
import { SpaceSettlementProvider } from "@/components/spaces/space-settlement-provider"
import { useCatalogSeed } from "@/hooks/use-catalog-seed"
import { useHotkeys } from "@/hooks/use-hotkeys"
import { usePosHotkeys } from "@/hooks/use-pos-hotkeys"
import { usePriceContext } from "@/hooks/use-price-context"
import { useRegisterClaim } from "@/hooks/use-register-claim"
import { useCatalogStore } from "@/lib/catalog/store"
import { useLockStore } from "@/lib/pos/lock-store"
import { useWorkspaceStore, supportsFullscreen } from "@/lib/pos/workspace-store"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { useCartStore } from "@/lib/cart/store"
import { useRealtimeSync } from "@/hooks/use-realtime-sync"
import { useOfflineSync } from "@/hooks/use-offline-sync"

function OfflineSyncRunner() {
  useOfflineSync()
  return null
}

function BeforeUnloadGuard() {
  const lineCount = useCartStore((s) => s.lines.length)

  React.useEffect(() => {
    if (lineCount === 0) return
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ""
    }
    window.addEventListener("beforeunload", handler)
    return () => window.removeEventListener("beforeunload", handler)
  }, [lineCount])

  return null
}

/**
 * Cierra el modo edición de hotkeys al salir de /pos.
 *
 * El editor vive en el bloque IZQUIERDO (ProductArea, solo en /pos) pero su
 * "modo" es un flag global del hotkeys-store que el CartPanel —persistente en
 * este layout— lee para mostrar la guía de edición en lugar del carrito. Nadie
 * lo apagaba al navegar, así que al ir a Órdenes quedaba el listado a la
 * izquierda y las instrucciones de hotkeys a la derecha, con el editor todavía
 * abierto (reporte del owner 2026-07-29).
 *
 * Se resuelve acá, en el layout que mantiene vivo el panel, y no con un cleanup
 * de unmount en ProductArea: ese cleanup también corre en el doble montaje de
 * StrictMode y apagaría el modo apenas se enciende desde el menú.
 */
function HotkeysEditScope() {
  const pathname = usePathname()

  React.useEffect(() => {
    if (pathname !== "/pos") {
      useHotkeysStore.getState().setEditing(false)
    }
  }, [pathname])

  return null
}

/**
 * Boundary de Suspense OBLIGATORIO: el layout (y `ProductArea`, adentro) usan
 * `useSearchParams()`. Sin un `<Suspense>` por encima, `next build` falla al
 * prerenderizar las rutas hijas —"Error occurred prerendering page
 * /pos/calendario"— y el deploy entero se cae (incidente 2026-08-01). Los
 * children quedan DENTRO del boundary a propósito: así cubre también a
 * cualquier hijo que lea la query.
 */
export default function PosWorkspaceLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <React.Suspense fallback={<PosLoadingScreen />}>
      <PosWorkspaceLayoutInner>{children}</PosWorkspaceLayoutInner>
    </React.Suspense>
  )
}

function PosWorkspaceLayoutInner({
  children,
}: {
  children: React.ReactNode
}) {
  useRealtimeSync("pos")
  useCatalogSeed()
  useHotkeys()
  usePosHotkeys()
  usePriceContext()

  const router = useRouter()
  const pathname = usePathname()
  const isMobile = useIsMobile()
  // En mobile el módulo se monta DENTRO del Dialog; en desktop, en la grilla.
  // Nunca en los dos a la vez: montar dos veces duplicaría fetches, sockets y
  // estado local del módulo.
  const searchParams = useSearchParams()
  const hotkeysAsModule = pathname === "/pos" && wantsHotkeysModule(searchParams)
  const moduleTitle = moduleTitleFor(pathname) ?? (hotkeysAsModule ? "HotKeys" : null)
  const moduleAsDialog = isMobile && moduleTitle !== null

  // Toggle "pantalla completa" de módulo (oculta el CartPanel): solo desktop
  // y solo en rutas que lo soportan (/pos/espacios, /pos/ordenes). En /pos
  // (home, venta) el carrito SIEMPRE se ve aunque el flag esté prendido —
  // la venta nunca cambia de layout, es memoria muscular del cajero.
  const modulesFullscreen = useWorkspaceStore((s) => s.modulesFullscreen)
  const cartHidden = !isMobile && !moduleAsDialog && supportsFullscreen(pathname) && modulesFullscreen

  // Auto-lock al arrancar si hay >1 operador (sin flash entre paints).
  // El flag vive en el lock-store (no en un useRef local) para sobrevivir
  // remounts del layout — Next puede invalidar la cache al navegar entre
  // rutas hijas (/pos → /pos/guardadas) y un useRef se resetearía, volviendo
  // a lockear cada vez. Incidente 2026-06-28.
  //
  // La cuenta de operadores sale del catalog store (`users`, que baja en el
  // bootstrap del POS), NO de `/v1/bootstrap.userCount`. Hasta 2026-08-23
  // este layout pedía ADEMÁS el bootstrap del PANEL con el Bearer del device
  // y gateaba todo el render con `if (!bootstrap) return <PosLoadingScreen/>`:
  // sin internet ese fetch no volvía nunca y la caja se quedaba clavada en el
  // loading para siempre, aunque el catálogo estuviera cacheado. Era el
  // segundo bloqueo del arranque offline, después del `PosAuthGuard`.
  //
  // El POS no necesita el bootstrap del panel para nada: el suyo ya trae
  // `users`. Un bootstrap por realm, y el del POS sabe operar offline.
  const catalogStatus = useCatalogStore((s) => s.status)
  const operatorCount = useCatalogStore((s) => s.users.length)
  const catalogReady = catalogStatus === "ready"
  if (catalogReady && !useLockStore.getState().autoLockDone) {
    useLockStore.getState().markAutoLockDone()
    if (operatorCount > 1) {
      useLockStore.getState().lock()
    }
  }

  // Toma la tenencia de esta caja (context/29 §4, F2) apenas entra al
  // workspace, best-effort — así un device que llega solo a una caja libre ya
  // tiene tenencia establecida antes de intentar cobrar, en vez de descubrir
  // el conflicto recién en `PayDialog`.
  //
  // Corrección de producto del owner (2026-08-20): la tenencia de caja
  // bloquea SOLO la emisión de un documento con numeración fiscal (factura —
  // ver `context/29-numeracion-y-exclusividad-de-caja.md` §4 y
  // `context/modules/17-numeracion.md` §3), NUNCA el acceso al workspace.
  // Sin tenencia, el POS tiene que seguir funcionando igual: catálogo,
  // carrito, cotizaciones, órdenes/comandas, clientes, transacciones. Por eso
  // este hook ya NO gatea el render — un 409 acá (otro device tiene la caja,
  // o este device recién la perdió) queda silencioso; el único gate real e
  // ineludible es el que ya hace `PayDialog` al cobrar (`RegisterTakenPhase`,
  // acotado al diálogo de pago, no al workspace entero) — ahí es donde
  // importa (evitar que dos dispositivos dupliquen una numeración fiscal), y
  // ahí es donde el backend (`sales.php`/`offline-sync.php`,
  // `RegisterLeaseService::holderConflict`) también lo hace cumplir
  // server-side.
  useRegisterClaim()

  // Gate del arranque: el catálogo hidratado (de red o del snapshot offline —
  // ver `hooks/use-pos-bootstrap.ts`). Nunca una request en vuelo.
  if (!catalogReady) {
    return <PosLoadingScreen />
  }

  return (
    <div className="relative flex h-full w-full flex-col overflow-hidden">
      <BeforeUnloadGuard />
      <HotkeysEditScope />
      <OfflineSyncRunner />
      {/* Diálogo de split + reconciliación de cobro de espacios (context/15
          §F3, bug T8) — persistente acá (no en /pos/espacios) para que
          sobreviva la navegación a /pos tras cargar el carrito. Ver docblock
          de `space-settlement-provider.tsx`. */}
      <SpaceSettlementProvider />
      <div className="flex min-h-0 flex-1 overflow-hidden">
        {!moduleAsDialog && (
          <div
            className={cn(
              "hidden overflow-hidden md:block",
              cartHidden ? "flex-1" : "flex-[7]",
            )}
          >
            {children}
          </div>
        )}
        {/* No se desmonta con `cartHidden`: el panel es persistente (ver
            docblock del layout) y además guarda estado local de UI propio
            además del zustand store. Solo se oculta con `hidden`. */}
        <div className={cn("flex-1 overflow-hidden md:flex-[3]", cartHidden && "hidden")}>
          <CartPanel />
        </div>
      </div>

      {/* Módulo de ruta como modal fullscreen en mobile. Cerrar vuelve a /pos:
          el Dialog ES la ruta, así que descartarlo tiene que descartar también
          la navegación — si no, el carrito quedaría visible con la URL todavía
          en /pos/espacios y el próximo render lo reabriría.
          Las clases de fullscreen van explícitas (y no delegadas al `max-sm:`
          del primitive) porque `useIsMobile` corta en 768px y `max-sm` en
          640px: en una tablet chica el modal quedaría centrado y flotando. */}
      {moduleAsDialog && (
        <Dialog
          open
          onOpenChange={(v) => {
            if (!v) router.push("/pos")
          }}
        >
          <DialogContent
            className={cn(
              "flex flex-col gap-0 overflow-hidden p-0",
              "!inset-0 !h-dvh !max-h-dvh !w-auto !max-w-none !translate-x-0 !translate-y-0 !rounded-none",
            )}
          >
            <DialogHeader className="sr-only">
              <DialogTitle>{moduleTitle}</DialogTitle>
              <DialogDescription>
                Módulo del POS abierto sobre el carrito.
              </DialogDescription>
            </DialogHeader>
            <div className="min-h-0 flex-1 overflow-hidden">{children}</div>
          </DialogContent>
        </Dialog>
      )}

      <LockScreen />
    </div>
  )
}
