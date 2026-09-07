# 72 — App del dueño (dashboard + Punto AI en el teléfono)

> Plan sin implementar. Escrito 2026-09-07.
> **D1 CERRADA por el owner el mismo día**: es una PWA APARTE, no el panel
> hecho instalable ni una extensión de la PWA del POS.
> P1-P4 propuestas SIN su OK.
> Leer §5 Arquitecturas rechazadas antes de proponer nada.

## 1. Qué resuelve

El dueño del comercio quiere, desde su teléfono, su dashboard del día (ventas,
estado de la caja, cuánta plata hay) y tener a mano el asistente IA de Punto.
Pedido del owner, 2026-09-06, textual: *"los dueños de los comercios también
quieren tener en una app su dashboard, y donde puedan ver por lo menos sus
ventas, el estado de la caja, cuánta plata hay, cuánto se vendió en el día,
etcétera. Y también tener a mano el Punto AI"*.

## 2. Decisión — cerrada por el owner (2026-09-07)

- **D1 — Es una PWA APARTE.** Textual: *"que sean como dos apps"*. No el
  panel hecho instalable, no una extensión de la PWA del POS. Dos íconos, dos
  apps.

## 3. Hechos verificados (2026-09-06/07) que fundamentan D1

1. **El manifest actual ya anticipó esta decisión.** `frontend/app/manifest.ts`
   tiene `scope: "/pos"` deliberado, y su docblock dice textual que el panel
   NO es instalable con este manifest, y que si tiene que serlo *"es otra PWA
   con su propio manifest e id — decisión de producto, no un ajuste de este
   archivo"*.
2. **No pueden ser la misma app aunque se quisiera — por AUTH.** El POS es
   token-only por MANDATO (Bearer del device, sin cookies — tres incidentes de
   la misma clase: 2026-07-19, 08-24, 08-25; ver la memoria del proyecto y
   `context/modules/23-auth-y-permisos.md`). El dashboard y el agente son realm
   `panel` (cookie de una PERSONA). Una sola app instalada sirviendo los dos
   realms reintroduce el "primera credencial válida gana" que el mandato
   prohíbe. Son dos identidades: una es la caja, la otra es la persona dueña.
3. **El agente hoy está OCULTO en mobile.** La ruta `/chat` tiene
   `hideOnMobile: true` (`frontend/lib/navigation/routes.ts`, entrada de
   `/chat`). Justo lo que el owner quiere en el teléfono es lo único que el
   panel esconde ahí — esa pantalla se rehace mobile-first, no solo se
   desbloquea.

## 4. Propuestas — SIN OK del owner

- **P1 — Superficie enfocada, NO el panel entero en el teléfono.** El panel
  es denso y de escritorio; instalarlo no lo vuelve usable. Lo que el owner
  nombró son 3-4 pantallas mobile-first contra endpoints que YA existen:
  dashboard del día (ventas, caja — `DashboardService`, `DrawerService`), y
  el chat del agente (`/api/agent/chat`, mismo BFF del panel). Se empieza por
  eso; lo demás se suma cuando se pida.
- **P2 — La mecánica de la segunda PWA.** Next sirve UN
  `/manifest.webmanifest` global (`app/manifest.ts`). La segunda app necesita
  manifest, `id`, `scope` y `start_url` propios. Dos caminos posibles, SIN
  decidir: (a) ruteo por HOST (patrón que ya existe: el sitio de marketing
  rutea por host en `middleware.ts`, `context/61`) con su manifest servido
  para ese host; (b) scope por path (ej. `/m`) con un manifest estático
  linkeado solo en el layout de ese route group. La decisión es de
  infra/producto (¿subdominio nuevo?) y queda abierta.
- **P3 — Permisos y alcance.** La app del dueño no es solo para el dueño: un
  encargado con su usuario ve lo que su rol y su alcance de sucursal permitan
  (`context/25` — view-scope, consolidado para el dueño global, sucursales
  asignadas para el resto). Nada de un rol nuevo: los roles existentes
  gobiernan.
- **P4 — El agente móvil reusa el chat del panel** (mismo endpoint, mismas
  tools, mismos créditos) con UI mobile-first propia. NO un BFF nuevo — el
  del POS (`context/59`) existe por el realm del device, que acá no aplica.

## 5. Arquitecturas rechazadas — no reintroducir

- **El panel "instalable"** — no resuelve la usabilidad móvil y contradice
  D1.
- **Meter el dashboard del dueño DENTRO de la PWA del POS** — mezcla de
  realms prohibida (hecho 2, el mandato token-only); además el POS es de la
  caja compartida, no de una persona.
- **Un realm/auth nuevo para la app** — la persona ya existe (realm `panel`,
  login por teléfono); una credencial nueva sería una tercera superficie de
  auth sin necesidad.

## 6. Docs relacionados

- `frontend/app/manifest.ts` — manifest actual del POS, con el docblock que
  ya anticipa esta decisión.
- `context/modules/23-auth-y-permisos.md` — el mandato token-only del POS y
  el realm `panel` por cookie.
- `context/25-sucursales-y-scopes.md` — view-scope que P3 hereda.
- `context/59-asistente-en-la-caja.md` — el agente del lado del POS, con BFF
  propio por el realm del device; contraste directo con P4.
- `context/61-sitio-marketing.md` — el ruteo por host que P2 (a) reusaría.
