# 77 — Migrador ENCOM → Punto

> Estado: **F1 IMPLEMENTADA** 2026-09-11 (branch `api/migrador-encom`).
> D1-D6 cerradas por el owner el mismo día, no relitigar.
> F2 (ventas históricas) **no** está implementada — ver §8.

## 1. Qué resuelve

El equipo de soporte carga en `/admin` las credenciales de un cliente en el
panel legacy (ENCOM) y un job exporta sus datos y los importa a una empresa de
Punto **ya creada**. El migrador importa datos; no crea cuentas.

Alcance de F1: catálogo (categorías, marcas, etiquetas, artículos), clientes,
y configuración (sucursales + cajas **con su numeración fiscal**).

## 2. Decisiones cerradas por el owner (2026-09-11)

| # | Decisión |
|---|---|
| **D1** | Superficie `/admin`, sección **Migraciones**: form de credenciales + empresa destino + checkboxes de qué migrar, y listado de jobs con estado y log por dominio. |
| **D2** | **La password NO se persiste.** El endpoint hace el login al legacy en el momento, guarda SOLO las cookies efímeras en el job y descarta la password. Si el login falla, el job no se crea. |
| **D3** | Ejecución por tabla-cola `migration_job` + **worker CLI aparte**, disparado desde el drain de `api/v1/maintenance.php`. |
| **D4** | Import por los **servicios reales** de Punto, nunca INSERT directo salvo la tabla de mapeo propia. `migration_map` da idempotencia. |
| **D5** | Sucursales y cajas se importan **con timbrado, punto de expedición y numeración actual**; la caja de Punto CONTINÚA la serie desde el último número + 1. **Validación dura**: dos cajas con el mismo punto de expedición bajo el mismo timbrado = error del job, no se importa a medias. La FE no se configura (manual después). Usuarios/staff NO se migran en F1. |
| **D6** | El job registra en `tenant_audit` del destino qué importó. |

## 3. Arquitectura

```
/admin  ──POST /v1/admin/migrations.php──▶  login al legacy (AHORA)
                                            └─ guarda cookies en migration_job (pending)

cron 2 min ──POST /v1/maintenance?job=migration-drain──▶ EncomMigrationService::drain()
                                            ├─ reencola jobs 'running' colgados
                                            └─ claimNext() + spawn del worker

php api/scripts/migration_worker.php <jobId> <companyId>
        ├─ EncomClient::fromCookies()   → export paceado del legacy
        ├─ EncomImportService::run()    → import por los servicios reales
        └─ finish()  → estado + progreso + BORRA las cookies
```

| Pieza | Archivo |
|---|---|
| Schema (cola + mapeo) | `api/database/migrations/postgres/218_migracion_encom.sql` |
| Contrato de la fuente | `api/lib/Admin/EncomSource.php` |
| Cliente HTTP del legacy | `api/lib/Admin/EncomClient.php` |
| Ciclo de vida del job + drain | `api/lib/Admin/EncomMigrationService.php` |
| Import por dominio | `api/lib/Admin/EncomImportService.php` |
| Endpoint realm admin | `api/v1/admin/migrations.php` |
| Worker | `api/scripts/migration_worker.php` |
| UI | `frontend/app/(admin)/admin/migrations/page.tsx` + `components/admin/migration-*.tsx` |
| Arnés | `api/tests/run_encom_migration_test.sh` (28 checks) |

### 3.1 Por qué cola + proceso aparte (D3), con la razón corregida

El plan original justificaba D3 con "los servicios usan constantes de proceso
único". **Verificado: los servicios de import reciben `$companyId` por
argumento** (`ItemService`, `ContactService`, `CategoryService`,
`OutletsService`, `RegisterAdminService`) — mejor de lo que el plan suponía.

Lo que SÍ es por proceso es el **contexto global**: `api/data.php` define
`COMPANY_ID` / `OUTLET_ID` / `TODAY` con `define()`, una vez por proceso. Y la
razón que sola alcanza para descartar el inline: **el export son decenas de
requests paceadas a 60 req/min**, o sea minutos de pared, muy por encima de
cualquier timeout de PHP-FPM.

El worker NO pasa por `data.php`: define las constantes a mano (patrón de los
arneses de `api/tests/`). `data.php` además exige outlet/register/user activos,
que una empresa recién creada —el caso normal de un destino— no tiene.

## 4. Mapa del legacy — CORRECCIONES verificadas

El mapa con el que arrancó la tarea tenía cuatro errores que cambian el diseño.
Todo esto se leyó del código del snapshot legacy, no se asumió:

1. **`get_registers.php` NO EXISTE.** Las cajas salen anidadas en
   `API/get_company.php` (`outlets[].registers[]`), que es además la única
   fuente que las trae **con su sucursal**. La otra opción,
   `a_registers.php?list=true`, devuelve **HTML** y corre acotado a la
   **sucursal activa** de la sesión, sin endpoint para cambiarla: habría
   migrado las cajas de una sola sucursal y sin saber de cuál.
2. **El punto de expedición YA viene como `EEE-PPP`** en
   `registerInvoicePrefix` — el propio legacy hace
   `explode("-", registerInvoicePrefix)` para mandar establecimiento y punto a
   la SET (`API/send_fe_invoices.php:54-59`). El campo `sufix` es OTRA cosa y
   **no** entra en el punto de expedición.
3. **`get_tags.php` no usa envelope** `{ok,data}`: devuelve un objeto indexado
   por id, e **inyecta por código** un tag que no existe en la tabla (id
   `166227`, "INTERNO"). Se filtra: migrarlo crearía una etiqueta que el
   cliente nunca creó.
4. **Solo `get_items.php` pagina.** Categorías (LIMIT 500), clientes (LIMIT
   1000), marcas y bancos (LIMIT 100) tienen el tope cableado en la SQL e
   **ignoran `offset`**. Pedir una segunda página devolvería la primera para
   siempre.

Además: `get_customers.php` **no acepta** el parámetro `type` (está cableado a
`type = 1`), y la fuente del nombre del comercio en Punto es
`company.config->>'settingName'`, no una columna.

### 4.1 Shapes que se consumen

| Fuente | Campos usados |
|---|---|
| `get_categories.php` | `ID`, `name`, `pos` |
| `get_brands.php` | `ID`, `name` |
| `get_tags.php` | objeto `{id: {name}}` |
| `get_items.php` | `ID`, `name`, `sku`, `price`, `cost`*, `discount`, `categoryID`, `brandID`, `status`, `type`, `UOM`, `description` |
| `get_customers.php` | `id`/`UID`, `name`, `tin`, `CI`, `phone`, `address`, `email`, `note` |
| `get_company.php` | `outlets[]`: `ID`, `name`, `address`, `phone`, `email`, `billingName`, `tin`, `description`, `lat`, `lng` |
| `get_company.php` | `registers[]`: `ID`, `name`, `invoiceAuth`, `invoiceAuthExp`, `prefix`, `sufix`, `invoiceNo`, `docsZeros` |

\* `cost` y `stock` son claves **condicionales**: solo vienen si el artículo
rastrea inventario. `null` ≠ 0.

## 5. La numeración fiscal (D5) — el corazón

El contador del legacy (`registerInvoiceNumber`) guarda el **último** número
emitido. `document_sequence.nextnumber` de Punto guarda el **próximo** (mig
117). Esa asimetría **es** el `+1`, no un margen de seguridad.

La caja se crea con `RegisterAdminService::create($outletId, $name, $extra)`
pasando `fiscal` (timbrado + `EEE-PPP`), `numbering.factura` (último + 1) y
`padWidth.factura` (de `docsZeros`). Ese servicio ya abre la serie correcta:
desde la mig 209 la identidad de `document_sequence` es
`(companyid, doctype, scopetype, scopeid, invoiceauth, prefix)`.

**No se reimplementa nada de la numeración** — se usa el mismo camino que el
alta de una caja desde el panel, que es el que aplica el invariante.

### 5.1 El rechazo es duro y previo

Dos cajas con el mismo `(timbrado, EEE-PPP)` llevarían la misma secuencia y
terminarían emitiendo dos facturas con el mismo número: documento duplicado,
ilegal ante la SET (`context/29` §2, multa por cada factura).

`EncomImportService::assertExpeditionPointsFree()` valida **todo el lote antes
de crear la primera caja** y aborta el dominio entero. `RegisterAdminService`
tiene su propio guard y la mig 143 su índice único; esto **no los reemplaza**,
se adelanta para poder fallar **sin efectos parciales**. Importar "hasta donde
se pudo" dejaría al comercio con la mitad de sus cajas fiscales y sin señal de
cuáles faltan.

También se rechaza un `prefix` que no cumpla `^\d{3}-\d{3}$`: no se fabrica un
punto de expedición a partir de datos incompletos.

## 6. Idempotencia

`migration_map(companyid, domain, legacyid) → puntoid`, con esa PK. Antes de
crear cualquier entidad se pregunta si ese id del legacy ya tiene id de Punto.
Re-correr da los mismos conteos con todo en `skipped`, y **no vuelve a mover la
numeración fiscal** (verificado en el arnés, caso B5).

`remember()` usa `ON CONFLICT DO NOTHING`, no `DO UPDATE`: si la clave ya
existe, el id válido es el **primero** — es al que pueden estar apuntando los
ítems ya importados.

Un solo job vivo por empresa, con índice único parcial en la base: dos jobs
concurrentes se pisan porque los dos leen el mapa antes de que el otro escriba.
La idempotencia protege el **re-correr**, no el correr en paralelo.

## 7. Detalles que muerden

- **La caja placeholder se reusa.** `OutletsService::create()` deja una caja
  "Nueva Caja" sin timbrado para cumplir el invariante "sucursal sin caja no
  existe". El importador la reusa para la primera caja de esa sucursal; si no,
  cada sucursal migrada quedaría con una caja fantasma que borrar a mano.
- **Sucursal de una caja: nunca se adivina.** Sale del mapa; si no está, se usa
  la sucursal de respaldo que el operador eligió, y sin ninguna de las dos la
  caja **no se importa** (memoria: prohibido resolver una dimensión faltante
  con "el primer outlet activo").
- **La categoría vive en dos lados**: la FK legacy `item.categoryId` y la m2m
  `item_category`. Se escriben las dos — escribir una sola deja el artículo sin
  categoría en la mitad de las pantallas (la trampa que ya pisó la mig 136,
  `context/41`).
- **El alta de un artículo es atómica.** `createBlank()` + `update()` van en
  una transacción: sin eso, una fila mala dejaba un "Nuevo Artículo" huérfano
  en el catálogo del cliente. Lo encontró el arnés.
- **`ItemImporter::legacyFlagsForKind()` pasó a `public static`** y el migrador
  la usa. Copiar el mapa kind→flags habría dejado dos tablas que se
  desincronizan en cuanto se agregue un kind.
- **Un dominio que falla no frena a los otros**, y un job con errores queda
  `failed` aunque parte haya entrado: `done` con errores es un verde que nadie
  vuelve a mirar.
- **Las cookies se borran al terminar el job**, salga bien o mal, y nunca se
  devuelven por la API (el servicio expone solo `hasCredentials`).

## 8. Qué queda para F2 — NO implementado

- **Ventas históricas.** Fuera de alcance explícito de F1. Lo que hay que
  resolver antes de construirlo: una venta importada NO puede pasar por
  `SaleService::save()` (asignaría numeración nueva y movería stock y caja);
  tiene que entrar como documento ya emitido, con su número congelado, y sin
  tocar `document_sequence` — que es justo lo que el import de cajas deja
  posicionado. Tampoco puede reabrir un período cerrado (`context/48`).
- **Stock inicial.** Deliberadamente fuera: un saldo es un movimiento del
  ledger con costo y sucursal (`context/52`), y el legacy solo expone un número
  suelto. Se carga con un conteo en la sucursal.
- **Usuarios y staff** (D5 los excluye de F1). `get_users.php` tiene además dos
  claves `TIN`/`tin` con valores distintos (una lee `lockpass`) — mapear eso a
  ciegas es cómo se importa una contraseña en el campo del RUC.
- **Compras, proveedores, cuentas/medios de pago.** `get_banks.php` ya se
  expone en `EncomSource` pero no se importa: el plan de cuentas de Punto no es
  el del legacy y el mapeo no está decidido.
- **Configuración de la empresa** (moneda, decimales, `taxName`). Se exporta
  (`settings()`) pero no se escribe: pisar la config de una empresa que ya
  operó es destructivo y el owner no lo pidió.

## 9. Lo que hay que hacer antes de mergear

1. **Cargar `ENCOM_MIGRATION_URL`** en Coolify (backend). Sin eso el endpoint
   responde 503 y la UI muestra el aviso con el botón bloqueado. No se cablea
   en código (regla: ningún dominio vive en el código).
2. **Validar los shapes contra el sistema vivo.** El export se leyó del código
   del snapshot, no de una respuesta real. Lo que conviene confirmar con una
   corrida contra un cliente de prueba está en §4 y en el reporte de la
   sesión — en especial que `get_company.php` devuelva `registers[]` poblado
   con `invoiceAuth`/`prefix`/`invoiceNo` (el snapshot los lee de `SELECT *`
   sin `_flattenJsonb`, y el mapa de schema del propio legacy dice que esos
   campos fueron demoted al JSONB `data` en su "Migración 26").

## 10. Arquitecturas rechazadas — no reintroducir

| Arquitectura | Por qué se rechazó |
|---|---|
| **Parsear `a_registers.php?list=true`** para leer las cajas | Devuelve HTML y está acotado a la sucursal ACTIVA de la sesión, sin forma de cambiarla. Migraría las cajas de una sola sucursal, sin saber cuál, y leyendo un TIMBRADO de una celda `<td>`. `get_company.php` da lo mismo en JSON y con la sucursal. |
| **Componer el punto de expedición como `prefix + "-" + sufix`** | `registerInvoicePrefix` YA es `EEE-PPP`; el legacy le hace `explode("-")` para SIFEN. `sufix` es otro campo. Componerlo generaría un punto de expedición inventado sobre un dato fiscal. |
| **Importar las cajas "hasta donde se pueda"** ante un choque de punto de expedición | Deja al comercio con la mitad de sus cajas fiscales y sin señal de cuáles faltan. D5 pide fallo duro del dominio. |
| **Guardar la password del cliente** para poder reintentar el job | D2. El reintento se resuelve creando el job de nuevo (el login son 3 campos); guardar la credencial de un tercero para ahorrar eso no se paga. |
| **INSERT directo del catálogo** para ir más rápido | D4. Los servicios son los que aplican los invariantes: saltearlos es exactamente cómo entran dos cajas con el mismo punto de expedición, un contacto con teléfono duplicado o un ítem sin fila en `item_outlet`. |
| **Correr el import inline en la request de `/admin`** | Minutos de export paceado a 60 req/min, más el contexto de tenant que se fija una vez por proceso. Ver §3.1. |
