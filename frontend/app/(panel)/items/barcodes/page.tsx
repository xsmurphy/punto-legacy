"use client"

import * as React from "react"
import { useSearchParams } from "next/navigation"
import { Printer, ChevronLeft } from "lucide-react"
import JsBarcode from "jsbarcode"

import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { api } from "@/lib/api-client"
import { formatMoney } from "@/lib/format"
import type { ItemFull } from "@/lib/types/item"
import { cn } from "@/lib/utils"

/**
 * Página de códigos de barras imprimibles — port del legacy panel/barcode.php
 *
 *   /items/barcodes?ids=<itemId>-<qty>|<itemId>-<qty>|...
 *
 * Recibe items + cuantas etiquetas por item, dibuja una grilla CSS con N
 * tarjetas (barcode CODE128 + nombre + precio + SKU). Toolbar arriba con
 * tamaño / columnas / altura / qué mostrar. window.print() para enviar
 * a impresora.
 */
export default function BarcodesPage() {
  return (
    <React.Suspense fallback={null}>
      <BarcodesInner />
    </React.Suspense>
  )
}

function BarcodesInner() {
  const searchParams = useSearchParams()
  const idsParam = searchParams.get("ids") ?? ""

  // Parse '<itemId>-<qty>|...' → [{id, qty}]. Soporta UUIDs con guiones:
  // tomamos el ÚLTIMO segmento después del último '-' como qty si es un int.
  const requests = React.useMemo(() => parseIds(idsParam), [idsParam])

  const { data: bootstrap } = useBootstrap()
  const [items, setItems] = React.useState<Array<{ item: ItemFull; qty: number }>>([])
  const [loading, setLoading] = React.useState(true)

  // UI state
  const [cols, setCols] = React.useState<2 | 3 | 4 | 6 | 12>(2)
  const [size, setSize] = React.useState<"reg" | "sm" | "md" | "lg">("reg")
  const [heightCm, setHeightCm] = React.useState(2.8)
  const [show, setShow] = React.useState({
    company: true,
    title: true,
    price: true,
    code: true,
  })

  // Trae los items en paralelo.
  React.useEffect(() => {
    if (requests.length === 0) {
      setLoading(false)
      return
    }
    Promise.all(
      requests.map((r) =>
        api.get<ItemFull>(`/v1/items?id=${r.id}`).then(
          (item) => ({ item, qty: r.qty }),
          () => null,
        ),
      ),
    ).then((results) => {
      setItems(results.filter((x): x is { item: ItemFull; qty: number } => x !== null))
      setLoading(false)
    })
  }, [requests])

  // Cada vez que cambian items o size, redibuja los barcodes.
  React.useEffect(() => {
    if (loading) return
    const svgs = document.querySelectorAll<SVGSVGElement>("svg.jsbarcode-canvas")
    const heightPx = size === "sm" ? 20 : size === "md" ? 40 : size === "lg" ? 60 : 30
    svgs.forEach((svg) => {
      const code = svg.dataset.code ?? ""
      if (!code) return
      try {
        JsBarcode(svg, code, {
          format: "CODE128",
          height: heightPx,
          displayValue: false,
          margin: 0,
        })
      } catch {
        // Code inválido — dejamos el svg vacío.
      }
    })
  }, [items, size, loading, show])

  const colSpanClass = colSpanFor(cols)
  const totalCards = items.reduce((acc, x) => acc + x.qty, 0)

  return (
    <div className="flex min-h-screen flex-col bg-white text-black">
      <Toolbar
        cols={cols}
        setCols={setCols}
        size={size}
        setSize={setSize}
        heightCm={heightCm}
        setHeightCm={setHeightCm}
        show={show}
        setShow={setShow}
        totalCards={totalCards}
      />

      <main className="grid grid-cols-12 gap-1 p-2 print:gap-0 print:p-0">
        {loading && (
          <div className="col-span-12 py-12 text-center text-sm text-muted-foreground">
            Cargando artículos…
          </div>
        )}

        {!loading && items.length === 0 && (
          <div className="col-span-12 py-12 text-center text-sm text-muted-foreground">
            Sin artículos. Volvé al listado y seleccioná items para generar
            códigos de barra.
          </div>
        )}

        {!loading &&
          items.flatMap(({ item, qty }) =>
            Array.from({ length: qty }).map((_, idx) => (
              <BarcodeCell
                key={`${item.itemId}-${idx}`}
                item={item}
                bootstrap={bootstrap}
                show={show}
                colSpanClass={colSpanClass}
                heightCm={heightCm}
              />
            )),
          )}
      </main>
    </div>
  )
}

// ── Toolbar ───────────────────────────────────────────────────────────────

function Toolbar({
  cols,
  setCols,
  size,
  setSize,
  heightCm,
  setHeightCm,
  show,
  setShow,
  totalCards,
}: {
  cols: 2 | 3 | 4 | 6 | 12
  setCols: (c: 2 | 3 | 4 | 6 | 12) => void
  size: "reg" | "sm" | "md" | "lg"
  setSize: (s: "reg" | "sm" | "md" | "lg") => void
  heightCm: number
  setHeightCm: (v: number) => void
  show: { company: boolean; title: boolean; price: boolean; code: boolean }
  setShow: (s: { company: boolean; title: boolean; price: boolean; code: boolean }) => void
  totalCards: number
}) {
  return (
    <div className="sticky top-0 z-10 flex flex-col gap-3 border-b bg-muted/30 p-3 text-sm print:hidden">
      <div className="flex flex-wrap items-end gap-3">
        <Button
          variant="ghost"
          size="sm"
          className="gap-1 text-xs"
          onClick={() => window.history.back()}
        >
          <ChevronLeft className="size-3.5" />
          Volver
        </Button>

        <div className="flex flex-col gap-1">
          <Label className="text-[10px] uppercase">Modelo</Label>
          <Select value={size} onValueChange={(v) => setSize(v as typeof size)}>
            <SelectTrigger className="h-8 w-32">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="sm">Pequeño</SelectItem>
              <SelectItem value="reg">Regular</SelectItem>
              <SelectItem value="md">Mediano</SelectItem>
              <SelectItem value="lg">Grande</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-1">
          <Label className="text-[10px] uppercase">Columnas</Label>
          <Select value={String(cols)} onValueChange={(v) => setCols(Number(v) as typeof cols)}>
            <SelectTrigger className="h-8 w-32">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="2">6 columnas</SelectItem>
              <SelectItem value="3">4 columnas</SelectItem>
              <SelectItem value="4">3 columnas</SelectItem>
              <SelectItem value="6">2 columnas</SelectItem>
              <SelectItem value="12">1 columna</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-1">
          <Label className="text-[10px] uppercase">Altura (cm)</Label>
          <Input
            type="number"
            min={1}
            step={0.1}
            value={heightCm}
            onChange={(e) => setHeightCm(Number(e.target.value) || 2.8)}
            className="h-8 w-24 text-right tabular-nums"
          />
        </div>

        <div className="ml-auto flex items-center gap-2">
          <span className="text-xs text-muted-foreground tabular-nums">
            {totalCards} etiquetas
          </span>
          <Button onClick={() => window.print()} className="gap-1.5">
            <Printer className="size-4" />
            Imprimir
          </Button>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-4 text-xs">
        {(["company", "title", "price", "code"] as const).map((k) => (
          <label key={k} className="flex items-center gap-2">
            <Checkbox
              checked={show[k]}
              onCheckedChange={(v) => setShow({ ...show, [k]: !!v })}
            />
            <span className="capitalize">
              {k === "company" ? "Empresa" : k === "title" ? "Título" : k === "price" ? "Precio" : "Código"}
            </span>
          </label>
        ))}
      </div>
    </div>
  )
}

// ── Cell ──────────────────────────────────────────────────────────────────

function BarcodeCell({
  item,
  bootstrap,
  show,
  colSpanClass,
  heightCm,
}: {
  item: ItemFull
  bootstrap: ReturnType<typeof useBootstrap>["data"]
  show: { company: boolean; title: boolean; price: boolean; code: boolean }
  colSpanClass: string
  heightCm: number
}) {
  // Si el item tiene SKU usamos eso; si no, el itemId (truncado).
  const code = (item.itemSKU || item.itemId || "").trim()
  const price = typeof item.itemPrice === "number"
    ? item.itemPrice
    : Number(item.itemPrice ?? 0)
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center overflow-hidden border border-border bg-white px-1 py-1 text-center text-[9px] text-black",
        "print:border-0",
        colSpanClass,
      )}
      style={{ height: `${heightCm}cm`, minHeight: `${heightCm}cm`, maxHeight: `${heightCm}cm` }}
    >
      {show.company && bootstrap?.companyName && (
        <div className="truncate font-medium leading-tight">{bootstrap.companyName}</div>
      )}
      {show.title && (
        <div className="truncate leading-tight">{item.itemName || "(sin nombre)"}</div>
      )}
      <svg
        className="jsbarcode-canvas mt-0.5 w-full"
        data-code={code}
      />
      {show.code && (
        <div className="truncate text-[8px] leading-tight">{code}</div>
      )}
      {show.price && (
        <div className="font-semibold leading-tight">
          {formatMoney(price, bootstrap)}
        </div>
      )}
    </div>
  )
}

// ── helpers ───────────────────────────────────────────────────────────────

function parseIds(raw: string): Array<{ id: string; qty: number }> {
  if (!raw) return []
  const decoded = decodeURIComponent(raw)
  return decoded
    .split("|")
    .map((token) => token.trim())
    .filter(Boolean)
    .map((token) => {
      // Los UUIDs tienen guiones — la qty es solo el ÚLTIMO segmento si es int.
      const lastDash = token.lastIndexOf("-")
      if (lastDash > 0) {
        const tail = token.slice(lastDash + 1)
        const n = Number(tail)
        if (Number.isInteger(n) && n > 0 && n < 1000) {
          return { id: token.slice(0, lastDash), qty: n }
        }
      }
      return { id: token, qty: 1 }
    })
}

function colSpanFor(cols: 2 | 3 | 4 | 6 | 12): string {
  // El grid es 12-col, cada cell ocupa N columnas → cards = 12/N por fila.
  switch (cols) {
    case 2: return "col-span-2"   // 6 cards/row
    case 3: return "col-span-3"   // 4 cards/row
    case 4: return "col-span-4"   // 3 cards/row
    case 6: return "col-span-6"   // 2 cards/row
    case 12: return "col-span-12" // 1 card/row
  }
}
