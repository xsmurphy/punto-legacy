# 74 — Módulo Wallet multi-nivel

> Estado: **plan sin implementar** (2026-09-16). Módulo NUEVO, diseñado desde
> cero: no se construye sobre giftcard ni sobre el crédito interno existentes
> (§9). La v1 es deliberadamente chica (§2). **Todas las decisiones de la v1
> están cerradas** (2026-09-16).

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
