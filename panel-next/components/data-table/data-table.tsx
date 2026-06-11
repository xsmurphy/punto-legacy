"use client"

import * as React from "react"
import {
  type ColumnDef,
  type ColumnFiltersState,
  type SortingState,
  type VisibilityState,
  flexRender,
  getCoreRowModel,
  getFilteredRowModel,
  getPaginationRowModel,
  getSortedRowModel,
  useReactTable,
} from "@tanstack/react-table"
import { ArrowDown, ArrowUp, ChevronsUpDown, Download, Search, SlidersHorizontal } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Skeleton } from "@/components/ui/skeleton"
import { cn } from "@/lib/utils"

export interface DataTableProps<T> {
  /** Stable id del listado — usado para persistir visibilidad de columnas en localStorage. */
  tableId: string
  data: T[]
  columns: ColumnDef<T, unknown>[]
  /** Función para resolver el id de cada row (uuid en BD); habilita keys estables. */
  getRowId?: (row: T) => string
  /** Click en la row (no en un button/link interno). Útil para navegar al detalle. */
  onRowClick?: (row: T) => void
  /** Placeholder del search global. Default: "Buscar…". */
  searchPlaceholder?: string
  /** Nombre del archivo de export (sin extensión). Si null, oculta el botón. */
  exportFileName?: string | null
  /** Mensaje en estado vacío. */
  emptyMessage?: React.ReactNode
  /** Skeleton rows mientras la query carga. */
  isLoading?: boolean
  /** Toolbar custom adicional a la izquierda (filtros por columna, etc). */
  toolbarSlot?: React.ReactNode
  /** Page size default. 25 por defecto. */
  pageSize?: number
}

export function DataTable<T>({
  tableId,
  data,
  columns,
  getRowId,
  onRowClick,
  searchPlaceholder = "Buscar…",
  exportFileName,
  emptyMessage,
  isLoading,
  toolbarSlot,
  pageSize = 25,
}: DataTableProps<T>) {
  const [sorting, setSorting] = React.useState<SortingState>([])
  const [columnFilters, setColumnFilters] = React.useState<ColumnFiltersState>([])
  const [globalFilter, setGlobalFilter] = React.useState("")
  const [columnVisibility, setColumnVisibility] = React.useState<VisibilityState>(
    () => loadVisibility(tableId),
  )

  // Persistir visibilidad por table id.
  React.useEffect(() => {
    saveVisibility(tableId, columnVisibility)
  }, [tableId, columnVisibility])

  const table = useReactTable<T>({
    data,
    columns,
    state: { sorting, columnFilters, globalFilter, columnVisibility },
    getRowId: getRowId ? (row) => getRowId(row) : undefined,
    onSortingChange: setSorting,
    onColumnFiltersChange: setColumnFilters,
    onGlobalFilterChange: setGlobalFilter,
    onColumnVisibilityChange: setColumnVisibility,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getPaginationRowModel: getPaginationRowModel(),
    initialState: { pagination: { pageSize } },
  })

  const handleExport = async () => {
    if (!exportFileName) return
    const rows = table.getFilteredRowModel().rows
    const visibleCols = table.getVisibleLeafColumns().filter((c) => c.id !== "actions")
    await exportToXlsx(rows, visibleCols, exportFileName)
  }

  return (
    <div className="flex flex-col gap-3">
      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={globalFilter ?? ""}
            onChange={(e) => setGlobalFilter(e.target.value)}
            placeholder={searchPlaceholder}
            className="h-9 pl-8"
          />
        </div>

        {toolbarSlot}

        <div className="ml-auto flex items-center gap-2">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm" className="h-9">
                <SlidersHorizontal className="size-3.5" />
                <span className="hidden sm:inline">Columnas</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuLabel>Mostrar columnas</DropdownMenuLabel>
              <DropdownMenuSeparator />
              {table.getAllColumns()
                .filter((c) => c.getCanHide())
                .map((column) => (
                  <DropdownMenuCheckboxItem
                    key={column.id}
                    checked={column.getIsVisible()}
                    onCheckedChange={(v) => column.toggleVisibility(!!v)}
                    onSelect={(e) => e.preventDefault()}
                  >
                    {columnLabel(column.columnDef as ColumnDef<unknown, unknown>)}
                  </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
          </DropdownMenu>

          {exportFileName && (
            <Button variant="outline" size="sm" className="h-9" onClick={handleExport}>
              <Download className="size-3.5" />
              <span className="hidden sm:inline">Excel</span>
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-md border overflow-hidden">
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((hg) => (
              <TableRow key={hg.id}>
                {hg.headers.map((header) => {
                  const sortDir = header.column.getIsSorted()
                  const canSort = header.column.getCanSort()
                  return (
                    <TableHead key={header.id} className={header.column.columnDef.meta?.className}>
                      {header.isPlaceholder ? null : canSort ? (
                        <button
                          type="button"
                          onClick={header.column.getToggleSortingHandler()}
                          className="-ml-2 inline-flex h-8 items-center gap-1 rounded px-2 text-xs font-medium hover:bg-accent"
                        >
                          {flexRender(header.column.columnDef.header, header.getContext())}
                          {sortDir === "asc" ? (
                            <ArrowUp className="size-3" />
                          ) : sortDir === "desc" ? (
                            <ArrowDown className="size-3" />
                          ) : (
                            <ChevronsUpDown className="size-3 opacity-40" />
                          )}
                        </button>
                      ) : (
                        flexRender(header.column.columnDef.header, header.getContext())
                      )}
                    </TableHead>
                  )
                })}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isLoading && (
              <>
                {Array.from({ length: 5 }).map((_, i) => (
                  <TableRow key={`sk-${i}`}>
                    {table.getVisibleLeafColumns().map((col, j) => (
                      <TableCell key={`sk-${i}-${j}`}>
                        <Skeleton className="h-4 w-full max-w-32" />
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </>
            )}
            {!isLoading && table.getRowModel().rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={table.getVisibleLeafColumns().length}>
                  <div className="py-12 text-center text-sm text-muted-foreground">
                    {emptyMessage ?? "Sin resultados."}
                  </div>
                </TableCell>
              </TableRow>
            )}
            {!isLoading &&
              table.getRowModel().rows.map((row) => (
                <TableRow
                  key={row.id}
                  className={cn(onRowClick && "cursor-pointer")}
                  onClick={() => onRowClick?.(row.original)}
                >
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id} className={cell.column.columnDef.meta?.className}>
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      {table.getPageCount() > 1 && (
        <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
          <span>
            {table.getFilteredRowModel().rows.length}{" "}
            {table.getFilteredRowModel().rows.length === 1 ? "resultado" : "resultados"}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => table.previousPage()}
              disabled={!table.getCanPreviousPage()}
            >
              Anterior
            </Button>
            <span className="tabular-nums">
              {table.getState().pagination.pageIndex + 1} / {table.getPageCount()}
            </span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => table.nextPage()}
              disabled={!table.getCanNextPage()}
            >
              Siguiente
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

// ── Helpers ─────────────────────────────────────────────────────────────

function loadVisibility(tableId: string): VisibilityState {
  if (typeof window === "undefined") return {}
  try {
    const raw = window.localStorage.getItem(`punto.dt.cols.${tableId}`)
    return raw ? JSON.parse(raw) : {}
  } catch {
    return {}
  }
}

function saveVisibility(tableId: string, v: VisibilityState) {
  if (typeof window === "undefined") return
  try {
    window.localStorage.setItem(`punto.dt.cols.${tableId}`, JSON.stringify(v))
  } catch {
    // localStorage full / disabled — ignorable, sólo perdemos persistencia.
  }
}

function columnLabel(colDef: ColumnDef<unknown, unknown>): string {
  // header puede ser string o ReactNode/function. Para el toggle queremos string.
  if (typeof colDef.header === "string") return colDef.header
  if (colDef.meta?.label) return colDef.meta.label
  return String(colDef.id ?? "Columna")
}

/**
 * Export a XLSX con exceljs. Respeta:
 *   - Filtros activos (table.getFilteredRowModel())
 *   - Columnas visibles (excluye 'actions')
 *   - Headers como string (o meta.label si el header no es string)
 * Lazy-import de exceljs para no inflar el bundle inicial.
 */
async function exportToXlsx<T>(
  rows: { getValue(id: string): unknown; original: T }[],
  cols: Array<{ id: string; columnDef: ColumnDef<T, unknown> }>,
  fileName: string,
) {
  const { default: ExcelJS } = await import("exceljs")
  const wb = new ExcelJS.Workbook()
  const ws = wb.addWorksheet("Datos")

  ws.columns = cols.map((c) => ({
    header: columnLabel(c.columnDef as ColumnDef<unknown, unknown>),
    key: c.id,
    width: 22,
  }))
  ws.getRow(1).font = { bold: true }

  for (const row of rows) {
    const obj: Record<string, unknown> = {}
    for (const c of cols) {
      const value = row.getValue(c.id)
      obj[c.id] = formatCellForExport(value)
    }
    ws.addRow(obj)
  }

  const buf = await wb.xlsx.writeBuffer()
  triggerDownload(new Blob([buf as ArrayBuffer], {
    type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  }), `${fileName}.xlsx`)
}

function formatCellForExport(v: unknown): string | number | null {
  if (v === null || v === undefined) return null
  if (typeof v === "boolean") return v ? "Sí" : "No"
  if (typeof v === "number") return v
  if (typeof v === "string") return v
  return JSON.stringify(v)
}

function triggerDownload(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement("a")
  a.href = url
  a.download = fileName
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// Extensión del meta para que los `columnDef` puedan declarar className y label
// sin perder el typing genérico de TanStack Table.
declare module "@tanstack/react-table" {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnMeta<TData extends unknown, TValue> {
    className?: string
    label?: string
  }
}
