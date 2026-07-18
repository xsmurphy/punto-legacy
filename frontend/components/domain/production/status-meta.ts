import type { ProductionStatus } from "@/lib/types/production"

export const PRODUCTION_STATUS_META: Record<
  ProductionStatus,
  { label: string; variant: "default" | "secondary" | "destructive" | "outline" }
> = {
  draft: { label: "Borrador", variant: "outline" },
  in_progress: { label: "En curso", variant: "secondary" },
  completed: { label: "Completada", variant: "default" },
  cancelled: { label: "Cancelada", variant: "destructive" },
}
