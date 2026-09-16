# 78 — Integración Fish (CRM): contactos + atributos para campañas

> Estado: plan cerrado 2026-09-16 (decisiones D1-D7 por el owner), SIN implementar.
> Fish está construyendo su lado (ingest) en paralelo — contrato propuesto en §4,
> pendiente de confirmación de shape final.

## 1. Qué es

Fish es el otro proyecto del owner: CRM con agentes IA, embudo de ventas,
contactos segmentados y campañas masivas. La integración replica el modelo
**Intercom**: Punto pushea contactos con atributos custom, Fish los almacena,
segmenta ("clientes que no compran hace 3 meses") y lanza campañas.

**Punto NO construye motor de segmentación.** Los segmentos viven en Fish;
Punto solo envía datos. Dos poblaciones con el mismo mecanismo:

- **Clientes finales** de cada comercio → workspace Fish del comercio.
- **Tenants de Punto** (los comercios como clientes del SaaS) → workspace
  propio de Punto. Punto es "un usuario más" de Fish.

## 2. Decisiones cerradas (owner, 2026-09-16)

- **D1 — Push unidireccional Punto→Fish.** Punto es fuente de datos, Fish es
  dueño de segmentos y campañas. NADA de sync bidireccional en v1.
- **D2 — Server-side, disparado por evento.** El push sale del backend
  (venta guardada, edición de contacto, anulación/NC, cobro, archivado,
  cambio de plan, login) vía outbox con reintentos. El "JS script" estilo
  Intercom quedó descartado como transporte (§7).
- **D3 — Actualizar solo cuando hay evento.** Sin polling ni job continuo.
  Funciona porque los atributos son HECHOS crudos: `lastPurchaseAt` congelado
  ES el dato — el segmento "inactivo 90 días" se filtra en Fish por fecha.
- **D4 — Atributos = hechos crudos tipados.** Fechas ISO-8601, números,
  strings, bools. PROHIBIDO todo derivado de ventana móvil
  (`averageVisitsMonth`, `salesLast30d`): se pudre sin eventos. Si algún día
  se quiere, es job periódico aparte, no parte de este flujo.
- **D5 — Upsert reemplaza.** Fish debe reflejar siempre el último estado.
  Merge por key (Punto puede mandar subsets por evento), no replace total.
- **D6 — Type-freeze en Fish.** Si el campo no existe en Fish, se CREA con el
  tipo del primer valor recibido y queda congelado (`"saldo": "150000"` queda
  string para siempre). De acá salen los dos invariantes del §5.
- **D7 — Backfill inicial obligatorio** antes de activar el flujo por eventos:
  los inactivos —el target de la campaña de reactivación— son justamente los
  que nunca van a generar un evento.

## 3. Arquitectura (lado Punto)

1. **Outbox** — fila por cambio (patrón `einvoice_document`/`NotificationOutbox`),
   worker que agrupa y pushea batch a la API de Fish, reintentos. NUNCA
   webhook inline en la request de venta.
2. **Mapper por población** — clientes finales leen `contact` + agregados de
   venta; tenants leen `company` + billing. Es la única pieza que varía;
   transporte y contrato son los mismos.
3. **Agregados por cliente** — `purchaseCount`/`totalSpent`/`debt` etc. hoy NO
   existen como rollup (CustomerService los calcula on-read para UN cliente).
   Se materializan por evento al guardar venta/cobro/NC, o rollup nuevo
   patrón `context/18`. Definir en la implementación.
4. **Backfill** — comando CLI por workspace: recorre contactos `type=1`,
   calcula agregados, pushea en batches.

Solo `contact.type=1` (clientes) sale hacia Fish. Nunca `type=0` (empleados)
ni `type=2` (proveedores).

## 4. Contrato propuesto a Fish (pendiente de confirmación)

Enviado a la sesión Fish 2026-09-16; construyen contra esto salvo que
devuelvan cambios:

- `POST` upsert **batch** (array, ~100-500 por request), auth por **API key
  de workspace** (un workspace = una empresa).
- Identidad: `externalId` = `contactId` de Punto (estable) como clave de
  upsert; teléfono E.164 como identidad secundaria/canal. Dos externalIds con
  el mismo teléfono NO se fusionan (Punto permite duplicados por teléfono —
  `context/modules/21` regla 2).
- Payload: `externalId`, `name`, `phone`, `email?` + `attributes: {}`
  schema-free tipado.
- Campos auto-creados con el tipo del primer valor (D6). Validación de tipo
  en updates posteriores.
- `archived: true` suprime de campañas sin borrar.
- Idempotencia natural del upsert (los reintentos del outbox duplican requests).

## 5. Invariantes del mapper (consecuencia del type-freeze)

1. **Cast explícito antes de serializar.** PDO devuelve todo string
   (`"150000"`); un `json_encode` ingenuo congela el campo como string en
   Fish PARA SIEMPRE. Todo number/bool/date se castea en el mapper.
2. **Valor null/vacío ⇒ la key se OMITE.** Nunca mandar null ni `""`: en el
   primer envío definiría mal el tipo del campo.
3. Teléfono sale en E.164 CON `+` (Fish es consumo externo — la convención
   "sin + en storage" es interna de Punto).

## 6. Catálogo v1 de campos

### Clientes finales (workspace del comercio)

| Campo | Tipo | Fuente / evento que actualiza |
|---|---|---|
| `externalId`, `name`, `phone`, `email`, `city`, `country`, `gender` | string | `contact` — alta/edición |
| `birthday`, `createdAt` | date | `contact` — alta/edición |
| `archived`, `creditable` | bool | archivado / edición |
| `firstPurchaseAt`, `lastPurchaseAt`, `lastPaymentAt` | date | venta / cobro |
| `purchaseCount`, `totalSpent`, `avgTicket`, `lastPurchaseAmount` | number | venta y anulación/NC (lifetime; `avgTicket` = total/count, solo cambia con eventos) |
| `debt`, `storeCredit` | number | venta a crédito / cobro / NC |
| `lastOutlet`, `topCategory` | string | venta |

### Tenants (workspace Punto)

| Campo | Tipo | Fuente / evento |
|---|---|---|
| `externalId`, `name`, `phone`, `email`, `country`, `currency`, `plan`, `status` | string | `company` + billing — alta/cambio de plan/bloqueo |
| `signupDate`, `planExpiresAt`, `lastLoginAt`, `lastSaleAt` | date | signup / billing / login / venta (`lastSaleAt` = actividad real, mejor señal de churn que login) |
| `planPrice` | number | cambio de plan |
| `outletsCount`, `usersCount`, `registersCount` | number | alta/baja de sucursal/usuario/caja |
| `feActive` | bool | provisioning FE |

Montos en la moneda del tenant (consistente dentro de cada workspace).

## 7. Arquitecturas rechazadas — leer antes de proponer

- **JS snippet como transporte** (propuesta inicial): solo dispara cuando el
  usuario visita con browser — el cliente final del POS no tiene browser ni
  login, y el tenant churneado (el target) es justo el que no entra. El
  snippet puede existir a futuro como tracking de actividad del panel, nunca
  como canal de datos.
- **Sync bidireccional**: dedup doble, conflictos, dos fuentes de verdad.
  Fish nunca escribe contactos hacia Punto en v1.
- **Atributos de ventana móvil por evento**: se pudren (D4).
- **Segmentos definidos en Punto**: v2 opcional como tag de membership
  pusheado a Fish; en v1 Punto no sabe qué es un segmento.
- **Fusionar duplicados por teléfono en Fish**: filas distintas en Punto
  siguen distintas en Fish; la dedup es problema de datos de Punto, no del
  canal.

## 8. Pendientes

- Confirmación del contrato por Fish (shape final, emisión de API key por
  workspace, operadores de filtro del motor de segmentos).
- Decidir materialización de agregados (por evento vs. rollup `context/18`).
- Dónde vive la API key del workspace por tenant (config? `company.config`?)
  y el flujo de conexión comercio↔Fish.
- Consentimiento/privacidad para el caso comercio→Fish (datos de clientes del
  comercio salen a otra plataforma) — sin analizar aún.
