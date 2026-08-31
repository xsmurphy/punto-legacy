"use client"

/**
 * Tab "Geográfico" del reporte de Análisis de Clientes.
 *
 * Responde "¿en qué zonas viven mis clientes?" en dos filas de escritorio, cada
 * una con el gráfico y la lista que se leen juntos (owner, 2026-08-31):
 *
 *   Fila 1 · barras del top de localidades + tabla completa de localidades.
 *   Fila 2 · mapa de calor de densidad + tabla de ciudades.
 *
 * Bajo `lg` cada fila se apila con el gráfico primero y su lista debajo.
 *
 * ── La cobertura va PRIMERO, y no en letra chica ─────────────────────────────
 * Las coordenadas y la localidad de un cliente existen solo si alguien las
 * cargó. Un mapa de calor armado con el 10% del padrón, presentado como "dónde
 * viven tus clientes", es una conclusión falsa sobre la que el dueño decide
 * dónde repartir. Por eso:
 *   - la cobertura se declara arriba de todo, con el número y el porcentaje;
 *   - por debajo de `COBERTURA_MINIMA_PCT` el mapa NO se dibuja solo: hay que
 *     pedirlo explícitamente, después de leer que la muestra no es
 *     representativa.
 *
 * El alcance tampoco es el del rango de fechas: es el padrón de clientes
 * completo (ver `CustomersService::geography`). Se dice en pantalla porque el
 * selector de fechas está a la vista y sería razonable suponer lo contrario.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts"
import { MapPin } from "lucide-react"

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"
import { formatInt } from "@/lib/format"
import type { CustomersGeo, GeoPlaceRow } from "@/hooks/use-reports"
import type { Bootstrap } from "@/lib/types/bootstrap"

import { CustomersHeatmap } from "./customers-heatmap"

/**
 * Debajo de este porcentaje de clientes con coordenadas, el mapa deja de ser
 * una descripción del padrón y pasa a ser una muestra sesgada (los que alguien
 * se tomó el trabajo de geolocalizar). No se oculta el dato: se deja de
 * mostrarlo COMO SI fuera la respuesta.
 */
const COBERTURA_MINIMA_PCT = 30

/** Localidades en el gráfico de barras — más no se leen de un vistazo. */
const TOP_LOCALIDADES = 12

const localidadesChartConfig = {
  clientes: { label: "Clientes", color: "var(--chart-1)" },
} satisfies ChartConfig

function pct(part: number, total: number): number {
  return total > 0 ? (part / total) * 100 : 0
}

/** Columnas compartidas por los listados de localidades y de ciudades. */
function placeColumns(
  header: string,
  totalClientes: number,
  bootstrap: Bootstrap | undefined
): ColumnDef<GeoPlaceRow>[] {
  return [
    {
      accessorKey: "label",
      header,
      cell: ({ row }) => (
        <div className="flex flex-col">
          <span className="truncate font-medium">{row.original.label}</span>
          {row.original.variantes > 1 && (
            // El dato es texto libre: avisar que el grupo junta varias
            // escrituras evita que el dueño crea que su padrón está prolijo.
            <span className="text-sm text-muted-foreground">
              {row.original.variantes} formas de escribirlo
            </span>
          )}
        </div>
      ),
      meta: { label: header },
    },
    {
      accessorKey: "clientes",
      header: "Clientes",
      cell: ({ getValue }) => (
        <span className="tabular-nums">
          {formatInt(Number(getValue()) || 0, bootstrap)}
        </span>
      ),
      meta: { label: "Clientes", className: "tabular-nums text-right" },
    },
    {
      id: "participacion",
      header: "Participación",
      accessorFn: (r) => pct(r.clientes, totalClientes),
      cell: ({ getValue }) => (
        <span className="text-muted-foreground tabular-nums">
          {(Number(getValue()) || 0).toFixed(1)}%
        </span>
      ),
      meta: { label: "Participación", className: "tabular-nums text-right" },
    },
  ]
}

export function CustomersGeoTab({
  geo,
  isLoading,
  bootstrap,
}: {
  geo: CustomersGeo | undefined
  isLoading: boolean
  bootstrap: Bootstrap | undefined
}) {
  const [mostrarMapaIgual, setMostrarMapaIgual] = React.useState(false)

  const cobertura = geo?.cobertura
  const totalClientes = cobertura?.clientes ?? 0
  const conCoords = cobertura?.conCoordenadas ?? 0
  const coberturaPct = pct(conCoords, totalClientes)
  const coberturaBaja = coberturaPct < COBERTURA_MINIMA_PCT

  const localidades = geo?.localidades ?? []
  const ciudades = geo?.ciudades ?? []
  const puntos = geo?.puntos ?? []

  const topLocalidades = React.useMemo(
    () =>
      localidades
        .slice(0, TOP_LOCALIDADES)
        .map((l) => ({ label: l.label, clientes: l.clientes }))
        .reverse(), // recharts pinta de abajo hacia arriba en layout vertical
    [localidades]
  )

  const localidadColumns = React.useMemo(
    () => placeColumns("Localidad", totalClientes, bootstrap),
    [bootstrap, totalClientes]
  )
  const ciudadColumns = React.useMemo(
    () => placeColumns("Ciudad", totalClientes, bootstrap),
    [bootstrap, totalClientes]
  )

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton className="h-24 w-full" />
        {/* El esqueleto imita las dos filas 2/3 + 1/3 del contenido real: si
            cargara como una sola columna, el layout saltaría al resolver. */}
        <div className="grid gap-4 lg:grid-cols-3">
          <Skeleton className="h-[380px] w-full lg:col-span-2" />
          <Skeleton className="h-[380px] w-full" />
        </div>
        <div className="grid gap-4 lg:grid-cols-3">
          <Skeleton className="h-[420px] w-full lg:col-span-2" />
          <Skeleton className="h-[420px] w-full" />
        </div>
      </div>
    )
  }

  if (!geo || totalClientes === 0) {
    return (
      <EmptyState
        icon={MapPin}
        title="Sin clientes registrados"
        description="Cuando cargues clientes con su dirección vas a ver acá en qué zonas se concentran."
      />
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <StatsRow>
        <StatTile
          label="Clientes registrados"
          value={formatInt(totalClientes, bootstrap)}
          emphasis
        />
        <StatTile
          label="Con ubicación en el mapa"
          value={`${formatInt(conCoords, bootstrap)} (${coberturaPct.toFixed(0)}%)`}
          tone={coberturaBaja ? "negative" : "positive"}
        />
        <StatTile
          label="Con localidad"
          value={`${formatInt(cobertura?.conLocalidad ?? 0, bootstrap)} (${pct(cobertura?.conLocalidad ?? 0, totalClientes).toFixed(0)}%)`}
        />
        <StatTile
          label="Con ciudad"
          value={`${formatInt(cobertura?.conCiudad ?? 0, bootstrap)} (${pct(cobertura?.conCiudad ?? 0, totalClientes).toFixed(0)}%)`}
        />
      </StatsRow>

      <p className="text-sm text-muted-foreground">
        Este análisis cubre los {formatInt(totalClientes, bootstrap)} clientes
        activos del comercio y no depende del rango de fechas: es dónde residen,
        no quién compró esta semana. Los datos salen de la dirección de cada
        cliente, así que solo aparece acá lo que alguien cargó.
      </p>

      {/* Barras de localidades a la izquierda y su tabla a la derecha (owner,
          2026-08-31): el gráfico muestra la forma del ranking y la tabla pone
          el nombre completo, el conteo y la participación de CADA localidad,
          incluidas las que no entran al top. Apiladas, comparar una barra con
          su fila obligaba a scrollear. 2/3 y 1/3 por el mismo motivo que la
          fila del mapa: las barras necesitan ancho para que las etiquetas
          inclinadas se lean; la tabla son tres columnas angostas. */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-2">
            <CardTitle>Localidades con más clientes</CardTitle>
            <p className="text-sm text-muted-foreground">
              {localidades.length > 0
                ? `Top ${Math.min(TOP_LOCALIDADES, localidades.length)} de ${formatInt(localidades.length, bootstrap)} localidades, sobre los ${formatInt(cobertura?.conLocalidad ?? 0, bootstrap)} clientes que tienen localidad cargada.`
                : "Ningún cliente tiene localidad cargada."}
            </p>
          </CardHeader>
          <CardContent>
            {topLocalidades.length === 0 ? (
              <div className="flex h-[280px] items-center justify-center">
                <EmptyState
                  icon={MapPin}
                  title="Sin localidades cargadas"
                  description="Completá la localidad en la ficha de tus clientes para ver este ranking."
                  showMarquee={false}
                  className="border-0 p-0"
                />
              </div>
            ) : (
              <ChartContainer
                config={localidadesChartConfig}
                className="h-[320px] w-full"
              >
                {/* Barras VERTICALES (columnas) por pedido del owner. En recharts
                    el default ya es vertical: `layout="vertical"` es justamente
                    el que las acuesta, así que la corrección es SACAR esa prop e
                    invertir los ejes — la categoría al eje X y el conteo al Y. */}
                <BarChart
                  data={topLocalidades}
                  margin={{ top: 4, right: 8, left: 0, bottom: 4 }}
                >
                  <CartesianGrid
                    strokeDasharray="3 3"
                    stroke="var(--border)"
                    vertical={false}
                  />
                  <XAxis
                    dataKey="label"
                    fontSize={10}
                    stroke="var(--muted-foreground)"
                    tickLine={false}
                    axisLine={false}
                    interval={0}
                    angle={-35}
                    textAnchor="end"
                    height={72}
                    // Los nombres de localidad son largos y con las barras de pie
                    // ya no hay 150px de ancho para cada etiqueta: se inclinan y
                    // se recortan. El nombre completo sigue en el tooltip y en la
                      // tabla de al lado, que es donde se lee, no en el eje.
                    tickFormatter={(v: string) =>
                      v.length > 18 ? `${v.slice(0, 17)}…` : v
                    }
                  />
                  <YAxis
                    allowDecimals={false}
                    fontSize={10}
                    width={32}
                    stroke="var(--muted-foreground)"
                    tickLine={false}
                    axisLine={false}
                  />
                  <ChartTooltip
                    cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                    content={<ChartTooltipContent />}
                  />
                  <Bar
                    dataKey="clientes"
                    fill="var(--color-clientes)"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={56}
                  />
                </BarChart>
              </ChartContainer>
            )}
          </CardContent>
        </Card>

        <div className="flex flex-col gap-2">
          <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
            Localidades
          </p>
          <DataTable
            tableId="report-customers-geo-localidades"
            data={localidades}
            columns={localidadColumns}
            getRowId={(r) => r.key}
            searchPlaceholder="Buscar localidad…"
            exportFileName="clientes_por_localidad"
            emptyMessage={
              <EmptyState
                icon={MapPin}
                title="Sin localidades cargadas"
                description="Completá la localidad en la ficha de tus clientes."
              />
            }
          />
        </div>
      </div>

      {/* Mapa a la izquierda y ciudades a la derecha (owner, 2026-08-31).
          Las dos cosas se leen JUNTAS: la mancha dice dónde se concentran y la
          lista pone el nombre y el número a esa mancha. Apiladas obligaban a
          scrollear entre una y otra para cruzarlas.

          2/3 y 1/3: el mapa necesita superficie para que la densidad se
          distinga; la lista es un ranking corto y a ancho completo queda con
          una columna de números perdida a la derecha. Bajo `lg` vuelven a
          apilarse, con el mapa primero. */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-2">
            <CardTitle>Mapa de calor</CardTitle>
            <p className="text-sm text-muted-foreground">
              Densidad sobre los {formatInt(conCoords, bootstrap)} clientes con
              coordenadas cargadas
              {geo.puntosTruncados
                ? " (se dibujan las zonas más densas; el resto se recortó por tamaño)"
                : ""}
              .
            </p>
          </CardHeader>
          <CardContent>
            {conCoords === 0 ? (
              <EmptyState
                icon={MapPin}
                title="Ningún cliente tiene ubicación cargada"
                description="Las coordenadas se cargan desde la ficha del cliente o al registrar una dirección de envío."
                showMarquee={false}
                className="border-0 p-0"
              />
            ) : coberturaBaja && !mostrarMapaIgual ? (
              <Alert>
                <AlertTitle>La muestra no representa a tu clientela</AlertTitle>
                <AlertDescription className="flex flex-col items-start gap-3">
                  <span>
                    Solo {formatInt(conCoords, bootstrap)} de los{" "}
                    {formatInt(totalClientes, bootstrap)} clientes tienen
                    ubicación cargada ({coberturaPct.toFixed(0)}%). Un mapa
                    hecho con esa fracción muestra dónde están los clientes que
                    alguien geolocalizó, no dónde vive tu clientela — no sirve
                    para decidir zonas de reparto ni de cobertura. Cargá la
                    ubicación en más fichas y volvé.
                  </span>
                  <Button
                    variant="outline"
                    onClick={() => setMostrarMapaIgual(true)}
                  >
                    Ver el mapa igual
                  </Button>
                </AlertDescription>
              </Alert>
            ) : (
              <div className="flex flex-col gap-2">
                {coberturaBaja && (
                  <p className="text-sm text-muted-foreground">
                    Cobertura del {coberturaPct.toFixed(0)}%: la mancha describe
                    a {formatInt(conCoords, bootstrap)} clientes, no a los{" "}
                    {formatInt(totalClientes, bootstrap)}.
                  </p>
                )}
                <CustomersHeatmap points={puntos} />
              </div>
            )}
          </CardContent>
        </Card>

        <div className="flex flex-col gap-2">
          <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
            Ciudades
          </p>
          <DataTable
            tableId="report-customers-geo-ciudades"
            data={ciudades}
            columns={ciudadColumns}
            getRowId={(r) => r.key}
            searchPlaceholder="Buscar ciudad…"
            exportFileName="clientes_por_ciudad"
            emptyMessage={
              <EmptyState
                icon={MapPin}
                title="Sin ciudades cargadas"
                description="Completá la ciudad en la ficha de tus clientes."
              />
            }
          />
        </div>
      </div>
    </div>
  )
}
