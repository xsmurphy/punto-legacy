import { ModulesPanel } from "@/components/modules/modules-panel"

// Página standalone del marketplace de módulos. El contenido vive en
// `<ModulesPanel>` para que también pueda embeberse en el modal /settings
// (sección "Módulos"). Llegada directa por URL / deep-link / bookmarks.
export default function ModulesPage() {
  return (
    <div className="space-y-6 p-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-bold tracking-tight">Módulos</h1>
        <p className="text-muted-foreground">
          Activá las funciones que tu negocio necesita.
        </p>
      </div>
      <ModulesPanel />
    </div>
  )
}
