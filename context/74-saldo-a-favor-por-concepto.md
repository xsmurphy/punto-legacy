# 74 — Saldo a favor por concepto (cantina escolar)

> Estado: **plan sin implementar** (2026-09-08). Nace de un caso concreto de
> posible cliente. **D1 es BLOQUEANTE y es fiscal, no técnica**: hasta que no se
> cierre, el modelo de datos no se puede fijar. D2-D7 propuestas SIN OK del
> owner.

## 1. El caso

Cantina de un colegio. Los padres:

- Pagan **mensualmente el almuerzo** de sus hijos.
- Algunos **adelantan dinero** para el consumo del alumno en la cantina.

Lo que el comercio pide:

1. Al **pagar el padre**, se emite la **factura legal**.
2. Ese dinero entra como **dos saldos a favor separados**: uno de almuerzo,
   otro de consumo en cantina.
3. Cada vez que el **alumno consume**, se debita del saldo correspondiente.
4. Esas salidas **NO vuelven a aparecer como venta** en los reportes — si no,
   se duplica el ingreso entre lo que pagó el padre y lo que consumió el hijo.

El punto 4 es el que suena difícil y es el que **ya está resuelto** (§2). El
trabajo real está en 2 y 3.

## 2. Qué YA existe (verificado en código, 2026-09-08)

El mecanismo de saldo a favor existe de punta a punta en el BACKEND, heredado
del legacy:

| Pieza | Dónde | Qué hace |
|---|---|---|
| Saldo del cliente | `contact.contactStoreCredit` (`db-schema-postgres.sql:226`, `DECIMAL(15,2)`) | Un escalar por contacto |
| Acreditación | `SaleService::persistInCreditItem()` (`api/lib/Sales/SaleService.php:1165`) | Ítem `inCredit` en la venta suma saldo. **Es una venta con su factura** |
| Débito | `SaleService` payment loop (`:1146`) + `Customer.php:104` | Pago `type='storeCredit'` resta saldo |
| No duplica en reportes | `Reports\NonAddingSales::compute()` | Cuantifica gift/storeCredit/points/internal como "ventas que NO suman" |
| Expuesto al panel | `Reports\SalesService.php:136` | Sale como `nonAddingToSales.totalGiftCredit` |
| No mueve caja | `Finance\FinanceLedger.php:270` | Debitar saldo NO genera movimiento: la plata entró cuando pagó el padre |
| Alias del medio de pago | `PaymentMethodResolver.php:203` | `storeCredit` e `inCredit` → `billetera` |

**Conclusión sobre el punto 4 del pedido**: la no-duplicación no hay que
construirla. El diseño ya reconoce el ingreso UNA vez (cuando el padre paga) y
trata el consumo como entrega contra un saldo ya vendido.

### 2.1 Hallazgo que cambia el alcance

**El POS nuevo no tiene NINGUNA superficie para esto.** `grep storeCredit` en
`frontend/` solo devuelve el campo de lectura (`PosCustomer.storeCredit`,
`lib/types/pos-bootstrap.ts:437`) y fixtures. No existe:

- Forma de **cargar** saldo desde la caja (el ítem `inCredit` no se puede armar
  desde el carrito).
- **Medio de pago** "saldo a favor" en el diálogo de cobro.
- Vista del saldo del cliente al vender.

O sea: el backend está, la caja no. Cualquier fase de este plan incluye
construir la UI, no solo el modelo.

## 3. Los huecos reales

1. **Un solo bolsillo.** `contactStoreCredit` es UN escalar. El caso exige DOS
   saldos que no se mezclen (almuerzo / cantina). Es el cambio estructural
   principal: de columna a **ledger por concepto**.
2. **Sin restricción de uso.** Hoy el saldo paga cualquier ítem. "El saldo de
   almuerzo solo paga almuerzo" no existe como concepto.
3. **Sin vínculo padre↔alumno.** No hay relación entre contactos en el modelo
   (verificado: no existe `parentId` ni tabla de vínculo). Hoy el saldo es del
   contacto que compró; acá paga uno y consume otro.
4. **Sin superficie en el POS** (§2.1).

## 4. Decisiones abiertas

### D1 — Con qué IVA se factura el anticipo · **BLOQUEANTE, fiscal**

Si la factura se emite al recibir el adelanto, **el IVA se devenga en ese
momento** — pero todavía no se sabe qué va a consumir el alumno. Si el almuerzo
y los productos de cantina tienen tasas distintas (10 / 5 / exento), no está
definido con qué tasa se factura.

Salidas posibles, a confirmar con el contador del colegio:

- **(a)** El anticipo se factura como un ítem de servicio con **tasa única**
  acordada (ej. almuerzo 10%). Simple, pero solo válido si el contador lo
  avala y si cada bolsillo es homogéneo en tasa.
- **(b)** Se factura **al consumir**, y el pago del padre es una cobranza sin
  factura (recibo). Contradice el pedido explícito del cliente.
- **(c)** Bolsillos **definidos por tasa**, no por concepto comercial: el
  padre carga "saldo 10%" y "saldo exento". Fiscalmente limpio, comercialmente
  confuso.

**Nada del modelo de datos se puede fijar antes de esto**: si la respuesta es
(b), el ledger de saldos deja de ser el centro del diseño.

### D2 — De quién es el saldo: del padre o del alumno

El que paga y el que consume son personas distintas. Dos modelos:

- **(a) Saldo del ALUMNO, pagado por el padre.** El alumno es un `contact`, el
  padre es el pagador de la factura. Necesita vínculo padre→alumno para que el
  padre vea/cargue el saldo de sus hijos, y para que un padre con 3 hijos no
  cargue en el bolsillo equivocado.
- **(b) Saldo del PADRE, consumido por sus hijos.** El alumno se identifica en
  la caja y el débito va contra el saldo del padre. Un padre con varios hijos
  tiene un pozo común; el colegio pierde el corte por alumno.

Recomendación: **(a)**. El colegio necesita saber cuánto consumió CADA alumno
(es el dato que le reclama el padre), y el vínculo sirve igual para (b).

### D3 — Cómo se define qué puede pagar cada bolsillo

El saldo de almuerzo solo paga almuerzo. Cómo se expresa esa regla:

- Por **categoría de ítem** (reusa taxonomía existente, sin schema nuevo).
- Por **flag en el ítem** (más preciso, más carga de datos).
- Por **lista explícita** de ítems elegibles por concepto.

Recomendación: **categoría**, con el concepto declarando qué categorías
acepta. Es el mecanismo que ya existe y el que el comercio ya mantiene.

### D4 — Qué pasa si el saldo no alcanza

- ¿El alumno puede consumir **a crédito** (saldo negativo) y el padre lo salda
  después? Eso convierte el bolsillo en cuenta corriente y ya existe
  (`contactCreditLine`) — pero mezcla dos mecanismos.
- ¿O se **rechaza** el consumo en la caja?
- ¿O se **cobra la diferencia** en efectivo en el momento?

Recomendación: rechazar por defecto, con la diferencia cobrable en efectivo
como pago mixto (el POS ya soporta pago mixto).

### D5 — Qué documento sale cuando el alumno consume

No puede salir una factura (ya se facturó al padre — sería doble facturación).
Sale un **comprobante interno de entrega**, no fiscal. Alineado con
`context/49-kude-y-portal-cliente.md`: el ticket por defecto ya es comprobante
interno no fiscal.

Falta decidir si ese comprobante se imprime siempre, a pedido, o nunca (el
alumno no necesita papel; el padre necesita el resumen del mes).

### D6 — Saldo no consumido a fin de año

¿Se devuelve, se arrastra, o vence? Si se devuelve, es una devolución sobre una
factura ya emitida (nota de crédito, `context/40`). Si vence, hay que definir
el reconocimiento del ingreso de lo no consumido. **Tiene consecuencia fiscal**
— no es una decisión de producto.

### D7 — Feature genérica o de rubro

El caso es escolar, pero "saldo a favor por concepto" sirve a gimnasios (pases),
clubes, empresas con comedor. Decidir si se construye genérico desde el
principio o acotado a este cliente. Costo de generalizar ahora: bajo. Costo de
generalizar después: alto (migración de datos vivos).

Recomendación: genérico. El vocabulario del dominio (padre/alumno) queda en la
UI, no en el schema.

## 5. Fases propuestas (sujetas a D1)

Asumiendo que D1 cierra en (a) o (c):

- **F0 — Ledger de saldos por concepto.** Tabla `contact_credit_ledger`
  siguiendo el patrón de `ai_credit_ledger` (`db-schema-postgres.sql:781`):
  `delta` + `balanceAfter` + `reason` + `meta`, append-only, saldo = SUM.
  Catálogo de conceptos por company. `contactStoreCredit` queda como
  **proyección derivada** (mismo criterio que `context/34` D2 con las columnas
  de módulos): el POS y los reportes ya lo leen, no se rompe nada.
- **F1 — Carga de saldo desde la caja.** Superficie en el POS para vender un
  concepto de saldo (el ítem `inCredit` con el concepto), con su factura.
- **F2 — Cobro con saldo.** Medio de pago "saldo a favor" en el diálogo de
  cobro, con selección de bolsillo, validación de elegibilidad (D3) y saldo
  visible del cliente.
- **F3 — Vínculo padre↔alumno** (según D2), con la vista del padre.
- **F4 — Resumen de consumo por alumno** para el padre (el reporte que el
  colegio va a necesitar el primer mes).

Offline: la carga y el consumo de saldo son estado COMPARTIDO entre cajas — un
alumno podría consumir en dos puntos a la vez. Aplica el criterio de
`project_offline_scope`: lo que necesita estado compartido puede bloquearse sin
red. **No inventar un saldo local optimista.**

## 6. Arquitecturas rechazadas (leer antes de proponer nada)

- **Registrar el consumo del alumno como venta normal y "restarlo después" en
  el reporte.** Es exactamente el doble conteo que el cliente quiere evitar, y
  ya está resuelto por `NonAddingSales` — el consumo no suma al total desde el
  diseño, no por una resta a posteriori.
- **Agregar `contactStoreCreditAlmuerzo` y `contactStoreCreditCantina` como
  columnas.** Dos bolsillos hoy, cinco el año que viene; sin historial de
  movimientos no se puede responder "por qué mi hijo tiene este saldo", que es
  la primera pregunta del padre. Ledger, no columnas.
- **Un `contact` por bolsillo** (el alumno duplicado, uno para almuerzo y otro
  para cantina). Rompe el corte por alumno, duplica datos personales y
  contamina todos los reportes de clientes.
- **Facturar al padre Y al consumir.** Doble facturación del mismo ingreso.

## 7. Invariantes

- El **ingreso se reconoce UNA vez**: cuando el padre paga. El consumo nunca
  suma al total de ventas.
- El **stock SÍ se descuenta** al consumir: la mercadería sale del inventario
  aunque no haya ingreso nuevo. Son dimensiones distintas y las dos son
  correctas.
- Debitar saldo **no mueve caja** (`FinanceLedger.php:270`) — es la cancelación
  de un pasivo con el cliente, no un cobro.
- El saldo es **por company**: nunca se cruza entre tenants ni entre sucursales
  de comercios distintos.
