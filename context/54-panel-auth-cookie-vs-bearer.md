# 54 — Panel a Bearer: matar la cookie de sesión del panel

> **Decisión del owner (2026-08-26): el panel migra a Bearer. Cerrada, no
> relitigar.** El motivo es de producto, no teórico: panel y `/pos` se usan en
> el MISMO navegador, sus credenciales se mezclan, y eso viene causando
> deslogueos del POS y bugs de sesión que bloquean la salida a producción.

## 0. La decisión y por qué

**Qué:** el realm `panel` deja de autenticar por cookie `_jwt_panel` y pasa a
`Authorization: Bearer`, igual que el POS. La cookie deja de emitirse.

**Por qué (razonamiento del owner, correcto):**

1. **Es un bug real, recurrente y que bloquea producción.** Cuatro incidentes de
   la misma clase en dos meses (2026-07-19, 08-24, 08-25, 08-26), todos con la
   misma raíz: el browser del operador lleva DOS credenciales (cookie del panel +
   Bearer del device) y algo del lado del server elige la equivocada. El cajero
   se desloguea. Eso no es aceptable en una caja.
2. **La cookie es la mitad ambiental del problema.** Una cookie viaja SOLA en
   toda request same-origin: nadie la eligió, el browser la adjunta. Un Bearer
   es explícito — el cliente HTTP lo pone a propósito. Sin cookie de panel, el
   browser deja de mandar credenciales por su cuenta y la clase de bug "llevo dos
   y el server elige mal" **deja de ser expresable**, no queda mitigada.
3. **El riesgo que se cambia es menos probable que el que se elimina.** La
   objeción clásica a Bearer es XSS (ver §4). Pero el panel es React con escapado
   por defecto, el POS ya vive con su token en el browser desde siempre, y los
   riesgos que de verdad enfrenta un tenant —phishing, dejar la sesión abierta en
   una PC ajena— **afectan igual a cookie y a Bearer**. Cambiar un riesgo
   hipotético por resolver uno que ocurre todas las semanas es la decisión
   correcta.

Las mitigaciones de §4 no son condición para migrar: son el trabajo que hace que
el saldo neto quede bien.

## 1. Cómo está hoy

| Cliente | Credencial | Realm |
|---|---|---|
| `lib/api-client.ts` (panel) | cookie `_jwt_panel` (`credentials:"include"`) | panel |
| `lib/api/pos-fetch.ts` (device) | Bearer en el browser (`credentials:"omit"`) | pos-app |

- Emisor único de la cookie: `authSetOpaqueCookie` (`api/includes/auth_session.php`),
  con `COOKIE_DOMAIN=.punto.la`.
- `authResolve()` **ya soporta Bearer para cualquier realm** y le da precedencia
  sobre las cookies (context/08 §60). El backend no necesita un modelo nuevo.
- **`/v1/login` YA devuelve el token en el body**
  (`{ok:true, data:{token, expiresIn, user}}`) además de setear la cookie. La
  credencial que el panel necesita ya está disponible para el cliente.

## 2. Lo que hace la migración viable (verificado 2026-08-26)

Se auditó el árbol buscando dependencias duras de la cookie. **No hay ninguna
bloqueante:**

- **Ningún Server Component depende de la cookie de auth.** Los dos layouts que
  llaman `cookies()` (`app/(panel)/layout.tsx`, `app/(admin)/layout.tsx`) leen
  `sidebar_state`, que es preferencia de UI. Era el riesgo grande (un Server
  Component no puede leer el storage del browser) y no aplica.
- **`PanelAuthGuard` ya es `"use client"`** — el gate de sesión del panel corre
  en el cliente, donde el token es accesible.
- **El backend ya está listo**: `authResolve` acepta Bearer en realm panel y
  `login.php` ya entrega el token.

Superficie a tocar (chica y enumerada): `lib/api-client.ts`, el catch-all
`app/api/v1/[...path]/route.ts`, y los 4 route handlers que hoy reenvían la
cookie cruda (`dashboard/income-chart`, `agent/chat`, `ocr-invoice`,
`geo/autocomplete` vía `getTenantCountry`).

## 3. Fases

**F1 — el cliente del panel manda Bearer.**
`api-client.ts` deja de usar `credentials:"include"` y adjunta
`Authorization: Bearer <token>`. El login guarda el token que `/v1/login` ya
devuelve. La cookie se sigue emitiendo en paralelo (todavía no se rompe nada).

**F2 — los BFF dejan de depender de la cookie.**
El catch-all pasa de `forwardCookie:true` a reenviar el `Authorization` entrante.
Los 4 route handlers que reenvían `cookie` cruda pasan a reenviar `Authorization`.
`getTenantCountry(cookie)` cambia de firma a token.
Al terminar F2, **ninguna ruta del panel necesita la cookie**.

**F3 — cutover.** Se deja de emitir `_jwt_panel` y se borra la existente. Re-login
masivo (los tokens de cookie no migran solos al storage del browser), igual que el
cutover de context/21. A partir de acá el browser no lleva ninguna credencial
ambiental de panel.

**F4 — limpieza.** Sacar `COOKIE_DOMAIN`, el path de cookie de panel en
`authSetOpaqueCookie`, `forwardCookie` del proxy, y `_authAmbientTokens()` deja de
mirar `_jwt_panel`. Actualizar context/08 §60: la tabla pasa a **Bearer para los
dos clientes**, y el guard `panel-cookie-no-bearer.test.ts` se reemplaza por uno
que exija que ninguna ruta del panel lea cookies de auth.

**Impersonación desde /admin (F2b, no olvidar).** Hoy el BFF de admin propaga el
`Set-Cookie` del upstream para "entrar como empresa". Con el panel en Bearer, ese
flujo pasa a **devolverle el token de la sesión impersonada al cliente**, que lo
guarda como cualquier token de panel. `issuePanelSession` ya devuelve
`['token' => ...]`, así que el cambio es de plumbing, no de modelo. Beneficio
lateral: desaparece la SEGUNDA `_jwt_panel` en el browser del admin, que fue
exactamente la causa del leak cross-tenant de `ad46b4c1`. `_jwt_admin` se decide
aparte (mismo dilema, distinto realm, sin urgencia).

## 4. Mitigaciones (parte del trabajo, no condición previa)

Un token legible por JavaScript se puede exfiltrar si alguien logra ejecutar
script en la página (XSS). Para que el saldo quede favorable:

- **Guardar el token en memoria del módulo, con `sessionStorage` de respaldo**
  (no `localStorage`): muere al cerrar la pestaña y no queda en disco.
- **Expiración corta + renovación.** Hoy el panel es 24h (`PANEL_JWT_TTL`). Con
  Bearer conviene bajarlo y renovar, para que un token robado tenga ventana chica.
- **CSP estricta** (sin `unsafe-inline`, sin hosts externos de script).
- **Auditar `dangerouslySetInnerHTML`** y las plantillas de impresión — React
  escapa por defecto, así que esos son los únicos puntos donde entra HTML crudo.

**Contra phishing y sesión abierta en PC ajena** —que es el riesgo más probable
en este negocio, y que cookie y Bearer sufren IGUAL— lo que sirve ya existe y
conviene apretarlo: `auth_session` es revocable, hay UI en `/settings/sessions`, y
el POS tiene bloqueo de pantalla. Vale sumar "cerrar todas mis sesiones" visible y
expiración por inactividad.

## 5. Estado

- [x] Emisor único de `_jwt_panel` (`ad46b4c1`) + `income-chart` deja de
      re-acuñar la cookie como Bearer (auditoría 2026-08-26).
- [x] Decisión del owner: **Bearer** (2026-08-26).
- [x] Verificado que ningún Server Component depende de la cookie de auth.
- [x] F1 — `api-client.ts` a Bearer (`credentials: "omit"` + token de
      `lib/auth/panel-token.ts`). Los CUATRO emisores de sesión de panel
      entregan el token al cliente: login, signup, cambio de sucursal
      (`active-outlet.php`, que RE-EMITE la sesión — antes la credencial nueva
      viajaba solo por cookie) e impersonación.
- [x] F2 — BFF sin cookie: el catch-all dejó de reenviarla y los 4 route
      handlers (income-chart, agent/chat, ocr-invoice, geo) reenvían
      `Authorization`. F2b: la impersonación devuelve el token en el body.
- [x] F3 — cutover: `issuePanelSession()` dejó de emitir `_jwt_panel` y ahora la
      BORRA en el browser.
- [x] F4 — limpieza: `_authAmbientTokens()` solo acepta `_jwt_admin` (la cookie
      de panel ya no autentica, verificado con arnés), `authSetOpaqueCookie()`
      eliminada (PHP no emite ninguna cookie de sesión), `forwardCookie` borrado
      del proxy, y §60 reescrito.

## 6. Lo que quedó afuera a propósito

- **El realm `admin` sigue en cookie** (`_jwt_admin`, emitida por el BFF de
  Next). Es otra superficie: no convive con el POS en el browser del cajero, que
  es lo que causaba los incidentes. Migrarlo es una decisión aparte, sin urgencia.
- **Mitigaciones de XSS de §4** (TTL corto del token de panel + renovación, CSP
  estricta, auditar `dangerouslySetInnerHTML`): pendientes. El token hoy dura lo
  mismo que duraba la cookie (`PANEL_JWT_TTL`, 24h) y se revoca igual desde
  `/settings/sessions`, así que no hay regresión — pero acortarlo es lo que
  achica la ventana de un token robado.
- **El WebSocket de realtime no autentica** (relay por canal `companyId`).
  Preexistente, ajeno a este cambio; no filtra datos, solo avisa "algo cambió".
