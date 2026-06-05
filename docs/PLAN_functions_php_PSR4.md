# Plan de migración de `app/includes/functions.php` a PSR-4

> **Estado:** En ejecución. Slices 0, 1, 2, 3 completos. Próximo: Slice 4 (`App\Helpers\String`).
> **Última actualización:** 2026-06-05 (Slice 3 — commit 3fdeeb5)
> **Estimado total:** 220h (~11 semanas FTE, 7 con 2 devs)

## Resumen ejecutivo

`app/includes/functions.php` **comenzó con 5116 líneas y 180 funciones globales**. Post Slice 1: 4068 líneas (−1049 dead code). Post Slice 2: 4062 líneas. Las funciones restantes incluyen el money path (cálculo de impuestos, comisiones, inventario, gift cards).

Migrarlo a namespaces PSR-4 sin romper nada requiere un **enfoque gradual de 16 sub-slices** durante 7-11 semanas.

## Inventario actual

- Líneas: 5116
- Funciones: 180
- Dead code: ~32 funciones sin callers (18%)
- Funciones que tocan BD: 95 (53%)
- Top callers: `validity` (130), `ncmExecute` (107), `iftn` (88), `jsonDieMsg` (57), `toUTF8` (39)

## Categorización por dominio (18 categorías)

| Dominio | Funciones | Líneas | Callers | Riesgo |
|---|---|---|---|---|
| Core DB (`ncmExecute`, `ncmUpdate`, `ncmInsert`, `getValue`, `_flattenJsonb`) | 5 | 250 | 127 | **CRÍTICO** |
| Validation (`validity`, `validateBool`) | 4 | 50 | 200+ | **CRÍTICO** (linchpin) |
| Null-coalesce (`iftn`, `explodes`, `implodes`) | 3 | 30 | 130 | Medio |
| Money/Tax (`formatCurrentNumber`, `addTax`, `divider`) | 10 | 400 | 50 | **CRÍTICO** |
| Response (`jsonDieMsg`, `jsonDieResult`, `dai`) | 4 | 80 | 67 | Bajo |
| String/Encoding (`toUTF8`, `markupt2HTML`) | 6 | 150 | 80+ | Medio |
| Date/Time (`niceDate`, `getNextDatePeriod`) | 6 | 200 | 30 | Bajo |
| Customer/Contact | 15 | 800 | 60 | Alto (GDPR) |
| Inventory/Stock (`manageStock`, `getProductionCOGS`, `getComboCOGS`) | 8 | 300 | 40 | **CRÍTICO** |
| Document Numbering (`getNextDocNumber`) | 4 | 80 | 25 | **CRÍTICO** |
| Gift Card | 2 | 60 | 5 | Alto |
| Notification (`sendEmails`, `sendSMS`, `sendPush`) | 5 | 400 | 20 | Bajo |
| Calendar (4 funciones) | 4 | 200 | **0** | **DELETE** |
| Taxonomy/Reference | 8 | 150 | 40 | Bajo |
| Outlet/Register | 4 | 80 | 20 | Bajo |
| Utility Functions | 12 | 150 | 60 | Bajo |
| Misc/Unknown | 15+ | 500 | variable | Variable |

## Decisión arquitectónica: Approach C (Híbrido Gradual)

3 enfoques considerados:

| Aspecto | A (Big Bang) | B (Files autoload sin namespace) | **C (Híbrido gradual)** |
|---|---|---|---|
| Breaking changes | Alto | Cero | Cero |
| Resuelve namespace | Sí | No | Sí |
| Testing incremental | No | Sí | Sí |
| Timeline | 4-6 sem | 1 sem | 11 sem |
| **Veredicto** | ❌ | ❌ | ✅ |

**Approach C funciona así:**

1. Crear `App\Helpers\X` / `App\Domain\Y` classes con métodos estáticos namespaced.
2. Funciones globales en `functions.php` se vuelven wrappers:
   ```php
   function validity($v) { return \Punto\App\Helpers\Validation::isValid($v); }
   ```
3. Callers nuevos usan namespace; callers viejos siguen usando funciones globales.
4. Migración gradual de callers cuando se toquen archivos (no big bang).
5. Wrappers permanecen ≥2 releases con deprecation notices antes de removerse.

**Razones por las que C es el correcto para Punto:**
- `api/lib/services/` ya usa namespace `Punto\Api\Services` — continuidad.
- `action.php` strangler requiere coexistencia temporal.
- Money/inventory lógica crítica — necesita migración incremental con pruebas.
- Equipo pequeño — no puede sostener 2 sprints paralelos.

## Tabla de 16 sub-slices

| Sub-slice | Qué | Horas | Riesgo | Bloquea | Notas |
|---|---|---|---|---|---|
| **0** ✅ | Estructura PSR-4 + composer autoload | 1 | Bajo | Nada | COMPLETO (commit 8a7819c) |
| **1** ✅ | Borrar ~27 funciones dead (−1049 líneas) | 4 | Bajo | Nada | COMPLETO — ver session-log 2026-06-04 |
| **2** ✅ | `App\Http\Response` (jsonDieMsg, jsonDieResult, dai) | 8 | Medio | Tests | COMPLETO (commit ceed82d) — 761 callers preservados |
| **3** ✅ | `App\Helpers\Validation` (validity, validateBool, validateHttp, validateResultFromDB) | 8 | **CRÍTICO** | 4-10 | COMPLETO (commit 3fdeeb5) — **2298 callers** preservados (validity 716 + validateHttp 1524 + validateBool 58). Estimación original de 130 callers fue 5.5× off — el patrón funcionó igual. |
| **4** | `App\Helpers\String` (toUTF8, markup) | 8 | Bajo | Nada | 80+ callers |
| **5** | `App\Helpers\Date` (niceDate) | 8 | Bajo | Nada | 30 callers |
| **6** | `App\Helpers\Utils` (divider, counts) | 4 | Bajo | Nada | 60+ callers |
| **7** | `App\Domain\Taxonomy` | 12 | Medio | 8 | + cache layer |
| **8** | `App\Domain\Store` (outlets, registers) | 12 | Bajo | 9 | Depende de 7 |
| **9** | `App\Domain\Customer` (getData, loyalty) | 20 | Alto | 15 | 60 callers, GDPR |
| **10** | `App\Database\Query` (ncmExecute) | 28 | **CRÍTICO** | 11-14 | 127 callers |
| **11** | `App\Domain\Document` (docNumber) | 16 | **CRÍTICO** | 12 | comprobante audit |
| **12** | `App\Domain\Money` (formatting, tax) | 28 | **CRÍTICO** | 13 | precision tests |
| **13** | `App\Domain\Inventory` (stock, COGS) | 28 | **CRÍTICO** | Nada | snapshot tests |
| **14** | `App\Domain\GiftCard` | 12 | Alto | Nada | 5 callers, isolated |
| **15** | `App\Services\Notification` (DI-ready) | 20 | Bajo | Nada | Mailgun, Twilio, Firebase |
| **16** | Deprecation notices + remover wrappers | 4 | Bajo | Nada | Después de 2+ releases |

**Total: 220h (~11 semanas FTE).**

## Top 5 funciones críticas (por callers)

```
1. validity()             130 callers   L2540   linchpin del refactor
2. ncmExecute()           107 callers   L2590   DB SELECT con JSONB demote
3. iftn()                  88 callers   L2503   null-coalesce legacy
4. jsonDieMsg()            57 callers   L4315   API error responses
5. toUTF8()                39 callers   L4879   charset conversion
```

## Funciones que NO se tocan (fuentes de verdad)

**Dinero e impuestos** — read-only excepto bug fixes críticos:
- `formatCurrentNumber()`, `formatNumberToInsertDB()`, `addTax()`, `divider()`

**Stock y costo** — snapshot tests antes de cualquier refactor:
- `manageStock()`, `getProductionCOGS()`, `getComboCOGS()`

**Documento números** — audit trail mandatory:
- `getNextDocNumber()`, `updateDocNumber()`

**Customer data** — GDPR compliance:
- `getCustomerName()`, `getContactData()`

## Top 5 riesgos críticos

1. **`ncmExecute()` + PG JSONB demoting puede romper** — Test fixtures con demoted columns + suite `_flattenJsonb()`.
2. **`validity()` = 130 callers de un solo golpe** — Wrapper FIRST en Slice 3 day 1; shadow deploy comparando old vs new.
3. **Money rounding produce errores en comprobante electrónico** — Extraer 50+ tx reales; regression suite con Finance.
4. **Inventory COGS/waste drift** — Snapshot valuation pre-Slice 13; flow test recipe→production→sale; precisión 0.1%.
5. **`action.php` strangler se rompe** — Wrappers permanecen ≥2 releases; deprecation notices (no fatal); no remover hasta migración completa.

## Recomendación inmediata (Slice 0)

**Tiempo: 1-2h | Riesgo: Bajo | Breaking changes: Cero | Valor: Habilita Slices 1-16**

1. `mkdir -p app/{Helpers,Domain,Http,Services,Database}/...`
2. Agregar `autoload.psr-4` a `app/composer.json`
3. `composer dump-autoload`

**Valor entregado por Slice 0:**
- IDE autocompletion funciona para código futuro namespacizado
- Estructura PSR-4 lista para migración incremental
- Cero breaking changes — app continúa funcionando

## Estimación total (Approach C)

- Horas: ~220 (55 días @ 4h/día)
- Semanas FTE: 11 (1 dev) o 7 (2 devs, 35% reducción)
- Equipo: 1.75 FTE (lead + junior/QA part-time)

## Go/No-Go criteria

- Unit tests >85% coverage
- Regression suite: 50+ real transaction scenarios
- Smoke tests: POS workflow E2E
- Performance: sin regresión en top 10 slow queries
- Security: sin nuevos injection vectors (DB queries)

## Funciones dependientes del strangler action.php

Único caller externo es `action.php`:
- `getCustomerName()` [L667]

**Implicación:** mientras action.php exista, esas funciones siguen retenidas. Se eliminan junto con action.php (ver `docs/PLAN_action_php_elimination.md`).

## Follow-up out of scope

Después de completar esta migración:
1. Eliminar variables globales (`$db`, `$SQLcompanyId`) → DI container
2. Completar migración action.php → bff (strangler pattern)
3. Database Query Builder pattern (no raw SQL)
4. Static analysis: phpstan, psalm en CI (slice #5 ampliado)
