"use client"

import * as React from "react"
import {
  type ColumnDef,
  type ColumnFiltersState,
  type RowSelectionState,
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
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import {
  Select as SelectRoot,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
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
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Skeleton } from "@/components/ui/skeleton"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatInt } from "@/lib/format"
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
  /**
   * Búsqueda controlada por el caller (server-side). Si se pasa junto con
   * `onSearchChange`, el input de búsqueda queda controlado desde afuera y
   * el filtro de texto client-side (`globalFilter` sobre `data`) se
   * desactiva — se asume que `data` ya llegó filtrada del servidor por ese
   * mismo término. Sin `onSearchChange`, el buscador sigue siendo 100%
   * client-side sobre `data` (comportamiento default, sin cambios).
   */
  searchValue?: string
  onSearchChange?: (value: string) => void
  /**
   * Total real en el servidor cuando `data` es un subset (ej. un LIMIT del
   * backend). Si se pasa y es mayor a las filas cargadas, el contador de
   * resultados muestra "Mostrando X de Y" en vez de "X resultados", para que
   * quede claro que hay más allá de lo cargado.
   */
  totalCount?: number
  /** Nombre del archivo de export (sin extensión). Si null, oculta el botón. */
  exportFileName?: string | null
  /** Mensaje en estado vacío. */
  emptyMessage?: React.ReactNode
  /** Skeleton rows mientras la query carga. */
  isLoading?: boolean
  /** Toolbar custom adicional a la izquierda (filtros por columna, etc). */
  toolbarSlot?: React.ReactNode
  /** Slot a la DERECHA del toolbar, pegado al column-toggle (Columnas). */
  rightToolbarSlot?: React.ReactNode
  /** Page size default. 25 por defecto. */
  pageSize?: number
  /** Habilita selección por checkbox + barra de bulk actions. */
  enableSelection?: boolean
  /** Render de la barra de acciones cuando hay filas seleccionadas. Recibe los rows seleccionados. */
  bulkActions?: (selected: T[], clearSelection: () => void) => React.ReactNode
  /** Visibilidad inicial de columnas. Solo aplica si no hay valor persistido en localStorage. */
  initialColumnVisibility?: VisibilityState
  /**
   * Fija la primera columna de datos (nombre del producto / cliente / sucursal)
   * al hacer scroll horizontal. Default: true.
   *
   * Con muchas columnas, al desplazarse a la derecha se pierde de vista de qué
   * fila se está leyendo y hay que volver al inicio para ubicarse. La columna
   * identificatoria siempre es la primera, así que se fija esa.
   *
   * Se puede apagar en tablas angostas donde nunca hay scroll horizontal y la
   * sombra del borde sería ruido visual.
   */
  stickyFirstColumn?: boolean
}

export function DataTable<T>({
  tableId,
  data,
  columns,
  getRowId,
  onRowClick,
  searchPlaceholder = "Buscar…",
  searchValue,
  onSearchChange,
  totalCount,
  exportFileName,
  emptyMessage,
  isLoading,
  toolbarSlot,
  rightToolbarSlot,
  pageSize = 25,
  enableSelection,
  bulkActions,
  initialColumnVisibility,
  stickyFirstColumn = true,
}: DataTableProps<T>) {
  // El footer de sumas se renderiza en el primer paint, así que pasa por SSR:
  // un `toLocaleString()` sin locale explícito daba "1,234" en el server y
  // "1.234" en el browser = React #418. Acá se arregla el default de TODAS las
  // tablas de una vez, no tabla por tabla.
  const { data: bootstrapForFooter } = useBootstrap()
  const [sorting, setSorting] = React.useState<SortingState>([])
  const [columnFilters, setColumnFilters] = React.useState<ColumnFiltersState>([])
  const [globalFilter, setGlobalFilter] = React.useState("")
  // Búsqueda externa (server-side): con `onSearchChange` el input lo maneja
  // el caller y `data` ya llega filtrada — el `globalFilter` de la tabla
  // queda forzado a "" para no re-filtrar client-side sobre datos que ya
  // pasaron por el filtro del servidor.
  const isSearchControlled = onSearchChange !== undefined
  const [columnVisibility, setColumnVisibility] = React.useState<VisibilityState>(
    () => {
      const persisted = loadVisibility(tableId)
      // Si no hay valor persistido para esta tabla, arrancamos con los defaults.
      return Object.keys(persisted).length === 0 ? (initialColumnVisibility ?? {}) : persisted
    },
  )
  const [rowSelection, setRowSelection] = React.useState<RowSelectionState>({})

  // Persistir visibilidad por table id.
  React.useEffect(() => {
    saveVisibility(tableId, columnVisibility)
  }, [tableId, columnVisibility])

  // Prepend de la columna de selección si está habilitada.
  const finalColumns = React.useMemo<ColumnDef<T, unknown>[]>(() => {
    if (!enableSelection) return columns
    const selectionCol: ColumnDef<T, unknown> = {
      id: "_select",
      enableSorting: false,
      enableHiding: false,
      header: ({ table }) => (
        <Checkbox
          aria-label="Seleccionar todo"
          checked={
            table.getIsAllPageRowsSelected()
              ? true
              : table.getIsSomePageRowsSelected()
              ? "indeterminate"
              : false
          }
          onCheckedChange={(v) => table.toggleAllPageRowsSelected(!!v)}
        />
      ),
      cell: ({ row }) => (
        <Checkbox
          aria-label="Seleccionar fila"
          checked={row.getIsSelected()}
          onCheckedChange={(v) => row.toggleSelected(!!v)}
          onClick={(e) => e.stopPropagation()}
        />
      ),
      meta: { className: "w-10" },
    }
    return [selectionCol, ...columns]
  }, [columns, enableSelection])

  const table = useReactTable<T>({
    data,
    columns: finalColumns,
    state: {
      sorting,
      columnFilters,
      globalFilter: isSearchControlled ? "" : globalFilter,
      columnVisibility,
      rowSelection,
    },
    getRowId: getRowId ? (row) => getRowId(row) : undefined,
    enableRowSelection: !!enableSelection,
    onSortingChange: setSorting,
    onColumnFiltersChange: setColumnFilters,
    onGlobalFilterChange: setGlobalFilter,
    onColumnVisibilityChange: setColumnVisibility,
    onRowSelectionChange: setRowSelection,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getPaginationRowModel: getPaginationRowModel(),
    initialState: { pagination: { pageSize } },
  })

  const selectedRows = React.useMemo(
    () => table.getSelectedRowModel().rows.map((r) => r.original),
    [table, rowSelection], // eslint-disable-line react-hooks/exhaustive-deps
  )
  const clearSelection = React.useCallback(() => setRowSelection({}), [])

  const handleExport = async () => {
    if (!exportFileName) return
    const rows = table.getFilteredRowModel().rows
    const visibleCols = table.getVisibleLeafColumns().filter((c) => c.id !== "actions")
    await exportToXlsx(rows, visibleCols, exportFileName)
  }

  const selectedCount = selectedRows.length

  // Footer de sumatoria: solo se calcula si al menos una columna visible
  // declaró `meta.footerSum`. La suma toma SIEMPRE `getFilteredRowModel()`
  // (todas las filas que pasan el filtro/búsqueda activos), NUNCA solo la
  // página visible — el pie representa el total del período filtrado, no
  // de la página actual.
  const visibleLeafColumns = table.getVisibleLeafColumns()

  // Ancho REAL de la columna de selección, medido del DOM.
  //
  // Estaba hardcodeado en `left-10` (40px) asumiendo el `w-10` de la columna,
  // pero `w-*` en una tabla con layout automático es una sugerencia: el ancho
  // final lo define el contenido más el padding de la celda. La columna medía
  // más de 40px, así que el nombre se fijaba 8px a la izquierda de donde
  // termina el checkbox y se montaba sobre él.
  //
  // Medirlo es lo único que no se desincroniza cuando cambie el padding del
  // primitive Table o el tamaño del checkbox.
  const selectColRef = React.useRef<HTMLTableCellElement | null>(null)
  const [selectColWidth, setSelectColWidth] = React.useState(0)

  React.useLayoutEffect(() => {
    if (!enableSelection || !stickyFirstColumn) return
    const el = selectColRef.current
    if (!el) return
    const medir = () => setSelectColWidth(el.getBoundingClientRect().width)
    medir()
    // El ancho cambia al re-renderizar la tabla (otro dataset, otra fuente).
    const ro = new ResizeObserver(medir)
    ro.observe(el)
    return () => ro.disconnect()
  }, [enableSelection, stickyFirstColumn])

  /**
   * Fija la columna identificatoria al hacer scroll horizontal.
   *
   * Se fija por ÍNDICE de columna visible, no por id: cada listado nombra su
   * primera columna distinto (itemName, contactName, outletName…) y el wrapper
   * no puede conocerlos a todos. Cuando la selección está activa, la columna 0
   * es el checkbox y se fijan las dos.
   *
   * El fondo opaco es obligatorio: sin él, las celdas que pasan por debajo se
   * ven a través de la columna fija.
   */
  function stickyCols(
    index: number,
    variant: "head" | "cell" | "foot",
  ): { className?: string; style?: React.CSSProperties } {
    if (!stickyFirstColumn) return {}

    const idxCheckbox = enableSelection ? 0 : -1
    const idxNombre   = enableSelection ? 1 : 0
    if (index !== idxCheckbox && index !== idxNombre) return {}

    const fondo =
      variant === "head" ? "bg-muted" : variant === "foot" ? "bg-muted/50" : "bg-background"

    return {
      className: cn(
        "sticky z-20",
        fondo,
        // El borde marca el corte solo en la columna del nombre (la última del
        // bloque fijo): en el checkbox quedaría en el medio del bloque.
        index === idxNombre &&
          "after:absolute after:inset-y-0 after:right-0 after:w-px after:bg-border",
      ),
      // `left` va inline y no como clase: el offset del nombre es el ancho
      // medido del checkbox, un valor que Tailwind no puede expresar.
      style: { left: index === idxCheckbox ? 0 : enableSelection ? selectColWidth : 0 },
    }
  }
  const hasFooterSum = visibleLeafColumns.some((c) => c.columnDef.meta?.footerSum)
  const footerSums = React.useMemo(() => {
    if (!hasFooterSum) return {}
    const filteredRows = table.getFilteredRowModel().rows
    const sums: Record<string, number> = {}
    for (const col of visibleLeafColumns) {
      if (!col.columnDef.meta?.footerSum) continue
      let sum = 0
      for (const row of filteredRows) {
        const n = Number(row.getValue(col.id))
        if (!isNaN(n)) sum += n
      }
      sums[col.id] = sum
    }
    return sums
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hasFooterSum, table.getFilteredRowModel().rows, columnVisibility])

  return (
    // `min-w-0`: sin esto el ancho intrínseco de la tabla empuja al contenedor
    // padre (grid item de un Dialog, hijo flex de un layout) porque su
    // min-width resuelve a `auto` — la tabla se sale del modal o de la página
    // en vez de scrollear. Con min-w-0 el contenedor se limita a lo
    // disponible y el `overflow-x-auto` del primitive Table hace su trabajo.
    // Ver también el mismo fix en SidebarInset (components/ui/sidebar.tsx).
    <div className="flex min-w-0 flex-col gap-3">
      {/* Bulk action bar */}
      {enableSelection && selectedCount > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-md border border-primary/30 bg-primary/5 px-3 py-2">
          <span className="text-xs font-medium">
            {selectedCount} {selectedCount === 1 ? "fila seleccionada" : "filas seleccionadas"}
          </span>
          <Button
            variant="ghost"
            size="sm"
            className="h-7 text-xs"
            onClick={clearSelection}
          >
            Limpiar
          </Button>
          <div className="ml-auto flex flex-wrap items-center gap-2">
            {bulkActions?.(selectedRows, clearSelection)}
          </div>
        </div>
      )}

      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={isSearchControlled ? searchValue ?? "" : globalFilter ?? ""}
            onChange={(e) =>
              isSearchControlled
                ? onSearchChange?.(e.target.value)
                : setGlobalFilter(e.target.value)
            }
            placeholder={searchPlaceholder}
            className="h-9 pl-8"
          />
        </div>

        {toolbarSlot}

        <div className="ml-auto flex items-center gap-2">
          {rightToolbarSlot}
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

      {/* Table — sin bordes externos. `border-y` deja un borde sutil arriba
          del header y debajo de la última row para enmarcar visualmente;
          `[&_tr]:border-b` mantiene los divisores entre rows. Cells con
          `py-3.5 px-3` agrandan la altura de cada row para respirar mejor. */}
      <div className="min-w-0 border-y [&_tr]:border-b last:[&_tr]:border-b-0 [&_td]:py-3.5 [&_td]:px-3 [&_th]:px-3 [&_th]:h-11">
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((hg) => (
              <TableRow key={hg.id}>
                {hg.headers.map((header, i) => {
                  const sortDir = header.column.getIsSorted()
                  const canSort = header.column.getCanSort()
                  return (
                    <TableHead
                      key={header.id}
                      ref={enableSelection && i === 0 ? selectColRef : undefined}
                      className={cn(header.column.columnDef.meta?.className, stickyCols(i, "head").className)}
                      style={stickyCols(i, "head").style}
                    >
                      {/* La tipografía del encabezado (`text-xs font-medium`)
                          vive acá y NO dentro del botón de orden: cuando estaba
                          solo en el botón, una columna no ordenable se pintaba
                          cruda y heredaba el tamaño del <th>, así que en la
                          misma fila convivían encabezados de dos tamaños
                          distintos. El `h-8` iguala el alto para que la línea
                          base no salte entre una columna y la siguiente. */}
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
                        <span className="inline-flex h-8 items-center text-xs font-medium">
                          {flexRender(header.column.columnDef.header, header.getContext())}
                        </span>
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
                  {row.getVisibleCells().map((cell, i) => (
                    <TableCell
                      key={cell.id}
                      className={cn(cell.column.columnDef.meta?.className, stickyCols(i, "cell").className)}
                      style={stickyCols(i, "cell").style}
                    >
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))}
          </TableBody>
          {hasFooterSum && (
            <TableFooter>
              <TableRow className="hover:bg-transparent">
                {visibleLeafColumns.map((col, i) => {
                  const meta = col.columnDef.meta
                  if (meta?.footerSum) {
                    const sum = footerSums[col.id] ?? 0
                    return (
                      <TableCell
                        key={col.id}
                        className={cn(meta.className, stickyCols(i, "foot").className)}
                        style={stickyCols(i, "foot").style}
                      >
                        {meta.footerFormat ? meta.footerFormat(sum) : formatInt(sum, bootstrapForFooter)}
                      </TableCell>
                    )
                  }
                  // Columna sin footerSum: celda vacía, salvo la primera
                  // columna del set (la más a la izquierda) que lleva un
                  // label discreto "Total".
                  return (
                    <TableCell
                      key={col.id}
                      className={cn(meta?.className, stickyCols(i, "foot").className)}
                      style={stickyCols(i, "foot").style}
                    >
                      {i === 0 ? (
                        <span className="text-muted-foreground">Total</span>
                      ) : null}
                    </TableCell>
                  )
                })}
              </TableRow>
            </TableFooter>
          )}
        </Table>
      </div>

      {/* Pagination */}
      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
        <span>
          {totalCount != null && totalCount > table.getFilteredRowModel().rows.length ? (
            <>
              Mostrando {table.getFilteredRowModel().rows.length} de {totalCount}
            </>
          ) : (
            <>
              {table.getFilteredRowModel().rows.length}{" "}
              {table.getFilteredRowModel().rows.length === 1 ? "resultado" : "resultados"}
            </>
          )}
        </span>
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-1.5">
            <span>Filas por página</span>
            <SelectRoot
              value={String(table.getState().pagination.pageSize)}
              onValueChange={(v) => table.setPageSize(Number(v))}
            >
              <SelectTrigger className="h-7 w-20">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {[10, 25, 50, 100, 200].map((n) => (
                  <SelectItem key={n} value={String(n)}>
                    {n}
                  </SelectItem>
                ))}
              </SelectContent>
            </SelectRoot>
          </div>
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
              {table.getState().pagination.pageIndex + 1} / {Math.max(1, table.getPageCount())}
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
      </div>
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
 * Delega en `exportRowsToXlsx` (core reusable) — acá solo arma columnas/filas
 * desde el row model de TanStack Table.
 */
async function exportToXlsx<T>(
  rows: { getValue(id: string): unknown; original: T }[],
  cols: Array<{ id: string; columnDef: ColumnDef<T, unknown> }>,
  fileName: string,
) {
  const columns = cols.map((c) => ({
    key: c.id,
    header: columnLabel(c.columnDef as ColumnDef<unknown, unknown>),
  }))
  const plainRows = rows.map((row) => {
    const obj: Record<string, unknown> = {}
    for (const c of cols) {
      obj[c.id] = formatCellForExport(row.getValue(c.id))
    }
    return obj
  })
  await exportRowsToXlsx(plainRows, columns, fileName)
}

/**
 * Core reusable de export a XLSX (exceljs, lazy-import para no inflar el
 * bundle inicial). Recibe filas y columnas YA resueltas — sin depender de un
 * `Table` de TanStack — para que reportes con layout FIJO (ej. RG90/Libro
 * Ventas, context/38-impuestos-multi-pais.md §F5: 20 columnas en un orden
 * exacto que exige Marangatu, con datos que no viven en ninguna tabla en
 * pantalla) puedan reusar el mismo mecanismo de export sin duplicar la
 * llamada a exceljs ni el trigger de descarga.
 */
export async function exportRowsToXlsx(
  rows: Array<Record<string, unknown>>,
  columns: Array<{ key: string; header: string; width?: number }>,
  fileName: string,
) {
  const { default: ExcelJS } = await import("exceljs")
  const wb = new ExcelJS.Workbook()
  const ws = wb.addWorksheet("Datos")

  ws.columns = columns.map((c) => ({
    header: c.header,
    key: c.key,
    width: c.width ?? 22,
  }))
  ws.getRow(1).font = { bold: true }

  for (const row of rows) {
    ws.addRow(row)
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
    /** Si true, el <DataTable> renderiza un <TableFooter> con la suma de esta columna. */
    footerSum?: boolean
    /** Formatea la suma del footer. Si se omite, usa formatInt con el separador del tenant. */
    footerFormat?: (sum: number) => React.ReactNode
  }
}
