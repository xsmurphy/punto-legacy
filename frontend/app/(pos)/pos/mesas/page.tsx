import { Utensils } from "lucide-react"

import { PosModulePlaceholder } from "@/components/register/pos-module-placeholder"

export default function MesasPage() {
  return (
    <PosModulePlaceholder
      title="Mesas"
      description="Gestión de mesas, sesiones y órdenes por sector. Próximamente."
      icon={Utensils}
    />
  )
}
