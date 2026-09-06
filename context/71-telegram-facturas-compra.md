# 71 — Telegram: facturas de compra por foto

> Plan sin implementar. Escrito 2026-09-06. D1-D3 cerradas por el owner;
> P1-P8 propuestas SIN su OK explícito. Hay una decisión ABIERTA que
> necesita al owner (§Decisión abierta) y un hueco de seguridad previo que
> este plan agrava y hay que cerrar ANTES de abrir el canal (§Prerequisito).
> Leer §Arquitecturas rechazadas antes de proponer nada.

## El hallazgo que define el alcance

**El pipeline de OCR ya existe entero y está en producción** (plan cerrado
`context/32-ocr-facturas-compra.md`). Telegram no es un pipeline nuevo: es
un **canal de ingesta nuevo** hacia el que ya está. Verificado:

- Tabla `purchase_draft` (mig 105): `api/database/migrations/postgres/105_purchase_draft.sql:12-27`
  — estados `queued|pending|approved|rejected|failed`, `extracted`
  (inmutable) vs `edited`, `imageref`, `contactid`, `transactionid`.
- `api/lib/Purchases/PurchaseDraftService.php` — `create()` (`:125`),
  `completeExtraction()`, `approve()` (`:247-338`, lock `FOR UPDATE`,
  idempotente), `reject()` (`:341-374`), `uploadImage()` (`:669`, dispatcher
  por mime real: PDF passthrough `:692`, imagen resize 2000px `:704`).
- Endpoint `api/v1/purchase-drafts.php:33`, POST multipart campo `image`
  (`:57`).
- Extracción por modelo de visión vía OpenRouter:
  `frontend/app/api/ocr-invoice/route.ts` +
  `frontend/lib/ai/extract-invoice.ts`, con drain de cola en
  `frontend/app/api/ocr-invoice/drain/route.ts`.
- Invariante ya vigente: la IA nunca escribe stock ni finanzas; `approve()`
  llama al MISMO `PurchasesService::create()` del alta manual
  (`context/modules/08-compras.md:33`).

Lo que falta es el **transporte y la identidad**, no el procesamiento.

## El problema (palabras del owner)

El comercio le saca fotos a las facturas de sus proveedores, se las manda a
un bot de Telegram de Punto, y Punto las procesa y las carga como borradores
de compra para que alguien las revise y apruebe. Puede mandar varias de una.

## Lo que falta (verificado)

1. **Ningún webhook entrante de mensajería.** El único webhook público es
   `api/v1/billing-webhook.php:1-48` (dLocal Go), y resuelve por un ID de
   pago propio, no por un identificador externo del comercio. El patrón "un
   tercero nos postea y nosotros resolvemos a qué tenant pertenece" **no
   existe todavía**. Sí sirve como molde de seguridad: lee el body crudo
   ANTES del bootstrap (`:21`) y verifica HMAC.
2. **Ninguna tabla mapea un identificador externo → tenant.** `auth_session`
   (mig 69) + realm `api` (`ApiKeyService.php:83`) es para API keys, y
   `device` (mig 11) es pareo de aparato. Ninguno sirve para un `chat_id`.
3. **`PurchaseDraftService` hoy exige `$_FILES` + sesión de panel.** Desde un
   webhook no hay ni una cosa ni la otra.
4. **La extracción la dispara el request del usuario** (`after()` de Next),
   no un worker. Con Telegram no hay nadie sosteniendo un request; hay que
   entrar por la cola/drain que ya existe.
5. Telegram: cero referencias en el repo. WhatsApp existe pero **solo
   saliente** (`api/lib/Notify/WhatsAppSender.php:1-60`, Evolution API).

## Decisiones — cerradas por el owner (2026-09-06)

- **D1 — Un solo bot de Punto, compartido por todos los comercios.** No un
  bot por tenant. Textual: "nosotros, como punto, vamos a tener nuestro bot
  de punto o nuestro canal de punto en Telegram". Consecuencia que hay que
  dejar escrita: si el bot es uno, el `chat_id` es LO ÚNICO que dice de qué
  comercio viene cada foto, así que el vínculo cuenta↔comercio pasa a ser la
  pieza de seguridad central de toda la feature.
- **D2 — Varias cuentas de Telegram por comercio.** Textual: "pueden tener
  varios o pueden tener uno". Es una tabla, no una columna.
- **D3 — Sector de setup en el panel** para dar de alta y de baja esas
  cuentas.

## Propuestas SIN OK del owner

- **P1 — El emparejamiento es por CÓDIGO DE UN SOLO USO, nunca por
  @username.** El panel genera un código con vencimiento, la persona le
  escribe `/vincular ABC123` al bot, y el bot guarda el `chat_id`
  NUMÉRICO. Por qué no el username: los de Telegram se cambian y se
  liberan, así que alguien puede tomar uno abandonado y quedar recibiendo el
  vínculo de otro comercio; el `chat_id` en cambio es estable. Es además el
  mismo mecanismo de pareo que Punto ya usa para una tablet del POS.
- **P2 — El vínculo es a un USUARIO del comercio, no al comercio.** Si
  colgara del comercio, la compra entraría sin autor — el mismo bug de
  atribución que se arregló el 2026-09-05 en `OrderCoreService::recordEvent()`
  (la acción quedaba a nombre de la tablet compartida). Atado a un
  `contact`, el borrador hereda sus permisos y su alcance de sucursal
  (`context/25`). D2 sale gratis: N vínculos, uno por persona.
- **P3 — Siempre borrador, nunca compra directa.** Una compra mueve stock,
  costo promedio ponderado, cuentas por pagar y Libro IVA. Un OCR que se
  equivoca en un monto y entra solo corrompe el costeo de todo el catálogo.
  El bot confirma recepción ("recibí 3 facturas, están para revisar"), la
  aprobación sigue siendo del panel. Esto NO es mecanismo nuevo:
  `purchase_draft` ya nace así.
- **P4 — Fail-closed con desconocidos.** Un bot compartido es superficie
  pública: cualquiera puede escribirle. Un `chat_id` sin vínculo no recibe
  nada útil y el bot NO confirma si un comercio existe.
- **P5 — Recibir, guardar, encolar, responder 200.** Telegram reintenta si
  el webhook tarda, así que el procesamiento va async por la cola/drain que
  ya existe. Verificación del `X-Telegram-Bot-Api-Secret-Token` leyendo el
  body crudo antes del bootstrap, molde `billing-webhook.php`.
- **P6 — El secreto del bot va en `platform_config`** (mig 108,
  `api/lib/Admin/PlatformConfig.php:1-35`), que es config global de
  plataforma y gana sobre env — igual que `integration.evolution`. NO va en
  `company.config` ni en el `CredentialVault` por tenant: el bot es de
  Punto, no del comercio.
- **P7 — Pedir la factura como ARCHIVO, no como foto.** Telegram comprime
  las imágenes mandadas como "foto" y los números de una factura son lo
  primero que se degrada. El bot tiene que pedir que se mande como archivo,
  o avisar cuando la calidad no alcanza — si no, el OCR falla de formas
  silenciosas y el comercio culpa al sistema.
- **P8 — Deduplicación por hash del archivo**, para que reenviar la misma
  foto no genere dos borradores.

## Decisión abierta que necesita al owner

**Cómo se cobra el procesamiento.** Cada factura consume un modelo de
visión, o sea plata. Punto ya tiene `ai_credit_ledger` para los créditos del
asistente (`context/09-costos-y-creditos.md`). Debitar de ahí es lo
natural, pero implica que un comercio sin créditos deja de poder mandar
facturas. Las tres salidas —debitar de los créditos existentes, un cupo
aparte, o incluido en el plan— son decisión del owner y ninguna está
tomada.

## Prerequisito de seguridad — cerrar ANTES de abrir el canal

`api/v1/purchases.php:21` y `api/v1/purchase-drafts.php:30` solo exigen
`apiAuthTenant(['panel'])`, **sin permission key dedicada** — ya está
documentado en `context/modules/08-compras.md:45`. Hoy lo tapa que el único
camino es el panel. Sumar un canal de ingesta desde afuera empeora eso:
pasaría a haber una vía no-panel hacia una familia de endpoints sin gate de
permisos. **La permission key de compras tiene que existir ANTES de abrir
el canal**, no después. Es prerequisito explícito, no mejora futura.

## Arquitecturas rechazadas — no reintroducir

- **Vincular por @username de Telegram.** Se cambian y se liberan (ver P1).
- **Un bot por tenant.** Descartado por D1; además multiplicaría los
  secretos a guardar y el alta de cada comercio.
- **Que el bot cree la compra directa sin borrador.** Ver P3.
- **Un pipeline de OCR nuevo para Telegram.** Ya existe uno en producción;
  duplicarlo garantiza que los dos diverjan y que la factura cargada por
  foto y la cargada por panel terminen valuadas distinto.
- **Meter el `chat_id` en `auth_session` o en `device`.** Son credenciales y
  aparatos, no identidades externas de terceros; disfrazar una de otra es
  el mismo error que el doc de remisiones marca sobre meter un `remisionid`
  en `einvoice_document`.

## Docs relacionados

- `context/32-ocr-facturas-compra.md` — el pipeline de OCR que este plan
  reusa entero; Telegram solo agrega un canal de ingesta.
- `context/modules/08-compras.md` — reglas 5 y 11 (invariante de la IA
  sobre stock/finanzas, hueco de permisos de compras).
- `context/09-costos-y-creditos.md` — `ai_credit_ledger`, de donde sale la
  decisión abierta de cobro.
- `context/25-sucursales-y-scopes.md` — alcance de sucursal que el borrador
  hereda del usuario vinculado (P2).
- `context/58-mcp-server.md` — otro caso de "identidad de un canal externo
  resuelve a un tenant/usuario", para comparar el patrón de pareo por código.
