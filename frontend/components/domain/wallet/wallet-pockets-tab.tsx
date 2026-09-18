"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"

import { Badge } from "@/components/ui/badge"
import { CatalogManager, type CatalogField } from "@/components/catalog/catalog-manager"
import { useCreateWalletPocket, useUpdateWalletPocket, useWalletPockets } from "@/hooks/use-wallet"
import { useTaxes } from "@/hooks/use-taxes"
import { WALLET_POCKET_NO_TAX, type WalletPocket, type WalletPocketPayload } from "@/lib/types/wallet"

/**
 * Catálogo de bolsillos de la wallet (context/74 §3.2), como una pestaña más de
 * Ajustes → Catálogos, junto a medios de pago e impuestos.
 *
 * Sin "Eliminar": un bolsillo tiene historia (movimientos que lo referencian).
 * Se desactiva desde el mismo form; desactivado deja de ofrecerse para
 * operaciones nuevas pero su saldo se sigue viendo en la ficha del cliente.
 *
 * Impuesto (mig 234): con el que se FACTURA cada carga a este bolsillo — la
 * carga es una venta y se factura sin saber qué se va a consumir, así que la
 * tasa es del bolsillo (context/74 §4). Default: el primero del catálogo de
 * impuestos, el mismo que elige el servidor si no se indica.
 */
export function WalletPocketsTab() {
  const { data, isLoading } = useWalletPockets()
  const { data: taxesData } = useTaxes()
  const taxes = taxesData?.taxes ?? []
  const defaultTaxId = taxes[0]?.id ?? WALLET_POCKET_NO_TAX

  const columns: ColumnDef<WalletPocket, unknown>[] = React.useMemo(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
        meta: { label: "Nombre" },
      },
      {
        id: "tax",
        header: "Impuesto",
        accessorFn: (row) => row.taxName ?? "Sin impuesto",
        meta: { label: "Impuesto" },
      },
      {
        id: "active",
        header: "Estado",
        accessorFn: (row) => (row.active ? "Activo" : "Inactivo"),
        cell: ({ row }) =>
          row.original.active ? (
            <Badge variant="secondary">Activo</Badge>
          ) : (
            <Badge variant="outline">Inactivo</Badge>
          ),
        meta: { label: "Estado" },
      },
    ],
    [],
  )

  const fields: CatalogField<WalletPocketPayload>[] = React.useMemo(
    () => [
      { name: "name", label: "Nombre", required: true, placeholder: "Ej: Almuerzo" },
      {
        name: "taxId",
        label: "Impuesto de la carga",
        type: "select",
        options: [
          ...taxes.map((t) => ({ value: t.id, label: t.name })),
          { value: WALLET_POCKET_NO_TAX, label: "Sin impuesto" },
        ],
      },
      { name: "active", label: "Activo", type: "switch" },
    ],
    [taxes],
  )

  return (
    <CatalogManager<WalletPocket, WalletPocketPayload>
      entitySingular="bolsillo"
      entityPlural="bolsillos"
      gender="m"
      description="Bolsillos en los que se separa el saldo de tus clientes."
      rows={data?.pockets ?? []}
      isLoading={isLoading}
      useCreate={useCreateWalletPocket}
      useUpdate={useUpdateWalletPocket}
      columns={columns}
      fields={fields}
      toFormValues={(row) => ({ name: row.name, active: row.active, taxId: row.taxId ?? WALLET_POCKET_NO_TAX })}
      getId={(row) => row.id}
      getLabel={(row) => row.name}
      emptyFormValues={{ name: "", active: true, taxId: defaultTaxId }}
      exportFileName="bolsillos"
    />
  )
}
