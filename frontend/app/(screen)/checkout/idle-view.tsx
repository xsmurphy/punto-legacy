import Image from "next/image"
import { PuntoLogo } from "@/components/layout/punto-logo"
import type { ScreenContext } from "./page"

/**
 * Estado idle del visor al cliente — MARCA DEL COMERCIO, no la caja.
 *
 * Pedido del owner: en standby (sin ítems en el carrito, sin cliente, sin
 * nada que cobrar) la pantalla muestra el logo del negocio centrado y el
 * nombre debajo. Nada más.
 *
 * Qué se sacó y por qué: antes el idle repetía el layout del `LiveView` con
 * un total en "0", "Total a pagar en Gs", "Sin cliente" y la línea
 * sucursal - caja. Esta pantalla la mira EL CLIENTE del comercio, no el
 * cajero: un "0" gigante y un "Sin cliente" son ruido operativo, y el nombre
 * de la caja es dato interno que no le aporta nada a quien está del otro
 * lado del mostrador. El estado vacío es el momento en que la pantalla no
 * tiene función operativa — ahí lo que corresponde es marca.
 *
 * Escala: se lee de lejos. Logo y nombre van en `clamp()` sobre el viewport
 * para llenar la pantalla tanto en un monitor de 10" apoyado en el mostrador
 * como en un TV. El `min-h-screen` + centrado en los dos ejes lo mantiene
 * compuesto en cualquier relación de aspecto.
 */
export function IdleView({ ctx }: { ctx: ScreenContext | null }) {
  const logoUrl = ctx?.logoUrl || null
  const hasLogo = logoUrl !== null
  const name = ctx?.companyName ?? ""

  return (
    <div className="relative flex min-h-screen flex-col items-center justify-center gap-8 p-12 lg:p-16">
      {logoUrl && (
        // Caja de proporción libre con `object-contain`: el logo del tenant
        // puede ser cuadrado o apaisado y en los dos casos entra sin
        // deformarse ni recortarse.
        <div
          className="relative w-full"
          style={{
            height: "clamp(8rem, 26vh, 20rem)",
            maxWidth: "min(70vw, 44rem)",
          }}
        >
          <Image
            src={logoUrl}
            alt={name || "Logo del comercio"}
            fill
            className="object-contain"
            priority
            unoptimized
          />
        </div>
      )}

      {name && (
        // Sin logo el nombre es la marca: sube de tamaño para no dejar la
        // pantalla vacía.
        <p
          className="max-w-[85vw] text-center font-semibold leading-tight text-foreground"
          style={{
            fontSize: hasLogo ? "clamp(1.75rem, 4vw, 3.5rem)" : "clamp(2.5rem, 8vw, 7rem)",
          }}
        >
          {name}
        </p>
      )}

      {/* Watermark Punto — abajo, centrado. Es marca, no dato operativo:
          se mantiene igual que en `LiveView`. */}
      <div className="pointer-events-none absolute bottom-8 flex flex-col items-center gap-1 opacity-70">
        <span className="text-xs uppercase tracking-wide text-muted-foreground">Usamos</span>
        <PuntoLogo variant="wordmark" className="h-8 w-[110px]" />
      </div>
    </div>
  )
}
