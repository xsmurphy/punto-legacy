"use client"

import * as React from "react"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Badge } from "@/components/ui/badge"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { useAdminMigration, type AdminMigrationStatus } from "@/hooks/use-admin"
import { formatDateTime } from "@/lib/format-date"

const STATUS_LABEL: Record<AdminMigrationStatus, string> = {
  pending: "En cola",
  running: "Importando",
  done: "Lista",
  failed: "Con errores",
}

const STATUS_VARIANT: Record<AdminMigrationStatus, "default" | "secondary" | "destructive" | "outline"> = {
  pending: "outline",
  running: "secondary",
  done: "default",
  failed: "destructive",
}

const DOMAIN_LABEL: Record<string, string> = {
  catalog: "Catálogo",
  customers: "Clientes",
  config: "Configuración",
  users: "Usuarios",
  payments: "Medios de pago",
  // La apertura de inventario. `failed` acá NO es un error del job: es la
  // cuenta de artículos que quedaron SIN abrir por no saberse su costo, y que
  // la bitácora nombra uno por uno para que soporte los complete.
  stock: "Stock inicial",
  category: "Categorías",
  brand: "Marcas",
  tag: "Etiquetas",
  item: "Artículos",
  // Combos y recetas: se cuentan aparte de los artículos porque son una
  // segunda pasada sobre los mismos ítems (primero existen, después se
  // componen), y porque es la fila donde el operador ve cuántos quedaron para
  // revisar a mano.
  compound: "Combos y recetas",
  customer: "Clientes",
  outlet: "Sucursales",
  register: "Cajas",
  user: "Usuarios",
  payment: "Medios de pago",
  // Histórico (F2). `failed` acá cuenta los documentos que NO se asentaron
  // —casi siempre por una referencia sin migrar (sucursal, usuario)— y la
  // bitácora dice cuál falta en cada caso.
  sales_history: "Ventas históricas",
  purchases_history: "Compras históricas",
  expenses_history: "Movimientos de caja históricos",
  job: "Migración",
}

interface Counts {
  total: number
  imported: number
  skipped: number
  failed: number
  // Líneas de detalle asentadas. Solo la traen los dominios que tienen líneas
  // (ventas y compras); en el resto no se muestra nada en vez de un 0 que se
  // leería como "no entró ninguna".
  lines?: number
}

function isCounts(v: unknown): v is Counts {
  return typeof v === "object" && v !== null && typeof (v as Counts).imported === "number"
}

export function MigrationDetailDialog({
  jobId,
  onOpenChange,
}: {
  jobId: string | null
  onOpenChange: (v: boolean) => void
}) {
  const { data, isLoading } = useAdminMigration(jobId ?? "")

  // `progress` mezcla los conteos por dominio con `options`, que no es un
  // conteo: se filtra por forma y no por nombre, así sumar una opción nueva
  // no obliga a tocar esta lista.
  const rows = React.useMemo(() => {
    if (!data?.progress) return []
    return Object.entries(data.progress).filter(([, v]) => isCounts(v)) as Array<[string, Counts]>
  }, [data?.progress])

  return (
    <Dialog open={jobId !== null} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>Detalle de la migración</DialogTitle>
          <DialogDescription>
            {data?.companyName ? `Destino: ${data.companyName}` : "Cargando…"}
          </DialogDescription>
        </DialogHeader>

        {isLoading && <p className="text-sm text-muted-foreground">Cargando el detalle…</p>}

        {data && (
          <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
              <Badge variant={STATUS_VARIANT[data.status]}>{STATUS_LABEL[data.status] ?? data.status}</Badge>
              <span className="text-sm text-muted-foreground">
                Lanzada {data.createdAt ? formatDateTime(data.createdAt) : "—"}
                {data.finishedAt ? ` · terminó ${formatDateTime(data.finishedAt)}` : ""}
              </span>
            </div>

            {data.status === "pending" && (
              <Alert>
                <AlertDescription>
                  En cola. El importador la levanta en menos de dos minutos y esta pantalla se actualiza sola.
                </AlertDescription>
              </Alert>
            )}

            {rows.length > 0 ? (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Dominio</TableHead>
                      <TableHead className="text-right">Encontrados</TableHead>
                      <TableHead className="text-right">Importados</TableHead>
                      {/* Sin esta columna, "300 ventas importadas" se lee como
                          éxito aunque no haya entrado una sola línea de detalle
                          — que es exactamente lo que pasó en la primera
                          migración real. */}
                      <TableHead className="text-right">Líneas</TableHead>
                      <TableHead className="text-right">Ya estaban</TableHead>
                      <TableHead className="text-right">Con error</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {rows.map(([domain, c]) => (
                      <TableRow key={domain}>
                        <TableCell className="font-medium">{DOMAIN_LABEL[domain] ?? domain}</TableCell>
                        <TableCell className="text-right">{c.total}</TableCell>
                        <TableCell className="text-right">{c.imported}</TableCell>
                        <TableCell className="text-right">
                          {typeof c.lines === "number" ? c.lines : "—"}
                        </TableCell>
                        <TableCell className="text-right">{c.skipped}</TableCell>
                        <TableCell className="text-right">{c.failed}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            ) : (
              data.status !== "pending" && (
                <p className="text-sm text-muted-foreground">Todavía no hay nada importado.</p>
              )
            )}

            {data.errors.length > 0 && (
              <Alert variant="destructive">
                <AlertTitle>
                  {data.errors.length === 1 ? "Un problema" : `${data.errors.length} problemas`} durante la
                  importación
                </AlertTitle>
                <AlertDescription>
                  <ul className="mt-2 flex list-disc flex-col gap-1 pl-4">
                    {data.errors.map((e, i) => (
                      <li key={i} className="text-sm">
                        <span className="font-medium">{DOMAIN_LABEL[e.domain] ?? e.domain}:</span> {e.message}
                      </li>
                    ))}
                  </ul>
                </AlertDescription>
              </Alert>
            )}

            {(data.log?.length ?? 0) > 0 && (
              <div className="flex flex-col gap-2">
                <p className="mb-0 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Notas
                </p>
                <ul className="flex flex-col gap-1">
                  {data.log?.map((l, i) => (
                    <li key={i} className="text-sm text-muted-foreground">
                      {l.message}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
