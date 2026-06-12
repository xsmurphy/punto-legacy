"use client"

import * as React from "react"
import { useSearchParams } from "next/navigation"
import { Loader2 } from "lucide-react"

import { TemplateEditor } from "@/components/print-templates/template-editor"
import { useDocumentTemplate } from "@/hooks/use-document-templates"

export default function PrintTemplatesPage() {
  return (
    <React.Suspense
      fallback={
        <div className="flex h-[60vh] items-center justify-center text-muted-foreground">
          <Loader2 className="size-5 animate-spin" />
        </div>
      }
    >
      <PrintTemplatesInner />
    </React.Suspense>
  )
}

function PrintTemplatesInner() {
  const params = useSearchParams()
  const id = params.get("id") ?? undefined
  const { data, isLoading } = useDocumentTemplate(id)

  if (id && isLoading) {
    return (
      <div className="flex h-[60vh] items-center justify-center text-muted-foreground">
        <Loader2 className="size-5 animate-spin" />
      </div>
    )
  }

  // `key` fuerza remount cuando cambia la plantilla — sin esto el TemplateEditor
  // mantiene state stale del id anterior y muestra la plantilla vacía aunque
  // `existing` haya cambiado (useState ignora cambios del initial value tras
  // mount). Cuando es plantilla nueva (sin id), 'new' garantiza un mount fresco.
  return <TemplateEditor key={data?.templateId ?? "new"} existing={data} />
}
