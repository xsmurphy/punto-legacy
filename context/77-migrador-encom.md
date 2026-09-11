# 77 — Migrador ENCOM → Punto

> Estado: **F1 IMPLEMENTADA** 2026-09-11. La capa de EXPORT se reescribió el
> mismo día sobre `POST /fetchs` (branch `api/migrador-fetchs`): ver §4, que
> reemplaza al scraping de pantallas con el que arrancó la F1.
> D1-D6 cerradas por el owner, no relitigar.
> **F2 (HISTÓRICO) implementada 2026-09-11** — ventas con sus líneas, compras
> y movimientos de caja, como REGISTRO CONTABLE. Ver **§17**, que reemplaza al
> "no se migra" de §12. Lo primero que hay que leer ahí es §17.1: el histórico
> NO toca stock, caja, numeración fiscal ni FE, y por eso es la ÚNICA parte
> del migrador que no pasa por los servicios de negocio.
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

> ### ⚠ El legacy son DOS hosts, no uno
>
> | Host | Qué sirve | De dónde sale |
> |---|---|---|
> | `panel.encom.com.py` | login, home, costos, **todo el histórico** | `ENCOM_MIGRATION_URL` |
> | `app.encom.com.py` | **`/fetchs`** (el bootstrap del POS) | **derivado** del `?i=` del alcance |
>
> Verificado en vivo: `/fetchs` contesta JSON en `app.` y **404 en `panel.`**.
>
> **Incidente del 2026-09-11** (job `c5747625…`, company `01a081dd…`): el
> cliente tenía UNA sola base y `post('/fetchs?load=…')` la resolvía contra
> `ENCOM_MIGRATION_URL`, o sea contra el panel. Resultado en la base de
> producción:
>
> - 4 errores reales —`outlets`, `users`, `customers`, `settings`, todos
>   "El legacy respondió 404 en /fetchs?load=…&gtoken="—;
> - **cero filas en `migration_map`**: no se mapeó nada;
> - **512 errores derivados** ("Venta X: la sucursal OLIVA no está migrada")
>   que enterraron esos cuatro. El histórico sí había traído datos (300 ventas,
>   207 compras) porque usa el PANEL, que es otra superficie y sí respondía.
>
> El arreglo tiene tres partes, y las tres importan: **(1)** dos propiedades con
> nombre —`panelUrl` y `posUrl`— en vez de una `baseUrl` ambigua, con el
> transporte hablando en URLs absolutas para que ninguna base quede implícita;
> **(2)** el origen del POS se DERIVA (§4.3) y, si no se puede, el job **falla
> ahí** sin importar nada; **(3)** el error de red ahora incluye el **host**, no
> solo el path — el 404 original decía `/fetchs?load=outlets` y ocultaba lo
> único que importaba.

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

**Y de la misma URL sale el host del POS.** Ese `Location` es literalmente
`https://app.encom.com.py/?i=<base64>`, y el href del botón "Caja" también es
absoluto al POS: el origen (esquema + host, sin path) se captura en el mismo
match que el `?i=` y se usa como base de TODOS los `/fetchs`. Por eso **no hay
una env var nueva** para el POS: el dato ya venía en la respuesta, y una
segunda variable sería un segundo lugar donde equivocarse —y uno que soporte
tendría que cargar a mano por cada cliente—.

Si **ninguna** vía da un par completo, el cliente **LANZA** y el job no se
crea. Es deliberado: seguir con un companyId vacío haría que `/fetchs`
devolviera el catálogo de otro comercio —o de ninguno— y el job lo importaría
sin una sola señal. El error sale en la pantalla del alta, con el operador
mirando, no media hora después dentro del worker.

**Si sale el par pero NO el origen** (un deploy que sirviera el enlace
relativo), también LANZA, y lo dice nombrando el POS. Nunca se cae de vuelta al
panel: eso es exactamente lo que produjo el incidente. Mismo criterio en
`fetch()`, que falla cerrado si `posUrl` está vacío — el fallback silencioso al
panel no existe en ninguna capa.

El alcance se guarda en `migration_job.credentials.scope`, junto a las cookies
y con su misma vida útil: se borra cuando el job termina. Desde el arreglo,
`scope` tiene **tres** campos (`companyId`, `outletId`, `posUrl`); un job viejo
con solo dos lo vuelve a resolver con la misma sesión (`fromCookies()` trata al
`posUrl` faltante como alcance incompleto), así que no quedan jobs a mitad de
camino pidiendo `/fetchs` contra el panel.

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

- ~~**Ventas históricas (F2).**~~ **Se migra desde 2026-09-11** — decisión del
  owner, junto con compras y movimientos de caja. Sigue siendo cierto que
  `/fetchs` no lo expone (es el bootstrap de una caja, no un reporte) y que
  una venta importada NO puede pasar por `SaleService::save()`; eso dejó de
  ser una pregunta abierta y pasó a ser el diseño. Ver **§17**.
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

## 17. El HISTÓRICO (F2) — 2026-09-11

Decisión del owner: se importa el histórico de **ventas (con sus líneas),
compras y movimientos de caja** como **REGISTRO CONTABLE**. Alimenta reportes,
balance y cuentas por cobrar/pagar.

### 17.1 La regla que manda sobre todo

El histórico **NO TOCA**: stock, caja/arqueos, numeración fiscal de Punto, ni
facturación electrónica. Son hechos que YA ocurrieron en otro sistema; acá
solo se registran para poder leerlos.

De ahí sale todo lo demás, incluida la excepción al D4:

> **No se usa `SaleService::save()`.** Ese camino asigna un número nuevo de
> `document_sequence`, encola FE, y mueve stock y caja — exactamente las
> cuatro cosas prohibidas. Llamarlo con una fecha vieja no produce un asiento
> histórico: produce una **venta nueva con fecha vieja**, que le rompe la
> serie fiscal al comercio y le descuadra el inventario.

La operación es OTRA, así que el camino es otro: `EncomHistoryImporter`, en su
propio archivo —para que la excepción no se lea como la regla— y acotado a
insertar los hechos. Sigue usando `ncmInsert()` (el helper canónico, que
resuelve los nombres reales de columna contra el schema) y el mecanismo de
rollups de siempre: lo que se saltea es la lógica de NEGOCIO de vender, no la
capa de datos.

### 17.2 Tres dominios, no uno

`sales_history`, `purchases_history`, `expenses_history`. Salen de endpoints
distintos y con formas distintas, escriben cosas distintas (un movimiento de
caja no es una `transaction`: va a `expenses`), y el operador tiene que poder
pedir uno sin los otros. Van al final del orden: un asiento referencia
artículos, clientes, usuarios y sucursales, y esos mapas los llenan los
dominios anteriores.

**No están en la selección por defecto de la UI**, y es la única excepción a
"todo tildado". No es una duda sobre si conviene traerlos: el legacy no tiene
endpoint de líneas por rango para las ventas, así que hay que pedirle el
detalle de CADA venta, una por una y paceada. Un comercio con miles de ventas
al año es un job de horas, y eso se elige a sabiendas.

### 17.3 Particionado — mig 221, con evidencia

`transaction` e `itemSold` están particionadas por mes (mig 156) y tienen
partición DEFAULT. **Verificado empíricamente contra Postgres real**: un
INSERT con fecha vieja **no falla**, cae en la DEFAULT. El problema es que se
queda ahí para siempre — `ensure_month_partitions()` ancla su cobertura en la
partición mensual más vieja YA CREADA y, *a propósito*, no se deja empujar
hacia atrás por los datos (para que una fecha basura no genere años de
particiones vacías).

O sea: un año de histórico caería entero en la DEFAULT, ninguna corrida futura
lo reclasificaría, y toda consulta por un mes viejo la escanearía. El
particionado (E1 de `context/48`) anulado justo para el comercio que más filas
trajo.

La mig 221 separa los dos casos que hasta ahora compartían mecanismo:

| Caso | Qué pasa |
|---|---|
| Fecha vieja SUELTA (tipeo, dato basura) | No mueve la cobertura. `ensure_month_partitions()` intacta. |
| RANGO conocido que un operador pidió importar | Crea sus meses, con `ensure_month_partitions_range()`. |

El cuerpo (dropear FK → DETACH de la default con `lock_timeout` → mover filas
→ re-attach → recrear FK) vive **una sola vez**: la función por rango es el
motor y la de siempre delega en ella, con la misma firma y el mismo resultado.
Copiarlo hubiera garantizado que las dos diverjan en el primer fix.

Tope duro de **120 meses**: un rango mayor es casi siempre una fecha mal leída
del origen, y ahí falla fuerte en vez de crear 672 particiones (verificado).

**Los límites se anclan en UTC explícito**, y eso es una corrección, no un
detalle. Un `date` convertido a `timestamptz` se interpreta en la zona de la
SESIÓN, y el importador fija la del tenant antes de escribir: con
America/Asuncion el límite superior de agosto caía 4 horas DENTRO de
septiembre y Postgres rechazaba la partición por solaparse. Lo encontró el
arnés, no el diseño. El mes de una partición es una decisión de
ALMACENAMIENTO: tiene que dar el mismo resultado corra quien corra.

**Que las particiones vivas ya estén en UTC NO es un supuesto: está
verificado contra PRODUCCIÓN el 2026-09-11.** El `code-reviewer` lo levantó
como riesgo —si alguna partición existente tuviera límites en otra zona, el
próximo mes chocaría por solapamiento— y se midió:

1. Todas las particiones de `transaction` **y** de `itemsold` están ancladas
   en UTC: `FOR VALUES FROM ('2026-08-01 00:00:00+00') TO ('2026-09-01
   00:00:00+00')`, y así el resto.
2. La query de deriva devolvió **0 filas**: no hay una sola partición con
   límites fuera de UTC.
3. `transaction_default` e `itemsold_default` tienen **0 filas** cada una.

La query queda escrita para que cualquiera la re-ejecute en otro ambiente
**antes de deployar** — el hecho da bien hoy y en esta base, pero la próxima
persona no tiene por qué creernos:

```sql
-- OJO: `pg_get_expr` RENDERIZA los límites en la zona de la SESIÓN. Sin esta
-- línea, una partición perfectamente anclada en UTC se ve como '-04' y la
-- query de abajo da un FALSO POSITIVO de deriva.
SET TIME ZONE 'UTC';

-- Particiones con límites que NO están en UTC (excluyendo la DEFAULT).
-- Esperado: 0 filas. Si devuelve algo, NO deployar: ese mes va a chocar.
SELECT p.relname AS tabla,
       c.relname AS particion,
       pg_get_expr(c.relpartbound, c.oid) AS bound
  FROM pg_class p
  JOIN pg_inherits i ON i.inhparent = p.oid
  JOIN pg_class c    ON c.oid = i.inhrelid
 WHERE p.relname IN ('transaction', 'itemsold')
   AND pg_get_expr(c.relpartbound, c.oid) <> 'DEFAULT'
   AND pg_get_expr(c.relpartbound, c.oid) NOT LIKE '%+00%';

-- Y cuántas filas hay en las DEFAULT (ver §17.3.1: cambia el costo operativo).
SELECT count(*) FROM transaction_default;
SELECT count(*) FROM itemsold_default;
```

### 17.3.1 Cuándo un import histórico pide ventana de mantenimiento

Depende de **una sola cosa: si la partición DEFAULT tiene filas.**

**Con la DEFAULT vacía** —el caso de producción hoy, medido arriba— crear un
mes es barato: no hay nada que mover, así que el `DETACH`/`ATTACH` ni siquiera
se dispara. Y el importador asegura las particiones **antes** de insertar, así
que tampoco se llega a llenar. Se puede correr contra un tenant vivo.

**Con filas en la DEFAULT** es otra operación. Para declarar el mes hay que
desprender la DEFAULT, mover las filas y volver a pegarla, y eso toma
**ACCESS EXCLUSIVE sobre `transaction` hasta el COMMIT**. Mientras dure, las
ventas concurrentes **no fallan: ENCOLAN** — que desde la caja se ve como el
POS colgado. El `lock_timeout` de 5s protege del caso inverso (que el POS
bloquee al job), no de este.

O sea: **correr un import histórico contra un tenant vivo con filas en la
DEFAULT es una operación de ventana de mantenimiento**, no un job más. Antes
de lanzarlo, la segunda query de arriba dice en cuál de los dos mundos estás.

### 17.4 Cierre de período

`fn_period_guard` (mig 157) es **BEFORE UPDATE OR DELETE únicamente** — un
INSERT en un mes cerrado entra sin que nada lo frene (es deliberado:
offline-first, el back nunca rechaza una venta ya emitida). **La base NO
protege al comercio de que una migración le reescriba un mes conciliado.**

Así que el chequeo lo hace el importador con `period_is_closed()`, **antes de
insertar y por MES entero**: se salta el mes completo con un mensaje claro, en
vez de dejar medio mes importado.

### 17.5 Rollups

Los reportes leen rollups pre-agregados, no la tabla de hechos: sin recalcular,
el histórico no aparece en ningún lado. Cada asiento marca su día con
`rollupMarkDirty()` y al final del dominio se drena con `rollup_reconcile()`
—el mismo motor del job de mantenimiento— en tandas acotadas y con techo, para
que un histórico grande no deje al worker recalculando sin fin.

### 17.6 Idempotencia y reanudación

Por mes, y con marca en `migration_map` (`sale_history` / `purchase_history` /
`expense_history`) **en la misma transacción que el asiento**. Sin eso, un
worker que muere entre las dos escrituras deja la venta sin marcar y la
corrida siguiente la asienta de nuevo: el comercio vería el doble de
facturación. Es el mismo riesgo que la apertura de stock (§16.4), y acá
también duplicar es plata.

**Supuesto que sostiene esto** (levantado por el `code-reviewer`): que hay UN
worker por empresa, que es lo que garantiza el índice único parcial de
`migration_job` (§6). `remember()` usa `ON CONFLICT DO NOTHING` y no comprueba
si insertó, así que si alguna vez dos workers procesaran el mismo job en
paralelo, uno podría dejar una `transaction` ya commiteada sin su marca —y la
corrida siguiente la duplicaría—. La idempotencia protege el RE-CORRER, no el
correr en paralelo.

### 17.7 Referencias que no resuelven

- **Artículo**: mapa por id → SKU → nombre normalizado → **artículo HISTÓRICO
  archivado** (`[Histórico] <nombre>`, `itemStatus = 0`, no vendible). Existe
  para que los totales CIERREN: `itemsold.itemid` es NOT NULL, y descartar la
  línea dejaría una venta cuyo total no coincide con la suma de sus ítems.
  Todos los lectores del catálogo activo filtran `itemStatus = 1`, así que no
  aparece en el POS. Queda nombrado en la bitácora.
- **Cliente**: la venta entra SIN cliente y al log. No se inventan contactos.
- **Usuario y sucursal**: la venta **no entra**. `transaction.userid` y
  `.outletid` son NOT NULL, y resolverlos con "el primero activo" le
  atribuiría ventas a quien no las hizo (memoria del proyecto: prohibido
  resolver una dimensión faltante adivinando). El log dice qué migrar.

### 17.8 Anuladas

Se marcan con **`voidedAt`** (mig 154), que es lo que los rollups miran para
excluirlas (mig 155) — no con `transactionType = 7`, que las sacaría de los
reportes por otro camino y les borraría el tipo real.

### 17.9 COGS — se ESCRIBE, y es una aproximación declarada

**`itemSoldCOGS` se escribe en cada línea importada** (corrección del owner,
2026-09-11). No alcanza con "el margen se calcula después": los reportes leen
el costo **congelado por línea**, no lo recalculan, así que con la columna
vacía el margen histórico sencillamente NO EXISTE.

**Qué costo se escribe**: `item.itemCost` del artículo ya migrado — el costo
**ACTUAL**, no el del día de la venta, porque el legacy no expone el costo de
cada venta (verificado: el form trae cantidad, precio, IVA y total, nada de
costo). **El margen histórico es entonces una aproximación conocida, no el
dato original**, y está dicho acá y en la UI del job.

Sale de `item.itemCost` y no del promedio ponderado del ledger a propósito: es
el que el propio migrador escribió (§4.4) o el que soporte cargó después, y
**no depende de que el dominio de apertura de stock haya corrido**. Con la
apertura corrida los dos valen lo mismo.

**El contrato es el de `SaleService`, copiado, no reinventado**:

- La columna guarda el costo **UNITARIO**, no el de la línea. Es lo que
  devuelve `resolveUnitCOGS()` y lo que persiste `persistItemsAndStock()`, sin
  multiplicar por la cantidad.
- El valor pasa por **`flipOnReturn()`**, que es no-op para los tipos que
  importa el histórico (0/3 venta, 1/4 compra) y solo invierte el signo en la
  devolución (tipo 6). Se llama igual para que el contrato quede literal y no
  haya que acordarse de esto si alguna vez se importan devoluciones.
- **Un artículo sin costo conocido deja la columna en NULL, nunca en 0.** Y no
  se escribe `null`: se **OMITE** del insert, porque `flipOnReturn(null)`
  devuelve **0** — y un 0 se lee como "costó nada", o sea **margen 100%** para
  siempre en ese artículo. Es el mismo criterio de §16.3.

Esas líneas quedan **nombradas en la bitácora del job**. Ojo con una asimetría
que conviene tener presente: cargarle el costo al artículo después **no las
arregla**, porque el asiento ya está escrito y la reanudación no lo
re-importa. Si importa que el margen figure, el costo tiene que estar cargado
ANTES de correr el histórico.

### 17.10 Supuestos que quedan

1. **Los nombres de los inputs del detalle de venta** (`itemQty[<id>]`,
   `itemPrice[<id>]`). El nombre del artículo NO está en un input sino en una
   celda, y se empareja con su línea **por orden de aparición**. Si el legacy
   desordenara uno de los dos lados, el nombre saldría corrido — por eso
   ninguna línea se descarta en silencio.
2. **Las columnas de compras** están relevadas parcialmente. Se resuelven por
   ENCABEZADO con palabras clave, que tolera columnas nuevas o de más.
3. **Cómo marca el legacy una anulada** (se busca `ANUL`/`CANCEL` en Tipo y
   Tipo Documento) y **contado vs crédito** (`CREDITO`).

### 17.11 Arquitecturas rechazadas — no reintroducir

| Arquitectura | Por qué se rechazó |
|---|---|
| **Importar el histórico por `SaleService::save()`** | Numera con `document_sequence`, encola FE, mueve stock y mueve caja. Es una venta nueva con fecha vieja, no un asiento. |
| **Una columna `source` nueva en `transaction`** | No existe, y la única parecida —`channel`— tiene CHECK cerrado (`mostrador|mesa|delivery`): inventarle un valor es el error que el proyecto ya documentó con `stockSource`. La convención real para "vino de una migración" es `migration_map`, que además da idempotencia; en la fila queda `meta.importedFrom`. |
| **Dejar que las filas viejas caigan en la partición DEFAULT** | No se reclasifican nunca (§17.3) y anulan el particionado para el comercio migrado. |
| **Hacer que `ensure_month_partitions()` mire los datos para ir hacia atrás** | Es justo lo que la mig 156 evitó a propósito: una fecha basura generaría años de particiones vacías. El rango se declara, no se deduce. |
| **Copiar la lógica de creación de particiones en una función nueva** | Es una danza de FK + DETACH + mover + re-attach: dos copias divergen en el primer fix. El motor es uno y la función vieja delega. |
| **Confiar en que la base rechace un INSERT en período cerrado** | El guard es BEFORE UPDATE OR DELETE. El INSERT entra. El chequeo es del importador (§17.4). |
| **Poner `itemSoldCOGS = 0` cuando el legacy no da el costo** | Margen 100% en todos los reportes del período. Mismo argumento que §16.3. |
| **Descartar la línea cuyo artículo no matchea** | El total de la venta deja de cerrar contra la suma de sus ítems. Entra un artículo histórico archivado y se nombra en la bitácora. |
| **Ponerle un usuario cualquiera a la venta cuyo usuario no está migrado** | `userid` es NOT NULL, pero completarlo con otro le atribuye ventas a quien no las hizo. La venta no entra y el log dice qué falta. |
| **Traer "todo el histórico" sin rango** | El legacy no dice desde cuándo tiene datos, y son decenas de miles de requests (una por venta) más años de particiones. El rango lo elige el operador; sin elección, 12 meses. |
| **Dejar que un dominio dependiente emita un error por fila cuando el prerequisito no está** | Es el incidente del 2026-09-11: 512 líneas de "la sucursal no está migrada" enterrando los 4 errores que eran la causa. Ver §17.12. |
| **Cortar "a los N errores iguales"** | Sigue nombrando el síntoma (solo que menos veces), ya pagó N requests paceadas contra el legacy, y obliga a elegir un N y a clasificar mensajes por parecido. La condición real es binaria. Ver §17.12. |

### 17.12 El prerequisito: por qué el histórico se fija ANTES de iterar

**El incidente.** El job `c5747625…` terminó `failed` con **512 errores**, todos
de la forma "Venta X: la sucursal OLIVA no está migrada". La causa real eran
**cuatro**: `/fetchs` pedido contra el host equivocado (§4), que dejó
`migration_map` sin una sola fila. Quien miraba el job veía 512 veces el
síntoma y no tenía ningún camino hasta el 404.

Cada uno de esos 512 errores era, por separado, correcto: una venta sin
sucursal no puede entrar y el motivo estaba bien dicho. El problema es de
AGREGADO — un job cuya causa raíz está sepultada bajo cientos de líneas
derivadas es un job que no se puede diagnosticar.

**El mecanismo.** `EncomHistoryImporter::assertPrerequisitos()` corre al
principio de los tres dominios de histórico, antes de iterar y antes de pedirle
nada al legacy: si no hay NINGUNA sucursal —ni en `migration_map`, ni en el
destino, que son las dos fuentes con las que `mapOf()` resuelve— aborta el
dominio con un error que nombra el prerequisito y manda a mirar `config`.

**Por qué esto y no un corte por repetición**: nombra la causa en vez del
síntoma, se evalúa con dos `count(*)` locales en vez de gastar N requests
paceadas a 60/min (y en ventas es una request POR VENTA), y no necesita elegir
un umbral ni comparar mensajes por parecido.

**Lo que NO cambia**: el error por fila sigue existiendo. Una sucursal suelta
que no resuelve —el comercio tiene tres y el legacy nombra una cuarta— es
información legítima de ESA venta. Lo único que se corta es el caso en que el
dominio entero era imposible desde antes de empezar.
