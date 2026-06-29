"use client"
import * as React from "react"
import { useAgentPageSnapshotStore, type PageSnapshot } from "./page-snapshot-store"

export function useAgentPageSnapshot(
  snapshot: PageSnapshot | null,
  deps: React.DependencyList,
): void {
  React.useEffect(() => {
    if (snapshot) {
      useAgentPageSnapshotStore.getState().setSnapshot(snapshot)
    }
    return () => {
      useAgentPageSnapshotStore.getState().clearSnapshot()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)
}
