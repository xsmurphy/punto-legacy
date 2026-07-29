# Hand-off — 2026-07-29

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Cerrar el circuito de fulfillment de órdenes (que cada pedido diga siempre a
dónde va: mostrador, retiro o envío con dirección), atender una tanda de bugs
reportados por el owner en producción, y — post-cierre — dejar documentado el
catálogo de documentos imprimibles del sistema y un hallazgo urgente sobre el
botón "Interno".

## Estado al cerrar

Todo commiteado y pusheado a `main` (Coolify deploya solo). Dos rangos:

**Sesión principal** `c1541ba6..904df1a4` (57 commits) — código:

- F-D-0 completo: columna `fulfillment` (mig 94), snapshot congelado de
  dirección de envío, selector Mostrador/Retiro/Envío en el carrito, mapa de
  `/pos/ordenes` filtrado por `delivery`.
- Estado `out_for_delivery` "En camino" (mig 96) + `courierid` y asignación de
  repartidor (mig 97).
- Destino explícito ("Espacio 2" / "Mostrador" / "Retiro" / "Envío") en KDS,
  pantalla de despacho, listados y comanda impresa, desde un helper único.
- Board de 3 columnas por estado en `/display`.
- Catálogo único de bloques de impresión (79 tipos): antes dos renderers con
  listas duplicadas descartaban 12 tipos en silencio.
- Ruta BFF `/api/geo` con Photon: autocompletado de direcciones, geocodificación
  inversa y resolvedor de links cortos de Maps.
- Tanda de bugs de uso real (descuento visible, UUIDs de medios de pago,
  decimales, recibo de crédito, cotización en blanco, ventas guardadas,
  errores del chat IA).
- P0 de producción resuelto: `Inventory::getAllWasteValue` leía `itemWaste`
  como columna cuando vive en el JSONB `data` → 42703 tumbaba toda venta.
- Auth: sesión revocada dejaba de reportarse con cookie de panel; diagnóstico
  nuevo de 401 en `authResolve`, todavía sin reproducir.

**Post-cierre** `b49e582c..7aec191a` (2 commits) — solo docs, `context/10-roadmap.md`:

- **Catálogo de documentos imprimibles, decisión cerrada del owner**: Factura ·
  Comprobante (sin valor fiscal, numeración aparte, se activa con "Interno") ·
  Recibo (pago de factura a crédito) · Nota de crédito (devolución) ·
  Remisión · Cotización · Orden. La **gift card ahora emite Comprobante**
  (reemplaza la regla vieja "gift card → Recibo").
- **Hallazgo verificado capa por capa: el botón "Interno" no hace nada y quema
  numeración FISCAL.** Es lo más urgente sin resolver — ver Trampas.
- Documentado el caso de negocio real detrás de "Interno" (viandas/consumo a
  cuenta de empresa) con una solución propuesta, **sin implementar, a validar
  con el owner**.

No confundir con deploy: todo está en `main`, pero las coordenadas de
sucursales del fix de validación NO se recargaron a mano (ver Trampas).

## Archivos y cambios

- `api/lib/App/Helpers/Validation.php` — guard `Arr::sizeOf` corregido (ver Callejones #1).
- Servicio de inventario (`getAllWasteValue`) — fix P0, lee `itemWaste` del JSONB `data`.
- `api/database/migrations/postgres/94_*.sql`, `96_*.sql`, `97_*.sql` — fulfillment, out_for_delivery, courierid.
- Ruta BFF `/api/geo` — integración Photon nueva (autocompletado, reverse geocode, links Maps).
- `frontend/app/(pos)/pos/**` — carrito con selector Mostrador/Retiro/Envío, mapa filtrado.
- `frontend/lib/orders/order-display.ts` — helper único de destino/estado usado en KDS/despacho/listados/comanda.
- `frontend/app/(screen)/display/` — board de 3 columnas.
- `context/29-numeracion-y-exclusividad-de-caja.md` — plan nuevo, no arrancado.
- `context/10-roadmap.md` — catálogo de documentos + hallazgo Interno + caso viandas (solo docs, sin código).
- `frontend/lib/commands/create-sale.ts:297` — manda `interno` en el payload (el backend lo ignora, ver Trampas).
- `frontend/components/pos/pay-dialog.tsx` — elige `receipt`/`factura` sin mirar `interno`.
- `frontend/lib/hardware/printers/binding.ts` — `PrinterDocType` no tiene `comprobante` todavía.

⚠ NO tocar en esta sesión (otra sesión en paralelo trabaja ahí): `api/lib/EInvoice/*`,
`api/v1/einvoice.php`, `api/includes/simple.config.php`, `CLAUDE.md`,
`context/28-*`, `frontend/**/einvoice*`, `frontend/public/sw.js`. Al cierre
`frontend/public/sw.js` seguía con un cambio sin commitear de esa otra
sesión — se dejó intacto, sin stagear.

## Callejones sin salida

1. **El PIN de sucursal "no aparecía en el mapa" no era bug del mapa.** La
   causa real: `Validation::isValid()` descartaba en silencio todo negativo
   (Paraguay tiene lat/lng negativas). Lección: ante "el dato no aparece",
   verificar en la BD antes de leer el componente que lo pinta.
2. **El 401 "Token de otro realm" sigue sin resolver.** Descartado: no es el
   proxy, no es el SAPI, no es multi-computadora, no es revocación
   automática. Instrumentado en `authResolve`, falta que el owner reproduzca
   una vez y se lea el log nuevo.
3. **Diagnóstico equivocado del auto-print**: se atribuyó primero al binding
   sin documento "Factura" (estaba bien). La causa real: el auto-print vivía
   solo en la rama online de `pay-dialog.tsx`, y con el server caído por el
   P0 toda venta se encolaba offline y ninguna imprimía.
4. **Sub-agentes**: dos se colgaron esperando el build de otra sesión y
   salieron sin commitear; uno re-delegó en vez de ejecutar. Lint+build no
   alcanzan como gate — el code review encontró bugs reales que ya habían
   pasado ambos.
5. Un intento de limpiar código muerto con reemplazo por script rompió
   `sale-options-drawer.tsx` (cortó el bloque equivocado por haber dos
   `previewTx`). Se recuperó con `git show HEAD:`. Antes de editar por script,
   contar ocurrencias primero.

## Próximo paso

Decidir con el owner si se implementa YA el fix mínimo de "Interno" (leer el
flag en backend, emitir Comprobante con `registerBoletaNumber`, actualizar
`pay-dialog.tsx` y `PrinterDocType`) o si se espera a resolver junto con la
solución de viandas (empleado→empresa, facturación por período) documentada en
`context/10-roadmap.md`. Mientras tanto, cada venta "Interno" en producción
sigue quemando numeración fiscal.

## Trampas conocidas

- **El botón "Interno" es un engaño**: el usuario cree que está evitando
  emitir una factura, pero el front manda `interno` y el backend no lo lee en
  ninguna capa (cero referencias en PHP) — la venta se persiste como contado
  type 0 y toma `registerInvoiceNumber`. Cada uso quema un número fiscal real.
  Workaround actual en producción para viandas: *crédito + interno* juntos.
- `registerBoletaNumber` está en el schema, 0 referencias en código — es el
  contador candidato para el futuro Comprobante, todavía sin usar.
- Las coordenadas de las 5 sucursales de producción siguen en NULL: el fix de
  `Validation::isValid` habilita guardarlas de acá en más, pero los valores
  viejos nunca se persistieron y hay que recargarlos a mano.
- `statusLabelFor()` sigue diciendo "Enviado" para `ready`+delivery, ahora
  ambiguo contra el nuevo "En camino".
- Razón social, RUC, email y timbrado no viajan al POS: esos bloques de las
  plantillas de impresión imprimen vacío.
- Ningún register de producción tiene timbrado cargado — bloquear facturación
  con timbrado vencido es responsabilidad del motor pero no se puede probar
  en prod todavía.
- `lease.php` entrega el mismo bloque de numeración a cualquier dispositivo
  que pregunte — no hay exclusividad por caja. Plan en
  `context/29-numeracion-y-exclusividad-de-caja.md`, no arrancado.
- Terminología a cuidar: "Recibo" (`receipt`, pago de factura a crédito) y
  "Comprobante" (documento no fiscal de entrega/consumo) se parecen y no
  deben colisionar en `PrinterDocType` cuando se implemente.
