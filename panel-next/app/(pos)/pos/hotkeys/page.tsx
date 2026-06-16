import { Zap } from "lucide-react"

import { PosModulePlaceholder } from "@/components/register/pos-module-placeholder"

export default function HotkeysPage() {
  return (
    <PosModulePlaceholder
      title="Hotkeys"
      description="Botones de acceso rápido a productos frecuentes. Próximamente."
      icon={Zap}
    />
  )
}
