import { ClipboardList } from "lucide-react"

import { PosModulePlaceholder } from "@/components/register/pos-module-placeholder"

export default function PosOrdenesPage() {
  return (
    <PosModulePlaceholder
      title="Órdenes"
      description="Gestión de órdenes del POS. Próximamente."
      icon={ClipboardList}
    />
  )
}
