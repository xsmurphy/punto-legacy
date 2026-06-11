"use client"

import * as React from "react"
import Link from "next/link"
import { AlertTriangle, ArrowLeft } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

export default function ItemsError({
  error,
  reset,
}: {
  error: Error & { digest?: string }
  reset: () => void
}) {
  React.useEffect(() => {
    console.error("[items] render error:", error)
  }, [error])

  return (
    <div className="flex flex-col gap-4">
      <Link
        href="/items"
        className="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="size-3.5" />
        Volver a artículos
      </Link>
      <Card>
        <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
          <AlertTriangle className="size-8 text-destructive opacity-70" />
          <div className="flex flex-col gap-1">
            <p className="font-medium">Error al cargar esta sección</p>
            <p className="text-xs text-muted-foreground">
              {error.message || "Ocurrió un error inesperado."}
            </p>
          </div>
          <Button variant="outline" size="sm" onClick={reset}>
            Reintentar
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}
