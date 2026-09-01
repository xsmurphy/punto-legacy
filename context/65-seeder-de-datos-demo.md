# 65 — Seeder de datos demo

> Estado: **PLAN, sin implementar.** Fecha 2026-09-01, corregido el mismo día.
> D1 cerrada por el owner, no relitigar. El resto (D2-D4 + F0) son PROPUESTAS
> mías y necesitan su OK — marcadas **[?]**.
>
> **Corrección 2026-09-01**: la versión original de este doc asumía que había
> que sembrar pasando por los servicios reales (`SaleService::save()`, etc.)
> para que los reportes "cuadraran". El owner corrigió la premisa: *"no es
> necesario que los datos cuadren, es más que nada para ver reportes
> completos"*. Eso cambia el modelo de punta a punta — ver §El criterio.
> Los precedentes `finance_backfill.php` / `run_sale_chain.php` y la
> arquitectura de cola que motivaban quedaron en §Arquitecturas rechazadas.

## Qué pidió el owner

Un botón en `/admin` que siembre datos en una cuenta, por un período
seleccionable. Textual: *"no solo ventas, también algunos clientes, plan de
cuentas etc. más que nada para tener info completa de reportes"*, y sobre la
fidelidad de esos datos: *"no es necesario que los datos cuadren, es más que
nada para ver reportes completos, balance, flujo de caja, ventas, gastos,
gráficos, evolución de clientes, etc."*

## El criterio: ninguna pantalla vacía, no datos que cuadren

El eje del plan no es "que los datos sean consistentes entre sí" — es **que
ninguna pantalla de reporte quede sin nada que dibujar**. Son objetivos
distintos y llevan a arquitecturas distintas:

- Un reporte **descuadrado** (el stock no coincide con las ventas, el ledger
  de finanzas no concilia con la caja) es ACEPTABLE — el owner lo aceptó
  explícitamente.
- Un reporte **VACÍO** no cumple el objetivo. Y es exactamente lo que pasa si
  el seeder solo llena la tabla "obvia" (`transaction`/`itemsold`).

Ejemplo verificado: **balance y flujo de caja no leen ventas.**
`BalanceService` lee `fin_account` (`api/lib/Reports/BalanceService.php:102`)
y `CashflowService` lee `fin_movement` + `fin_account`
(`api/lib/Reports/CashflowService.php:120-121,171`). Si el seeder inserta
ventas en `transaction`/`itemsold` y nada más, las dos pantallas que el owner
nombró primero quedan en cero pese a que "las ventas" sí se sembraron.

Consecuencia directa: **hay que sembrar cada FUENTE que cada reporte
consulta**, no la operación que en producción las alimenta de rebote. Eso es
D2 (abajo) y el trabajo de F0.

## Decisión cerrada por el owner (2026-09-01)

**D1 — El seeder SOLO puede apuntar a cuentas internas (`company.isinternal`),
verificado en el BACKEND.** El owner: *"solo cuentas internas que sirven para
demo"*. La marca ya existe y ya es el candado que separa a los clientes reales
del tenant emisor de facturación SaaS — `AdminReportsService::notInternalWhere()`
la usa en cada agregado (`isinternal IS NULL OR isinternal = 0`, mig 114) y el
listado de `/admin/companies` ya la muestra (`page.tsx:233`).

El endpoint del seeder hace la verificación inversa —`isinternal = 1`,
explícito, server-side— y **rechaza cualquier otro destino, sin excepción ni
flag de override.** Con INSERT directo este candado importa MÁS que antes, no
menos: no hay ningún `Service` en el medio que valide nada (permisos, dueño
del recurso, límites) — el endpoint del seeder es la única barrera entre "yo
mandé un `companyId`" y filas escritas en cualquier tabla del sistema. Un
clic equivocado sobre un tenant real no es "datos de prueba molestos", es
ventas falsas en la contabilidad de un cliente y stock alterado de verdad.

## Decisiones propuestas — falta OK del owner

### D2 [?] — INSERT directo a las tablas que cada reporte lee, sin pasar por los servicios de operación

Se abandona el modelo de `finance_backfill.php`/`run_sale_chain.php` (sembrar
vía los `Service` reales). El seeder inserta filas directamente en
`transaction`, `itemsold`, `fin_movement`, `fin_account`, `stock`, contactos,
etc. — las tablas fuente de cada pantalla, identificadas en F0. Sin pasar por
`SaleService::save()`, `FinanceLedger::record*`, `DrawerService`, ni CRUD de
contactos/ítems.

**Verificado — no hace falta preservar consistencia de bajo nivel:**

- El wrapper de DB (`Query::insert()` en
  `api/lib/App/Database/Query.php:277-282` → `ncmInsert()` en
  `api/includes/functions.php:1560-1606` → `DB::AutoExecute()` en
  `api/includes/lib/DB.php:518-577`) no lee `COMPANY_ID`/`OUTLET_ID`/
  `USER_ID` en ningún punto — esas constantes solo las usa la capa de
  `Service`/`Domain`, nunca el wrapper de bajo nivel.
- Dos triggers de negocio sí corren sobre INSERT plano y hay que respetarlos:
  `trg_transaction_registry_sync_insert` (AFTER INSERT ON `transaction`,
  sincroniza `transaction_registry` desde `NEW.*`) y
  `trg_itemsold_backfill_dims` (BEFORE INSERT ON `itemsold`, completa
  `companyid`/`outletid` desde `transaction_registry` si vienen NULL —
  falla si el `transactionid` no existe ahí). Consecuencia práctica: insertar
  primero la fila en `transaction` (puebla `transaction_registry` solo) antes
  que sus `itemsold`, o setear `companyid`/`outletid` explícitos en cada fila
  de `itemsold`. `fin_movement`/`fin_account`/`stock` no tienen triggers de
  negocio en el alta (mig 156).

### F0 [?] — Inventario de fuentes por pantalla (primera fase del plan)

Antes de escribir el seeder hay que relevar, para cada pantalla de reportes
que el owner nombró, cuál es su fuente real — no adivinarlo:

| Pantalla | Fuente | Estado |
|---|---|---|
| Balance | `fin_account` | Verificado (`BalanceService.php:102`) |
| Flujo de caja | `fin_movement` + `fin_account` | Verificado (`CashflowService.php:120-121,171`) |
| Ventas, gastos, gráficos, evolución de clientes, ranking de productos | — | **Pendiente — F0 releva cada una antes de implementar** |

Por cada fuente confirmada, F0 también anota (bundled, mismo pase): si la
tabla tiene columna JSONB de extensión disponible para taguear origen (ver
D3) y si algún reporte lee de una tabla de rollup en vez de la tabla viva
(en ese caso el seeder tiene que decidir si inserta directo en el rollup o
ensucia `rollup_dirty` para que el cron existente lo recalcule — mig 41,
`*/10 * * * * maintenance.sh rollup-reconcile`).

Nota aparte, no bloqueante: `AccountService::ensureSeed()` ya auto-siembra
`fin_account` en el primer acceso del tenant — el seeder puede confiar en eso
o insertar el plan de cuentas directo, es indistinto.

### D3 [?] — Origen y reversión: tag al insertar, reversión por DELETE directo

Con INSERT directo el seeder escribe cada columna de cada fila que crea, así
que taguear el origen es gratis — no hace falta razonar en qué momento
"marcar" algo que ya se sembró por otro camino. Se etiqueta
`meta->>'demoSeedJobId'` (JSONB ya existente, sin migración) en cada tabla
que F0 identifique como escrita, confirmando el nombre de la columna de
extensión tabla por tabla (`config`/`data`/`meta`, `context/04` #2).

Esto también cambia la reversión. La versión original del doc reservaba
"anular por el servicio real" (`context/40`) para deshacer lo sembrado,
porque ahí sí se habían creado documentos fiscales de verdad. Con INSERT
directo no hay documento fiscal ni correlativo consumido — **revertir es un
DELETE scoped por el tag**, sin pasar por ningún servicio de anulación. Es
seguro porque no queda ningún estado externo (SIFEN, numeración) que un
DELETE deje huérfano.

Con eso, la opción de "acotar por rango de fechas + companyId" (sin tag,
sin migración) sigue siendo válida como fallback más simple, pero el tag
explícito ya no tiene el costo que tenía antes (before: "toca varias tablas,
each con su propio nombre a confirmar" — ahora esa confirmación es parte del
mismo pase de F0). Recomiendo el tag como default.

### D4 [?] — Mecanismo de ejecución: un endpoint, sin cola ni proceso detachado

La arquitectura de cola + `maintenance.php` + proceso CLI detachado del doc
original resolvía dos problemas que **ya no existen** con INSERT directo:

1. **Las constantes PHP no aplican.** `COMPANY_ID`/`OUTLET_ID`/etc. se
   definen en `api/data.php:16-19` (`define()`, no reasignable) pero solo se
   cargan dentro de `apiAuthTenant()` (`api/bootstrap.php:271`) — un script
   que no pasa por ahí nunca las define, y el wrapper de DB no las necesita
   para hacer un INSERT (ver D2). Un mismo proceso puede sembrar cualquier
   `companyId`, o varios, sin restricción.
2. **El volumen no es un problema de tiempo.** Miles de INSERT en lote (con
   `Query::insert()` o `INSERT ... VALUES` multi-fila) son segundos, no
   minutos — no hay riesgo real de timeout de request para sembrar un mes o
   un año de datos.

Con esas dos restricciones caídas, alcanza con **un endpoint de `/admin`**
que valide `isinternal=1` (D1) y haga el trabajo síncrono, dentro de la
misma request, envuelto en una transacción por `companyId`. Si en la
práctica algún período extremo (varios años, volumen muy alto) se acerca al
timeout del server, la salida es un job simple invocado una vez —no una cola
con polling— nunca la arquitectura de `demo_seed_job` + cron + `proc_open`
detachado que planteaba la versión anterior.

## Qué se siembra — capas

1. **F0 primero**: inventario de fuente por pantalla (arriba).
2. **INSERT directo** en cada fuente identificada, respetando el orden
   `transaction` → `itemsold` (o `companyid`/`outletid` explícitos) del
   trigger de sync (D2), con `demoSeedJobId` taggeado (D3).
3. **Rollups**: si F0 encuentra que algún reporte lee de una tabla de
   rollup, el seeder inserta ahí también o ensucia `rollup_dirty` — a
   decidir por pantalla, no hay default único.

Número de documento en las ventas sembradas: si se quiere que las filas
tengan un número (para que se vea como una venta real en el detalle), se les
pone uno inventado sin tocar `document_sequence` ni el correlativo real —
no hace falta timbrado vigente para insertar una fila.

## Preguntas abiertas para el owner

- **Realismo de la distribución**: picos por hora del día y por día de la
  semana, no uniforme — sin eso cualquier gráfico se ve sembrado. Con fechas
  puestas directo por el seeder (sin pasar por un servicio que resuelva "la
  hora actual"), esto es más simple que antes. ¿Alcanza con una curva fija
  razonable (almuerzo/tarde, más volumen fin de semana), o el owner quiere
  parametrizar el perfil por tipo de comercio?
- **Volumen**: cuántas filas/día por default, y si un período largo (ej. un
  año) trunca, samplea, o simplemente inserta todo (ya no es un problema de
  tiempo, D4).
- **D3**: ¿confirma el tag (`meta->>'demoSeedJobId'`) como mecanismo de
  reversión, o prefiere el fallback por rango+companyId sin migración?
- **Alcance de "algunos clientes"**: ¿cuántos contactos nuevos por corrida,
  o reusa los que ya tenga la cuenta?

## Arquitecturas rechazadas — no reintroducir

- **Sembrar pasando por los servicios reales
  (`SaleService::save()`/`FinanceLedger`/`DrawerService`).** Era el modelo
  original de este doc, invalidado por el owner: el objetivo no es
  consistencia contable, es que ninguna pantalla quede vacía. Pasar por los
  servicios además reintroduce la restricción de constantes PHP y el límite
  de timeout que D4 elimina. Rechazado por D2.
- **Cola (`demo_seed_job`) + job en `maintenance.php` + proceso CLI
  detachado (`proc_open`).** Resolvía dos restricciones que resultaron
  falsas para INSERT directo: las constantes PHP por proceso (no se leen en
  el wrapper de DB) y el timeout por volumen (miles de INSERT son segundos).
  Sigue siendo un patrón válido en general (`print_job` es buen precedente),
  pero es sobre-ingeniería para este caso. Rechazado por D4.
- **Correr el seeder inline dentro de la request HTTP del botón.** Esto en
  realidad ahora SÍ es la propuesta (D4) — este ítem queda solo para dejar
  registro de que la versión original lo rechazaba por las dos razones de
  arriba, ambas caídas.
- **Que el seeder cree o mueva un timbrado/`document_sequence` real.** Sigue
  rechazado, aunque ya no aplica por otra vía: al no pasar por
  `SaleService`, el seeder nunca necesita `RegisterAdminService::seedSequence()`
  ni un número de autorización real — si hace falta un número de documento
  visible, se inventa uno suelto (ver §Qué se siembra) sin tocar el
  correlativo fiscal (`context/29`).
- **Permitir el seeder sobre cualquier cuenta con un flag de override.**
  Rechazado en D1 sin excepción — el candado es `isinternal=1`, server-side,
  sin bypass. Con INSERT directo (sin ningún `Service` que valide nada en el
  medio) este candado es más crítico que antes, no menos.

## Docs relacionados

- `context/34-admin-saas-plan.md` — panel `/admin`, de donde sale el botón y
  el patrón de servicios `Admin/*` que ya usan `notInternalWhere()`.
- `context/29-numeracion-y-exclusividad-de-caja.md` — por qué el timbrado
  real nunca se fabrica, aunque este plan ya no lo necesite para operar.
- `context/48-escalamiento-de-datos.md` — rollups y su grano, relevante para
  F0 si algún reporte lee de ahí en vez de la tabla viva.
