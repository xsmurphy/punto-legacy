# 63 — Conteo de stock en la caja

> Estado: **F0+F1+F2 implementadas** — F0/F1 el 2026-09-02 (conteo ciego en
> `/pos/conteo`, offline-nativo, permiso `pos.stock.count` contra el operador
> del PIN, migs 186/187); **F2 el 2026-09-06** (conteo NO ciego, mig 193 —
> ver §F2 abajo). Fecha del plan original 2026-09-01. D1-D4 y
> D9 cerradas por el owner, no relitigar. El resto de este doc son
> derivaciones técnicas de esas decisiones — implementación propuesta,
> discutible en el cómo, no en el qué.
> El motor de conteo YA EXISTE y está implementado (`InventoryCountService`,
> tablas `inventory_count`/`inventory_count_item`, panel) — este plan no lo
> construye, lo lleva a la caja. Ver §Estado del código.
> Origen: pedido del owner — muchos comercios quieren que el cajero cuente
> stock en la caja (sobre todo comida rápida con producto terminado en
> mostrador) y el cajero no tiene acceso al panel.

## El problema (palabras del owner)

En comercios de comida rápida, con producto terminado en mostrador, el dueño
quiere que el cajero pueda contar el stock desde la caja — típicamente al
abrir o cerrar turno — sin depender del panel, al que el cajero no entra.

**Los conteos son independientes entre sí, y esto define el diseño.** Textual:

> "Con relación al conteo se hacen de forma independiente, ningún conteo
> depende de otro. Pero todo queda registrado. Yo puedo contar a la mañana al
> comenzar y al cerrar cuenta otro cajero sin saber si yo hice o no el conteo
> ni qué resultado arrojó. Pero eso sí, internamente cada conteo finalizado
> debe quedar detallado en el panel con sus diferencias. También debe ser
> configurable la opción de que al finalizar el conteo se modifique el stock
> o simplemente queda como registro sin modificar el stock."

No hay relevo con acuerdo entre dos cajeros: cada conteo es un evento
autónomo, de una sola persona, ciego a lo que haya pasado en el conteo
anterior. Lo único constante es que todo conteo finalizado queda registrado
con el detalle de sus diferencias, visible en el panel.

## El modelo — el arqueo de caja sirve para UNA sola cosa

La analogía con el arqueo de efectivo (`context/51`) sigue sirviendo para
contar contra lo que el sistema espera y registrar la diferencia. No sirve
para el resto del molde: el arqueo de caja encadena apertura y cierre (el
fondo que uno declara lo hereda el siguiente) y pide conformidad de quien
recibe el turno. El conteo de stock no hace ninguna de las dos cosas.

| Arqueo de caja | Conteo de stock |
|---|---|
| Esperado = lo que el sistema calcula | Esperado = `onHand` del ledger (`context/52` D1) |
| Diferencia = sobrante/faltante | Diferencia = columna generada de `inventory_count_item` |
| Cierre a ciegas = sin ver el esperado | Conteo ciego = sin ver el esperado (D2), configurable |
| Encadena apertura/cierre, hereda el fondo | Cada conteo es independiente, sin herencia |
| Firma quien cierra + quien abre después | Solo queda quién lo hizo (`startedBy`/`finishedBy`) |

Un conteo puede anotar en qué turno ocurrió como dato de contexto, pero no
depende de otro conteo ni lo condiciona.

## Decisiones — cerradas por el owner

- **D1 — Por default, el conteo aplica el ajuste al instante.** Al confirmar,
  el stock se corrige solo, sin esperar aprobación del dueño; la diferencia
  queda registrada con el nombre de quien contó. Matizada por D9: el comercio
  puede configurar que el conteo NO toque el stock y quede solo como
  registro.
- **D2 — Conteo ciego, pero CONFIGURABLE.** El modo ciego (el operador no ve
  el stock teórico mientras cuenta) es el default recomendado: si el cajero
  ve lo que el sistema espera, escribe lo que el sistema espera. El comercio
  tiene que poder apagarlo.
- **D3 — El alcance es una lista fija que define el dueño.** El dueño arma
  una vez qué se cuenta en cada turno (lo del mostrador) y el cajero solo la
  completa. Repetible, rápido, comparable entre turnos. Puede haber más de
  una lista si el mostrador cambia por horario.
- **D4 — Es opcional por comercio.** Módulo activable, como Órdenes y
  Espacios. Un comercio que no lo necesita no lo ve.
- **D9 — Configurable si el conteo aplica el ajuste o queda solo como
  registro.** Flag de tenant, hermano de `stockCountBlind` — probablemente
  convenga que los dos vivan juntos en la misma sección de Ajustes. Afecta
  también al conteo del PANEL, no solo al de la caja.

## Estado del código (verificado)

**El motor ya existe, no hay que construirlo:**

- Tablas `inventory_count` (sesión) e `inventory_count_item` (líneas, con
  `difference` como columna GENERADA) — mig `46_inventory_count.sql`. La mig
  `158_inventory_count_scope.sql` agregó `scope` jsonb.
- `api/lib/services/InventoryCountService.php`: `create`, `preview`,
  `setCountedQty`, `bulkSetCountedQty`, `finish`, `cancel`. `finish()`
  (línea 280) toma `FOR UPDATE`, selecciona solo líneas con diferencia
  distinta de cero, y por cada una llama `Inventory::manageStock()` con
  `source='inventory_count'` — el ÚNICO escritor de stock del sistema
  (`context/52` F1-F4). Decisión ya documentada ahí: `countedQty = NULL` al
  finalizar se trata como "sin diferencia", no se ajusta a 0.
- `conteo` es un docType con correlativo por sucursal (mig 129).
- El esperado sale de `SUM(stockcount)` del ledger vía `InventoryCountScope`,
  ya migrado al modelo de `context/52`.
- Panel: `frontend/app/(panel)/inventory-count/` (listado + detalle).

**Cuatro bloqueos para llevarlo a la caja:**

1. **El endpoint es panel-only.** `api/v1/inventory_count.php:21` es
   `apiAuthTenant(['panel'])` — el realm `pos-app` no puede llamarlo. Todo
   POST exige el permiso `inventory.stock.adjust`, que es de PANEL, y
   `unlock-pin.php:130` lo filtra fuera de lo que baja a la tablet (solo
   bajan permisos con prefijo `pos.`).
2. **No existe ningún permiso `pos.*` de stock.** Hace falta uno nuevo, vía
   mig de backfill (molde: `181_backfill_pos_ai_use_permission.php`).
   `OperatorContext::can()` evalúa contra el rol REAL del operador logueado,
   no el del device — así el conteo queda atribuido a la persona que lo
   hizo, no al aparato.
3. **La cola offline no tiene canal para conteo.** `frontend/lib/pos/offline-db.ts`
   (`PendingOpKind`) no tiene tipo para esto. Molde limpio:
   `useUpdatePosRegisterConfig` (`frontend/hooks/use-pos-config.ts:144-200`).
   Reglas de la cola (`context/51` §2): canal FIFO que frena en la primera
   falla, cerco por `registerId`, idempotencia por `opId`
   (header `X-Punto-Op-Id`).
4. **No hay ninguna superficie de conteo en el POS.** Cero archivos en
   `app/(pos)/`, `lib/pos/`, `app/api/pos/` que lo mencionen.

**Idempotencia — el hueco real de la cola offline.** `finish()` es segura
ante reenvío (la segunda llamada da 409 por el chequeo de status), pero
`setCountedQty`/`bulkSetCountedQty` son *last-write-wins*, sin versión ni
`opId`. Encolarlas tal cual rompería la garantía que la cola promete en todo
lo demás (§3 de `context/51`) — hay que resolverlo antes de exponerlas
offline, no envolverlas como están.

**El hallazgo que este plan tiene que registrar**: el flag **`stockCountBlind`
YA EXISTE y está MUERTO.** Tiene columna en la config del tenant, switch en
Ajustes ("Conteos de stock ciegos — El operador no ve el stock teórico
mientras cuenta"), tipo TS y zod. **No lo lee nadie**: ni
`InventoryCountService` ni la pantalla del panel lo consultan, y el esperado
se pinta siempre (`inventory-count/[id]/page.tsx:465`). La D2 del owner es
exactamente ese flag — hay que cablearlo, no crearlo. Cablearlo de paso
arregla el conteo del PANEL, que hoy ignora una preferencia que el dueño cree
haber configurado.

**Impacto técnico de D9.** Hoy `finish()` SIEMPRE llama
`Inventory::manageStock()` por cada línea con diferencia — no hay forma de
que el conteo quede como registro puro. El modo "solo registro" significa
que `finish()` cierra la sesión y NO escribe en el ledger; las diferencias
quedan en `inventory_count_item` para consulta. A diferencia de
`stockCountBlind`, este flag no existe hoy — hay que crearlo, y falta
decidir dónde vive (candidato: junto a `stockCountBlind`, misma sección de
Ajustes).

**Consecuencia técnica del modo ciego, y por qué ordena las fases**: el POS
**no tiene stock teórico**. `frontend/lib/pos-bff/reshape.ts:93-96` hardcodea
`stock: null`. Según `context/53`, el dato en realidad SÍ llega hasta el
BFF —`ItemsQuery.php:197` ya trae `COALESCE(st.onhand,0) AS stockOnHand` en
el LIST de `/v1/items`, y es `reshape.ts` el que lo descarta—, pero es
company-wide, no por sucursal, así que tampoco serviría tal cual para la
caja. De ahí que:

- **Conteo CIEGO no necesita el esperado** → funciona con lo que el
  bootstrap ya trae, es offline-nativo, y no depende de resolver ese TODO.
- **Conteo NO ciego necesita el stock teórico en la caja, por sucursal** →
  obliga a resolver el TODO (bajar stock al bootstrap escopeado por
  sucursal, o un endpoint nuevo), y a decidir qué pasa sin red, donde ese
  número no se puede refrescar.

**Módulo activable**: `frontend/lib/modules-catalog.ts` (`MODULES_CATALOG`) +
un allowlist en `ModulesService` del backend + `usePosModules()` + entrada en
`frontend/components/layout/pos-sidebar.tsx`. El propio
`modules-catalog.ts:191-195` trae el checklist de cómo pasar un módulo de
`soon` a `available`.

## Fases

- **F0 — Cablear `stockCountBlind`.** Conectar el flag muerto a
  `InventoryCountService` y a la pantalla del panel: a ciegas no se pinta el
  esperado, con el flag apagado se pinta como hoy. Prerequisito de D2 y
  arregla el conteo del panel de paso. Barato, sin dependencias, se puede
  hacer antes de tocar la caja.

- **F1 — Conteo CIEGO en la caja.** El default recomendado y el camino
  barato: no necesita resolver el stock teórico del POS.
  - Permiso `pos.*` de stock (mig de backfill) + gate del endpoint por
    MÉTODO, no por embudo completo (el GET de preview puede seguir siendo
    de panel si hace falta; el POST de conteo se abre a `pos-app` con el
    permiso nuevo).
  - Flag de tenant para D9 (aplica ajuste vs. solo registro): mig nueva + UI
    en Ajustes junto a `stockCountBlind` + branch en `finish()` que salta
    `manageStock()` en modo registro.
  - Cola offline: canal nuevo para conteo, con `opId` resolviendo la
    idempotencia que `setCountedQty`/`bulkSetCountedQty` no tienen hoy.
  - Lista fija (D3): resuelta con lo que `inventory_count.scope` (mig 158)
    ya ofrece — ver §Preguntas abiertas si conviene una entidad propia.
  - Módulo activable (D4): entrada en `MODULES_CATALOG` + sidebar del POS.
  - Turno como dato de contexto (opcional): guardar en qué turno se hizo el
    conteo, sin que el conteo dependa de él ni lo condicione.

- **F2 — Conteo NO ciego en la caja. IMPLEMENTADA 2026-09-06.** Dos
  decisiones del owner la reencuadraron respecto de lo que este plan preveía:

  - **La granularidad se movió de COMERCIO a ROL.** Pedido textual del
    cliente: *"le habilitás a un usuario que sea ciego y nuestro usuario tiene
    libre"* — el modo lo decide la PERSONA. Permiso nuevo
    `inventory.count.open` (mig 193, backfill a quien ya tiene
    `inventory.stock.adjust`, nunca al rol `device`). `stockCountBlind` **no
    se eliminó**: pasó a ser el PISO del comercio, y el permiso la excepción
    que lo levanta. Tabla de verdad completa en el docblock de
    `api/lib/Settings/StockCountMode.php`, **único** resolver, consumido por
    el panel (`get`/`list`) y por la caja (`action=expected`) — el servicio ya
    no re-deriva el modo, lo recibe resuelto. Bajo `pos-app` se evalúa contra
    el operador del PIN (`OperatorContext`), no contra el rol `device`.
  - **El teórico es ONLINE; sin red se cuenta a ciegas.** El `stock: null` de
    `reshape.ts` **NO se resolvió, y no es un pendiente**: bajarlo al snapshot
    sería sincronización continua de todo el catálogo, y un teórico VIEJO es
    peor que ninguno (el operador ajusta contra un número que ya no es cierto
    y firma una diferencia inventada). El conteo ciego sigue siendo
    offline-nativo y no se tocó; el abierto pide el teórico al abrir la sesión
    y sin red **arranca ciego**, dicho con esa palabra en el indicador de modo.

  El filtrado lo hace el SERVIDOR: sin el permiso, `expectedQty` no viaja en
  la respuesta (ni se calcula). Fail-closed en las dos capas. Arnés:
  `api/tests/run_stock_count_open_mode_test.sh`.

## Preguntas abiertas para el owner

- ¿El conteo bloquea el cierre de turno, o es un paso opcional dentro de él?
  (Analogía posible: `ShiftCloseGate` de `context/51` §8, que hoy gatea el
  cierre por órdenes/espacios abiertos — mismo patrón de interruptor
  por-comercio, aplicado a "sin conteo no cerrás".)
- ¿Qué pasa con un conteo a medias si el turno se cierra igual? ¿Se descarta,
  se completa después, o el cierre queda bloqueado hasta terminarlo?
- ¿La lista fija (D3) vive como entidad nueva, o se apoya en el `scope` jsonb
  que `inventory_count` ya tiene desde la mig 158?
- ¿El flag de D9 (aplica ajuste vs. solo registro) es un toggle propio, o se
  fusiona con `stockCountBlind` en un solo control de "modo de conteo"?

## Arquitecturas rechazadas — no reintroducir

- **Aprobación posterior del dueño antes de aplicar el ajuste.** Alternativa
  a D1, descartada por decisión explícita del owner: el ajuste se aplica al
  instante (o queda como registro puro si D9 así lo configura), el dueño
  audita después, nunca autoriza antes.
- **El conteo como arqueo encadenado** (cierre del saliente = apertura del
  entrante, con inicial heredado). Fue el modelo original de este doc — el
  owner lo corrigió el 2026-09-01: los conteos son eventos independientes y
  ciegos entre sí, sin herencia de "stock inicial" ni encadenamiento
  cierre→apertura.
- **La doble firma del relevo** (el PIN del entrante como conformidad, vía
  `OperatorAssertion`). Caía junto con el arqueo encadenado: sin herencia no
  hay traspaso que confirmar. Quien hizo el conteo ya queda identificado por
  `startedBy`/`finishedBy` — no hace falta que una segunda persona lo
  confirme.
- **"Un solo número acordado" si saliente y entrante no coinciden.** No
  aplica: son conteos de personas distintas, cada uno con su propio
  resultado — no hay comparación entre ellos que requiera acuerdo.
- **Cualquier dependencia entre un conteo y el anterior** (incluye "sin
  relevo, firma solo el saliente"). El conteo puede registrar en qué turno
  se hizo como dato de contexto, pero no depende de otro conteo ni lo
  condiciona.

- **Bajar el stock teórico al snapshot del POS** (resolver el `stock: null` de
  `reshape.ts:96` para que el conteo abierto funcione offline). Descartado en
  la F2 y no es un TODO pendiente: el saldo se mueve con CADA venta de
  CUALQUIER caja, así que mantenerlo fresco es sincronización continua de todo
  el catálogo — justo lo que el bootstrap del POS evita— y un teórico viejo es
  PEOR que ninguno: el operador ajusta contra un número que ya no es cierto y
  firma una diferencia inventada. Sin red el conteo arranca CIEGO, que es un
  modo válido y el default recomendado.

- **Que la pantalla decida el modo con un flag que baja al dispositivo.** El
  esperado se filtra en el SERVIDOR: sin `inventory.count.open`, `expectedQty`
  no viaja. `inventory.count.open` no lleva prefijo `pos.` (gobierna también
  el panel) y por eso `unlock-pin.php` no la baja: la caja pregunta y el
  servidor contesta. Un flag que el cliente tiene que respetar se evade con
  las devtools — es exactamente el bug del cierre de caja a ciegas que la mig
  169 vino a cerrar.

- **Dos resolvers del modo, uno para el panel y otro para la caja.** Hay UNO
  (`StockCountMode`), y `InventoryCountService::get()/list()` lo reciben como
  parámetro obligatorio, sin default. Si cada superficie lo derivara por su
  cuenta, la que nadie revisa terminaría publicando el esperado que la otra
  esconde.

## Docs relacionados

- `context/52-stock-ledger-unica-fuente.md` — el ledger que el conteo ajusta
  vía `manageStock`, y de donde sale el esperado (`onHand`).
- `context/51-configuracion-offline-de-la-caja.md` — el molde de la cola de
  operaciones y del arqueo de efectivo que este plan calca para mercadería
  (con las excepciones marcadas en §El modelo).
- `context/59-asistente-en-la-caja.md` — origen de `OperatorAssertion` y del
  patrón de permisos `pos.*` sobre el rol real del operador del PIN.
- `context/29-numeracion-y-exclusividad-de-caja.md` — el docType `conteo` y
  su correlativo por sucursal.
