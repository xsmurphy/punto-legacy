"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { ArrowLeft, Tag, Building2, Receipt, Tags, CreditCard } from "lucide-react"
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
import {
  useTags,
  useCreateTag,
  useUpdateTag,
  useDeleteTag,
} from "@/hooks/use-tags"
import {
  usePaymentMethods,
  useCreatePaymentMethod,
  useUpdatePaymentMethod,
  useDeletePaymentMethod,
} from "@/hooks/use-payment-methods"
import { useFinanceAccounts } from "@/hooks/use-finance-accounts"

import type { Category, CategoryPayload } from "@/lib/types/category"
import type { Brand, BrandPayload } from "@/lib/types/brand"
import type { Tax, TaxPayload } from "@/lib/types/tax"
import type { Tag as TagItem, TagPayload } from "@/lib/types/tag"
import type { PaymentMethod, PaymentMethodPayload } from "@/lib/types/payment-method"

// Suprimir warning de unused — exportado por completitud del módulo.
void useCategory

/**
 * Catálogo unificado: categorías + marcas + impuestos. Cada tab usa el
 * componente genérico CatalogManager con su configuración (hooks, columns,
 * fields).
 */
type CatalogTabValue = "categories" | "brands" | "tags" | "taxes" | "payment-methods"

const VALID_TABS: CatalogTabValue[] = ["categories", "brands", "tags", "taxes", "payment-methods"]

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
            Categorías, marcas, etiquetas e impuestos disponibles para los artículos.
          </p>
        </div>
      </header>

      <Tabs value={tab} onValueChange={onTabChange}>
        {/* TabsList full-width 3-col — antes era ancho-contenido y dejaba la
            mitad derecha vacía. grid-cols-3 + w-full estira cada tab. */}
        <TabsList className="grid w-full grid-cols-5">
          <TabsTrigger value="categories" className="gap-1.5">
            <Tag className="size-3.5" />
            Categorías
          </TabsTrigger>
          <TabsTrigger value="brands" className="gap-1.5">
            <Building2 className="size-3.5" />
            Marcas
          </TabsTrigger>
          <TabsTrigger value="tags" className="gap-1.5">
            <Tags className="size-3.5" />
            Etiquetas
          </TabsTrigger>
          <TabsTrigger value="taxes" className="gap-1.5">
            <Receipt className="size-3.5" />
            Impuestos
          </TabsTrigger>
          <TabsTrigger value="payment-methods" className="gap-1.5">
            <CreditCard className="size-3.5" />
            Medios de pago
          </TabsTrigger>
        </TabsList>

        <TabsContent value="categories" className="mt-6">
          <CategoriesTab />
        </TabsContent>
        <TabsContent value="brands" className="mt-6">
          <BrandsTab />
        </TabsContent>
        <TabsContent value="tags" className="mt-6">
          <TagsTab />
        </TabsContent>
        <TabsContent value="taxes" className="mt-6">
          <TaxesTab />
        </TabsContent>
        <TabsContent value="payment-methods" className="mt-6">
          <PaymentMethodsTab />
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

// ── Tags ───────────────────────────────────────────────────────────────────

function TagsTab() {
  const columns: ColumnDef<TagItem, unknown>[] = React.useMemo(
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

  const fields: CatalogField<TagPayload>[] = [
    { name: "name", label: "Nombre", required: true, placeholder: "Ej: Promoción" },
  ]

  const { data, isLoading } = useTags()

  return (
    <CatalogManager<TagItem, TagPayload>
      entitySingular="etiqueta"
      entityPlural="etiquetas"
      rows={data?.tags ?? []}
      isLoading={isLoading}
      useCreate={useCreateTag}
      useUpdate={useUpdateTag}
      useDelete={useDeleteTag}
      columns={columns}
      fields={fields}
      toFormValues={(row) => ({ name: row.name })}
      getId={(row) => row.id}
      getLabel={(row) => row.name}
      emptyFormValues={{ name: "" }}
      exportFileName="etiquetas"
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

// ── Payment methods ──────────────────────────────────────────────────────────

/** Sentinel del Select de cuenta ('' no es válido en Radix): sin banco → Efectivo. */
const NO_ACCOUNT = "__none__"

function isEfectivoName(name: string): boolean {
  return name.trim().toLowerCase() === "efectivo"
}

function PaymentMethodsTab() {
  const { data, isLoading } = usePaymentMethods()
  const { data: accountsData } = useFinanceAccounts()

  // Solo cuentas type='bank' son asignables — ConfigService::update valida ese
  // type (wallet/cash se rechazan con 422). El método Efectivo cae fijo en la
  // cuenta Efectivo del sistema; el resto sin banco también (sentinel NO_ACCOUNT).
  const accountOptions = React.useMemo(() => {
    const banks = (accountsData ?? []).filter((a) => a.type === "bank" && a.status === 1)
    return [
      { value: NO_ACCOUNT, label: "Efectivo (sin banco asignado)" },
      ...banks.map((a) => ({ value: a.id, label: a.name })),
    ]
  }, [accountsData])

  const columns: ColumnDef<PaymentMethod, unknown>[] = React.useMemo(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
        meta: { label: "Nombre" },
      },
      {
        accessorKey: "code",
        header: "Atajo",
        cell: ({ row }) =>
          row.original.code ? (
            <span className="font-mono text-xs text-muted-foreground">{row.original.code}</span>
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
        meta: { label: "Atajo" },
      },
      {
        id: "requiresIdentifier",
        header: "Pide identificador",
        cell: ({ row }) => (row.original.requiresIdentifier ? "Sí" : "No"),
        meta: { label: "Pide identificador" },
      },
    ],
    [],
  )

  const fields: CatalogField<PaymentMethodPayload>[] = React.useMemo(
    () => [
      { name: "name", label: "Nombre", required: true, placeholder: "Ej: Transferencia" },
      {
        name: "code",
        label: "Atajo de teclado",
        placeholder: "Ej: T",
        helperText: "Una letra para disparar este medio con el teclado en el POS.",
      },
      {
        name: "hasChange",
        label: "Acepta vuelto",
        type: "switch",
        helperText: "Permite entregar vuelto cuando el monto supera el total.",
      },
      {
        name: "requiresIdentifier",
        label: "Pide identificador",
        type: "switch",
        helperText: "Solicita un dato (nro de operación, voucher…) antes de aplicar el pago.",
      },
      {
        name: "identifierLabel",
        label: "Etiqueta del identificador",
        placeholder: "Ej: Nro de operación",
      },
      {
        name: "identifierPlaceholder",
        label: "Placeholder del identificador",
        placeholder: "Ej: 123456",
      },
      {
        name: "accountId",
        label: "Cuenta de Finanzas",
        type: "select",
        options: accountOptions,
        placeholder: "Elegí una cuenta",
        helperText: "Banco al que se acredita este medio. Sin banco, cae en Efectivo.",
        // Efectivo es fijo: su cuenta no se edita.
        disabled: (values) => isEfectivoName(values.name),
      },
    ],
    [accountOptions],
  )

  return (
    <CatalogManager<PaymentMethod, PaymentMethodPayload>
      entitySingular="medio de pago"
      entityPlural="medios de pago"
      rows={data?.paymentMethods ?? []}
      isLoading={isLoading}
      useCreate={useCreatePaymentMethod}
      useUpdate={useUpdatePaymentMethod}
      useDelete={useDeletePaymentMethod}
      columns={columns}
      fields={fields}
      toFormValues={(row) => ({
        name: row.name,
        code: row.code,
        hasChange: row.hasChange,
        requiresIdentifier: row.requiresIdentifier,
        identifierLabel: row.identifierLabel,
        identifierPlaceholder: row.identifierPlaceholder,
        // accountId string para el Select: sentinel cuando no hay banco / es Efectivo.
        accountId:
          row.accountId && !isEfectivoName(row.name) ? row.accountId : NO_ACCOUNT,
      })}
      getId={(row) => row.id}
      getLabel={(row) => row.name}
      emptyFormValues={{
        name: "",
        code: "",
        hasChange: false,
        requiresIdentifier: false,
        identifierLabel: "",
        identifierPlaceholder: "",
        accountId: NO_ACCOUNT,
      }}
      exportFileName="medios-de-pago"
      transformPayload={(v) => ({
        ...v,
        // Sentinel → null; Efectivo nunca manda accountId (el backend lo ignora).
        accountId: isEfectivoName(v.name) || v.accountId === NO_ACCOUNT ? null : v.accountId,
      })}
    />
  )
}

