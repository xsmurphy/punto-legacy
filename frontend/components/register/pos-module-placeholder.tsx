import type { ComponentType } from "react"

import {
  Empty,
  EmptyHeader,
  EmptyTitle,
  EmptyDescription,
  EmptyMedia,
} from "@/components/ui/empty"

/**
 * Placeholder de un módulo del POS todavía no implementado.
 *
 * Los módulos de la caja (Hotkeys, Espacios, Calendario, Órdenes) viven en el
 * sidebar contextual de /pos. Hasta que cada uno tenga su pantalla real,
 * estas rutas renderizan este placeholder para que la navegación no dé 404.
 */
export function PosModulePlaceholder({
  title,
  description,
  icon: Icon,
}: {
  title: string
  description: string
  icon: ComponentType<{ className?: string }>
}) {
  return (
    <div className="flex h-full w-full items-center justify-center p-6">
      <Empty className="border-0">
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <Icon className="size-5" />
          </EmptyMedia>
          <EmptyTitle>{title}</EmptyTitle>
          <EmptyDescription>{description}</EmptyDescription>
        </EmptyHeader>
      </Empty>
    </div>
  )
}
