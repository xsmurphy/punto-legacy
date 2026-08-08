# Hand-off — 2026-08-08

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Arrancó arreglando el numeric-pad del POS, después se triaron en cascada los 17
bugs/pedidos de un tester (`Mejoras Punto.docx`, post-deploy `9925453`). Uno de
esos triajes (columnas de IVA por tasa en plantillas de impresión) destapó que
el modelo de impuestos del POS estaba mal de raíz — `TAX_RATE=0.10` hardcodeado,
sin multi-tasa/multi-país/incluido-vs-añadido — y se abrió un plan propio
(`context/38-impuestos-multi-pais.md`) que se convirtió en el grueso final de
la sesión.

## Estado al cerrar

Todo commiteado Y **pusheado** a `main` (deploy automático, confirmado por el
owner). Migraciones 117 (numeración), 118 (interno), 119 (decimales), 120
(tax rate/kind) ya corrieron en el servidor — confirmado indirectamente:
F0-F2 de impuestos se probaron con éxito en el navegador contra ese deploy.

- **P0 de plata resuelto**: ventas con decimales no persistían. Cadena de 4
  causas reales, cada una tapando la siguiente: `AutoExecute` (`api/includes/lib/DB.php`)
  tragaba el error de PG y no seteaba `lastError` → se agregó `firstError`
  (una sola vez por transacción, no pisado por cascadas 25P02) → destapó que
  `transaction.transactionUnitsSold` era **INT** (todas las demás columnas de
  cantidad ya eran DECIMAL(15,3)) → al reproducir el signup en dev apareció
  `item.itemKind` NOT NULL sin default no mandado por los ítems demo → y
  `\RoleService` sin barra inicial resolviendo al namespace equivocado.
  Verificado end-to-end en el navegador: signup completo + venta de 1.5
  unidades en un tenant real.
- **Numeración de documentos F1**: tabla `document_sequence` + asignador
  atómico `DocumentNumber::allocate` (mig 117). Plan completo en
  `context/37-numeracion-documentos.md`. **Sin cablear a ningún emisor
  todavía** — solo existe la infraestructura.
- **Impuestos multi-país F0→F2b** (plan en `context/38-impuestos-multi-pais.md`,
  D1-D4 cerradas por el owner): tabla `tax` como fuente única (F0); motor de
  cálculo puro implementado DOS VECES espejo TS+PHP con 16 fixtures
  compartidos (F1); backend (`SaleService::enrichWithTaxes`) resuelve y
  congela tasa/kind/taxIncluded por línea server-side ANTES del motor — el
  payload del cliente deja de ser autoritativo (F2a); carrito del POS muestra
  el IVA real del motor en vez del hardcode (F2b). Verificado en dev: venta
  real con tasa 5% persistió `taxRate:5`/`taxAmount:150` Y el carrito mostró
  el mismo número.
- Otros fixes triviales de la cascada de tester bugs: producción no volvía a
  consumir insumos de un terminado ya vendido; Resumen de reportes no sumaba
  el descuento dos veces; columna Estado de transacciones mostraba cobro en
  vez de modalidad; timbrado se guardaba pero `flattenJsonb` lo vaciaba al
  leer; flag "Interno" se descartaba en el borde de la API (quemaba
  numeración fiscal); 37 call-sites de `toLocaleString()` sin locale (React
  #418 en prod); 2 fatales de prod encontrados vía API de GlitchTip.

## Archivos y cambios

- `context/38-impuestos-multi-pais.md` — plan vivo, header actualizado con
  progreso (F0-F2b hechas, F3 sigue).
- `context/37-numeracion-documentos.md` — plan vivo, F1 hecha, D3/D5/D6 abiertas.
- `context/10-roadmap.md` — triaje completo del reporte del tester 2026-08-04.
- `api/lib/services/SaleService.php` — `enrichWithTaxes()`, el corazón de F2a.
- `api/lib/Tax/*` (motor PHP) y equivalente TS en `frontend/lib/tax/*` — motor
  espejo F1, cualquier divergencia futura rompe el fixture runner.
- `api/includes/lib/DB.php` — `AutoExecute` ya no traga errores de PG.
- `frontend/app/(pos)/pos/**` — carrito lee IVA real (F2b), incluye fix de
  `loadFromOrder`/`loadFromSession` que no traían `taxId` al retomar orden/mesa.
- Migraciones 117 (document_sequence), 118 (interno), 119 (decimales INT→DECIMAL),
  120 (tax rate/kind) — todas corridas en prod.

⚠ Sin tocar (de otras sesiones paralelas, no pisar): `api/database/seeds/finance_backfill.php`,
`api/v1/finance/backfill.php`, `api/v1/transactions.php`,
`context/22-finanzas-module-plan.md`, `frontend/hooks/use-finance-backfill.ts`,
`frontend/public/sw.js` — modificados sin commitear al cierre de esta sesión,
son de vouchers/giftcards/finanzas en paralelo.

## Callejones sin salida

1. **"Aprobar dispositivo" en Ajustes→Dispositivos reportado como roto** —
   era error de automatización de browser propio (clic sobre un menú ya
   cerrado + screenshot de la pestaña equivocada). El flujo funciona,
   verificado después. Si alguien reporta lo mismo, sospechar del tooling
   antes que del código.
2. Dos issues de GlitchTip (`TenantContext` not found, `PriceListService`
   ArgumentCountError) eran ruido de debugging manual del owner con `php -r`
   en el servidor — no tráfico real, no se tocaron.
3. Primer intento de resolver tasa de impuesto (pre-F0) solo miraba
   `taxonomy`; hay DOS tablas de impuestos vivas (`tax` y `taxonomy`, mig 23
   desdobló sin retirar la vieja) — hubo que mirar ambas con COALESCE
   (`b20bd721`) antes de que F0 unificara la fuente.

## Próximo paso

**F3 del plan de impuestos** (`context/38-impuestos-multi-pais.md` §D):
facturación electrónica lee el desglose ya congelado por F2a en vez de
recalcular del catálogo, + bloques de plantilla de impresión parametrizados
por tasa (`item_total_by_rate`, `subtotal_by_rate`, `iva_by_rate`,
`iva_total`). Esto es lo que finalmente resuelve el pedido original del
tester (columnas IVA 0/5/10/15% en plantillas) y destraba los 9 campos que
hoy dan `null`. Después F4 (rollup con dimensión por tasa) y F5 (RG90/Libro
Ventas — no existen en ningún lado del repo todavía).

## Trampas conocidas

- **Vales canjeados y doble devengo de IVA**: F2a hace que las líneas de
  vale canjeado reciban `taxAmount` calculado, pero NO suman al total de la
  venta (ya se cobraron al emitir el vale). Riesgo de doble devengo —
  decisión del owner pendiente, spawneada como `task_54b691dc` en otra
  sesión. No resolver acá sin coordinar.
- **Código muerto post-F2a**: `SaleInput::$taxObj`/`taxObjSanitizer` quedaron
  sin consumidores. Spawneado como `task_b8337582` en otra sesión — no
  duplicar el trabajo.
- Otras 2 tareas en curso en sesiones separadas, no tocar: `task_fe009320`
  (cuelgue de `/settings/catalog?tab=X` por URL directa) y `task_42453fb0`
  (500 en `/v1/notifications/feed`).
- El payload de venta del POS sigue mandando `tax:0` a propósito — es
  intencional (F2a recalcula server-side), no un bug si se lo encuentra de nuevo.
- `document_sequence` (numeración F1) existe pero no está cableada a ningún
  emisor real todavía — no asumir que algún documento ya tiene correlativo.
