/**
 * Hook de barcode scanner (keyboard-wedge) — placeholder Sprint 0.
 *
 * El POS legacy captura barcodes via un listener global de keydown que
 * detecta secuencias de dígitos seguidas de Enter (el patrón de los
 * lectores USB keyboard-wedge que emulan teclado).
 *
 * Este módulo provee el hook `useBarcodeScanner` que:
 *   1. Escucha eventos `keydown` globales.
 *   2. Acumula caracteres mientras llegan rápido (< 50ms entre teclas).
 *   3. Al detectar Enter (o tiempo de inactividad), llama al callback.
 *   4. Distingue entre input del cajero (lento) y scanner (ráfaga).
 *
 * TODO (Slice A): portar desde `app/scripts/app.js`:
 *   - Buscar `barcode` / `scanner` / `keydown` + `mousetrap` en app.js.
 *   - También hay soporte de báscula (peso + código) — ver context/14 §3.2.
 *
 * Referencia de port:
 *   app/scripts/app.js → buscar `barcodeBuffer` / `lastKeyTime` / `scanThreshold`
 *
 * Ver context/14-app-rewrite-analysis.md §3.2 (barcode + báscula).
 * Ver context/16-app-next-rewrite.md §8 (UX velocidad).
 */

"use client"

import * as React from "react"

export interface BarcodeScannerOptions {
  /**
   * Callback disparado cuando se detecta un barcode completo.
   * @param barcode El string escaneado (sin Enter).
   */
  onScan: (barcode: string) => void
  /**
   * Umbral en ms entre teclas para considerar una ráfaga de scanner.
   * Default 50ms — los scanners USB suelen enviar < 20ms entre chars.
   */
  threshold?: number
  /**
   * Longitud mínima para considerar un barcode válido. Default 3.
   * Evita falsos positivos con teclas sueltas del cajero.
   */
  minLength?: number
  /** Si false, el hook está desactivado. Útil cuando un modal está abierto. */
  enabled?: boolean
}

/**
 * Hook que detecta scans de barcode via keyboard-wedge.
 * TODO (Slice A): implementar la lógica real.
 *
 * @example
 * useBarcodeScanner({
 *   onScan: (code) => {
 *     const item = findItemBySku(catalogStore.items, code)
 *     if (item) addToCart(item)
 *   },
 * })
 */
export function useBarcodeScanner(_options: BarcodeScannerOptions): void {
  // TODO (Slice A): implementar keyboard-wedge detection.
  // Ver app/scripts/app.js → ncmTransactions barcode buffer logic.
  React.useEffect(() => {
    // placeholder — no-op
  }, [])
}
