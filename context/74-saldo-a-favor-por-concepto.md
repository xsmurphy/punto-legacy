# 74 — Adelantos y saldo a favor por concepto

> Estado: **plan sin implementar** (2026-09-08). Nace del caso de una cantina
> escolar, pero **D1 lo convirtió en un motor genérico de ADELANTOS**: giftcard,
> crédito interno y los bolsillos por concepto son el mismo mecanismo con
> distinto portador. D1 CERRADA por el owner (2026-09-08). D2-D7 propuestas SIN
> su OK.

## 1. El caso que lo origina

Cantina de un colegio. Los padres pagan **mensualmente el almuerzo** y algunos
**adelantan dinero** para el consumo del alumno en la cantina. Lo pedido:

1. Al **pagar el padre**, se emite la **factura legal**.
2. Ese dinero entra como **dos saldos a favor separados**: almuerzo y cantina.
3. Cada vez que el **alumno consume**, se debita del saldo correspondiente.
4. Esas salidas **NO vuelven a aparecer como venta** — si no, se duplica el
   ingreso entre lo que pagó el padre y lo que consumió el hijo.

El punto 4, que suena difícil, **ya está resuelto** (§2). El trabajo real está
en 2 y 3.

## 2. Qué YA existe (verificado en código, 2026-09-08)

### 2.1 Giftcard y crédito interno son EL MISMO mecanismo

Observación del owner que el código confirma: **una giftcard es un adelanto de
dinero**, igual que el crédito interno. Se emiten en el MISMO loop de venta
(`SaleService.php:2100-2112`), una al lado de la otra, y se consumen igual.

| | Giftcard | Crédito interno |
|---|---|---|
| Portador del saldo | un **código** (anónimo, transferible) | un **contacto** (nominal) |
| Dónde vive el saldo | `giftCardSold.giftCardSoldValue` | `contact.contactStoreCredit` |
| Cómo se emite | línea de venta `giftcardId` → `sellGiftCard()` | línea de venta `type='inCredit'` → `persistInCreditItem()` (`:1165`) |
| Cómo se descuenta | `GREATEST(value - ?, 0)` (`SaleService.php:1240`) | `UPDATE` resta (`Customer.php:104`) |
| Consumo parcial | **sí** | sí |
| En reportes | `NonAddingSales` lo excluye del total | idem (`NonAddingSales.php:68-70`) |
| Mueve caja al consumir | no | no (`FinanceLedger.php:270`) |

Las dos son escalares mutados in-place, **sin historial de movimientos**.
`NonAddingSales` ya las trata idénticamente junto con `points`.

**Consecuencia para este plan**: no se construye un mecanismo nuevo al lado de
giftcard — se construye **el motor que ambas deberían haber compartido siempre**,
y giftcard migra a él (§5).

### 2.2 Lo que eso ya resuelve

El punto 4 del pedido (no duplicar el ingreso) **no hay que construirlo**: el
diseño ya reconoce el ingreso UNA vez, al vender el adelanto, y trata el consumo
como entrega contra algo ya vendido.

### 2.3 Hallazgo que cambia el alcance

**El POS nuevo no tiene NINGUNA superficie para saldo a favor.** `grep
storeCredit` en `frontend/` solo devuelve el campo de lectura
(`PosCustomer.storeCredit`, `lib/types/pos-bootstrap.ts:437`) y fixtures. No
existe forma de **cargar** saldo desde la caja ni **medio de pago** "saldo a
favor" en el cobro. El backend está, la caja no. Giftcard sí tiene flujo en
`pay-dialog`.

## 3. D1 — CERRADA (owner, 2026-09-08): dos modos de facturación

> Lo correcto es facturar cuando el producto o servicio se entrega, pero la
> percepción del cliente es distinta: quiere su factura en el momento de pagar.
> Punto soporta **los dos**, y el comercio elige.

### Modo A — se factura al recibir el adelanto (percepción del cliente)

- La carga de saldo **es una venta**, con su factura, en el momento del pago.
- El consumo **no suma** a ventas (`NonAddingSales`) y sale con comprobante
  interno no fiscal.
- Es el comportamiento que Punto ya tiene hoy para giftcard e `inCredit`.
- **Tensión fiscal a declarar**: el IVA se devenga sin saber qué se va a
  consumir. Si el comercio vende con tasas distintas (10 / 5 / exento), el
  bolsillo tiene que ser **homogéneo en tasa** o el comercio asume la
  diferencia. Se resuelve con D3 (el concepto declara qué acepta) y se le
  advierte al configurar.

### Modo B — se factura al entregar (correcto contablemente)

- La carga de saldo **NO es una venta**: es una **cobranza anticipada** — un
  pasivo con el cliente. Sale un recibo de anticipo, no una factura.
- El consumo **SÍ es la venta**, con su factura y su IVA real, y **SÍ suma** a
  ventas.
- Al consumir no entra plata: el pago es contra el anticipo ya cobrado.

### La inversión que esto implica (lo que hay que no perder de vista)

Los dos modos **invierten dónde se reconoce el ingreso**, y eso atraviesa toda
la cadena:

| | Modo A | Modo B |
|---|---|---|
| Documento al cargar | Factura | Recibo de anticipo |
| Documento al consumir | Comprobante interno | **Factura** |
| Ingreso suma al cargar | **sí** | no |
| Ingreso suma al consumir | no (`NonAddingSales`) | **sí** |
| Movimiento de caja | al cargar | al cargar |
| IVA | congelado en la carga | real, en el consumo |

Modo B **no es un flag sobre el modo A**: es el camino inverso, y `NonAddingSales`
deja de aplicar al consumo. Cualquier implementación que trate B como "A con un
if" va a duplicar o a perder ingresos.

### Invariante crítica — el modo se congela en el SALDO, no en la configuración

Si el comercio cambia de modo con saldos vivos, los saldos cargados bajo A (ya
facturados) se volverían a facturar al consumirse: **doble facturación de un
ingreso ya declarado**.

Por eso el modo **viaja con el saldo**, no con la company: cada carga registra
bajo qué modo nació y el consumo se comporta según ESE valor, no según el
switch de hoy. Es exactamente el patrón que el proyecto ya usa para el IVA
congelado por venta (`context/38`) y para el timbrado congelado en la
transacción (`context/29`). Cambiar el switch afecta solo a las cargas nuevas.

## 4. Los huecos reales

1. **Un solo bolsillo.** `contactStoreCredit` es UN escalar; el caso exige dos
   saldos que no se mezclen. Cambio estructural principal: de columna a
   **ledger por concepto**.
2. **Sin historial.** Ni giftcard ni crédito interno guardan movimientos: el
   saldo se pisa. No se puede responder "por qué mi hijo tiene este saldo",
   que es la primera pregunta del padre.
3. **Sin restricción de uso.** El saldo paga cualquier ítem. "El saldo de
   almuerzo solo paga almuerzo" no existe.
4. **Sin vínculo padre↔alumno.** No hay relación entre contactos (verificado:
   no existe `parentId` ni tabla de vínculo). Paga uno, consume otro.
5. **Sin superficie en el POS** (§2.3).

## 5. Decisiones abiertas

### D2 — De quién es el saldo: del padre o del alumno

- **(a) Del ALUMNO, pagado por el padre.** Necesita vínculo padre→alumno.
- **(b) Del PADRE, consumido por sus hijos.** Pozo común; el colegio pierde el
  corte por alumno.

Recomendación: **(a)** — el colegio necesita saber cuánto consumió CADA alumno,
que es el dato que le reclama el padre.

### D3 — Cómo se define qué puede pagar cada bolsillo

Por **categoría de ítem** (reusa taxonomía existente), por flag en el ítem, o
por lista explícita. Recomendación: **categoría**, con el concepto declarando
cuáles acepta. Es lo que el comercio ya mantiene, y en modo A es además lo que
permite mantener el bolsillo homogéneo en tasa.

### D4 — Qué pasa si el saldo no alcanza

¿Consumo a crédito (saldo negativo), rechazo en la caja, o cobro de la
diferencia? Recomendación: **rechazar, con la diferencia cobrable en efectivo**
como pago mixto (ya soportado). Saldo negativo mezcla este mecanismo con la
cuenta corriente, que ya existe (`contactCreditLine`).

### D5 — Qué documento sale al consumir (modo A)

Comprobante interno de entrega, no fiscal — alineado con `context/49`. Falta
decidir si se imprime siempre, a pedido o nunca (el alumno no necesita papel;
el padre necesita el resumen del mes).

### D6 — Saldo no consumido a fin de año

¿Se devuelve, se arrastra o vence? **Tiene consecuencia fiscal distinta por
modo**: en A el ingreso ya se declaró (devolver = nota de crédito,
`context/40`); en B el anticipo es un pasivo y su vencimiento reconoce un
ingreso sin entrega. Giftcard ya tiene `giftCardSoldExpires` — mismo problema,
sin resolver.

### D7 — Alcance de la unificación con giftcard

Tres niveles posibles:

- **(a)** Motor nuevo solo para conceptos; giftcard queda como está. Rápido,
  deja dos mecanismos casi iguales (justo lo que la regla de arquitectura del
  proyecto prohíbe).
- **(b)** Motor nuevo; giftcard migra después, con su tabla como proyección
  derivada (patrón `context/34` D2).
- **(c)** Motor nuevo y giftcard migra en la misma tanda.

Recomendación: **(b)**. Giftcard está en producción con datos vivos y flujo en
`pay-dialog`; migrarla en la misma tanda que un motor nuevo multiplica el
riesgo sin necesidad. Pero el motor se diseña desde el principio para
soportarla — el portador (código vs contacto) es un campo, no una bifurcación.

## 6. Fases propuestas

- **F0 — Motor de adelantos.** Tabla `advance_ledger` siguiendo el patrón de
  `ai_credit_ledger` (`db-schema-postgres.sql:781`): `delta` + `balanceAfter` +
  `reason` + `meta`, append-only, saldo = SUM. Catálogo de conceptos por
  company, cada uno con su modo (A/B) y sus categorías elegibles. **El modo se
  graba en cada movimiento de carga.** `contactStoreCredit` queda como
  proyección derivada (el POS y los reportes ya la leen: no se rompe nada).
- **F1 — Carga de saldo desde la caja**, con la bifurcación de D1: en modo A
  emite factura (camino actual de `inCredit`), en modo B emite recibo de
  anticipo sin sumar a ventas.
- **F2 — Cobro con saldo.** Medio de pago "saldo a favor" en el diálogo,
  selección de bolsillo, validación de elegibilidad (D3), saldo visible. En
  modo B esta venta factura normal.
- **F3 — Vínculo padre↔alumno** (según D2) y vista del padre.
- **F4 — Resumen de consumo por alumno** — el reporte que el colegio va a
  necesitar el primer mes.
- **F5 — Migración de giftcard al motor** (según D7).

Offline: cargar y consumir saldo es **estado compartido entre cajas** (un
alumno podría consumir en dos puntos a la vez). Aplica `project_offline_scope`:
puede bloquearse sin red. **No inventar un saldo local optimista.**

## 7. Arquitecturas rechazadas (leer antes de proponer nada)

- **Registrar el consumo como venta normal y "restarlo después" en el reporte.**
  Es el doble conteo que se quiere evitar, y ya está resuelto por
  `NonAddingSales`: el consumo no suma desde el diseño, no por una resta a
  posteriori.
- **Agregar `contactStoreCreditAlmuerzo` y `contactStoreCreditCantina` como
  columnas.** Dos bolsillos hoy, cinco el año que viene, y sin historial no se
  puede explicar un saldo. Ledger, no columnas.
- **Un `contact` por bolsillo** (el alumno duplicado). Rompe el corte por
  alumno, duplica datos personales, contamina los reportes de clientes.
- **Facturar al padre Y al consumir.** Doble facturación del mismo ingreso —
  es exactamente lo que pasa si el modo se lee de la configuración actual en
  vez del saldo (§3).
- **Modo B como un `if` dentro del modo A.** Los dos modos invierten dónde se
  reconoce el ingreso; tratarlos como variantes del mismo camino duplica o
  pierde ingresos según el lado.
- **Un mecanismo nuevo al lado de giftcard.** Son el mismo concepto (§2.1);
  duplicarlo contradice la regla de arquitectura del proyecto y deja dos
  motores de saldo que se van a desincronizar.

## 8. Invariantes

- El **ingreso se reconoce UNA vez**, en el momento que dicta el modo con el
  que nació ESE saldo.
- El **modo viaja con el saldo**, nunca se lee de la configuración vigente.
- El **stock SÍ se descuenta** al consumir, en los dos modos: la mercadería
  sale del inventario aunque no haya ingreso nuevo. Son dimensiones distintas y
  las dos son correctas.
- Debitar saldo **no mueve caja** (`FinanceLedger.php:270`): es la cancelación
  de un pasivo con el cliente, no un cobro.
- El saldo es **por company**: nunca se cruza entre tenants ni entre comercios.
