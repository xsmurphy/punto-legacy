"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { ArrowLeft, Tag, Building2, Receipt } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { CatalogManager, type CatalogField } from "@/components/catalog/catalog-manager"

import {
  useCategories,
  useCategory,
  useCreateCategory,
  useUpdateCategory,
  useDeleteCategory,
} from "@/hooks/use-categories"
import {
  useBrands,
  useCreateBrand,
  useUpdateBrand,
  useDeleteBrand,
} from "@/hooks/use-brands"
import {
  useTaxes,
  useCreateTax,
  useUpdateTax,
  useDeleteTax,
} from "@/hooks/use-taxes"

import type { Category, CategoryPayload } from "@/lib/types/category"
import type { Brand, BrandPayload } from "@/lib/types/brand"
import type { Tax, TaxPayload } from "@/lib/types/tax"

// Suprimir warning de unused — exportado por completitud del módulo.
void useCategory

/**
 * Catálogo unificado: categorías + marcas + impuestos. Cada tab usa el
 * componente genérico CatalogManager con su configuración (hooks, columns,
 * fields).
 */
type CatalogTabValue = "categories" | "brands" | "taxes"

const VALID_TABS: CatalogTabValue[] = ["categories", "brands", "taxes"]

function parseTab(raw: string | null): CatalogTabValue {
  return raw && (VALID_TABS as string[]).includes(raw) ? (raw as CatalogTabValue) : "categories"
}

export default function CatalogPage() {
  const router = useRouter()
  const searchParams = useSearchParams()
  // Deep-link vía ?tab=brands|taxes — el modal de Settings → Catálogo lanza
  // con la sección correcta (Categorías/Marcas/Impuestos) según la card que
  // el user clickeó. Default a "categories" cuando el query no viene o no es válido.
  const tab = parseTab(searchParams.get("tab"))

  const onTabChange = (next: string) => {
    const v = parseTab(next)
    // Reemplaza la URL (sin push para no inflar el history) — el state vive
    // en el query string, persistente a refresh y compartible por link.
    const sp = new URLSearchParams(searchParams.toString())
    sp.set("tab", v)
    router.replace(`/settings/catalog?${sp.toString()}`, { scroll: false })
  }

  return (
    <div className="space-y-6">
      <header className="flex items-center gap-3">
        <Button asChild variant="ghost" size="icon" className="size-8">
          <Link href="/settings" aria-label="Volver">
            <ArrowLeft className="size-4" />
          </Link>
        </Button>
        <div>
          <h1 className="text-2xl font-semibold">Catálogo</h1>
          <p className="text-sm text-muted-foreground">
            Categorías, marcas e impuestos disponibles para los artículos.
          </p>
        </div>
      </header>

      <Tabs value={tab} onValueChange={onTabChange}>
        {/* TabsList full-width 3-col — antes era ancho-contenido y dejaba la
            mitad derecha vacía. grid-cols-3 + w-full estira cada tab. */}
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="categories" className="gap-1.5">
            <Tag className="size-3.5" />
            Categorías
          </TabsTrigger>
          <TabsTrigger value="brands" className="gap-1.5">
            <Building2 className="size-3.5" />
            Marcas
          </TabsTrigger>
          <TabsTrigger value="taxes" className="gap-1.5">
            <Receipt className="size-3.5" />
            Impuestos
          </TabsTrigger>
        </TabsList>

        <TabsContent value="categories" className="mt-6">
          <CategoriesTab />
        </TabsContent>
        <TabsContent value="brands" className="mt-6">
          <BrandsTab />
        </TabsContent>
        <TabsContent value="taxes" className="mt-6">
          <TaxesTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}

// ── Categories ─────────────────────────────────────────────────────────────

function CategoriesTab() {
  const columns: ColumnDef<Category, unknown>[] = React.useMemo(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
        meta: { label: "Nombre" },
      },
    ],
    [],
  )

  const fields: CatalogField<CategoryPayload>[] = [
    { name: "name", label: "Nombre", required: true, placeholder: "Ej: Bebidas" },
  ]

  const { data, isLoading } = useCategories()

  return (
    <CatalogManager<Category, CategoryPayload>
      entitySingular="categoría"
      entityPlural="categorías"
      rows={data?.categories ?? []}
      isLoading={isLoading}
      useCreate={useCreateCategory}
      useUpdate={useUpdateCategory}
      useDelete={useDeleteCategory}
      columns={columns}
      fields={fields}
      toFormValues={(row) => ({ name: row.name })}
      getId={(row) => row.id}
      getLabel={(row) => row.name}
      emptyFormValues={{ name: "" }}
      exportFileName="categorias"
    />
  )
}

// ── Brands ─────────────────────────────────────────────────────────────────

function BrandsTab() {
  const columns: ColumnDef<Brand, unknown>[] = React.useMemo(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
        meta: { label: "Nombre" },
      },
    ],
    [],
  )

  const fields: CatalogField<BrandPayload>[] = [
    { name: "name", label: "Nombre", required: true, placeholder: "Ej: Coca-Cola" },
  ]

  const { data, isLoading } = useBrands()

  return (
    <CatalogManager<Brand, BrandPayload>
      entitySingular="marca"
      entityPlural="marcas"
      rows={data?.brands ?? []}
      isLoading={isLoading}
      useCreate={useCreateBrand}
      useUpdate={useUpdateBrand}
      useDelete={useDeleteBrand}
      columns={columns}
      fields={fields}
      toFormValues={(row) => ({ name: row.name })}
      getId={(row) => row.id}
      getLabel={(row) => row.name}
      emptyFormValues={{ name: "" }}
      exportFileName="marcas"
    />
  )
}

// ── Taxes ──────────────────────────────────────────────────────────────────

function TaxesTab() {
  const columns: ColumnDef<Tax, unknown>[] = React.useMemo(
    () => [
      {
        accessorKey: "name",
        header: "Valor",
        cell: ({ row }) => {
          const r = row.original
          const display = r.rate !== null ? `${r.rate}%` : r.name
          return <span className="font-medium">{display}</span>
        },
        meta: { label: "Valor" },
      },
    ],
    [],
  )

  const fields: CatalogField<TaxPayload>[] = [
    {
      name: "name",
      label: "Valor del impuesto",
      required: true,
      placeholder: "Ej: 10",
      helperText:
        "El porcentaje del IVA en formato numérico ('10', '5', '0'). Compat con facturación electrónica.",
    },
  ]

  const { data, isLoading } = useTaxes()

  return (
    <CatalogManager<Tax, TaxPayload>
      entitySingular="impuesto"
      entityPlural="impuestos"
      rows={data?.taxes ?? []}
      isLoading={isLoading}
      useCreate={useCreateTax}
      useUpdate={useUpdateTax}
      useDelete={useDeleteTax}
      columns={columns}
      fields={fields}
      toFormValues={(row) => ({ name: row.name })}
      getId={(row) => row.id}
      getLabel={(row) => (row.rate !== null ? `${row.rate}%` : row.name)}
      emptyFormValues={{ name: "" }}
      exportFileName="impuestos"
    />
  )
}
