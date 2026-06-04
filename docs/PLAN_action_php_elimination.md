# Plan de eliminación de `app/action.php`

> **Estado:** Diseño aprobado, no iniciado.
> **Última actualización:** 2026-06-04 (audit por Explore agent)
> **Estimado total:** 70-93h de desarrollo (5-6 semanas FTE, 3 semanas con 2 devs)

## Resumen ejecutivo

`app/action.php` retiene 1685 líneas de código legacy que manejan el guardado de TODOS los tipos de transacción excepto `type 0/3` simples (ya migrados a `SaleService` en slices 35a-f). Eliminar el archivo requiere migrar **8 tipos activos** del POS al patrón moderno BFF→API→Service, en orden de riesgo creciente.

**No es un slice; es un programa de migración de 5-6 semanas.**

## Inventario real de tipos

`postSale` (app.js:16513) rutea según `transactionType`:
- Type 0/3 simple → `SaleService` ✅
- Type 0/3 con parentId/giftcard/EI/recurrente → `action.php` (fallback 422)
- Type 1–13 (resto) → `action.php` directo

| Type | Nombre | Estado | Acción |
|---|---|---|---|
| 0 | Cashsale simple | ✅ SaleService | — |
| 0 con parentId/etc | Cashsale no-simple | action.php | Sub-slice 35e |
| 1 | CashPurchase | Muerto (sin UI) | Descartar en 35i |
| 2 | Saved | localStorage (nunca server) | No aplica |
| 3 | Creditsale simple | ✅ SaleService | — |
| 3 con parentId/etc | Creditsale no-simple | action.php | Sub-slice 35e/35f |
| 4 | CreditPurchase | Muerto | Descartar en 35i |
| 5 | Pago factura crédito | action.php — **dinero** | Sub-slice 35e.1 |
| 6 | Devolución | action.php — **dinero** | Sub-slice 35e.2 |
| 7 | Anulada | Muerto (void usa otro path) | Descartar en 35i |
| 8 | Recurrente | action.php | Sub-slice 35f |
| 9 | Cotización (quote) | action.php | Sub-slice 35h |
| 10 | Delivery | Muerto (else vacío) | Descartar en 35i |
| 11 | OpenTable | localStorage (nunca server) | No aplica |
| 12 | Order (KDS) | action.php | Sub-slice 35g |
| 13 | Schedule/agendamiento | action.php | Sub-slice 35d |

**Activos a migrar: tipos 5, 6, 8, 9, 12, 13 (+ caso parentId de 0/3).**
**Muertos a descartar: tipos 1, 4, 7, 10.**
**No aplican (localStorage): 2, 11.**

## Tabla de sub-slices

| Sub-slice | Migra | Helpers críticos | Horas | Riesgo |
|---|---|---|---|---|
| **35h** | Type 9 (quote) | `updateDocumentNumber`, `sendEmails` | 4-6 | Bajo |
| **35f** | Type 8 (recurrente) + type 3 repeat | `getNextDatePeriod` | 4-6 | Bajo |
| **35g** | Type 12 (order/KDS) | `sendWS`, `manageStock` | 6-8 | Medio |
| **35d** | Type 13 (schedule) | `insertEmptySchedule`, push/email/sms | 5-7 | Medio |
| **35c** | Type 0/3 con giftcard | `manageGiftCard` | 4-6 | Bajo |
| **35e.1** | Type 5 (credit payment) — **dinero** | `manageStock`, parentId array logic | 6-8 | **ALTO** |
| **35e.2** | Type 6 (devolución) — **dinero** | `flipOnReturn`, EI, comisiones | 8-12 | **ALTO** |
| **35b** | Types 0/3/6 + EI (sendFE) | `sendFE` best-effort | 3-5 | Integración |
| **35i** | Borrar action.php + tipos muertos (1,4,7,10) | — | 2-3 | Bajo |

**Total: 42-61h directo + 8h diseño + 16-24h QA = 70-93h.**

## Caso especial: `parentId`

`parentId` aparece en 3 contextos semánticamente distintos:

1. **Type 5 (común):** id de la factura siendo pagada. Puede ser número/UUID/**array `{txId → debt}`** (cuando paga múltiples deudas).
2. **Type 0/3 con parentId (edge raro):** venta hija que ajusta una venta matriz.
3. **Type 12 con tag de cierre:** orden KDS que cierra una orden previa.

`action.php` líneas 141-157 tiene la lógica de detección (numeric → UID, string → ID, array → ARRAY).

**Sub-slice 35e.1 DEBE soportar parentId como array** — es lo único que action.php hace que SaleService aún no contempla.

## Helpers que NO se tocan

Estos están en `app/includes/functions.php` y son fuente única de verdad — JAMÁS refactorizar durante la migración:

- `flipOnReturn()` — sign logic para devoluciones
- `manageStock()` — inventario transaccional
- `manageCustomerLoyalty()`, `manageCustomerStoreCredit()`, `manageGiftCard()` — balance del cliente
- `getUserComissionTotal()`, `getItemComsissionTotal()` — comisiones (dinero)
- `getProductionCOGS()`, `getComboCOGS()` — COGS
- `getSaleType()` — type → docType
- `getNextDatePeriod()` — fechas recurrentes
- `insertEmptySchedule()` — schedule sessions
- `sendWS()`, `sendEmails()`, `sendSMS()`, `sendPush()`, `sendAuditoria()`, `sendFE()` — best-effort

Refactor de `functions.php` queda para DESPUÉS de borrar action.php.

## Orden de ataque recomendado

**Sprint 1 (semana 1-2)** — bajo riesgo, paths operacionales:
1. Slice 35h (quote, type 9) — 4-6h
2. Slice 35f (recurrente, type 8) — 4-6h
3. Slice 35g (order/KDS, type 12) — 6-8h

**Sprint 2 (semana 3-4)** — medio riesgo, notificaciones:
4. Slice 35d (schedule, type 13) — 5-7h
5. Slice 35c (giftcard en 0/3) — 4-6h

**Sprint 3 (semana 5-6)** — **alto riesgo, money path**:
6. Slice 35e.1 (type 5 credit payment) — 6-8h, pair programming
7. Slice 35e.2 (type 6 return) — 8-12h, pair programming
8. Slice 35b (EI) — 3-5h
9. Slice 35i (borrar action.php + descartar muertos) — 2-3h

## Riesgos top-5

| # | Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|---|
| 1 | Type 5 parentId array logic falla | Media | Crítico (pagos inexactos) | Mock array tests + staging E2E |
| 2 | `flipOnReturn` sign inversión incorrecta | Baja | Crítico (reportes invertidos) | Unit tests vs action.php behavior |
| 3 | `manageStock` race condition | Baja | Crítico (stock fantasma) | DB constraint, transacción aislada |
| 4 | Comisión calculation error | Baja | Crítico (dinero mal distribuido) | Unit test vs legacy verbatim |
| 5 | WebSocket broadcast falla en type 12 | Media | Alto (orden no visible en KDS) | Best-effort, polling fallback |

## Prerequisitos antes de arrancar

1. **Test suite mínimo para action.php** — capturar comportamiento legacy por tipo (al menos 1 caso E2E por tipo activo).
2. **CI básico** (slice #5 del top-5 mejoras estructurales) — Playwright para regresión.
3. **Staging environment** con BD de prueba para validar money path slices (35e.1, 35e.2).
4. Decisión de scope: ¿descartar tipos muertos (1, 4, 7, 10) ahora o en 35i?

## Deliverables por sub-slice

Cada sub-slice debe producir:
1. Service nuevo en `api/lib/Sales/` con namespace, DTO, exceptions custom
2. Endpoint `api/v1/sales-<tipo>.php` o ampliación de `api/v1/sales.php`
3. BFF mínimo en `app/bff/` si requiere traducción
4. Tests unitarios + 1 E2E happy path por tipo
5. Update de `saleIsSimplePathEligible()` removiendo el tipo del rechazo
6. Repunte del callsite en `app/scripts/app.js`

## No hacer

- ❌ Refactorizar `app/includes/functions.php` durante esta migración
- ❌ Cambiar el shape del payload `data.transaction` que envía el front
- ❌ Tocar `flipOnReturn`, `manageStock`, `manage*` (son source of truth)
- ❌ Hacer múltiples sub-slices del Sprint 3 en paralelo (money path)
- ❌ Mergear sin reviewer obligatorio para 35e.1 y 35e.2

## Referencias

- `app/action.php:57-1685` — código a migrar
- `app/scripts/app.js:11587` (`saveSale`) — origen de payloads
- `app/scripts/app.js:16513` (`postSale`) — router actual
- `app/includes/functions.php` — helpers reutilizables
- `app/includes/functions.php:saleIsSimplePathEligible` — gate de elegibilidad
- `api/lib/Sales/SaleService.php` — modelo a replicar
- `context/10-roadmap.md` § "action.php — estado post-Slice 36" — referencia general
