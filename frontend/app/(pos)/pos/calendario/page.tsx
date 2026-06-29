import { CalendarDays } from "lucide-react"

import { PosModulePlaceholder } from "@/components/register/pos-module-placeholder"

export default function PosCalendarioPage() {
  return (
    <PosModulePlaceholder
      title="Agenda"
      description="Calendario de turnos, reservas y eventos. Próximamente."
      icon={CalendarDays}
    />
  )
}
