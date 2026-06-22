import { ParkedSalesPanel } from "@/components/register/parked-sales-panel"

export default function GuardadasPage() {
  return (
    <div className="flex h-full flex-col">
      <div className="border-b px-4 py-3">
        <h2 className="text-sm font-semibold">Ventas guardadas</h2>
      </div>
      <div className="flex-1 overflow-y-auto">
        <ParkedSalesPanel />
      </div>
    </div>
  )
}
