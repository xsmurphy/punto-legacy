"use client"

/**
 * Hook keyboard-wedge para lectores de código de barras USB.
 *
 * Un lector de barras actúa como teclado: tipea los caracteres del código
 * muy rápido (< 50 ms entre teclas) y envía Enter al final. Esta diferencia
 * de velocidad es lo que nos permite distinguirlo del tipeo humano normal.
 *
 * Implementación:
 *   - Listener global `keydown` en `window`.
 *   - Buffer de caracteres con timeout configurable (default 100 ms).
 *   - Cada tecla que llega dentro del timeout se acumula en el buffer.
 *   - Si el siguiente keydown llega pasado el timeout → el buffer se descarta
 *     (era tipeo humano, no scan).
 *   - Al recibir Enter con buffer no vacío (>= minLength) → `onScan(buffer)`.
 *   - Se ignora si el foco está en un `<input>` o `<textarea>` editable, para
 *     no interferir con la búsqueda manual del cajero.
 *
 * Lógica de báscula (weightBarcodes, opcional):
 *   Códigos de 12 dígitos (UPC) que empiezan con "2": peso embebido.
 *     UPC: dígito 1 = "2", pos 1–5 = SKU (5 dígitos), pos 6–10 = peso en gramos.
 *   Códigos de 13 dígitos (EAN): igual, pos 2–6 = SKU, pos 7–11 = peso.
 *   Si `parseWeightBarcode` está habilitado, se extrae el SKU y el peso y se
 *   pasa `{ code, weight }` al callback. Sino se pasa weight=1 siempre.
 *
 * Ref. legacy: app/scripts/app.js L545 ($.fn.codeScanner) y L13518–13549 (wiring).
 * Ref. ncmScaleBarcode: L3395–3430.
 *
 * Usage:
 *   const scanner = useBarcodeScanner({ onScan: ({ code, weight }) => ... })
 *   // scanner.enabled / scanner.setEnabled para pausar desde dialogs.
 */

import * as React from "react"

export interface BarcodeScanResult {
  /** Código escaneado (SKU limpio, sin caracteres de bascula). */
  code: string
  /** Peso en kg extraído del barcode de báscula. 1 si no aplica. */
  weight: number
}

interface UseBarcanneroOptions {
  /** Llamado cuando se detecta un scan completo. */
  onScan: (result: BarcodeScanResult) => void
  /**
   * Milisegundos máximos entre dos teclas consecutivas para que se considere
   * scan (no tipeo humano). El legacy usa 1000 ms — usamos 100 ms para ser
   * más conservadores. Los lectores modernos tardan ~2–5 ms entre chars.
   */
  maxTimeBetweenKeys?: number
  /**
   * Mínimo de caracteres necesarios para emitir un scan.
   * El legacy usa 3 (L13519). Adoptamos el mismo valor.
   */
  minLength?: number
  /**
   * Si true, intenta parsear códigos de báscula (UPC/EAN que empiezan con "2"
   * y tienen 12 o 13 dígitos). Si false (default), siempre weight=1.
   */
  parseWeightBarcode?: boolean
  /** Si false, el hook no escucha (pausado). Default true. */
  enabled?: boolean
}

/**
 * Parsea un barcode de báscula al estilo ncmScaleBarcode del legacy.
 * Devuelve `{ code, weight }` donde:
 *   - code: SKU extraído del barcode.
 *   - weight: kg (gramos / 1000).
 * Si el barcode NO es de báscula, devuelve el código original con weight=1.
 */
function parseScaleBarcode(raw: string): BarcodeScanResult {
  const str = String(parseInt(raw, 10)) // strip leading zeros
  if (str.charAt(0) === "2" && (str.length === 12 || str.length === 13)) {
    if (str.length === 12) {
      // UPC: pos 1–5 = sku, pos 6–10 = weight (gramos)
      const sku = str.substring(1, 6)
      const weightGrams = parseInt(str.substring(6, 11), 10)
      return { code: sku, weight: weightGrams / 1000 }
    } else {
      // EAN: pos 2–6 = sku, pos 7–11 = weight (gramos)
      const sku = str.substring(2, 7)
      const weightGrams = parseInt(str.substring(7, 12), 10)
      return { code: sku, weight: weightGrams / 1000 }
    }
  }
  return { code: raw, weight: 1 }
}

interface UseBarcodeScanner {
  enabled: boolean
  setEnabled: (v: boolean) => void
}

export function useBarcodeScanner({
  onScan,
  maxTimeBetweenKeys = 100,
  minLength = 3,
  parseWeightBarcode = false,
  enabled: externalEnabled = true,
}: UseBarcanneroOptions): UseBarcodeScanner {
  const [internalEnabled, setInternalEnabled] = React.useState(true)
  const enabled = externalEnabled && internalEnabled

  // Refs para buffer (no necesitamos re-render con cada tecla)
  const bufferRef = React.useRef<string[]>([])
  const timerRef = React.useRef<ReturnType<typeof setTimeout> | null>(null)
  const onScanRef = React.useRef(onScan)

  React.useEffect(() => {
    onScanRef.current = onScan
  }, [onScan])

  React.useEffect(() => {
    if (!enabled) {
      // Limpiar buffer al pausar para no emitir un scan fantasma al reactivar.
      bufferRef.current = []
      if (timerRef.current) clearTimeout(timerRef.current)
      return
    }

    function handleKeyDown(e: KeyboardEvent) {
      // Ignorar si el foco está en un input/textarea editable.
      const target = e.target as HTMLElement | null
      if (target) {
        const tag = target.tagName.toLowerCase()
        const isEditable =
          (tag === "input" && (target as HTMLInputElement).type !== "hidden") ||
          tag === "textarea" ||
          (target as HTMLElement).isContentEditable
        if (isEditable) return
      }

      // Enter con buffer no vacío → scan completo.
      if (e.key === "Enter") {
        const captured = bufferRef.current.join("")
        bufferRef.current = []
        if (timerRef.current) clearTimeout(timerRef.current)

        if (captured.length >= minLength) {
          const result = parseWeightBarcode
            ? parseScaleBarcode(captured)
            : { code: captured, weight: 1 }
          onScanRef.current(result)
        }
        return
      }

      // Solo acumular caracteres imprimibles (no teclas de función, flechas, etc.)
      if (e.key.length !== 1) return

      // Reiniciar el timer en cada tecla. Si pasa el timeout sin nueva tecla,
      // se descarta el buffer (era tipeo humano).
      if (timerRef.current) clearTimeout(timerRef.current)
      timerRef.current = setTimeout(() => {
        bufferRef.current = []
      }, maxTimeBetweenKeys)

      bufferRef.current.push(e.key)
    }

    window.addEventListener("keydown", handleKeyDown)
    return () => {
      window.removeEventListener("keydown", handleKeyDown)
      if (timerRef.current) clearTimeout(timerRef.current)
    }
  }, [enabled, maxTimeBetweenKeys, minLength, parseWeightBarcode])

  return { enabled: internalEnabled, setEnabled: setInternalEnabled }
}
