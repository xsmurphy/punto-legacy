"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/** Centro de notificaciones del panel (context/31). */
export type NotificationKind = "check_due" | "loan_due" | "purchase_due" | "event"
export type NotificationSeverity = "overdue" | "upcoming" | "info"

export interface NotificationItem {
  alertKey: string
  kind: NotificationKind
  title: string
  message: string
  /** Fecha del evento (`notify`). Excluyente con `dueDate`. */
  date: string | null
  /** Vencimiento de la obligación derivada. Excluyente con `date`. */
  dueDate: string | null
  amount?: number
  link: string | null
  severity: NotificationSeverity
  read: boolean
}

export interface NotificationsFeedResponse {
  items: NotificationItem[]
  unreadCount: number
}

const FEED_KEY = ["notifications", "feed"]

/** Poll suave (60s) — realtime WS queda para después (context/31). */
export function useNotificationsFeed() {
  return useQuery<NotificationsFeedResponse>({
    queryKey: FEED_KEY,
    queryFn: () => api.get<NotificationsFeedResponse>("/v1/notifications/feed"),
    refetchInterval: 60_000,
    staleTime: 15_000,
  })
}

/** El backend devuelve el feed ya actualizado — pisamos la cache directo, sin refetch. */
function useFeedMutation<TVars>(mutationFn: (vars: TVars) => Promise<NotificationsFeedResponse>) {
  const qc = useQueryClient()
  return useMutation<NotificationsFeedResponse, Error, TVars>({
    mutationFn,
    onSuccess: (data) => {
      qc.setQueryData(FEED_KEY, data)
    },
  })
}

export function useMarkNotificationsRead() {
  return useFeedMutation<string[]>((alertKeys) =>
    api.post<NotificationsFeedResponse>("/v1/notifications/feed", { op: "read", alertKeys }),
  )
}

export function useDismissNotification() {
  return useFeedMutation<string>((alertKey) =>
    api.post<NotificationsFeedResponse>("/v1/notifications/feed", { op: "dismiss", alertKey }),
  )
}

export function useMarkAllNotificationsRead() {
  return useFeedMutation<void>(() =>
    api.post<NotificationsFeedResponse>("/v1/notifications/feed", { op: "readAll" }),
  )
}
