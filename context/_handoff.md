# Hand-off — 2026-07-29

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Cerrar el circuito de fulfillment de órdenes (que cada pedido diga siempre a
dónde va: mostrador, retiro o envío con dirección) y, sobre la marcha, atender
una tanda de bugs reportados por el owner durante el uso real en producción.

## Estado al cerrar

Todo commiteado y pusheado a `main` (Coolify deploya solo). Rango de la
sesión: `c1541ba6..904df1a4` (57 commits). Entregado:

- F-D-0 completo: columna `fulfillment` (mig 94), snapshot congelado de
  dirección de envío, selector Mostrador/Retiro/Envío en el carrito, mapa de
  `/pos/ordenes` filtrado por `delivery`.
- Estado `out_for_delivery` "En camino" (mig 96) + `courierid` y asignación de
  repartidor (mig 97).
- Destino explícito ("Espacio 2" / "Mostrador" / "Retiro" / "Envío") en KDS,
  pantalla de despacho, listados y comanda impresa, desde un helper único.
- Board de 3 columnas por estado en `/display`; claro/oscuro consistente en
  despacho, impresión y checkout.
- Catálogo único de bloques de impresión (79 tipos): antes dos renderers con
  listas duplicadas descartaban 12 tipos en silencio.
- Ruta BFF `/api/geo` con Photon: autocompletado de direcciones, geocodificación
  inversa y resolvedor de links cortos de Maps.
- Tanda de bugs de uso real: descuento de venta visible y con estado; UUIDs de
  medios de pago en Control de Caja (escritura + lectura + agrupación); decimales
  en el carrito; recibo al pagar crédito; cotización en blanco; ventas guardadas
  que tumbaban la página; errores del chat IA visibles.
- P0 de producción resuelto: TODA venta fallaba con 500 —
  `Inventory::getAllWasteValue` leía `itemWaste` como columna cuando vive en el
  JSONB `data` → 42703 abortaba la TX de la venta y todo lo posterior caía con
  25P02.
- Auth: la sesión revocada dejaba de reportarse cuando había cookie de panel;
  + diagnóstico nuevo de 401 en `authResolve` (loguea header/credenciales/realm
  en cada 401, todavía sin reproducir).
- Proceso: `context/_handoff.md` reescribible + skill `/end-session` única
  (se borró la copia local del proyecto).

No confundir con deploy: todo está en `main` y Coolify lo toma automáticamente,
pero las coordenadas de sucursales que el fix de validación habilita NO se
recargaron a mano (ver Trampas).

## Archivos y cambios

- `api/lib/App/Helpers/Validation.php` — guard `Arr::sizeOf` corregido (ver Callejones #1).
- Servicio de inventario (`getAllWasteValue`) — fix P0, lee `itemWaste` del JSONB `data`.
- `api/database/migrations/postgres/94_*.sql`, `96_*.sql`, `97_*.sql` — fulfillment, out_for_delivery, courierid.
- Ruta BFF `/api/geo` — integración Photon nueva (autocompletado, reverse geocode, links Maps).
- `frontend/app/(pos)/pos/**` — carrito con selector Mostrador/Retiro/Envío, mapa filtrado.
- `frontend/lib/orders/order-display.ts` — helper único de destino/estado usado en KDS/despacho/listados/comanda.
- `frontend/app/(screen)/display/` — board de 3 columnas.
- `context/29-numeracion-y-exclusividad-de-caja.md` — plan nuevo, no arrancado.

⚠ NO tocar en esta sesión (otra sesión en paralelo trabaja ahí): `api/lib/EInvoice/*`,
`api/v1/einvoice.php`, `api/includes/simple.config.php`, `CLAUDE.md`,
`context/28-*`, `frontend/**/einvoice*`, `frontend/public/sw.js`. Al cierre de
esta sesión `frontend/public/sw.js` tenía un cambio sin commitear de esa otra
sesión — se dejó intacto, sin stagear.

## Callejones sin salida

1. **El PIN de sucursal "no aparecía en el mapa" no era bug del mapa.** Se
   revisó componente, bootstrap y store sin resultado. La causa real estaba en
   `Validation::isValid()`: el guard `Arr::sizeOf($value) < 0.00001` recibe el
   NÚMERO cuando el valor es numérico, así que descartaba en silencio todo
   negativo — y Paraguay tiene lat/lng negativas. Ninguna coordenada llegó
   nunca a la BD. Lección: ante "el dato no aparece", verificar en la BD ANTES
   de leer el componente que lo pinta.
2. **El 401 "Token de otro realm" sigue sin resolver.** Descartado con
   evidencia: no es el proxy (reenvía `Authorization`), no es el SAPI (logs
   muestran ambos realms resolviendo bien), no es multi-computadora (un
   device sostiene 2-3 sesiones activas por diseño), no es revocación
   automática (todas las revocaciones tienen autor humano). Queda
   instrumentado en `authResolve` — falta que el owner reproduzca una vez y
   se lea el log nuevo.
3. **Diagnóstico equivocado del auto-print**: se atribuyó primero al binding
   sin documento "Factura" (estaba bien). La causa real: el auto-print vivía
   solo en la rama online de `pay-dialog.tsx`, y con el server caído por el
   P0 toda venta se encolaba offline y ninguna imprimía.
4. **Sub-agentes**: dos se colgaron esperando el build de otra sesión y
   salieron sin commitear (se revisó y commiteó a mano); uno re-delegó en vez
   de ejecutar y no dejó nada. Los briefs ahora llevan "EJECUTÁ VOS MISMO, no
   delegues". Lint+build no alcanzan como gate — el code review encontró un id
   de dirección deducido por heurística, estado de envío colgado al cambiar de
   cliente, y tres handlers duplicados byte por byte.
5. Un intento de limpiar código muerto con reemplazo por script rompió
   `sale-options-drawer.tsx` (cortó el bloque equivocado por haber dos
   `previewTx`, se llevó el cuerpo de `handleSaveAsQuote`). Se recuperó con
   `git show HEAD:`. Antes de editar por script, contar ocurrencias primero.

## Próximo paso

Pedirle al owner que reproduzca el 401 de cambio de caja una vez y comparta el
log nuevo de `authResolve` (incluye header/credenciales/realm por request).

## Trampas conocidas

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
  que pregunte — no hay exclusividad por caja (riesgo real: 4 dispositivos
  activos sobre la misma caja en producción). Plan en
  `context/29-numeracion-y-exclusividad-de-caja.md`, no arrancado.
- Decisiones cerradas con el owner, pendientes de codificar: el repartidor es
  un usuario con permiso acotado (no un device pareado); "día fiscal" no
  existe en Punto, el lease vence con la fecha del outlet; el control de caja
  es opcional y no sirve de ancla; geocoding usa Photon (gratis, OSM) detrás
  de ruta propia para poder cambiar de proveedor sin tocar la UI.
- Preguntas abiertas sin decidir: dispositivo sin tenencia de caja
  ¿online-only o no opera?; register sin timbrado ¿bloquea/avisa/deja pasar?;
  qué documento no fiscal imprime una venta en curso; cómo se modela el
  ítem-descuento.
