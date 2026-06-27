import type { QueryClient } from "@tanstack/react-query"
let _queryClient: QueryClient | null = null
export function setSharedQueryClient(qc: QueryClient): void {
  // Solo en browser — en SSR no hay instancia compartida entre requests.
  if (typeof window !== "undefined") { _queryClient = qc }
}
export function getSharedQueryClient(): QueryClient | null { return _queryClient }
