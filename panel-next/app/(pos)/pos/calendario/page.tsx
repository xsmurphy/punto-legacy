import { CalendarDays } from "lucide-react"

import { PosModulePlaceholder } from "@/components/register/pos-module-placeholder"

export default function CalendarioPage() {
  return (
    <PosModulePlaceholder
      title="Calendario"
      description="Agendamiento de turnos y reservas. Próximamente."
      icon={CalendarDays}
    />
  )
}
