# Hand-off — 2026-07-30

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. Para historial ver `_session-log.md`.

## Objetivo

Salió del reporte del owner de "Dispositivo no conectado" apareciendo de la nada en el
POS. Al investigar ese bug se encontró un patrón repetido: el wrapper de acceso a datos
(`Query.php`, JSONB routing) tenía el mismo tipo de bug de raíz en 4 features distintas
(variantes, PIN, expenses, venta guardada/config de caja/anular compra). Se atacó el
wrapper compartido, no cada call-site. En paralelo: corrección de descuentos por ítem
(implementación previa mía estaba mal) y fix del toggle "quitar IVA".

## Estado al cerrar

Todo commiteado y pusheado a `main` (`3299437e..1076d1e9`, 37 commits). Coolify deploya
solo desde main. Nada pendiente de push.

- Mig 99 (variantes) y el cambio de `ai_model_config` (modelo de chat) **ya aplicados a
  mano en prod**, verificados.
- Migs 100 (hash de PIN) y 101 (`transaction.ivaRemoved`) **NO se corrieron a mano** —
  son idempotentes, corren solas en el boot del próximo deploy.

## Archivos y cambios

- `api/lib/App/Database/Query.php` — `flattenJsonb()` (ya no destruye el crudo) +
  `rawJsonb()` (side-channel WeakMap).
- `api/includes/functions.php` — `_getTableSchema()` (el schema map al que hay que
  sumar TODA columna nueva o la escritura se pierde en silencio), `_resolveTablePk()`,
  `_pkIsUuid()`, `_rawJsonb()`.
- `frontend/lib/cart/allocate-discounts.ts` — nuevo: reparto de descuento por ítem
  (resto mayor) + `lineGross()`, fuente única del bruto que comparten el carrito
  (`lineSubtotal` en `frontend/lib/cart/store.ts`) y el payload de la venta.
- `context/30-ai-agent-roadmap.md` — nuevo, plan de registry de tools + router de dos
  etapas (F0-F5).
- `context/_feature-requests.md` — deuda de permisos (17/45 chequeados) + pedidos
  nuevos (buscador /settings, roles/permisos, PedidosYa/Monchis).
- `frontend/public/sw.js` — tiene un cambio sin commitear que **NO es mío** (sesión
  paralela de facturación electrónica). No tocar, no stagear.

## Callejones sin salida

1. **"Refresh" fantasma en /pos/ordenes**: perseguí ChunkLoadError, payload RSC,
   service worker, comparé headers de las 4 rutas contra prod (todas devuelven
   `text/x-component` idéntico). Nada de eso era la causa real: el editor de hotkeys
   quedaba abierto al navegar porque su flag es global y `CartPanel` es persistente
   (fix `8bd37d27`). El fix del purge de caches del SW (`707c4f8f`) es correcto
   igual pero no era la causa de este síntoma. **Lección**: pedir la descripción
   exacta del síntoma antes de instrumentar nada.
2. **Descarté un hallazgo correcto de una auditoría automática** que marcó el
   descuento de venta como bug LIVE; razoné que "el % alcanza a ítems nuevos" era
   la semántica pedida y lo cerré. El owner corrigió: son dos reglas distintas
   (alcance congelado + un descuento por producto) y ninguna se cumplía. **Lección**:
   si el reporte contradice mi interpretación de una regla de negocio, preguntar
   antes de cerrar.
3. Consola de browser vacía en un reload no prueba ausencia de error — se limpia
   sola salvo "Preserve log" tildado.
4. El clasificador de permisos de Bash estuvo caído gran parte de la tarde: no se
   podía ejecutar nada (ni verificar ni commitear). Por eso la mig 100 no se aplicó
   a mano — quedó para correr en boot.

## Próximo paso

Ninguna acción de código en curso. Lo que sigue depende del owner:
reproducir en prod los 5 bugs abiertos (ver Trampas conocidas) y decidir los 2 temas
de producto pendientes (botón "Interno" sin Comprobante, imprimir venta en curso).

## Trampas conocidas

- **Deuda de permisos**: de 45 permisos del catálogo, solo 17 se chequean en el
  backend — un rol "solo ver" hoy puede anular ventas. Anotado en
  `context/_feature-requests.md`, sin plan todavía.
- **Reportes de IVA**: `itemSoldTax`/`transactionTax` se guardan SIEMPRE en 0 —
  el devengo real depende de que el front mande tax por ítem, fuera de alcance
  de esta sesión. No es regresión nueva, es preexistente.
- **5 bugs pendientes de reproducir en prod** (necesitan al owner in situ): cobrar
  mesa "servidor no conectado"; solo Efectivo al cobrar (puede ser el mismo 401 de
  `/v1/payment-methods` ya arreglado en `83854750` — a confirmar); cierre de caja
  500; agente IA que no completa creación de producto; recibo faltante al pagar
  crédito.
- `frontend/public/sw.js` con diff sin commitear ajeno a esta sesión — no
  stagearlo por error en el próximo commit.
- Plugin `caveman` (marketplace `JuliusBrussee/caveman`) instalado hoy en el
  entorno del owner — config fuera del repo, no relacionado al código.
