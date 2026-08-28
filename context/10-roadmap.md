<!-- REGLA: Este es el roadmap único del proyecto. Actualizar cuando:
     - Se completa un item (mover a _archive-roadmap-completado.md)
     - Se agrega un item nuevo
     - Cambian las prioridades
     - Se cierra una fase o se abre una nueva
     Items históricos completados archivados en _archive-roadmap-completado.md -->

# 10 — Roadmap Técnico (vivo)

Roadmap único del proyecto Punto POS. Solo items vivos / abiertos.
Items completados archivados en [_archive-roadmap-completado.md](_archive-roadmap-completado.md).

> **Última actualización:** 2026-08-28 (tres módulos pedidos por el owner: consignación, alquiler y subproductos/reproceso en producción — ver primera sección; el reproceso resultó ya posible con el motor actual)
>
> 2026-08-26 (estación de impresión instalable como PWA — pedido del owner; ver item abajo)
>
> 2026-08-26 (auditoría de seguridad de auth: 4 P1 cross-tenant cerrados, cero P0; 7 P2 intra-tenant reportados sin arreglar, ver sección abajo)
>
> 2026-08-25 (orden y stock: plan del "comprometido" cerrado — `context/53`; destapa que no hay job que limpie órdenes zombie, bloqueante de su F1)
>
> 2026-08-23 (add-ons: el stock ya se descuenta al cobrar una orden o mesa, ver P0 #2; uPay pasa a standby por decisión del owner)
>
> 2026-08-22 (permisos: rol propio para el dispositivo POS — cierra la toma del tenant desde un token de caja; anti-escalación también en /v1/roles; queda abierta la fase (b), sesión de operador sobre el token del device)

---

## Módulos nuevos pedidos por el owner (2026-08-28) — sin planificar

Los tres entran como pedido del owner el 2026-08-28. Ninguno tiene plan cerrado
todavía; lo que sigue es el encuadre técnico para poder decidir alcance y orden.

### 1. Venta en consignación

Mercadería que cambia de manos ANTES de cambiar de dueño. Son dos flujos
opuestos y conviene decidir cuál se implementa primero, porque el modelo de
datos que necesitan NO es el mismo:

- **Consignación RECIBIDA** (el proveedor me deja mercadería y le pago lo que
  vendo). El stock existe físicamente en mi sucursal pero **no es mi activo**:
  no debe entrar a la valuación de inventario propio ni al costo hasta que se
  venda. Al vender se dispara la deuda con el proveedor.
- **Consignación ENTREGADA** (dejo mercadería en otro comercio). Sigue siendo
  MI stock, pero fuera de mi sucursal, y solo lo facturo cuando el tercero
  reporta la venta.

Impacto en lo que ya existe:

- `stock` hoy tiene sucursal y depósito, pero **no propiedad**. Consignación
  recibida exige distinguir "está acá" de "es mío" — sin eso, el valor de
  inventario y el costo de la mercadería quedan inflados con algo que no se
  compró. Es el punto de diseño central; casi todo lo demás se deriva.
- `RecipeCosting` y el promedio ponderado del ledger (`context/52`) asumen que
  todo lo que entró se compró. Una entrada en consignación a costo cero
  distorsiona el promedio de ese ítem.
- Cuentas por pagar: la deuda nace al VENDER, no al recibir — el flujo inverso
  al de una compra normal.
- La remisión (`context/42-remision.md`) ya modela traslado de mercadería sin
  venta: es el documento más parecido que existe y probablemente el punto de
  partida para el movimiento de consignación entregada.

### 2. Alquiler

Ítems que salen y **vuelven**, con tarifa por período. No hay nada equivalente
hoy: el catálogo modela cosas que se venden (se van y no vuelven) o servicios
(no tienen stock).

Lo que exige que no existe:

- **Estado por unidad**, no por cantidad: "3 disponibles de 10" no alcanza —
  hay que saber QUÉ unidad está afuera, con quién y hasta cuándo. Eso implica
  identidad por unidad (serie/etiqueta), que el catálogo no tiene.
- **Calendario de disponibilidad**: una reserva a futuro bloquea la unidad sin
  moverla del stock todavía. Ojo: `reserved` ya significa reserva de MESA
  (`context/53`), así que el vocabulario está ocupado y conviene no reusarlo.
- **Devolución con estado**: vuelve entero, dañado o no vuelve. Cada caso
  termina distinto (nada, cobro por daño contra el depósito, venta forzada).
- **Depósito/garantía**: dinero retenido que no es ingreso hasta que se define
  el destino. Toca Finanzas, no solo el POS.

El caso "no vuelve y se cobra" convierte el alquiler en venta — vale definir si
eso genera una venta real (con su documento fiscal) o un ajuste.

### 3. Subproductos y reproceso en producción — ANÁLISIS, no compromiso

El pedido: que los restos de una producción (retazos, sobras, producto que no se
vendió) puedan volver como materia prima de otra. El ejemplo del owner: el pan
que sobra se muele y la galleta molida es insumo de otra receta.

**Hallazgo: son DOS casos y solo uno es caro.**

**(a) Reproceso de un ítem terminado → ya se puede hacer HOY, sin desarrollo.**
El pan que no se vendió es stock terminado. Crear un ítem "galleta molida" con
receta `{pan: 1}` y completar una `production_order` descuenta el pan y acredita
la galleta, con su costo real tomado del ledger — exactamente lo que se pide, y
el motor ya lo hace (`ProductionService::complete()`, `RecipeCosting`). Lo que
falta no es modelo, es **descubrimiento**: nadie va a deducir que "reprocesar"
se hace armando una receta al revés. Trabajo real ≈ un atajo "Reprocesar" desde
el ítem o desde la merma que precargue la orden. Barato y cubre el caso que el
owner describió primero.

**(b) Subproducto simultáneo (la misma orden produce pan Y retazo) → caro.**
Acá sí falta modelo:

- `production_order` tiene UN output (`itemid` + `qtyproduced`). Un segundo
  output exige tabla aparte (`production_order_output`) y tocar el motor de
  completado, que es código sensible (mueve stock y congela costos).
- **El problema difícil es el costeo, no el stock.** Si una orden gasta 100 de
  harina y salen pan + retazo, ¿cuánto vale cada uno? Las tres respuestas
  contables estándar son: subproducto a costo cero (todo el costo al principal,
  simple pero el retazo entra gratis y distorsiona el costo de la receta que
  después lo use), valor neto realizable (se le resta al principal el precio
  estimado del subproducto), o prorrateo por valor de mercado. **Elegir esto es
  una decisión del owner/contador, no técnica** — y define cuánto código hace
  falta.
- Hoy la merma es una salida TERMINAL: `waste_event` resta stock y ahí termina
  (regla 6 de `context/modules/06-produccion.md`: las unidades falladas no
  entran a stock). Convertir merma en entrada de otro ítem invierte esa
  semántica, así que **no se debería modelar como merma** — un subproducto no
  es merma, es producción conjunta. Mezclarlos rompería los reportes de merma.

**Recomendación:** hacer (a) —que es casi gratis y ya resuelve el caso del pan
del día anterior— y dejar (b) para cuando exista un caso real que lo pida con
volumen. (b) sin la decisión de costeo tomada es un módulo a medias que ensucia
el costo de todas las recetas que toquen el subproducto.

---

## Seguridad — P2 pendientes de la auditoría de auth (2026-08-26)

La auditoría completa de auth (disparada por el leak cross-tenant de
`income-chart`, ya cerrado — ver `context/08` §60 y `context/54`) encontró
4 P1 cross-tenant, ya arreglados, y estos P2 **intra-tenant** (ninguno
cross-tenant) sin arreglar:

- `api/v1/modules.php:44-83` — `action=toggle`/`config` sin ningún
  `hasPermission()`; cualquier sesión panel prende/apaga módulos y edita
  su config. El más directo de arreglar.
- `api/bootstrap.php:226-244` — `X-Outlet-Id: all` valida pertenencia al
  tenant pero no chequea rol: un cajero ve reportes consolidados de todas
  las sucursales. Decisión de producto.
- `api/v1/attendance.php:25-40` — el control de presencia física es
  `md5(companyId.outletId)`, derivable por cualquiera que conozca su
  propio `companyId` (lo publica `/v1/bootstrap`).
- `api/v1/devices.php:35` — 403 (device ajeno) vs 404 (inexistente) es
  oráculo de existencia; unificar a 404.
- `api/lib/services/OrderService.php:196-217,349` y
  `api/lib/Reports/DashboardService.php:592` — scope incompleto
  (companyId no usado / EXISTS sin filtrar), no explotable hoy.
- `api/lib/services/DrawerService.php:92,102,838,892` — queries a
  `expenses` filtradas solo por `registerId`+fecha, sin `companyId`.

## Estación de Impresión instalable como PWA (pedido del owner 2026-08-26)

La estación (`frontend/app/(screen)/print`, módulo de pairing `print`, plan en
`context/26-print-station-plan.md`) hoy vive en una pestaña del navegador: el
comercio la deja abierta y cualquiera la cierra sin darse cuenta, y no hay
forma de volver a ella salvo escribir la URL. El owner la quiere **instalable**
— icono en el escritorio, se abre en su propia ventana y se minimiza como una
app más.

**Lo que NO es**: agregar `/print` al manifest actual. Ese manifest declara
`id: "/pos"` y `scope: "/pos"` a propósito — la PWA instalable de hoy es LA
CAJA, y Chrome solo ofrece instalar cuando la página visitada cae dentro del
scope. Meter `/print` ahí haría que las dos compartan identidad: una sola app
instalada, un solo icono, y el que abra la caja podría terminar en la estación.
Es **otra PWA**: su propio manifest (`id`/`scope`/`start_url` = `/print`),
su propio nombre corto y sus propios iconos, servido desde una ruta aparte y
enlazado con `<link rel="manifest">` solo en ese árbol.

**Por qué encaja bien con esta pantalla**: la estación es exactamente el caso
de uso de una PWA de escritorio — una sola ventana, siempre abierta, sin
navegación. Y una ventana propia la separa del resto de las pestañas del
comercio, que es el problema que el owner quiere resolver.

**Lo que esto NO resuelve** (sigue abierto, ver `context/05` §Estación de
Impresión): las impresoras de RED no son alcanzables desde el browser —
instalar la pantalla no le da acceso TCP a la LAN. Una PWA sigue siendo una
página; el agente local sigue siendo una decisión de producto pendiente. Este
item es de ergonomía del operador, no de conectividad.

**Requisito no negociable (owner, 2026-08-26): tiene que aguantar DÍAS abierta
y minimizada.** Ese es el modo de uso real —se prende a la mañana y nadie la
toca— y hoy nadie lo verificó. Antes de darlo por hecho hay que probarlo y
arreglar lo que salte. Qué mirar, en orden de riesgo:

1. **El navegador congela lo que no se ve.** Chrome/Edge suspenden timers de
   pestañas en background y el Memory Saver puede descartar la ventana entera.
   Si el drenado de la cola cuelga de un `setInterval`, la estación deja de
   imprimir sin dar ningún error — el peor modo de falla posible acá. Verificar
   qué pasa con la ventana minimizada horas, y si hace falta apoyarse en el
   canal WS (que es push y no depende del timer) o en un Wake Lock.
2. **Reconexión del WebSocket** tras suspensión del equipo, caída de red o
   reinicio del proxy. Un socket muerto que nadie reabre es el mismo silencio
   del punto 1.
3. **Vencimiento de la credencial.** Revisar el TTL de la sesión del módulo
   `print`: si expira a las 24h, la estación amanece muerta y el comercio no
   sabe por qué. Debería reconectar sola o avisar en pantalla, nunca quedarse
   en blanco.
4. **Memoria**: listas de trabajos que crecen sin tope, listeners que se
   acumulan por reconexión, estado de React que nunca se poda. Días de uptime
   convierten una fuga chica en una ventana congelada.
5. **Cambio de día**: cualquier cosa cacheada por fecha (correlativos, filtros
   "de hoy") tiene que rotar sin recargar la página.
6. **Señal de vida visible**: que el operador pueda mirar la pantalla y saber
   que sigue conectada. Hoy no hay forma de distinguir "sin trabajos" de
   "colgada".

**Autoarranque al prender la máquina**: sí, pero es configuración del equipo,
no código. Chrome y Edge exponen "iniciar al abrir sesión" para PWAs
instaladas (`chrome://apps` → clic derecho sobre la app), y en Windows también
sirve un acceso directo en la carpeta de Inicio (`shell:startup`). La web no
puede pedirlo por seguridad. Va en el instructivo de instalación del local,
junto con desactivar la suspensión del equipo — confirmar el paso exacto en la
máquina destino, que cambia entre versiones.

**Tamaño**: el manifest en sí es chico (manifest + iconos + link en el layout
del árbol `screen`). Lo que puede crecer es el punto 1-4: son los que hacen la
diferencia entre "se instala" y "sirve".

---

## uPay (ueno bank) — cobro desde el POS ⏸ EN STANDBY

**Decisión del owner (2026-08-23): standby, no es urgente.** Queda anotado en
el roadmap y nada más — no se retoma hasta que lleguen las credenciales de
Ueno. Lo único que se podía hacer sin ellas (F1a, la pasarela genérica
`ensurePspMethod` + `<PspQrDialog>`) ya está implementado, así que no hay
trabajo desbloqueado esperando. No tratarlo como bloqueo prioritario ni
proponer sus fases mientras siga así.

Plan completo en **`context/50-upay.md`**. Va acá y no en
`_feature-requests.md` porque ese archivo es la pila de pedidos **de clientes**
capturados del soporte; esto es una integración técnica que pidió el owner.

**El agujero:** uPay ya está en el catálogo de módulos del panel como
"Próximamente" (`frontend/lib/modules-catalog.ts:193`, puesto por `0565da2f`
junto con el módulo Bancard) y **no tenía una sola línea en `context/`** — una
card muerta sin nada detrás que dijera qué falta para prenderla.

**Estado del relevamiento:**

- uPay es la plataforma de cobros de ueno bank; **absorbió a Pagopar**.
  Modalidades: QR, Link de Pagos (48 h), terminal uPOS, API + plugins de
  e-commerce. QR es la que aplica al mostrador.
- **La documentación de la API está detrás de login**
  (`desarrolladores.upay.com.py` sirve solo la cáscara del SPA). Lo público es
  la doc de Pagopar (rev. 2017): auth por `sha1(clave_privada . operación)`,
  confirmación **por polling** (`pedidos/1.1/traer` → `pagado`), **sin webhook
  documentado, sin reversa, sin liquidación**.
- Entra con el **patrón de módulo de Bancard** (allowlist + flat key +
  `moduleData` con canales + resolución server-side en `bootstrap.php`), y con
  `CredentialVault` (AES-256-GCM) para las claves por comercio.
- **Encaja en `rollup_payments_day` sin tocar el grano** (mig 160): `method`
  sale de `transactionPaymentType`, un cobro uPay agrupa en `method='upay'`.

**Las dos deudas de raíz ya están pagadas** (F1a, 2026-08-23, branch
`frontend/psp-generico`): `ensurePspMethod()` provisiona un medio de pago POR
pasarela (con `PspCatalog` como fuente de verdad única) y el ciclo de cobro
vive en `<PspQrDialog>` con un adapter por PSP (Bancard es el primero). Sin
migración de datos y sin cambio de grano en el rollup. Sumar uPay ahora es un
adapter + una entrada en el catálogo, no un copy-paste del módulo.

**Bloqueante (F0, owner):** alta como comercio/desarrollador en ueno para leer
la doc real y conseguir sandbox. 13 preguntas abiertas en `context/50` §6 —
las críticas: si la API es Pagopar o una nueva, si hay flujo de cobro
presencial (el pedido de Pagopar exige email + ciudad + categoría por ítem, que
en mostrador no existen), si hay webhook con contrato, y si hay sandbox.

---

## Permisos del POS — sesión de operador sobre el token del dispositivo (abierto)

Fase siguiente del trabajo de enforcement de permisos. La parte (a) —darle al
dispositivo un rol propio— **ya está implementada** (branch
`api/permisos-enforcement`, mig 161). Falta la (b).

### Qué quedó hecho (contexto para retomar)

El token del POS se emitía con `roleId='1'`, que `RoleService::LEGACY_MAP`
resuelve a `owner`, y `hasPermission()` le devuelve `true` a todo cuando el rol
es owner. Los `hasPermission()` de los 7 endpoints que aceptan el realm
`pos-app` eran, por lo tanto, letra muerta en la caja. El camino explotable
completo estaba en `/v1/contacts`: PUT sobre un contacto `type=0` resolvía
`contacts.user.manage`, el device pasaba, `ContactService` mapea `phone` a
`contactPhone`, y `/v1/login.php` autentica por `contactPhone AND type=0` — un
device le cambiaba el teléfono de login al Dueño y se quedaba con el comercio.

Lo implementado:

- Rol seed `device` (`RoleService::SEED_PERMISSIONS['device']`) con el piso de
  capacidades de una terminal, derivado endpoint por endpoint del inventario
  real de llamadas del POS con Bearer. Nada de empleados, catálogo de
  escritura, config del comercio ni plantillas de escritura.
- `DeviceAuth::buildToken()` emite con ese rol; `bootstrap.php` (apiAuthTenant)
  y `DeviceAuth::resolveDeviceToken()` lo **resuelven contra el tenant en cada
  request** en vez de leer el `roleid` de la sesión, así una sesión vieja
  tampoco opera como owner.
- Guard de realm en `contactsRequire()`: los contactos `type=0` solo se tocan
  desde el panel, para view/edit/delete (antes el guard existía solo en DELETE).
- Mig 161: siembra el rol en los tenants existentes y re-apunta las sesiones
  `pos-app` vivas. No revoca nada, no hace falta re-parear.

### (b) — lo que falta: `perms(device) ∩ perms(operador)`

Hoy todas las cajas de un tenant tienen exactamente el mismo piso, sin importar
quién esté parado adelante. Un cajero y un encargado en la misma terminal
pueden lo mismo. El objetivo es que, cuando el operador se identifica en la
pantalla de bloqueo, los permisos efectivos sean la **intersección** de los del
dispositivo y los de esa persona: el device pone el techo (una terminal nunca
puede más que una terminal) y el operador lo recorta.

**No se re-emite el token del device por operador.** Es eterno, se emite al
parear —mucho antes de que haya alguien en la caja— y atarlo al turno rompe
offline-first y revive la confusión device/operador del incidente 2026-07-19.

Lo que hay que construir:

1. **Sesión de operador.** `POST /v1/unlock-pin` hoy valida el PIN y devuelve
   `{ user: { id, name } }` y nada más — no crea ninguna sesión. Tiene que
   emitir una sesión corta (realm propio, TTL del turno) con el `roleId` real
   del contacto, y el POS guardarla junto al Bearer del device.
2. **Transporte.** El POS manda las dos credenciales: `Authorization: Bearer`
   (device) + la sesión del operador en un header propio. `posFetch` es el
   único punto donde se inyecta el Bearer, así que es el único que hay que
   tocar del lado del cliente.
3. **Resolución.** Un solo lugar decide los permisos efectivos —el mismo que
   hoy resuelve el rol del device en `bootstrap.php`— y devuelve la
   intersección. Sin sesión de operador (o con una vencida) se cae al piso del
   device, que es exactamente el comportamiento actual: **sin red, la caja
   sigue operando igual que hoy**, que es el requisito no negociable.
4. **Cierre de turno.** La sesión del operador muere con el bloqueo de
   pantalla / cierre de caja.

Riesgo principal a vigilar al implementarlo: que un `403` nuevo aparezca en
medio de una venta ya emitida. `pos.sale.create` y `pos.discount.apply` están a
propósito FUERA de todo gate por eso mismo (ver `EXCEPCIONES_CONOCIDAS` en
`api/tests/permission_enforcement_test.php`); el control de descuentos por
operador es del cliente (deshabilitar el campo), nunca rechazar el documento.

Guard de regresión ya escrito: la sección (C) del arnés de permisos fija el
piso del rol `device` clave por clave, con el endpoint que cada una habilita.
Recortarlo sin querer se pone rojo antes de romper una caja.

---

## Orden y stock — el comprometido (plan cerrado, sin implementar)

Plan completo: `context/53-orden-y-stock-reserva.md`. D1-D4 cerradas por el
owner el 2026-08-25. Prerequisito ya mergeado (`48a3e495`).

**El estado hoy**: ninguna orden toca stock — entre que la comanda sale a
cocina y la mesa se cobra, el sistema cree que la mercadería sigue
disponible. Y el POS no ve stock ninguno: `reshape.ts:93-96` fija
`stock: null` con un TODO que miente (`ItemsQuery.php:197` sí devuelve el
saldo), así que el badge del buscador y el patch optimista post-cobro son
no-ops, y la cañería de realtime de stock transporta `null` de punta a punta.

**Fases** (detalle en `context/53`):

1. **F1 — Comprometido derivado + disponible + vencimiento de órdenes.**
   `comprometido` = Σ qty de líneas de órdenes no terminales; `disponible` =
   `onHand − comprometido`, servido desde `Inventory::onHandBulk` (no un
   lector nuevo — lo prohíbe D2 de `context/52`). **No se shippea sin el
   vencimiento**: ver el bloqueante abajo.
2. **F2 — Bajar stock al POS** (bootstrap + delta + arreglar que el saldo sea
   por sucursal, no company-wide). Desbloquea el disponible offline.
3. **F3 — Marca de consumo idempotente** en `pos_order_item`, patrón CAS de
   `settledpaymentid`. Prerequisito de F4.
4. **F4 — Descuento al despachar**, interruptor por tenant (gastronomía).
   Requiere persistir el evento de despacho, que hoy NO existe: no hay
   `printed_at`/`dispatched_at` y reimprimir una comanda es indistinguible de
   imprimirla por primera vez.

**BLOQUEANTE de F1 — órdenes zombie.** No existe NINGÚN job que limpie
órdenes abiertas viejas: los seis del cron (`api/docker/cron/crontab:13-18`)
y los tres `cron.schedule` de `pg_cron` no tocan `pos_order`. El cierre de
caja tampoco las ve (`DrawerService`/`CashCountStatus`/`DrawersService` no
referencian `pos_order`), y `fn_period_guard` no cubre esa tabla
(`157_period_close.sql:168-184`). Una orden abandonada queda `open`/`sent`
para siempre. Hoy no molesta porque nadie lee las órdenes abiertas; en cuanto
el disponible las lea, cada orden zombie resta stock fantasma para siempre.
Hace falta `settingOrderStaleHours` por tenant + job `orders-expire` que
cancele con motivo (nunca `DELETE` — una orden no se borra).

**Decisión pendiente del owner**: el default del vencimiento. Un comercio que
deja mesas abiertas de un día para el otro es un caso legítimo; probablemente
tenga que variar por `source` (mesa vs. mostrador).

## P0 — hallazgos de la auditoría del backlog (2026-08-22)

Tres problemas nuevos, destapados al auditar el backlog contra el código real.
Ocupan el lugar de prioridad que dejó libre la numeración fiscal (resuelta,
ver abajo) — los tres son plata o seguridad, no UX.

1. ~~**Venta con vale canjeado no se puede facturar electrónicamente**~~
   ✅ **RESUELTO (2026-08-24).** La raíz no era el guard del mapper sino el
   filtro de líneas facturables: `EInvoiceService::buildSaleArrayForMapper`
   incluía la línea del vale (bruto sin plata en `transactionTotal`) en
   `$items` y en Σ(total). Ahora se excluye con el mismo criterio que el
   motor de impuestos (exenta y fuera de los buckets del Libro Ventas): el
   DE de la venta que CANJEA no declara lo que se cobró en la venta que
   EMITIÓ el vale. Pendiente menor anotado: la NC de una devolución que
   incluya líneas de vale no tiene exclusión equivalente (itemSold no lleva
   marca de vale) — la política correcta es que la devolución no reintegre
   plata de una línea que no se cobró; decidir en context/36.
2. ~~**El stock de add-ons no se descuenta cuando la venta nace de una orden o
   mesa**~~ ✅ **RESUELTO (2026-08-23).** `loadFromOrder` ya no descarta las
   hijas: `rebuildSelectionsFromOrder()` (`frontend/lib/cart/store.ts`) las
   devuelve al carrito como `CartLine.selections` del padre, y de ahí el cobro
   es indistinguible de una venta directa — `expandAddonSelections` corre,
   persiste las líneas hijas y descuenta el stock.

   Regla del owner que fijó el diseño: la orden no lleva montos (qué y cuánto,
   notas y etiquetas); los montos se cargan al cobrarla, para facturar. Por eso
   el `pricedelta` congelado de la orden solo despeja el precio base del padre,
   y el recargo que se cobra sale del catálogo vigente. `SpaceBalanceService` /
   `SpaceSettlementService` NO cambian: una hija no es una unidad cobrable por
   separado. Detalle completo: `context/41-addons-y-combos.md` §"El add-on
   cruza el flujo de orden".
3. ~~**20 de 47 permisos del catálogo no tienen enforcement**~~ ✅ **RESUELTO
   (2026-08-22, branch `api/permisos-enforcement`).** Eran 25, no 20. Hoy 45
   de 47 gateadas; las 2 restantes (`pos.sale.create`, `pos.discount.apply`)
   quedan fuera a propósito por offline-first —el back no rechaza una venta
   ya emitida— y están declaradas como excepción en el arnés.

   Evidencia: `api/tests/permission_enforcement_test.php` + runner, 144
   checks en verde (cobertura del catálogo, matriz endpoint × rol
   end-to-end con sesiones reales, gates de caja con rol real en realm
   pos-app, y escalación de privilegios). Suite existente sin regresiones:
   `sale_chain` (venta end-to-end desde el realm POS), `sale_void`,
   `return_d2_d3`, `credit_payment_void`, `db_error_visibility`,
   `pos_device_revoked`, `role_permission_backfill`, `register_lease`.

   Además del gate, se cerraron tres agujeros que el enforcement destapó:
   escalación de privilegios en `/v1/users` (un Encargado podía asignarse
   Dueño), bypass del gate de empleados vía `/v1/contacts` (que no filtra
   por `type`), y el 404-antes-del-403 de contactos que servía de oráculo de
   existencia. `ai.agent.elevated`, que no correspondía a ninguna operación
   real, se cableó a `create_user` del agente en vez de borrarse.

   **Queda abierto (P1, no lo cierra este trabajo):** el token de dispositivo
   se emite con `roleId='1'` → seed `owner`, así que en el realm `pos-app`
   todo gate pasa. No es una regresión (es así desde que existe el pareo) ni
   se puede cambiar sin romper la caja: el rol del que pareó el device no es
   el del cajero que está operando, y el POS no tiene hoy identidad de
   operador server-side (el PIN de la pantalla de bloqueo es cosmético,
   `lock-store.ts`). Cerrarlo es un trabajo propio: sesión de operador real
   emitida por el desbloqueo. Detalle en `context/08` §12.2.

## P0 FISCAL — numeración de comprobantes ✅ RESUELTO (2026-08-22)

Los dos problemas que ocupaban este lugar desde 2026-08-17 ya están cerrados:

1. **Venta ONLINE sin número** — resuelto. `create-sale.ts:328,442` manda
   `invoiceno`; verificado en prod: 0 ventas tipo 0/3 sin número desde
   2026-08-19.
2. **Cuatro POS compartiendo el arriendo de la misma caja** — resuelto.
   Migs 141/143, `api/v1/register-lease.php`, `use-register-leases.ts`.

Las 5 filas que seguían ⬜ en `context/29-numeracion-y-exclusividad-de-caja.md`
§7 también están todas resueltas — ver ese doc.

**Residuo histórico — decisión del owner (2026-08-23): NO se backfillean.**
Las 257 ventas emitidas antes del fix (previas a 2026-08-19) que quedaron
persistidas con `invoiceNo = NULL` quedan como están. Cerrado como "no se
hace", no como pendiente — no volver a proponerlo.

## POS — arranque y operación sin internet ✅ RESUELTO (2026-08-23)

El item "persistir el catálogo en IndexedDB" (TODO histórico en
`lib/catalog/store.ts`, §5 de `context/16`) queda **cerrado**, y con él el
bloqueo que lo hacía urgente: **el POS no arrancaba sin red aunque tuviera todo
lo necesario para vender**.

Eran dos bloqueos en serie, no uno:

1. `PosAuthGuard` pintaba un `fixed inset-0` sobre la caja entera ante cualquier
   fallo no-401 — el comentario decía "para no bloquear la caja".
2. El layout del POS pedía además `/v1/bootstrap` (realm **panel**, con el
   Bearer del device) y gateaba todo el render con `if (!bootstrap)`. Sin red
   ese fetch no volvía nunca: `PosLoadingScreen` para siempre.

Y no había de dónde sacar los datos: la ruta `NetworkFirst` del Service Worker
para `/api/pos/bootstrap` **nunca matcheó**. serwist evalúa los matchers RegExp
con `regExp.exec(url.href)` —contra el href completo, no el pathname— así que
`/^\/api\/pos\/bootstrap/` no matcheaba nunca. Ruta muerta en silencio desde
que se escribió. Lección transferible: **matchers de función sobre
`url.pathname`, nunca RegExp anclados con `^/`**.

Resuelto con snapshot del bootstrap en IndexedDB (`punto-pos-offline`, store
`snapshots`, junto a la cola de ventas que ya vivía ahí), política
red → cache → fallar en `lib/pos/bootstrap-source.ts`, y purga de la PII al
desvincular el device. La única pantalla bloqueante que queda es el device que
JAMÁS sincronizó. Detalle completo en `context/16-app-next-rewrite.md` §5 y
`context/43-sync-incremental.md` §Arranque sin red.

Entró también la primera suite de tests del frontend (vitest + fake-indexeddb,
`npm test`), acotada a la migración de la base y al árbol de decisión del
bootstrap — lo que no se puede verificar leyendo.

## POS — bugs y mejoras reportados por el owner (2026-08-18) ✅ RESUELTOS (2026-08-22)

Las 6 quedaron cerradas, verificado contra código:

1. **Abrir una factura vencida desde el POS lleva al Panel** — resuelto: hoy
   abre un dialog interno (`pos-transaction-detail-dialog.tsx:1-24`), nunca
   sale del realm device.
2. **Modal de info de producto incompleto** — resuelto: `product-info-dialog.tsx:120-253`
   ya trae galería, SKU, categoría, marca, tipo, IVA y sucursales.
3. **No hay forma de ver info de producto desde el buscador** — resuelto
   (`product-search-dialog.tsx:12,43,198`).
4. **Gráfico "POR MÉTODO DE PAGO" con lista debajo** — resuelto, ya es solo la
   dona (`pos-main-menu.tsx:898,905-908`).
5. **Cliente › Datos a una sola columna** — resuelto, dos columnas
   (`contact-detail-view.tsx:573`).
6. **Icono de hotkeys del sidebar abría edición de hotkeys** — resuelto
   (`pos-sidebar.tsx:99-107,168`).

## Reporte del tester — "Actualización 21" (recibido 2026-08-22)

Seis items, contrastados contra el código el mismo día. Ninguno estaba
resuelto; el #1 es en parte un bug ya cerrado (`4ada70c1`) con otro síntoma
detrás. Estado se actualiza acá a medida que se cierran.

1. **Costo de producción directa en reportes no coincide con el costo de la
   receta** (Hamburguesa Cheddar: ficha Gs 13.820, reporte otro número).
   **Resuelto** (`api/recipe-costing`).
   Causa: tres fórmulas de costo de receta conviviendo —
   ficha (`ItemCompoundService`, `Σ qty × itemCost`, sin merma, 1 nivel),
   venta (`Inventory::getProductionCOGS`, promedio móvil con merma, SIN
   fallback a `itemCost`, 1 nivel, outlet de `OUTLET_ID`), producción previa
   (`ProductionService::complete`, recursiva con fallback). Además
   `Reports/ProductionService.php:85,110` y `ProductsService.php:95` suman
   `itemSoldCOGS` (unitario) sin `× itemSoldUnits`. Fix: servicio único
   `RecipeCosting` sobre `explodeRecipe()`; las tres fórmulas pasan a ser
   wrappers; contrato `itemSoldCOGS = unitario` fijado.
2. **Próxima factura pierde los ceros a la izquierda.** `document_sequence.
   nextnumber` es bigint y `RegisterAdminService::425` castea a int; el
   `registerDocsLeadingZeros` legacy (mig 26) no tiene UI y solo lo leen 3
   reportes. Fix: `document_sequence.padwidth` (default 7, `context/29 §1`) +
   formateador único `DocumentNumber::format()` usado por panel, POS, ticket
   y reportes; select "Dígitos del N°" en el form de caja.
   **Resuelto** (document-number-padwidth)
3. **Líneas horizontales/verticales de la plantilla no salen en papel.**
   `html-renderer.ts:74-81` las pinta como contenido con margen dentro de un
   wrapper `overflow:hidden` de la altura del bloque — una línea de 1px cae
   fuera del clip; la vertical ignora `block.height`. El canvas las dibuja
   como la caja entera, por eso se ven en el editor. Fix: helper de geometría
   compartido por canvas + renderers; la línea ES la caja.
   **Resuelto** (frontend/print-template-lines)
4. **Nuevo conteo mezcla ítems de todas las sucursales + pedido de filtro por
   categoría.** `InventoryCountService::create:56` snapshotea todos los
   ítems trackeables del tenant; el outlet solo se usa para la cantidad
   esperada. Fix: alcance del conteo como dato (`outletId`, `locationId`,
   `categoryIds[]`, `includeZeroStock`) persistido en `inventory_count`,
   `InventoryCountScope::itemsQuery()` único, preview "vas a contar N".
   **Resuelto** (inventory-count-scope)
5. **Ventas › Transacciones: ver filtros activos.** El filtro por método de
   pago / tipo de venta no existe todavía. Fix: Selects en `toolbarSlot` +
   chips removibles, patrón de `items/page.tsx`.
   **Resuelto** (`frontend/transactions-filters`)
6. **Artículos: buscar por nombre de categoría no encuentra.** `/items` trae
   los 200 ítems más nuevos y busca client-side; el `q` server-side tampoco
   cubre `taxonomyName`. Fix: `q` al servidor con debounce, SQL extendido a
   categoría. **Resuelto** (`frontend/items-search-category`).

## Destapado por el arnés contra Postgres real (2026-08-22)

- **`DB::Execute()` se traga errores SQL** (devuelve `false` + `error_log`).
  Escondió durante meses el `max(uuid)` del reporte de producción y el
  `23502` que hacía que `RoleService::_savePermissions()` nunca persistiera
  (ambos arreglados en `5d964d83` / `e79bbeaf`). **Resuelto** (2026-08-22,
  branch `api/db-errores-ruidosos`): el wrapper ahora LANZA `DbQueryException`
  en vez de devolver `false` (ver `context/08-convenciones-criticas.md` §54 y
  `context/06-infraestructura.md` para el kill-switch `DB_THROW_ON_ERROR`). El
  arnés nuevo contra Postgres real destapó y arregló dos bugs latentes más:
  `Customer::getContactData()` concatenaba el id al WHERE sin sanitizar, y con
  id vacío PG tiraba `22P02` que el wrapper se comía (el reporte de producción
  mostraba el usuario en blanco); y el realm `/v1/admin/*` no cargaba
  bootstrap y no tenía handler de excepciones — devolvía un 500 en blanco.
- `verify_production_cogs` tenía el mismo patrón `''` → uuid en `contactId`.
  **Resuelto** en el mismo branch: la causa era `Customer::getContactData()`
  (ver arriba), no el arnés — ahora valida el UUID y usa placeholder.

## Reporte del tester — "Mejoras Punto" (recibido 2026-08-19)

Documento del tester con marcas de color propias: **verde = él lo dio por
resuelto**, rojo = error nuevo. Lo que sigue es el estado tras contrastarlo
contra el código.

### Ya resuelto (confirmado por el tester)

- Compras a crédito no aparecían en el reporte de facturas de gastos.
- Ver stock actual en columnas.
- Pagos a facturas a crédito hechos desde la caja no se veían en "pagos recibidos".
- Ventas a crédito de caja no se detectaban en Finanzas › Cuentas por cobrar —
  **parcial**, ver pendientes.

### Resuelto en esta sesión, el tester todavía no lo vio

- **Error de IVA en la plantilla de impresión** (duplicaba el subtotal en 5% y
  10%, y la liquidación daba 110.909 sumando subtotal + impuesto). Era el mock
  estático del preview; se eliminó y el editor pasó a resolver con el motor
  real.
- **`Class "Punto\Api\Production\DocumentNumber" not found`** al producir
  desde artículos: `ProductionService` llamaba a `DocumentNumber` por su nombre
  corto sin importarlo (6 call-sites, roto desde `fdc95a99`). Arreglado en
  `cbf6f974`.

### P0 ✅ RESUELTO (2026-08-22)

- **Caja bloqueada sin recuperación** — resuelto: "Liberar caja" existe y
  funciona (`registers-tab.tsx:135,576`, migs 148/149, permiso
  `settings.register.release`). Lo que faltaba era descubribilidad, no
  código; el tester no había llegado a la pestaña Cajas de la sucursal.

### Pendientes nuevos (17-08 en adelante)

1. **Multimoneda**: cobrar en dólares y guaraníes en Caja (plan en `context/42-multi-moneda.md`).
2. **Pago a compra a crédito**: existe en 2 puntos del panel; no existe en
   `/purchase/[id]` ni en el POS (esto último, por diseño). Necesita repro
   real para confirmar si el fallo reportado es uno de esos dos huecos.
3. **Cuentas por cobrar: saldo en Caja no coincide con el panel** —
   **RESUELTO**: hoy ambos renderizan el mismo componente sobre la misma
   query (`OpenInvoicesService::contactStatement()`).
4. **Contactos**: consolidar/unificar clientes duplicados desde panel y contactos.
5. **Impresión**: formatos A4 y Oficio elegibles en Caja — **RECHAZADO por el
   owner (2026-08-22)**. El tamaño de papel es configuración de la plantilla y
   se define en Ajustes; en Caja se imprime y nada más, sin selector. Coherente
   con el invariante "lo que se imprime lo decide la plantilla" y con la regla
   de posiciones estables del POS. Lo que SÍ sigue vigente es el editor de
   layout para hoja completa (`_feature-requests.md`, "A4 / preimpresos con
   posicionamiento"), que vive en Ajustes, no en Caja.
6. **Cuentas por cobrar (resto del punto verde)** — **RESUELTO**: pago por el
   total de la deuda con reparto FIFO
   (`CreditPaymentService::createDistributed()`).
7. **Producción previa**: `El item a producir no trackea inventario
   (itemTrackInventory)` al producir manualmente — **no es bug**: guard
   válido (`ProductionService.php:263-271`, commit `ab328a2d`).
8. **Inventario**: elegir categorías al hacer el conteo y poder habilitar
   costos — **RESUELTO**, ver "Actualización 21" item 4 (`inventory-count-scope`).

### Pendientes que ya venían del reporte anterior

- Botón de anular / devolución / nota de crédito en Caja › Transacciones.
- Al seleccionar un combo en caja no se despliegan sus artículos e insumos —
  **RESUELTO** por F4 de add-ons (`AddonPickerDialog`, ver también T7 más abajo).
- Tickets de comanda y factura se imprimen incompletos — **RESUELTO**, misma
  causa que las líneas de plantilla que no salían en papel (commit `6cd9da75`).
- Exportación RG90 y Libro Ventas desde Ventas › Transacciones (plan en `context/46`).
- Reporte detallado en Reportes › Productos y servicios; historial por artículo;
  acceso directo al historial desde la ficha del producto — **RESUELTO**
  (`GET /v1/reports/products?view=detail|combos`, `items/[id]/page.tsx:930`
  para el historial). Filtro por categorías: sin verificar.
- Costo de producción no se calcula en el reporte de productos y servicios —
  **RESUELTO**, ver "Actualización 21" item 1 (`api/recipe-costing`).
- Los reportes de salida no desglosan los componentes de un combo —
  **RESUELTO** (`view=combos` del mismo endpoint de arriba).
- Auditoría con más detalle de los movimientos por usuario — sigue abierto
  (relacionado: "drill-down de staff" en `_feature-requests.md`).
- **Separar productos por sucursal** — parcial: el POS ya NO mezcla
  (`outletVisibilityClause()`, `ItemsQuery.php:319`, commit `a48c8555`). El
  panel sigue mostrando el catálogo completo por diseño declarado
  (`api/v1/items.php:155`) — queda como decisión de producto pendiente del
  owner, no como bug.

## POS — modelo de viewports y orientación forzada (2026-08-19, sin implementar)

**Regla del owner**: el POS tiene **solo dos modos**, no un espectro de anchos.

- **Horizontal** — tablets y computadoras. Es el modo normal de una caja.
- **Vertical / phone view** — smartphones y tablets verticales chicas.

El corte entre ambos ya existe en código: `useIsMobile()` /
`MOBILE_BREAKPOINT = 768` (`frontend/hooks/use-mobile.ts`), usado por
`frontend/app/(pos)/pos/layout.tsx`. Cualquier componente nuevo del POS debe
reusar ese criterio, nunca inventar un breakpoint al lado.

**Pendiente**: *"en una tablet debemos forzar a que siempre se use
horizontal"*. No implementado. Lo que hay que saber antes de encararlo:

- Se declara en el manifest de la PWA (`"orientation": "landscape"`) y se
  refuerza con `screen.orientation.lock()`.
- ⚠ **iOS/Safari ignora las dos cosas** — en iPad no se puede bloquear la
  orientación desde la web. Ahí las únicas salidas son detectar el vertical y
  mostrar un aviso bloqueante ("girá el dispositivo"), o dejar que caiga en
  phone view.
- Decisión del owner pendiente: manifest + lock asumiendo que en iPad no
  aplica; lo mismo más un aviso bloqueante en iPad; o no hacer nada y que la
  tablet vertical caiga en phone view.

## Bugs destapados al documentar los módulos (2026-08-17)

Salieron de escribir `context/modules/` — cada uno con evidencia `path:line` en
el doc del módulo. Los dos primeros son plata, y ya están cerrados; los otros
dos siguen abiertos.

1. **El costo de producción directa NUNCA se calcula.** **Resuelto**
   (`4ada70c1`). `SaleService` comparaba `itemType === 'direct_production'`,
   una etiqueta sintética de presentación que jamás se escribe a BD (un
   `produccion_directa` persiste `itemType = 'product'`, `ItemKind.php:32`), y
   el branch de COGS era código muerto. Pasó a usar el predicado real
   (`Inventory::saleExplodesRecipe()`, flags
   `itemProduction`/`itemTrackInventory`). El costeo en sí se unificó después
   en `RecipeCosting` — ver el item #1 del reporte del tester
   "Actualización 21". Arnés: `verify_production_cogs.php` casos 1-2.
2. **Los reportes de producción directa salen siempre vacíos**, sin error
   visible. **Resuelto** (`4ada70c1`). Misma causa; los tabs filtran ahora por
   los flags reales + `EXISTS item_compound` (los flags solos no alcanzan:
   servicio / insumo_sin_stock / descuento comparten la misma combinación).
   Arnés: `verify_production_cogs.php` casos 3/3b.
3. ~~**Una orden/mesa con add-ons no descuenta el stock del add-on**~~
   ✅ **RESUELTO (2026-08-23)** — ver el ítem 2 de "P0 — hallazgos de la
   auditoría del backlog" al principio de este doc. El miedo al doble conteo
   que justificaba descartar las `selections` ya no aplicaba:
   `expandAddonSelections` le RESTA al padre la suma de los deltas antes de
   repartirlos a las hijas, así que padre + hijas = exactamente lo cobrado.
4. **`$sD['type']` en `SaleService.php:1807`** — **no es un bug vivo**: es
   rama muerta. Verificado que los usos restantes son un discriminador
   interno del backend (`'type' => 'compound'`, línea 1893), no un valor que
   dependa de lo que mande el frontend.

**Además, dos divergencias plan↔código** (no son bugs, son deuda de estado):
la mig 23 dejó escrito que iba a retirar `taxonomy` "cuando facturación/
reportes/ítems migren" y nunca pasó — las dos tablas siguen vivas y
sincronizadas por triggers; y el `TAX_RATE = 0.10` que F2b daba por muerto
sigue vivo en `allocate-discounts.ts:29` para el neteo de "quitar IVA".

## Cuentas por cobrar/pagar — cobro/pago inline (2026-08-16)

`/reports/open-invoices`: detalle por contacto (reusa `AccountStatementSection`
del tab Financiero), colores de estado (Badge destructive/outline por
`dueStatus`), y cobro/pago inline con 3 modos (una factura puntual, todas,
monto libre repartido server-side FIFO oldest-first) — generalizado a
clientes (`credit_payment`) Y proveedores (`purchase_payment`,
`CreditPaymentService::createDistributed()`).

**Resuelto (2026-08-16):**
- **Anulación de un pago/cobro registrado.** Implementada:
  `CreditPaymentService::void()` (soft-void `transactionStatus=6`, mismo
  patrón que `PurchaseCreditNoteService::void()` como se había anotado acá),
  `DELETE /v1/credit-payments?id=`, permiso `pos.sale.void` (cliente) /
  `finance.manage` (proveedor), botón en `AccountStatementSection` y en
  `/transactions/{id}`. Detalle completo y decisión del owner sobre el
  correlativo en `context/40-anulacion-y-nota-credito.md` (sección "Anulación
  de recibos de pago/cobro").

Lista corta de bugs concretos reportados durante el uso. Se vacía a medida que
se arreglan — lo que crece acá es señal de deuda, no de backlog.

**Auditoría 2026-07-30**: se revisó todo el listado contra el código (no contra
la memoria de quién reportó qué). De ~24 bugs anotados quedan tres categorías:
los que siguen abiertos por decisión de producto pendiente (el owner tiene que
resolver qué se hace, no es un bug de código), los que necesitan repro en
producción (no se pueden confirmar leyendo el código solo), y los que ya
estaban arreglados y nadie había cerrado — esos se movieron a
"Cerrados en la auditoría del 2026-07-30" al final de la sección, con la
evidencia de dónde quedó el fix.

### Reporte del tester — 2026-08-04 (`Mejoras Punto.docx`, post deploy `9925453`)

17 ítems. Triado y root-cause abajo; varios compartían raíz.

**Resueltos en esta sesión**

| Qué reportó | Raíz real | Fix |
|---|---|---|
| Producción descuenta insumos dos veces | El guard leía `$sD['type']` del carrito y el POS nunca manda ese campo, así que nunca cortaba. Afectaba también a la anulación, que reponía insumos jamás consumidos | `822f8df3` |
| Total Bruto suma mal el descuento | `transactionTotal` se persiste BRUTO; el front asumía neto y lo volvía a sumar. Rompía también el neto y su KPI | `c9f09875` |
| Timbrado "no se guarda" | Guardaba bien: `flattenJsonb` hace unset de `data` y el read-back devolvía todo vacío. Mismo bug tiraba el provisioning de facturación electrónica | `2102d4c8` |
| Renombrar estados a Contado/Crédito | La columna mostraba `transactionComplete` (estado de cobro), no modalidad — un crédito cobrado se veía igual que un contado | `d0537e8e` |
| Ventas con decimales quedan pendientes | Sin arreglar aún, pero se destrabó el diagnóstico: `AutoExecute` INSERT no seteaba `lastError` ni `transOk`, así que el error real de PG se perdía y `CompleteTrans()` hacía COMMIT sobre una tx abortada | `62941d41` |

**No reproducen**

- **Cuentas por cobrar no detecta ventas a crédito**: funciona (verificado en
  prod 2026-08-04, 7 clientes / Gs. 2.015.000).
- ~~**Cuentas por pagar no muestra compras a crédito**: no existe ninguna
  compra a crédito en el sistema, las 68 cargadas son Contado.~~
  **REABIERTO 2026-08-16** — el owner mostró una compra a crédito real
  (`002-010--0934535`, proveedor Don Ruben, con vencimiento 20/08/2026). El
  diagnóstico de "no reproduce" era falso: se apoyaba en un conteo del momento,
  no en el flujo. Hay que volver a verificar el reporte de cuentas por pagar
  contra datos reales antes de darlo por bueno.

  Nota de por qué costó verlo: hasta `c338595b` el DETALLE de compra no
  mostraba la modalidad — solo el listado —, así que una compra a crédito se
  veía idéntica a una de contado al abrirla. Cualquier verificación manual
  hecha desde el detalle daba "todas contado".

**Diagnosticados, sin implementar**

- **Combos no despliegan sus componentes en la caja**: no es un bug de display
  — `PosItem` no tiene campo de componentes y el bootstrap del POS nunca los
  manda. El POS no tiene el dato. Requiere decidir de dónde se traen
  (¿bootstrap o on-demand?) y cómo se muestran; `Inventory::displayableCompounds()`
  ya devuelve el shape.
- **Plan de cuentas**: la estructura existe (`fin_category`, jerárquica,
  income/expense) pero **nadie elige la cuenta**: `FinanceLedger` asigna
  categorías fijas del sistema (`ensureSalesCategoryId`,
  `ensurePurchasesCategoryId`), así que toda compra cae en "Compras" y toda
  venta en "Ventas". Falta que la compra/gasto lleve su `categoryId` elegido.
  Chico y de alto valor.
- **Centros de costo**: no existe nada (`outletid` hace de proxy grueso). Es un
  módulo aparte — el owner pidió dejarlo en roadmap.

**Pendientes de decisión / de datos**

- Numeración correlativa de TODOS los documentos → `context/37-numeracion-documentos.md`.
- ~~Columnas y totales de IVA en plantillas~~ **RESUELTO 2026-08-08** (F3 de
  `context/38-impuestos-multi-pais.md`): la facturación electrónica lee el IVA
  congelado por línea en vez del catálogo actual (`f9db4ffc`), el ticket lleva
  el desglose fiscal por línea (`74252a02`) y existen bloques de plantilla
  parametrizados por tasa — `item_total_by_rate`, `subtotal_by_rate`,
  `iva_by_rate`, `iva_total` — con paleta generada según los impuestos del
  comercio (`66503f24`). Verificado con una venta de 4 líneas (10% incluido,
  5% incluido, exenta, 10% añadido con descuento) en `decimals` 0 y 2.
- Anulaciones / devoluciones / notas de crédito, export RG90 + Libro Ventas,
  reporte detallado de productos e historial por artículo: alta de
  funcionalidad, sin empezar.
- SQL 25P02 al crear cuenta: mismo enmascaramiento que las ventas con
  decimales; esperar el error real de PG ahora que `62941d41` lo deja pasar.
- **Etiquetas de venta: el catálogo que sugiere no es el que valida** (hallazgo
  2026-08-09, salido del review de las etiquetas por línea `37bd618c`). El
  picker sugiere desde un catálogo y el backend valida contra otro
  (`taxonomy`/`toTag`, `frontend/lib/commands/create-sale.ts:207,291` vs
  `SaleService.php:854-869`). **Framing corregido (verificado 2026-08-22): NO
  aborta la venta** — la etiqueta rechazada se omite en silencio desde
  `11a159e3`. Sigue pendiente arreglar el mismatch de catálogos (se pierde la
  etiqueta, no la venta). Las etiquetas por LÍNEA no tienen este problema (no
  validan contra FK, son texto libre en `itemSold.meta`).

### Reporte del tester — 2026-08-03 (`requerimientos_punto_de_venta`)

Documento con 4 capturas. Triado abajo. **Ojo con la ventana temporal**: el
reporte se escribió el mismo día en que se desplegaron varios fixes, así que
dos ítems pueden estar ya resueltos y probados contra el build viejo — están
marcados RE-TEST y no se tocan hasta que el tester confirme.

**Bugs — plata o dato incorrecto (arriba = peor)**

| # | Qué pasa | Dónde | Estado |
|---|---|---|---|
| T1 | Cobro por partes en una mesa: la venta se confirma pero el pago parcial NO se registra en la cuenta de la mesa (toast "no se pudo registrar el pago parcial… Avisá al soporte" junto a "¡Venta confirmada!"). La caja cobró y la mesa sigue debiendo → descuadre. | Espacios / `SpaceSettlementService` | RESUELTO `1f9c8f97` |
| T2 | Canje de gift card: "Giftcard no encontrada" siempre. En la captura el código tipeado es `490828` y el listado muestra `4908128` (vigente, Gs 700.000) — puede ser un dígito comido por el input o un lookup que no normaliza. | POS / giftcards | RESUELTO `634c5aa3` — ver nota abajo |
| T3 | Descuentos de una cotización se ven bien en caja, pero en Panel → Transacciones → Cotización el monto vuelve al total sin descuento. | Cotizaciones | RE-TEST (fix `27ab36b6`, 2026-07-31 19:51) |
| T4 | Descuento de -20% asignado a un cliente desde el panel no se aplica (ni automático ni manual) al totalizar en caja. | Listas de precios / caja | RESUELTO `ef6bab48` + `e03c8a2e` |
| T5 | Cliente → Órdenes sale vacío ("Sin órdenes") aunque la orden se generó a nombre de ese cliente. Igual en panel y reporte. | Órdenes / ficha de cliente | RESUELTO — tab de la ficha + reporte general (ver nota abajo) |
| T6 | Las cuentas por cobrar (facturas a crédito) no aparecen en los datos del cliente. | Clientes | RESUELTO — fuente única con el reporte general (ver nota abajo) |
| T7 | Combo dinámico/fijo no despliega sus categorías al agregarlo: entra al carrito como producto suelto, sin poder elegir los ítems. | Catálogo / POS | RESUELTO por F4 de add-ons (`AddonPickerDialog`) |
| T8 | Al procesar un espacio por cantidad o total no lleva al listado de ventas, así que no se puede asignar cliente si pide factura. | Espacios | RESUELTO — arrastre del reporte anterior, verificado por el owner 2026-08-09 (ver nota abajo) |
| T11 | Modal de detalle de transacción: demasiado chico y con la información pobre y cruda. Debería verse como una factura, con el nivel de detalle de la vista de compras (`/purchase/[id]`), no como una tabla de 3 columnas. Reportado con captura por el owner 2026-08-06. | Panel / transacciones | RESUELTO 2026-08-08 — `2c555b39` + `1dc99c45` (ver nota abajo) |
| T9 | Modificar cantidad de un ítem del carrito: no deja tipear cantidad ni decimales, "persiste incluso usando Shift". | POS / carrito | RE-TEST (fix `4c0158d0`, desplegado hoy) |
| T10 | Orden en venta: al procesar el pedido vuelve a la lista de ventas y pide cobrar de nuevo algo ya pagado. | POS / órdenes | RE-TEST (fix `675a4608`, desplegado hoy) |

El tester ya dio por cerrado el de persistencia de ventas guardadas.

**T2 — nota (2026-08-08)**: NO era el dígito faltante — el caso del reporte
(`490828` tipeado vs `4908128` real) es un simple typo del cajero. El bug de
software (`is_array()` sobre un `CaseInsensitiveArray`, que rompía TODO canje
sin importar el código) ya estaba resuelto por `634c5aa3` (2026-08-05) antes
de esta auditoría. Lo que la auditoría dejó, y se cerró en esta sesión:

- **Mensajes de error honestos en el consumo del front**: `validate` (dialog
  de canje) ya devolvía mensaje distinto por caso (no encontrada/vencida/ya
  usada/saldo insuficiente) desde el diseño original — eso quedaba oculto
  detrás del bug de `is_array()`, que hacía que TODO cayera en "no
  encontrada" antes de llegar a esas ramas. Con el bug arreglado, el dialog ya
  mostraba el mensaje real. Lo que sí faltaba: el `.catch()` fire-and-forget
  de `resource=consume` en `pay-dialog.tsx` (post-venta) tragaba el motivo y
  mostraba un toast genérico — ahora incluye `err.message` real, porque ahí
  la venta YA está cobrada y soporte necesita saber si hay que reconciliar.
- **Aislamiento multi-tenant confirmado**: el lookup ya escopea por
  `companyId` en el `WHERE`, así que una gift card de OTRO comercio ya
  reportaba "no encontrada" (nunca "vencida"/"usada") — sin cambios, solo
  verificado.
- **Unicidad del código de emisión, en el backend**: el código de emisión es
  texto libre (`giftcard-issue-dialog.tsx`) sin `maxLength` (agregado, 64 =
  ancho de columna) y sin garantía real de unicidad case-insensitive. El
  canje matchea `UPPER(code)=UPPER(?)` pero la `UNIQUE("companyId", code)` de
  la mig 44 es case-sensitive — dos códigos que solo difieren en case
  (`GC-ABC12345` / `gc-abc12345`) pasaban la constraint pero eran la MISMA
  gift card para el canje (que resuelve con `LIMIT 1` y se queda con una
  arbitrariamente) — plata fantasma. Fix: `SaleService::issueGiftCard()`
  normaliza el código a mayúsculas antes de insertar, y mig
  `126_giftcard_code_unique_ci.sql` agrega
  `uq_giftcard_company_code_ci UNIQUE ("companyId", UPPER(code))`. La mig
  detecta duplicados preexistentes y, si los hay, se salta la creación del
  índice con `RAISE NOTICE` (no aborta el boot) — verificado contra Postgres
  16 real en Docker con y sin duplicados inyectados; con duplicados el
  `EXECUTE` cae en `EXCEPTION WHEN unique_violation` sin tocar ninguna fila,
  y el pre-check de `issueGiftCard()` queda como backstop server-side hasta
  que se limpien a mano y se re-corra la migración (que sí crea el índice en
  ese re-run, sin acción extra).

**T5 — nota**: el tab "Órdenes" de la ficha del cliente leía el reporte legacy
`orders` (`transaction` type=12, pedido online viejo) en vez de `pos_order`
(módulo Órdenes real). Fix: `OrderCoreService::list()` suma filtro
`customerId`, `/v1/orders-core` lo expone, y el tab pasó a `useOrdersByCustomer`
+ `OrderStatusBadge` compartido.

**T5 — reporte general resuelto (2026-08-04)**: `OrdersService::listOrders()`
(`api/lib/Reports/OrdersService.php`) y el KPI `orders` del dashboard
(`DashboardService::orders()`) pasaron de `transaction` type=12 a `pos_order`
— mismo defecto, misma migración. Total se calcula con un JOIN agregado a
`pos_order_item` (excluye `cancelled`), `status` es la unión de strings del
módulo Órdenes, `channel` sale de `source` (`ecommerce`→`ecom`, resto→`local`;
hoy no hay integración que produzca `ecommerce` en prod, `onlineCount` da 0
a propósito). `dueDate` queda `null` (`pos_order` no tiene vencimiento).
Frontend: `OrderRow.status` pasó a la union de `OrderStatus`, y
`orders-list.tsx` reemplazó su `STATUS_MAP` numérico por `OrderStatusBadge`
compartido (adaptando `OrderRow`→`Order` con `toOrderStub`, `interactive`
`false`). `api/lib/services/OrderService.php` (legacy, type=12) queda
intacto — dominio aparte, no se tocó.

**T8 — nota (2026-08-09)**: el owner verificó el flujo y ya funcionaba — el
ítem venía arrastrado del reporte anterior sin borrar. Vale como advertencia de
proceso: se diagnosticó y se cambió código antes de confirmar que el bug
existía todavía.

El primer diagnóstico ADEMÁS estuvo mal. Se concluyó que en tablet el módulo
`/pos/espacios` se pinta como Dialog fullscreen encima del `CartPanel`
(`layout.tsx`, `moduleAsDialog`) y tapaba el botón "Cliente". Falso para este
caso: `useIsMobile` (`hooks/use-mobile.ts`) corta en ancho `< 768px` — el
comentario del propio archivo aclara que "una tablet POS >768px sigue siendo
táctil" —, y el reporte se hizo en una notebook a resolución alta, donde el
módulo va al 70% y el carrito al 30%, ambos visibles. **Regla que sale de
acá**: `useIsMobile` es ancho de viewport, no tipo de dispositivo; para
capacidad táctil está `useIsCoarsePointer`. No inferir "tablet" de ninguno de
los dos sin el dato del dispositivo.

Lo que sí quedó del intento (`4000df23`, defendible por sí solo): el estado de
reconciliación del cobro parcial salió del estado local de `espacios/page.tsx`
y vive en `lib/spaces/settlement-store.ts`, con el efecto montado en
`SpaceSettlementProvider` (hermano del `CartPanel` en el layout del POS, fuera
del slot de ruta). Antes, cualquier navegación mataba la reconciliación — por
eso `40e8cbf9` había sacado la navegación a propósito. Efecto lateral: la
guarda de doble cobro (`chargeInFlight`) ahora sobrevive un cobro en vuelo.
Detalle verificado en código: `clearCart()` resetea `settlementIntent` ANTES
de que se dispare el cierre del PayDialog, así que guardar ese estado en el
cart store lo habría dejado en `null` justo cuando la reconciliación lo lee.

Además se corrigió un comentario mentiroso: el docblock de `handleSplitCharge`
decía "abre el PayDialog" y nunca lo abrió.

**T11 — nota (2026-08-08)**: no se agrandó el modal, se lo reemplazó. El
diagnóstico fue que había CUATRO vistas divergentes del detalle de una
transacción (`PanelDetailView`, `TransactionDetailContent` del POS, el
`TransactionDetail` local de `pos-transactions-dialog.tsx`, y ninguna para
"Pagos recibidos") contra UNA sola de compras, y que casi todos los datos que
faltaban ya existían en BD sin devolverse. Fix en dos fases, plan en
`context/39-detalle-transaccion.md`:

- `2c555b39` — resolver canónico `Transactions/TransactionDetailService`, al
  nivel de `PurchasesService::find()`. Devuelve timbrado (`register.invoiceAuth`),
  sucursal, caja, cajero resuelto a nombre, fecha con hora, condición
  contado/crédito, descuento por línea en monto y %, comisión y usuario
  asignado por línea, impuesto congelado por línea (F2a) y desglose por tasa
  (`toTaxObj`), y documentos vinculados vía `TransactionLinkService`
  (`quote_to_sale` en ambas direcciones + órdenes cobradas, que existían sin
  usarse). Muere la query inline de `api/v1/reports/transactions.php`.
- `1dc99c45` — página dedicada `/transactions/{id}`, espejo de `/purchase/{id}`
  (decisión del owner: página, no modal más grande). El tab Cotizaciones usa la
  misma página. "Pagos recibidos" recibió un modal chico — antes ese tab no
  tenía ni click de fila. Se borraron `PanelDetailView` y `PanelEditView`.

Queda F4 del plan: migrar el detalle del POS al mismo resolver. El campo legacy
`toTransactions` quedó deprecado (cero INSERT vivo; `transaction_link` lo
reemplazó).

**T6 — nota**: `ContactAnalyticsService::openInvoicesTotal()` calculaba el saldo
restando la columna `transactionPaid`, que nadie en el repo escribe (el pago
real es una transacción vinculada vía `transaction_link`), así que el
predicado "impaga" siempre era cierto y el KPI daba un número sin sentido —
mientras el reporte general (`OpenInvoicesService::general()`) sí calculaba
bien. Fix: se extrajo `OpenInvoicesService::forContact()` (misma lógica
per-contacto que `general()`, reusa `payedByParent()`) y la ficha del cliente
pasó a consumirla — una sola definición de "cuánto debe", no dos que puedan
volver a divergir.

**T4 — DOS defectos encadenados, ambos silenciosos**:

1. `ef6bab48` — `PriceListService` referenciaba columnas con identificadores
   camelCase ENTRE COMILLAS (`pl."defaultAdjustment"`). En PG un identificador
   quoteado es case-sensitive y las columnas son minúsculas, así que la query
   fallaba. `resolveActiveList` lo interpretaba como "no hay lista" y devolvía
   el precio base. ~30 referencias corregidas; los ~13 alias post-`AS` (que
   definen el contrato JSON) quedaron intactos.
2. `e03c8a2e` — con lo anterior arreglado el bug SEGUÍA: `resolveActiveList`
   encontraba la lista y la devolvía con `return (array) $list`. La fila es un
   `CaseInsensitiveArray`, o sea un OBJETO, y castear un objeto a array da sus
   propiedades privadas mangleadas, no los campos. `$list['defaultAdjustment']`
   quedaba null → ajuste 0 → precio base. Se reemplazó por `ncmRow()`.

Ninguno de los dos lanzaba excepción ni warning en ninguna capa: el front
atrapa el fallo de `price_resolve` y sigue con precios base a propósito
(`use-price-context.ts:83`). Por eso el síntoma era "la lista no hace nada".

**Lección de método**: la verificación que cerró esto fue ejecutar el SERVICIO
DESPLEGADO de punta a punta, no las queries sueltas. Probar las queries
corregidas por separado daba -20% correcto y ocultaba el defecto del consumidor.

Verificado contra `e03c8a2e` en producción: 1.200.000 → 960.000, 10.000 → 8.000.

Lateral sin resolver: hay DOS listas "Pedidos Ya" duplicadas, ambas con `+20.00`.
Y `data.priceListId` está en null para todos los contactos — el camino
"lista asignada al cliente" no se pudo probar con datos reales, solo el de
lista aplicada a mano en la venta. Vale confirmarlo con el tester.

**T3 — ya estaba resuelto, verificado contra producción**: se ejecutó
`TransactionsService::quotes()` contra la base real y devuelve los netos
correctos (#8: 102.000−6.000=96.000; #7: 151.000−22.650=128.350; #5:
402.500−13.250=389.250). El descuento SÍ se persiste (`transactiondiscount`) y
tanto el listado como `detail()` lo restan. El fix es `27ab36b6` del 2026-07-31
19:51 y la cotización que el tester usó para reportar se creó a las 18:51 — una
hora antes. Mismo caso que T9/T10: probado contra el build viejo.

Ojo al leer ese archivo: `cobros()` (línea ~252) muestra `transactionTotal`
CRUDO y eso es correcto — ahí el total es el monto del pago y el descuento no
aplica. No "arreglarlo" por simetría con `quotes()`.

**T1 — causa raíz y fix (`1f9c8f97`)**: el defecto no era una validación puntual
sino el ORDEN. La venta se creaba y recién después se intentaba registrar el
pago parcial, así que las siete validaciones de `registerPayment` corrían con la
plata ya en la caja. Evidencia: `space_session_payment` tenía 2 filas
`kind='items'` y CERO de `amount`/`share` — ningún cobro por monto libre o por
partes se había registrado nunca. Lo más probable en el caso del tester fue el
bloqueo de familia (esa mesa ya se cobraba por ítems), pero daba igual cuál
tropezara. Ahora un preflight de solo lectura corre las MISMAS reglas
(`validateAndComputeAmount`, definición única compartida con el camino de
escritura) antes de tocar la caja; si rechaza, la venta no se crea y el cajero
lee el motivo real en vez de "avisá al soporte". No cierra la ventana entera
—otro dispositivo puede cobrar en el medio— así que `registerPayment` sigue
siendo la autoridad final y el caller sigue manejando su error.

**Pedidos de producto (no son bugs)** — venta por kg/metros con QR del plato;
clasificar el tipo de venta (Pedidos Ya / Mayoristas) para los reportes;
plantilla de impresión por defecto en cotizaciones; mozo asignado a un espacio;
renombrar/etiquetar espacios; cobrar ítems individuales de una mesa; tecla `O`
para saltar a órdenes (baja); lista unificada de preparación para cocina;
guardar el último costo de compra como referencia (clave para producción: sin
esto, producir antes de cargar la factura da márgenes negativos); línea de
crédito del cliente; pagar facturas a crédito desde Clientes; historial de
transacciones en la ficha del contacto; impresoras A4 y preimpresos; timbrado
por caja; panel de transacciones más detallado; nota de crédito con impresión
o PDF. El módulo de viandas que piden ya está relevado más abajo en este mismo
documento.

**Resueltos el 2026-07-29** (pendientes de confirmación en uso): descuento de
venta visible y con estado en el menú; UUIDs de medios de pago en Control de
Caja (resuelto en las dos puntas + agrupación por nombre, así el mismo medio no
aparece dos veces); cantidades decimales en el carrito; recibo al pagar una
factura a crédito; cotización que salía en blanco; ventas guardadas que tumbaban
la página; y el chat del agente, que ahora muestra los errores que antes se
tragaba.

### Consumo a cuenta de empresa (viandas) — el caso real detrás de "Interno"

Contado por el owner (2026-07-29). **No es una venta interna: es consumo a
cuenta que se factura al cierre del período.** Re-reportado por testers el
2026-07-30 ("pedidos de viandas semanales/mensuales por cliente o empresa,
con resumen de consumiciones por fecha") — no sabían que ya está relevado con
caso de negocio y solución propuesta. Un restaurante con convenio
entrega almuerzos a empleados de una empresa; no cobra en el momento, registra
quién pidió y cuánto; a fin de mes emite UNA factura a la empresa por el total,
y la empresa exige el detalle de consumo por empleado.

**Lo que hacen hoy es un workaround**: cada plato sale como *crédito + interno*
— interno para que no emita factura legal, crédito para que no sume en caja
pero quede como pendiente de pago. Cada cliente recibe un link público con su
estado de cuenta. Funciona por efecto colateral de dos flags, no porque el
modelo lo contemple: hoy además el flag `interno` ni siquiera llega al backend
(ver el ítem de abajo), así que esos consumos están tomando numeración fiscal.

**Solución propuesta** (a validar con el owner antes de ejecutar):

1. **Comprobante como documento propio** (no fiscal, contador propio —
   `registerBoletaNumber` está libre). Es lo que respalda la entrega del plato:
   legitima lo que ya hacen en vez de que un flag ignorado tome números de
   factura. La gift card también emite Comprobante (decisión del owner
   2026-07-29), lo que reemplaza la regla actual "gift card → Recibo".
2. **Empleado → Empresa**: el consumo de un empleado acumula en la cuenta de la
   EMPRESA. Requiere relación padre/hijo entre contactos; verificar si el
   modelo actual de `contact` ya la soporta o hay que agregarla.
3. **Facturación por período**: elegir empresa + rango, juntar los comprobantes
   de consumo de sus empleados y emitir **UNA factura fiscal** por el total,
   dejando cada comprobante vinculado a esa factura
   (`parentTransactionId`). Eso da las dos cosas que el cliente necesita: un
   único documento fiscal y el detalle por empleado como respaldo.
4. **Estado de cuenta público**: ya existe por cliente; extenderlo al nivel
   empresa, consolidando por empleado.

⚠ **Invariantes que hay que respetar o se rompe la contabilidad**:
- Los comprobantes de consumo **no son ventas fiscales** y no deben sumar
  ingresos: al facturar el período, el reporte tiene que contar **la factura O
  los comprobantes, nunca los dos** (doble conteo — el error clásico de este
  modelo).
- La factura del período debe registrar **qué comprobantes cubre**, para
  auditar y para que no se facturen dos veces.
- El consumo no toca la caja; el pago de fin de mes sí.

⚠ **Terminología** (duda planteada por el owner): "Recibo" y "Comprobante" se
parecen. En el código conviene fijarlos como conceptos distintos y que nunca
colisionen: `receipt` = recibo de dinero por pago de una factura a crédito;
`comprobante` = documento no fiscal de entrega/consumo. Mantener la palabra que
ya usa el personal, pero documentar la diferencia donde se define
`PrinterDocType`.

- **El botón "Interno" no hace nada — la venta interna consume numeración
  FISCAL** (2026-07-29, hallazgo). El owner definió el catálogo de documentos:
  Factura · **Comprobante (sin valor fiscal, numeración aparte, se activa con
  "Interno")** · Recibo (pago de crédito) · Nota de crédito (devolución) ·
  Remisión · Cotización · Orden. Verificado contra el código: el Comprobante
  **no existe en ninguna capa**.
  - ~~El front manda `interno` en el payload y el backend no lo lee en ningún
    lado: el flag muere en el borde de la API.~~ **RESUELTO 2026-08-04**
    (mig 118 + `SaleInput::$interno`): la columna `transaction.interno` ya
    persiste, siguiendo el patrón de `ivaRemoved` (mig 101). Es solo el paso
    cero — el Comprobante como documento propio sigue pendiente, y hasta que
    exista la venta interna sigue tomando numeración de factura.
  - Por lo tanto la venta se persiste como contado type 0 y toma
    `registerInvoiceNumber` — o sea, **una venta sin valor fiscal está
    quemando números de factura**, con el agravante de correlatividad
    número↔fecha de `context/29`.
  - La impresión también lo ignora: `pay-dialog.tsx` elige
    `hasGiftcardIssuance ? "receipt" : "factura"`, sin mirar `interno`. Una
    venta interna imprime **Factura**.
  - `PrinterDocType` (`lib/hardware/printers/binding.ts`) no tiene
    `comprobante`; los bindings de impresora no pueden apuntarle.
  - Contador libre disponible: **`registerBoletaNumber` está en el schema y no
    lo usa nadie** (0 referencias) — candidato natural para la numeración
    aparte del Comprobante.
  ⚠ No se implementó: definir tipo de documento fiscal, contador y ruteo es
  decisión del owner. Lo que sí es seguro afirmar es que hoy el botón engaña.
- **Imprimir la venta EN CURSO — falta definir el documento** (2026-07-29).
  La entrada del drawer de opciones sigue sin conectar, a propósito: el intento
  de conectarla la emitía con docType `receipt`, y en este sistema el Recibo es
  un documento FISCAL (respalda el pago de una factura a crédito). Emitirlo para
  una venta que todavía no existe, sin transactionId ni número, genera un papel
  con forma de comprobante fiscal que no respalda nada. Lo que corresponde es un
  documento NO fiscal tipo pre-cuenta, que hoy no está modelado. **Decisión de
  producto pendiente**: qué es ese documento, qué lleva y si necesita plantilla
  propia.
### Relevamiento del cliente — "Módulo caja" (doc del 2026-07-28)

Bugs concretos del documento. Los que el propio doc marca resueltos (gift cards,
control de caja) no se repiten acá. **Verificar cuáles siguen vivos después del
deploy de hoy antes de atacarlos** — varios eran síntomas del P0 de `itemWaste`,
que abortaba la transacción de CUALQUIER venta y la mandaba a la cola offline.

Los cinco bugs de esta lista ya arreglados (vencimiento/pago inicial de
crédito, cotización en blanco, ventas guardadas, decimales, descuento por
artículo) se movieron a "Cerrados en la auditoría del 2026-07-30" al final de
la sección. Quedan acá los que necesitan repro en producción, ver
"Pendientes de reproducción" abajo.

Cubiertos por el trabajo del 2026-07-28, **pendientes de confirmación del
cliente**: el `25P02` en ventas a crédito y el "todas las ventas aparecen sin
conexión" (los dos eran el P0 de `itemWaste`, commit `da46d29f`), y la vista
previa de impresión que no se parecía a la plantilla configurada (catálogo de
bloques, `3cab66b1` — con la salvedad de que razón social, RUC, email y timbrado
todavía no viajan al POS y salen vacíos).

### Pedidos de funcionalidad del cliente (doc "Módulo caja", 2026-07-28)

No son correcciones: son features a planificar y priorizar aparte.

- **Cierre de turno con listado de productos vendidos** imprimible.
- **Panel de transacciones más rico** — el cliente pidió más detalle del que hay
  hoy (adjuntó referencia visual en el doc).
- **Pago de facturas a crédito desde Clientes** y **línea de crédito por cliente**
  (hoy no hay dónde cargarla). Sigue sin implementar — verificado 2026-07-30:
  `frontend/app/(panel)/contacts/[id]/page.tsx` no tiene tab ni lógica de
  pago/cuenta corriente.
- **Transacciones del contacto** dentro de su ficha, para anular o reimprimir.
- **Gift card: permitir cargar un código manual** — el que genera el sistema no
  lo reconoce al canjear.
- **Catálogo del POS en vista vertical** al abrir un grupo desde hotkeys, para
  que se vean imagen y detalle del producto.
- **Espacios**: imprimir pre-cuenta, cambiar/unir espacio, asignar mozo,
  renombrar, etiquetas. (Cobro por partes YA existe: split de cuenta F3.)
- **Órdenes**: notas y etiquetas por pedido; atajo de teclado `O` para saltar a
  órdenes.
- **Pedidos de viandas** (semanales/mensuales por cliente o empresa, con resumen
  de consumiciones por fecha) — es un módulo, no un ajuste. Re-reportado por
  testers el 2026-07-30, ver nota arriba en "Consumo a cuenta de empresa".
- **Chat de soporte embebido** en un costado de la pantalla.
- **Facturación electrónica** — ya en curso, ver
  [28-facturacion-electronica-plan.md](28-facturacion-electronica-plan.md).
  Re-reportado por testers el 2026-07-30 (marcado "se puede ver luego" en su
  doc) sin saber que ya está F0-F2 completo contra API real.

### Pendientes de reproducción (no se pueden verificar leyendo código)

- **Espacios: cobrar la mesa por el total dice "servidor no conectado"** — se
  corrigió la clasificación de 5xx y el timeout el 2026-07-27, pero el error de
  fondo nunca se diagnosticó. Falta: reproducir en un tenant con espacios
  activos y capturar la respuesta real del servidor al cobrar.
- **Al cobrar no se ven medios de pago distintos de Efectivo.** ⚠ NO se
  reproduce (owner, 2026-07-28) — pasa en algún caso particular sin
  identificar. Hoy 2026-07-30 se arregló un 401 de `/v1/payment-methods` que
  hacía caer el POS a los medios de fallback (commit `83854750`) — puede haber
  sido la causa de este reporte. Falta: confirmar con el owner si sigue
  pasando después de este fix.
- **Cierre de caja 500** (`Drawer action error 500: Error al cerrar caja`). El
  doc lo marca resuelto en otro punto pero el mensaje quedó registrado. Falta:
  reproducir el cierre de caja y ver si el 500 todavía sale.
- **El agente no completa la creación de producto** (2026-07-29). El chat ya
  muestra los errores (antes se los tragaba), pero la causa de fondo sigue sin
  identificarse — el logging se agregó recién. Falta: reproducirlo de nuevo y
  revisar los logs server-side del intento.
- **Pago de factura a crédito sin comprobante**: al pagar desde el detalle de
  una transacción a crédito no ofrecía imprimir el recibo de dinero. Falta:
  confirmar si el fix del 2026-07-29 (recibo al pagar una factura a crédito,
  ver nota arriba) ya cubre este caso o si es un flujo distinto.

### Cerrados en la auditoría del 2026-07-30

- **Venta a crédito sin fecha de vencimiento ni pago inicial** — la UI de
  vencimiento y la de pago inicial ya existen
  (`frontend/components/register/pay-dialog.tsx:1060` y `:1145-1195`).
- **Ventas guardadas no se pueden reabrir** — arreglado, commit `229e654c`: el
  GET leía el JSONB con `ncmExecute` y el flatten lo borraba.
- **Cantidades decimales bloqueadas al clickear una línea del carrito** — el
  pad abre en modo decimal
  (`frontend/components/register/qty-edit-dialog.tsx:33-42`).
- **Cotizaciones: la vista previa / PDF sale en blanco** — resuelto el
  2026-07-29, confirmado.
- **Descuento con artículo-descuento no afecta al total** — superado por el
  rework de descuentos del 2026-07-30 (ver abajo): ahora se reparte por ítem
  y queda reflejado en `itemSold.itemSoldDiscount` y en el total real
  cobrado.

**Rework de descuentos y reglas del owner (2026-07-30)**: el descuento
global se repartía sobre el total sin tocar `itemSold.itemSoldDiscount`
(commit `e7a3ad8d`, `frontend/lib/cart/allocate-discounts.ts`, reparto por
resto mayor). El owner cerró dos reglas que una implementación previa mía
había asumido mal (commit `dbbc4aca`): el descuento global congela su
alcance a las líneas presentes al aplicarlo (lo agregado después no se
descuenta), y un producto no puede tener más de un descuento a la vez
(individual bloquea al global). `saleDiscount` pasó a
`{value, mode, lineIds}`. Devoluciones y "cuánto se pagó a crédito" ahora
usan el neto, no el bruto (`9d46ad12`). El toggle "quitar IVA" también
tenía el mismo tipo de bug (persistía mal entre carrito y payload) —
arreglado con mig 101 (`transaction.ivaRemoved`) y `lineGross()` como
fuente única de la fórmula del bruto.

### Auditoría 2026-07-30 — reportes de testers (2 documentos)

Fuente: dos documentos de testers ("Cambios para analizar dentro de Punto" y
"Punto Panel"), con 12 candidatos a bug concretos más una lista larga de
pedidos (ver `_feature-requests.md`). Se verificó cada bug contra el código,
no contra el reporte.

**Confirmados (6):**

- **Lista de precios / descuento de cliente no se aplica en Caja al
  totalizar.** ~~`priceListId` se guarda en el cart store al elegir lista
  manualmente (`frontend/components/register/sale-options-drawer.tsx:739`)
  pero nada lo consume para recalcular precio —
  `frontend/lib/cart/add-catalog-item.ts:39` siempre usa `item.price` plano.
  Tampoco hay auto-aplicación del descuento del cliente seleccionado:
  `PosCustomer` no trae `priceListId`. El backend ya tiene el endpoint
  (`api/v1/price_resolve.php`, `resolvePriceBatch`) y el frontend tiene el
  hook (`frontend/hooks/use-price-lists.ts:107`, `useResolvePrices`) — pero
  ese hook no tiene NINGÚN consumidor en todo el repo. Endpoint construido,
  nunca cableado al carrito.~~ **RESUELTO** (`use-price-context.ts`, commit
  `69ff4014`) — el doc no lo reflejaba.
- **Gift card "no se encontró" al canjear** — no es el problema de código
  manual (ya se puede tipear uno, `giftcard-validation-dialog.tsx:118-132`):
  es un mismatch de case. La emisión fuerza mayúsculas
  (`giftcard-issue-dialog.tsx:136`, `.toUpperCase()`), la validación del
  canje NO normaliza (`giftcard-validation-dialog.tsx:122-126`) y el backend
  hace match exacto case-sensitive (`api/v1/giftcards.php:38-40`,
  `WHERE code = ?`). Un código tipeado en minúscula nunca matchea.
- **Cotización: panel muestra el total sin descuento + "Ver"/imprimir no
  hace nada** — dos causas independientes. (1) `TransactionsService::quotes()`
  (`api/lib/Reports/TransactionsService.php:310`) devuelve `transactionTotal`
  crudo sin restar el descuento, a diferencia de `detail()` que sí calcula
  `netTotal`. (2) el tab "Cotizaciones" del listado de transacciones no tiene
  `onRowClick` (`frontend/components/domain/transactions/transactions-list.tsx`,
  fila ~768-785; comparar con el tab normal en ~738 que sí lo tiene) —
  clickear una cotización no abre nada, por eso "guardar/imprimir desde VER"
  no reacciona: nunca hay un VER.
- **No imprime el cierre de caja al cerrar** — **RESUELTO**
  (`pos-main-menu.tsx:1101-1110`, commit `eac29e7e`).
- **Timbrado 0 / INICIO / VENCIMIENTO / DIRECCIÓN vacíos en la factura
  impresa** — **RESUELTO**: `authNumber`/`authStartDate`/`authExpiration` y
  `outletAddress`/`companyAddress` ya viajan al bootstrap del POS
  (`api/v1/bootstrap.php:100-135`, `build-ticket-data.ts:11-118`).
- **Combo dinámico y combo fijo se agregan como producto suelto, sin
  desplegar las categorías configuradas** — **RESUELTO** por F4 de add-ons
  (`add-catalog-item.ts:38-41`, commit `f71496f6`; sub-líneas indentadas en
  `cart-panel.tsx:921-940`).

**Ya arreglados (5)** — el tester probó antes del fix o no volvió a probar:

- Cantidades decimales al clickear una línea del carrito — mismo flujo ya
  cerrado arriba, "Cerrados en la auditoría del 2026-07-30"
  (`qty-edit-dialog.tsx:33-42`). Verificado que no hay otro flujo de edición
  de cantidad (el buscador de productos solo agrega, no edita).
- Descuento en cotización no persistía — commit `e7a3ad8d`
  (`frontend/lib/commands/create-quote.ts` ahora usa
  `allocateLineDiscounts()` en vez de `discount:0` hardcodeado).
- Producción previa sin poder generar producción — commit `9fffd2b7`
  (2026-07-17), botón "Producir" en el detalle del ítem navega a
  `/produccion?newItemId=`.
- Panel, combo dinámico "pide cambiar a producción" — commit `cddf45fb`
  (2026-07-17): el gate de `showCompounds` estaba ordenado antes del branch
  de `combo_dinamico` y lo tapaba con el mensaje "cambialo a un tipo
  Producción o Combo"; ya se reordenó.
- "Editar Stock en panel Legacy" no carga — commit `3d908f50` (2026-07-17):
  el link muerto al panel PHP borrado se reemplazó por `/stock-adjustment`
  nativo (el botón hoy dice "Ajustar stock", ya no menciona "legacy").

**Matizado (1)**:

- Documentos de impresión sin eliminar / sin "Texto Personalizado" — parcial.
  Eliminar plantilla Y eliminar bloque YA existen
  (`frontend/components/print-templates/template-editor.tsx:151,296-310`), y
  el bloque "Texto Personalizado" (`type: "custom"`) ya permite texto libre
  editable (`block-inspector.tsx:36,56-62`). Lo que sigue sin poder editarse:
  el título/nombre de columna de los bloques dinámicos de tabla de ítems
  (`item_receipt*`) — `block-inspector.tsx:63-72` los deja explícitamente de
  solo lectura. Es la repro que faltaba para el pendiente "Texto
  Personalizado sin repro" anotado en `_session-log.md`.

**No es bug (1)**:

- Conteo de Stock sin filtro por categoría — no hay regresión: ningún módulo
  similar (`/stock-adjustment`, `/bulk-adjustment`) tiene ese filtro tampoco.
  Es un pedido de feature legítimo, ya anotado en el backlog de 2026-07-07 de
  abajo — re-reportado, no nuevo.

### Auditoría 2026-07-31 — reportes de testers, segunda tanda (2 documentos)

Fuente: "Requerimientos_Panel_Punto.docx" + "requerimientos_punto_de_venta.docx".
~70% re-reporta lo ya auditado el 2026-07-30 (mismos testers) — esos ítems NO se
duplican acá; quedan como dato de prioridad. Los pedidos de producto nuevos
están en `_feature-requests.md` §2026-07-31. Bugs nuevos verificados contra código:

**Confirmado (1):**

- **"Ingreso de dinero" en caja se muestra como "Extracción" en el panel.**
  Convención real de la tabla `expenses` (fuente de verdad
  `DrawerService.php:325-410`, `getIncome`/`getExpenses`): `type IS NULL` =
  extracción, `type = 1` = ingreso. Nadie escribe `type = 2` en todo el repo.
  Pero `frontend/app/(panel)/reports/expenses/page.tsx` clasifica con
  `type === 2` (KPIs línea ~100, badge ~173, signo ~229/234) → todo ingreso
  cae al branch extracción. Fix: front pasa a `=== 1`.

**Probablemente ya arreglado (2)** — reverificar post-deploy, el tester probó antes del fix:

- Ventas guardadas "desaparecen" (0 ítems / Gs. 0) — fix `229e654c`
  (2026-07-30, flattenJsonb en `parked-sales.php`).
- Tab Variantes no abre nada al activar el switch — fix `f8d7cd68`
  (2026-07-29). Verificado en esta sesión que el código en main es correcto
  para ítems guardados; si persiste en prod es bundle viejo del service
  worker o deploy pendiente, no código.

**Pendiente de reproducción (3)** — necesitan BD/sesión viva:

- **Espacios: orden "Cobrada" re-suma su subtotal a pendientes** — **no era
  bug**, cerrado 2026-08-22: `space-session-dialog.tsx:90-94` muestra "Total
  de la sesión" (consumo total de la mesa, a propósito); el cobro en sí usa
  `SpaceBalanceService`, que sí excluye lo ya saldado. Eran dos números con
  propósito distinto, no un descuadre.
- **"Sale transaction aborted" al cobrar la última parte de una mesa por
  partes.** NO VERIFICABLE sin repro en vivo, pero hay sospecha fundada tras
  la auditoría de 2026-08-22: `SpaceSettlementService::registerPayment()`
  corre `settleIfCovered()` en una TX ANIDADA que solo hace
  `markPaid()`+`close()` en el último pago del split — exactamente cuando
  aparece el síntoma. `preflightPayment` no elimina la carrera:
  `SpaceSettlementService.php:258-277` documenta que el POS crea la venta
  ANTES de la validación definitiva del pago parcial. Falta repro real.
- **Productos de ambas sucursales mezclados en el catálogo** — ✅ RESUELTO
  2026-08-22 (decisión owner). El POS ya no mezclaba (`outletVisibilityClause()`,
  `ItemsQuery.php:319`, commit `a48c8555`). El listado de Artículos del panel
  (`app/(panel)/items/page.tsx`) ahora también respeta el view-scope global:
  "Todas las sucursales" ve el catálogo completo (comportamiento histórico),
  una sucursal puntual ve solo lo asignado a ella + lo global (`outletId IS
  NULL`), reusando la misma `outletVisibilityClause()`. Opt-in por
  `?respectViewScope=1` en `api/v1/items.php` — el resto de los consumers de
  `/v1/items` (picker de Compras, receta de combos, agente IA) siguen viendo
  el catálogo completo a propósito (ver `context/25-sucursales-y-scopes.md`
  §3/§5).

## Módulos nuevos ✅ (cierre 2026-07-19 / 2026-07-27)

- **Producción v1** ✅ — plan `context/23-production-module-plan.md`. F0 recetas canónicas en `item_compound` (mig 75), F1 `production_order`+`waste_event` (migs 76/77, permiso `production.manage`), F2 UI `/produccion`. Pendiente: v2 (parcial/co-productos/reversa).
- **Órdenes** ✅ (O0-O2) — plan `context/24-orders-module-plan.md`. O0 core (`pos_order`/`order_station`, correlativo advisory-lock, canal realtime `kds`), O1 modal POS (Pagar↔Ordenar, comandas), O2 KDS+display device-paired WS. Pendiente: O3 reservas, O4 ecommerce/agenda.
- **Espacios v1** ✅ (ex Mesas, rename migs 81/82) — plan `context/15-espacios-module-plan.md`. F0/F1 schema+editor (react-rnd), F2 operación POS (mapa, sesión, cobro multi-orden). **F3 split de cuenta ✅ (2026-07-27)**: mig 90 (`space_session_payment`+`settledpaymentid`) + mig 91 (índice único anti doble-cobro) + `SpaceSettlementService`, UI 4 modos (total/por ítems/monto libre/partes iguales), no se mezclan familias de modo en una misma mesa.
  **Hecho 2026-08-21**: toggle pantalla completa en `/pos/espacios` y `/pos/ordenes` (oculta el carrito) — `lib/pos/workspace-store.ts` (zustand+localStorage, preferencia de dispositivo), botón `FullscreenToggle` en ambas barras flotantes. Pendientes sin planificar (owner 2026-08-21, ampliado 2026-08-23) — ver
  `context/_feature-requests.md` § POS/Espacios y § Producción/Cocina para el
  detalle de cada uno: selector de mozo al abrir mesa (falta UI, backend
  listo), unir/cambiar/renombrar/etiquetar espacio desde el POS (**marcado
  importante por el owner**), asignación exclusiva de mesa a un mozo (otros
  operadores no pueden modificarla — cruza con el enforcement de permisos de
  hoy, `hasPermission`/rol `device`, `context/08`), y comanda unificada por
  cantidad (orden de producción para cocina, agrega por ítem y por
  ingrediente — cruza con recetas/`RecipeCosting` y el KDS).
- **Estación de Impresión (pool)** — plan propio `context/26-print-station-plan.md` (cerrado 2026-07-19: estación router tonto device-paired + cola durable `print_job` + opt-in por binding). P0 backend + P1 pantalla ✅. Pendiente P2 (panel + rama pool del pipeline) y P3 (formatos inkjet/matricial). ⚠ Impresoras de RED no alcanzables desde el browser — ver hallazgo en el doc.
- **SLA de tiempo por orden + Delivery (O4)** — plan `context/27-delivery-sla-plan.md` (2026-07-19). **Historial de transiciones F-EVT-0 ✅ (2026-07-27)**: migs 85/86, tabla `pos_order_event` (scope order|item, actor, station snapshoteado), `recordEvent()` en los 6 caminos que tocan status, misma TX — base del SLA. SLA target = máximo por estación (trabajo paralelo entre estaciones). Delivery con `fulfillment`/`out_for_delivery` **✅ completo (2026-07-29)**: F-D-0 (mig 94, snapshot de dirección, selector Mostrador/Retiro/Envío, mapa filtrado) + F-D-1 (mig 96 estado "En camino", mig 97 `courierid`/asignación de repartidor). Fiscalidad del `deliveryfee` resuelta: ítem del catálogo, cascada zona→banda. Abierto: app propia del repartidor (decisión cerrada — entra como usuario con permiso acotado, no device pareado — falta implementar).
- **KDS — rediseño de flujo horizontal (2026-07-27)**: de columnas por estado a comandas en fila única, estado = color (la tarjeta nunca se mueve), pin local, teclado completo, recall (terminadas salen del board, "devolver a preparación" las trae de vuelta). El KDS nunca está desatendido — TV siempre con teclado/mouse detrás.
- **Libreta de direcciones (2026-07-27)**: extendida sobre `customerAddress` existente (mig 87: `reference`+soft-delete), parser de coords centralizado en `lib/geo/parse-coordinates.ts`.
- **Facturación electrónica (SIFEN/Paraguay)** — plan `context/28-facturacion-electronica-plan.md` (2026-07-27/30). Proveedor real: **Factomate** (no Automate). F0/F1/F2/F3/F4/F6/F7 ✅ Hechas (ya figuran así en el propio plan — este doc estaba desincronizado). Verificado contra API real de DEV (2 facturas emitidas). F5 (emisión diferida offline) bloqueada hasta que Factomate responda sobre la fecha de emisión diferida. **`APP_ENCRYPTION_KEY` — ✅ CERRADO (2026-08-23).** Ya está cargada en producción desde hace días — verificado por el owner en el contenedor correcto de la API. El item nació de haber mirado el contenedor equivocado (`api-asqhqb…`, que NO es de Punto). **Timbrado de cajas — deja de ser bloqueante (2026-08-23).** Las cajas de la cuenta de prueba ya lo tienen cargado (3 de 12); las otras 9 son cuentas dummy y se ignoran. Recordar `context/08-convenciones-criticas.md` §56: el timbrado NO bloquea operar, solo es obligatorio con facturación electrónica activa.

---

## `$_SESSION` — RESUELTO (2026-08-22)

**La premisa original de este item ya había muerto**: `/app` y `/panel` ya NO
existen (eliminados en `dbaf0989`, `05aefff4`, `939bcfbb`) — nunca hubo un
F-auth-jwt-only real que correr sobre esas rutas. Lo que realmente seguía vivo
era: (1) `session_start()` incondicional en `api/bootstrap.php` en CADA
request, (2) `RateLimiter` (`api/libraries/rateLimiter.php`) usando
`$_SESSION` como store de contadores, y (3) `loginPart()`
(`api/includes/functions.php`), código muerto que escribía
`$_SESSION['user'][...]` (su único caller vivía dentro de `signUp()`, que a su
vez no tiene callers — el registro real entra por `/v1/signup` →
`SignupService::create()`).

**Hallazgo de seguridad que motivó resolverlo ahora**: el `RateLimiter` de
`/admin` frenaba por IP+email vía `$_SESSION` — sin cookie, cada request
scripteado estrenaba sesión con el contador en 0, así que un atacante nunca
era frenado. Era seguridad decorativa, no solo deuda. Además, detrás de
Traefik TODO request llega con `REMOTE_ADDR = 172.18.0.2` (IP del proxy) —
un store real con key `REMOTE_ADDR` hubiera compartido el límite de
`head.php` (80 req/min) entre TODA la plataforma.

**Resuelto**: `session_start()` sacado de `bootstrap.php` (la API es
stateless — auth son tokens opacos en `auth_session`, ver `context/21`).
`rateLimiter.php` eliminado, reemplazado por:
- `api/lib/Cache/RedisClient.php` (`Punto\Api\Cache\RedisClient`) — conector
  Redis canónico (phpredis), parsea `REDIS_URL`.
- `api/lib/RateLimit/RateLimiter.php` (`Punto\Api\RateLimit\RateLimiter`) —
  ventana fija, `INCR`+`EXPIRE` atómicos vía Lua. Política ante Redis caído
  configurable por caller: `FAIL_OPEN` (`head.php`, no puede tumbar toda la
  API) o `FAIL_CLOSED` (`api/v1/admin/login.php`, 503 antes que dejar pasar
  sin throttle contra bcrypt).
- `api/lib/Http/ClientIp.php` (`Punto\Api\Http\ClientIp`) — resuelve la IP
  real del cliente; solo lee `X-Forwarded-For` si el peer es un proxy
  confiable (loopback/RFC1918), y toma la entrada DERECHA (la izquierda es
  spoofeable).

`loginPart()` y `getUserIpAddr()` eliminadas de `api/includes/functions.php`
(sin callers vivos, la segunda además insegura por confiar en
`HTTP_CLIENT_IP`/extremo izquierdo de XFF). `docker-entrypoint.sh` ya no
escribe `session-redis.ini` (config muerta).

**DB.php y helpers JSONB duplicados app/panel** — **RESUELTO** por la
eliminación de `/app` y `/panel` (mismos commits de arriba): la duplicación
que describía este item ya no puede existir.

## Schema consolidation — campos no-queryables → JSONB ✅ RESUELTO

El patrón `contact`/`item` → columnas indexables + `data JSONB` para el resto
ya está implementado: `db-schema-postgres.sql:243,290`, `Schema::split()`.

---

## Análisis del módulo de inventario / producción / recetas (charlado 2026-06-10)

### Inventory widget — deuda de semántica (bloqueante cierre F2)

Único reporte que no migró en F2. `panel/lib/reports/ReportInventoryService::widget()` delega a
`getAllInventoryAndItemsModule()` (panel/includes/functions.php:2570). El KPI declara "valor de
inventario" (cost, sell, qty) pero su lógica tiene tres problemas:

1. **Fuente cruzada**: itera `getAllItems()` y multiplica por `inventory[id]['cogs'] * inventory[id]['onHand']`,
   donde `onHand` viene de `getAllItemStock` (que lee el ledger `stock`, no la tabla `inventory`).
   Si `stock.stockOnHand` (denormalizado) divergió de `SUM(inventory.inventoryCount WHERE type=0)`,
   el KPI miente sin error.
2. **Sin scope de outlet**: el comentario dice "por sucursal" pero la función NO recibe outletId.
   Suma TODOS los outlets. Para una company multi-sucursal el número es la suma del tenant, no de
   la sucursal activa.
3. **Bug PG conocido** (comentario del Service): el legacy usaba `BETWEEN "..."` con comillas dobles
   que en PG son identificadores, no strings literales — devuelve 0/0/0 silencioso si no se arregló.

**Decisión de producto pendiente:**
- ¿El widget muestra "inventario total del tenant" o "inventario del outlet activo"? Distintos KPIs.
- ¿Suma valor incluye sub-partición depósito? (no debería: el stock por location es sub-partición
  del stock por outlet — sumarlos doble-cuenta. Ver [[project-jerarquia-dominio]]).
- ¿Combos y precombos cuentan? Un combo no tiene stock propio (vende ingredientes); un precombo sí.
  Si se incluyen ambos como "inventario", se valúa dos veces el mismo COGS.

Hasta resolverlo, el widget queda en panel local. Migración técnica = 30 min cuando haya criterio.

### Mapa del módulo (estado actual)

**7 tablas con responsabilidades superpuestas:**

| Tabla | Rol declarado | Quién la escribe | Problema |
|---|---|---|---|
| `inventory` | Batches físicos (count, COGS, expiration) | purchases, count, OutletsService::create, sale (`type=2`=sold) | Es batches FIFO/FEFO pero también tiene `inventoryType` con 3 estados (active/waste/sold) — mezcla concerns |
| `stock` | Ledger de movimientos (event log) | manageStock (god-node, 27 callers), production handler | Tiene `stockOnHand` y `stockOnHandCOGS` denormalizados — running balance dentro del log → fuente cuádruple de "stock actual" |
| `stockTrigger` | Materializado: stock actual por (item, outlet) | a_items.php triggers config, manejo de umbrales | Solo se usa para umbrales de reabastecimiento — su nombre confunde con "running total" |
| `inventoryCount` | Sesiones de conteo físico (auditoría) | a_inventory_count.php | OK — concern aislado |
| `toCompound` | Recetas (item → ingredientes con qty) | CompoundService (F2) | OK — relación N-a-N limpia |
| `toLocation` | Sub-partición del stock por depósito | manageStock | Cumulativo por (item, location) — debería sumar al `stockOnHand` del outlet pero el código no garantiza la invariante |
| `production` | Log de producciones ejecutadas | functions.php:8507 | Concurrencia con `stock` (cada producción escribe AMBAS) — audit duplicado |

**Cuatro fuentes de verdad para "stock actual de item X en outlet Y":**
1. `stock.stockOnHand` del último row (item, outlet) — ledger denormalizado
2. `stockTrigger.stockTriggerCount` — materialized cache
3. `SUM(inventory.inventoryCount) WHERE inventoryType=0` — batches activos
4. `SUM(toLocation.toLocationCount)` — sub-partición depósito (debería ≤ 1, 2 y 3)

**Estas 4 pueden divergir** (no hay constraint que las una). El POS y el panel usan distintas según
el caller — getCurrentStock, getItemMainStock, getAllItemStock, displayableCompounds tienen lógica
propia. Riesgo real de "el stock que ve el panel ≠ el stock que ve el POS".

**God-node `manageStock`** (panel/includes/functions.php:8287 + duplicada en `app/Domain/Inventory.php:349`).
27 callers. Centraliza la escritura del ledger + sub-partición — pero NO actualiza `stockTrigger`
ni `inventory.inventoryCount`. Esas las actualizan otros handlers (purchase, inventory_count,
production) por separado. Implícito y frágil.

**Tipos de item con semántica de stock distinta** (sin clase central que los discrimine):
- `product` → stock normal (descuenta al vender, suma al comprar)
- `combo` → vende los ingredientes (toCompound); el item combo no tiene stock propio
- `precombo` → producto pre-fabricado: se ejecuta producción → suma stock del precombo → al vender descuenta precombo
- `comboAddons` → combo configurable; mismo concepto que combo + selección
- `direct_production` → produce al vender (consume ingredientes al checkout, no antes)
- `compound` → sub-componente; sí tiene stock propio
- `discount`, `giftcard`, `dynamic`, `group` → no stock
- Las reglas viven repartidas en strings inline en a_items.php / a_bulk_*.php / action.php

### Problemas estructurales identificados

1. **Drift entre las 4 fuentes**: nada garantiza consistencia. Una venta offline del POS, una compra
   del panel y un conteo físico pueden dejar las 4 en estados distintos.
2. **`manageStock` duplicada panel vs /app**: dos copias del god-node con riesgo de divergencia
   funcional (ya pasó en el comment original de Inventory.php: "semántica preservada VERBATIM" —
   pero verbatim hoy no garantiza verbatim mañana). Cualquier fix en uno hay que replicarlo.
3. **Tipos de item sin discriminator**: la lógica "si itemType=combo entonces…" está esparcida en
   ~30 lugares. Imposible agregar un tipo nuevo sin cazar todos los if/switch.
4. **toLocation sin invariante**: la suma de `toLocationCount` por (item, outlet) debería igualar
   `stockOnHand`, pero no hay constraint ni trigger. Una venta sin location_id deja la sub-partición
   desactualizada.
5. **Producción tiene dos audits** (production + stock): los reports usan stock con
   `WHERE stockSource='production'` para reconstruir; bug latente si dejan de coincidir.
6. **inventoryType triple-meaning**: 0/1/2 = active/waste/sold mezcla concerns. Sold debería ser un
   evento del ledger, no un estado de batch.
7. **COGS calculation en manageStock** (panel/includes/functions.php:8290 ss): cálculo running de
   COGS promedio ponderado con casos especiales para stock negativo. Complejo y sin test unitario.
   Es money path → cualquier regresión cambia márgenes reportados.

### Propuesta — principios rectores

**1. Una sola fuente de verdad para "stock actual"**. Las otras son **derivadas** con triggers o
   materializaciones explícitas:
   - **Opción A**: `stock` ledger es source of truth. Stock actual = `SELECT stockOnHand FROM stock
     WHERE item, outlet ORDER BY stockDate DESC LIMIT 1`. `inventory` y `stockTrigger` se reconstruyen.
   - **Opción B**: `inventory` batches es source of truth (FEFO/FIFO real). Stock actual =
     `SUM(inventoryCount) WHERE active`. Ledger se mantiene como audit log derivado.
   - **Opción C**: tabla nueva `itemStock(itemId, outletId, count, cost)` con UNIQUE — running total
     materializado, ledger y batches alimentan vía triggers.

   **Recomendación**: opción C. PostgreSQL tiene la herramienta (LISTEN/NOTIFY + triggers); separar
   "balance actual" de "historial" elimina el riesgo de denormalización dentro del log.

**2. `toLocation` con invariante garantizado**. Trigger PG: `SUM(toLocationCount) WHERE item, outlet
   = itemStock.count`. Si el código quiere mover stock entre locations debe hacerlo vía función
   atómica `transferLocation(item, fromLoc, toLoc, qty)`.

**3. Discriminator de itemType**. Tabla `itemTypeRule(type, hasOwnStock, consumesOnSale,
   producesStock, requiresCompound)` — los handlers leen la regla en vez de hardcodear if/else.
   Agregar un tipo nuevo = un INSERT.

**4. `manageStock` único en `/api/lib/Inventory/`** (movible a `Punto\Api\Inventory\Service`).
   El panel y el POS llaman al mismo endpoint o al mismo Service in-process. Cero duplicación.

**5. Eventos del ledger explícitos**:
   - `purchase` → +itemStock, +inventory batch
   - `sale` → -itemStock, -inventory batch (FEFO consume primero)
   - `production` (precombo) → +itemStock(producto), -itemStock(ingredientes) por receta
   - `directProduction` (al vender) → -itemStock(ingredientes), sin afectar item producido
   - `transfer-outlet` → -outletA, +outletB (atómico)
   - `transfer-location` → -locA, +locB (mismo outlet, atómico)
   - `count` → ajuste delta = counted - current
   - `waste` → -itemStock, +log
   - `adjust` → manual

   Cada uno es una función pública del Service con tests propios. `manageStock` legacy se vuelve
   thin dispatcher.

**6. Concurrency**: SELECT FOR UPDATE en itemStock antes de mutar. Hoy el POS offline puede generar
   movimientos concurrentes al sincronizar — sin lock hay risk de last-write-wins silenciosa.

### Plan de migración propuesto (incremental, no big-bang)

| Fase | Qué | Riesgo | Reversible? |
|---|---|---|---|
| **I0** | Documentar invariantes esperados y escribir tests de regresión sobre stock actual para 5 escenarios reales (purchase → sale → production → count → transfer). Sin tocar código. | Bajo | N/A |
| **I1** | Tabla nueva `itemStock` + backfill desde el ledger. Solo lectura por ahora — comparar contra las 3 fuentes existentes con un `/api/v1/diagnostics/stock-divergence` que cuenta divergencias. Telemetría. | Bajo (read-only) | Sí (drop table) |
| **I2** | Servicio `Punto\Api\Inventory\StockService` con métodos por evento (purchase/sale/production/...). Endpoint `/api/v1/inventory/movements?event=X`. Convive con manageStock legacy. | Medio | Sí (revert) |
| **I3** | Triggers PG que mantienen `stockTrigger`, `inventory` aggregate e `itemStock` sincronizados desde el ledger. La tabla `itemStock` pasa a writable solo vía el service. | Medio-alto (triggers en money path) | Trigger DROP |
| **I4** | Itemtype discriminator → tabla + service. Refactor de los handlers que tienen `if itemType=combo`. | Alto (afecta a_items, action.php, bulk_*) | Por slice |
| **I5** | Migración POS: `app/Domain/Inventory::manageStock` deja de escribir directo y llama al endpoint `/api/v1/inventory/movements`. La copia panel se elimina. | Alto (offline mode del POS) | Por endpoint |
| **I6** | Concurrency: SELECT FOR UPDATE + retry policy. inventoryType colapsa a un solo concern (waste/sold pasan al ledger). | Alto | Difícil |

**Timing**: NO ahora. F3 (oleadas legacy) sigue siendo prioridad — F3 toca a_items / a_purchase /
a_bulk_* que son el mismo terreno, hacerlo en paralelo es migrar blanco móvil. Post-F4 es la
ventana correcta. **I0 sí se puede hacer ahora** (documentación + tests sin tocar código) y queda
de plataforma para los demás.

### Pendientes de decisión antes de I0

- ¿Producción y direct_production siguen siendo dos tipos distintos o se colapsan a uno con flag
  "consume al vender vs pre-fabricar"?
- ¿precombo es un caso especial o un alias de producción?
- ¿Permitimos stock negativo en el ledger o lo rechazamos en el service?
- ¿El conteo físico (inventoryCount) corrige hacia el counted o registra un evento "ajuste"?

## Principios del roadmap

- **Progresivo**: cada fase es independientemente deployable.
- **No regresivo**: el código legacy sigue funcionando mientras el nuevo se introduce en paralelo.
- **Smallest safe step**: nada de rewrites completos, solo cambios quirúrgicos y acumulativos.

---

## Estado actual del sistema

| Aspecto | Estado |
|---------|--------|
| Backend | PHP 8.4, sin framework, wrapper PDO propio (`api/includes/lib/DB.php`, sin ORM ni librería externa) ✅ |
| DB | PostgreSQL 16 + Docker, migrations runner automático ✅ |
| Frontend (panel) | **frontend** (Next.js 15 + React 19 + shadcn/ui + TanStack Query). Legacy panel eliminado. ✅ |
| Frontend (POS) | **fusionado dentro de frontend** en `app/(pos)/pos` desde 2026-06-16. ✅ |
| Dominio | **app.punto.la** sirve panel + `/pos` + `/admin` + `/chat` + `/checkout` (POS legacy descontinuado, 2026-06-19) ✅ |
| Auth realms | 3 cookies JWT distintas: `_jwt_panel` (panel, 24h), `_jwt` (pos-app, device pairing 10y), `_jwt_screen` (checkout screen, 10y). Realm gate por `iss` claim. ✅ |
| IDs | UUID v4 (gen_random_uuid) ✅ — v7 fue dropeado en pivot |
| API | `/v1/*` endpoints con envelope canónico, multi-realm allowlist explícita por endpoint ✅ |
| WebSockets | ws-server propio (Node + Redis Pub/Sub) en `ws.punto.la`. Realtime sync panel↔POS funcionando (`<companyId>:invalidate` channel). ✅ |
| Catálogo | M2M completo (category/brand/tag con item_*), UNIQUE case-insensitive, ItemImporter con separadores `\|` (sprint 2026-06-19). ✅ |
| Reportes | Tablas de rollup pre-agregado (`report_rollup`, día/mes/año) — gated por `REPORTS_ROLLUP_ENABLED`. SummaryYear/Categories/Brands/PaymentMethods con cutover + `?verify=1`. ✅ |
| Checkout Screen | Visor al cliente con device pairing por token persistente (`customer_display`), pareo por PIN, realtime via WS. ✅ |
| Agente IA | Chat embebido (FAB + página `/chat`) vía **OpenRouter** (DeepSeek+Gemini default), AI SDK v6, 13 tools acotadas con `confirmToken`, billing débito atómico (`ai_credit_ledger`), historial localStorage con redacción de credenciales + auto-expiración 60s. ✅ AI-1..AI-3b. |
| Seguridad | CORS allowlist, JWT realm isolation, tenant_audit de mutaciones, device revocation, credenciales auto-redactadas en chat. ✅ |

---

## Vista general de fases

```
Phase 0 ✅ → Phase 1 ✅ → Phase 2 ✅ → Phase WS ✅ → Phase UUID ✅ → Phase PG ✅
                                  ↓
              frontend rewrite ✅ (greenfield Next/shadcn, plan context/12)
                                  ↓
                       Fusión POS → frontend ✅ (2026-06-16)
                                  ↓
                       Catálogo M2M ✅ (sprint 2026-06-19, context/14)
                                  ↓
                       Realtime sync ✅ (context/15)
                                  ↓
                       Checkout Screen ✅ (context/16)
                                  ↓
              Reports rollup RB-1+RB-2 ✅ (context/18) — RB-3 pendiente
                                  ↓
                  Agente IA AI-1..AI-3b ✅ (context/17) — AI-4/AI-5 pendiente
```

---

## Orden de ejecución actual (prioridad)

1. **AHORA**: estabilizar el sprint reciente (smoke tests en prod, calibración de pricing del agente, verificación numérica del rollup con `?verify=1`).
2. **Siguiente**: RB-3 (rollup stock/production/commissions/vpayments) + AI-4 (UI /admin para `ai_model_config`).
3. **Después**: AI-5 (OCR de facturas, análisis libre sobre rollup), AI-3 tools extras según uso, dashboards custom por IA.
4. **Paralelo / oportunista**: deuda técnica (JWT-only, schema consolidation) cuando bloquee algo.

---

# Prioridad ALTA (próximas 4-8 semanas)

## F2 — Backend pendiente del POS React (acumulado sesión 2026-06-16)

Estos TODOs están anotados en el código pero requieren backend para completarse. El POS React funciona hoy con stubs.

| # | Pendiente | Detalle |
|---|-----------|---------|
| 1 | ~~**Separar `_jwt_pos` de `_jwt_panel`**~~ ✓ | Cookies independientes ya funcionando (`_jwt` para realm pos-app, `_jwt_panel` para panel, `_jwt_screen` para checkout screen). Memoria [[jwt-two-tokens-rule]]. |
| 2 | **`POST /v1/device/unpair`** | Para "Eliminar dispositivo del comercio" (hoy no existe el endpoint). |
| 3 | ~~**`POST /v1/lock-screen/verify`**~~ ✓ | **RESUELTO**: el lockscreen valida el PIN localmente (SHA-256 contra `pinhash` precacheado, `lock-screen.tsx:8`); ya no hay `STUB_PIN`. |
| 4 | **`bootstrap.user.name` y `bootstrap.user.roleName`** | Agregar al SELECT del bootstrap PHP. `roleName` ya disponible en `UsersService`. Confirmado por ausencia en la auditoría 2026-08-22: `bootstrap.user` sigue siendo `{id, role}`, el operador activo no tiene nombre (la lista de empleados sí). |
| 5 | **Persistir `register.data.mergeRepeated`** | Hoy solo en memoria Zustand (default ON). Falta `PUT /v1/register?resource=merge-repeated`. |
| 6 | ~~**Endpoints reales de Control de Caja**~~ ✓ | Implementado: `DrawerService` + `api/v1/drawer.php`. Migs 33/34. |
| 7 | ~~**Endpoints reales de Transacciones**~~ ✓ | Detalle, edición, duplicar/reimprimir, cierre desde panel. Órdenes O0-O2 ✅ (2026-07-19, ver abajo); Agenda pendiente. |
| 8 | **Persistencia de impresoras** | Probable `register.data.printers` JSONB. |
| 9 | ~~**UI panel para gestión de cajas POS pareadas**~~ ✓ | **Superado**: `connected-device.ts` unifica la tabla con `kind:"pos"` = "Caja POS" en `/settings/devices`. |

---

## frontend — Selector de sucursal en menú del usuario ✅ RESUELTO

**RESUELTO** (2026-08-22): implementado en `app-sidebar.tsx:182-241`. El
detalle abajo queda como referencia histórica del diseño.

### (histórico) NUEVO 2026-06-12

**Feature ausente del frontend que SÍ existe en legacy.** En el menú dropdown del usuario (sidebar bottom) el panel legacy permite, cuando la cuenta tiene ≥2 sucursales:

1. Mostrar el **nombre de la sucursal activa** debajo del nombre de la empresa
2. **Cambiar la sucursal seleccionada** (o elegir "Todas las sucursales")
3. Esa selección **scopea** lo que el usuario ve en el resto del panel

### Reglas de scope por recurso

| Recurso | Comportamiento con sucursal seleccionada | Con "Todas" |
|---|---|---|
| **Stock / inventario** | Solo de esa sucursal | Consolidado de todas |
| **Reportes** (ventas, dashboard, etc.) | Solo de esa sucursal | Consolidado |
| **Cajas / drawers** | Solo de esa sucursal | Todas |
| **Contactos** | TODOS (no filtra — son del tenant, no del outlet) | Igual |
| **Items / catálogo** | TODOS (compartidos del tenant) | Igual |
| **Settings (taxonomies / empresa)** | TODOS | Igual |

### Implementación pendiente

1. **Bootstrap / contexto** — `useBootstrap()` ya trae `outletId` activo. Agregar `outlets[]` (lista para el selector) si no está. Verificar que el JWT ya carga `oid` (claim activo del JWT panel) y si no, exponer endpoint para cambiarlo.
2. **Backend** — chequear que cada endpoint que filtra por outlet:
   - `/v1/reports/*` — filtre por el `outletId` del contexto (debería ya).
   - `/v1/items?resource=inventory` — idem.
   - Para "Todas": pasar `outletId=null` o flag `?all=1` y SERVICE consolida (`SUM` cross-outlet).
3. **Frontend** — `app-sidebar.tsx` (`SidebarFooter` → dropdown user menu) agregar:
   - Subtitle del trigger = `bootstrap.activeOutletName` (en vez del actual vacío)
   - Item del dropdown "Sucursal actual: …" con sub-menu para cambiar
   - Mutation `useSetActiveOutlet(outletId|null)` → POST endpoint que re-emite JWT con nuevo `oid` claim + invalida queries cacheadas
4. **Persistencia** — el outlet activo viaja en el JWT (no en cookie aparte) — al cambiar, re-emitir el JWT panel (mismo handoff que existe ya).

### Por qué es importante

- **UX inconsistente** vs legacy — usuario que ya está acostumbrado a cambiar sucursal del legacy se confunde si en frontend no aparece.
- **Multi-outlet es caso común** — la mayoría de tenants medianos tiene 2-4 sucursales y necesita cambiar de scope diariamente (ej. el dueño revisa los 4 dashboards de la mañana).
- **Bloquea adopción del frontend** para tenants multi-outlet — sin esto no pueden migrar.

### Notas técnicas

- El `outletId` del JWT panel ya existe (`F0` del desacople 2026-06-10) pero falta UI para cambiarlo.
- `apiAuthTenant` ya devuelve `outletId` en el contexto — los services consumen vía `$ctx['outletId']`.
- "Todas las sucursales" probablemente requiere `outletId = null` en el JWT y que cada service haga `WHERE outletId = ? OR ? IS NULL` o branch sin filtro.

---

## Admin realm — super-admins de plataforma separados (iniciado 2026-05-28) — SUPERSEDED

> **Superseded (2026-08-22)**: el estado vivo de `/admin` (dashboard, salud,
> planes, billing, F1-F6) se documenta ahora en
> [34-admin-saas-plan.md](34-admin-saas-plan.md). Lo que sigue abajo queda
> como referencia histórica de la separación de realms (F0-F6 de auth), ya
> cerrada.

**Decisión**: los super-admins de plataforma dejan de ser un "tenant especial" (flag `SAAS_ADM` sobre `MASTER_COMPANY_ID`) y pasan a ser usuarios propios en `admin_user`, con login en `/admin` y JWT criptográficamente separado del realm tenant. Ver [ADR-002](context/adr/ADR-002-admin-realm-separado.md) y `02-arquitectura.md § Admin realm`.

**Dos realms aislados:** `_jwt_panel` (tenant, `JWT_SECRET`) nunca valida en `/admin`; `_jwt_admin` (`ADMIN_JWT_SECRET`, `aud:"admin"`) nunca valida en el panel tenant. Secrets + cookies + audience distintos.

**Login de tenant:** los tenants mantienen su login pero por **TELÉFONO** (no email). `findEmailOrPhoneLogin` ya tiene el branch de phone. El login de tenant NO se depreca, solo cambia el identificador.

**Franchiser:** sigue como realm tenant (`/panel/franchiser.php`, gateado por `isParent`) — NO va a `/admin`.

### 🆕 PIVOTE 2026-06-14 — el /admin se reescribe DE CERO en el stack de frontend

**Decisión del owner**: la UI del realm `/admin` (hoy vanilla JS + Bootstrap en
`panel/admin/*`) se reescribe **greenfield**, **mismo techstack que frontend**
(Next.js 15 App Router + TS + shadcn/ui + Tailwind v4 + TanStack Query).
**NO** nos guiamos por el legacy: no se replica su visual ni su estructura —
se diseña de cero (análogo al pivote del panel tenant → frontend del 2026-06-10).

- El backend del admin (realm aislado `_jwt_admin`/`aud:"admin"`, `adminMiddleware`,
  tablas `admin_user`) **se conserva** — lo que muere es la UI legacy, no la auth.
  Las F0–F6 de abajo quedan como referencia funcional de QUÉ hace el admin, NO de cómo se ve.
- El billing del admin agregado el 2026-06-14 (grantAiCredits / setAddons /
  listRequests / resolveRequest en `CompanyAdminService` + drawer legacy
  `panel/admin`) es **transitorio**: vive en el legacy hasta que exista el shell
  admin en frontend. Migrar esas pantallas al nuevo /admin cuando se construya.
- Prerequisito: auth de realm admin en el `/api` compartido (hoy solo existe en
  `panel/API/v1/admin/*`) o un BFF admin equivalente en el nuevo app, + shell/layout
  + auth-guard del admin en el stack frontend.
- El legacy `panel/admin/*` se borra cuando el nuevo /admin lo cubra 100% (mismo
  criterio que el panel tenant).

### Plan de fases (6 fases, no big-bang — cada una deployable) — UI legacy, ver pivote arriba

| Fase | Qué | Estado |
|------|-----|--------|
| **F0** | Tabla `admin_user` (migración 09) + `bootstrap_seed.php` (CLI idempotente, bcrypt) + vars `.env` (`ADMIN_JWT_SECRET/TTL`, `ADMIN_BOOTSTRAP_EMAIL/PASSWORD`). Verificado E2E en DB local. | ✅ HECHA (commit 01a8929, 2026-05-28) |
| **F1** | Auth del realm `/admin`: login email+pass, JWT propio (`_jwt_admin`, `aud:"admin"`), `adminMiddleware`, `login.html` estático + BFF, rate-limit. | ✅ HECHA (commit 96f8b8f, 2026-05-28) |
| **F2** | CRUD de admins en `/admin` (modelo BFF 3 capas). No permitir desactivar el último admin activo. | ✅ HECHA (commit 89e7388, 2026-05-28) |
| **F3** | Home `/admin` + migrar gestión de companies + billing desde `main.php` (queries cross-tenant aisladas en `lib/admin`). | **✅ COMPLETA — F3.1+F3.2+F3.3+F3.4+F3.5 hechas** |
| **F4** | ⚠️ RIESGO ALTO — desacoplar `SAAS_ADM`/`MASTER_COMPANY_ID` del panel tenant (quitar redirect `@.php:11`, limpiar `config.php`). Va ÚLTIMO porque rompe el gate de identidad legacy. | **✅ HECHA (commit ea7b67f, 2026-06-07)** |
| **F5** | Login de tenant por teléfono (no email) — independiente de F1–F4. | **✅ HECHA (commit ccfa676, 2026-06-07)** |
| **F6** | Decommission de `main.php` como admin + hardening + verificar aislamiento de realms E2E. | **✅ HECHA (commit d310fe4, 2026-06-07)** |


> **Notas técnicas detalladas de F0..F3.5 / F4 / F5 / F6 archivadas** en [_archive-roadmap-completado.md](_archive-roadmap-completado.md) — solo referencia histórica.

---

## ~~Migration Runner~~ ✅

Implementado en `database/migrate.php` + `docker-entrypoint.sh`. Lee `schema_migrations`, compara con `database/migrations/postgres/`, aplica pendientes en orden numérico (no lexicográfico), fail-fast con exit 1. Corre en cada deploy de Coolify antes de exec'ear el CMD.

---

## Sprint 2026-06-19 a 2026-06-21 — entregado

Conjunto grande de slices ejecutados en sesiones consecutivas (Opus orquesta + Sonnet ejecuta + pasada de review). Detalle en `_session-log.md`.

### Catálogo M2M ✅ (plan `context/14`)

- Migs 37 (dedup), 38 (UNIQUE case-insensitive en taxonomy/category/brand/tax), 39 (tabla `tag` + `item_tag` + triggers bidireccionales)
- `Taxonomy::getIdOrInsert` con LOWER comparison + retry ON CONFLICT (race-safe)
- Endpoint `/v1/tags` + `TagService` + branches `resource=brands|tags` en `items.php`
- `/settings/catalog` tab Etiquetas (grid 4-col)
- Form de item con `BrandsPicker` + `TagsPicker` multi-select
- `ItemImporter` acepta listas separadas por `|` en CATEGORIA/MARCA/ETIQUETAS

### Realtime sync panel ↔ POS ✅ (plan `context/15`)

- `realtimePublish` helper PHP wire en `apiAuthTenant::realtimeAfterMutation` (mapeo cerrado endpoint→entity)
- Cliente WS singleton en `frontend/lib/realtime.ts` con reconnect exponential
- `useRealtimeSync(scope)` mapea entity→queryKeys de TanStack
- ws-server deployado en Coolify a `wss://ws.punto.la`
- Convención mantenida: el mapa `realtimeAfterMutation` debe incluir TODO endpoint mutante (caso clásico de drift: `/v1/sales` faltaba)

### Checkout Screen ✅ (plan `context/16`)

- Mig 40 `customer_display` con token persistente (10y)
- Endpoints `/v1/screens/{request,pair,publish,heartbeat,list,revoke}` con Redis fsockopen+RESP (NO phpredis)
- POS publica cart-update/sale-confirmed/cart-cleared
- Sección "Pantalla cliente" en AjustesPanel del POS con dialog InputOTP
- Ruta `(screen)/checkout` standalone (pairing/live/confirmed/idle states)
- `/settings/devices` con CRUD pantallas + revocación

### Reportes rollup pre-agregado — RB-1 + RB-2 ✅ (plan `context/18`)

- Mig 41: `report_rollup` (genérica con métricas fijas + extra JSONB), `rollup_dirty`, funciones `rollup_recompute_period` / `rollup_reconcile`, backfill histórico inline
- Mig 42 extiende a `item_sales`/`item_returns`/`payments`
- Hook `rollupMarkDirty` en `SaleService` (post-CommitTrans) + DrawerService + edición de transacciones
- `RollupReader` con `monthlyBuckets` / `itemSalesRange` / `paymentsRange`
- Cutover `SummaryYearService`/`Categories`/`Brands`/`PaymentMethods` — GATED por `REPORTS_ROLLUP_ENABLED` (default OFF = live; activar tras `?verify=1` con diff vacío)
- **Procedimiento activación** documentado en `context/18` §"Procedimiento de cutover"

### Finanzas Fase 1-3 ✅ (plan `context/22`)

- Fase 1-2: mig 72 (`fin_account`/`fin_category`/`fin_movement`/`fin_check`/`fin_reconciliation`) + Account/Category/Movement/Check/Reconciliation services + endpoints `/v1/finance/*` + UI (Resumen/Cuentas/Categorías/Movimientos/Cheques/Conciliación/Ajustes) + permiso `finance.manage`. Cuenta "Efectivo" del sistema (issystem, mapeo fijo); Ajustes lee medios de pago REALES del tenant (taxonomía `paymentMethod`).
- **Fase 3 (auto-integración, 2026-07-02)**: mig 73 (UNIQUE por `(companyid,source,sourceid,accountid)` para split-payment) + `FinanceLedger` (re-lee la fila de origen; sirve para hook en vivo Y backfill) + hooks best-effort en sales/credit-payments/purchases/transactions + `DrawerService`→`ncmInsert` + backfill CLI + `POST /v1/finance/backfill` (advisory lock) + botón "Importar histórico"
- **Idempotencia de saldo atómica**: `recordDerivedMovement` con `INSERT ... ON CONFLICT DO NOTHING RETURNING` — delta a `currentbalance` una única vez, sin TOCTOU
- **TODO Fase 4**: returns (`transactionType=2/6`) no generan movimiento (sobreestiman ingresos); backfill sin cap de tiempo; dashboard cashflow
- **CRUD de medios de pago ✅ (2026-07-02, branch `pay-methods-crud`)**: `PaymentMethodService` + `/v1/payment-methods` (CRUD sobre `taxonomy` paymentMethod, auto-seed Efectivo/T.Crédito/T.Débito). Tab "Medios de pago" en Settings → Catálogo (`CatalogManager` genérico con `switch`/`select`). `ConfigService::resolveAccountId` dual-path (UUID nuevo vs slug legacy backfill). POS bootstrap trae métodos reales con fallback seguro. `pay-dialog.tsx` re-keyeado a `systemKey` en vez de `id` literal para giftcard/interno
- **Color + orden de medios de pago ✅ (2026-07-02, branch `pay-methods-color-order`)**: medios de pago ganaron color y orden por drag&drop (`dnd-kit`). Paleta unificada (`lib/ui/color-palette.ts`) + `ColorPicker` canonico aplicada tambien a Hotkeys/Usuarios/Impresoras (ver `context/20` §4.8.1)

### Agente IA AI-1..AI-3b ✅ (plan `context/17`)

- Mig 43 `ai_model_config` (capability→model+creditsPerKToken, editable desde /admin — UI pendiente AI-4)
- AI-1: route handler `/api/agent/chat` con AI SDK v6 + OpenRouter (DeepSeek default), tool `get_sales_summary`, FAB + Sheet
- AI-2: gate 402 + débito atómico en `/v1/ai/debit` (lock FOR UPDATE) + ledger `agent_chat`
- AI-3: 13 tools (5 lecturas + 8 escrituras con `confirmToken`) — alcance acotado por memoria [[ai-agent-scope-limits]] (NO ventas/caja/permisos/bulk/hard-delete)
- AI-3b: refinement UI (FAB neutro, MessageCircle, input ChatGPT-style, página `/chat` con sugerencias, integración al menú POS sin overlay conflict)
- Markdown render + acciones (copiar / leer Web Speech API)
- Historial persistente (Zustand persist + localStorage, hook `useAgentChat` compartido FAB/página)
- Credenciales: formato dictado por system prompt + auto-expiración real 60s + redacción ANTES de persistir (defense in depth)

### Bug fixes notables del sprint

- Wrapper PDO: agregados `Affected_Rows`, `BeginTrans/CommitTrans/RollbackTrans` (services del API los llamaban con esos nombres del wrapper legacy que la clase actual todavía no exponía)
- `Taxonomy::getIdOrInsert` usaba `$SQLcompanyId` global vacío en /api → duplicados en imports (commit `6ec65bb`)
- `transactions.php` detalle: `CaseInsensitiveArray` no es `JsonSerializable` → frontend recibía `{}` (fix con `GetRowAssoc()`)
- Cookies JWT mezcladas tras cambio de dominio frontend-dev → app.punto.la: nuevo `/v1/logout` para borrar solo `_jwt_panel`
- `screens.php` usaba `new Redis()` (extension phpredis no instalada) → fix con fsockopen+RESP igual que `wsPublish`
- Mig 37 inicial asumía columnas que no existen en BD real (`outlet.taxId`, `outlet.categoryId`) → self-heal con `information_schema` check
- Barra de categorías POS con ancho fijo (no se ve fea con 1 sola)
- Teléfonos: display nacional, backend E.164 (`formatPhone` helper)
- Logout del menú sidebar tenía `onClick={onLogout}` pero la prop nunca se pasaba → wire en `PanelAuthGuard`

---

# Prioridad MEDIA (2-4 meses)

## Phase AI — Agente IA del producto

> **Estado actual (2026-06-21):** AI-1, AI-2, AI-3 y AI-3b ✅ ENTREGADOS en el sprint reciente — chat embebido funcional en producción. Stack: OpenRouter (NO Anthropic SDK directo como originalmente planeaba este doc), AI SDK v6 + `@ai-sdk/react`, React/Next embebido en frontend (NO microservicio FastAPI). Ver memorias [[ai-agent-openrouter-direction]] y [[ai-agent-scope-limits]] + plan `context/17-ai-agent-plan.md` para arquitectura real.
>
> El texto debajo refleja la VISIÓN ORIGINAL — útil como referencia conceptual (correctitud, dashboards por IA, reportes ad-hoc) pero los detalles de implementación están desactualizados (FastAPI, Python, microservicio). Lo que SÍ aplica vivo: la regla de oro (tool calling determinista, NUNCA text-to-SQL libre) y la visión de "reportes ad-hoc + dashboards custom por IA" (AI-5 pendiente).

### Pendientes reales de Phase AI

> **Norte de largo plazo (2026-07-30):** `context/30-ai-agent-roadmap.md` — el
> agente como capa transversal del ERP: registry de tools por dominio + router
> de dos etapas (floor tools + dominios lazy), OCR de facturas/menús, CRUDs por
> sectores, web_search y análisis sobre rollups. AI-5 queda subsumido en las
> fases F2/F5 de ese doc.

| Slice | Scope | Prioridad |
|---|---|---|
| ~~**AI-4**~~ ✓ | **RESUELTO**: pricing editable en `/admin` (`api/v1/admin/ai-config.php`, `AiAdminService::testModel()`). | — |
| **AI-5** | **Parcial**: OCR de facturas ya implementado (`PurchaseDraftService.php`, tabla `purchase_draft`, mig 105). Sigue pendiente: análisis libre sobre rollup (queries NL → `report_rollup`), dashboards custom guardados en `dashboard.config` JSONB. → detalle en `context/30` (F2/F5). | Media |
| **AI-tools++** | Expandir las 13 tools iniciales según uso: tools para reportes específicos, búsquedas avanzadas, recomendaciones (top sellers, stock bajo). | Media |
| **AI proactivo** | Cron que detecta condiciones (stock bajo, ventas anómalas) y notifica al operador. Necesita canal — el FAB del agente puede mostrar un badge. | Baja |
| **AI multi-canal** | Telegram / WhatsApp / SMS. Mismo agente, otros transports. Requiere vincular usuario externo → JWT del tenant (flow de pareo). | Baja |

### Histórico — visión original (referencia conceptual)

## Phase AI.1 — Agente IA básico (HISTÓRICO — ver sección anterior para estado real)

**Problema**: El valor diferencial del producto es ser AI-first. Sin agente funcional
no hay diferenciación.

**Dependencia**: backend de los módulos consultados expuesto como Services/API limpia (las tools del agente leen de ahí). Refuerza la estrategia backend-first de `02-arquitectura.md`: cada módulo modernizado le da más superficie de datos al agente. NO requiere modernizar el frontend de los reportes — el agente los reemplaza.

**Cliente LLM**: OpenRouter (no Anthropic directo), SDK `openai` apuntando a OpenRouter. El "tool use" es function calling estándar (formato OpenAI).

### Visión

Un agente autónomo que habla con la API de Punto via JWT. Los usuarios interactúan con el sistema por chat (widget web, Telegram, WhatsApp) en lenguaje natural. El agente interpreta la intención, llama los endpoints correctos y devuelve respuestas formateadas.

**El agente tiene dos facetas que comparten la misma base** (tools deterministas + LLM orquestador):

1. **Chatbot / asistente genérico** — el usuario pregunta sobre sus datos ("¿cómo van las ventas?", "¿qué producto se vende menos?"), pide recomendaciones ("¿qué debería reponer?", "¿conviene este combo?") y obtiene ayuda operativa. Conversacional, libre.

2. **Analista de datos** — reemplaza la proliferación de reportes hardcodeados (~13K líneas en `report_*`). El usuario pide reportes ad-hoc en NL y **dashboards customizados**: describe qué quiere ver, la IA arma la estructura, se guarda, y se renderiza en vivo. Ver "Reportes y dashboards por IA" abajo.

**Decisión (2026-05-24)**: el agente es el reemplazo de los reportes exploratorios, no un chatbot decorativo. Los reportes legales/contables (facturación, libro de ventas, impositivos) se quedan hardcodeados — formato exacto y auditable, la IA no aporta ahí.

### Regla de oro — correctitud y seguridad (NO negociable)

En finanzas/inventario los números deben ser **exactos**; un dato mal calculado hace que el dueño decida con info falsa.

- **Tool calling determinista, NUNCA text-to-SQL libre.** El LLM elige entre funciones acotadas y parametrizadas (`ventas_periodo(desde, hasta, agrupar_por)`, `top_productos(n)`, `stock_bajo()`). Cada tool ejecuta SQL fija filtrada por `companyId`. El LLM **no escribe SQL ni hace aritmética** — solo decide qué preguntar y presenta el resultado.
- **Por qué**: text-to-SQL libre = fuga multi-tenant (leer otra company), queries que tumban la DB, y JOINs mal hechos = números errados con cara de correctos.
- **Aislamiento de tenant**: el JWT del usuario fija `companyId`; toda tool lo aplica en el WHERE. El LLM nunca recibe ni elige el `companyId`.

### Reportes y dashboards por IA

- **Reporte ad-hoc**: pregunta NL → el LLM elige tool(s) → datos exactos → respuesta formateada (texto + tabla/gráfico).
- **Dashboard custom**: el usuario describe ("ventas diarias del mes, top 5 productos, alertas de stock bajo") → la IA genera una **config** (JSON: lista de widgets, cada uno = una tool + params) → se guarda la config (`dashboard.config` JSONB por usuario/company) → el dashboard se renderiza **en vivo**, cada widget llama su tool.
- **KPIs guardados = DEFINICIONES, no valores.** Se persiste la fórmula/tool/params del KPI, nunca el número calculado (quedaría viejo y, si lo calculó la IA, podría estar mal). Los datos son siempre frescos y deterministas.
- La IA hace el trabajo creativo (estructurar) **una vez**; los números vienen de queries cada vez.

### Arquitectura

```
Telegram / WhatsApp / Widget Web
         ↓
    punto-agent/  (microservicio Python + FastAPI)
    ├── Interpreta intención (LLM via OpenRouter — function calling)
    ├── Llama tools deterministas → panel/API/* con JWT del usuario
    └── Formatea y devuelve respuesta (texto / tabla / config de dashboard)
         ↓
    panel/API/  (los endpoints existentes, sin modificar)
```

### Por qué esto funciona sin tocar el monolito

El agente solo necesita el JWT del usuario y los endpoints. No sabe nada de PHP ni de la base de datos. La API de Punto es su única interfaz.

### Tools de Claude (cada tool = un endpoint)

```python
tools = [
    {
        "name": "get_sales_report",
        "description": "Obtiene ventas de un período",
        "input_schema": {
            "properties": {
                "date_from": {"type": "string"},
                "date_to": {"type": "string"}
            }
        }
    },
    { "name": "get_stock_level", ... },
    { "name": "create_order", ... },
    { "name": "get_customers", ... },
    # ~20 tools para los casos de uso más frecuentes
]
```

### Casos de uso iniciales

| Faceta | Ejemplo de input | Action |
|--------|-----------------|--------|
| Chatbot | "mandame el cierre de hoy" | `ventas_periodo(hoy)` → resumen formateado |
| Chatbot | "cuánto stock me queda de Coca Cola" | `stock_nivel` con filtro |
| Chatbot (recomendación) | "¿qué debería reponer?" | `stock_bajo` + razonamiento sobre el resultado |
| Analista (reporte ad-hoc) | "ventas del mes pasado por categoría" | `ventas_periodo(..., agrupar_por=categoria)` → tabla/gráfico |
| Analista (dashboard) | "armame un dashboard con ventas diarias, top 5 productos y stock bajo" | genera config JSON → se guarda → render en vivo |
| Escritura (AI.3+) | "registrá una venta de 2 hamburguesas" | `create_order` |
| Proactivo (AI.5) | (sin trigger) stock bajo detectado | Alerta automática |

### Stack técnico

```
punto-agent/
├── main.py              # FastAPI app
├── agent.py             # Lógica del agente (Claude tool use)
├── tools/
│   ├── sales.py         # Wrappers para endpoints de ventas
│   ├── inventory.py     # Wrappers para items/stock
│   └── orders.py        # Wrappers para órdenes
├── channels/
│   ├── telegram.py      # python-telegram-bot
│   └── whatsapp.py      # Meta Cloud API o Twilio
└── auth.py              # Vincula usuario Telegram/WA → JWT de Punto
```

### Auth del agente

```
Usuario envía /start en Telegram
    → Bot genera código de vinculación de 6 dígitos
    → Usuario ingresa el código en el panel de Punto
    → Panel registra: telegram_id ↔ companyId + JWT
    → El agente usa ese JWT para todas las llamadas futuras
```

### Fases de implementación

| Fase | Scope | Prioridad |
|------|-------|-----------|
| AI.1 | Agente básico (OpenRouter) + widget web chat + 5 tools de solo lectura (ventas, items, stock, clientes) | Alta |
| AI.2 | **Reportes ad-hoc por NL** — el chatbot responde preguntas de datos con tablas/gráficos (reemplaza reportes exploratorios) | Alta |
| AI.3 | **Dashboards customizados** — IA genera config JSON, se guarda en `dashboard.config`, render en vivo. KPIs = definiciones | Alta |
| AI.4 | Recomendaciones (reponer stock, combos, productos lentos) sobre los datos de las tools | Media |
| AI.5 | Integración Telegram + bot de reportes | Media |
| AI.6 | Tools de escritura (crear órdenes, registrar ventas) | Media |
| AI.7 | WhatsApp (Meta Cloud API) | Media |
| AI.8 | Alertas proactivas (cron que monitorea + notifica) | Media |
| AI.9 | Contexto persistente por usuario (memoria conversacional) | Baja |

**Esfuerzo MVP (AI.1)**: ~2 semanas. AI.2/AI.3 reusan las mismas tools — el costo es el frontend (chat + render de tablas/dashboards), no nuevo backend.

---

## CDN Local completo

**Problema**: Algunos assets todavía referencian CDNs externos. Offline-first requiere todo local.

**Propuesta**: Mover todo a `/assets/vendor/`. Ya hay avance parcial.

**Esfuerzo**: ~4 horas para completar

---

## Higiene de assets — vendoring vía npm (EN CURSO 2026-05-27)

**Objetivo**: gestionar los ~55 vendor JS de `assets/vendor/js/` vía `package.json` (provenance,
versión pineada, `npm audit`) en vez de archivos "misteriosos" commiteados. Alpine ya entró así.

**Plan**: `npm i --save-exact pkg@<versión-vendoreada>` para los npm-ables; `build.sh` (o un
`vendor-sync`) copia el dist canónico de `node_modules/` a `assets/vendor/js/`. **Pinear EXACTO**
(no bumpear — jQuery 3.6.3 / Chart 2.9.4 / Bootstrap 3.4.1 están congelados a propósito).

**Quedan como archivo** (no en npm / custom): `iguider`, `jquery.businessHours-1.0.1` (npm
`business-hours` es otro paquete) + el código propio del proyecto (`ncm.js`, `common.js`,
`documentPrintBuilder`, `ncmMaps`, etc. — no son vendor).

**Bumps de major a testear** (npm no tiene la versión vieja o difiere): `@fingerprintjs/fingerprintjs`
v3→v5, `jsrsasign`, `snap` (verificar snap.svg vs el "snap-1.9.3" vendoreado).

### Follow-up: reemplazar iguider (tour de onboarding)

`iguider` (~107 refs en dashboard/purchase/app POS) no está en npm y parece medio abandonado (hay
un `iguider.stub.js` → puede estar ya stubbeado). **Reemplazo recomendado: `driver.js` 1.4.0 (MIT,
~5KB, sin deps, vanilla).** Shepherd/intro.js son **AGPL** → descartados para SaaS comercial cerrado.
**Antes de migrar: verificar si el tour está activo** — si está muerto, ELIMINAR iguider en vez de
reemplazarlo. Es scope aparte (cambio de comportamiento del tour).

---

# Prioridad BAJA (largo plazo)

## Phase 6 — Arquitectura moderna (Slim 4)

**Dependencia**: Phases 1-5

**Problema**: El monolito PHP sin framework dificulta testing, middleware chains, DI.

**Propuesta**: Introducir Slim 4 como app paralela bajo `/v2/...`. Un endpoint a la vez.

**Esfuerzo**: Setup inicial ~2 días. Migración gradual.

**Riesgos**: Complejidad de mantener dos stacks. Solo hacer cuando haya masa crítica de endpoints nuevos.

---

## Phase AI.4+ — WhatsApp, alertas proactivas, memoria

Items futuros del agente IA. Ver sección "Phase AI.1" arriba para detalle completo.

---

# Decisiones técnicas vigentes

| Decisión | Elección | Razón |
|----------|----------|-------|
| Lenguaje backend | PHP (mantener) | Sin capacidad de rewrite completo |
| WebSockets | ws-server propio (Node.js) | Eliminar costo de Pusher |
| Pub/Sub | Redis | Ya en el stack, sin dependencia extra |
| JWT | HS256 custom PHP | Sin composer dependency adicional |
| IDs en API | UUID v7 (post Phase UUID) | Hashids deprecados, enc/dec son identity |
| API location | Dentro de panel/ (mantener) | No vale la pena separar aún |
| AI Agent | Microservicio Python separado | No tocar el monolito |
| Conexión BD legacy | `panel/API/lib/legacy_db.php` helper | Centraliza migración MySQL→PG sin tocar lógica |


---

# Variables de entorno completas

```ini
# Seguridad (Phase 0)
APP_DEBUG=false
HASHIDS_SALT=<random-64-char>

# JWT (Phase 1 + 2)
JWT_SECRET=<random-64-char>
JWT_TTL=28800

# WebSocket (Phase WS)
WS_URL=wss://ws.tudominio.com
REDIS_URL=redis://redis:6379

# AI Agent (Phase AI — pendiente)
ANTHROPIC_API_KEY=sk-ant-...
TELEGRAM_BOT_TOKEN=...
WHATSAPP_ACCESS_TOKEN=...
PUNTO_API_BASE=https://panel.tudominio.com/API
AGENT_JWT_SECRET=...

# Feature flags (Phase 6 — pendiente)
USE_V2_ITEMS=false
USE_V2_CONTACTS=false
```

---

# Notas técnicas importantes

## Patrón de migración endpoints `api_head.php` → envelope canónico

```php
// ANTES
include_once('api_head.php');
// ... lógica ...
jsonDieResult($data, 200);

// DESPUÉS
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
// ... lógica sin cambios ...
apiOk($data);
```

## Notas de `api_middleware.php`

```php
// CRÍTICO: $db debe ser global antes de incluir db.php y functions.php
// porque functions.php llama getAllPlans() en scope global en línea 3
global $db, $ADODB_CACHE_DIR, $plansValues, $countries;

// enc()/dec() no están en functions.php, están redefinidos en el middleware
// (en post-Phase UUID, son identity passthrough)
```

## Helper `legacy_db.php` (para endpoints MySQL→PG)

Reemplaza el bloque MySQL hardcoded por:

```php
require_once __DIR__ . '/lib/legacy_db.php';
```

Que internamente:
- Carga `cors.php`
- Conecta a PG via `includes/db.php` (creds desde `.env`)
- Carga `simple.config.php` + `functions.php`
- Define `enc/dec` defensivamente como identity

## Estructura del proyecto

```
system/
├── app/                    # POS — módulo de caja (PHP legacy)
├── panel/                  # Admin/ERP (PHP legacy)
│   ├── API/                # ~93 endpoints REST
│   │   ├── lib/
│   │   │   ├── response.php       # Envelope canónico ✅
│   │   │   ├── api_middleware.php # Middleware JWT ✅
│   │   │   └── legacy_db.php      # Helper conexión PG para endpoints legacy ✅
│   │   └── auth.php               # Login JWT ✅
│   ├── includes/
│   │   ├── simple.config.php      # Constantes globales
│   │   ├── jwt.php                # JWT HS256 puro PHP
│   │   ├── db.postgres.php        # Conexión PG (legacy)
│   │   ├── db.pdo.php             # Conexión PG (PDO — actual)
│   │   └── ws_publish.php         # Publica eventos a Redis
│   └── standalone/
│       └── scripts/
│           └── ncm-ws.js          # Cliente WebSocket (drop-in Pusher) ✅
├── ws-server/              # Microservicio Node.js WebSocket ✅
├── database/
│   ├── migrations/postgres/
│   └── seeds/
├── docker-compose.yml      # PostgreSQL + Redis + pgAdmin + ws-server
└── context/                # Kit de contexto para Claude (este roadmap está acá)
```

---

## Feature request — entidad fiscal separada del contacto (2026-08-21, sin planificar)

**Separar "quién compra" de "a quién se factura".** Hoy `contact` mezcla el
cliente con sus datos de facturación, así que N personas distintas que
facturan a la misma empresa se cargan como N contactos que repiten RUC,
razón social y dirección fiscal — redundancia real en la BD, y hace que
reportes como Cuentas por Cobrar partan al mismo receptor en varias filas.
El modelo correcto es una entidad fiscal (receptor) propia, con relación N:1
desde el contacto: el contacto es la persona con la que se opera, la entidad
fiscal es a quién sale el documento. Impacta facturación electrónica (el
receptor del DE), cuentas por cobrar y el buscador de clientes del POS.
Observación del owner 2026-08-21: un RUC repetido NO implica contacto
duplicado — es un caso legítimo y frecuente.

---

## Backlog testing 2026-07-07 — Panel + POS (feedback testers)

**Re-reportado casi íntegro por testers el 2026-07-30** (doc "Punto Panel") —
tres semanas después, sigue sin atacarse nada de esta lista. Es señal de
prioridad, no una lista nueva. Los pocos puntos que SÍ son nuevos del batch
2026-07-30 (reporte de gift card editable, columna de suma al pie en
transacciones, columna de etiquetas internas, orden de pedido consolidado
para cocina, tipo de venta/canal) están en `_feature-requests.md` §2026-07-30.

**Actualización 2026-08-22**: varios ítems de esta lista cruda ya se
resolvieron y quedaron marcados como CERRADO en `_feature-requests.md`
(categoría inline, sesiones de servicio, columnas stock/costo, kardex, packs
de compra, subcategorías de gastos) — el detalle y la evidencia viven ahí,
no se duplican acá.

**Fiscal/Reportes:**
- Export RG90 / Libro de ventas (pedido 2x)
- Filtros en transacciones (contado/crédito/internas, cajero, cliente, documento) + columnas tipo doc/método/caja
- Detalle de documento enriquecido (tipo, número, emisión, vencimiento, sucursal, cliente, responsable, IVA desglosado, descuentos)
- Reporte productos detallado (usuario/cliente/documento/fecha por venta)
- Export + imprimir en todos los reportes
- Notificación de facturas crédito por cobrar
- Reporte de transferencias de stock (usuario, sucursal, receptor, productos, fecha) formato nota de remisión
- Medios de pago vista detallada (documento, cliente, RUC, método, sucursal, total)

**Catálogo/Inventario:**
- Crear categoría inline desde el form de artículo
- Sesiones configurables para servicios tipo paquete
- Stock mínimo con notificación automática
- Columnas stock actual + costo de stock en listado de artículos
- Historial de movimientos por artículo
- Imprimir listado de artículos
- Conteo de stock filtrado por categorías
- Producción generable desde producción previa
- Movimiento de inventario → link al documento origen + columna ingresos

**Compras/Gastos:**
- Packs de compra (1 caja = N unidades)
- Categorías de gastos con subcategorías
- Recordar último costo de compra por producto
- Factura de compra contado vs crédito

**Otros:**
- Comisiones por usuario (Gs o %)
- Control de cajas imprimir/PDF + edición por rol admin/jefe
- Timbrado y prefijos en config de caja registradora desde el panel
- Posicionamiento fino de bloques en editor de plantillas (arriba/abajo/ancho/alto)

---

> Items completados archivados en [_archive-roadmap-completado.md](_archive-roadmap-completado.md).
