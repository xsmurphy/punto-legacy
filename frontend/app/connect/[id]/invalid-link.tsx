"use client"

import { XCircle } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"

export function InvalidLink({ reason }: { reason: string }) {
  return (
    <div className="min-h-svh flex flex-col items-center justify-center p-6 bg-background">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
          <XCircle className="size-12 text-muted-foreground" />
          <div className="space-y-1">
            <h2 className="text-xl font-semibold">Link inválido o expirado</h2>
            <p className="text-sm text-muted-foreground">
              Pedile al administrador que genere uno nuevo y volvé a abrirlo.
            </p>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
