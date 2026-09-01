# 66 — Onboarding conducido por el agente

> Estado: **PLAN, sin implementar.** Fecha 2026-09-01. D1-D2 cerradas por el
> owner, no relitigar. El resto (D3-D4, fases, mecanismo de traza) son
> PROPUESTAS mías y necesitan su OK — marcadas **[?]**.
>
> **BLOQUEANTE, léase primero**: F0 (§Prerequisito) no es parte del plan, es
> lo que hay que arreglar ANTES de escribir una línea de este plan. Sin eso,
> D2 no se puede cumplir — ver abajo por qué.

## Qué pidió el owner

Que el agente **haga** la configuración de la cuenta, no que la guíe. Su
ejemplo textual:

> *User: Necesito crear 2 usuarios con X permisos y dos cajas en X sucursal.*
> *Bot: Dame el nombre de los usuarios, y nro de timbrado de las cajas.*

También: que el cliente mande un Excel con sus productos y el bot pida lo que
falta. *"Que realmente el bot sirva más que solo servir como guía o que solo
responda preguntas"*. El objetivo de negocio es el onboarding: la
configuración inicial es donde se pierden los clientes.

## Prerequisito bloqueante — atribución rota en `tenant_audit`

`context/59-asistente-en-la-caja.md:8` ya lo documenta como P1 sin resolver:
**`tenant_audit` atribuye las escrituras del agente al contacto que pareó la
tablet, no al operador real.** Verificado el porqué, y es más profundo que un
campo mal seteado:

`apiAuthTenant()` escribe la fila de auditoría **genéricamente, para toda
mutación**, en `bootstrap.php:329-335`, con `$ctx['userId']` — el `userId`
que la propia función resolvió del realm (para `pos-app`, el contacto que
pareó el dispositivo, `bootstrap.php:225-227`). Esa escritura pasa **antes**
de que el endpoint corra. `AgentActor::authorize()` — el que resuelve al
operador de verdad vía `OperatorContext` y el `X-Operator-Token`
(`api/lib/Ai/AgentActor.php:89-112`) — recién se llama **adentro** de
`confirm.php`/`execute.php`, después de que `apiAuthTenant()` ya volvió y ya
audité con el `userId` equivocado.

No es que falte pasar un dato: es que el punto donde se decide "quién es la
persona" (`AgentActor`, por endpoint) corre estructuralmente después del
punto donde se audita (`tenantAudit()`, genérico en el wrapper). La solución
correcta ataca el wrapper, no cada endpoint: `apiAuthTenant()` tiene que
resolver el `X-Operator-Token` (cuando el realm es `pos-app` y viene
presente) **antes** de auditar, y usar ese `userId` tanto para la fila de
`tenant_audit` como para el `$ctx['userId']` que devuelve — así corrige de
una vez toda escritura `pos-app` con operador identificado, no solo las dos
del agente. Es la misma clase de arreglo que ya hizo `transactions.php` para
`OUTLET_ID` (context/59, D1): resolver la dimensión correcta en el lugar
compartido, no en cada consumidor.

Sin esto, D2 (abajo) no sirve como defensa: dejar constancia formal de que
"lo hizo" el contacto que pareó la tablet hace meses, cuando lo pidió el
encargado del turno, es peor que no auditar — es una prueba documental
falsa. **F0 de este plan es este fix**, y nada de lo demás se escribe antes.

## D1 — CERRADA por el owner (2026-09-01): ventas no, el resto sí

Textual: *"Ventas no dejaremos que haga el bot, eso es muy delicado pero el
resto puede hacerlo"*. Amplía el alcance vigente (`context/17`, `context/59`
D2), que hoy excluye también cajas, sucursales y permisos:

| | Hoy (`confirm-tool.ts`) | Con D1 |
|---|---|---|
| Contactos, ítems, taxonomías, importación | Dentro | Dentro |
| Usuarios | Dentro (no-admin) | Dentro |
| Cajas, sucursales, roles/permisos | Fuera | **Dentro** |
| Ventas | Fuera | Fuera, sin excepción |

## D2 — CERRADA por el owner (2026-09-01): auditar la INTENCIÓN, no solo el efecto

Textual: *"dejando constancia en auditoría para que luego no digan que el bot
hizo algo que no se le pidió y nos culpen de eso"*. Tres piezas, no una:

1. **El pedido**, en las palabras del cliente.
2. **El plan** que el bot propuso a partir de eso.
3. **Quién confirmó** — y con F0 arreglado, quién es de verdad.

Hoy el sistema guarda (2) y (3) a medias, y no guarda (1):

- El `summary` de `register_action` (`confirm-tool.ts:149`) es la
  paráfrasis del BOT, no el pedido del cliente — sirve para (2), no para (1).
- El pedido del cliente en sus propias palabras **no llega al backend en
  ningún campo**. `registerConfirmation()` (`confirm-tool.ts:69-103`) manda
  `{actions, summary}` a `/v1/ai/confirm`; el último mensaje del usuario
  existe en el `messages[]` de la ruta de chat pero no se reenvía. Hay que
  sumarlo — un campo más en el body, threaded desde la ruta del chat.
- `tenant_audit` no tiene dónde poner texto libre extenso: `meta` es JSONB
  (mig 35), cabe, pero la fila hoy solo lleva `{keyId}` para `api` y nada
  para el resto (`bootstrap.php:326-328`).

### D3 [?] — mecanismo de traza: `meta` jsonb vs. tabla propia

Sin resolver, con el trade-off declarado:

- **`meta` jsonb**: cero migración. `{rawRequest, summary, actionsCount}` en
  la fila que YA se escribe para el POST a `/v1/ai/confirm`. Rápido, pero
  mezclado con el resto del tráfico mutante del tenant — consultar "todo lo
  que pidió el agente" implica filtrar `endpoint LIKE '/v1/ai/%'` sobre una
  tabla de propósito general.
- **Tabla propia** (ej. `agent_intent_log`): no ensucia el audit general,
  permite retención distinta (esto es lo que se le muestra a un cliente que
  reclama, capaz conviene guardarlo más tiempo que el resto de
  `tenant_audit`), y guarda la relación completa pedido→plan→confirmación→
  ejecución en una fila en vez de reconstruirla de dos INSERTs (`confirm` +
  `execute`) más el resultado por acción. Cuesta una migración.

No lo resuelvo acá — es la decisión que más cambia el trabajo de F3.

## Lo que YA EXISTE — el plan construye menos de lo que parece

- **Confirmación en bloque, ya implementada.** `register_action` recibe
  SIEMPRE un array `actions` (`confirm-tool.ts:15-19,144-156`) — pedir
  "creá 2 usuarios y 2 cajas" ya genera UN `confirmToken` para el lote
  entero, no N confirmaciones. La pieza que el owner pide para el onboarding
  ("voy a crear esto, esto y esto, confirmá una vez") **ya está construida**;
  no hace falta mecanismo nuevo.
- **Fallo parcial, ya decidido — y es la respuesta a la pregunta que el owner
  marcó como la más importante.** `execute.php:9-10`: *"Un fallo en una
  acción del lote NO aborta las demás — cada acción se ejecuta de forma
  independiente y su resultado se reporta por separado"* (`$results`,
  `$okCount`, `$failCount`, `execute.php:283-291`). Si la acción 7 de 15
  falla, las otras 14 corren igual y el usuario ve cuál falló. Mismo patrón
  en la importación tabular: `ItemImporter`/`ContactImporter` devuelven un
  `$report` por FILA (`ImportSession.php:161,164`), no todo-o-nada — 340
  productos con un error en el 200 terminan en 339 filas procesadas y 1
  reportada. **Este es el precedente vigente del proyecto**, no algo a
  inventar. La pregunta real para el owner no es "qué hacemos", es si las
  acciones de sucursal/caja/rol quieren el mismo trato que contactos/ítems o
  si el mayor costo de un rollback a mitad de camino justifica una excepción
  — dejo la pregunta abajo, no la resuelvo.
- **Excel, ya funciona.** `parse-tabular.ts` toma `.xlsx`/`.xls`, extrae la
  primera hoja, la convierte a CSV. La mitad del pedido del owner
  ("mandame un Excel con mis productos") ya está construida.
- **Defensa en profundidad por acción, ya existe.** `AgentActor::authorize()`
  resuelve al actor una sola vez para `confirm` y `execute`
  (`AgentActor.php:89-112`), y `requiredPermission()` (`:177-208`) mapea
  acción → permiso REAL que esa persona necesitaría para hacerlo a mano. El
  agente nunca puede hacer algo que quien lo opera no podría hacer solo. Hoy
  no tiene entradas para caja/sucursal/rol — sumarlas es mecánico, el patrón
  ya está.
- **El CRUD de destino ya existe** para la mayoría de las acciones nuevas:
  `RegisterAdminService::create(outletId, name, extra)`
  (`api/lib/services/RegisterAdminService.php:238`) para cajas,
  `OutletsService` para sucursales. El plan cablea, no construye.
- **La validación determinista de timbrado ya es de BACKEND, no de prompt.**
  `context/29` documenta el constraint de unicidad del punto de expedición
  por timbrado (mig 143) — está en la BD, así que aunque el agente llame mal
  al servicio, la fila duplicada la rechaza la base, no el criterio del
  modelo. El trabajo real es que `execute.php` llame a
  `RegisterAdminService::create()` (que valida ANTES y devuelve un error
  legible) y no a un INSERT crudo que le devuelva al usuario una violación de
  constraint en crudo.

## D4 [?] — acciones nuevas a sumar

`create_register`, `create_outlet`, `assign_role` (asignar un rol EXISTENTE,
no crear uno). Cada una necesita, siguiendo el patrón de las 9 actuales:
entrada en `AI_CONFIRM_ALLOWED_ACTIONS` (`confirm.php`) + validación de
payload ahí + `requiredPermission()` en `AgentActor` + `case` en
`execute.php` que llame al servicio real (nunca INSERT directo, ver arriba).

**Crear roles nuevos queda AFUERA** de esta propuesta — es la pregunta abierta
del owner (crear un rol es crear un conjunto de permisos, superficie de
escalada), y `create_user` ya sienta el precedente de "solo roles
existentes, `roleName` no-admin" (`confirm-tool.ts:49`). Extender ese mismo
criterio a `assign_role` es consistente; crear roles necesita su OK explícito.

## Fases

| Fase | Qué | Depende de |
|---|---|---|
| **F0** | ~~Fix de atribución en `tenant_audit` (§Prerequisito)~~ **IMPLEMENTADA 2026-09-01** — `api/lib/Auth/AuditActor.php`, llamada desde `apiAuthTenant()` antes de auditar | — (ya no bloquea) |
| **F1** | Sumar `create_register`/`create_outlet`/`assign_role` al catálogo: `confirm.php`, `AgentActor::requiredPermission()`, `execute.php` (D4) | F0 |
| **F2** | Registro de intención (D2): campo `rawRequest` de punta a punta (chat → `register_action` → `/v1/ai/confirm`) + mecanismo de traza (D3, a decidir) | F0 |
| **F3** | Onboarding conducido: el bot pregunta lo que falta (nombres, timbrados) siguiendo el ejemplo del owner — prompt/orquestación, sin backend nuevo si F6 se deriva | F1 |
| **F4** | Checklist de estado — ver abajo | F1 |
| **F5** | UI: dónde vive el onboarding conducido (pregunta abierta, no decidida) | F3 |

**F4 — checklist derivado, no persistido.** El bot necesita saber qué falta.
Preferir derivarlo de datos existentes (¿hay sucursales? ¿cajas? ¿ítems?
¿usuarios además del dueño?) contra las tools de lectura que YA existen
(`get_outlets`, `get_users`, `get_items`, `context/59` catálogo compartido)
en vez de persistir un progreso — un checklist guardado se desincroniza del
estado real apenas alguien configura algo desde el panel en paralelo. No
requiere tabla nueva.

## Preguntas abiertas — no las resuelvo

- Si el plan en bloque se confirma entero o el usuario puede desmarcar ítems
  antes de ejecutar (`register_action` hoy no soporta edición parcial del
  lote, solo confirmar o no el lote completo tal cual se registró).
- Si las acciones de caja/sucursal/rol necesitan semántica distinta de fallo
  parcial (rollback) frente al precedente ya vigente de fallo independiente
  por acción — ver §Lo que YA EXISTE.
- Mecanismo de traza (D3): `meta` jsonb vs. tabla propia.
- Alcance de roles: solo `assign_role` sobre roles existentes (propuesto
  arriba) o el owner quiere permitir crear roles nuevos.
- Dónde vive el onboarding conducido: solo panel (tarea de dueño) o también
  POS (el POS ya tiene el agente, pero el onboarding no es tarea de cajero).

## Arquitecturas rechazadas — no reintroducir

- **Parchear la atribución solo en `confirm.php`/`execute.php`.** Arregla las
  dos rutas del agente y deja rota cualquier otra escritura `pos-app` con
  operador identificado que se agregue después (ej. D9 de `context/59` si
  algún día se aplica a más endpoints). El fix va en `apiAuthTenant()`.
- **Persistir un checklist de onboarding en tabla propia.** Se desincroniza
  del estado real la primera vez que alguien configura algo fuera del bot.
  Preferido: derivarlo (F4).
- **Un mecanismo nuevo de confirmación en bloque.** Ya existe
  (`register_action` con `actions[]`) — construir uno paralelo duplicaría el
  `confirmToken`/store de Redis que ya funciona.
- **INSERT directo para `create_register`.** A diferencia del seeder de
  `context/65` (cuentas internas, sin consecuencia fiscal), acá el timbrado
  es real: pasar por `RegisterAdminService::create()` no es una preferencia
  de estilo, es lo que le da al usuario un error legible en vez de una
  violación de constraint cruda cuando el punto de expedición ya existe.

## Docs relacionados

- `context/59-asistente-en-la-caja.md` — el asistente en la caja, el P1 de
  atribución (§Prerequisito) y el patrón `AgentActor`.
- `context/17-ai-agent-plan.md` — agente IA embebido, alcance original.
- `context/29-numeracion-y-exclusividad-de-caja.md` — por qué el timbrado se
  valida en BD, no en el prompt.
- `context/65-seeder-de-datos-demo.md` — precedente de INSERT directo
  rechazado acá por tener consecuencia fiscal real (a diferencia del seeder).
