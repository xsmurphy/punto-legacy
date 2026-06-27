import { clearDeviceToken } from "@/lib/auth/device-token"
import { useCatalogStore } from "@/lib/catalog/store"
import { useCartStore } from "@/lib/cart/store"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { useLockStore } from "@/lib/pos/lock-store"
import type { QueryClient } from "@tanstack/react-query"

/**
 * Cleanup completo de la sesión del módulo (POS, KDS, etc.). Se invoca cuando
 * el server invalida la sesión por cualquier motivo: device revocado, token
 * expirado, sin auth. Garantía: después de llamar esto, el browser NO retiene
 * ningún dato sensible en memoria ni storage.
 *
 * NO limpia:
 * - Cola offline de ventas no sincronizadas (vive en IndexedDB, sobrevive re-pair)
 * - browserLocalId (identifica el browser para idempotencia del device-flow)
 *
 * @param queryClient Cliente React Query — se invoca clear() para vaciar
 *                    todos los caches. Pasar el queryClient explícito en vez
 *                    de obtenerlo de un hook para que la función sea callable
 *                    desde código non-React (api-client interceptor).
 */
export function moduleLogout(queryClient: QueryClient): void {
  clearDeviceToken()
  useCatalogStore.getState().reset()
  useCartStore.getState().clear()
  useHotkeysStore.getState().reset()
  useLockStore.getState().reset()
  queryClient.clear()
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent("module:logged-out"))
  }
}
