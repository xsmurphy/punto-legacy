"use client"

/**
 * Ficha de producto del POS — todo lo que el cajero necesita saber del ítem
 * sin salir de la caja: fotos, datos del catálogo (categoría, marca, IVA,
 * sucursal asignada, tipo, SKU), descripción, etiquetas, precio de venta y
 * dónde hay stock.
 *
 * El caso de uso que la motivó (owner, 2026-08-14) es el quiebre: cuando en
 * esta sucursal no hay, el cajero tiene que poder decirle al cliente en cuál
 * sí. Por eso el bloque de stock es por sucursal y depósito, no un número
 * suelto, y la sucursal de la caja aparece primera y marcada.
 *
 * NUNCA muestra costo, costo promedio ni valorizado — sólo precio de VENTA.
 * El filtro real está en el BFF (`app/api/pos/items/route.ts`): esos campos ni
 * siquiera llegan al device.
 *
 * Se abre desde el ícono de info del tile de Hotkeys (`product-area.tsx`) y
 * desde el avatar de cada fila del buscador (`product-search-dialog.tsx`); en
 * ambos casos el tap/click principal sigue agregando el ítem al carrito.
 *
 * OFFLINE: nombre/SKU/precio/categoría/marca/IVA/sucursal/tipo salen del
 * catálogo cacheado (`useCatalogStore`) — disponibles sin red. La galería
 * completa, la descripción, las etiquetas y el stock por sucursal SÍ
 * requieren red (mismo criterio que el stock, que ya lo requería): degradan
 * mostrando la portada cacheada (`item.imageUrl`) y el estado de error con
 * reintento, nunca rompen ni bloquean la ficha.
 */

import * as React from "react"
import { AlertTriangle, PackageSearch, RefreshCw, WifiOff } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Separator } from "@/components/ui/separator"
import { Skeleton } from "@/components/ui/skeleton"
import { EmptyState } from "@/components/empty-state"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCategoryBrandMaps, resolveCategoryName, resolveBrandName } from "@/lib/catalog/resolve-names"
import { formatMoney } from "@/lib/format-money"
import { formatQty } from "@/lib/format-qty"
import {
  STOCK_STATUS_CLASS,
  STOCK_STATUS_LABEL,
  stockStatus,
  type StockStatus,
} from "@/lib/stock-status"
import { cn } from "@/lib/utils"
import { usePosItemInfo, type PosItemImage, type PosItemStockOutlet } from "@/hooks/use-pos-item-info"
import type { PosConfig, PosItem, PosTaxRate } from "@/lib/types/pos-bootstrap"
import { KIND_META, type ItemKind } from "@/lib/types/item"

/**
 * Relleno de la barra de salud. Espeja los colores de `STOCK_STATUS_CLASS`
 * (que define los del TEXTO) para que el semáforo diga lo mismo en las dos
 * superficies. `ok` no usa el verde de marca: §14 lo reserva para acentos
 * puntuales, y acá el color que tiene que saltar es el del problema.
 */
const STOCK_BAR_CLASS: Record<StockStatus, string> = {
  quiebre: "bg-destructive",
  bajo: "bg-amber-500 dark:bg-amber-400",
  sobre: "bg-blue-500 dark:bg-blue-400",
  ok: "bg-foreground/60",
}

const STOCK_BADGE_VARIANT: Record<StockStatus, "destructive" | "secondary" | "outline"> = {
  quiebre: "destructive",
  bajo: "secondary",
  sobre: "outline",
  ok: "outline",
}

/**
 * `taxId === null` → el ítem no tiene impuesto asignado en catálogo (no es lo
 * mismo que "exento": exento SÍ tiene una tasa configurada, con `kind`
 * "exempt"). `taxId` sin match en `taxes` → tasa borrada/fuera de este
 * snapshot, mismo criterio de fallback que `resolveCategoryName`.
 */
function resolveTaxLabel(taxId: string | null, taxes: PosTaxRate[]): string {
  if (taxId === null) return "Sin impuesto asignado"
  const tax = taxes.find((t) => t.id === taxId)
  if (!tax) return "Impuesto"
  return tax.kind === "exempt" ? "Exento" : `${tax.rate}%`
}

interface Props {
  /** Ítem cuya ficha se muestra. `null` = diálogo cerrado. */
  item: PosItem | null
  onClose: () => void
}

export function ProductInfoDialog({ item, onClose }: Props) {
  const config = useCatalogStore((s) => s.config)
  const activeOutlet = useCatalogStore((s) => s.outlet)
  // Todas las sucursales del tenant (para resolver `item.outletId` → nombre).
  // Distinto de `outlets` más abajo, que es el desglose de STOCK por sucursal
  // de este ítem puntual — no renombrar sin revisar los dos usos.
  const allOutlets = useCatalogStore((s) => s.outlets)
  const taxes = useCatalogStore((s) => s.taxes)
  const { categoryMap, brandMap } = useCategoryBrandMaps()

  const { data, isPending, isError, refetch, isFetching } = usePosItemInfo(item?.id ?? null)

  // Datos del catálogo en memoria: es lo que se muestra mientras carga y lo
  // único que queda si el device está sin red (el bootstrap del POS vive
  // cacheado, la ficha no). La ficha en vivo (`data.detail`) sigue trayendo
  // categoryName/brandName YA resueltos server-side (`use-pos-item-info.ts`
  // — endpoint de detalle puntual, no el bootstrap masivo, así que no aplica
  // la regla de context/45); el fallback offline/loading resuelve contra las
  // listas del store, igual que el resto del POS.
  const name = data?.detail.itemName || item?.name || ""
  const sku = data?.detail.itemSKU ?? item?.sku ?? null
  const price = data?.detail.itemPrice ?? item?.price ?? 0
  const uom = data?.detail.itemUOM ?? item?.uom ?? null
  const categoryName =
    data?.detail.categoryName ?? resolveCategoryName(item?.categoryId ?? null, categoryMap)
  const brandName = data?.detail.brandName ?? resolveBrandName(item?.brandId ?? null, brandMap)
  const trackInventory = data?.detail.itemTrackInventory ?? item?.trackInventory ?? false
  const minStock = data?.detail.itemMinStock ?? null
  const maxStock = data?.detail.itemMaxStock ?? null

  // Tipo de ítem: viaja cacheado (`item.kind`), nunca depende de la ficha en
  // vivo. `KIND_META` es la misma tabla de labels que usa el panel
  // (`lib/types/item.ts`) — un solo lugar donde "servicio" se traduce a
  // "Servicio", no una copia local que se puede desalinear.
  const kindLabel = item
    ? (KIND_META[item.kind as ItemKind]?.label ?? item.kind)
    : null

  // Sucursal asignada (`item.outletId`, legado 1:1): resuelve contra
  // `useCatalogStore.outlets` (todas las sucursales del tenant, ya cacheado
  // — mismo criterio que categoría/marca, ver `resolve-names.ts`). `null` no
  // es "sin dato" sino "vendible en cualquier sucursal".
  const assignedOutletName =
    item?.outletId === null || item?.outletId === undefined
      ? "Todas las sucursales"
      : (allOutlets.find((o) => o.id === item.outletId)?.name ?? "Sucursal")

  // IVA: la tasa vive en `item.taxId` (cacheada) contra `useCatalogStore.taxes`
  // — mismo patrón que el carrito (`lib/cart/store.ts`), no requiere red.
  const taxLabel = resolveTaxLabel(item?.taxId ?? null, taxes)

  // Galería: la ficha en vivo trae hasta 5 fotos ordenadas. Mientras carga o
  // sin red, cae a la portada cacheada del catálogo (si el ítem tiene) — así
  // la ficha nunca queda sin imagen solo porque el fetch de detalle no llegó.
  const galleryImages: PosItemImage[] =
    data?.detail.images && data.detail.images.length > 0
      ? data.detail.images
      : item?.imageUrl
        ? [{ id: "cover", url: item.imageUrl, sort: 0 }]
        : []

  // Sucursal de la caja primero: es la respuesta a "¿tengo para vender ahora?".
  const outlets = React.useMemo(() => {
    const rows = data?.stock.outlets ?? []
    if (!activeOutlet) return rows
    return [...rows].sort((a, b) => {
      const aActive = a.outletId === activeOutlet.id ? 0 : 1
      const bActive = b.outletId === activeOutlet.id ? 0 : 1
      return aActive - bActive || a.outletName.localeCompare(b.outletName)
    })
  }, [data?.stock.outlets, activeOutlet])

  const total = data?.stock.total ?? 0
  const totalStatus = stockStatus({
    qty: total,
    min: minStock,
    max: maxStock,
    tracked: trackInventory,
  })

  // Escala común de las barras: el saldo más alto entre sucursales, el máximo
  // configurado y el mínimo. Compartir la escala es lo que hace comparables
  // las barras entre sí — con una escala por fila, 2 unidades y 200 se verían
  // igual de largas.
  const scale = React.useMemo(() => {
    const maxOutlet = outlets.reduce((acc, o) => Math.max(acc, o.qty), 0)
    return Math.max(maxOutlet, maxStock ?? 0, (minStock ?? 0) * 2, 1)
  }, [outlets, maxStock, minStock])

  const offline = typeof navigator !== "undefined" && navigator.onLine === false

  return (
    <Dialog open={item !== null} onOpenChange={(open) => { if (!open) onClose() }}>
      {/* Bucket l (sm:max-w-4xl) — la ficha pasó a 2 columnas y necesita más
          ancho que el bucket m default (§14 Regla #2.1). */}
      <DialogContent className="sm:max-w-4xl" mobileFullscreen>
        <DialogHeader>
          <DialogTitle>{name}</DialogTitle>
          <DialogDescription>
            {[sku ? `SKU ${sku}` : null, categoryName, brandName]
              .filter(Boolean)
              .join(" · ") || "Ficha del producto"}
          </DialogDescription>
        </DialogHeader>

        {/*
          Corte en `md` (768px) — NO un breakpoint nuevo. El POS ya modela
          "horizontal" vs "vertical/phone view" con `useIsMobile()`
          (frontend/hooks/use-mobile.ts, MOBILE_BREAKPOINT = 768) y
          `pos/layout.tsx` usa exactamente `md:` como su equivalente Tailwind
          para esa misma frontera. Reusamos esa: NO hay un tercer modo
          "tablet vertical" a acomodar — o el POS corre horizontal (tablet u
          computadora, ahí van las 2 columnas) o cae en phone view (apilado,
          1 columna). Forzar la orientación es un tema de la app/PWA, ajeno a
          este diálogo.
        */}
        <div className="flex flex-col gap-4 md:flex-row md:items-start md:gap-6">
          {/* ── Galería ─────────────────────────────────────────────────── */}
          {galleryImages.length > 0 && (
            <div className="md:w-72 md:shrink-0">
              <ProductGallery images={galleryImages} name={name} />
            </div>
          )}

          <div className="flex min-w-0 flex-1 flex-col gap-4">
            {/* ── Precio de venta ───────────────────────────────────────── */}
            <div>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Precio de venta
              </p>
              <p className="text-2xl font-semibold tabular-nums">
                {formatMoney(price, config)}
                {uom && (
                  <span className="ml-1.5 text-sm font-normal text-muted-foreground">
                    por {uom}
                  </span>
                )}
              </p>
            </div>

            {/* ── Datos del producto ──────────────────────────────────────
                Todo cacheado (item.*), disponible sin red. 3 columnas desde
                `md`: la columna de contenido ya tiene ancho de sobra al lado
                de la galería, y 2 filas en vez de 3 es menos scroll. */}
            <div className="flex flex-col gap-2">
              <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Datos del producto
              </p>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2.5 md:grid-cols-3">
                <InfoField label="Tipo de ítem" value={kindLabel} />
                <InfoField label="SKU" value={sku} />
                <InfoField label="Categoría" value={categoryName} />
                <InfoField label="Marca" value={brandName} />
                <InfoField label="IVA" value={taxLabel} />
                <InfoField label="Sucursal asignada" value={assignedOutletName} />
              </dl>
            </div>

            {/* ── Descripción y etiquetas ─────────────────────────────────── */}
            {isPending ? (
              <div className="flex flex-col gap-2">
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-2/3" />
              </div>
            ) : (
              <>
                {data?.detail.itemDescription && (
                  <p className="text-sm text-muted-foreground">{data.detail.itemDescription}</p>
                )}
                {(data?.detail.tags.length ?? 0) > 0 && (
                  <div className="flex flex-wrap gap-2">
                    {data?.detail.tags.map((tag) => (
                      <Badge key={tag.id} variant="secondary">
                        {tag.name}
                      </Badge>
                    ))}
                  </div>
                )}
              </>
            )}

            <Separator />

            {/* ── Stock ───────────────────────────────────────────────────── */}
            <div className="flex flex-col gap-3">
              <div className="flex items-center justify-between gap-3">
                <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Stock por sucursal
                </p>
                {totalStatus !== null && !isPending && !isError && (
                  <Badge variant={STOCK_BADGE_VARIANT[totalStatus]}>
                    {STOCK_STATUS_LABEL[totalStatus]}
                  </Badge>
                )}
              </div>

              {!trackInventory ? (
                <p className="rounded-lg border border-border p-4 text-sm text-muted-foreground">
                  Este ítem no lleva control de stock, así que no tiene saldo ni alertas.
                  Se puede vender siempre.
                </p>
              ) : isError ? (
                <div className="flex flex-col items-start gap-3 rounded-lg border border-border p-4">
                  <div className="flex items-start gap-2.5">
                    {offline ? (
                      <WifiOff className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    ) : (
                      <AlertTriangle className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    )}
                    <p className="text-sm text-muted-foreground">
                      {offline
                        ? "Sin conexión — el stock no está disponible. El precio y los datos que ves salen del catálogo guardado en la caja."
                        : "No se pudo consultar el stock. Probá de nuevo en unos segundos."}
                    </p>
                  </div>
                  <Button variant="outline" onClick={() => refetch()} disabled={isFetching}>
                    <RefreshCw className={cn("size-4", isFetching && "animate-spin")} />
                    Reintentar
                  </Button>
                </div>
              ) : isPending ? (
                <div className="flex flex-col gap-3">
                  {[0, 1].map((i) => (
                    <div key={i} className="flex flex-col gap-2">
                      <Skeleton className="h-4 w-1/2" />
                      <Skeleton className="h-2 w-full" />
                    </div>
                  ))}
                </div>
              ) : outlets.length === 0 ? (
                <EmptyState
                  icon={PackageSearch}
                  title="Sin movimientos de stock"
                  description="Este ítem todavía no tiene entradas ni salidas cargadas en ninguna sucursal."
                  ghost={false}
                />
              ) : (
                <>
                  <div className="flex flex-col gap-3">
                    {outlets.map((outlet) => (
                      <OutletStockRow
                        key={outlet.outletId}
                        outlet={outlet}
                        isActive={outlet.outletId === activeOutlet?.id}
                        min={minStock}
                        max={maxStock}
                        scale={scale}
                        config={config}
                      />
                    ))}
                  </div>

                  <div className="flex items-center justify-between gap-3 border-t border-border pt-3">
                    <span className="text-sm font-medium">Total</span>
                    <span
                      className={cn(
                        "text-sm tabular-nums",
                        totalStatus ? STOCK_STATUS_CLASS[totalStatus] : undefined,
                      )}
                    >
                      {formatQty(total, config)}
                      {uom ? ` ${uom}` : ""}
                    </span>
                  </div>

                  {(minStock !== null || maxStock !== null) && (
                    <p className="text-sm text-muted-foreground">
                      {[
                        minStock !== null ? `Mínimo ${formatQty(minStock, config)}` : null,
                        maxStock !== null ? `máximo ${formatQty(maxStock, config)}` : null,
                      ]
                        .filter(Boolean)
                        .join(" · ")}
                    </p>
                  )}
                </>
              )}
            </div>
          </div>
        </div>

        <DialogFooter>
          {/* Touch target de cajero: full width en la tablet (§14 §2.2). */}
          <Button size="lg" className="w-full sm:w-auto" onClick={onClose}>
            Cerrar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Fila de sucursal ─────────────────────────────────────────────────────────

function OutletStockRow({
  outlet,
  isActive,
  min,
  max,
  scale,
  config,
}: {
  outlet: PosItemStockOutlet
  isActive: boolean
  min: number | null
  max: number | null
  scale: number
  config: Pick<PosConfig, "thousand"> | null
}) {
  // El mínimo/máximo del ítem es uno solo para todo el comercio; se aplica a
  // cada sucursal como aproximación para pintar dónde alcanza y dónde no. La
  // decisión de reponer sigue siendo del panel, acá sólo se deriva al cliente.
  const status = stockStatus({ qty: outlet.qty, min, max, tracked: true }) ?? "ok"
  const fill = Math.max(0, Math.min(100, (outlet.qty / scale) * 100))
  const minMark = min !== null && min > 0 ? Math.min(100, (min / scale) * 100) : null

  // Un único depósito sin nombre es el saldo de la sucursal repetido: no aporta.
  const locations =
    outlet.locations.length > 1 || outlet.locations[0]?.locationName
      ? outlet.locations
      : []

  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-baseline justify-between gap-3">
        <span className="flex min-w-0 items-center gap-2">
          <span className="truncate text-sm font-medium">{outlet.outletName}</span>
          {isActive && (
            <Badge variant="outline" className="shrink-0">
              Esta sucursal
            </Badge>
          )}
        </span>
        <span className={cn("shrink-0 text-sm tabular-nums", STOCK_STATUS_CLASS[status])}>
          {formatQty(outlet.qty, config)}
        </span>
      </div>

      {/* Barra de salud: saldo contra la escala común, con marca del mínimo. */}
      <div className="relative h-2 w-full overflow-hidden rounded-full bg-muted">
        <div
          className={cn("h-full rounded-full transition-all", STOCK_BAR_CLASS[status])}
          style={{ width: `${fill}%` }}
        />
        {minMark !== null && (
          <span
            aria-hidden
            className="absolute inset-y-0 w-px bg-foreground/50"
            style={{ left: `${minMark}%` }}
          />
        )}
      </div>

      {locations.length > 0 && (
        <ul className="flex flex-col gap-0.5 pl-3">
          {locations.map((loc, i) => (
            <li
              key={loc.locationId ?? `principal-${i}`}
              className="flex items-baseline justify-between gap-3 text-sm text-muted-foreground"
            >
              <span className="truncate">{loc.locationName ?? "Depósito principal"}</span>
              <span className="shrink-0 tabular-nums">{formatQty(loc.qty, config)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

// ── Fila de dato del producto ─────────────────────────────────────────────────

/** Par label/valor de la grilla "Datos del producto". `null` → "—" (dato sin configurar). */
function InfoField({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="truncate text-sm font-medium">{value ?? "—"}</dd>
    </div>
  )
}

// ── Galería de imágenes ────────────────────────────────────────────────────────

/**
 * Foto grande (portada o la seleccionada) + tira de miniaturas si hay más de
 * una. Sin librería de carrusel (no hay `Carousel` instalado en el proyecto,
 * ver context/20): con el tope de 5 imágenes por ítem (`ItemImageService`),
 * una tira de botones alcanza y mantiene el mismo patrón táctil que el resto
 * del POS — cada miniatura es un botón de 56px (mínimo táctil), navegable
 * por teclado (Tab + Enter/Espacio) sin JS adicional.
 */
function ProductGallery({ images, name }: { images: PosItemImage[]; name: string }) {
  const [active, setActive] = React.useState(0)
  // Si la galería cambia (otro ítem, o llegaron más fotos de la ficha en
  // vivo) y el índice activo quedó afuera de rango, volver a la portada.
  const safeActive = active < images.length ? active : 0
  const current = images[safeActive]

  return (
    <div className="flex flex-col gap-2">
      <div className="relative mx-auto aspect-square w-full max-w-64 overflow-hidden rounded-lg border border-border bg-muted">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={current.url} alt={name} className="h-full w-full object-cover" />
      </div>

      {images.length > 1 && (
        <div className="flex justify-center gap-2 overflow-x-auto">
          {images.map((img, i) => (
            <button
              key={img.id}
              type="button"
              onClick={() => setActive(i)}
              aria-label={`Ver foto ${i + 1} de ${images.length}`}
              aria-current={i === safeActive}
              className={cn(
                "size-14 shrink-0 overflow-hidden rounded-md border-2 transition-colors",
                i === safeActive
                  ? "border-primary"
                  : "border-transparent opacity-70 hover:opacity-100",
              )}
            >
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={img.url} alt="" className="h-full w-full object-cover" />
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
