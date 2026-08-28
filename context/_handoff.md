# Hand-off — 2026-08-27

## Objetivo
Preparar Punto para go-live: stock ledger como única fuente de verdad
(banco: solo movimientos, nunca se pisa un saldo), numeración fiscal con
preaviso de timbrado, y cierre de la auditoría de seguridad de auth que
destapó un leak cross-tenant real. En paralelo, el panel completó el
cutover de cookie a Bearer (decisión del owner 2026-08-26, ejecutada
2026-08-27).

## Estado al cerrar
`main` en `6a9d70c6`, todo commiteado y pusheado. Auth: cutover Bearer
completo (F1-F4, `context/54`) — el panel ya NO emite ni acepta
`_jwt_panel`, solo Bearer, igual que el POS. Auditoría de seguridad:
4 P1 cross-tenant cerrados (mergeado `7a16fc96`), cero P0, 7 P2
intra-tenant documentados y SIN arreglar (ver Trampas). Stock ledger:
F1-F4 de `context/52` implementadas y con arnés propio 12/12 verde
(Docker + Postgres descartable, no corrido contra prod). Numeración:
D5 de `context/37` (preaviso de timbrado) implementada.

## Archivos y cambios
- `context/52-stock-ledger-unica-fuente.md` — plan + estado F1-F4 verde.
- `frontend/lib/cart/store.ts` (`rebuildSelectionsFromOrder`) — add-ons
  descuentan stock al cobrar orden/mesa.
- `api/lib/services/*Stock*` — lector único SUM, `manageStock` lanza en
  vez de fallar en silencio, combos sin doble reposición
  (`kind='compoundChild'` derivado de `meta.compound`).
- `api/tests/stock_ledger_test.php` — arnés 12/12.
- `frontend/lib/documents/timbrado-warning.ts` — umbrales compartidos
  (ámbar ≤200, rojo ≤50); badge en tab Cajas + 4to estado del pill del POS.
- `context/54-panel-auth-cookie-vs-bearer.md` — F1-F4 cerradas, checklist
  tildado. `api-client.ts` a Bearer, BFF sin reenviar cookie,
  `issuePanelSession()` no emite `_jwt_panel`.
- `frontend/app/api/dashboard/income-chart/route.ts` y descarga de
  plantilla CSV — dos call-sites que se habían quedado autenticando por
  cookie cruda pese al cutover, corregidos (`5825cfde`, `946472a6`).
- Checklist de go-live (artifact):
  https://claude.ai/code/artifact/f19a38b1-0cb0-4f25-939a-7448c3f999ac
- Backlog visual (artifact):
  https://claude.ai/code/artifact/6f23d6f5-f511-4db3-a378-c2abe1a35ebb

## Callejones sin salida
- Docker Desktop quedó ZOMBIE ~4h corriendo el arnés de stock: backend
  vivo pero engine sin socket; `killall` normal no lo mató (mismo PID
  sobrevivió), hizo falta `kill -9` y relanzar. Si un arnés dice "docker
  no levanta", chequear PID zombie antes de esperar.
- Primer run del arnés se recortó con `| tail -18` y se perdieron 2 de 3
  fallos reales — correr el runner completo y filtrar con grep sobre el
  archivo, nunca tail en la primera pasada.
- `$f['meta']` NO existe en filas del wrapper DB: `flattenJsonb` mezcla
  las keys del JSONB al nivel de fila y borra `meta` (ya costó 4 features
  el 2026-07-30, volvió a morder en `isCompoundChildRow`). Todo código
  que lea `meta` de una fila del wrapper debe contemplar la forma
  aplanada.
- `stockCOGS=''` reventaba el INSERT (NUMERIC no acepta string vacío) —
  antes lo tapaba el `false` silencioso de `manageStock`; con
  `manageStock` lanzando en fallo real, cualquier caller que arme COGS
  no-numérico ahora explota visible en vez de perderse.

## Próximo paso
Go-live: verificar que el deploy de Coolify en prod está al día con
`6a9d70c6` (rollbackea silencioso si falla el build) y correr el
simulacro end-to-end de 14 pasos del checklist (link arriba). En
paralelo, confirmar que la mig `175_bancard_qr_tenant_binding.sql`
corrió en prod (`bancard_qr` debe existir) — es la única de la sesión de
auditoría que toca schema y no se verificó en prod al cerrar esa sesión.

## Trampas conocidas
- **7 P2 de seguridad SIN arreglar** (todos intra-tenant, ninguno
  cross-tenant, ver `context/10-roadmap.md`): `api/v1/modules.php:44-83`
  (toggle/config sin permiso, el más directo); `api/bootstrap.php:226-244`
  (`X-Outlet-Id: all` sin chequeo de rol); `api/v1/attendance.php:25-40`
  (control de presencia = `md5` derivable); `api/v1/devices.php:35` (403
  vs 404 = oráculo de existencia); `OrderService.php:196-217,349` y
  `DashboardService.php:592` (scope incompleto, no explotable hoy);
  `DrawerService.php` (expenses sin companyId).
- NC de devolución con líneas de vale sin exclusión — pendiente decidir
  política en `context/36`.
- Gaps de stock a backlog (ver `context/52`): packs no descuentan
  componentes (G3), producción completada sin reversa (G6), POS offline
  con `stock: null` hardcodeado en `reshape.ts`.
- Postmortem pendiente: por qué el enforcement de lockout de PIN no se
  probó contra el rol `device` (la regresión la resolvió la sesión
  paralela "Punto bugs", mig 162 — el roster del lockscreen pasó al
  bootstrap del POS).
- Símbolo de moneda imprime `?` en la térmica — `UNKNOWN_CURRENCY_SIGN =
  "¤"` (`frontend/lib/tenant-locale.ts:135`) no existe en CP437.
- TZ "Asunción" literal en migs 157/160 + `period-close.php` — crítico
  antes del primer tenant no-PY.
- 8 sesiones de device duplicadas en prod esperando decisión de revocar.
- Backups: importante, NO bloqueante del go-live (decisión del owner).
- Costo de inventario va CON IVA incluido — decisión del owner, no bug.
