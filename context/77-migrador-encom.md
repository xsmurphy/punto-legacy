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
| Parsers (CSV / tabla HTML / form) | `api/lib/Admin/EncomParse.php` |
| Ciclo de vida del job + drain | `api/lib/Admin/EncomMigrationService.php` |
| Import por dominio | `api/lib/Admin/EncomImportService.php` |
| Endpoint realm admin | `api/v1/admin/migrations.php` |
| Worker | `api/scripts/migration_worker.php` |
| UI | `frontend/app/(admin)/admin/migrations/page.tsx` + `components/admin/migration-*.tsx` |
| Arnés | `api/tests/run_encom_migration_test.sh` (49 checks) |

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

## 4. El sistema VIVO no tiene `/API` ni `/bff`

> **Esto invalida el mapa con el que arrancó la tarea.** Verificado contra
> `panel.encom.com.py` con una sesión real el 2026-09-11: **el deploy vivo es
> MÁS VIEJO que el código del snapshot.** Cualquier plan que asuma la
> superficie JSON está mirando código que no está desplegado.

| Superficie | Sistema vivo |
|---|---|
| `/bff/*.php` | **404**, no existe |
| `/API/get_*.php` | **`{"error":"Acceso denegado"}`** incluso con sesión de panel válida (usa el auth viejo por api_key, que no tenemos) |
| `a_*.php?action=…` con cookie **PHPSESSID** | **Funciona** — es la única superficie disponible |

O sea: el export sale de las mismas PANTALLAS que ve el cliente. De ahí que el
cliente parsee CSV y HTML en vez de consumir JSON; no es una preferencia.

**La prueba más clara de la divergencia** está en el CSV de contactos: el vivo
devuelve 10 columnas **con** `TELEFONO 2`, y el snapshot tiene 9 porque su
"Migración 25" eliminó esa columna. Por eso `EncomParse::csvRows()` indexa
**por nombre de columna, nunca por posición** — un parser posicional lee el
email en la columna del teléfono en una de las dos versiones y no se entera.

Y el encabezado del documento fiscal es la constante `TIN_NAME`, que sale de
`settingTIN` del comercio: dice "RUC" en Paraguay y otra cosa en otro país.
Se resuelve por alias y, si ninguno matchea, por su posición conocida.

### 4.1 Fuente por dominio

| Dominio | Fuente | Forma |
|---|---|---|
| Clientes | `a_contacts?action=download` | CSV (coma, comillas, saltos `\r`) |
| Artículos | `a_items?action=showTable&format=json` | JSON; **fallback** a la tabla HTML |
| Categorías / marcas | derivadas de los artículos | el export los trae por NOMBRE |
| Sucursales | `a_outlets?showTable=true` + `?action=edit&id=` | `{"table": "<html>"}` **con** wrapper + form |
| Cajas | `a_registers?list=true` + `?action=edit&id=` | HTML **sin wrapper** + form |
| Empresa | `a_settings` (página entera) | form HTML |
| Ventas (F2) | `a_report_transactions?action=detailTable` + `?action=edit&id=` | HTML |

Notas que gobiernan el diseño:

- **El valor crudo NO es el texto visible.** Viaja en `data-order` (tablas de
  contactos/transacciones) o en `data-sort` (tabla de artículos); el texto
  está formateado para mirar (`1.250.000`, `12 ene`). El parser prueba
  `data-order` → `data-sort` → `data-filter` → texto, en ese orden.
- **Las columnas se resuelven por ENCABEZADO, no por índice.** El listado vivo
  de cajas tiene una columna `Sucursal` que el snapshot no tiene: por índice
  fijo, el nombre de la sucursal se lee como TIMBRADO y todo lo de la derecha
  queda corrido uno. Es el mismo defecto que el CSV posicional, y la misma
  solución (`EncomParse::columnIndex()`).
- **El punto de expedición viene con GUIÓN FINAL** (`009-001-`) y el número con
  ceros (`0006848`). Punto valida `^\d{3}-\d{3}$`, así que sin normalizar el
  prefijo TODAS las cajas serían rechazadas por formato. Los ceros sí sirven:
  de esa cadena salen el correlativo y el ancho de impresión.
- **El id de la fila tampoco está siempre en el mismo atributo**: cajas,
  sucursales y transacciones usan `data-id`, pero la tabla de ARTÍCULOS usa
  `id` a secas. Mirar solo `data-id` saltearía en silencio todo el catálogo.
- **`a_contacts?action=download` NO filtra por tipo**: trae clientes,
  proveedores y el personal del comercio en el mismo CSV. Los separa la
  columna `ROL` (`Cliente` / `Proveedor` / nombre del rol). F1 importa solo
  `Cliente`.
- **El CSV de contactos no trae id**, así que la idempotencia usa clave
  natural: el documento fiscal si está, si no el nombre normalizado
  (`EncomImportService::customerKey()`). Dos clientes homónimos sin documento
  se fusionan — costo conocido de no tener id, y el lado seguro.
- **`a_items?action=exportCSV` se descartó**: exige `ids` (no tiene "todos") y
  su header declara 18 columnas mientras las filas traen 7 claves con otros
  nombres. Está desalineado en el propio código.

### 4.2 Las cajas: el switch de sucursal `?o=`

`a_registers?list=true` corre `... WHERE <roc>`, donde `<roc>` es `getROC(1)` =
"empresa + la sucursal **ACTIVA de la sesión**", y ese archivo **no lee ningún
parámetro de sucursal del request**. Por sí solo solo ve una sucursal.

La salida es un switch **global** que procesa `includes/functions.php` en
cualquier página del panel: **`?o=<outletId>`** escribe la sucursal activa en
la sesión. Dos detalles obligan a hacerlo en DOS requests:

1. el switch responde `header('location: …')` **sin el query string**, así que
   `?o=X&list=true` perdería el `list`;
2. la constante `OUTLET_ID` se define **antes** de que el switch corra, así que
   el cambio recién se ve en el request **siguiente**.

Entonces, por cada sucursal: un GET que cambia la sucursal activa (se ignora el
cuerpo, y contesta **302 a propósito** — el cliente lo tolera solo en esa
llamada) y otro que pide el listado. `a_outlets?showTable=true` sí trae TODAS
las sucursales (filtra solo por empresa), así que es el punto de entrada.

El listado de cajas no trae el vencimiento del timbrado ni la numeración
máxima: eso solo está en `?action=edit&id=`, un request más por caja.

### 4.3 Fragilidad asumida, y qué la contiene

Esto es scraping de pantallas: si el legacy cambia el orden de una columna o
el `name` de un input, el export se rompe. Lo que lo contiene:

- **Los `name` de los inputs son estables** y es de ahí que salen los datos
  FISCALES (timbrado, punto, número, dígitos, vencimiento) — no de posiciones.
  `EncomParse::formValues()` indexa por `name`.
- **El CSV se indexa por nombre de columna.**
- Lo único posicional que queda es el orden de columnas de las dos tablas
  (cajas y artículos), y está cubierto por el arnés con fragmentos copiados
  del sistema vivo.
- **Nunca se interpreta un id**: se devuelve opaco tal como vino (`data-id` →
  `?o=` / `?id=`). En el snapshot `enc()`/`dec()` son no-ops, pero en el deploy
  viejo podrían no serlo, y así da igual.
- Un choque de datos FISCALES no se resuelve adivinando: aborta el dominio
  (§5.1).

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

- **Ventas históricas.** Fuera de alcance explícito de F1. La SUPERFICIE ya
  quedó relevada y con cliente listo —`EncomClient::salesRaw($from, $to)` sobre
  `a_report_transactions?action=detailTable` (valores crudos en `data-order`,
  id de la venta en `data-id`) y `saleDetailRaw($id)` sobre `?action=edit&id=`,
  que es el form con los ÍTEMS de esa venta— pero **ningún dominio de F1 los
  llama**. Lo que falta decidir antes de construirlo: una venta importada NO
  puede pasar por `SaleService::save()` (asignaría numeración nueva y movería
  stock y caja); tiene que entrar como documento ya emitido, con su número
  congelado, y sin tocar `document_sequence` — que es justo lo que el import de
  cajas deja posicionado. Tampoco puede reabrir un período cerrado
  (`context/48`).
- **Etiquetas.** La superficie viva NO las expone por ninguna vía: la tabla de
  artículos no tiene columna y el modo JSON no trae el campo. `tags()` devuelve
  vacío a propósito — inventarlas a partir de otra cosa sería peor que no
  migrarlas.
- **Stock inicial.** Deliberadamente fuera: un saldo es un movimiento del
  ledger con costo y sucursal (`context/52`), y el legacy solo expone un número
  suelto. Se carga con un conteo en la sucursal.
- **Proveedores.** Vienen en el MISMO CSV que los clientes (columna
  `ROL = Proveedor`), así que sumarlos es cambiar un filtro. No se hizo porque
  D5 acota F1 a clientes y un proveedor arrastra compras y cuentas por pagar.
- **Usuarios y staff** (D5 los excluye de F1). También vienen en ese CSV, con
  el nombre del rol en `ROL`. Ojo: `get_users.php` del snapshot tiene dos
  claves `TIN`/`tin` con valores distintos (una lee `lockpass`) — mapear eso a
  ciegas es cómo se importa una contraseña en el campo del RUC.
- **Medios de pago / cuentas.** `banks()` devuelve vacío: la superficie viva no
  los expone y el plan de cuentas de Punto no es el del legacy.
- **Configuración de la empresa.** Se exporta (`settings()` lee el form de
  `a_settings`) pero NO se escribe: pisar la config de una empresa que ya operó
  es destructivo y el owner no lo pidió.

## 9. Supuestos que quedan, y qué se verificó

**Verificado contra el sistema vivo** (coordinador, 2026-09-11):

- `/API` y `/bff` no sirven; el login por `POST /login?login=true` devuelve
  `"true"` y PHPSESSID alcanza.
- El header exacto del CSV de contactos (10 columnas, con `TELEFONO 2`).
- **`registerInvoiceNumber` es el ÚLTIMO número EMITIDO** — la caja
  "AUTOIMPRESOR OLIVA 2026" muestra `0006848` y la última factura del día es
  `009-001-0006848`. **El `+1` de la continuación de serie es correcto.** Era
  el supuesto más caro del plan y queda cerrado.
- `a_outlets?showTable=true` responde **con** wrapper `{"table": …}`; columnas
  Nombre | Razón Social | RUC | Teléfono | Dirección | Online | Estado.
- `a_registers?list=true` responde **SIN** wrapper (HTML pelado) y sus
  columnas son Nombre | Creado el | **Sucursal** | Timbrado | Prefijo | No. de
  Factura | Sufijo | Estado. **Dos trampas ya corregidas**: esa columna
  `Sucursal` no existe en el snapshot y corría un lugar todo lo fiscal (el
  nombre de la sucursal se leía como timbrado), y el prefijo viene con **guión
  final** (`009-001-`), que el regex `^\d{3}-\d{3}$` de Punto habría rechazado
  abortando el dominio entero. Por eso las columnas se resuelven **por
  encabezado** y el prefijo se normaliza.

**Sigue asumido, a confirmar en la primera corrida real:**

1. **Que los `name` de los inputs del form de caja sean los del snapshot**
   (`auth`, `prefix`, `sufix`, `invoice`, `leadingZero`, `expiration`,
   `registerInvoiceNoMax`). **No falla en silencio**: si el form llega y no
   trae NINGUNO de esos campos, `enrichRegisterFromForm()` lanza nombrando la
   caja y el dominio aborta sin importar ninguna (arnés, caso J).
2. **Que `?o=<outletId>` siga cambiando la sucursal activa.** Cubierto por los
   dos lados: si el switch no anduviera, se migrarían las cajas de una sola
   sucursal (se nota en el conteo); y si el listado NO estuviera acotado por
   sucursal —posible, porque el vivo trae una columna `Sucursal`, que es
   justamente lo que tendría una lista que abarca varias— el recorrido
   devolvería cada caja una vez por sucursal. Eso se ve como dos cajas con el
   mismo (timbrado, punto) y habría abortado el import por un choque
   inexistente, así que **las cajas se deduplican por id** y la sucursal sale
   de esa columna cuando está.
3. **Que `a_items?action=showTable` acepte `format=json`.** Probablemente NO
   (es más nuevo); por eso hay fallback a la tabla HTML y el arnés prueba los
   dos caminos.
4. **El orden de columnas de la tabla de ARTÍCULOS** (20 columnas). Es lo único
   que sigue siendo posicional; las de cajas y sucursales ya se resuelven por
   encabezado.

## 10. Lo que hay que hacer antes de mergear

1. **Cargar `ENCOM_MIGRATION_URL`** en Coolify (backend). Sin eso el endpoint
   responde 503 y la UI muestra el aviso con el botón bloqueado. No se cablea
   en código (regla: ningún dominio vive en el código).
2. **Una corrida real contra un cliente de prueba**, mirando los cinco puntos
   de §9 — sobre todo el 5, que es fiscal.


## 11. Arquitecturas rechazadas — no reintroducir

| Arquitectura | Por qué se rechazó |
|---|---|
| **Usar `/API/get_*.php` o `/bff/*.php`** (el mapa original) | NO EXISTEN en el deploy vivo: 404 y "Acceso denegado". Es código del snapshot que no está desplegado. Ver §4. |
| **`get_company.php` como fuente de sucursales y cajas** | Era la fuente elegida en la primera versión de este plan, y está en el grupo `/API` de la fila de arriba: el vivo la rechaza. Se reemplazó por `a_outlets?showTable` + `a_registers?list` con el switch `?o=`. |
| **Parsear el número de factura del TEXTO de la tabla** | El texto está formateado para mirar (`1.250.000`, `12 ene`). El valor crudo viaja en `data-order`/`data-sort`. Parsear el texto obliga a deshacer separadores de miles que dependen de la config del comercio. |
| **Indexar el CSV de contactos por POSICIÓN** | El vivo tiene 10 columnas y el snapshot 9 (`TELEFONO 2`), y el header del documento fiscal es `TIN_NAME`, que cambia por país. Posicional lee el email en la columna del teléfono en una de las dos versiones. |
| **`a_items?action=exportCSV`** | Exige `ids` (no tiene "todos") y su header declara 18 columnas mientras las filas traen 7 claves con otros nombres: está desalineado en el propio legacy. |
| **Componer el punto de expedición como `prefix + "-" + sufix`** | `registerInvoicePrefix` YA es `EEE-PPP`; el legacy le hace `explode("-")` para SIFEN. `sufix` es otro campo. Componerlo generaría un punto de expedición inventado sobre un dato fiscal. |
| **Importar las cajas "hasta donde se pueda"** ante un choque de punto de expedición | Deja al comercio con la mitad de sus cajas fiscales y sin señal de cuáles faltan. D5 pide fallo duro del dominio. |
| **Guardar la password del cliente** para poder reintentar el job | D2. El reintento se resuelve creando el job de nuevo (el login son 3 campos); guardar la credencial de un tercero para ahorrar eso no se paga. |
| **INSERT directo del catálogo** para ir más rápido | D4. Los servicios son los que aplican los invariantes: saltearlos es exactamente cómo entran dos cajas con el mismo punto de expedición, un contacto con teléfono duplicado o un ítem sin fila en `item_outlet`. |
| **Correr el import inline en la request de `/admin`** | Minutos de export paceado a 60 req/min, más el contexto de tenant que se fija una vez por proceso. Ver §3.1. |
