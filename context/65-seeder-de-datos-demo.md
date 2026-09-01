# 65 — Seeder de datos demo

> Estado: **PLAN, sin implementar.** Fecha 2026-09-01. D1 cerrada por el
> owner, no relitigar. El resto (D2-D4 + mecanismo de ejecución) son
> PROPUESTAS mías y necesitan su OK — marcadas **[?]**.
> No hay motor previo que reusar (a diferencia de `context/63` y `context/64`):
> esto se arma desde cero, apoyado en dos precedentes reales del repo —
> `finance_backfill.php` y `run_sale_chain.php` — ver §Estado del código.

## Qué pidió el owner

Un botón en `/admin` que siembre datos en una cuenta, por un período
seleccionable. Textual: *"no solo ventas, también algunos clientes, plan de
cuentas etc. más que nada para tener info completa de reportes"*. El objetivo
real: poder mirar reportes con datos que parezcan de un comercio de verdad —
hoy una cuenta nueva no tiene con qué llenar un dashboard, un ranking de
productos ni una serie mensual.

## Decisión cerrada por el owner (2026-09-01)

**D1 — El seeder SOLO puede apuntar a cuentas internas (`company.isinternal`),
verificado en el BACKEND.** El owner: *"solo cuentas internas que sirven para
demo"*. La marca ya existe y ya es el candado que separa a los clientes reales
del tenant emisor de facturación SaaS — `AdminReportsService::notInternalWhere()`
la usa en cada agregado (`isinternal IS NULL OR isinternal = 0`, mig 114) y el
listado de `/admin/companies` ya la muestra (`page.tsx:233`).

El endpoint del seeder hace la verificación inversa —`isinternal = 1`,
explícito, server-side— y **rechaza cualquier otro destino, sin excepción ni
flag de override.** No es un candado de UI: un clic equivocado sobre un
tenant real no es "datos de prueba molestos", es ventas falsas en la
contabilidad de un cliente, stock descontado de verdad y numeración fiscal
consumida. Cada venta toma un correlativo del timbrado y ante SIFEN eso no se
deshace (`context/29`).

## Estado del código (verificado)

**Dos precedentes de "sembrar por los servicios reales, no INSERT directo"
ya viven en el repo:**

- `api/database/seeds/finance_backfill.php` — reconstruye `fin_movement`
  llamando los mismos `record*` de `FinanceLedger` que usan los hooks en
  vivo. Idempotente por el UNIQUE `(companyid, source, sourceid, accountid)
  WHERE sourceid IS NOT NULL` (mig 73). Corre por CLI con el `companyId`
  como argumento (`php finance_backfill.php <companyId>`), o requerido desde
  un endpoint de panel.
- `api/lib/Sales/verify_chain/run_sale_chain.php` — genera ventas REALES
  contra `SaleService::save()` (mismo camino que `/api/v1/sales.php`), sin
  mocks. Su propio docblock (líneas 10-13) documenta la restricción que
  define la arquitectura de este plan: *"data.php define COMPANY_ID/
  OUTLET_ID/USER_ID/etc. como constantes PHP — solo se pueden definir una
  vez por proceso"*. `TenantContext` (`api/lib/Context/TenantContext.php`)
  es el reemplazo moderno de esas constantes, pero el comentario de su
  propia clase confirma que **coexisten**: código legacy (`manageStock`,
  `sendAuditoria`) sigue leyendo las constantes globales, y bootstrap
  garantiza que ambos representen el mismo tenant. Mientras eso siga así,
  un proceso PHP solo puede sembrar UN tenant.
- `SaleInput` (`api/lib/Sales/SaleInput.php`) recibe `date` y `timestamp`
  explícitos — la venta se puede backdatear con su hora de emisión real
  (mismo mecanismo que el fix reciente de "la venta se guarda con su hora de
  emisión, no con la del container"). Esto es lo que permite distribuir
  ventas sembradas a lo largo del período pedido, no solo "hoy".

**Piezas que la operación real ya resuelve solas, sin que el seeder tenga que
tocarlas:**

- **Plan de cuentas**: `AccountService::list()` hace auto-seed de
  `fin_account` en el primer acceso del tenant (`ensureSeed()`, comentario
  propio: *"Auto-seed en el primer acceso (si el tenant no tiene ninguna
  cuenta todavía)"*). El seeder no necesita crear el plan de cuentas — alcanza
  con que algo dispare ese primer acceso (o llamarlo explícito una vez).
- **Rollups**: `rollup_dirty` + `rollup_reconcile()` (mig 41) ya corren cada
  10 minutos vía cron (`*/10 * * * * maintenance.sh rollup-reconcile`). Si la
  operación sembrada pasa por los servicios reales, ensucia `rollup_dirty`
  igual que una venta real y el job existente la recoge sola — el seeder no
  escribe rollups.
- **Turnos**: `DrawerService::open(amount, date, userId)` /
  `close(amount, date, userId, countedByMethod, closingTotals)` son el
  servicio real de apertura/cierre de caja — el seeder los puede llamar por
  día simulado para que el arqueo también tenga datos.

**Piezas que el seeder NO puede fabricar:**

- **Timbrado / punto de expedición**: `document_sequence` se crea vía
  `RegisterAdminService::seedSequence()` — una acción administrativa manual,
  atada a un número de autorización real del SET, con constraint de
  unicidad por timbrado (mig 143). No es dato que un seeder pueda inventar
  sin volverlo fiscalmente falso. Ver §Preguntas abiertas.

**Mecanismo para correr fuera del request** — el repo ya tiene dos piezas que,
combinadas, resuelven el problema, ninguna alcanza sola:

- `api/v1/maintenance.php` — jobs internos invocados por cron BusyBox
  (`api/docker/cron/crontab`) contra un secreto compartido
  (`EINVOICE_DRAIN_SECRET`, header `X-Maintenance-Secret`), con
  `pg_try_advisory_lock(hashtext('maintenance:'||job))` por job y `200
  {skipped:true}` si ya hay una corrida adentro. Corre DENTRO del mismo
  proceso PHP que atiende la request del cron — sirve para trabajo acotado
  (`rollup-reconcile` tiene `limit`/tope 5000), no para "sembrar miles de
  ventas", que es exactamente lo que este plan tiene que evitar meter en una
  request.
- `print_job` (mig 83) — precedente real de tabla-cola en este proyecto:
  `status` enum (`queued|printing|done|failed|cancelled`), `attempts`,
  `lastError`, timestamps. Un consumidor (hoy: el device de impresión vía
  WS) la va vaciando y actualiza el estado a medida que progresa.
  `api/lib/Sales/verify_chain/verify_register_lease.php:158` y
  `verify_register_release_on_change.php:162` además muestran que
  `proc_open()` para lanzar un script PHP hijo desde otro proceso PHP ya se
  usa en este repo, aunque hoy solo en arneses de verificación.

## Restricción técnica que define la arquitectura

El seeder NO puede correr inline dentro de la request HTTP del botón de
`/admin`: esa request ya resolvió su propio contexto de admin, y las
constantes de tenant (COMPANY_ID/OUTLET_ID/etc.) que el código legacy sigue
leyendo no se pueden redefinir a mitad de proceso para apuntar a la cuenta
destino. Sembrar un mes de operación son cientos o miles de ventas pasando
por el motor real — tampoco entra en el timeout de una request aunque el
problema de las constantes no existiera.

## Decisiones propuestas — falta OK del owner

### D2 [?] — Se siembra por los SERVICIOS REALES, nunca INSERT directo

Mismo fundamento que `finance_backfill.php` y `run_sale_chain.php`: los
reportes derivados (rollups, ledger de stock, ledger de finanzas) se
calculan desde la operación, no desde las tablas crudas. Insertar filas a
mano produce reportes que no cuadran entre sí — lo contrario de "info
completa de reportes". El seeder llama `SaleService::save()`,
`FinanceLedger::record*` (indirecto, vía los hooks que ya disparan las
ventas), `DrawerService::open/close`, y los CRUD reales de contactos/ítems.

### D3 [?] — Mecanismo de origen y reversión: dos opciones, sin resolver

Sin un botón que revierta, una cuenta demo se ensucia una sola vez y deja de
servir. Ni `transaction` ni `stock` tienen hoy una columna pensada para
"esto es sintético" (`stock.stockSource` existe pero es el TIPO de operación
—`adjustment`, `inventory_count`—, no un flag de origen sembrado;
`fin_movement.source` es igual de ambiguo para este uso). Dos caminos:

- **Opción A — marca explícita.** `transaction.meta` es JSONB ya existente
  (sin migración): taguear `meta->>'demoSeedJobId'`. Contactos e ítems
  tienen (por principio de diseño del schema, `context/04` #2) columnas
  JSONB de extensión (`config`/`data`/`meta`) donde cabría el mismo tag —
  falta confirmar el nombre exacto tabla por tabla al implementar. Reversión
  = filtrar por el tag y VOID/anular cada entidad por su servicio real (la
  anulación de venta ya existe, `context/40`), nunca DELETE crudo. Costo:
  toca varias tablas, cada una con su propio nombre de columna JSONB a
  confirmar.
- **Opción B — acotar por rango de fechas + companyId interno.** Como el
  seeder solo corre sobre cuentas `isinternal=1` y siempre en un período
  declarado, "revertir" es anular todo lo que cae en `[companyId,
  fechaDesde, fechaHasta]` vía los mismos servicios reales, sin columna
  nueva ni migración. Más simple, cero schema change. El riesgo: si alguien
  además usó esa cuenta demo a mano dentro del mismo rango, la reversión se
  lo lleva puesto — riesgo acotado porque son cuentas internas de demo, no
  clientes, pero no es cero.

No lo resuelvo acá — el trade-off es simplicidad+riesgo-acotado (B) vs.
precisión+costo-de-implementación (A). Recomiendo B como default y A si el
owner prefiere poder mezclar datos reales y sembrados en la misma cuenta
demo sin que la reversión los confunda.

### D4 [?] — Mecanismo de ejecución: cola + proceso CLI separado

Ni `maintenance.php` solo ni un script CLI solo alcanzan — se combinan:

1. Tabla nueva `demo_seed_job` (mismo molde de `print_job`: `status`
   `queued|running|done|failed`, `companyId`, `fromDate`/`toDate`,
   contadores de progreso, `lastError`, `createdBy`, timestamps).
2. El botón de `/admin` hace POST, valida `isinternal=1` (D1), inserta la
   fila en `queued` y responde con el `jobId` — la request termina en
   milisegundos, no espera nada.
3. Un job nuevo en `maintenance.php` (ej. `demo-seed-dispatch`, mismo cron
   BusyBox, cada 1-2 min) toma la fila más vieja en `queued` bajo el mismo
   patrón de advisory lock que ya usan los otros jobs, y en vez de sembrar
   INLINE, lanza `php api/database/seeds/demo_seed.php <companyId> <from>
   <to> <jobId>` como proceso hijo DETACHADO (`proc_open`/`shell_exec` con
   redirección a log y `&`, no `wait`) — precedente de `proc_open()` ya
   usado en `verify_chain`, aunque ahí es síncrono. El tick de
   `maintenance.php` solo marca `running` y vuelve.
4. El script CLI (proceso propio, un tenant, sin el problema de las
   constantes) hace el trabajo real: abre turno, siembra ventas
   distribuidas, cierra turno, por cada día del período — actualiza
   `demo_seed_job` con su progreso y termina en `done`/`failed`.
5. `/admin` hace polling de `GET` sobre `demo_seed_job` (mismo patrón que la
   UI de impresión consulta `print_job`).

Alternativa descartada: un cron dedicado nuevo que invoque el CLI
directamente sin pasar por `maintenance.php` — se rechaza porque duplica el
mecanismo de lock/secreto que `maintenance.php` ya centraliza, exactamente
el patrón de "dos copias del mismo guard" que `context/64` D3 ya identificó
como error a no repetir.

## Qué se siembra — capas por dependencia

1. **Sin dependencias** — catálogo (categorías/marcas/ítems), contactos
   (clientes, algún proveedor), plan de cuentas (se auto-siembra solo, ver
   arriba).
2. **Operación** — ventas distribuidas en el período vía `SaleService::save()`:
   mezcla de medios de pago, algunas a crédito, algunas devoluciones/NC
   (`context/40`), turnos abiertos/cerrados por día (`DrawerService`).
3. **Derivado, automático si (2) pasó por los servicios reales** — stock
   (`Inventory::manageStock` vía los hooks de venta), finanzas
   (`FinanceLedger`), rollups (`rollup_dirty` + cron existente).

## Preguntas abiertas para el owner

- **Timbrado faltante: ¿el seeder verifica y avisa, o lo crea?** Propongo
  que verifique y falle con mensaje claro — crear un timbrado es una acción
  fiscal con número de autorización real, no algo que un seeder deba
  fabricar. Si la cuenta interna destino no tiene punto de expedición
  configurado, hay que configurarlo a mano una vez (como cualquier caja
  real) antes de poder sembrar ventas ahí.
- **Realismo de la distribución**: picos por hora del día y por día de la
  semana, no uniforme — sin eso cualquier gráfico se ve sembrado. ¿Alcanza
  con una curva fija razonable (almuerzo/tarde, más volumen fin de semana),
  o el owner quiere parametrizar el perfil por tipo de comercio?
- **Volumen**: cuántas ventas/día por default, y si un período largo (ej. un
  año) trunca, samplea, o el trabajo simplemente tarda lo que tenga que
  tardar corriendo en background.
- **D3**: opción A o B (ver arriba).
- **Alcance de "algunos clientes"**: ¿cuántos contactos nuevos por corrida,
  o reusa los que ya tenga la cuenta?

## Arquitecturas rechazadas — no reintroducir

- **Sembrar con INSERT directo a las tablas crudas.** Produce reportes
  (rollups, ledger, stock) que no cuadran entre sí — el objetivo del pedido
  es exactamente lo contrario. Rechazado por D2.
- **Correr el seeder inline dentro de la request HTTP del botón.** Choca con
  la restricción de constantes por proceso (`run_sale_chain.php`) y con el
  timeout de una request para cientos o miles de ventas.
- **Un cron dedicado nuevo, separado de `maintenance.php`.** Duplicaría el
  lock/secreto que ya existe centralizado — mismo error que `context/64` D3
  ya marcó como "no reintroducir" para el guard de auth.
- **Que el seeder cree o mueva un timbrado/`document_sequence`.** Es una
  acción fiscal con número de autorización real; fabricarlo rompe la
  premisa de `context/29` de que el punto de expedición es un dato real, no
  sintético.
- **Permitir el seeder sobre cualquier cuenta con un flag de override.**
  Rechazado en D1 sin excepción — el candado es `isinternal=1`, server-side,
  sin bypass.

## Docs relacionados

- `context/34-admin-saas-plan.md` — panel `/admin`, de donde sale el botón y
  el patrón de servicios `Admin/*` que ya usan `notInternalWhere()`.
- `context/29-numeracion-y-exclusividad-de-caja.md` — por qué el timbrado no
  se puede fabricar.
- `context/40-anulacion-y-nota-credito.md` — el servicio real que la
  reversión (D3) y las devoluciones sembradas (capa 2) tienen que usar.
- `context/52-stock-ledger-unica-fuente.md` — por qué el stock sembrado tiene
  que pasar por `manageStock()` y no por UPDATE directo.
- `context/64-mcp-admin-saas.md` — precedente del error "guard duplicado" que
  D4 evita repetir con el mecanismo de cola.
