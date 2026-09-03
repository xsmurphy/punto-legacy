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
import { ArrowLeft } from "lucide-react"
import { Button } from "@/components/ui/button"
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
import { ViewportProbe } from "@/components/pos/viewport-probe"
import { PosDocumentTitle } from "@/components/pos/document-title"
import { LockScreen } from "@/components/register/lock-screen"
import { useIdleLock } from "@/hooks/use-idle-lock"
import { usePosRegisterConfig } from "@/hooks/use-pos-config"
import { PosLoadingScreen } from "@/components/register/pos-loading-screen"
import { SpaceSettlementProvider } from "@/components/spaces/space-settlement-provider"
import { useCatalogSeed } from "@/hooks/use-catalog-seed"
import { useHotkeys } from "@/hooks/use-hotkeys"
import { usePosHotkeys } from "@/hooks/use-pos-hotkeys"
import { usePriceContext } from "@/hooks/use-price-context"
import { useRegisterClaim } from "@/hooks/use-register-claim"
import { useCatalogStore } from "@/lib/catalog/store"
import { useWorkspaceStore, supportsFullscreen } from "@/lib/pos/workspace-store"
import { usePosDebugStore } from "@/lib/pos/debug-store"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { useCartStore } from "@/lib/cart/store"
import { useRealtimeSync } from "@/hooks/use-realtime-sync"
import { useOfflineSync } from "@/hooks/use-offline-sync"
import { usePendingOpsSync } from "@/hooks/use-pending-ops-sync"

function OfflineSyncRunner() {
  // Dos colas, dos ciclos: ventas emitidas (comprobantes que ya existen en
  // papel) y operaciones de configuración y de caja. Se montan juntas pero no
  // se mezclan — ver el docblock de `lib/pos/pending-ops.ts`.
  useOfflineSync()
  usePendingOpsSync()
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
  // Interruptor persistente de la sonda de viewport (Ajustes → Diagnóstico).
  const viewportProbe = usePosDebugStore((st) => st.viewportProbe)
  const hotkeysAsModule = pathname === "/pos" && wantsHotkeysModule(searchParams)

  // En móvil, el editor de hotkeys VIVE en el módulo-modal: si el modal se
  // cierra (cancelar el diálogo de asignación, gesto de cierre) con el modo
  // edición todavía prendido, el carrito queda mostrando la guía de edición a
  // pantalla completa sin ningún control para salir (reporte del owner
  // 2026-08-25). HotkeysEditScope no cubre este caso: mira el pathname, y
  // cerrar el modal no lo cambia (/pos con y sin ?view=hotkeys). En desktop no
  // aplica — el editor convive con su botón "Listo" en la grilla.
  React.useEffect(() => {
    if (isMobile && pathname === "/pos" && !hotkeysAsModule) {
      useHotkeysStore.getState().setEditing(false)
    }
  }, [isMobile, pathname, hotkeysAsModule])
  const moduleTitle = moduleTitleFor(pathname) ?? (hotkeysAsModule ? "HotKeys" : null)
  const moduleAsDialog = isMobile && moduleTitle !== null

  // Toggle "pantalla completa" de módulo (oculta el CartPanel): solo desktop
  // y solo en rutas que lo soportan (/pos/espacios, /pos/ordenes). En /pos
  // (home, venta) el carrito SIEMPRE se ve aunque el flag esté prendido —
  // la venta nunca cambia de layout, es memoria muscular del cajero.
  const modulesFullscreen = useWorkspaceStore((s) => s.modulesFullscreen)
  const cartHidden = !isMobile && !moduleAsDialog && supportsFullscreen(pathname) && modulesFullscreen

  // Auto-lock: ya NO vive acá.
  //
  // Hasta 2026-08-23 este layout auto-lockeaba solo `if (operatorCount > 1)` y
  // solo una vez por sesión (flag `autoLockDone` persistido). Desde 2026-08-24
  // (pedido del owner) el lock screen es SIEMPRE lo primero: abrir la app o
  // recargarla muestra el PIN, sin importar cuántos operadores haya. Eso ya no
  // necesita ninguna condición del layout — el lock-store arranca en
  // `locked: true` y no persiste ese campo, así que el overlay está montado
  // desde el primer paint. Ver `lib/pos/lock-store.ts` (incluye por qué esto
  // revierte la decisión del incidente 2026-06-28).
  //
  // Lo que SÍ sigue siendo cierto: el POS no pide el bootstrap del PANEL para
  // nada. Hasta 2026-08-23 lo hacía con el Bearer del device y gateaba todo el
  // render con `if (!bootstrap) return <PosLoadingScreen/>`: sin internet ese
  // fetch no volvía nunca y la caja quedaba clavada en el loading aunque el
  // catálogo estuviera cacheado. Un bootstrap por realm, y el del POS sabe
  // operar offline.
  const catalogStatus = useCatalogStore((s) => s.status)
  const catalogReady = catalogStatus === "ready"

  // Toma y MANTIENE la tenencia de esta caja (context/29 §4), y persiste el
  // resultado en el device (`lib/pos/register-tenancy.ts`) para que el POS
  // sepa sin red si tiene derecho a emitir. Hasta 2026-08-23 este hook era un
  // disparo único cuyo resultado nadie leía, y sin conexión no quedaba ningún
  // gate: se vendía, se imprimía, y el rechazo aparecía al sincronizar.
  //
  // Corrección de producto del owner (2026-08-20), intacta: la tenencia
  // bloquea SOLO la emisión de un documento con numeración fiscal (factura —
  // ver `context/29-numeracion-y-exclusividad-de-caja.md` §4 y
  // `context/modules/17-numeracion.md` §3), NUNCA el acceso al workspace.
  // Sin tenencia, el POS sigue funcionando igual: catálogo, carrito,
  // cotizaciones, órdenes/comandas, clientes, transacciones. Por eso este hook
  // no gatea ningún render; el gate real vive en `PayDialog`
  // (`RegisterTakenPhase`, acotado al diálogo de pago), que ahora consulta el
  // grant local y por eso también bloquea offline. El backend
  // (`sales.php`/`offline-sync.php`, `RegisterLeaseService::holderConflict`)
  // sigue siendo la última palabra server-side.
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  useRegisterClaim(activeRegisterId || null)

  // Gate del arranque: el catálogo hidratado (de red o del snapshot offline —
  // ver `hooks/use-pos-bootstrap.ts`). Nunca una request en vuelo.
  if (!catalogReady) {
    return <PosLoadingScreen />
  }

  return (
    <div className="relative flex h-full w-full flex-col overflow-hidden">
      {/* Sonda de viewport. Ver el docblock del componente — existe porque en
          una PWA de iOS no hay devtools y el "gap de abajo" se persiguió a
          ciegas tres veces.

          DOS disparadores, no uno: el `?debug=viewport` de siempre para el
          browser, y el interruptor de Ajustes → Diagnóstico para la PWA
          INSTALADA, que es como opera la caja y donde no hay barra de
          direcciones en la que escribir un query param. Justo el modo donde
          aparecen los bugs de viewport es el que no podía activarla. */}
      {(searchParams.get("debug") === "viewport" || viewportProbe) && <ViewportProbe />}
      <PosDocumentTitle />
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
              // `pb`: en tablet este bloque apoya en el borde inferior igual
              // que el carrito (que lo descuenta en su `CartBottom`), así que
              // la última fila de la grilla no queda debajo del indicador de
              // gestos del iPad. En desktop la variable vale 0.
              "hidden overflow-hidden pb-[var(--safe-b)] md:block",
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
            // La X del primitive vive en `absolute top-4 right-4`: a pantalla
            // completa eso cae DENTRO del status bar del teléfono, donde no
            // recibe el toque. Como en un módulo esa X era la única salida, el
            // cajero quedaba encerrado — "entro al módulo de órdenes y ya no
            // puedo volver" (owner, 2026-08-25). La reemplaza la barra de
            // abajo, que además dice a dónde vuelve.
            showCloseButton={false}
            className={cn(
              "flex flex-col gap-0 overflow-hidden p-0",
              // `!h-auto` y no `!h-dvh`: el alto lo definen `top:0` y
              // `bottom:0` de `inset-0`, no una unidad de viewport. Es el
              // mismo motivo por el que `mobileFullscreen` migró en
              // `ui/dialog.tsx` — con `viewport-fit=cover`, vh/dvh son
              // justo lo que cambia de valor según el chrome del sistema, y
              // unos píxeles de diferencia dejan el overlay asomando contra
              // el borde inferior.
              "!inset-0 !h-auto !max-h-none !w-auto !max-w-none !translate-x-0 !translate-y-0 !rounded-none",
            )}
          >
            <DialogHeader className="sr-only">
              <DialogTitle>{moduleTitle}</DialogTitle>
              <DialogDescription>
                Módulo del POS abierto sobre el carrito.
              </DialogDescription>
            </DialogHeader>
            {/* Barra de vuelta a la caja — el módulo tapa la pantalla entera y
                el trigger de navegación vive en el carrito, que queda debajo.
                Sin esto la única salida era el gesto del sistema, que en la
                PWA instalada no existe.

                Acá se descuenta `--safe-t`: esta barra es el elemento que
                apoya en el borde superior de esta superficie (el diálogo se
                portalea al `<body>`, no hereda el inset del shell del POS).
                El fondo de la barra llega igual hasta el borde físico; lo que
                se corre hacia abajo es su contenido. Ver `app/globals.css`
                § "Áreas seguras del dispositivo". */}
            <div className="flex shrink-0 items-center gap-1 border-b pt-[calc(0.5rem+var(--safe-t))] pr-1 pb-2 pl-1">
              <Button
                variant="ghost"
                size="icon"
                className="size-11"
                aria-label="Volver a la caja"
                onClick={() => router.push("/pos")}
              >
                <ArrowLeft className="size-5" />
              </Button>
              <span className="min-w-0 flex-1 truncate text-base font-semibold">
                {moduleTitle}
              </span>
              {/* Sin botón de "otro módulo" acá: existió (PanelLeft) y el
                  owner lo eliminó el 2026-08-25 — no le veía sentido. Cambiar
                  de módulo es: flecha a la caja y el trigger de módulos del
                  toolbar del carrito. */}
            </div>
            {/* `pb`: el módulo apoya en el borde inferior. Su barra de vistas
                (Órdenes) y sus listas terminan justo arriba del indicador de
                gestos en vez de debajo. */}
            <div className="min-h-0 flex-1 overflow-hidden pb-[var(--safe-b)]">
              {children}
            </div>
          </DialogContent>
        </Dialog>
      )}

      <LockScreen />
      {/* El timer de inactividad vive al lado del lock screen porque es quien
          lo dispara. Sin caja activa no hay config que leer ni sesión que
          bloquear, así que no se monta. */}
      {activeRegisterId !== "" && <IdleLockTimer registerId={activeRegisterId} />}
    </div>
  )
}

/**
 * Puente entre la config de la caja y `useIdleLock`.
 *
 * Componente aparte y no un hook en el layout para que el refetch de la config
 * (react-query) no re-renderice el árbol entero del workspace —incluido el
 * carrito persistente— cada vez que se revalida.
 */
function IdleLockTimer({ registerId }: { registerId: string }) {
  const { data } = usePosRegisterConfig(registerId)
  useIdleLock(data?.config.lockAfterSeconds ?? 0)
  return null
}
