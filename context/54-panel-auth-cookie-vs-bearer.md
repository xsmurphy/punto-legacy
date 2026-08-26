# 54 — Autenticación del panel: cookie vs Bearer (plan post-auditoría 2026-08-26)

> Disparado por la auditoría de seguridad del 2026-08-26 (leak cross-tenant real
> en el panel). Cierra el pedido recurrente del owner de "abandonar cookies por
> Bearer". **Es una decisión abierta del owner** — este doc pone los dos caminos
> con sus tradeoffs y una recomendación; no relitiga nada ya decidido.

## 0. TL;DR

La causa de los incidentes NO es "cookies en vez de Bearer". Es **dos cosas
concretas y arreglables sin tocar el modelo**:

1. El `COOKIE_DOMAIN=.punto.la` **wildcard** — hace que la cookie del panel
   valga en todos los subdominios, y habilita que convivan dos cookies del
   mismo nombre con scopes distintos (host-only vs domain-wide).
2. **Emisores divergentes** de la misma cookie (el BFF de impersonación acuñaba
   su propia `_jwt_panel` host-only en paralelo al emisor PHP domain-wide). Ya
   unificado en `ad46b4c1`; el `income-chart` que la leía por nombre y la
   re-mandaba como Bearer, unificado en esta auditoría.

Mover el panel a **Bearer-en-localStorage es un DOWNGRADE de seguridad** para
una app web: cambia una clase de bug (scope de cookie mal configurado, ya
cerrada) por otra peor (robo del token por XSS — una cookie `HttpOnly` no la
puede leer un script, un Bearer en `localStorage` sí). El POS usa Bearer porque
es un dispositivo pareado, no un navegador con superficie de XSS de terceros.

**Recomendación: Opción A** (cookie host-only + emisor único), no Bearer.

## 1. Cómo está hoy

| Cookie | Emisor | Scope | Flags | Realm |
|---|---|---|---|---|
| `_jwt_panel` | `authSetOpaqueCookie` (PHP, único) | `domain=.punto.la` (wildcard) | HttpOnly, Lax, Secure | panel |
| `_jwt_admin` | BFF admin (Next, único) | host-only | HttpOnly, Lax, Secure | admin |
| `_jwt` | device pairing (legacy) | — | — | pos-app |

- El browser del operador solo habla con **`app.punto.la`** (el Next app). Todo
  el tráfico a la API PHP es **server-to-server desde el BFF** (`API_URL`
  interno), nunca del browser directo. → la cookie del panel **no necesita
  cruzar subdominios**: el wildcard `.punto.la` es (hoy) vestigial.
- `authResolve()` ya tiene precedencia Bearer-sobre-cookie y robustez
  multi-candidato (context/08 §60). El POS ya es token-only.

## 2. Opción A — cookie host-only + emisor único (RECOMENDADA)

**Qué:** dejar de emitir `_jwt_panel` con `domain=.punto.la`; emitirla
**host-only** (sin `domain`), igual que `_jwt_admin`. Un solo emisor, HttpOnly,
`SameSite=Lax`, `Secure`.

**Por qué cierra la clase de bug:** sin `domain` wildcard, no pueden coexistir
dos `_jwt_panel` de scope distinto en el mismo browser — es imposible que un
consumidor lea una y otro consumidor lea otra. El leak de 2026-08-26 deja de
ser expresable.

**Por qué NO es un downgrade:** sigue siendo `HttpOnly` → un XSS no la roba. Se
conserva todo lo bueno de la cookie; solo se saca el wildcard que sobra.

**Prerrequisito DURO a confirmar con infra (owner):** que ningún browser del
usuario pegue directo a otro subdominio que `app.punto.la` (ej. `api.punto.la`,
`screen.punto.la`) esperando la cookie. Si TODO pasa por el BFF same-origin
—como parece hoy— host-only alcanza. Si algún flujo del browser cruza
subdominio, ese flujo hay que ruteralo por el BFF antes (o queda como la única
`razón dura` para conservar el wildcard, y entonces se documenta acá).

**Alcance (chico):**
1. `api/includes/auth_session.php` — `authSetOpaqueCookie`/`authClearCookie`:
   dejar de aplicar `COOKIE_DOMAIN` a `_jwt_panel` (o directamente vaciar el env
   en la config del panel). El set y el clear tienen que usar el MISMO scope
   (un clear con domain distinto no borra la cookie → sesión zombie).
2. Migración de sesiones vivas: las cookies domain-wide ya emitidas siguen
   viajando hasta que expiren. Emitir un `Set-Cookie` de borrado de la variante
   domain-wide en el próximo request autenticado (o forzar re-login). Sin esto,
   por un tiempo conviven la vieja (domain) y la nueva (host-only) — el mismo
   patrón de dos scopes que queremos matar. **El borrado de la variante vieja es
   parte del fix, no un extra.**
3. Guard de test: extender `panel-cookie-no-bearer.test.ts` (o el arnés PHP) a
   que falle si `_jwt_panel` se emite con `domain`.

**Qué NO rompe:** el POS (token-only, no toca esta cookie), la impersonación
(ya propaga el `Set-Cookie` del upstream — heredaría el scope host-only sin
cambios), el resto del panel (mismo origen).

## 3. Opción B — Bearer para el panel (pedido del owner)

**Qué:** el panel deja de usar cookie; guarda el token opaco `pt_` en memoria/
`localStorage` y lo manda en `Authorization: Bearer` en cada request, como el
POS. `authResolve` ya lo soporta (la precedencia Bearer ya existe).

**Fases:**
- **F1** — `lib/api-client.ts` adjunta `Authorization: Bearer <token>` en vez de
  depender de la cookie; el login guarda el token donde lo lea el cliente.
- **F2** — el catch-all BFF `/api/v1/[...path]` deja de necesitar
  `forwardCookie` para el panel (el Bearer viaja solo). `income-chart` y
  cualquier route handler que hoy reenvía cookie pasan a reenviar el
  `Authorization` entrante.
- **F3** — cutover: re-login masivo (los tokens de cookie no migran a
  localStorage solos). Igual que el cutover del auth rewrite (context/21).
- **F4** — borrar `COOKIE_DOMAIN`, `authSetOpaqueCookie` para panel, y el
  `forwardCookie` del catch-all. `_jwt_admin` decide aparte (mismo dilema).

**Qué ROMPE / a vigilar:**
- **XSS = robo de sesión.** Es el costo real. Mitigaciones si se toma este
  camino: tokens de vida corta + refresh, CSP estricta, y aún así la superficie
  es peor que HttpOnly. Un panel es una app con mucho input de terceros
  (nombres de productos, notas, etc.) — no es el modelo de amenaza del POS.
- SSR / route handlers que hoy leen la cookie para pre-renderizar pierden la
  credencial (el server no ve `localStorage`) — hay que pasar el token por otro
  canal o mover esas lecturas al cliente.
- CSRF cambia de forma: con Bearer no hay envío automático (bueno), pero hay que
  asegurar que ningún endpoint siga aceptando la cookie como fallback para el
  realm panel (si no, no ganás nada).

## 4. Recomendación

**Opción A.** Cierra exactamente la clase de bug de los incidentes (scope de
cookie divergente) al menor costo y SIN degradar la resistencia a XSS. La
Opción B satisface la preferencia expresada del owner pero cambia un riesgo
cerrado por uno peor y abierto; si el owner igual la quiere, que sea con los ojos
en el tradeoff de XSS de §3, no como "lo seguro".

Decisión del owner. Este doc no implementa ninguna de las dos — la auditoría
2026-08-26 ya dejó cerrado el leak agudo (emisor único + income-chart), que es
lo urgente; la elección A/B es el paso de fondo, sin apuro.

## 5. Estado

- [x] Emisor único de `_jwt_panel` (ad46b4c1) + `income-chart` deja de re-acuñar
      Bearer (auditoría 2026-08-26).
- [ ] Decisión owner: A (host-only) vs B (Bearer).
- [ ] Confirmar con infra el prerrequisito de §2 (nada del browser cruza
      subdominio).
