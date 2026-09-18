"use client"

import { XCircle } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { redeemErrorCopy } from "@/lib/devices/redeem-copy"

/**
 * Pantalla terminal del flujo de conexión: este link no va a servir.
 *
 * El diccionario de motivos vive en `lib/devices/redeem-copy.ts`, compartido
 * con el formulario de vinculación de "no conectado" (que canjea sin navegar).
 * Acá había una copia; ver el docblock de ese módulo.
 *
 * Antes el prop `reason` se recibía y se DESCARTABA —siempre se pintaba "Link
 * inválido o expirado"—, así que el motivo real ("ya está en uso en otro
 * dispositivo", "el dispositivo fue desconectado por un administrador") nunca
 * llegaba a la pantalla y el operador no tenía forma de saber qué hacer
 * distinto.
 */
export function InvalidLink({ reason }: { reason: string }) {
  const { title, detail } = redeemErrorCopy(reason)

  return (
    <div className="min-h-svh flex flex-col items-center justify-center p-6 bg-background">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
          <XCircle className="size-12 text-muted-foreground" />
          <div className="space-y-1">
            <h2 className="text-xl font-semibold">{title}</h2>
            <p className="text-sm text-muted-foreground">{detail}</p>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
