# 43 — Sync incremental del POS

**Estado:** implementado 2026-08-16 (backend completo + reconexión del front;
arranque en frío queda con el bootstrap completo por decisión explícita, ver
§Qué quedó afuera).

## Qué reemplaza y por qué

Con conexión y caja en uso, el WS (`context/15-realtime-sync-plan.md`) ya
resuelve "algo cambió, andá a buscarlo" en caliente. Pero al **reconectar**
tras una caída (wifi intermitente, proxy que cierra el socket, tablet que se
durmió), `frontend/lib/realtime.ts` no tiene forma de saber qué se perdió
mientras el WS estuvo caído — `ws-server` es un relay puro sin backlog. La
única respuesta que tenía hasta ahora era invalidar TODO el cache de
TanStack, lo que forzaba un refetch completo de `pos-bootstrap`: con un
tenant de **10.000 clientes y 5.000 productos**, eso son ~15.000 filas
re-descargadas en cada reconexión — inaceptable, y el disparador explícito
de este trabajo.

La solución (pedida por el owner con precedente en su sistema legacy): una
fecha de última modificación **por sección** que el POS compara contra su
propia marca de agua local. Si el server está más adelante, pedir solo lo
que cambió desde esa fecha — no el catálogo entero.

## Las 3 secciones y por qué cada una elige distinto

| Sección | Cardinalidad | Estrategia | Por qué |
|---|---|---|---|
| `items` | 100s–10.000s | Delta por fila (`updated_at` + lápidas) | Volumen real — es el problema que motivó esto |
| `customers` | 100s–10.000s | Delta por fila (`updated_at` + lápidas) | Ídem |
| `settings` | 10s–100s | Refetch completo cuando stale | Bundle chico — un delta por fila sería complejidad sin beneficio |

`settings` agrupa outlet/register/tax/category/brand/tag/payment-method/
printer_binding/user/**document_template** — todo lo que `/api/pos/bootstrap`
trae que NO es items/customers. Ninguna de esas tablas tiene más que unas
decenas de filas por tenant; recargar el bundle completo cuando cambió es
más barato que construirle su propio delta+lápidas.

**`document_template` se sumó al bundle 2026-08-16** (context/08 §53, hueco
P0 de impresión offline): antes `printSale`/`printTicketInBrowser` pedían la
plantilla al server EN EL MOMENTO de imprimir
(`/api/v1/document-templates?id=...`, sin cache ni fallback) — offline, o en
un device sin sesión de operador panel (el catch-all `/api/v1/*` solo
reenvía `_jwt_panel`, nunca el Bearer del device), ese fetch fallaba y el
ticket físico no salía aunque la venta ya se hubiera emitido bien. Cambios:
`api/v1/document-templates.php` pasó a `apiAuthTenant(['panel','pos-app'])`
(device GET-only, mismo patrón que items.php/item_addons.php);
`PosBootstrap.printTemplates` (`lib/types/pos-bootstrap.ts`) se suma al fan-out
de `/api/pos/bootstrap/route.ts`; `fetchTemplateConfig`/
`fetchDefaultTemplateConfig` (`lib/hardware/printers/print-in-browser.ts`)
pasaron de `fetch` a lookup síncrono contra `useCatalogStore.printTemplates`
— resuelven SIEMPRE local, sin fallback a red (justificación completa en el
docblock de ese archivo). `ENTITY_TO_QUERY_KEYS['document-template']` ahora
incluye `["pos-bootstrap"]` para que un template editado en el panel llegue
al device (antes solo invalidaba el listado del editor).

## Decisión 1 — Watermark de `items`/`customers`: DERIVADO, no una tabla aparte

El owner describió su legacy con una tabla de secciones (`items
2026-08-15, customers 2026-08-13, settings 2026-08-01`) que él mismo
actualizaba. Acá, para `items`/`customers`, la respuesta es **`MAX(updated_at)
WHERE companyId = ?`** — ya indexado (`idx_item_updated`,
`idx_contact_updated`, `(updated_at, companyId)`, preexistentes) — en vez de
mantener una tabla de watermarks a mano.

Por qué: una tabla de watermarks separada tiene el MISMO problema de fondo
que los borrados (ver Decisión 2) — depende de que TODO call-site que
mute `item`/`contact` se acuerde de bumpearla. `MAX(updated_at)` no puede
desincronizarse de la realidad porque SE COMPUTA de la realidad — no hay
nada que "olvidar".

`settings` sí usa una marca mantenida (`company.config.settingsLastUpdate`,
mismo patrón legacy que `updateLastTimeEdit()` ya tenía para
`itemsLastUpdate`/`customersLastUpdate`/`calendarLastUpdate`/
`orderLastUpdate`) — pero bumpeada desde un choke point default-on, no desde
call-sites individuales (ver `syncSectionAfterMutation()` en
`api/bootstrap.php`, llamada desde `realtimeAfterMutation()` con el
`$entity` que esa función YA deriva del path para el realtime). Un endpoint
nuevo bajo `/v1/` que mute `outlet`/`register`/`tax`/`category`/`brand`/
`tag`/`payment-method`/`printer_binding`/`user` bumpea el watermark sin que
nadie tenga que acordarse de llamar nada — mismo principio "default-on, no
allowlist" que ya usa el mapa de realtime. `item`/`contact` NO pasan por
acá (su propio `updated_at` ya es su watermark) ni tampoco
`transaction`/`drawer`/`expense`/etc. (ruido de alta frecuencia — una venta
por minuto invalidaría `settings` constantemente sin necesidad).

`TODAY` (usada en cada write de `updated_at`) es `date('Y-m-d H:i:s')` —
**con hora, segundo incluido** — no solo fecha. Se verificó explícitamente
(era el riesgo señalado por el owner: "si es solo fecha, un delta con
granularidad de día es inútil") y no hizo falta arreglar nada ahí.

**Consistencia de reloj — encontrado y corregido.** `SyncService::
watermarks()` devuelve `serverTime` con `TODAY` (reloj del server PHP). Se
auditaron TODOS los writes de `updated_at` de `item`/`contact` para
confirmar que usan el MISMO origen — un `since` generado con el reloj de
PHP comparado contra una fila escrita con el reloj de la DB (`NOW()` de
Postgres) tiene una ventana de carrera si ambos relojes desincronizan, por
chica que sea: el peor bug posible acá es una actualización que se pierde
en silencio. Se encontraron DOS focos usando `NOW()` de Postgres en vez de
`TODAY`: `ItemRepository::archive()` y `ContactRepository::archive()` (el
resto de los writes — `ItemService::update()`/`createBlank()`,
`ContactService::update()`/`create()`, `Inventory.php` vía
`updateRowLastUpdate()`, `ItemImporter`, `VariantService` — ya usaban
`TODAY`). Corregidos para usar `TODAY` parametrizado, igual que el resto.
La convención más amplia del codebase (writes de negocio en hora LOCAL del
tenant, naive — ver el comentario de `timezone` en `lib/types/
pos-bootstrap.ts`) es preexistente y ortogonal a este fix: mientras el
mismo reloj (PHP `TODAY`) escriba y el mismo reloj lea, la comparación
`>` cierra sin importar qué zona horaria representa ese naive string — el
riesgo real era la MEZCLA de dos relojes distintos, no la zona horaria en
sí.

**Extensión 2026-08-17 (`context/45-satelites-item-contact-sync.md`, mig
139):** `MAX(updated_at)` ahora también refleja cambios en tablas
satélite (direcciones/notas de contacto, receta/categoría/marca/tag/
imágenes/add-ons/overrides de precio de ítem, etc.) — un trigger genérico
por satélite bumpea `updated_at` del padre, así este watermark derivado
los cubre sin cambiar de forma. Mismo cuidado de reloj que arriba: el
trigger usa `now() AT TIME ZONE settingTimeZone` (no `now()` a secas) para
no reintroducir la mezcla de relojes que este documento corrigió — el
contrato del delta (shape de la respuesta, endpoint, `since`/`serverTime`)
NO cambió, solo se amplió qué operaciones lo disparan.

## Decisión 2 — Borrados: tabla de lápidas + TRIGGER de DB (no un helper por call-site)

`SELECT ... WHERE updated_at > $fecha` nunca devuelve una fila borrada. Se
relevaron **todos** los `DELETE FROM` sobre entidades que el POS consume
(item, contact, brand, category, tax, tag, register, price_list,
price_list_item, printer_binding, addon_group, combo_group, giftcard — ~15
call-sites repartidos en otros tantos Services, más el wipe completo de
tenant en `CompanyAdminService`). Un helper PHP tipo `updateRowLastUpdate()`
que cada call-site debe recordar llamar tiene el mismo problema estructural
que ya forzó la migración de `manageStock()` a publicar realtime desde
ADENTRO en vez de confiar en 27 callers (`context/15`, hardening
2026-08-15): un call-site nuevo, o código legacy que nadie audita, se
olvida — y un producto borrado por un admin mientras la tablet está offline
queda fantasma para siempre.

**Elegido:** tabla `deleted_row(companyId, entity, rowId, deleted_at)` (mig
138) poblada por un **trigger `AFTER DELETE`** genérico
(`fn_record_deletion()`, parametrizado con el nombre de entity y el nombre
de columna PK vía `TG_ARGV` + `to_jsonb(OLD)`). Un trigger de DB no puede
"olvidarse" — corre pase lo que pase, incluso para código que no existe
todavía o un `DELETE` emitido a mano contra la base. Atado a `item` y
`contact` (las dos entidades que este sync sincroniza por fila).

**Por qué no al resto de la lista relevada:** `brand`/`category`/`tax`/`tag`
viven dentro del bundle `settings` — cuando cambian (incluido un borrado),
un refetch completo del bundle reemplaza la lista entera, así que un
borrado se resuelve solo (el id simplemente no está en la respuesta nueva),
sin necesitar su propia lápida. Mismo razonamiento para
register/price_list/printer_binding/addon_group/combo_group/giftcard — de
cardinalidad baja, sin delta por fila, sin problema de fantasmas.

**El wipe completo de tenant** (`CompanyAdminService::delete()`, borra
`item`/`contact` de UNA — `DELETE FROM item WHERE companyId=?`) SÍ dispara
el trigger (miles de lápidas de una vez), pero es inofensivo: `deleted_row`
tiene `companyId REFERENCES company(companyId) ON DELETE CASCADE`, y esa
misma función borra `company` unas líneas después — las lápidas
desaparecen con el cascade, no quedan huérfanas. Verificado en
Postgres real (ver §Verificación).

**Purga:** pg_cron (mismo patrón fail-tolerant que mig 36,
`purge-tenant-audit`) borra lápidas con más de **90 días** diariamente
(decisión del owner). `SyncService::TOMBSTONE_RETENTION_DAYS` — coordinado
a mano con el SQL de la migración porque pg_cron no puede leer una
constante PHP. La ventana y la regla de abajo son la MISMA decisión, no dos
separadas: si el `since` que manda el POS es más viejo que esa ventana, el
endpoint devuelve `full=true` en vez de confiar en una cobertura de lápidas
que puede haber sido purgada — sin esto, un borrado cuya lápida ya se
purgó quedaría como producto fantasma para siempre en un dispositivo que
estuvo offline más de 90 días.

## Endpoint — `POST /v1/sync`

- `GET  /v1/sync?resource=watermarks` → `{ items, customers, settings, serverTime }`
  (todas fechas ISO-like `Y-m-d H:i:s`, o `null` si la sección está vacía).
  Primera llamada al reconectar — barata (3 queries indexadas/JSONB).
- `POST /v1/sync?section=items` body `{ since }` → `{ items: [...], deletedIds: [...], full, serverTime }`
- `POST /v1/sync?section=customers` body `{ since }` → `{ customers: [...], deletedIds: [...], full, serverTime }`

POST (no GET con `since` en query string) por el mismo criterio que
`?resource=bulk-get` de items.php/contacts.php (context/15): es una
LECTURA, pero un POST no lo cachea el browser ni un proxy intermedio — el
dispositivo siempre recibe el estado fresco. Excluido de
`realtimeAfterMutation()` (`$excluded` en bootstrap.php) — nunca dispara un
evento fantasma.

`companyId` SIEMPRE del JWT (`apiAuthTenant(['panel','pos-app'])`, mismo
guard que items.php/contacts.php) — nunca del body.

`full=true` (items/deletedIds SIEMPRE vacíos en ese caso) cuando `since` es
`null` o excede la ventana de retención de lápidas. Guarda de borde
adicional (Alcance §5): si el delta trae más filas que
`SyncService::MAX_REASONABLE_ROWS` (20.000), también cae a `full` — más
barato recargar todo que aplicar un merge gigante fila por fila en el store
del front.

`buildItemsSelectSql()`/`presentItem()` se extrajeron de `api/v1/items.php`
a `api/lib/Items/ItemsQuery.php` para que el delta comparta el MISMO SELECT
que el listado paginado y el bulk-get — evita que un JOIN nuevo (ej. cuando
se agregó `hasAddons`, context/41) se agregue en dos lugares y se
desincronice en un tercero. `ContactRepository::listUpdatedSince()` +
`ContactService::manyUpdatedSince()` siguen el mismo patrón que
`getManyByIds()` (bulk-get de contactos).

## Marca de agua en el POS — dónde vive y por qué nunca usa el reloj local

`frontend/lib/catalog/sync-watermarks.ts` — `localStorage`, clave
`punto-pos-sync-watermarks:<companyId>`, un objeto `{items, customers,
settings}`. Sobrevive el cierre del browser (a diferencia de
`sessionStorage`/memoria). No es IndexedDB porque acá solo viven 3 strings
chicos — el catálogo en sí sigue en memoria (`useCatalogStore`) + Service
Worker cache (`app/sw.ts`, `NetworkFirst` sobre `/api/pos/bootstrap`).

**Nunca `Date.now()` del dispositivo.** Cada watermark guardado es
literalmente el `serverTime` que devolvió `/v1/sync` (backend, `TODAY` —
hora del server PHP) en la respuesta de ESE sync — nunca calculado en el
cliente. Una tablet con el reloj adelantado que usara su propio reloj se
perdería actualizaciones para siempre (todo delta futuro comparado contra
una fecha "del futuro" nunca trae nada); con el reloj atrasado, pediría el
catálogo completo en cada sync (todo le parece "cambiado"). El código lo
trata como opaco: lo persiste y lo reenvía tal cual, nunca lo parsea ni lo
compara con `Date.now()`.

## Cableado — reconexión sí, arranque en frío no (esta sesión)

`frontend/lib/catalog/delta-sync.ts::runDeltaSync()` reemplaza, en
`hooks/use-realtime-sync.ts`, el `qc.invalidateQueries()` a ciegas que
corría en cada reconexión del WS (`subscribeReconnect`, `clientScope ===
"pos"`). El store de catálogo ya está caliente en memoria en ese momento
(si no lo estuviera, no habría nada que reconectar) — el delta se mergea
ahí mismo con `patchItems`/`removeItems`/`patchCustomers`/`removeCustomers`,
los MISMOS métodos que ya usa el sync realtime quirúrgico
(`lib/catalog/realtime-catalog-sync.ts`, context/15) para no duplicar el
mecanismo de merge. El panel sigue con el invalidate-todo viejo (no tiene
el problema de volumen que motivó este cambio).

**Instalación nueva / primer arranque:** sin watermark local (`localStorage`
vacío), `runDeltaSync` no se ejecuta hasta la primera reconexión —
`useCatalogStore.getState().config?.companyId` recién existe después del
bootstrap completo inicial. Ese bootstrap completo (`usePosBootstrap()`, sin
cambios) ES el camino de la primera vez, aceptado explícitamente por el
owner. Al terminar, `useCatalogSeed` llama `primeWatermarks()` — un `GET
/v1/sync?resource=watermarks` que graba las 3 marcas ni bien el bootstrap
completo termina, así la PRIMERA reconexión después de instalar YA es
delta, no necesita un ciclo completo antes.

**Falla del delta:** cualquier error de red en `runDeltaSync` (o
`full=true` de una sección) cae a `qc.invalidateQueries({queryKey:
["pos-bootstrap"]})` — mismo fallback que ya usa
`realtime-catalog-sync.ts` para el sync quirúrgico. Nunca deja al POS
"medio sincronizado" en silencio.

## Arranque SIN RED (2026-08-23)

Lo de arriba describe el arranque **con** conexión. Desde 2026-08-23 el POS
también arranca sin ella: `usePosBootstrap()` sirve un snapshot del bootstrap
persistido en IndexedDB cuando la red no responde
(`frontend/lib/pos/bootstrap-source.ts` → `bootstrap-cache.ts`). La caja abre,
busca, cobra e imprime con datos que pueden estar viejos — se muestran, no se
esconden. Detalle completo en `context/16-app-next-rewrite.md` §5.

Tres cosas que este documento necesita saber de eso:

1. **Las marcas de agua NO se priman con datos cacheados.** `useCatalogSeed`
   llama `primeWatermarks()` solo cuando el bootstrap vino de la RED
   (`catalogFromCache === false` en `offline-sync-store`). Primarlas con un
   snapshot sería afirmar "ya tengo todo lo que el server tenía hasta este
   instante" sobre una sincronización que nunca ocurrió: el device se saltearía
   **para siempre** todos los cambios ocurridos entre el guardado del snapshot y
   la reconexión.

2. **Al volver la red, el catálogo se pone al día por el camino de siempre.** El
   poll de sesión de `usePosBootstrap` (60s) y `refetchOnReconnect` traen el
   bootstrap fresco; la reconexión del WS dispara `runDeltaSync` con la marca de
   agua que haya. Nada nuevo en este mecanismo.

3. **Desde el snapshot se hidrata UNA sola vez.** Offline la query se re-resuelve
   cada 60s devolviendo el mismo snapshot con un `dataUpdatedAt` nuevo. Sin un
   corte explícito, la guarda de frescura de `useCatalogSeed` lo tomaría por
   "más fresco que el último patch" y volcaría el snapshot sobre el store cada
   minuto — borrando los descuentos de stock optimistas de cada venta offline y
   cualquier ítem/cliente tocado durante el corte. Datos viejos pisando datos
   nuevos. Por eso `if (fromCache && status !== "idle") return`.

El pendiente que sigue abierto es el del bloque de abajo: pedir un **delta** en
el arranque en frío en vez del bootstrap completo. El snapshot offline no lo
resuelve — lo hace menos urgente, porque el arranque ya no depende de que la
request salga bien.


## Qué quedó afuera (decisión explícita, no olvido)

- **Delta en el arranque en frío.** "Al arrancar el POS" (cold start: app
  recién abierta o página recargada) sigue pidiendo `/api/pos/bootstrap`
  completo, sin cambios — el store de Zustand se resetea en cada reload
  (no hay persistencia del catálogo, `store.ts` lo marca como TODO desde
  antes de esta sesión: *"Fase offline: persistir en IndexedDB (Dexie)"*)
  y un delta sin una base local sobre la cual aplicar el merge dejaría el
  catálogo con SOLO los ítems recién cambiados, perdiendo el resto. Activar
  esto de verdad necesita: (1) persistir `items`/`customers` en IndexedDB
  (`idb`, ya es dependencia del proyecto vía `lib/pos/offline-queue.ts` —
  no hace falta sumar Dexie), y (2) resolver el `companyId` ANTES del
  primer fetch — ya existe un candidato (`lib/auth/device-claims.ts`,
  guardado en el pairing del dispositivo, disponible sync sin red). La
  marca de agua SÍ queda primada (`primeWatermarks`) apenas termina el
  bootstrap de arranque, así que el trabajo que falta es puramente de
  persistencia del catálogo, no de watermarks — queda como el próximo paso
  natural, no una reescritura.
- **Sección `settings` sin delta propio.** Decisión permanente (no un
  recorte de esta sesión), ver Decisión 1 — el bundle es chico, un refetch
  completo es proporcional.
- **`price_list`/`price_list_item`** no forman parte del bundle `settings`
  ni tienen lápida propia — A PROPÓSITO, no un olvido (ver comentario en
  `syncSectionAfterMutation()`, `api/bootstrap.php`). El gap de invalidación
  EN CALIENTE (editar una lista no re-resolvía el carrito abierto en el POS)
  se cerró 2026-08-16 — ver `context/15-realtime-sync-plan.md` §Fix listas
  de precios. Lo que sigue sin resolver es la RECONEXIÓN/arranque offline:
  `price_list_item` puede tener tantas filas como el catálogo (un override
  por producto, por lista) y se reescribe ENTERO en cada guardado
  (`PriceListService::setItems()`, DELETE+INSERT sin `updatedAt` por fila) —
  no encaja ni en el modelo por-fila de `item`/`contact` (esta tabla no tiene
  esa columna) ni en el bundle `settings` (volumen, ver arriba). Plan de
  delta propio (watermark por LISTA, no por fila — granularidad real de
  `setItems()`) + bajada al bootstrap para operar sin red: planificado, no
  implementado, ver `context/44-listas-de-precio-offline.md`.

## Referencias

- `context/15-realtime-sync-plan.md` — el camino complementario: cambios
  EN CALIENTE (WS, con conexión y caja en uso). Este documento cubre
  reconexión/arranque — los dos caminos coexisten sin superponerse (WS
  nunca dispara para `pos-bootstrap` en el POS, ver `use-realtime-sync.ts`
  §Modelo quirúrgico).
- Migración: `api/database/migrations/postgres/138_sync_incremental.sql`.
- `context/45-satelites-item-contact-sync.md` (mig 139) — trigger genérico
  que bumpea `item`/`contact` desde sus tablas satélite, así este
  watermark derivado también cubre esos cambios.
- Backend: `api/v1/sync.php`, `api/lib/Sync/SyncService.php`,
  `api/lib/Items/ItemsQuery.php` (extraída), `ContactRepository::
  listUpdatedSince()`/`ContactService::manyUpdatedSince()`,
  `syncSectionAfterMutation()` en `api/bootstrap.php`.
- Frontend: `frontend/lib/catalog/sync-watermarks.ts`,
  `frontend/lib/catalog/delta-sync.ts`, `frontend/app/api/pos/sync/route.ts`,
  wiring en `hooks/use-realtime-sync.ts` y `hooks/use-catalog-seed.ts`.
- Arranque sin red: `frontend/lib/pos/offline-db.ts` (dueño del schema
  IndexedDB), `bootstrap-cache.ts` (snapshot), `bootstrap-source.ts` (política
  red/cache/nada), `hooks/use-pos-bootstrap.ts`. Tests en
  `frontend/lib/pos/__tests__/offline-boot.test.ts` (`npm test`).
- Arnés: `api/lib/Sales/verify_chain/verify_sync.php` (paso 3.6 de
  `run.sh`) — modifica un item y pide el delta desde una fecha anterior
  (trae solo ese item), archiva+hard-delete otro (aparece en
  `deletedIds`), y prueba `full=true` para `since=null` y `since` fuera de
  la ventana de retención.
