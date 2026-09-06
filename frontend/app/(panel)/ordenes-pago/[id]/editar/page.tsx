"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import { ArrowLeft } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { PaymentOrderForm } from "@/components/domain/purchases/payment-order-form"
import { usePaymentOrder } from "@/hooks/use-payment-orders"

/**
 * Edición de una orden de pago — SOLO en borrador.
 *
 * Una vez aprobada, cambiar las facturas o los montos convertiría la aprobación
 * en una firma en blanco: se aprobó una cosa y se pagaría otra.
 * `PaymentOrderService::update()` lo rechaza con un 422; acá se rechaza antes
 * para no ofrecer una pantalla que no puede guardar.
 */
export default function EditPaymentOrderPage() {
  const params = useParams<{ id: string }>()
  const router = useRouter()
  const id = params.id

  const { data, isLoading } = usePaymentOrder(id)

  if (isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  if (!data) {
    return (
      <div className="flex flex-col gap-4">
        <p className="text-muted-foreground">Orden de pago no encontrada.</p>
        <Button variant="ghost" className="w-fit" onClick={() => router.push("/ordenes-pago")}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Volver
        </Button>
      </div>
    )
  }

  if (data.order.status !== "draft") {
    return (
      <div className="flex flex-col gap-4">
        <p className="text-muted-foreground">
          Esta orden ya no está en borrador, así que no se edita. Para cambiar lo que se va a pagar,
          cancelala y armá una nueva — así queda rastro de las dos.
        </p>
        <Button variant="ghost" className="w-fit" onClick={() => router.push(`/ordenes-pago/${id}`)}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Volver a la orden
        </Button>
      </div>
    )
  }

  return (
    <PaymentOrderForm
      initial={{
        paymentOrderId: data.order.paymentOrderId,
        supplierId: data.order.supplierId,
        outletId: data.order.outletId,
        paymentDate: data.order.paymentDate,
        notes: data.order.notes,
        lines: data.lines.map((l) => ({ transactionId: l.transactionId, amount: l.amount })),
      }}
    />
  )
}
