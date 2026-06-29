"use client"

import * as React from "react"
import { Plus, Trash2, Loader2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import {
  useItemVariants,
  useBulkUpsertVariants,
  type VariantInput,
  type VariantRow,
} from "@/hooks/use-item-variants"

interface Axis {
  name: string
  values: string
}

interface MatrixRow {
  itemId?: string
  attrs: Record<string, string>
  sku: string
  barcode: string
  price: number | null
  cost: number | null
  stock: number
  _key: string
}

function buildKey(attrs: Record<string, string>): string {
  return Object.entries(attrs)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([k, v]) => `${k}:${v}`)
    .join("|")
}

function cartesian(axes: Axis[]): Record<string, string>[] {
  const parsed = axes
    .filter((a) => a.name.trim() !== "")
    .map((a) => ({
      name: a.name.trim(),
      vals: a.values
        .split(",")
        .map((v) => v.trim())
        .filter(Boolean),
    }))
    .filter((a) => a.vals.length > 0)

  if (parsed.length === 0) return []

  let result: Record<string, string>[] = [{}]
  for (const axis of parsed) {
    const next: Record<string, string>[] = []
    for (const combo of result) {
      for (const val of axis.vals) {
        next.push({ ...combo, [axis.name]: val })
      }
    }
    result = next
  }
  return result
}

export function VariantsTab({
  parentId,
  disabled,
}: {
  parentId: string
  disabled?: boolean
}) {
  const { data, isLoading } = useItemVariants(parentId)
  const save = useBulkUpsertVariants(parentId)

  const [axes, setAxes] = React.useState<Axis[]>([{ name: "", values: "" }])
  const [matrix, setMatrix] = React.useState<MatrixRow[]>([])

  React.useEffect(() => {
    const variants = data?.variants ?? []
    if (variants.length === 0) return

    const axisMap: Record<string, Set<string>> = {}
    for (const v of variants) {
      const attrs = v.variantAttributes ?? {}
      for (const [k, val] of Object.entries(attrs)) {
        if (!axisMap[k]) axisMap[k] = new Set()
        axisMap[k].add(val)
      }
    }

    const inferredAxes: Axis[] = Object.entries(axisMap).map(([name, vals]) => ({
      name,
      values: [...vals].join(", "),
    }))
    if (inferredAxes.length > 0) {
      setAxes(inferredAxes)
    }

    const rows: MatrixRow[] = variants.map((v) => ({
      itemId: v.itemId,
      attrs: v.variantAttributes ?? {},
      sku: v.itemSKU ?? "",
      barcode: (v as VariantRow & { itemBarcode?: string | null }).itemBarcode ?? "",
      price: typeof v.itemPrice === "number" ? v.itemPrice : Number(v.itemPrice) || null,
      cost: typeof v.itemCost === "number" ? v.itemCost : Number(v.itemCost) || 0,
      stock: 0,
      _key: buildKey(v.variantAttributes ?? {}),
    }))
    setMatrix(rows)
  }, [data?.variants])

  function handleAddAxis() {
    setAxes((prev) => [...prev, { name: "", values: "" }])
  }

  function handleRemoveAxis(idx: number) {
    setAxes((prev) => prev.filter((_, i) => i !== idx))
  }

  function handleAxisChange(idx: number, field: keyof Axis, value: string) {
    setAxes((prev) => prev.map((a, i) => (i === idx ? { ...a, [field]: value } : a)))
  }

  function handleGenerate() {
    const combos = cartesian(axes)
    if (combos.length === 0) {
      toast.error("Defini al menos un eje con valores para generar la matriz.")
      return
    }
    if (combos.length > 100) {
      toast.error("La matriz tiene mas de 100 combinaciones. Reduci los valores.")
      return
    }

    const existingMap = new Map<string, MatrixRow>()
    for (const row of matrix) {
      existingMap.set(row._key, row)
    }

    const newMatrix: MatrixRow[] = combos.map((attrs) => {
      const key = buildKey(attrs)
      const existing = existingMap.get(key)
      if (existing) return existing
      return {
        attrs,
        sku: "",
        barcode: "",
        price: null,
        cost: null,
        stock: 0,
        _key: key,
      }
    })

    setMatrix(newMatrix)
  }

  function handleRowChange(
    idx: number,
    field: keyof Omit<MatrixRow, "attrs" | "_key" | "itemId">,
    value: string | number | null,
  ) {
    setMatrix((prev) =>
      prev.map((r, i) => (i === idx ? { ...r, [field]: value } : r)),
    )
  }

  function handleRemoveRow(idx: number) {
    setMatrix((prev) => prev.filter((_, i) => i !== idx))
  }

  async function handleSave() {
    const invalid = matrix.filter((r) => !r.price || r.price <= 0)
    if (invalid.length > 0) {
      toast.error(
        `${invalid.length} fila(s) sin precio. Completa el campo Precio en cada variante.`,
      )
      return
    }

    const payload: VariantInput[] = matrix.map((r) => ({
      ...(r.itemId ? { itemId: r.itemId } : {}),
      sku: r.sku,
      barcode: r.barcode || undefined,
      price: r.price ?? 0,
      cost: r.cost ?? 0,
      stock: r.itemId ? undefined : r.stock || undefined,
      variantAttributes: r.attrs,
    }))

    try {
      await save.mutateAsync(payload)
      toast.success("Variantes guardadas")
    } catch (e) {
      toast.error("No se pudieron guardar las variantes", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const axisNames = axes.map((a) => a.name.trim()).filter(Boolean)

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">
            Atributos de variacion
          </CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-col gap-3">
            {axes.map((axis, idx) => (
              <div key={idx} className="flex items-center gap-2">
                <Input
                  placeholder="Eje (ej: Talle)"
                  className="w-36 h-9 text-sm"
                  value={axis.name}
                  onChange={(e) => handleAxisChange(idx, "name", e.target.value)}
                  disabled={disabled}
                />
                <Input
                  placeholder="Valores separados por coma (S, M, L)"
                  className="flex-1 h-9 text-sm"
                  value={axis.values}
                  onChange={(e) => handleAxisChange(idx, "values", e.target.value)}
                  disabled={disabled}
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="shrink-0 text-muted-foreground hover:text-destructive"
                  onClick={() => handleRemoveAxis(idx)}
                  disabled={axes.length <= 1 || disabled}
                >
                  <Trash2 className="size-4" />
                </Button>
              </div>
            ))}
          </div>
          <div className="flex gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleAddAxis}
              disabled={disabled}
            >
              <Plus className="mr-1.5 size-3.5" />
              Agregar eje
            </Button>
            <Button
              type="button"
              variant="default"
              size="sm"
              onClick={handleGenerate}
              disabled={disabled}
            >
              Generar matriz
            </Button>
          </div>
        </CardContent>
      </Card>

      {matrix.length > 0 && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="text-base font-semibold tracking-tight">
              Variantes{" "}
              <Badge variant="secondary" className="ml-1">
                {matrix.length}
              </Badge>
            </CardTitle>
            <Button
              type="button"
              onClick={handleSave}
              disabled={save.isPending || disabled}
              size="sm"
            >
              {save.isPending && (
                <Loader2 className="mr-1.5 size-3.5 animate-spin" />
              )}
              Guardar variantes
            </Button>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    {axisNames.map((name) => (
                      <TableHead key={name} className="whitespace-nowrap">
                        {name}
                      </TableHead>
                    ))}
                    <TableHead>SKU</TableHead>
                    <TableHead>Barcode</TableHead>
                    <TableHead>Precio</TableHead>
                    <TableHead>Costo</TableHead>
                    <TableHead>Stock inicial</TableHead>
                    <TableHead className="w-10"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {matrix.map((row, idx) => (
                    <TableRow key={row._key}>
                      {axisNames.map((name) => (
                        <TableCell
                          key={name}
                          className="whitespace-nowrap text-sm font-medium"
                        >
                          {row.attrs[name] ?? "-"}
                        </TableCell>
                      ))}
                      <TableCell>
                        <Input
                          className="h-8 w-28 tabular-nums text-sm"
                          placeholder="SKU"
                          value={row.sku}
                          onChange={(e) =>
                            handleRowChange(idx, "sku", e.target.value)
                          }
                          disabled={disabled}
                        />
                      </TableCell>
                      <TableCell>
                        <Input
                          className="h-8 w-28 tabular-nums text-sm"
                          placeholder="Codigo de barras"
                          value={row.barcode}
                          onChange={(e) =>
                            handleRowChange(idx, "barcode", e.target.value)
                          }
                          disabled={disabled}
                        />
                      </TableCell>
                      <TableCell>
                        <MoneyInput
                          className="h-8 w-24"
                          value={row.price ?? 0}
                          onChange={(v) =>
                            handleRowChange(idx, "price", v ?? null)
                          }
                          disabled={disabled}
                        />
                      </TableCell>
                      <TableCell>
                        <MoneyInput
                          className="h-8 w-24"
                          value={row.cost ?? 0}
                          onChange={(v) =>
                            handleRowChange(idx, "cost", v ?? null)
                          }
                          disabled={disabled}
                        />
                      </TableCell>
                      <TableCell>
                        <Input
                          type="number"
                          inputMode="numeric"
                          min={0}
                          className="h-8 w-20 tabular-nums text-sm"
                          placeholder="0"
                          value={row.stock}
                          onChange={(e) =>
                            handleRowChange(
                              idx,
                              "stock",
                              parseFloat(e.target.value) || 0,
                            )
                          }
                          disabled={disabled || !!row.itemId}
                          title={
                            row.itemId
                              ? "Usa Ajustes de stock para modificar stock existente"
                              : undefined
                          }
                        />
                      </TableCell>
                      <TableCell>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          className="text-muted-foreground hover:text-destructive"
                          onClick={() => handleRemoveRow(idx)}
                          disabled={disabled}
                        >
                          <Trash2 className="size-3.5" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>
      )}

      {matrix.length === 0 && !isLoading && (
        <p className="text-sm text-muted-foreground">
          Defini los atributos y presiona &quot;Generar matriz&quot; para crear las
          combinaciones.
        </p>
      )}
    </div>
  )
}
