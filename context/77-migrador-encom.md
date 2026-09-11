# 77 — Migrador ENCOM → Punto

> Estado: **F1 IMPLEMENTADA** 2026-09-11. La capa de EXPORT se reescribió el
> mismo día sobre `POST /fetchs` (branch `api/migrador-fetchs`): ver §4, que
> reemplaza al scraping de pantallas con el que arrancó la F1.
> D1-D6 cerradas por el owner, no relitigar.
> F2 (ventas históricas) **no** está implementada — ver §12.
> **La APERTURA DE STOCK se agregó el 2026-09-11** (decisión del owner, §16):
> el migrador importa cantidad y costo por (artículo, sucursal). Eso REVIERTE
> el "el stock inicial no se migra" que decían §12 y la UI.

## 1. Qué resuelve

El equipo de soporte carga en `/admin` las credenciales de un cliente en el
panel legacy (ENCOM) y un job exporta sus datos y los importa a una empresa de
Punto **ya creada**. El migrador importa datos; no crea cuentas.

Alcance: catálogo (categorías, marcas, etiquetas, artículos **con sus combos y
recetas**), clientes, configuración (sucursales + cajas **con su numeración
fiscal**), **usuarios** y **medios de pago**.

## 2. Decisiones cerradas por el owner (2026-09-11)

| # | Decisión |
|---|---|
| **D1** | Superficie `/admin`, sección **Migraciones**: form de credenciales + empresa destino + checkboxes de qué migrar, y listado de jobs con estado y log por dominio. |
| **D2** | **La password NO se persiste.** El endpoint hace el login al legacy en el momento, guarda SOLO las cookies efímeras y el alcance en el job, y descarta la password. Si el login falla, el job no se crea. |
| **D3** | Ejecución por tabla-cola `migration_job` + **worker CLI aparte**, disparado desde el drain de `api/v1/maintenance.php`. |
| **D4** | Import por los **servicios reales** de Punto, nunca INSERT directo salvo la tabla de mapeo propia. `migration_map` da idempotencia. |
| **D5** | Sucursales y cajas se importan **con timbrado, punto de expedición y numeración actual**; la caja de Punto CONTINÚA la serie desde el último número + 1. **Validación dura**: dos cajas con el mismo punto de expedición bajo el mismo timbrado = error del job, no se importa a medias. La FE no se configura (manual después). |
| **D6** | El job registra en `tenant_audit` del destino qué importó. |

> D5 decía además "usuarios/staff NO se migran en F1". Eso era una consecuencia
> de la fuente, no una decisión de producto: las pantallas del panel no
> exponían el equipo. Con `/fetchs` sí se exponen, y se migran (§8).

## 3. Arquitectura

```
/admin  ──POST /v1/admin/migrations.php──▶  login al legacy (AHORA)
                                            ├─ resuelve companyId/outletId del legacy
                                            └─ guarda cookies + alcance en migration_job (pending)

cron 2 min ──POST /v1/maintenance?job=migration-drain──▶ EncomMigrationService::drain()
                                            ├─ reencola jobs 'running' colgados
                                            └─ claimNext() + spawn del worker

php api/scripts/migration_worker.php <jobId> <companyId>
        ├─ EncomClient::fromCookies()   → 7 POST a /fetchs
        ├─ EncomImportService::run()    → import por los servicios reales
        └─ finish()  → estado + progreso + BORRA las cookies
```

| Pieza | Archivo |
|---|---|
| Schema (cola + mapeo) | `api/database/migrations/postgres/218_migracion_encom.sql` |
| Contrato de la fuente | `api/lib/Admin/EncomSource.php` |
| Cliente HTTP del legacy | `api/lib/Admin/EncomClient.php` |
| Parsers (solo lo que F2 va a necesitar) | `api/lib/Admin/EncomParse.php` |
| Ciclo de vida del job + drain | `api/lib/Admin/EncomMigrationService.php` |
| Import por dominio | `api/lib/Admin/EncomImportService.php` |
| Endpoint realm admin | `api/v1/admin/migrations.php` |
| Worker | `api/scripts/migration_worker.php` |
| UI | `frontend/app/(admin)/admin/migrations/page.tsx` + `components/admin/migration-*.tsx` |
| Arnés | `api/tests/run_encom_migration_test.sh` |

### 3.1 Por qué cola + proceso aparte (D3)

`api/data.php` define `COMPANY_ID` / `OUTLET_ID` / `TODAY` con `define()`, una
vez por proceso, y exige outlet/register/user activos que una empresa recién
creada —el caso normal de un destino— no tiene. El worker NO pasa por
`data.php`: define las constantes a mano (patrón de los arneses de
`api/tests/`).

La segunda razón perdió peso con `/fetchs`: el export ya no son decenas de
requests paceadas (era una por sucursal y otra por caja), son **siete**. Sigue
siendo un proceso aparte por el contexto de tenant y porque el import completo
—catálogo grande por los servicios reales— igual pasa cualquier timeout de
PHP-FPM razonable.

## 4. La fuente: `POST /fetchs`, el bootstrap del POS

> **Esto reemplaza al scraping con el que se implementó la F1.** El plan
> anterior documentaba que el deploy vivo no tenía superficie JSON (`/bff/*` da
> 404, `/API/get_*` da "Acceso denegado") y concluía que el export tenía que
> salir de las PANTALLAS del panel, parseando CSV y HTML. Esa conclusión era
> correcta sobre las superficies que se habían probado, e **incompleta**: el
> POS legacy se bootstrapea contra un endpoint propio que sí responde.

```
POST https://app.encom.com.py/fetchs?load=<dominio>&gtoken=
body: companyId=<hashid>&outletId=<hashid>&updateData=true&lastUpdate=false
```

Verificado vivo el 2026-09-11 con `companyId=QE22&outletId=62Lm`. `gtoken`
vacío funciona; la autorización real es la cookie **PHPSESSID** del login.
Devuelve JSON limpio, no HTML.

### 4.1 Qué devuelve cada `load`

| `load` | Forma | Lo que aporta |
|---|---|---|
| `items` | lista | Catálogo completo **con `compound` inline** (combos y recetas), `kind`, `tax`, `categoryId`, `sku`, stock actual |
| `customers` | lista | `customerId` propio, documento **con su tipo**, `storeCredit`, `creditLine`, `loyalty`, geo SIFEN |
| `users` | lista | Equipo del comercio con `lockPass` (PIN), `roleName` y `permissions` |
| `outlets` | lista | Sucursales con razón social, RUC, lat/lng y `weekHours` |
| `registers` | **objeto** `{registers, docsNum}` | Cajas con timbrado y punto, y el **último correlativo por tipo de documento** |
| `settings` | lista de 1 | Config del comercio, **`paymentMethods`** y `tags` |

### 4.2 Por qué se ELIMINÓ el scraping en vez de dejarlo como respaldo

Regla del proyecto: arquitectura, no parche. Dos fuentes para el mismo dominio
dejan sin respuesta la pregunta "¿de dónde salió este dato?" cuando algo sale
mal, y obligan a mantener y probar dos mapeos que divergen en silencio.

La que sobrevive es estrictamente mejor, y no solo por robustez:

- **Trae lo que el otro no podía.** Combos, recetas, usuarios, PIN, medios de
  pago y el correlativo por doctype eran "no migrables" en la F1 **por la
  fuente**, no por una decisión.
- **No depende del orden de las columnas de una tabla** ni de qué atributo
  (`data-order` / `data-sort`) lleva el valor crudo, ni del encabezado
  `TIN_NAME` que cambia por país, ni de que el `name` de un input del form no
  se mueva. Toda esa fragilidad —documentada en detalle en la versión anterior
  de este doc— desapareció junto con el código que la sufría.
- **Pasó de decenas de requests a siete.** El recorrido por sucursal con el
  switch `?o=` (dos requests por sucursal) más un `?action=edit` por caja ya no
  existe.

Se borraron: el parser de CSV indexado por nombre de columna, `columnIndex()` /
`htmlHeaders()` / `htmlTable()` y todos los métodos de scraping del cliente.
**Sobreviven** `htmlRows()`, `tableHtml()` y `formValues()` en `EncomParse`,
más `get()` y la sesión de panel en `EncomClient`, por una sola razón: el
histórico de VENTAS (F2) **no está en `/fetchs`** y sigue saliendo de
`a_report_transactions` (§12). Si F2 se descarta, se van los tres juntos.

### 4.3 Cómo se obtiene el alcance sin pedírselo a nadie

`/fetchs` necesita el par (companyId, outletId) del legacy: hashids cortos
(`QE22`, `62Lm`), no UUID, que el cliente nunca ve y no puede dictar. Se deduce
de la sesión recién abierta:

1. `GET /bff/pos-redirect.php` **sin seguir el redirect** → header `Location`
   con `?i=<base64>` → `base64_decode` → `"companyId,outletId"`.
2. Fallback para el deploy viejo (donde ese archivo da 404): el mismo `?i=` en
   el href del botón "Caja" del home del panel.

Si **ninguna** vía da un par completo, el cliente **LANZA** y el job no se
crea. Es deliberado: seguir con un companyId vacío haría que `/fetchs`
devolviera el catálogo de otro comercio —o de ninguno— y el job lo importaría
sin una sola señal. El error sale en la pantalla del alta, con el operador
mirando, no media hora después dentro del worker.

El alcance se guarda en `migration_job.credentials.scope`, junto a las cookies
y con su misma vida útil: se borra cuando el job termina.

### 4.4 La excepción acotada: el COSTO sale del panel

`/fetchs` es el bootstrap del POS y **el POS no necesita el costo para
vender**, así que no lo manda. Es el único campo del catálogo que el scraping
viejo daba y este no, y perderlo deja en cero todo reporte de margen del
comercio migrado — una regresión contra el migrador que ya está en producción.

Por eso el costo —y solo el costo— se lee de `a_items?action=showTable`, la
tabla del panel, con la sesión que ya está viva (no hay login nuevo).

**Esto no contradice §4.2.** Lo que ahí se rechaza es tener DOS fuentes para el
mismo dato; acá hay **una fuente por dato**: el catálogo entero (nombre, precio,
IVA, categoría, marca, SKU, código de barras, composición) sigue saliendo de
`/fetchs`, y del panel sale un campo que esa fuente no tiene.

Reglas que lo mantienen acotado:

- **Es un enriquecimiento, nunca un insumo.** Si el panel falla, cambia de
  columnas o contesta vacío, el catálogo se importa igual —sin costos— y el job
  lo dice en la bitácora. `loadItemCosts()` no propaga nunca.
- **El cruce es por SKU y, si no hay, por nombre normalizado.** El SKU es el
  identificador que el comercio controla; el nombre es una heurística
  razonable (las dos superficies son del mismo comercio) pero no es una clave.
- **Lo que no matchea NO se inventa.** El artículo entra con `itemCost = NULL`
  ("no lo sé", que no es lo mismo que 0: un 0 falso arruina el margen de ese
  artículo para siempre) y queda nombrado en el log para que soporte lo cargue.
- **Las columnas se resuelven por ENCABEZADO.** La tabla de artículos era lo
  único que en la F1 seguía siendo posicional —el supuesto #4 del plan—; ahora
  que vuelve a tener un lector, se lee por encabezado con el orden conocido
  como respaldo.
- **`exportCSV` sigue descartado** (§15): exige `ids` y su header está
  desalineado con las filas en el propio legacy.

## 5. La numeración fiscal (D5) — el corazón

El contador del legacy guarda el **último** número emitido. `document_sequence.
nextnumber` de Punto guarda el **próximo** (mig 117). Esa asimetría **es** el
`+1`, no un margen de seguridad. (Verificado contra el sistema vivo: la caja
"AUTOIMPRESOR OLIVA 2026" mostraba `0006848` y la última factura del día era
`009-001-0006848`.)

Lo que cambió con `/fetchs`: ese número ya no se lee de un campo de texto de un
formulario, sino de **`docsNum`**, la estructura con la que el propio POS
legacy numera. Y trae un contador **por tipo de documento**.

`docsNum` tiene siete contadores y Punto numera por caja **tres**
(`RegisterAdminService::DOC_TYPES` = `factura`, `cotizacion`, `nota_credito`).
Se mapean esos tres:

| legacy | Punto | Serie |
|---|---|---|
| `invoiceNo` | `factura` | timbrado + punto de expedición |
| `quoteNo` | `cotizacion` | sin serie fiscal |
| `returnNo` | `nota_credito` | hereda la de la factura (mig 215) |

`ticketNo`, `orderNo`, `remissionNo` y `scheduleNo` **no se migran**: Punto no
les asigna secuencia por caja, y mandarlos hace que `RegisterAdminService`
rechace el alta entera con "Tipo de documento desconocido". No se inventa una
secuencia para un documento que Punto no numera así.

La caja se crea con `RegisterAdminService::create()` pasando `fiscal`,
`numbering` y `padWidth` (de `leadingZero`). **No se reimplementa nada de la
numeración** — es el mismo camino que el alta de una caja desde el panel.

### 5.1 El rechazo es duro y previo

Dos cajas con el mismo `(timbrado, EEE-PPP)` llevarían la misma secuencia y
emitirían dos facturas con el mismo número: documento duplicado, ilegal ante la
SET (`context/29` §2). `assertExpeditionPointsFree()` valida **todo el lote
antes de crear la primera caja** y aborta el dominio entero. También se rechaza
un prefijo que no cumpla `^\d{3}-\d{3}$` (el legacy lo guarda con guión final,
`001-001-`, y se normaliza antes de validar).

## 6. Idempotencia

`migration_map(companyid, domain, legacyid) → puntoid`. Antes de crear
cualquier entidad se pregunta si ese id del legacy ya tiene id de Punto.
Re-correr da los mismos conteos con todo en `skipped`, y **no vuelve a mover la
numeración fiscal**.

Dominios del mapa: `category`, `brand`, `tag`, `item`, **`compound`**,
`customer`, `outlet`, `register`, `user`, `payment`.

**`compound` es un dominio aparte del `item` a propósito**, y es el caso donde
la idempotencia no era gratis: `ItemCompoundService::add()` **suma** la cantidad
cuando el ingrediente ya existe, así que re-correr sin esa marca convertiría 1
unidad de harina en 2, y en 3. Son dos hechos distintos —"el artículo existe" y
"el artículo ya está compuesto"— y necesitan dos filas.

**Y tiene DOS NIVELES**, por corrección de stock. Cada componente escrito deja
su propia marca (`padre:hijo`); el PADRE se marca **solo cuando no quedó ningún
componente sin resolver**. Una receta a medias —un combo cuyo componente no
existe todavía en el catálogo migrado— **no se marca**, así que la corrida
siguiente vuelve a entrar y la TERMINA en cuanto soporte crea el ítem que
faltaba; los componentes ya escritos los saltea su propia marca, sin volver a
sumarse.

Marcar al padre a medias lo congelaría para siempre: la corrida siguiente lo
saltearía por idempotente y `explodeRecipe` descontaría de menos en CADA venta,
en silencio. Es el P1 que encontró el `code-reviewer` sobre esta branch;
cubierto por el caso M del arnés (2 componentes, 1 resuelve: no se marca, y una
segunda corrida lo completa sin duplicar el primero).

`remember()` usa `ON CONFLICT DO NOTHING`: si la clave ya existe, el id válido
es el **primero** — es al que pueden estar apuntando los ítems ya importados.

Un solo job vivo por empresa, con índice único parcial en la base. La
idempotencia protege el **re-correr**, no el correr en paralelo.

## 7. Combos y recetas — el mapeo de `compound`

`compound` viaja **inline en cada artículo**, como un string con JSON adentro:

```json
[{"id":"6KNgR","units":"1.000","select":"0"}]
```

`id` es el itemId del componente **en el legacy**, `units` la cantidad (decimal
con punto: `1.000` es 1, no mil) y `select` si el componente lo elige el
cliente al vender.

**Se importa en DOS pasadas**: primero se crean todos los artículos, después se
componen. Un combo puede referenciar ítems que vienen después que él en el
export, así que la composición no se puede resolver mientras se crea.

### 7.1 Mapeo de kinds

| `kind` legacy | kind de Punto | Composición |
|---|---|---|
| `product` | `producto` | — |
| `service` / `type=service` | `servicio` | — |
| `combo` | `combo_fijo` | `item_compound` |
| `precombo` | `combo_fijo` | `item_compound` (un combo cerrado usado como componente de otro sigue siendo un combo fijo) |
| `production` | `produccion_previa` | `item_compound` |
| `direct_production` | `produccion_directa` | `item_compound` |
| `comboAddons` | `combo_dinamico` | **no se compone** |
| `dynamic` | `combo_dinamico` | **no se compone** |
| `giftcard` | `giftcard` | — |
| `discount` | `descuento` | — |
| lo que no matchee | `producto` | — |

Los flags legacy (`itemType`/`itemCanSale`/`itemTrackInventory`/
`itemProduction`) salen de `ItemImporter::legacyFlagsForKind()`, que es
`public static` justamente para que no haya dos tablas kind→flags.

### 7.2 Qué NO se inventa

Un componente con `select="1"` es una OPCIÓN que el cliente elige. En Punto eso
**no es una receta** sino un grupo de add-ons (`addon_group`), y el export no
trae nada de lo que ese modelo necesita: ni el nombre del grupo, ni
`minSelect`/`maxSelect`, ni el `priceDelta` de cada opción.

Fabricar un grupo con valores inventados es **peor** que no migrarlo: un
`maxSelect` adivinado deja al cajero sin poder cerrar la venta, y un
`priceDelta` en 0 regala el agregado (`context/41` D2). Así que esos
componentes no se escriben, el `combo_dinamico` entra **sin composición**, y el
artículo queda anotado en la bitácora del job —con su nombre y su kind del
legacy— como "revisar a mano". Lo mismo cuando un componente no existe en el
mapa.

Es la diferencia entre un combo que hay que terminar de armar (visible, con su
nombre en el log) y uno que vende mal en silencio.

## 8. Usuarios y roles

Se migran nombre, email, teléfono, color, sucursal y **el PIN de la caja**
(`lockPass`), por `UsersService` — el mismo servicio del panel, con su
validación de 4 dígitos y de PIN único.

**El objeto `permissions` del legacy NO se mapea permiso por permiso.** Su
forma (`{register: {access, orders: {create, edit, view}, …}}`) no tiene
relación con las permission keys de Punto (`pos.sale.create`, …), y traducirla
a ciegas es adivinar: adivinar de más significa darle a un cajero un permiso
que nunca tuvo, que es el tipo de error que nadie descubre hasta que alguien
anula una venta que no debía.

En su lugar se asigna el **ROL de Punto más cercano por nombre** y el rol trae
sus permisos:

1. nombre idéntico (`"Cajero"` del legacy → `Cajero` de Punto);
2. palabra clave → slug: dueño/propietario → `owner`; encargado/gerente/
   supervisor/**administrador** → `manager`; cajero/vendedor/mozo → `cashier`;
3. ante la duda, el rol **más bajo** que exista.

"Administrador" cae en `manager`, no en `owner` — mismo criterio que el mapa
legacy de `RoleService`. El rol `device` nunca se asigna: no es para una
persona, lo lleva la sesión de un dispositivo pareado.

**Cada asignación queda escrita en la bitácora del job** ("rol del legacy X →
rol de Punto Y") para que soporte la revise con el comercio.

⚠ **`RoleService` vive en el namespace GLOBAL**, no en `Punto\Api\Auth` como el
resto de `api/lib/Auth/`. Llamarlo con el namespace "obvio" tira *class not
found* y se lleva puesto el dominio entero de usuarios (progreso en `null`, no
una fila fallida). Lo encontró el arnés.

**La contraseña del panel no se migra**: el legacy guarda un hash con otro
algoritmo y otra sal. Cada usuario nace con una contraseña aleatoria que nadie
conoce —entra al POS con su PIN, que sí se migra— y la del panel se restablece
desde Equipo. Está dicho en la UI y en el log del job.

Si un dato OPCIONAL viene sucio (email ya usado, PIN ya usado, teléfono que
libphonenumber no parsea), el usuario se importa **sin ese campo** y la
bitácora dice cuál se cayó. Perder al usuario entero por un email duplicado no
se paga; pisarlo en silencio tampoco.

## 9. Medios de pago

`ensureSeed()` primero (Efectivo, tarjetas, Giftcard, Cheque — los que
disparan flujos propios del POS por su `systemKey`), y recién después los del
legacy. Un medio que ya existe con el mismo nombre **se adopta** (se mapea al
existente) en vez de duplicarse: `taxonomy` tiene UNIQUE por (empresa, tipo,
nombre) y "Efectivo" además es único por diseño.

## 10. Impuestos

`/fetchs` manda el impuesto del artículo como el VALOR legacy (`"10"`, `"5"`,
`"0"`), que es exactamente lo que Punto guarda en `tax.name` (la compatibilidad
con `getTaxValue()` es histórica y sigue vigente). Si el destino no tiene ese
impuesto se crea con `TaxService`, que deriva `rate`/`kind` del nombre con el
mismo criterio que el resto del sistema. Un impuesto que no se pudo crear no
cuesta el artículo: entra sin impuesto y queda la nota.

## 11. Detalles que muerden

- **La caja placeholder se reusa.** `OutletsService::create()` deja una caja
  "Nueva Caja" sin timbrado para cumplir el invariante "sucursal sin caja no
  existe". El importador la reusa para la primera caja de esa sucursal; si no,
  cada sucursal migrada quedaría con una caja fantasma que borrar a mano.
- **Sucursal de una caja: nunca se adivina.** Sale del mapa; si no está, se usa
  la sucursal de respaldo que el operador eligió, y sin ninguna de las dos la
  caja **no se importa** (memoria: prohibido resolver una dimensión faltante
  con "el primer outlet activo").
- **La categoría vive en dos lados**: la FK legacy `item.categoryId` y la m2m
  `item_category`. Se escriben las dos (la trampa que ya pisó la mig 136).
- **El alta de un artículo es atómica.** `createBlank()` + `update()` van en una
  transacción: sin eso, una fila mala dejaba un "Nuevo Artículo" huérfano.
- **Los clientes ya no se fusionan.** El CSV del panel no traía id y la
  idempotencia usaba una clave natural (documento, o nombre normalizado), que
  fusionaba a dos homónimos sin documento en un solo cliente. `/fetchs` manda
  `customerId`: ese parche se fue con el scraping.
- **Un dominio que falla no frena a los otros**, y un job con errores queda
  `failed` aunque parte haya entrado: `done` con errores es un verde que nadie
  vuelve a mirar.
- **Las cookies se borran al terminar el job**, salga bien o mal, y nunca se
  devuelven por la API. El drain BARRE las de los jobs que nunca llegaron a
  correr: a las 24 h se nulean y el job se cierra como `failed` con el motivo
  escrito (un `pending` sin cookies no puede correr y bloquearía toda migración
  nueva de ese comercio).

### 11.1 Ventana conocida: caja huérfana si el proceso muere entre medio

El import de una caja son dos escrituras que NO están en la misma transacción:
`RegisterAdminService::create()` y después `remember()`. Si el worker muere en
el medio, la caja queda creada **sin mapear**, y al reintentar
`assertExpeditionPointsFree()` encuentra el par (timbrado, punto) tomado por
ella misma y aborta el dominio.

**Cómo se reconoce**: el error nombra como dueña del punto de expedición a una
caja con el MISMO nombre que la que se está importando. **Cómo se arregla**:
borrar esa caja en el panel del destino (no emitió nada) y relanzar.

**Por qué se deja así**: cerrarlo pide una transacción que abarque el servicio
canónico de altas de caja —que abre la suya y publica un evento realtime al
commitear— para servir a un caso del migrador. Un arreglo manual de un minuto,
en un flujo que corre una vez por cliente y con un operador mirando, contra
tocar el camino por el que se dan de alta TODAS las cajas del producto.

## 12. Qué NO se migra, y por qué

- **Ventas históricas (F2).** `/fetchs` no las expone por ningún `load`: es el
  bootstrap de una caja, no un reporte. La superficie ya está relevada y el
  cliente listo (`salesRaw()` / `saleDetailRaw()` sobre
  `a_report_transactions`), pero **ningún dominio los llama**. Lo que falta
  decidir: una venta importada NO puede pasar por `SaleService::save()`
  (asignaría numeración nueva y movería stock y caja); tiene que entrar como
  documento ya emitido, con su número congelado, sin tocar `document_sequence`
  —que es justo lo que el import de cajas deja posicionado— y sin reabrir un
  período cerrado (`context/48`).
- ~~**Stock inicial.**~~ **Se migra desde 2026-09-11** — decisión del owner.
  Era el mismo argumento que el costo: sin apertura, el COGS de toda venta nace
  null y los reportes de margen salen vacíos. Cantidad y costo son la MISMA
  operación y entran juntos. Ver §16.
- ~~**El COSTO de los artículos.**~~ **Se migra desde 2026-09-11** — decisión
  del owner: perderlo era una regresión contra el migrador que ya está en
  producción y dejaba en cero los reportes de margen. Sale de la tabla del
  panel, no de `/fetchs`, y se cruza por SKU o por nombre. Ver §4.4: es un
  enriquecimiento acotado, no una vuelta al scraping de catálogo.
- **Grupos de opciones de un combo dinámico.** Ver §7.2.
- **Horario de atención de las sucursales.** `weekHours` viene en el export,
  pero el `outlet` de Punto no tiene un modelo de horarios mantenido (la
  columna `data` lo menciona; ningún servicio lo escribe ni lo lee). Guardarlo
  acá sería crear un campo que solo el migrador conoce.
- **Numeración de ticket / orden / remisión / agenda.** Ver §5.
- **Configuración de la empresa.** Se exporta (`settings()`) y se usa para
  etiquetas y medios de pago, pero NO se escribe sobre `company`: pisar la
  config de una empresa que ya operó es destructivo y el owner no lo pidió.
- **Proveedores.** El legacy los tiene; `/fetchs?load=customers` devuelve
  clientes. Un proveedor arrastra compras y cuentas por pagar: es otro alcance.

## 13. Supuestos que quedan, y qué se verificó

**Verificado contra el sistema vivo** (2026-09-11):

- `POST /fetchs?load=<X>&gtoken=` responde JSON con `gtoken` vacío y cookie
  PHPSESSID, para `items` (416 filas), `users` (7), `customers` (481),
  `outlets`, `registers` y `settings`.
- Los campos de cada dominio (§4.1) y que `compound` viaja como string JSON.
- `registers` devuelve `{registers, docsNum}` y `docsNum[].invoiceNo` es el
  ÚLTIMO correlativo emitido.
- El login por `POST /login?login=true` con `email`/`password` devuelve `"true"`
  y PHPSESSID alcanza.
- `base64_decode('UG5YYSxLTHpW') === 'PnXa,KLzV'` — la forma del `?i=`.

**Asumido, a confirmar en la primera corrida real** (los tres están aislados y
ninguno aborta un dominio):

1. **El shape de `settings.paymentMethods`.** El relevamiento confirma que la
   CLAVE existe, no cómo viene adentro. El cliente acepta lista de nombres o de
   objetos (`name`/`paymentMethodName`/`title`/`label`) y descarta lo que no
   tenga nombre.
2. **El shape de `settings.tags`.** Igual que el anterior. Una etiqueta mal
   leída es cosmética: se ignora en silencio en vez de abortar el catálogo.
3. **Que `name` sea la razón social y `fullName` el nombre de la persona** en
   `customers`. Si estuvieran invertidos, los dos campos igual quedan cargados
   (`ContactService` guarda `contactName` + `contactSecondName`), solo que
   cruzados.
4. **Que `barcode` no venga.** No aparece en el relevamiento; se lee por si
   acaso (`barcode`/`itemBarcode`) y su ausencia deja el artículo sin código,
   nunca con uno inventado.

**Confirmado como DISTINTO, y por eso no se traduce:** el `typeIdentifier` de
`customers` usa la numeración del legacy (manda `1`, `2`), que **no** es la
Tabla 3 de la SET con la que Punto valida (`ContactService::ID_TYPES` = 11..17).
Su tabla de códigos no está relevada y es un dato fiscal, así que el tipo se
manda SOLO si el valor ya es un código válido de Punto; si no, se omite y Punto
lo infiere al leer. **El número del documento se migra igual** (`ruc` → `tin`,
`ci` → `ci`), que es lo que identifica al cliente. Lo encontró el arnés: con la
traducción a ciegas, `ContactService` lanzaba y se perdía el cliente ENTERO.

## 14. Lo que hay que hacer antes de mergear

1. **Cargar `ENCOM_MIGRATION_URL`** en Coolify (backend). Sin eso el endpoint
   responde 503 y la UI muestra el aviso con el botón bloqueado.
2. **Una corrida real contra un cliente de prueba**, mirando los cuatro puntos
   de §13 y, sobre todo, la numeración (§5), que es fiscal.

## 15. Arquitecturas rechazadas — no reintroducir

| Arquitectura | Por qué se rechazó |
|---|---|
| **Scraping del panel para catálogo / clientes / cajas / sucursales** | Reemplazado por `/fetchs` y **eliminado**, no dejado como fallback. Ver §4.2: dos fuentes para el mismo dominio dejan sin respuesta de dónde salió un dato y divergen en silencio. La ÚNICA lectura que queda del panel en el catálogo es el COSTO (§4.4), que `/fetchs` no manda: una fuente por dato, no dos por dato. |
| **`a_items?action=exportCSV`** como fuente del costo | Exige `ids` (no tiene "todos") y su header declara 18 columnas mientras las filas traen 7 claves con otros nombres: está desalineado en el propio legacy. El costo sale de `showTable`, resuelto por encabezado. |
| **Poner 0 cuando no se encuentra el costo** | "No lo sé" y "cuesta cero" no son lo mismo: un 0 falso arruina el margen de ese artículo para siempre y nadie lo vuelve a mirar. Entra `NULL` y el artículo queda nombrado en la bitácora. |
| **Usar `/API/get_*.php` o `/bff/*.php` como fuente de datos** | NO EXISTEN en el deploy vivo: 404 y "Acceso denegado". (`/bff/pos-redirect.php` se usa SOLO por su header `Location`, y con fallback si no está.) |
| **Pedirle al operador el companyId/outletId del legacy** | El cliente no los conoce —son hashids internos que nunca ve— y tipearlos mal importa el catálogo de otro comercio. Se deducen de la sesión (§4.3). |
| **Seguir con el alcance a medias** si no se pudo resolver | `/fetchs` contestaría el bootstrap de otra sucursal, o de ninguna, y el job importaría eso sin señal. Se LANZA. |
| **Mapear `permissions` del legacy permiso por permiso** | Las dos formas no tienen relación y adivinar de más le da a un cajero permisos que nunca tuvo. Se asigna el ROL más cercano y ante la duda el más bajo (§8). |
| **Fabricar grupos de add-ons a partir de los componentes `select=1`** | El export no trae nombre de grupo, min/max ni `priceDelta`. Un `maxSelect` adivinado traba la venta; un `priceDelta` 0 regala el agregado. Se anota para revisar (§7.2). |
| **Componer las recetas en la misma pasada que crea los artículos** | Un combo referencia ítems que pueden venir después en el export. Dos pasadas. |
| **Marcar la composición en el mismo dominio del mapa que el artículo** | `ItemCompoundService::add()` SUMA la cantidad: re-correr duplicaría cada receta. Son dos hechos distintos (§6). |
| **Mandar `ticketNo`/`orderNo`/`remissionNo` como numeración de la caja** | `RegisterAdminService` rechaza el alta entera con "Tipo de documento desconocido". Punto no numera esos documentos por caja (§5). |
| **Importar las cajas "hasta donde se pueda"** ante un choque de punto de expedición | Deja al comercio con la mitad de sus cajas fiscales y sin señal de cuáles faltan. D5 pide fallo duro del dominio. |
| **Componer el punto de expedición como `prefix + "-" + sufix`** | `invoicePrefix` YA es `EEE-PPP` (con guión final, que se normaliza). `sufix` es otro campo. Sería inventar un dato fiscal. |
| **Guardar la password del cliente** para poder reintentar el job | D2. El reintento se resuelve creando el job de nuevo (el login son 3 campos). |
| **INSERT directo del catálogo** para ir más rápido | D4. Los servicios son los que aplican los invariantes: saltearlos es exactamente cómo entran dos cajas con el mismo punto de expedición o una receta con un ciclo. |
| **Correr el import inline en la request de `/admin`** | Contexto de tenant por proceso + duración del import por servicios reales. Ver §3.1. |

## 16. La apertura de stock (2026-09-11)

### 16.1 Por qué hacía falta, y por qué `itemCost` no alcanzaba

El migrador ya importaba `item.itemCost` (§4.4), pero **ese campo no se usa al
vender**. `SaleService::resolveUnitCOGS()` (`api/lib/Sales/SaleService.php`) lee
el costo promedio ponderado que dejó el **último movimiento del ledger**
(`getItemStock(...)['stockOnHandCOGS']`). Sin un solo movimiento no hay COGS: la
venta lo omite y **todos los reportes de margen del comercio migrado nacen
vacíos**. Por eso cantidad y costo son la misma operación y se importan juntos.

### 16.2 Cómo entra

Dominio propio **`stock`**, que corre **último**: una apertura es un movimiento
por (artículo, sucursal), y necesita el mapa de artículos que llena `catalog` y
el de sucursales que llena `config`.

- **Por el servicio real** (D4): `Inventory::manageStock()`, único escritor del
  ledger. `source='adjustment'` — el mismo contrato que el ajuste del panel
  (`StockAdjustmentService`) y que la carga inicial de la planilla
  (`ItemImporter::cargarStockInicial()`). No se inventa un source.
- **Por sucursal**: `/fetchs` contesta el bootstrap de UNA caja, así que el
  saldo de cada sucursal se pide con su propio `outletId`
  (`EncomClient::itemStock()`). La caché del cliente pasó a ser por
  **(load, sucursal)**: con una sola clave, el saldo de la primera sucursal se
  le habría servido a todas las demás.
- **Solo artículos con stock propio**, y lo decide el artículo YA migrado
  (`item.itemTrackInventory`), no el flag del legacy. Servicios, combos y
  producciones no llevan apertura: su costo sale de `RecipeCosting`.
- **El costo sale de `item.itemCost`** (el que el propio migrador escribió, o el
  que soporte cargó después). No se vuelve a pedir al panel: una fuente por dato.

### 16.3 La regla dura: nunca un 0 que se lea como "cuesta cero"

**Un artículo sin costo conocido NO se abre.** Queda nombrado en la bitácora con
su cantidad; soporte le carga el costo y vuelve a lanzar, y la corrida siguiente
lo abre sin tocar los que ya estaban.

Verificado **empíricamente contra Postgres real** antes de elegir:

| Situación | Qué pasa |
|---|---|
| `manageStock()` con costo desconocido | NO guarda NULL: escribe **0.00** en `stockCOGS` y `stockOnHandCOGS` |
| Con esa fila, `resolveUnitCOGS()` | devuelve **0.0**, no null → la venta escribe `itemSoldCOGS = 0` → **margen 100%** |
| Sin ninguna fila | devuelve **null** → la venta OMITE la columna, que es lo que `SaleService` ya hace a propósito |
| `stockCOGS = NULL` escrito a mano | la columna lo acepta y `resolveUnitCOGS()` da null… **pero al primer movimiento posterior colapsa a 0.0** (el promedio móvil lo castea y lo propaga) |

O sea que "costo sin definir" **no es un estado que el ledger sepa sostener**:
la opción de abrir con el costo vacío estaba disponible en la columna y no en el
comportamiento. De ahí que la decisión sea no abrir.

### 16.4 Idempotencia: acá duplicar es plata

Marca por **(artículo, sucursal)** en el dominio `stock_opening` de
`migration_map`, y **el movimiento y su marca van en la misma transacción**. Sin
eso, un worker que muere entre las dos escrituras deja el movimiento sin marcar
y la corrida siguiente lo **suma de nuevo**: mismo riesgo que las recetas (§6),
pero peor, porque el duplicado es stock que el comercio cree tener.

### 16.5 El arreglo que esto destapó en `manageStock()`

`stock.userId` es `uuid` y `manageStock()` lo escribía **sin** el `?: null` que
ya tenían `transactionId`, `supplierId` y `locationId`. Con `USER_ID = ''` —el
contexto del worker, que importa para OTRO tenant y no tiene usuario de sesión—
el INSERT reventaba entero.

Por el mismo motivo se guardaron las lecturas de nombres del bloque de
auditoría: `getValue('contact', …)` y `getCurrentOutletName()` interpolan su id
en SQL crudo, así que con la constante vacía tiran `invalid input syntax for
type uuid` y —al correr dentro de la transacción del caller— **la abortan**.
`REGISTER_ID` ya estaba guardado por exactamente esta razón; faltaban las otras
dos.

Va **en el wrapper**, no en el migrador: es el único punto por el que pasan los
27 callers, y cualquier proceso sin sesión (jobs, `/admin`, sync) pisaba la
misma piedra.
| ~~**Migrar el stock inicial**~~ | **REVERTIDA 2026-09-11 por el owner.** El motivo para no hacerlo era que el export daba "un número suelto sin costo"; el costo ya se migra (§4.4), así que la apertura entra CON costo y el argumento cayó. Ver §16. |
| **Abrir el stock con costo 0 cuando el costo no se sabe** | Verificado empíricamente: con la fila en 0, `resolveUnitCOGS()` devuelve `0.0` y no `null`, la venta escribe `itemSoldCOGS = 0` y el margen sale **100%**. Sin apertura devuelve `null` y la venta OMITE la columna. Un artículo sin costo NO se abre (§16). |
| **Escribir la apertura con `stockCOGS = NULL`** ("costo sin definir") | La columna lo acepta, pero el NULL **no sobrevive**: al primer movimiento posterior el promedio móvil lo castea a 0 y lo propaga al snapshot nuevo. "Costo sin definir" no es un estado que el ledger sepa sostener (§16). |
| **Un `stockSource` nuevo para la apertura** | Los lectores que filtran por `stockSource` buscan valores concretos y los que lo muestran lo traducen con una tabla cerrada: un valor nuevo sale crudo en pantalla y ningún reporte lo entiende. Se usa `adjustment`, el mismo contrato que el ajuste del panel y que la carga inicial de la planilla. |
| **Meter la apertura dentro del dominio `catalog`** | Un saldo es un movimiento por (artículo, **sucursal**), y las sucursales las mapea `config`, que corre DESPUÉS. Dentro de `catalog` correría sin sucursales mapeadas. Es dominio propio y va último. |
| **INSERT directo en `stock`** para abrir el inventario | `Inventory::manageStock()` es el ÚNICO escritor del ledger (D1/D6 de `context/52`): es quien calcula el promedio ponderado, repostea el historial y publica el evento realtime. |
