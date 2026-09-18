"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"

import { Badge } from "@/components/ui/badge"
import { CatalogManager, type CatalogField } from "@/components/catalog/catalog-manager"
import { useCreateWalletPocket, useUpdateWalletPocket, useWalletPockets } from "@/hooks/use-wallet"
import type { WalletPocket, WalletPocketPayload } from "@/lib/types/wallet"

/**
 * Catálogo de bolsillos de la wallet (context/74 §3.2), como una pestaña más de
 * Ajustes → Catálogos, junto a medios de pago e impuestos.
 *
 * Sin "Eliminar": un bolsillo tiene historia (movimientos que lo referencian).
 * Se desactiva desde el mismo form; desactivado deja de ofrecerse para
 * operaciones nuevas pero su saldo se sigue viendo en la ficha del cliente.
 */
export function WalletPocketsTab() {
  const { data, isLoading } = useWalletPockets()

  const columns: ColumnDef<WalletPocket, unknown>[] = React.useMemo(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
        meta: { label: "Nombre" },
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
      { name: "active", label: "Activo", type: "switch" },
    ],
    [],
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
      toFormValues={(row) => ({ name: row.name, active: row.active })}
      getId={(row) => row.id}
      getLabel={(row) => row.name}
      emptyFormValues={{ name: "", active: true }}
      exportFileName="bolsillos"
    />
  )
}
