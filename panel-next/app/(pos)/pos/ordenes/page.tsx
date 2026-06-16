import { ClipboardList } from "lucide-react"

import { PosModulePlaceholder } from "@/components/register/pos-module-placeholder"

export default function OrdenesPage() {
  return (
    <PosModulePlaceholder
      title="Órdenes"
      description="Órdenes abiertas, delivery y para llevar. Próximamente."
      icon={ClipboardList}
    />
  )
}
