# Hand-off — 2026-08-03

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Cerrar el módulo de facturación electrónica (SIFEN/Factomate) de punta a punta —
F3 (medios de pago, RUC, notas de crédito) hasta F7 (onboarding white-label,
el tenant nunca ve al proveedor) — arrancando desde el hand-off del 2026-07-30
que dejaba F0-F2 hechas. Después, una cola de fixes puntuales reportados por
el owner sobre `/items` (variantes, tab Stock) y `/pos` (mesa que forzaba el
pago). Sin plan único para la segunda mitad — cada pedido cerrado end-to-end
antes de pasar al siguiente.

## Estado al cerrar

**Todo commiteado en `main` LOCAL. NO pusheado** (pedido explícito del owner:
"hagamos varios cambios y luego un solo push" — está esperando confirmación
para el push final, no se hizo por decisión suya, no por olvido).

- **FE F3** — medios de pago reales por línea (antes: uno solo, con el total),
  lookup de RUC vía padrón del emisor (Factomate) + padrón público de fallback,
  notas de crédito por devolución, y un bug de fondo: la factura declaraba el
  BRUTO en vez del NETO (sobre-declaraba IVA en toda venta con descuento).
- **FE F4** — rip-out completo del proveedor de FE legacy (`sendFE`/`consultFE`,
  `FACTURACION_ELECTRONICA_*`, `ElectronicInvoiceService`).
- **FE F6** — portal público `/factura/<token>` para que el comprador vea su
  documento sin cuenta (token firmado HMAC, sin auth, aislamiento multi-tenant
  vía el companyId firmado adentro del token — no un parámetro del request).
- **FE F7** — onboarding white-label: el tenant completa un formulario LEGAL
  (actividad económica, email, CSC) y Punto provisiona el emisor con su
  credencial admin (`FACTOMATE_ADMIN_*` en env). RUC/razón social salen de
  Configuración del negocio, el timbrado vive EN LA CAJA (cada caja es un
  punto de expedición) — nada duplicado. Alta reanudable por checkpoints.
- **Fix de raíz** `Validation::isValid()`: `true == 'undefined'` en PHP 8 hacía
  que CUALQUIER booleano `true` de un body JSON se leyera como `false`. Rompía
  todos los switches de `/modules` (no solo FE) — reportado por el owner como
  "el switch de facturación electrónica no se activa".
- **Fix** `VariantService::validateParent()` tipado `: array` devolvía
  `CaseInsensitiveArray` crudo → TypeError, rompía TODO guardado de variantes.
- **Tab Stock** de items (`/items/[id]`, pestaña Stock): reemplazó los 3 KPIs
  "Próximamente" por datos reales (costo promedio ponderado + valor total
  calculados en vivo desde el ledger `stock`, precio de compra reusando un
  endpoint que ya existía) + historial paginado + dialog de ajuste manual.
- **Fix** mesa de POS: "Cobrar" armaba el carrito y abría el modal de pago en
  el mismo paso — no dejaba sumar cliente/descuento antes. El `CartPanel` ya
  está montado y visible en `/pos/espacios`; bastó con no forzar el modal.
- **Catálogo de módulos**: sacadas las pantallas (KDS/CDS/COS — son
  dispositivos, no módulos) y los módulos sin implementación real pasaron a
  "Próximamente" (CRM, Campañas, Factura en PDF, Reportes diarios, API,
  Recordatorios, Verificador de Precios). Facturación Electrónica a Destacados.

## Archivos y cambios

- `context/28-facturacion-electronica-plan.md` — plan vivo, actualizado en cada
  fase (F3→F7), es la fuente de verdad de decisiones/SIN VERIFICAR.
- `api/lib/EInvoice/*` — `EInvoiceProvisioningService.php` (nuevo, alta
  white-label reanudable), `FactomateSession.php` (cadena admin vía
  PhoneLogin sin contraseña del tenant), `PortalToken.php` (nuevo, HMAC),
  `EInvoiceService.php` (+744/-231, el más tocado del módulo).
- `api/lib/PaymentMethods/PaymentMethodResolver.php` — nuevo, extraído de
  `Finance\ConfigService` para compartir la resolución clave-de-pago↔taxonomyId
  entre Finanzas y FE.
- `api/lib/Contacts/TaxpayerLookupService.php` — nuevo, lookup de RUC backend
  (antes el browser del cajero pegaba directo a turuc.com.py).
- `api/v1/einvoice-public.php` — nuevo, endpoint del portal (sin auth).
- `api/lib/App/Helpers/Validation.php` — fix del booleano (~716 callers).
- `api/lib/Items/VariantService.php`, `StockMovementsService.php` (nuevo).
- `api/lib/services/RegisterAdminService.php` — timbrado por caja.
- `frontend/components/items/stock-tab.tsx`, `hooks/use-item-stock.ts` — nuevos.
- `frontend/app/(pos)/pos/espacios/page.tsx` — fix mesa (10 líneas).
- `frontend/lib/modules-catalog.ts` — poda + reorden de categorías.
- Migración `100_einvoice_whitelabel.sql` — `factomate_tenant_id/user_id`,
  `fiscal`, `provisioning` (checkpoints), status `provisioning`.

⚠ Sin commitear (de OTRA sesión, Finanzas — no tocado, viene de hand-offs
anteriores, sigue sin resolver): `api/database/seeds/finance_backfill.php`,
`api/lib/services/ReturnService.php` (parcialmente — otra sesión le agregó un
vínculo a `transaction_link`), `api/v1/finance/backfill.php`,
`api/v1/transactions.php`, `context/22-finanzas-module-plan.md`,
`frontend/hooks/use-finance-backfill.ts`.

## Callejones sin salida

1. **Colisión real entre sesiones paralelas — casi se pierden 3 commits.**
   Los últimos 3 commits de esta sesión (VariantService, tab Stock, fix mesa)
   quedaron sin pushear por pedido del owner. En algún momento, OTRA sesión
   compartiendo este mismo working directory corrió algo que resetéo `main`
   local a un commit anterior (`675a4608`, de esa otra sesión) — los 3 commits
   quedaron huérfanos, fuera de cualquier branch, con sus archivos borrados
   del disco (aunque los objetos de git sobrevivieron, recuperables por hash).
   Se recuperaron con `git cherry-pick` sobre el nuevo tip de `main`
   (`a2c8b03d`, `418cecf3`, `40e8cbf9` son los hashes nuevos). **Lección: un
   commit sin pushear en un working directory compartido por sesiones
   paralelas no está a salvo** — el guardado real es el push, no el commit
   local. Si se repite esta situación, verificar con
   `git merge-base --is-ancestor <hash> HEAD` que los commits de la sesión
   siguen en la rama antes de asumir que están seguros.
2. **La API real de Factomate sigue sin tocarse en F3/F4/F6/F7.** Todo el
   trabajo de esta sesión es SIN VERIFICAR contra la API real: falta la
   credencial admin (`FACTOMATE_ADMIN_USERNAME/PASSWORD_TEST` en env) —
   sin ella, el alta responde "servicio no disponible" y no hay forma de
   probar `CreateExternal`/`PhoneLogin` con identidad email/alta de timbrado
   por caja. Ver `context/28` §Preguntas abiertas para la lista completa.
3. **`itemCost` sigue sin auto-actualizarse** desde el ledger de stock pese a
   que el form del ítem dice "se actualiza solo con movimientos de inventario"
   — es falso, nunca se implementó. El tab Stock nuevo NO lo arregla (decisión
   deliberada: tocar `Inventory::manageStock`, el hot path de cada venta, para
   esto es un cambio de mayor riesgo que no correspondía a este pedido — el
   KPI "Costo promedio" del tab se calcula en vivo desde `stock`, no depende
   de `itemCost`). Queda como deuda conocida, no como bug de esta sesión.

## Próximo paso

1. **Confirmar con el owner si pushear ahora** — dado el near-miss del punto 1
   de arriba, hay presión real para no dejar más commits sin pushear de lo
   necesario. `main` local está 3 commits adelante de `origin/main`
   (`a2c8b03d..40e8cbf9`).
2. Pendientes explícitos que el owner pidió y quedaron en cola sin empezar
   (no bloquean nada, son las próximas dos tareas si se retoma):
   - Quitar iconos redundantes icon+texto en botones de Órdenes
     (`order-detail-view.tsx`/`order-card.tsx`: `DollarSign` en "Cobrar",
     `Printer` en "Reimprimir comanda", `X` en "Cancelar orden" — el icono es
     decorativo porque el texto ya dice lo mismo; los botones ICON-ONLY como
     el trigger `MoreHorizontal` y `RowActions` de las DataTable quedan igual).
   - Unificar tabs al 100% de ancho en todas las secciones del panel (hoy
     algunas ocupan el ancho del contenedor, otras solo el ancho del texto —
     sin auditar todavía, no se identificaron los archivos concretos).
3. Cargar `FACTOMATE_ADMIN_USERNAME_TEST`/`PASSWORD_TEST` en env y correr
   migs 92/93/95/100 en prod antes de poder verificar F3-F7 contra la API real.

## Trampas conocidas

- **El working directory de `system/` es compartido por sesiones paralelas
  en simultáneo** (confirmado en esta sesión, ver Callejón #1) — cualquier
  operación git asumiendo que "nadie más está tocando esto ahora" es
  arriesgada. Verificar `git status`/rama ANTES de cualquier commit/push si
  pasó tiempo desde el último chequeo.
- `api/lib/services/ReturnService.php` tiene cambios de OTRA sesión sin
  commitear (vínculo a `transaction_link`) — no pisarlos si se toca ese
  archivo.
- El drainer de FE (`EINVOICE_DRAIN_SECRET` + cron) sigue sin configurar en
  prod — heredado del hand-off de F0-F2, no tocado esta sesión.
- Trampas heredadas de sesiones previas sin resolver: dogfooding de
  facturación del SaaS sin tenant emisor configurado en `/admin`→Plataforma,
  `SIGNUP_OTP=off`, `APP_DEBUG=true` en Coolify.
