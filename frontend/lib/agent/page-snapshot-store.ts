"use client"
import { create } from "zustand"

export type PageSnapshot = {
  route: string
  routeLabel: string
  summary: Record<string, unknown>
}

interface PageSnapshotState {
  snapshot: PageSnapshot | null
  setSnapshot: (s: PageSnapshot) => void
  clearSnapshot: () => void
}

export const useAgentPageSnapshotStore = create<PageSnapshotState>((set) => ({
  snapshot: null,
  setSnapshot: (snapshot) => set({ snapshot }),
  clearSnapshot: () => set({ snapshot: null }),
}))
