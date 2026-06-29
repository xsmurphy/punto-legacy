import { create } from "zustand"

interface AgentChatState {
  open: boolean
  setOpen: (v: boolean) => void
  toggle: () => void
}

export const useAgentChatStore = create<AgentChatState>((set) => ({
  open: false,
  setOpen: (v) => set({ open: v }),
  toggle: () => set((s) => ({ open: !s.open })),
}))
