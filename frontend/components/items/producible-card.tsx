"use client"

import * as React from "react"
import { ChevronDown, PackageSearch } from "lucide-react"
import { EmptyState } from "@/components/empty-state"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useProducibleNow } from "@/hooks/use-production"
import { formatInt } from "@/lib/format"
import { formatQty } from "@/lib/format-qty"
import type { ProducibleOutlet } from "@/lib/types/production"
import { cn } from "@/lib/utils"

/**
 * "Producibles ahora" — cuántas unidades de este artículo salen HOY con el
 * stock de los insumos de su receta, por sucursal, con el insumo que corta.
 *
 * Pedido del owner (2026-09-07): un producto que se arma sobre pedido no tiene
 * un stock propio que mirar, así que la ficha no puede contestar "cuántas me
 * quedan" — el número hay que deducirlo de la receta.
 *
 * El cálculo es 100% del server (`GET /v1/production?resource=producible`):
 * acá NO se multiplica, no se redondea y no se decide qué limita. Si el número
 * viviera en el front, la caja, el panel y un reporte darían tres respuestas
 * distintas para la misma receta — que es exactamente el defecto que el motor
 * único (`RecipeCapacity`) vino a cerrar.
 *
 * La sucursal tampoco se elige acá: viaja en el view-scope (header
 * `X-Outlet-Id` del panel), igual que en el resto de los lectores. Con una
 * sucursal seleccionada viene una; en consolidado, las del usuario.
 */
export function ProducibleCard({ itemId }: { itemId: string }) {
  const { data: bootstrap } = useBootstrap()
  const { data, isLoading, isError } = useProducibleNow(itemId)

  if (isLoading) {
    return (
      <Card>
        <CardHeader>
          {/* Título de sección dentro de página (§14 regla #1), igual que los
              Cards vecinos de la ficha ("Insumos / Receta", "Procedimiento"). */}
          <CardTitle className="text-base font-semibold tracking-tight">
            Producibles ahora
          </CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <Skeleton className="h-16 w-full" />
        </CardContent>
      </Card>
    )
  }

  // Sin receta la sección no existe: no hay nada que deducir. Tampoco se
  // muestra un error ruidoso si la lectura falla — es un dato derivado y
  // secundario, la ficha tiene que seguir siendo usable sin él.
  if (isError || !data?.hasRecipe) {
    return null
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">
          Producibles ahora
        </CardTitle>
        <CardDescription>
          Unidades que salen con el stock actual de los insumos de la receta. Es
          una foto del momento: cambia con cada venta, compra o ajuste.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {data.outlets.length === 0 ? (
          <EmptyState
            icon={PackageSearch}
            title="Sin sucursales para calcular"
            description="Elegí una sucursal en el selector de arriba para ver cuántas unidades salen con su stock."
            ghost={false}
          />
        ) : (
          data.outlets.map((outlet) => (
            <OutletRow key={outlet.outletId} outlet={outlet} bootstrap={bootstrap} />
          ))
        )}
      </CardContent>
    </Card>
  )
}

type BootstrapConfig = ReturnType<typeof useBootstrap>["data"]

function OutletRow({
  outlet,
  bootstrap,
}: {
  outlet: ProducibleOutlet
  bootstrap: BootstrapConfig
}) {
  const [open, setOpen] = React.useState(false)

  // `null` no es 0: significa que ningún insumo de la receta lleva control de
  // stock, o sea que no hay número honesto que dar. Se dice eso, no un
  // "ilimitado" que el dueño leería como una promesa.
  const sinNumero = outlet.capacity === null
  const sinStock = outlet.capacity === 0

  return (
    <Collapsible
      open={open}
      onOpenChange={setOpen}
      className="rounded-md border p-4"
    >
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0 space-y-1">
          <p className="truncate text-sm font-medium">{outlet.outletName}</p>
          <LimitingLine outlet={outlet} bootstrap={bootstrap} />
        </div>

        <div className="flex shrink-0 items-baseline gap-1">
          <span
            className={cn(
              "text-2xl font-semibold tabular-nums",
              sinStock && "text-destructive",
              sinNumero && "text-muted-foreground",
            )}
          >
            {sinNumero ? "—" : formatInt(outlet.capacity, bootstrap)}
          </span>
          {!sinNumero && (
            <span className="text-sm text-muted-foreground">u.</span>
          )}
        </div>
      </div>

      {outlet.ingredients.length > 0 && (
        <>
          <CollapsibleTrigger asChild>
            <Button variant="ghost" size="sm" className="mt-2 -ml-2">
              <ChevronDown
                className={cn("size-4 transition-transform", open && "rotate-180")}
              />
              {open ? "Ocultar desglose" : "Ver desglose"}
            </Button>
          </CollapsibleTrigger>

          <CollapsibleContent className="mt-3">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Insumo</TableHead>
                    <TableHead className="text-right">Necesita por unidad</TableHead>
                    <TableHead className="text-right">Hay</TableHead>
                    <TableHead className="text-right">Alcanza para</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {outlet.ingredients.map((ing) => (
                    <TableRow key={ing.itemId}>
                      <TableCell className="font-medium">
                        <span className="flex items-center gap-2">
                          <span className="min-w-0 truncate">{ing.itemName}</span>
                          {ing.limiting && (
                            <Badge variant="secondary">Limita</Badge>
                          )}
                        </span>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatQty(ing.neededPerUnit, bootstrap)}
                      </TableCell>
                      <TableCell
                        className={cn(
                          "text-right tabular-nums",
                          ing.tracked && ing.onHand !== null && ing.onHand <= 0
                            ? "text-destructive"
                            : undefined,
                        )}
                      >
                        {/* `onHand: null` = insumo sin control de inventario.
                            Es saldo DESCONOCIDO, no cero: pintar un 0 acá le
                            diría al dueño que no tiene sal cuando el paquete
                            está lleno y nunca se cargó al sistema. */}
                        {ing.onHand === null ? (
                          <span className="text-muted-foreground">—</span>
                        ) : (
                          formatQty(ing.onHand, bootstrap)
                        )}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {ing.unitsSupported === null ? (
                          <span className="text-muted-foreground">No limita</span>
                        ) : (
                          formatInt(ing.unitsSupported, bootstrap)
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            <p className="mt-2 text-sm text-muted-foreground">
              Los insumos sin control de stock no limitan: su saldo no se conoce,
              no es cero.
            </p>
          </CollapsibleContent>
        </>
      )}
    </Collapsible>
  )
}

function LimitingLine({
  outlet,
  bootstrap,
}: {
  outlet: ProducibleOutlet
  bootstrap: BootstrapConfig
}) {
  if (outlet.capacity === null || !outlet.limiting) {
    return (
      <p className="text-sm text-muted-foreground">
        Sin insumos con control de stock en la receta.
      </p>
    )
  }

  const { itemName, onHand, unitsSupported } = outlet.limiting

  if (onHand !== null && onHand <= 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Sin stock de <span className="text-foreground">{itemName}</span>.
      </p>
    )
  }

  return (
    <p className="text-sm text-muted-foreground">
      Te limita <span className="text-foreground">{itemName}</span>: hay{" "}
      <span className="tabular-nums">{formatQty(onHand, bootstrap)}</span>,
      alcanzan para{" "}
      <span className="tabular-nums">{formatInt(unitsSupported, bootstrap)}</span>.
    </p>
  )
}
