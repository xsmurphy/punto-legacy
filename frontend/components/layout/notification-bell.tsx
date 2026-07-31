"use client"

import * as React from "react"
import Link from "next/link"
import { Bell } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { DropdownMenuItem } from "@/components/ui/dropdown-menu"
import { cn } from "@/lib/utils"
import { useNotificationsFeed } from "@/hooks/use-notifications"

/**
 * Entrada del centro de notificaciones (context/31) en el menú del usuario
 * (pie del sidebar, decisión owner 2026-07-31 — antes era una fila propia
 * con Popover). Link directo a /notificaciones + badge de no-leídos.
 * Solo se monta en scope Panel (el feed es realm panel; en admin//pos el
 * poll daría 401).
 */
export function NotificationsMenuItem() {
  const { data } = useNotificationsFeed()
  const unreadCount = data?.unreadCount ?? 0

  return (
    <DropdownMenuItem asChild>
      <Link href="/notificaciones">
        <Bell />
        Notificaciones
        {unreadCount > 0 && (
          <Badge variant="secondary" className="ml-auto tabular-nums">
            {unreadCount > 99 ? "99+" : unreadCount}
          </Badge>
        )}
      </Link>
    </DropdownMenuItem>
  )
}

/**
 * Punto de no-leídos sobre el avatar del pie del sidebar — sin él, el badge
 * queda invisible hasta abrir el menú. Mismo queryKey que el menú (React
 * Query dedupea: un solo poll). null cuando no hay nada sin leer.
 */
export function NotificationUnreadDot({ className }: { className?: string }) {
  const { data } = useNotificationsFeed()
  const unreadCount = data?.unreadCount ?? 0
  if (unreadCount === 0) return null

  return (
    <span
      aria-label={`${unreadCount} notificaciones sin leer`}
      className={cn("size-2 rounded-full bg-destructive", className)}
    />
  )
}
