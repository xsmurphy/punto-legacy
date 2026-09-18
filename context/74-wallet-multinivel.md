# 74 — Módulo Wallet multi-nivel

> Estado: **F1 y F2 implementadas 2026-09-18** (núcleo §11, caja §12), más el
> **reporte de bolsillos** (§13, mismo día). F3-F5 pendientes.
> Módulo NUEVO, diseñado desde cero: no se construye sobre giftcard ni sobre el
> crédito interno existentes (§9). La v1 es deliberadamente chica (§2).
> **Todas las decisiones de la v1 están cerradas** (2026-09-16).

## 1. Qué es

Saldo a favor de un cliente, cargado con una venta, separado en **bolsillos**, y
repartible a sus **hijos**.

- El cliente **titular** recibe la carga y la factura.
- El titular tiene **hijos**: otros contactos del comercio que consumen con
  saldo propio.
- El usuario del comercio **transfiere** saldo del titular a sus hijos.
- Cada cliente puede tener **más de un bolsillo** (ej. Almuerzo, Merienda).

Rubro-agnóstico: colegio (padre e hijos), comedor de empresa (empresa y
empleados), club (socio y familia). El schema habla de titular e hijo; "padre" y
"alumno" son vocabulario de la interfaz.

## 2. Alcance de la v1

Entra:

1. **Cargar saldo con una venta**, en un bolsillo del titular.
2. **Bolsillos** configurables por comercio.
3. **Hijos** dados de alta como contactos del titular.
4. **Transferir** saldo del titular a un hijo, bolsillo por bolsillo.
5. **Pagar con saldo** en la caja: el cajero elige al cliente y el bolsillo.

Queda para después:

- **Interfaz para el titular y el hijo** donde ven su saldo (D5).
- **Modo B**, facturar al consumir en vez de al cargar (§4).
- **Vencimiento** del saldo (D8).
- **Unificación con giftcard** (§9).

## 3. Modelo

### 3.1 Jerarquía: vive en el contacto

El hijo **es un contacto más** del comercio, con `parentContactId` apuntando al
titular. Es la única fuente de la jerarquía: la wallet no guarda su propio
padre.

- Al titular **se le factura** y **en él entran las cargas**.
- Un hijo **nunca se carga desde afuera**: solo recibe transferencias de su
  titular. Así cada peso es rastreable desde que entró hasta dónde se consumió.
- **El sujeto fiscal es siempre el titular.** Un menor no es sujeto de
  facturación.
- Todo vive dentro de un mismo comercio.

### 3.2 Bolsillos

Un bolsillo es un **saldo con nombre** dentro de la cuenta de un cliente.
Almuerzo y Merienda no se mezclan.

El comercio define el catálogo de bolsillos. Cada cliente (titular o hijo) tiene
un saldo por bolsillo. Un comercio que no necesita separar opera con uno solo.

### 3.3 El tope ES el saldo del bolsillo (owner, 2026-09-16)

No hay un mecanismo de topes aparte. Si el padre quiere que el hijo gaste como
máximo 50.000 en merienda, le transfiere 50.000 al bolsillo Merienda. **Cuando
se acaba, llegó a su tope.**

Consecuencia: **el saldo de un bolsillo nunca puede quedar negativo.** Si pudiera,
el tope no existiría.

**Límite a declarar**: como el cajero elige el bolsillo (D4), el tope es tan
firme como la disciplina del cajero. Si Merienda está vacía y el cajero debita
Almuerzo, el tope se saltea. Es aceptable en la v1 porque el cajero conoce a los
chicos (D7); si deja de alcanzar, la salida es restringir qué se puede comprar
con cada bolsillo.

### 3.4 Movimientos

**El saldo es la suma de los movimientos.** No existe un campo de saldo que
alguien pueda pisar, y los movimientos nunca se editan ni se borran.

| Tipo | Qué es | Efecto |
|---|---|---|
| `load` | carga con una venta | + en el bolsillo del titular |
| `transfer` | titular → hijo | − en el titular, + en el hijo (par atómico) |
| `spend` | pago en la caja | − en el bolsillo elegido |
| `refund` | reversa de un pago | + |
| `adjust` | corrección manual | ±, con motivo y autor |

`transfer` son **dos movimientos que existen juntos o no existe ninguno**.

### 3.5 Tablas

```
contact                     -- existente
  parentContactId           -- NULL = titular; si no, hijo de ese contacto

wallet_pocket               -- catálogo de bolsillos del comercio
  id, companyId, name, active

wallet_movement             -- append-only
  id, companyId
  contactId                 -- de quién es el saldo
  pocketId                  -- en qué bolsillo
  type                      -- load | transfer | spend | refund | adjust
  amount                    -- con signo
  balanceAfter              -- saldo del bolsillo tras el movimiento
  billingMode               -- congelado en cada load (§4)
  transferGroupId           -- une los dos lados de una transferencia
  sourceType, sourceId      -- venta, ajuste, etc.
  actorContactId            -- quién lo hizo, siempre
  reason, createdAt
```

Dos tablas nuevas y una columna. Sin campo `balance`, sin `wallet_limit`.

## 4. Facturación

**v1: se factura al cargar (modo A).** La carga es una venta con su factura a
nombre del titular. Pagar con saldo después **no es una venta nueva**: no suma a
ventas y no mueve caja, porque el dinero entró cuando se cargó.

Tensión fiscal a declarar: se factura sin saber qué se va a consumir. Si el
comercio vende productos con tasas de IVA distintas, conviene que cada bolsillo
agrupe productos de una misma tasa.

**Después: modo B** (decisión del owner, D1). La carga es un anticipo con recibo y
la factura sale al consumir. Invierte dónde se reconoce el ingreso, así que no es
una variante del modo A. Para que se pueda sumar sin romper nada, **la v1 ya
graba el modo en cada `load`**: si un comercio cambia de modo con saldos vivos,
lo cargado bajo A no se vuelve a facturar.

## 5. Operación en la caja

- **Identificar al cliente (D7, cerrada)**: el cajero lo busca por nombre y al
  seleccionarlo ve el saldo de cada bolsillo. La lista muestra un dato que
  desambigüe (grado, o foto) porque con hermanos y homónimos seleccionar mal
  debita a otro.
- **Elegir el bolsillo (D4, cerrada)**: lo elige el cajero.
- **Debitar**: el chequeo de saldo y el descuento van en **la misma operación,
  del lado del servidor**. Dos cajas que leen "quedan 5.000" y aprueban 5.000
  cada una dejarían el bolsillo en −5.000.
- **Sin conexión**: pagar con saldo **se bloquea**. El saldo es compartido entre
  cajas y nunca se aprueba contra un saldo guardado en el dispositivo. Buscar
  al cliente sí puede funcionar sin red.

## 6. Decisiones

### Cerradas

- **D1** — Dos modos de facturación; la v1 implementa solo el A (§4).
- **D2** — La jerarquía es el modelo, y vive en el contacto (§3.1).
- **D3** — Una cuenta por persona, con bolsillos adentro (§3.2).
- **D4** (2026-09-16) — El cajero elige el bolsillo.
- **Topes** (2026-09-16) — El tope es el saldo del bolsillo (§3.3).
- **D5** (2026-09-16) — La v1 no tiene interfaz para titular ni hijo. Fase
  posterior: los dos ven su saldo, solo lectura, y el hijo solo ve el suyo.
  Mover dinero desde ahí requiere login real, no un link.
- **D7** — El cajero busca al cliente por nombre (§5).

- **D6** (2026-09-16) — Si el bolsillo no alcanza, la diferencia se cobra con
  otro medio de pago en la misma venta. El bolsillo llega a cero y no más, así
  que el tope se respeta.
- **D11** (2026-09-16) — Los hijos se marcan y quedan ocultos por defecto en los
  listados de clientes, visibles con un filtro.

- **D12** (2026-09-18) — **El consumo con saldo NO es una venta fiscal.** Es un
  COMPROBANTE INTERNO de consumo: descuenta stock y registra COGS (el producto
  sale en ese momento), NO suma a ingresos/ventas, NO mueve caja y NO emite
  factura electrónica. Lleva los productos y el precio consumido para que los
  reportes de productos lo muestren APARTE ("consumido con saldo"), sin
  mezclarlo con lo cobrado.
- **D13** (2026-09-18) — **Cada peso entra una sola vez a los reportes, en la
  CARGA.** Ingresos = carga (venta con factura). Stock/costo = consumo. Caja =
  carga. El criterio "no suma a ingresos" vive en UN lugar que todos los
  reportes leen, nunca como parche por call-site (resuelto en §12.1).
- **D14** (2026-09-18) — **Si el bolsillo no alcanza, la diferencia se cobra
  como una CARGA automática**: una venta normal de "carga de saldo" por la
  diferencia (con su factura, pagada con otro medio) y después el consumo se
  paga ENTERO con saldo. Toda factura sale al cargar y ningún consumo emite
  factura. El cajero ve UN cobro. Son dos operaciones, en ese orden (la
  numeración fiscal la asigna la caja). Si el consumo falla después de la
  carga, la plata queda como saldo del cliente y el cajero reintenta el
  consumo. Refina D6.
- **D15** (2026-09-18) — **Carga offline sí, consumo offline no.** La carga es
  una venta emitida: funciona sin red como toda venta y el `load` se aplica al
  sincronizar (solo suma, no deja nada negativo). Pagar con saldo requiere red
  (§5): sin conexión el medio "Saldo" queda deshabilitado con el motivo en el
  propio control.

### Fuera de la v1

**D8 — Vencimiento.** Si algún día vence, en el modo A devolver es una nota de
crédito sobre una factura ya emitida.

## 7. Fases

- **F1 — Núcleo**: `wallet_pocket`, `wallet_movement`, `parentContactId`, y las
  operaciones con sus reglas.
- **F2 — Caja**: cargar con una venta y pagar con saldo.
- **F3 — Hijos y transferencias**: alta de hijos y transferencia por bolsillo.
- **F4 — Interfaz del titular y del hijo** (lectura).
- **F5 — Modo B.**

## 8. Invariantes

- El saldo de un bolsillo es la suma de sus movimientos y **nunca es negativo**.
- Los movimientos no se editan ni se borran; un error se corrige con otro
  movimiento, con motivo y autor.
- Una transferencia es atómica.
- El ingreso se reconoce una sola vez, según el modo grabado en la carga.
- Pagar con saldo y transferir **no mueven caja**.
- El stock **sí** se descuenta al consumir.
- El chequeo de saldo y el descuento son una sola operación en el servidor.
- Cada movimiento tiene autor.
- Toda la jerarquía vive dentro de un comercio.

## 9. Giftcard y crédito interno

Punto ya tiene giftcard y crédito interno, los dos sin historial, sin bolsillos
y sin jerarquía. **No condicionan este diseño.** Si conviene unificarlos se
decide con este módulo funcionando.

## 10. Arquitecturas rechazadas

- **Un campo `balance`.** El día que no coincide con los movimientos no hay forma
  de saber cuál está bien.
- **Una columna por bolsillo.** No escala y no tiene historial.
- **Una cuenta por persona × bolsillo.** El cajero vería "Juan-Almuerzo" y
  "Juan-Merienda" como dos clientes.
- **Un mecanismo de topes aparte.** El bolsillo ya es el tope (§3.3).
- **Pagar con saldo como venta nueva en el modo A.** Duplica el ingreso.
- **Chequear saldo en la caja y descontar después, o aprobar contra saldo local
  sin conexión.** Deja bolsillos negativos con dos cajas.
- **Leer el modo de facturación de la configuración vigente.** Refactura saldos
  ya facturados.

## 11. F1 — implementado (2026-09-18)

Branch `frontend/wallet-f1`. Núcleo completo; la caja y las pantallas de
hijos/transferencias NO están.

**Schema — mig 232.** `contact.parentcontactid` (columna NUEVA: la
`contact.parentId` legacy tiene semántica de franquicia, mig 08, y no se
reusa), `wallet_pocket`, `wallet_movement` con `seq BIGSERIAL` para ordenar
(los UUID son v4). Invariantes en la BD, no solo en PHP:

- `CHECK (balanceafter >= 0)` — nunca negativo.
- Trigger `trg_wallet_movement_chain`: `balanceafter` = anterior + `amount`.
  Así el último `balanceafter` ES la suma y leer el saldo es O(1).
- Trigger append-only: UPDATE/DELETE/TRUNCATE lanzan. Única excepción: la
  purga del tenant desde `/admin` (`CompanyAdminService::hardDelete()`), que
  setea `set_config('punto.tenant_purge', <companyId>, true)` y solo borra
  filas de ESE comercio.
- CHECKs de signo por tipo, `billingmode` obligatorio en `load`,
  `transfergroupid` si y solo si `transfer`, motivo obligatorio en `adjust`.
- FK compuesta `(pocketid, companyid)`: un movimiento no apunta al bolsillo de
  otro comercio.
- Trigger `trg_contact_parent_guard`: padre del mismo comercio, un solo nivel.
  CHECK de no auto-referencia.

**Servicio — `api/lib/Wallet/WalletService.php`.** Expone TODAS las
operaciones de la v1 para que F2/F3 solo las cableen: `load`, `spend`,
`refund`, `adjust`, `transfer`, `setParent`, más el catálogo de bolsillos y
las lecturas. Reglas:

- Toda operación que mueve saldo toma `pg_advisory_xact_lock` por
  (contacto, bolsillo) DENTRO de la transacción antes de leer el saldo. La
  transferencia toma los dos en orden por clave. Las que dependen de la
  jerarquía (cargar, transferir) leen el contacto `FOR SHARE`; `setParent` lo
  toma `FOR UPDATE`.
- Aritmética en centavos enteros.
- `spend` sin saldo lanza `WalletInsufficientFundsException` con `available`
  (lo que había, leído bajo el lock) — es lo que la caja necesita para D6.
- Anidable: si corre dentro de otra transacción (la venta de F2) NO publica
  realtime; el orquestador llama a `publishChange()` tras su commit. El lock
  se suelta en el commit de la venta.
- `refund` exige el origen del pago que revierte y nunca devuelve más de lo
  pagado con ese origen.
- **Decisión tomada dentro del brief**: `setParent` exige que el contacto que
  cambia de lugar en la jerarquía tenga todos sus bolsillos en cero (un
  titular con saldo que pasa a hijo, o un hijo que cambia de titular,
  rompería la trazabilidad de §3.1). Se vacía antes con un ajuste.
- Solo clientes (`contact.type = 1`); el autor es siempre un usuario del
  comercio (`type = 0`).

**API — `api/v1/wallet.php`** (realm `panel`): catálogo de bolsillos
(listar/crear/renombrar/activar, sin borrar), saldos por bolsillo,
movimientos paginados por cursor `seq`, y `adjust`. NO expone cargar, pagar,
revertir ni transferir: eso es F2/F3. Permisos `wallet.view` / `wallet.manage`
(grupo Contactos, `since` 12, sin seed — el Dueño las tiene por serlo). Sin
alcance por sucursal (el saldo es del comercio). Módulo activable `wallet`
(`ModulesService::NATIVE_KEYS`), gateado también server-side con el nuevo
`ModulesService::isEnabled()`.

**Panel.** Sin pantallas nuevas en el menú:

- Bolsillos: pestaña de Ajustes → Catálogo (`/settings/catalog?tab=wallet-pockets`),
  junto a medios de pago e impuestos, más su card en la sección Catálogo de
  `/settings`. Visible con el módulo activo y `wallet.manage`. `CatalogManager`
  ganó `useDelete` opcional (un bolsillo no se borra) y género gramatical.
- Saldo: bloque dentro de la pestaña "Financiero" de la ficha del cliente
  (saldo por bolsillo + movimientos en DataTable + "Ajustar" con MoneyInput y
  motivo obligatorio). Solo `variant="panel"`.
- El módulo aparece en `/modules` (categoría Cobros) y "Configurar" lleva a la
  pestaña de bolsillos.

**Tests.** `bash api/tests/run_wallet_test.sh` — 75 checks contra Postgres
real, incluida la carrera real de dos procesos pagando el mismo saldo (se
verificó que sin el lock falla).

**Qué NO está.** Cargar con una venta y pagar con saldo en la caja (F2, con
claves `pos.*` propias contra el operador del PIN); alta de hijos, filtro que
los oculta en listados (D11) y transferencia en UI (F3 — `setParent` y
`transfer` ya existen en el servicio); interfaz del titular/hijo (F4); modo B
(F5).

## 12. F2 — implementado (2026-09-18)

Branch `frontend/wallet-f2` (toca `api/` y `frontend/`, regla 2 del workflow).
Cargar saldo con una venta y pagar con saldo en la caja, con D12-D15.

### 12.1 D13 — por qué un TIPO de transacción y no la "venta interna"

El consumo es `transactionType = 15` (`SaleType::WalletConsumption`). El
criterio "no suma a ingresos" vive en el TIPO, que es lo único que todo lector
ya mira con LISTA BLANCA: reportes de ventas (`IN (0,3)` / `(0,3,6)`), rollups
(migs 42/160), ledger de Finanzas (`recordSale` solo toma el tipo 0), cierre de
caja (`DrawerService`, `IN (0,3,5,6)`), unicidad fiscal
(`uq_transaction_expedition_invoiceno`, tipos 0/3) y facturación electrónica
(`enqueueElectronicInvoice`, FC/FCR). Un tipo nuevo queda afuera de todos por
construcción: cero reportes tocados, cero parches por call-site. El arnés lo
verifica contra los servicios reales (`SalesService::salesTotals`,
`NonAddingSales::salesByPayment`, `fin_movement`, `einvoice_document`).

La venta interna se investigó y se descartó: es un tipo 0 con el tag mágico
`166227` (y la columna `interno`, mig 118, que ningún reporte lee). NUMERA bajo
timbrado, ENCOLA FE y ENTRA a la caja; los reportes la RESTAN después y solo si
el dueño prendió `ignoreInternal`. Montar el consumo ahí haría depender de un
checkbox que el ingreso se cuente dos veces, y emitiría factura.

El tipo 15 NO se puede crear por `/v1/sales` ni por la cola offline
(`SaleInput::fromPayload` lo rechaza): la única puerta es
`SaleInput::forWalletConsumption()`, que siempre lo acompaña del débito.

### 12.2 Backend

- **Mig 234.** `wallet_pocket.taxid` (FK a `tax`, RESTRICT; default = primer
  impuesto del catálogo, backfill incluido; trigger: del mismo comercio;
  `TaxService::delete()` frena con el nombre del bolsillo). `item.systemkey` +
  índice único parcial `(companyid, systemkey)`.
- **Ítem de sistema** `WalletLoadItem` ("Carga de saldo", `systemkey =
  'wallet_load'`): servicio sin stock, SIN `item_outlet` (invisible para toda
  caja por construcción), lo crea el servidor la primera vez (idempotente ante
  carreras por el índice). El panel no lo lista (`/v1/items`) ni lo edita,
  archiva o borra (`ItemService::isSystemItem`).
- **Carga = venta.** El POS manda la línea con `walletLoad: {pocketId}` y SIN
  ítem (así una caja puede emitir su primera carga sin red). `SaleService::
  resolveWalletLoads()` (antes de abrir la transacción) valida titular +
  bolsillo activo + monto, asigna el ítem de sistema y congela el impuesto DEL
  BOLSILLO siempre incluido (`enrichWithTaxes`). Dentro de la transacción,
  `persistWalletMovements()` escribe un `load` por línea: modo A, `sourceType
  'sale'`, origen = la venta, monto = neto de la línea (`total − totalDiscount`:
  el descuento de venta ya viene prorrateado). Rechazos (422): a un hijo, a
  crédito, sin cliente.
- **Consumo = comprobante interno.** `/v1/pos-wallet?resource=consume` (realm
  `pos-app` único, Bearer del device, token-only). En UNA transacción:
  `SaleService::save()` con tipo 15 → itemSold + COGS + stock, número del
  talonario interno `consumo_saldo` por caja (`DocumentNumber::allocate`, sin
  serie fiscal, vuelve si falla), y `spend` del neto contra el bolsillo
  (`sourceType 'consumption'`). Sin saldo: 409 `INSUFFICIENT_FUNDS` con
  `available` y NADA escrito (ni comprobante, ni stock, ni número).
  Idempotente por uid. Sin timbrado, sin FE, sin Finanzas, sin rollup, sin
  notificaciones.
- **Saldos para la caja:** `/v1/pos-wallet?resource=balances` → saldos por
  bolsillo + `isChild`.
- **Permisos** `pos.wallet.load` / `pos.wallet.spend` (`since` 13,
  `CURRENT_VERSION` 13, sin seed — mismo criterio que `wallet.*`), evaluados
  contra el OPERADOR del PIN (`OperatorContext`). `/v1/pos-wallet` los exige;
  `/v1/sales` exige `pos.wallet.load` en el camino DIRECTO si la venta trae una
  carga (realm `pos-app` forzado con `array_merge`, el valor forzado gana). La
  cola offline evalúa el permiso de quien EMITIÓ, no de quien sincroniza: la
  caja embebe en toda venta con carga la afirmación firmada del operador al
  emitir (`walletLoadAuth`) y `WalletLoadPermission` la verifica vigente en el
  INSTANTE de la emisión (`OperatorAssertion::verifyAt`). Sin afirmación o sin
  permiso, la venta ya emitida se guarda igual (§53), la carga NO se acredita
  y queda marcada en `meta.walletLoadWithheld` (mismo patrón que
  `invoiceAuthExpiredAtEmission`), visible como "Carga de saldo: sin
  acreditar" en el detalle de la transacción del panel. El autor de cada
  movimiento es el operador.
- **Bootstrap de la caja:** `walletPockets` (activos, con su impuesto) solo con
  el módulo prendido; los contactos exponen `parentContactId`.
- **Realtime:** `WalletService::publishChange()` tras el commit de la venta /
  del consumo (el aviso nunca sale antes de que el saldo exista).

### 12.3 Caja (POS)

- **Cargar saldo:** opción del menú "Opciones de venta", junto a "Vale" — es
  la misma clase de acción (agrega una línea a la venta en curso, solo modo
  venta, funciona sin red). Aparece con el módulo prendido y `pos.wallet.load`;
  sin cliente, con un cliente a cargo o sin bolsillos, la fila se apaga y el
  toque dice el motivo. Diálogo: bolsillo (botones) + monto con `<NumericPad>`.
- **Saldo del cliente (D7):** en el chip del cliente del carrito, en la línea
  del RUC con alto fijo (no empuja nada). Solo online; sin red no se muestra.
- **Medio "Saldo"** en el cobro: existe siempre con el módulo prendido (grupo
  secundario), apagado en su lugar con el motivo cuando no aplica (sin red,
  sin permiso, sin cliente, crédito, cobro de espacio/orden, interno/sin IVA,
  cargas/vales/gift cards en el carrito). Elige el bolsillo viendo el saldo
  (pedido fresco). Si no alcanza se aplica lo que hay y el resto se cobra con
  otro medio: al confirmar se emite la CARGA de la diferencia por el mismo
  camino fiscal que cualquier venta (`emitSale`, extraído de `handleConfirm`:
  gates de timbrado y tenencia, número local, cola offline) y después el
  consumo entero. Si el consumo falla, el cobro queda "todo con saldo", el
  mensaje dice que la carga ya está en el saldo, y "Saldo" reintenta SOLO el
  consumo (mismo uid). Cerrar el cobro en ese estado lo avisa.
- **Impresión:** la carga sale como Factura; el consumo por el documento
  Recibo (no fiscal) — lo que imprime lo decide la plantilla.
- **Panel:** el bolsillo gana "Impuesto de la carga" en Ajustes → Catálogo.

### 12.4 Tests

`bash api/tests/run_wallet_test.sh` corre F1 (75) + `wallet_pos_test.php` (63)
contra Postgres real: carga atómica con la venta (un constraint trigger
diferido revienta el COMMIT y no queda ni venta ni carga), rechazos, consumo
con stock/COGS sin FE/Finanzas/ingresos (con control positivo), saldo
insuficiente sin escribir nada, carrera real de dos cajas por el comprobante
completo, los 403 del operador por HTTP real, y la cola offline con emisor
sin permiso / sin afirmación / afirmación adulterada / con permiso.

### 12.5 Qué quedó fuera

- ~~**PENDIENTE PRIORITARIO — monto del consumo confiado desde el POS.**~~
  **RESUELTO 2026-09-18 (§13)** como CONTROL, no como rechazo: el consumo
  congela en cada línea el valor de lista resuelto en el servidor (mig 235) y
  el reporte de bolsillos muestra cada consumo debitado por debajo de ese
  valor, separando lo que la caja declaró como descuento de lo que no tiene
  explicación, con usuario y caja. El débito sigue saliendo del payload a
  propósito: rechazar un consumo por precio le cortaría al cajero descuentos y
  listas que hoy puede aplicar con permiso; lo que faltaba era que la
  diferencia fuera VISIBLE, porque el consumo no pasa por arqueo ni margen.
- **Carga retenida sin resolución en el sistema**: `meta.walletLoadWithheld`
  se ve en el detalle, pero no hay acción para acreditarla o devolverla (hoy
  se resuelve con un ajuste manual del bolsillo o una devolución de la venta).
- ~~**Reporte "consumido con saldo"**~~ **RESUELTO 2026-09-18 (§13)**: tabla
  "Consumido por producto" dentro de la pestaña Bolsillos de Ventas.
- **Devolución de un consumo** (`refund` existe en el servicio, sin UI ni
  flujo en la caja).
- Hijos y transferencias en UI (F3), interfaz del titular (F4), modo B (F5).
- La carga que llega por la cola offline a un cliente que dejó de ser titular
  entre la emisión y el sync queda en la cola como `INVALID_INPUT` (no hay a
  quién cargarle sin inventarlo); la caja ya bloquea el caso al emitir.
- El comprobante de consumo sale por el binding "Recibo": un comercio sin
  impresora asignada a Recibo no lo imprime solo (se reimprime a mano).

## 13. Reporte de bolsillos — implementado (2026-09-18)

Branch `frontend/wallet-reporte` (toca `api/` y `frontend/`). Es el control
que faltaba para el P1 de §12.5: el importe de un consumo lo manda la caja, y
a diferencia de una venta, el consumo no entra al arqueo ni al margen (D12),
así que un precio bajado no quedaba expuesto en ningún lado.

**Dónde vive.** Pestaña **Bolsillos** del reporte de Ventas
(`/reports/sales?tab=bolsillos`), junto a Dashboard / Transacciones / Pagos /
Cotizaciones; visible solo con el módulo `wallet` activo. No es un reporte
suelto en el índice (regla del owner: acoplar a lo que existe) — la carga ES
una venta y el consumo es la otra mitad de esa plata. Entrada de paleta
"Reportes · Bolsillos" con `requiresModule: 'wallet'`.

**Valor de lista: NO era derivable, se congela.** Lo que ya se guardaba no
alcanza: `itemSoldTotal` es el bruto que eligió la caja (si bajó el precio,
la línea ya viene baja) y `transactionTotal` es el `subtotal` del payload, ni
siquiera la suma de las líneas. Mig 235: `itemsold.itemsoldlisttotal`
(unidades × precio de lista, redondeado a los decimales del comercio, mismo
grano que `itemsoldtotal`). Lo escribe `SaleService::freezeWalletListTotals()`
SOLO para el tipo 15, antes de abrir la transacción:
- línea de producto: `PriceListService::resolvePriceBatch()` con el cliente y
  la sucursal del comprobante (lista del cliente → de la sucursal → precio
  del ítem), el mismo resolver que usa la caja;
- hija de add-on o de combo: su precio ya lo puso el servidor desde la BD, su
  lista es su total.
La lista elegida A MANO en la caja NO se considera: no viaja en el payload, y
si viajara, una caja alterada mandaría la más barata y borraría la diferencia.
Consecuencia declarada: un consumo con lista manual aparece como diferencia
sin descuento. Los consumos anteriores a la mig quedan en NULL y no se
evalúan (inventarles la lista con el catálogo de hoy daría diferencias falsas).

**La cuenta, por consumo** (`WalletReportService::differencesCte`, una sola
definición para KPIs, detalle y agrupaciones). Lo cobrado es el DÉBITO real
(`wallet_movement` `spend`), no el total declarado:
- diferencia = max(lista − debitado, 0)
- con descuento = min(diferencia, descuento registrado en el comprobante)
- sin descuento = el resto: precio de línea bajado, lista manual o total
  declarado menor a la suma de las líneas.

**Pantalla** (arquetipo Reporte, `context/84` §4):
- KPIs `StatTile` con delta contra el período anterior: Cargado (cargas
  acreditadas: `load` con origen venta, por fecha de la venta — una carga
  retenida `walletLoadWithheld` no cuenta), Consumido (débitos), **Saldo por
  entregar** (el pasivo: último `balanceafter` de cada cliente × bolsillo a la
  fecha de fin), **Diferencias detectadas** (las sin descuento, KPI principal).
- Gráfico cargado vs consumido por día (card blanca).
- "Diferencias contra el precio de lista": por usuario (el operador del PIN)
  y por caja, más el detalle por consumo en `DataTable` con export.
- Por bolsillo: cargado / consumido / saldo, fila de total en gris.
- Consumido por producto (`DataTable` con export), aparte de lo vendido
  cobrado. Las hijas de combo no se listan (valor cero, su costo está en el
  combo); las de add-on sí.

**Backend.** `GET /v1/reports/wallet?dataset=summary|full` (realms `panel` y
`api`), gate `reports.sales.view` + módulo `wallet` verificado server-side.
Alcance de sucursal con `Roc::build(..., 't')` sobre `transaction`: cargas y
consumos se acotan como cualquier reporte de ventas. **El saldo por entregar
NO se acota**: el saldo es del comercio (§3.1) y no hay forma honesta de
partirlo por sucursal. Sin rollup: lee `transaction` tipo 15 +
`wallet_movement` + `itemsold` del rango con índices existentes; el volumen de
consumos es una fracción de las ventas.

**Tests.** `wallet_report_test.php` (26 checks) en `run_wallet_test.sh`,
contra Postgres real y en un día aislado al azar: lista congelada del
catálogo y de la lista del cliente, venta normal sin lista, KPIs sobre un set
conocido, período anterior vacío, diferencias con y sin descuento con su
usuario y su caja, total declarado menor que las líneas, productos/bolsillos/
día a día, saldo = suma de los saldos del comercio, y por el endpoint real:
usuario acotado a una sucursal no ve el consumo de otra, global sí, módulo
apagado 403.

**Qué NO está.** Acción sobre una diferencia (hoy se corrige con un ajuste
del bolsillo o hablando con el cajero); la devolución de un consumo (§12.5)
tampoco se refleja todavía en "Consumido".

**Gap de F2 encontrado en el review (NO resuelto acá):** anular una venta de
CARGA (`SaleVoidService`, tipos 0/3) no revierte el `load` del bolsillo: la
factura queda anulada y el saldo sigue acreditado. El reporte excluye las
cargas anuladas de "Cargado", así que ese saldo aparece en "Saldo por
entregar" sin carga que lo respalde — es la señal visible hasta que la
anulación revierta la carga (o la rechace si el saldo ya se consumió).

Otros dos detalles de la cuenta: "descuento registrado" es el mayor entre el
del comprobante y la suma de los de sus líneas; y qué línea es hija de un
add-on/combo lo decide la marca que pone el servidor al expandir, nunca el
`type` del payload (una caja que marcara un producto como `addon` se habría
llevado su precio bajado como lista).
