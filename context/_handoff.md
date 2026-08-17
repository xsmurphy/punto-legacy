# Hand-off — 2026-08-17

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Sesión larga (2026-08-15/17, entreverada con ≥3 sesiones paralelas — ver
bitácora). Ejes propios: impuestos F3 (FE lee IVA congelado), resolver
canónico de detalle de transacción, remisión, recuperar RG90/Libro Ventas,
hacer real la regla "toda mutación se sincroniza sola" (el POS nunca
escuchaba), auditar el offline del POS contra su propia regla, cuentas por
pagar de cero, y arrancar `context/modules/`.

## Estado al cerrar

Todo commiteado y pusheado a `main` (`990f1956..1d4068d8`, 90 commits). Deploy
no confirmado explícitamente esta sesión (ver "Pendiente de verificación").
Detalle de cada feature en la bitácora y en `context/38/39/41/42-remision/43`.
Lo que condiciona retomar: F4 de `context/39` (migrar el POS al resolver
canónico) queda abierta a propósito; `context/modules/` tiene 13/25 docs;
`frontend/public/sw.js` modificado sin commitear es artefacto de build, no
requiere acción.

## Archivos y cambios

- `context/38-impuestos-multi-pais.md`, `39-detalle-transaccion.md`,
  `41-addons-y-combos.md` — fases hechas documentadas adentro; actualicé sus
  filas resumen en `CLAUDE.md` (estaban desactualizadas).
- `api/lib/Sales/verify_chain/run.sh` — arnés E2E (Postgres descartable, 2
  tenants, venta real + factura offline + impresión) + `verify_realtime.php`,
  `verify_sync.php`, `verify_offline_resolution.php`.
- `frontend/lib/commands/create-sale.ts`, `api/lib/Sales/SaleInput.php:48,157`,
  `SaleService.php:663` — hueco del P0 de numeración (ver Próximo paso).

## Callejones sin salida

1. **Agente murió tras ~8h sin commitear** (triggers de satélites,
   `context/45`), se perdió todo, sin branch/worktree. Relanzado exigiendo
   commits incrementales. **Exigirlo siempre en briefs largos.**
2. **F3b duplicado**: agente "colgado" seguía vivo, dos escribieron
   `blocks.ts` a la vez (`74252a02`/`5f77cefc`). Sin daño, pero confirmar que
   un agente realmente murió antes de relanzarlo.
3. **Commit de docs se llevó 6 archivos de otra tarea** (`5f7842cb`, índice
   compartido entre sesiones). Usar `git commit -- <paths>` explícitos.
4. **3 agentes colgados** esperando notificación de un `code-reviewer` que
   ellos mismos lanzaron en background — debe correr en primer plano.
5. **Diagnósticos propios que resultaron falsos** (verificar contra el flujo
   real, no contra un conteo): T8 espacios no era el modal tablet (ya estaba
   resuelto); `sumDerivedAmounts` sí filtraba anulados; "las ventas online
   las numera el servidor" — **falso, es la raíz del P0 de abajo**; "no
   existe compra a crédito" se cerró en falso, el owner mostró una real.
6. **Sobre-diseñé dos veces**, el owner frenó ambas: gating de bloques de
   plantilla por tipo de documento (el constructor ya alcanza) y marca de
   "crédito no habilitado" (el flag ya vive en el cache local del POS).

## Próximo paso

**P0 fiscal de numeración, dos problemas independientes** (`context/10-roadmap.md`, commit `1d4068d8`):

1. **Toda venta ONLINE se persiste sin número de comprobante.** El front
   nunca manda `invoiceno` (`create-sale.ts`), `SaleInput` lo deja null
   (`SaleInput.php:48,157`), `SaleService::save()` persiste ese null (`:663`)
   sin llamar a `DocumentNumber::allocate()`. El único camino que asigna
   número es `api/v1/offline-sync.php:38,59`. El ticket tampoco muestra el
   comprobante. Empezar cableando `allocate()` en el camino online de `save()`.
2. **`context/29-numeracion-y-exclusividad-de-caja.md`**: 4 dispositivos POS
   sobre la misma caja comparten arriendo → mismo número offline. Verificado
   contra prod 2026-07-28. Plan F0-F6, **nada implementado**.

Después: 12 módulos de `context/modules/` sin documentar; 4 bugs del roadmap
(costo de producción directa no se calcula; sus reportes salen vacíos;
add-ons de orden no descuentan stock al cobrarse; `$sD['type']` leído sin
guard en un segundo call-site).

## Trampas conocidas

- **3 stashes sin revisar**: `sw.js pre-merge satelites` (propio,
  descartable); `wip-other-agent-contacts` y `parallel-session-wip` (sesiones
  paralelas viejas, nadie los revisó, pueden tener trabajo perdido).
- **Archivos sin commitear de OTRAS sesiones**, no tocar sin coordinar:
  `api/v1/modules.php`, `frontend/app/(screen)/display/display-card.tsx`,
  `frontend/components/layout/pos-sidebar.tsx`.
- Numeración anulada no se reusa — un salto en la secuencia no es bug.
- Offline-first es la BASE del POS: lo que se EMITE funciona sin internet, el
  backend nunca rechaza una venta ya emitida. Ítem/contacto son raíces de
  sync — cualquier satélite recarga el padre entero.
- Sin confirmar: T3/T9/T10 del tester; realtime en el deploy real (no solo el
  arnés); si consignación/exposición mueven stock en la remisión.
