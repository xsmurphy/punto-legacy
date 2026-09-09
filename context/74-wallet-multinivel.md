# 74 — Módulo Wallet multi-nivel

> Estado: **plan sin implementar** (2026-09-09). **Módulo NUEVO, diseñado desde
> cero** — no es una extensión de giftcard ni del crédito interno existentes
> (esos son mecanismos viejos con su propio alcance; §11 dice qué se hace con
> ellos, y no condicionan este diseño). **D1 y D2 CERRADAS** por el owner.
> D3-D10 propuestas SIN su OK.

## 1. Qué es

Un módulo de **dinero prepago con jerarquía y control**.

Un **titular** carga saldo en su wallet, abre **sub-cuentas** para terceros que
consumen en su nombre, les transfiere fondos, y conserva control sobre ellas:
ve qué consumieron y les fija **topes** por ventana de tiempo.

Tres ideas, y las tres son el módulo:

1. **Prepago** — el dinero entra antes del consumo.
2. **Jerarquía** — quien paga y quien consume son personas distintas, con una
   relación de control entre ellas.
3. **Control** — el titular limita y audita el consumo de sus sub-cuentas sin
   depender del comercio.

## 2. Casos de uso

| Rubro | Titular | Sub-cuentas | Por qué necesita topes |
|---|---|---|---|
| Cantina escolar | el padre | sus hijos | que el chico no gaste todo el lunes |
| Gimnasio / club | el socio | grupo familiar | control del gasto en buffet |
| Empresa con comedor | la empresa | empleados | presupuesto diario por persona |
| Flota | la empresa | choferes | límite de combustible por viaje |
| Coworking / hotel | la cuenta corporativa | huéspedes o miembros | tope por estadía |

**El schema habla de titular y sub-cuenta.** "Padre" y "alumno" son vocabulario
de UI por rubro, nunca nombres de tabla ni de campo.

## 3. Modelo conceptual

### 3.1 Wallet

Una wallet **es un saldo con dueño y reglas**. Su clase no es un atributo
propio: **la determina el contacto dueño** (§3.4).

- **Wallet de titular** — la de un contacto sin padre. Es la única a la que
  entra dinero desde afuera.
- **Wallet de hijo** — la de un contacto con padre. **No se carga desde
  afuera**: solo recibe transferencias de la wallet del padre. Esa restricción
  es lo que hace que el dinero sea rastreable desde que entró hasta dónde se
  consumió.

Una wallet tiene: saldo, estado (activa/bloqueada), y sus reglas (topes,
elegibilidad). El **saldo nunca es un campo que se pisa**: es la suma de sus
movimientos (§3.3).

### 3.2 Bolsillo (concepto)

Dentro de una wallet, el dinero puede estar separado por **concepto**:
"almuerzo" y "cantina" no se mezclan. Un concepto declara:

- Su **nombre** (lo que ve el usuario).
- **Qué se puede comprar con él** (elegibilidad — D4).
- Su **modo de facturación** (§4).

Un comercio que no necesita separar conceptos opera con uno solo, implícito. El
módulo no obliga a la complejidad de nadie.

### 3.3 Movimiento

**Todo cambio de saldo es un movimiento, y los movimientos no se editan ni se
borran.** El saldo de una wallet es la suma de sus movimientos; no existe un
campo "saldo" que alguien pueda pisar.

Es la decisión estructural del módulo, y no es por prolijidad: el módulo
promete que el titular pueda ver *por qué* su sub-cuenta tiene el saldo que
tiene, y los topes se calculan sumando consumos en una ventana. Sin historial,
las dos promesas centrales son imposibles.

Tipos de movimiento:

| Tipo | Qué es | Efecto |
|---|---|---|
| `load` | el titular carga dinero | + en la wallet raíz |
| `transfer` | la raíz manda a una sub-wallet | − en raíz, + en sub (par atómico) |
| `spend` | consumo en el punto de venta | − en la wallet que paga |
| `refund` | reversa de un consumo | + |
| `adjust` | corrección manual del comercio | ± , siempre con motivo y autor |
| `expire` | vencimiento de saldo | − (según D8) |

`transfer` es **un par de movimientos que se aplican juntos o no se aplican**:
nunca puede existir el débito sin su crédito.

### 3.4 La jerarquía vive en el CONTACTO (owner, 2026-09-09)

El consumidor **es un contacto más del comercio**, dado de alta como **hijo de
otro contacto**. La relación padre→hijo es un hecho del padrón de clientes, no
del módulo de wallet: la wallet la hereda.

De ahí salen tres reglas que el módulo no puede romper:

1. **La cuenta del padre es la que recibe el dinero.** Se le factura a ella y en
   ella se acreditan las cargas. Una cuenta hija **nunca se carga desde afuera**.
2. **Desde la cuenta padre se distribuye** a las hijas por transferencia
   interna. Es lo que hace que cada peso sea rastreable desde que entró hasta
   dónde se consumió.
3. **El sujeto fiscal es siempre el padre**, en los dos modos. En modo A la
   factura de la carga va a su nombre; en modo B, la factura del consumo del
   hijo también — el hijo consume, pero el documento sale a nombre de quien
   pagó. Un menor no es sujeto de facturación.

El titular controla a sus hijos: crea, transfiere, fija topes, bloquea y ve el
histórico. Un contacto hijo **no controla nada** — solo consume.

Toda la relación vive **dentro de un mismo comercio**: un contacto hijo nunca
cuelga de un titular de otro tenant.

## 4. D1 — CERRADA (owner): dos modos de facturación

El dinero entra antes de que se entregue nada. Cuándo se emite el documento
fiscal es una decisión del comercio, y **Punto soporta las dos**:

**Modo A — factura al cargar.** El titular paga y recibe su factura en el acto.
Es lo que el cliente espera y pide. El consumo posterior no es una venta nueva:
entrega mercadería contra algo ya facturado, con comprobante interno.

**Modo B — factura al entregar.** La carga es una **cobranza anticipada** — el
comercio recibe dinero y queda debiendo mercadería. Documento: recibo de
anticipo, no factura. La venta —con su IVA real— ocurre al consumir.

### Lo que cambia entre modos

| | Modo A | Modo B |
|---|---|---|
| Documento al cargar | Factura | Recibo de anticipo |
| Documento al consumir | Comprobante interno | **Factura** |
| El ingreso se reconoce | al cargar | al consumir |
| El IVA se determina | al cargar (sin saber qué se consumirá) | al consumir (real) |
| Naturaleza contable de la carga | venta | pasivo con el cliente |

**No son variantes del mismo flujo: son flujos inversos.** El ingreso se
reconoce en puntos opuestos de la línea de tiempo. Cualquier implementación que
trate B como "A con una condición" va a duplicar o a perder ingresos según de
qué lado se equivoque.

### Modo A tiene una tensión fiscal que hay que declarar

Se factura sin saber qué se va a consumir. Si el comercio vende con tasas de
IVA distintas, el bolsillo **debe ser homogéneo en tasa** — o el comercio
absorbe la diferencia. El módulo lo advierte al configurar el concepto; la
elegibilidad (D4) es la herramienta para mantenerlo homogéneo.

### Invariante — el modo se congela en el saldo

Si el comercio cambia de modo teniendo saldos vivos, lo cargado bajo A —ya
facturado— se volvería a facturar al consumirse. Es doble facturación de un
ingreso ya declarado.

**Cada carga registra el modo con el que nació**, y el consumo se comporta
según ESE valor. Cambiar la configuración afecta solo a las cargas nuevas. El
modo es un atributo del dinero, no del comercio.

## 5. D2 — CERRADA (owner): la jerarquía es el modelo

No hay disyuntiva entre "el saldo es del que paga" o "del que consume": **son
los dos, en jerarquía**. De ahí se derivan tres cosas:

- La **relación entre personas** es el corazón del módulo, no un accesorio.
- La **transferencia interna** es una operación de primera clase: no es venta,
  no es cobranza, **no mueve la caja del comercio**. Es dinero que ya estaba
  adentro cambiando de bolsillo.
- El **corte por sub-cuenta** es una consulta directa sobre los movimientos —
  es la pregunta que el titular hace todos los meses.

## 6. Topes

Un tope es **un límite de gasto por ventana de tiempo** sobre una sub-cuenta:
"máximo 20.000 por día", "100.000 por semana".

### Cómo se evalúa

En el momento del consumo, el módulo suma los `spend` de esa wallet dentro de
la ventana y decide. **La evaluación y el descuento ocurren en la misma
operación atómica, del lado del servidor.**

No es un detalle de implementación, es la única forma correcta: dos cajas
simultáneas leen "lleva 5.000 de 10.000", las dos aprueban 6.000, y el chico
gastó 11.000. Un tope que se evalúa antes y se aplica después no es un tope.

### Sin conexión

El saldo y los topes son **estado compartido entre puntos de venta**: una caja
sin red no sabe qué se consumió en otra. Consumir contra wallet **requiere
conexión** y se bloquea sin ella.

Es coherente con cómo el POS ya trata lo compartido, y con que offline sea
emergencia y no modo de operación. **Nunca aprobar un consumo contra un saldo
o un tope calculado localmente**: una decisión así, contra un chico en la fila,
no se puede revertir después.

### Qué se limita

Monto por ventana en la primera iteración. Limitar *qué* se compra ya lo
resuelve la elegibilidad del concepto (D4); limitar cantidad de consumos o
franja horaria queda para después si aparece el pedido.

## 7. Superficie del titular

El titular necesita, sin depender del comercio: ver saldos, ver el histórico de
cada sub-cuenta, transferir, y fijar topes.

Es una **superficie propia para el cliente final del comercio** — ni el panel
del comercio ni la caja. Cómo se autentica es D5.

Separación que el diseño impone: **leer y escribir tienen umbrales distintos**.
Ver un saldo tolera un acceso liviano; cambiar un tope o mover dinero exige
identidad real.

## 8. Modelo de datos propuesto

Cuatro entidades. Nada más.

```
contact                   -- entidad EXISTENTE del comercio
  parentContactId         -- NULL = titular; si no, es hijo de ese contacto
                          -- ÚNICA fuente de verdad de la jerarquía (§3.4)

wallet
  id, companyId
  ownerContactId          -- de quién es
  conceptId               -- bolsillo (NULL = wallet sin conceptos)
  status                  -- active | blocked
  createdAt
  -- SIN parentWalletId: si el contacto dueño tiene padre, esta wallet es hija.
  -- Dos fuentes para la misma jerarquía terminan contradiciéndose.

wallet_concept            -- catálogo por comercio
  id, companyId
  name
  billingMode             -- A | B  (default de las cargas nuevas)
  eligibility             -- qué se puede comprar (D4)
  active

wallet_movement           -- append-only, nunca UPDATE ni DELETE
  id, companyId, walletId
  type                    -- load | transfer | spend | refund | adjust | expire
  amount                  -- con signo
  balanceAfter            -- saldo resultante, para auditar sin recalcular
  billingMode             -- CONGELADO en las cargas (§4)
  transferGroupId         -- une los dos lados de una transferencia
  sourceType, sourceId    -- qué lo originó (venta, ajuste, etc.)
  actorContactId          -- quién lo hizo
  reason, meta
  createdAt

wallet_limit
  id, companyId, walletId
  window                  -- daily | weekly | monthly
  amount
  activeFrom
```

Decisiones que este modelo toma a propósito:

- **`balanceAfter` en cada movimiento**: permite auditar y detectar corrupción
  sin sumar toda la historia.
- **`billingMode` en el movimiento, no solo en el concepto**: es lo que hace
  cumplir la invariante de §4.
- **`transferGroupId`**: hace verificable que ninguna transferencia quedó a
  medias.
- **`actorContactId` siempre**: cada peso movido tiene responsable. En un
  módulo donde un adulto controla el dinero de un menor, "quién hizo esto" no
  es opcional.
- **Sin campo `balance` en `wallet`**: si existe, alguien lo va a escribir.
- **Sin `parentWalletId`**: la jerarquía es de contactos (§3.4). Duplicarla en
  la wallet crea el día en que las dos versiones no coinciden y nadie sabe cuál
  manda.

## 9. Decisiones abiertas

### D3 — CERRADA (owner, 2026-09-09): sub-cuenta por persona, bolsillos adentro

Una sub-cuenta **por persona**, y dentro de ella el saldo separado **por
concepto**. Con dos hijos y dos conceptos son 2 sub-cuentas con 2 bolsillos cada
una, no 4 cuentas sueltas.

Es como la gente lo dice en voz alta ("el saldo de almuerzo de Juan"), los dos
cortes —por persona y por concepto— salen naturalmente, y sobre todo encaja con
D7: el cajero tipea "Juan", le aparece **una** entrada, y no tiene que decidir
de qué bolsillo sale un alfajor con la fila esperando.

#### Consecuencia: el bolsillo lo elige el sistema, no el cajero

Si el cajero solo elige a la persona, **algo tiene que decidir de qué bolsillo
debitar cada ítem**. Ese algo es la elegibilidad del concepto (D4), que deja de
ser una restricción opcional y pasa a ser el **mecanismo de ruteo** del módulo.

De ahí, tres reglas que el diseño necesita:

1. **Una venta puede repartirse entre varios bolsillos.** El chico lleva el
   almuerzo y una gaseosa en la misma compra: parte sale de "almuerzo" y parte
   de "cantina". El pago con wallet **no es un único débito** — es un débito por
   bolsillo, todos dentro de la misma operación atómica.
2. **Un ítem elegible en más de un bolsillo necesita una regla de
   precedencia** — sin ella, dos cajas podrían debitar distinto para la misma
   compra. Ver D4.
3. **Un ítem que no es elegible en ningún bolsillo no se paga con wallet.** Se
   rechaza o se cobra por otro medio (D6), nunca se debita "del que tenga
   saldo".

### D4 — Cómo se define qué puede pagar cada bolsillo

Con D3 cerrada, esto ya no es solo una restricción: es el **ruteo** que decide
de qué bolsillo sale cada ítem.

Por categoría de producto, por marca/etiqueta, o por lista explícita.
Recomendación: **por categoría**, que es la clasificación que el comercio ya
mantiene viva por otras razones, y que permite mantener el bolsillo homogéneo en
tasa de IVA (§4).

Falta además definir la **precedencia ante ambigüedad**, que D3 volvió
obligatoria: si un ítem cae en dos bolsillos, ¿manda un orden de prioridad
declarado por el comercio, el bolsillo más específico, o el de mayor saldo?
Recomendación: **orden explícito de los conceptos**, configurado por el
comercio. Cualquier regla implícita hace que la misma compra pueda debitar
distinto según el día.

### D5 — Cómo se autentica el titular

- **(a) Identidad propia** (teléfono + verificación), con sesión real.
  Habilita escritura: transferir, fijar topes, bloquear.
- **(b) Acceso por link firmado**, sin sesión. Barato y sin fricción, pero un
  link que mueve dinero o cambia límites es una credencial permanente
  circulando por mensajería.
- **(c) El titular no entra**: el comercio administra y le manda el resumen. La
  wallet funciona; el control del titular, no — y el control es un tercio del
  módulo (§1).

Recomendación: **(b) para lectura, (a) para escritura**, en ese orden. Ver
saldo e histórico con link firmado entrega valor desde el primer día; mover
dinero espera identidad real. **El link de lectura nunca habilita escritura.**

### D6 — Qué pasa cuando el consumo excede saldo o tope

¿Se rechaza, se permite deuda, o se cobra la diferencia por otro medio?
Recomendación: **rechazar, con la diferencia cobrable en el momento** (pago
mixto). Permitir saldo negativo convierte la wallet en una cuenta corriente,
que es otro producto con otras reglas.

### D7 — CERRADA (owner, 2026-09-09): el cajero busca al consumidor en el POS

**Por el momento**, el cajero —que conoce a los chicos— lo busca por nombre en
la caja, y al seleccionarlo **ve su crédito disponible**. Sin credencial física,
sin código, sin QR.

Consecuencias de diseño:

- **La búsqueda tiene que ser instantánea**: un recreo dura 15 minutos y la fila
  es toda al mismo tiempo. Búsqueda incremental por nombre, sin pasos previos.
- **Buscar puede ser local; ver saldo y consumir, no.** El padrón de
  sub-cuentas puede vivir en la caja para que la búsqueda no dependa de la red,
  pero el saldo se resuelve en línea al seleccionar — es estado compartido
  (§6). Nunca mostrar un saldo cacheado como si fuera el vigente.
- **El riesgo real es seleccionar a la persona equivocada**, y ahí se debita el
  dinero de otro. Con hermanos, apellidos repetidos u homónimos es un error
  fácil de cometer y con consecuencia de plata. La lista **debe mostrar un dato
  que desambigüe** —grado/curso, o foto si el comercio la carga— y no solo el
  nombre. Un `refund` lo corrige, pero el chico ya se fue con el alfajor.
- **Esto depende de que el cajero conozca a los consumidores.** Funciona en una
  cantina chica; no escala a un colegio grande ni sobrevive a personal
  rotativo, y esa es exactamente la razón por la que el owner lo marcó como
  "por el momento". La evolución natural —código corto, QR en el carnet— entra
  después sin cambiar el modelo: es otra forma de resolver la misma
  sub-cuenta, no otro modelo de datos.

### D11 — El contacto hijo dentro del padrón de clientes

Si el consumidor es un contacto más (§3.4), un colegio suma cientos de contactos
que **no son clientes comerciales**: no se les factura, no se les vende, no
entran en una campaña. Sin distinguirlos, ensucian el padrón, los buscadores y
los reportes de clientes del comercio.

Opciones: marcarlos con un tipo/rol propio y excluirlos por defecto de los
listados comerciales; o dejarlos como contactos normales y que el comercio
filtre.

Recomendación: **marcarlos y excluirlos por defecto**, visibles con un filtro
explícito. Quien busca "mis clientes" no está buscando a los chicos.

### D8 — Vencimiento del saldo

¿El saldo no consumido se arrastra, se devuelve o vence? Y si vence, ¿cuándo y
con qué aviso? **Consecuencia fiscal distinta por modo**: en A el ingreso ya se
declaró (devolver es una nota de crédito); en B el anticipo es un pasivo y su
vencimiento reconoce un ingreso sin haber entregado nada.

### D9 — Qué ve el CONSUMIDOR en el momento de consumir

Que el cajero ve el saldo ya quedó decidido en D7. Falta el otro lado: ¿el
consumidor ve el suyo? Afecta la experiencia y, con menores, también su
exposición delante de la fila — "no te alcanza" dicho en voz alta es distinto
que un rechazo discreto.

Recomendación: mostrárselo a pedido, y que el rechazo por saldo o tope se
comunique sin anunciarlo al resto de la fila.

### D10 — Alcance de la primera versión

El módulo completo es grande. ¿La primera versión entra con topes, o topes
espera? Recomendación: **carga + sub-cuentas + transferencia + consumo primero**
(es el circuito de dinero completo y ya resuelve el caso del colegio), topes en
la segunda. Sin el circuito no hay módulo; sin topes hay módulo incompleto pero
usable.

## 10. Fases propuestas

- **F0 — Núcleo.** Entidades de §8, saldo por suma de movimientos, operaciones
  `load`/`spend` con sus reglas.
- **F1 — Carga desde la caja**, con la bifurcación de modo (§4).
- **F2 — Consumo en el punto de venta**: identificación de la sub-cuenta (D7),
  validación de elegibilidad, descuento atómico.
- **F3 — Sub-cuentas y transferencia** (par atómico).
- **F4 — Topes** (§6): definición y evaluación server-side.
- **F5 — Superficie del titular** (D5): lectura primero.
- **F6 — Vencimiento y cierre de ciclo** (D8).

## 11. Qué se hace con giftcard y crédito interno

Punto ya tiene dos mecanismos de dinero prepago: **giftcard** (saldo atado a un
código, transferible, anónimo) y **crédito interno** (saldo atado a un
contacto). Los dos son escalares sin historial, sin jerarquía y sin topes.

**No condicionan este diseño.** La wallet se construye por su cuenta y con su
propio modelo. Cuando esté en producción, la convergencia es una decisión
aparte —absorberlos, dejarlos como productos distintos, o mantener giftcard
como "wallet sin titular"—, y se toma con el módulo funcionando, no antes.
Diseñar la wallet para acomodar dos mecanismos viejos la haría peor.

## 12. Invariantes del módulo

- **El saldo es la suma de los movimientos.** No existe un campo de saldo
  autoritativo que se pise.
- **Los movimientos no se editan ni se borran.** Un error se corrige con un
  movimiento de signo contrario, con motivo y autor.
- **El ingreso se reconoce una sola vez**, en el punto que dicta el modo con el
  que nació ese dinero.
- **El modo viaja con el dinero**, nunca se lee de la configuración vigente.
- **Una transferencia es atómica**: sus dos lados existen juntos o no existe
  ninguno.
- **Transferir no mueve la caja del comercio**; consumir contra saldo tampoco.
  El dinero entró una vez, cuando se cargó.
- **El stock sí se descuenta al consumir**, en los dos modos: la mercadería
  sale del inventario aunque no haya ingreso nuevo.
- **Los topes se evalúan y aplican server-side, atómicamente**, junto con el
  descuento.
- **Toda la jerarquía vive dentro de un comercio.** Una sub-cuenta nunca cuelga
  de un titular de otro tenant, y una wallet nunca se consume en un comercio
  que no la emitió.
- **Cada movimiento tiene autor.** Sin excepción.

## 13. Arquitecturas rechazadas (leer antes de proponer nada)

- **Campo `balance` en la wallet.** Si existe, alguien lo escribe, y el día que
  diverge de los movimientos no hay forma de saber cuál miente.
- **Una columna por bolsillo.** Dos conceptos hoy, cinco el año que viene, y
  ningún historial para explicar un saldo — que es lo que el módulo promete.
- **Un contacto por sub-cuenta sin jerarquía real.** Duplica personas, rompe el
  corte por consumidor y contamina el padrón de clientes del comercio.
- **Facturar al cargar Y al consumir.** Doble facturación del mismo ingreso —
  es lo que ocurre si el modo se lee de la configuración actual en vez del
  movimiento.
- **Modo B como una condición dentro del modo A.** Son flujos inversos (§4).
- **Evaluar topes en el cliente, o contra saldo local sin conexión.** La
  concurrencia entre cajas hace que el chequeo local sea incorrecto por
  construcción.
- **Un link firmado que permita transferir o cambiar topes.** Es una credencial
  permanente en un mensaje reenviable.
- **Diseñar el módulo alrededor de giftcard o del crédito interno existentes**
  (§11). Son mecanismos con otro alcance; adaptarse a ellos degrada el modelo.
