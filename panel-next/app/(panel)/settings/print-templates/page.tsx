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

  return <TemplateEditor existing={data} />
}
