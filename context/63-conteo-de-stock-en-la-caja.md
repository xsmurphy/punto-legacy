# 63 — Conteo de stock en la caja (relevo de turno)

> Estado: **PLAN, sin implementar.** Fecha 2026-09-01. D1-D4 cerradas por el
> owner, no relitigar. D5 en adelante son derivaciones arquitectónicas de esta
> sesión — propuestas sin su OK, marcadas como tales, discutibles.
> El motor de conteo YA EXISTE y está implementado (`InventoryCountService`,
> tablas `inventory_count`/`inventory_count_item`, panel) — este plan no lo
> construye, lo lleva a la caja. Ver §Estado del código.
> Origen: pedido del owner — muchos comercios quieren que el cajero cuente
> stock en cada cambio de turno (sobre todo comida rápida con producto
> terminado en mostrador) y el cajero no tiene acceso al panel.

## El problema (palabras del owner)

En el cambio de turno, sobre todo en locales de comida rápida que venden
producto terminado, el saliente y el entrante cuentan juntos lo que quedó en
el mostrador antes de que uno se vaya y el otro se haga cargo. Hoy esa tarea
no tiene ninguna superficie en la caja: el conteo de stock vive solo en el
panel, al que el cajero no entra.

**La lógica real, y esto define el diseño**: el conteo lo hacen JUNTOS el
cajero que termina el turno y el que lo inicia. Los dos acuerdan que quedaron
X unidades, y así el traspaso queda verificado por ambos. En el cierre del
día no hay relevo (se cierra el local), así que ahí firma solo el saliente; a
la mañana el que abre hace su propio conteo de apertura. **Siempre hay un
cierre y una apertura de stock**, igual que siempre hay un cierre y una
apertura de caja.

## El modelo (propuesta) — el arqueo de caja, pero con mercadería

La analogía que sostiene todo el diseño: esto es el arqueo de efectivo
(`context/51`) pero con mercadería en vez de billetes. Punto ya tiene ese
modelo entero — apertura con monto inicial, cierre con lo declarado, esperado
contra contado, sobrante/faltante, cierre a ciegas — y es el mismo molde:

| Arqueo de caja | Conteo de stock (propuesta) |
|---|---|
| Apertura declara el fondo inicial | Cierre del saliente declara lo que queda |
| El entrante hereda ese fondo | El entrante hereda ese stock como su inicial |
| Esperado = lo que el sistema calcula | Esperado = `onHand` del ledger (`context/52` D1) |
| Diferencia = sobrante/faltante | Diferencia = columna generada de `inventory_count_item` |
| Cierre a ciegas = sin ver el esperado | Conteo ciego = sin ver el esperado (D2) |
| Firma: el operador que cierra la caja | Firma: los DOS operadores del relevo |

Atar el conteo al turno de caja —no dejarlo como una sesión suelta— es lo que
le da sentido a la doble firma: el stock que el saliente deja ES el inicial
que el entrante recibe, igual que el efectivo del cajón. Sin ese amarre, "el
entrante firma" no tendría contra qué comparar.

## Decisiones

### Cerradas por el owner (2026-09-01)

- **D1 — El conteo aplica el ajuste al instante.** Al confirmar, el stock se
  corrige solo — no espera aprobación del dueño. La diferencia queda
  registrada con el nombre de quien contó, y el dueño la ve después en el
  panel.
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

### Propuestas, sin OK del owner

- **D5 — El conteo se ata al TURNO DE CAJA, no es una sesión suelta.** Ver
  §El modelo. Es la decisión que hace que D1-D3 encajen entre sí: sin turno
  no hay "inicial" contra el cual el siguiente relevo compara, y la doble
  firma no tiene a qué atarse.
- **D6 — La firma del entrante es su PIN.** Reusa el mecanismo que ya existe:
  `OperatorAssertion` (HMAC con TTL de 16h, emitido solo por
  `/v1/unlock-pin.php` tras validar el PIN server-side, `context/59`). El
  entrante tipea su PIN y eso es su conformidad. No hace falta inventar un
  mecanismo de firma nuevo.
- **D7 — Un solo número acordado, nunca dos.** Si el saliente y el entrante
  no coinciden, cuentan de nuevo hasta acordar. Se registra el número en el
  que los dos están de acuerdo — no "lo que dijo el saliente" y "lo que dijo
  el entrante" como dos filas separadas.
- **D8 — Sin relevo (cierre del día), firma solo el saliente.** Queda
  asentado en el registro que no hubo testigo — no se simula una segunda
  firma ni se bloquea el cierre por falta de un entrante que no existe.

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
   `OperatorContext::can()` evalúa contra el rol REAL del operador del PIN,
   no el del device — importa para D6, la firma es de una persona, no del
   aparato.
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
  - Turno de caja como contenedor (D5): la sesión de conteo se abre/cierra
    ligada al turno, con el stock del cierre pasando como inicial del
    siguiente.
  - Doble firma (D6/D7): PIN del entrante vía `OperatorAssertion`; UI de
    "cuenten de nuevo" cuando los dos números no coinciden.
  - Cola offline: canal nuevo para conteo, con `opId` resolviendo la
    idempotencia que `setCountedQty`/`bulkSetCountedQty` no tienen hoy.
  - Lista fija (D3): resuelta con lo que `inventory_count.scope` (mig 158)
    ya ofrece — ver §Preguntas abiertas si conviene una entidad propia.
  - Módulo activable (D4): entrada en `MODULES_CATALOG` + sidebar del POS.
  - Sin relevo (D8): flujo de cierre de local, un solo firmante.

- **F2 — Conteo NO ciego en la caja.** Depende de F1 completo. Requiere
  resolver el TODO de `reshape.ts` (stock teórico por sucursal en el POS,
  vía bootstrap o endpoint nuevo) y decidir qué pasa con ese número sin red,
  porque no se puede refrescar en el momento. D2 (configurable) se cumple
  del todo recién acá — F1 por sí sola solo cubre la mitad del interruptor.

## Preguntas abiertas para el owner

- ¿El conteo bloquea el cierre de turno, o es un paso opcional dentro de él?
  (Analogía posible: `ShiftCloseGate` de `context/51` §8, que hoy gatea el
  cierre por órdenes/espacios abiertos — mismo patrón de interruptor
  por-comercio, aplicado a "sin conteo no cerrás".)
- ¿Qué pasa con un conteo a medias si el turno se cierra igual? ¿Se descarta,
  se completa después, o el cierre queda bloqueado hasta terminarlo?
- ¿La lista fija (D3) vive como entidad nueva, o se apoya en el `scope` jsonb
  que `inventory_count` ya tiene desde la mig 158?
- ¿El conteo de apertura del entrante es el MISMO registro que el cierre del
  saliente (una sesión, dos firmas), o dos registros encadenados (el cierre
  de uno referencia la apertura del otro)?

## Arquitecturas rechazadas — no reintroducir

- **Aprobación posterior del dueño antes de aplicar el ajuste.** Se
  consideró como alternativa a D1 y quedó descartada por decisión explícita
  del owner: el ajuste se aplica al instante, el dueño audita después, no
  autoriza antes. Un conteo que espera aprobación no resuelve el problema
  real (dos cajeros que se van sin saber si el traspaso cuadró).
- **Dos números en desacuerdo, guardados los dos.** Se consideró registrar
  "lo que dijo el saliente" y "lo que dijo el entrante" como filas separadas
  cuando no coinciden. Rechazada (D7): si no hay acuerdo, cuentan de nuevo
  hasta que lo haya. Guardar el desacuerdo en vez de resolverlo traslada el
  problema al dueño, que es exactamente lo que D1 evita para el ajuste de
  stock.
- **Mecanismo de firma propio para el entrante.** Se consideró un token o
  confirmación ad-hoc para la doble firma. Rechazado en favor de reusar
  `OperatorAssertion` (D6): el mecanismo de PIN server-side ya existe, ya
  tiene TTL y ya está atado al operador real, no al device — inventar uno
  nuevo duplicaría lo que `context/59` ya resolvió para el agente IA de la
  caja.

## Docs relacionados

- `context/52-stock-ledger-unica-fuente.md` — el ledger que el conteo ajusta
  vía `manageStock`, y de donde sale el esperado (`onHand`).
- `context/51-configuracion-offline-de-la-caja.md` — el molde de la cola de
  operaciones y del arqueo de efectivo que este plan calca para mercadería.
- `context/59-asistente-en-la-caja.md` — origen de `OperatorAssertion` y del
  patrón de permisos `pos.*` sobre el rol real del operador del PIN.
- `context/29-numeracion-y-exclusividad-de-caja.md` — el docType `conteo` y
  su correlativo por sucursal.
