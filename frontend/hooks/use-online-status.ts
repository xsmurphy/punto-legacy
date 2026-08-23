'use client'

import * as React from 'react'

/**
 * Estado de conexión del navegador, reactivo.
 *
 * Wrapper único de `navigator.onLine` + los eventos `online`/`offline`. Existía
 * copiado en `OfflineBanner` (reactivo) y en `product-info-dialog` (una lectura
 * suelta en el render, que NO se actualizaba al caerse la red). Un solo lugar
 * para que todos los consumidores vean lo mismo.
 *
 * Arranca en `true` a propósito: en el primer render del servidor no hay
 * `navigator`, y asumir "offline" pintaría un aviso falso en cada carga. El
 * efecto corrige el valor real inmediatamente después de montar.
 */
export function useOnlineStatus(): boolean {
  const [isOnline, setIsOnline] = React.useState(true)

  React.useEffect(() => {
    setIsOnline(navigator.onLine)
    const handleOnline = () => setIsOnline(true)
    const handleOffline = () => setIsOnline(false)
    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)
    return () => {
      window.removeEventListener('online', handleOnline)
      window.removeEventListener('offline', handleOffline)
    }
  }, [])

  return isOnline
}
