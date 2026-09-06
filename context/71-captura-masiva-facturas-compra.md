# 71 — Captura masiva de facturas de compra por foto

> Plan sin implementar. Escrito 2026-09-06.
> **D1 CERRADA el mismo día y CORRIGE la premisa original**: el canal es la
> **PWA de Punto**, no un bot de Telegram. Telegram quedó evaluado y descartado
> en §6 con las razones del owner — leer eso antes de reabrirlo.
> D2-D4 cerradas por el owner; P1-P6 propuestas SIN su OK.
> Hay una decisión ABIERTA (§5) y un hueco de seguridad previo que este plan
> agrava y hay que cerrar ANTES de abrir el canal (§4).
> Leer §7 Arquitecturas rechazadas antes de proponer nada.

## 1. Qué resuelve

El comercio junta facturas de proveedor en papel y las carga de a una a mano.
La necesidad es **capturar muchas de una sentada** —sacar N fotos seguidas— y
que Punto las procese y las deje como borradores de compra para revisar y
aprobar.

## 2. El hallazgo que define el alcance

**El pipeline de OCR ya existe entero y está en producción** (plan cerrado en
`context/32-ocr-facturas-compra.md`). Esto **no es un pipeline nuevo**: es un
**canal de ingesta** hacia el que ya está andando. Verificado:

- Tabla `purchase_draft` (mig 105): `api/database/migrations/postgres/105_purchase_draft.sql:12-27`
  — estados `queued|pending|approved|rejected|failed`, `extracted` (inmutable)
  vs `edited`, `imageref`, `contactid`, `transactionid`.
- `api/lib/Purchases/PurchaseDraftService.php` — `create()` (`:125`),
  `completeExtraction()`, `approve()` (`:247-338`, lock `FOR UPDATE`,
  idempotente), `reject()` (`:341-374`), `uploadImage()` (`:669`, dispatcher
  por mime real: PDF passthrough `:692`, imagen resize 2000px `:704`).
- Endpoint `api/v1/purchase-drafts.php:33`, POST multipart campo `image` (`:57`).
- Extracción por modelo de visión vía OpenRouter:
  `frontend/app/api/ocr-invoice/route.ts` + `frontend/lib/ai/extract-invoice.ts`,
  con drain de cola en `frontend/app/api/ocr-invoice/drain/route.ts`.
- Invariante ya vigente: la IA nunca escribe stock ni finanzas; `approve()`
  llama al MISMO `PurchasesService::create()` del alta manual
  (`context/modules/08-compras.md:33`).

Lo que falta es **la captura**, no el procesamiento. Y con la PWA como canal,
falta bastante menos de lo que parecía: la sesión ya está autenticada, el
endpoint ya recibe multipart, y el usuario ya es un usuario real de Punto con
sus permisos y su alcance de sucursal.

## 3. Decisiones — cerradas por el owner (2026-09-06)

- **D1 — El canal es la PWA de Punto, no Telegram.** Textual: *"Si podemos
  usar la Progressive Web App de punto, yo creo que es mejor, porque así
  incentivamos el uso de nuestros productos, y hacemos que la gente lo tenga
  ahí y controlamos mejor la calidad de las fotografías y los límites de
  fotos, tamaños, etcétera."* Tres razones y las tres son del producto, no
  técnicas: adopción de la app propia, **control de la calidad de la foto**, y
  **control de los límites** (cantidad y tamaño). Ver §6 para por qué Telegram
  no puede dar ninguna de las tres.
- **D2 — El vínculo de una identidad externa, si algún día existe, es a un
  USUARIO, no al comercio.** Textual: *"tiene que ser por usuario"*. Aplica
  a cualquier canal futuro (§6). Con la PWA sale gratis: la sesión ya es de una
  persona.
- **D3 — Un canal externo se revoca como una sesión.** Textual: *"que el
  tenant pueda eliminar el Telegram de cada usuario, así como si fueran
  sesiones"*. Listar (quién, desde cuándo, última actividad) y cortar. Es el
  modelo mental correcto: un vínculo externo es una credencial de una persona.
  No aplica a la PWA —ahí ya son las sesiones de siempre— pero queda como
  requisito de cualquier canal de §6.
- **D4 — Si hubiera bot, no lleva IA.** Textual: *"no sé si tener un bot de IA
  en Telegram, yo creo que es más como un canal de ingesta de archivos, o
  puede ser un bot preestructurado, pero no con IA"*. El modelo de visión ya
  corre del otro lado; un bot conversacional solo agregaría una vía de
  inyección de prompt sin aportar nada.

## 4. El trabajo

### F0 — Prerequisito de seguridad, antes que todo lo demás

`api/v1/purchases.php:21` y `api/v1/purchase-drafts.php:30` solo exigen
`apiAuthTenant(['panel'])`, **sin permission key dedicada** — ya documentado en
`context/modules/08-compras.md:45`. Hoy lo tapa que el único camino es el panel
y que quien entra al panel es alguien del comercio.

Este plan agrava eso: multiplica quién y desde dónde crea borradores de compra.
**La permission key de compras tiene que existir ANTES**, no después. Es
prerequisito, no mejora futura.

### F1 — Captura múltiple en la PWA

La pantalla: sacar N fotos seguidas sin salir de la cámara, verlas en una tira,
descartar la que salió mal, y subir el lote. Cada foto genera su
`purchase_draft` y entra a la cola de extracción que ya existe.

Lo que D1 pide explícitamente y hay que construir de verdad, no dar por hecho:

- **Control de calidad en la captura.** Resolución mínima, y rechazo o aviso
  cuando la foto sale movida o muy oscura — antes de subirla, no después de que
  el OCR devuelva basura. Es la mitad del valor de D1: el OCR que falla en
  silencio es lo que hace que el comercio culpe al sistema.
- **Límites explícitos**: cuántas fotos por lote y qué tamaño máximo por foto.
  `uploadImage()` ya redimensiona a 2000px del lado del server
  (`PurchaseDraftService.php:704`), así que el límite del cliente es sobre lo
  que se transfiere, no sobre lo que se guarda.
- **Subida resiliente**: en un celular en un depósito la conexión se corta. El
  lote sube de a una con reintento, y una foto que falla no tira las otras.
  Ojo con `syncPendingOps()` (`lib/pos/pending-ops-sync.ts`): trata todo fallo
  no clasificado como TERMINAL — si se reusa esa cola, un 5xx transitorio
  dejaría la factura en `failed`. La semántica correcta ya existe:
  `canSendPendingOp()` → `waiting`.
- **Deduplicación por hash del archivo**, para que subir dos veces el mismo
  lote no genere borradores duplicados.

### F2 — La bandeja de borradores

Ya existe (`purchase_draft` + su UI). Lo que hay que revisar es si aguanta un
lote de 20 de una: revisar y aprobar de a una es el cuello de botella real una
vez que capturar deja de serlo.

## 5. Decisión abierta que necesita al owner

**Cómo se cobra el procesamiento.** Cada factura consume un modelo de visión,
o sea plata. Punto ya tiene `ai_credit_ledger` para los créditos del asistente
(`context/09-costos-y-creditos.md`). Debitar de ahí es lo natural, pero
implica que un comercio sin créditos deja de poder cargar facturas por foto.
Las tres salidas —debitar de los créditos existentes, un cupo aparte, o
incluido en el plan— son decisión del owner y ninguna está tomada.

Con la PWA la pregunta se vuelve más urgente que con un bot: el canal es más
fácil de usar, así que el volumen va a ser mayor.

## 6. Telegram — evaluado y descartado (2026-09-06)

La idea original era un bot de Punto al que el comercio le manda las fotos.
Se descartó a favor de la PWA. Las razones, para no reabrirlo sin argumentos
nuevos:

**Las tres del owner (D1):** con Telegram no se incentiva el uso de la app
propia; **no se controla la calidad de la foto** —Telegram comprime lo que se
manda como "foto", y los números de una factura son lo primero que se degrada,
así que el OCR falla de formas silenciosas—; y **no se controlan los límites**,
porque el usuario manda lo que quiere desde una app que no es nuestra.

**El costo técnico, que además era desparejo.** Telegram arrastraba: un webhook
público entrante (patrón que **no existe** en el repo — el único webhook es
`api/v1/billing-webhook.php:1-48` de dLocal Go, y resuelve por un ID de pago
propio, no por un identificador externo del comercio); una tabla nueva que
mapee `chat_id` → usuario (**hoy nada mapea un identificador externo a un
tenant**: `auth_session` + realm `api` es para API keys y `device` es pareo de
aparato); códigos de pareo de un solo uso; una UI de revocación (D3); el
secreto del bot en `platform_config` (mig 108); y un refactor de
`PurchaseDraftService`, que hoy exige `$_FILES` + sesión de panel y no tiene
ninguna de las dos cosas desde un webhook. La PWA no necesita nada de eso: la
sesión ya existe y el endpoint ya recibe multipart.

**Lo único que Telegram daba y la PWA no**: reenviar el PDF que el proveedor
mandó por chat, sin bajarlo y volver a subirlo. Es una ventaja real y acotada.
Si aparece la demanda, se reabre — pero como **segundo** canal sobre el mismo
`purchase_draft`, nunca como pipeline propio, y con D2/D3/D4 ya cerradas
arriba: vínculo por usuario, revocable como sesión, bot sin IA.

**Si se reabre, esto ya está decidido y no se relitiga:**
- Un solo bot de Punto compartido, no uno por tenant (multiplicaría secretos y
  el alta de cada comercio). Consecuencia: el `chat_id` pasa a ser lo ÚNICO que
  dice de qué comercio viene cada foto, así que el vínculo es la pieza de
  seguridad central.
- Emparejamiento por **código de un solo uso**, nunca por `@username`: los
  usernames de Telegram se cambian y se liberan, así que alguien puede tomar
  uno abandonado y quedar recibiendo el vínculo de otro comercio. El `chat_id`
  numérico es estable. Es el mismo mecanismo de pareo que Punto ya usa para una
  tablet del POS.
- **Fail-closed con desconocidos**: un bot compartido es superficie pública.
  Un `chat_id` sin vínculo no recibe nada útil, y el bot NO confirma si un
  comercio existe.
- **Recibir, guardar, encolar, responder 200**: Telegram reintenta si el
  webhook tarda. Verificación del `X-Telegram-Bot-Api-Secret-Token` leyendo el
  body crudo antes del bootstrap, molde `billing-webhook.php:21`.
- Pedir la factura como **archivo**, no como foto (compresión).

## 7. Arquitecturas rechazadas — no reintroducir

- **Un pipeline de OCR nuevo para el canal nuevo.** Ya existe uno en
  producción; duplicarlo garantiza que diverjan y que la factura cargada por
  foto y la cargada a mano terminen valuadas distinto.
- **Que la foto se convierta en compra directa sin borrador.** Una compra mueve
  stock, costo promedio ponderado, cuentas por pagar y Libro IVA. Un OCR que se
  equivoca en un monto y entra solo corrompe el costeo de todo el catálogo.
  `purchase_draft` ya nace como borrador; no hay que inventar nada, hay que no
  saltearlo.
- **Vincular un canal externo al COMERCIO en vez de a un usuario.** El borrador
  entraría sin autor — el mismo bug de atribución que se arregló el 2026-09-05
  en `OrderCoreService::recordEvent()`, donde la acción quedaba a nombre de la
  tablet compartida. Contradice D2.
- **Meter un `chat_id` (o cualquier identidad externa) en `auth_session` o en
  `device`.** Son credenciales y aparatos, no identidades de terceros;
  disfrazar una de otra es el mismo error que `context/42-remision.md` marca
  sobre meter un `remisionid` en `einvoice_document`.
- **Subir la foto sin control de calidad y confiar en que el OCR se arregle.**
  Es exactamente lo que D1 vino a evitar.

## 8. Docs relacionados

- `context/32-ocr-facturas-compra.md` — el pipeline que este plan reusa entero.
- `context/modules/08-compras.md` — invariante de la IA sobre stock/finanzas, y
  el hueco de permisos de compras que es la F0.
- `context/09-costos-y-creditos.md` — `ai_credit_ledger`, de donde sale la
  decisión abierta de §5.
- `context/25-sucursales-y-scopes.md` — alcance de sucursal que el borrador
  hereda del usuario.
- `context/58-mcp-server.md` — otro caso de "identidad de un canal externo
  resuelve a un tenant/usuario", si algún día se reabre §6.
