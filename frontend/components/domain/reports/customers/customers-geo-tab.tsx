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
 *   - por debajo de `COBERTURA_MINIMA_PCT` el mapa se dibuja IGUAL, pero con
 *     la advertencia encima. Hasta el 2026-08-31 había que apretar un botón
 *     para verlo y el owner preguntó por qué: la preocupación es real, pero
 *     alcanza con decirlo. Esconder el dato detrás de un clic no informa
 *     mejor — agrega fricción en cada visita y asume que nadie lee.
 *
 * El alcance tampoco es el del rango de fechas: es el padrón de clientes
 * completo (ver `CustomersService::geography`). Se dice en pantalla porque el
 * selector de fechas está a la vista y sería razonable suponer lo contrario.
 */

import * as React from "react"
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts"
import { MapPin } from "lucide-react"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
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

/**
 * Listado simple de lugares — SIN DataTable, por pedido del owner
 * (2026-08-31: "no es necesario usar datatables para listar localidades ni
 * ciudades, puede ser un listado simple sin buscador ni filtros ni
 * paginación").
 *
 * Tenía razón: `<DataTable>` es la convención para LISTADOS LARGOS, donde
 * buscar, ordenar y exportar son lo que hace usable la pantalla. Acá son dos
 * rankings cortos que se leen de un vistazo y viven al lado del mapa; el
 * chrome de la tabla pesaba más que el dato que mostraba.
 *
 * El total de cada lista sigue disponible en la exportación del tab, así que
 * no se pierde nada: solo deja de ocupar espacio en pantalla.
 */
function PlaceList({
  title,
  rows,
  totalClientes,
  bootstrap,
  emptyTitle,
  emptyDescription,
}: {
  title: string
  rows: GeoPlaceRow[]
  totalClientes: number
  bootstrap: Bootstrap | undefined
  emptyTitle: string
  emptyDescription: string
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent>
        {rows.length === 0 ? (
          <EmptyState
            icon={MapPin}
            title={emptyTitle}
            description={emptyDescription}
            showMarquee={false}
            className="border-0 p-0"
          />
        ) : (
          // `max-h` + scroll: la lista puede tener decenas de entradas y no
          // debe estirar la fila más allá del bloque que tiene al lado.
          <ul className="flex max-h-[420px] flex-col overflow-y-auto">
            {rows.map((r) => (
              <li
                key={r.key}
                className="flex items-baseline justify-between gap-3 border-b border-border py-2 last:border-0"
              >
                <div className="flex min-w-0 flex-col">
                  <span className="truncate text-sm font-medium">
                    {r.label}
                  </span>
                  {r.variantes > 1 && (
                    // El dato es texto libre: avisar que el grupo junta varias
                    // escrituras evita que el dueño crea que su padrón está
                    // prolijo.
                    <span className="text-xs text-muted-foreground">
                      {r.variantes} formas de escribirlo
                    </span>
                  )}
                </div>
                <div className="flex shrink-0 items-baseline gap-2">
                  <span className="text-sm font-semibold tabular-nums">
                    {formatInt(r.clientes, bootstrap)}
                  </span>
                  <span className="text-xs text-muted-foreground tabular-nums">
                    {pct(r.clientes, totalClientes).toFixed(1)}%
                  </span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
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
        .map((l) => ({ label: l.label, clientes: l.clientes })),
      // Sin `.reverse()`: hacía falta con las barras acostadas, donde recharts
      // pinta de abajo hacia arriba. De pie, el orden del array ES el orden de
      // izquierda a derecha, así que invertirlo dejaba la más alta al final.
    [localidades]
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
      {/* FILA 1 · mapa | localidades — FILA 2 · ciudades | barras.
          Orden pedido por el owner (2026-08-31). El mapa abre el tab porque es
          la lectura de un vistazo: dónde se concentran. Las barras cierran,
          como el detalle que confirma lo que el mapa insinuó.

          Cada fila cruza un bloque ancho con su lista: se leen juntos y no hay
          que scrollear para pasar de la mancha al nombre. Bajo `lg` se apilan
          en este mismo orden. */}
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
            ) : (
              <CustomersHeatmap points={puntos} />
            )}
          </CardContent>
        </Card>

        <PlaceList
          title="Localidades"
          rows={localidades}
          totalClientes={totalClientes}
          bootstrap={bootstrap}
          emptyTitle="Sin localidades cargadas"
          emptyDescription="Completá la localidad en la ficha de tus clientes."
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <PlaceList
          title="Ciudades"
          rows={ciudades}
          totalClientes={totalClientes}
          bootstrap={bootstrap}
          emptyTitle="Sin ciudades cargadas"
          emptyDescription="Completá la ciudad en la ficha de tus clientes."
        />

        <Card className="lg:col-span-2">
          <CardHeader className="pb-2">
            <CardTitle>Localidades con más clientes</CardTitle>
            <p className="text-sm text-muted-foreground">
              Las {TOP_LOCALIDADES} con más clientes, de mayor a menor.
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
      </div>
    </div>
  )
}
