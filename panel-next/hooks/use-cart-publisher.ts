"use client"
import * as React from "react"
import { api } from "@/lib/api-client"
import { useCartStore, selectCartTotal, lineSubtotal } from "@/lib/cart/store"

export function useCartPublisher() {
  const lines = useCartStore((s) => s.lines)
  const customer = useCartStore((s) => s.customer)
  const ivaRemoved = useCartStore((s) => s.ivaRemoved)
  const total = useCartStore(selectCartTotal)
  const lastPayload = React.useRef<string>("")

  React.useEffect(() => {
    const payload = JSON.stringify({ lines, customer, total })
    if (payload === lastPayload.current) return
    lastPayload.current = payload
    const handle = setTimeout(() => {
      void api.post("/v1/screens/publish", {
        type: "cart-update",
        data: {
          lines: lines.map((l) => ({
            name: l.name,
            qty: l.qty,
            total: lineSubtotal(l, ivaRemoved),
          })),
          total: total ?? 0,
          discount: 0,
          customer: customer ? { name: customer.name, tin: customer.tin ?? "" } : null,
        },
      }).catch(() => {})
    }, 200)
    return () => clearTimeout(handle)
  }, [lines, customer, total, ivaRemoved])
}
