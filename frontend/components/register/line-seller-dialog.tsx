"use client"

import { SellerPickerDialog } from "@/components/pos/seller-picker-dialog"

interface Props {
  open: boolean
  currentSellerId: string | undefined
  onSelect: (userId: string | null) => void
  onClose: () => void
}

export function LineSellerDialog({ open, currentSellerId, onSelect, onClose }: Props) {
  return (
    <SellerPickerDialog
      open={open}
      onOpenChange={(v) => { if (!v) onClose() }}
      onSelect={onSelect}
      currentUserId={currentSellerId}
    />
  )
}
