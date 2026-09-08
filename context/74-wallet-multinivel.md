# 74 — Wallet multi-nivel (titular + sub-cuentas)

> Estado: **plan sin implementar** (2026-09-08). Empezó como "saldo a favor de
> una cantina escolar" y el owner lo elevó a **módulo propio y genérico**: una
> wallet de titular con sub-cuentas, transferencias internas, histórico y topes
> de consumo. **D1 y D2 CERRADAS** por el owner. D3-D9 propuestas SIN su OK.
>
> Renombrado desde `74-saldo-a-favor-por-concepto.md` (el alcance ya no es un
> saldo, es un módulo).

## 1. Qué es

Un **titular** (un contacto) tiene una wallet, le carga saldo, y **abre
sub-cuentas** a las que transfiere parte de ese saldo. Sobre cada sub-cuenta
conserva control: ve el histórico de consumo y le fija **topes** (diario,
semanal, por período).

El caso que lo originó es una cantina escolar —el padre carga, los hijos
consumen— pero el owner lo quiere genérico. Aplica igual a:

- Gimnasio o club: socio titular y su grupo familiar.
- Empresa con comedor: la empresa carga, cada empleado consume con su tope.
- Flota: la empresa carga combustible, cada chofer tiene su sub-cuenta.

**Vocabulario del módulo: titular y sub-cuenta.** "Padre" y "alumno" son la UI
de un rubro, nunca el schema (misma regla que `markets.ts` con lo que cambia
por país).

## 2. El caso que lo origina

Cantina de un colegio. Los padres pagan mensualmente el almuerzo y algunos
adelantan dinero para el consumo en la cantina. Piden:

1. Al **pagar el padre**, factura legal.
2. El dinero entra como **dos saldos separados**: almuerzo y cantina.
3. Cada **consumo del alumno** debita del saldo que corresponde.
4. Esas salidas **no vuelven a aparecer como venta** (si no, se duplica el
   ingreso entre lo que pagó el padre y lo que consumió el hijo).

El punto 4, que suena difícil, **ya está resuelto** (§3.2).

## 3. Qué YA existe (verificado en código, 2026-09-08)

### 3.1 Giftcard y crédito interno son EL MISMO mecanismo

Observación del owner que el código confirma: **una giftcard es un adelanto de
dinero**, igual que el crédito interno. Se emiten en el MISMO loop de venta
(`SaleService.php:2100-2112`), una al lado de la otra.

| | Giftcard | Crédito interno |
|---|---|---|
| Portador | un **código** (anónimo, transferible) | un **contacto** (nominal) |
| Dónde vive el saldo | `giftCardSold.giftCardSoldValue` | `contact.contactStoreCredit` |
| Cómo se emite | línea de venta `giftcardId` → `sellGiftCard()` | línea `type='inCredit'` → `persistInCreditItem()` (`:1165`) |
| Cómo se descuenta | `GREATEST(value - ?, 0)` (`SaleService.php:1240`) | `UPDATE` resta (`Customer.php:104`) |
| Consumo parcial | sí | sí |
| En reportes | excluido del total (`NonAddingSales.php:68-70`) | idem |
| Mueve caja al consumir | no | no (`FinanceLedger.php:270`) |

Las dos son **escalares mutados in-place, sin historial**. La wallet es el motor
que ambas debieron compartir siempre; giftcard migra a él después (D9).

### 3.2 Lo que eso ya resuelve

El punto 4 del pedido **no hay que construirlo**: el diseño ya reconoce el
ingreso UNA vez y trata el consumo como entrega contra algo ya vendido.

### 3.3 Lo que NO existe — los huecos

1. **Un solo bolsillo, sin jerarquía.** `contactStoreCredit` es un escalar por
   contacto. No hay sub-cuentas ni vínculo entre contactos (verificado: no
   existe `parentId` ni tabla de vínculo).
2. **Sin historial.** El saldo se pisa. No se puede responder "por qué mi hijo
   tiene este saldo" — que es literalmente lo que el módulo promete mostrar.
3. **Sin topes, y sin precedente de topes.** `contactCreditLine` existe pero
   **solo se muestra formateado** (`CustomerService.php:121,189,238`): ningún
   camino de venta lo enforcea. Los topes de la wallet serían el primer límite
   que Punto evalúa al vender.
4. **Sin transferencias.** No hay movimiento de saldo entre contactos.
5. **Sin superficie en el POS.** `grep storeCredit` en `frontend/` solo devuelve
   el campo de lectura (`lib/types/pos-bootstrap.ts:437`) y fixtures: no hay
   forma de cargar saldo ni medio de pago "saldo". Giftcard sí tiene flujo en
   `pay-dialog`.
6. **Sin superficie para el titular** — ver D5, es la pieza más grande.

## 4. D1 — CERRADA (owner): dos modos de facturación

> Lo correcto es facturar cuando se entrega, pero la percepción del cliente es
> distinta: quiere la factura al pagar. Punto soporta **los dos** y el comercio
> elige.

**Modo A — factura al cargar.** La carga es una venta con factura. El consumo no
suma a ventas (`NonAddingSales`) y sale con comprobante interno. Es lo que Punto
ya hace con giftcard e `inCredit`. *Tensión fiscal a declarar*: el IVA se devenga
sin saber qué se va a consumir; el bolsillo debe ser homogéneo en tasa (D4) o el
comercio asume la diferencia.

**Modo B — factura al entregar.** La carga NO es venta: es cobranza anticipada,
un pasivo con el cliente, con recibo de anticipo. El consumo SÍ es la venta, con
su IVA real, y SÍ suma a ventas.

### Los modos invierten dónde se reconoce el ingreso

| | Modo A | Modo B |
|---|---|---|
| Documento al cargar | Factura | Recibo de anticipo |
| Documento al consumir | Comprobante interno | **Factura** |
| Ingreso suma al cargar | **sí** | no |
| Ingreso suma al consumir | no (`NonAddingSales`) | **sí** |
| Movimiento de caja | al cargar | al cargar |
| IVA | congelado en la carga | real, en el consumo |

Modo B **no es un flag sobre A**: es el camino inverso y `NonAddingSales` deja
de aplicar al consumo. Implementarlo como "A con un if" duplica o pierde
ingresos.

### Invariante: el modo se congela en el SALDO, no en la configuración

Si el comercio cambia de modo con saldos vivos, lo cargado bajo A (ya facturado)
se volvería a facturar al consumirse: **doble facturación de un ingreso ya
declarado**. El modo viaja con cada carga; el consumo se comporta según ESE
valor, no según el switch de hoy. Mismo patrón que el IVA congelado por venta
(`context/38`) y el timbrado congelado en la transacción (`context/29`).

## 5. D2 — CERRADA (owner): la jerarquía es el modelo

Queda descartada la disyuntiva anterior ("¿el saldo es del padre o del
alumno?"): **son las dos cosas, en jerarquía**. Wallet del titular → sub-cuentas
→ el titular transfiere y controla.

Consecuencias directas:

- El **vínculo entre contactos deja de ser un extra** y pasa a ser el corazón
  del módulo.
- La **transferencia interna** es una operación de primera clase: no es venta,
  no es cobranza, **no mueve caja** — es un par de movimientos atómicos
  (débito en origen, crédito en destino) dentro del mismo tenant.
- El **corte por sub-cuenta** sale gratis del ledger: es la pregunta que el
  titular hace todos los meses.

## 6. Decisiones abiertas

### D3 — Jerarquía y concepto: ¿un eje o dos?

El caso pide sub-cuentas (por alumno) **y** conceptos (almuerzo / cantina). Son
ejes distintos y hay que decidir cómo se cruzan:

- **(a) El concepto ES la sub-cuenta.** El titular abre "Juan-almuerzo" y
  "Juan-cantina". Simple de implementar, combinatorio para el usuario: 3 hijos
  × 2 conceptos = 6 sub-cuentas que administrar a mano.
- **(b) Sub-cuenta por persona, con bolsillos por concepto adentro.** Dos
  niveles de jerarquía más una dimensión de concepto. Es el modelo que el
  usuario describe en voz alta ("el saldo de almuerzo de Juan"), y el corte por
  persona y por concepto salen los dos.
- **(c) Solo sub-cuentas, sin concepto**, y la restricción de qué se puede
  comprar se resuelve por categoría de ítem sobre la sub-cuenta.

Recomendación: **(b)**. Es más caro que (a) pero (a) empuja la combinatoria al
usuario y no sobrevive al segundo hijo. En el ledger es una columna más, no una
tabla más.

### D4 — Cómo se restringe qué puede pagar cada bolsillo

Por **categoría de ítem** (reusa taxonomía existente), por flag en el ítem, o
por lista explícita. Recomendación: **categoría**, con el concepto declarando
cuáles acepta. Es lo que el comercio ya mantiene, y en modo A es lo que permite
mantener el bolsillo homogéneo en tasa.

### D5 — Cómo entra el titular a ver y controlar

El titular necesita ver saldos, histórico y fijar topes. **La credencial de
cliente final YA EXISTE en el schema vivo** — el legacy la usaba para el login
de compradores del módulo ecommerce (dato del owner, 2026-09-08):

- `contact.contactPassword CHAR(68)` + `salt`, con
  `PanelAuth::checkPassword()` — el mismo mecanismo que hoy autentica al dueño
  del comercio.
- El rewrite de auth (`context/21`) dejó **`realm` como columna** de
  `auth_session`: sumar un realm es el mecanismo previsto, no una excepción.

Lo que falta, y es acotado:

1. **El resolver excluye a los clientes final por diseño.** `findPhoneLogin`
   (`api/includes/functions.php:2609`) filtra `type = 0 AND ownerRoleSql` — solo
   el dueño del tenant. Un contacto cliente (`type = 1`) no puede loguear hoy.
2. **No queda código del login de ecommerce** en el repo (se fue con el panel
   legacy). El modelo de datos sobrevivió, la superficie no.
3. **Un realm `customer` nuevo**, con su alcance: un titular solo ve SU wallet y
   las sub-cuentas que cuelgan de ella.

Sigue siendo trabajo de auth y aplica el MANDATO de no mezclar realms
(`feedback_pos_token_only_no_realms`, tres incidentes de la misma clase): el
endpoint del titular no acepta cookie de panel ni Bearer de device, y viceversa.

Alternativa barata para la primera iteración: **link firmado por sub-cuenta**,
extendiendo el patrón del portal de facturas (`context/28` F6, anónimo con token
firmado). Sirve para LEER; **no para escribir topes** — un link que cambia
límites es una credencial permanente circulando por WhatsApp.

Recomendación: **link firmado para lectura en la primera iteración, realm
`customer` para escritura**. Con el modelo de credencial ya en el schema, el
realm dejó de ser el costo que parecía y puede entrar antes de lo previsto.

### D6 — Topes: qué se limita y cómo se evalúa

Sin precedente en el código (§3.3). Dos problemas duros, ninguno de UI:

1. **Carrera entre cajas.** Dos cajas simultáneas leen "lleva 5.000 de 10.000",
   las dos aprueban 6.000, el alumno consume 11.000. El tope **tiene que
   evaluarse y aplicarse atómicamente server-side**, en la misma transacción
   que descuenta el saldo — nunca leyendo y decidiendo en el cliente.
2. **Offline.** Una caja sin red no sabe cuánto se consumió en otra. Aplica
   `project_offline_scope`: el saldo y los topes son **estado compartido y
   pueden bloquearse sin red**. Alineado con
   `project_offline_es_emergencia_no_operacion`. **Prohibido evaluar un tope
   contra un saldo local optimista** — es la clase de decisión que después
   nadie puede revertir contra un alumno.

Falta decidir el alcance: ¿tope por monto, por cantidad de consumos, por
categoría (ej. "nada de gaseosas")? Recomendación: **monto por ventana
temporal** en la primera iteración; la restricción por categoría ya la cubre D4.

### D7 — Qué pasa si el saldo o el tope no alcanzan

¿Rechazo, consumo a crédito, o cobro de la diferencia? Recomendación:
**rechazar, con la diferencia cobrable en efectivo** como pago mixto (ya
soportado). Saldo negativo mezclaría esto con la cuenta corriente
(`contactCreditLine`), que es otro mecanismo.

### D8 — Saldo no consumido (fin de ciclo / fin de año)

¿Se devuelve, se arrastra o vence? **Consecuencia fiscal distinta por modo**: en
A el ingreso ya se declaró (devolver = nota de crédito, `context/40`); en B el
anticipo es un pasivo y su vencimiento reconoce ingreso sin entrega. Giftcard ya
tiene `giftCardSoldExpires` con el mismo problema sin resolver.

### D9 — Alcance de la unificación con giftcard

- **(a)** Motor nuevo, giftcard queda como está (deja dos mecanismos casi
  iguales — lo que la regla de arquitectura del proyecto prohíbe).
- **(b)** Motor nuevo, giftcard migra después con su tabla como proyección
  derivada (patrón `context/34` D2).
- **(c)** Las dos en la misma tanda.

Recomendación: **(b)**. Giftcard está en producción con datos vivos y flujo en
`pay-dialog`; migrarla junto con un motor nuevo multiplica el riesgo. Pero el
motor se diseña desde el principio para soportarla: el portador (código vs
contacto) es un campo, no una bifurcación.

## 7. Fases propuestas

- **F0 — Motor de wallet.** `wallet` (titular, sub-cuentas vía `parentWalletId`,
  concepto según D3) + `wallet_ledger` append-only siguiendo el patrón de
  `ai_credit_ledger` (`db-schema-postgres.sql:781`): `delta` + `balanceAfter` +
  `reason` + `meta`, saldo = SUM. **Cada carga graba su modo** (§4).
  `contactStoreCredit` queda como proyección derivada — el POS y los reportes ya
  la leen, no se rompe nada.
- **F1 — Carga de saldo desde la caja**, con la bifurcación de D1.
- **F2 — Cobro con saldo**: medio de pago en el diálogo, selección de bolsillo,
  validación de elegibilidad (D4) y saldo visible.
- **F3 — Sub-cuentas y transferencia interna** (par de movimientos atómico, sin
  tocar caja ni ventas).
- **F4 — Topes** (D6): definición + evaluación atómica server-side.
- **F5 — Superficie del titular** (D5): lectura primero.
- **F6 — Migración de giftcard** al motor (D9).

## 8. Arquitecturas rechazadas (leer antes de proponer nada)

- **Registrar el consumo como venta normal y "restarlo después" en el reporte.**
  Es el doble conteo que se quiere evitar y ya está resuelto por
  `NonAddingSales`: el consumo no suma desde el diseño, no por una resta a
  posteriori.
- **Columnas por bolsillo** (`contactStoreCreditAlmuerzo`, etc.). Dos hoy, cinco
  el año que viene, y sin historial no se puede explicar un saldo — que es
  justo lo que el módulo promete. Ledger, no columnas.
- **Un `contact` por sub-cuenta sin jerarquía real** (el alumno duplicado por
  concepto). Rompe el corte por persona, duplica datos personales y contamina
  los reportes de clientes.
- **Facturar al titular Y al consumir.** Doble facturación del mismo ingreso —
  es lo que pasa si el modo se lee de la configuración vigente en vez del saldo
  (§4).
- **Modo B como un `if` dentro del modo A.** Invierten dónde se reconoce el
  ingreso; tratarlos como variantes duplica o pierde ingresos según el lado.
- **Un mecanismo nuevo al lado de giftcard.** Son el mismo concepto (§3.1);
  duplicarlo deja dos motores de saldo que se desincronizan.
- **Evaluar topes en el cliente, o contra saldo local offline.** La carrera
  entre cajas hace que el chequeo local sea incorrecto por construcción (§D6).
- **Un link firmado que permita cambiar topes.** Sería una credencial
  permanente circulando por WhatsApp; la escritura necesita sesión real (§D5).

## 9. Invariantes

- El **ingreso se reconoce UNA vez**, en el momento que dicta el modo con el que
  nació ESE saldo.
- El **modo viaja con el saldo**, nunca se lee de la configuración vigente.
- El **stock SÍ se descuenta** al consumir, en los dos modos: la mercadería sale
  del inventario aunque no haya ingreso nuevo. Dimensiones distintas, las dos
  correctas.
- Debitar saldo **no mueve caja** (`FinanceLedger.php:270`): cancela un pasivo
  con el cliente, no es un cobro. **Transferir entre wallets tampoco** — no sale
  ni entra dinero del comercio.
- Los **topes se evalúan server-side y atómicamente**, en la misma transacción
  que descuenta.
- Toda la jerarquía vive **dentro de una company**: una sub-cuenta nunca cuelga
  de un titular de otro tenant.
