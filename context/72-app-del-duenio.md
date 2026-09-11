# 72 — App del dueño (dashboard + Punto AI en el teléfono)

> Plan sin implementar. Escrito 2026-09-07, **ampliado y CORREGIDO 2026-09-09**.
> ⚠ **D1 REVERTIDA por el owner el 2026-09-09** (§8): es la MISMA PWA que
> `/pos`, una sola app instalable. Lo que sigue en §2-§3 quedó SUPERSEDED —
> se conserva porque el análisis de auth de §3 sigue siendo válido como
> descripción del sistema, pero su conclusión ("no pueden ser la misma app")
> era incorrecta. Leer §8 ANTES que §2.
> **D2 y D3 CERRADAS 2026-09-09** (§7): vive dentro de `frontend/`, y el tema
> del preset es una variación de DENSIDAD del mismo shadcn, no una marca nueva.
> P1-P4 y D4-D6 propuestas SIN su OK.
> Leer §5 y §7.6 Arquitecturas rechazadas antes de proponer nada.

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

## 7. Alcance ampliado — pedido del owner (2026-09-09)

> Textual: *"una versión más resumida del panel, con cosas básicas pero
> importantes, ej. el foco puede ser el Bot, reportes básicos, configuraciones
> importantes de emergencia... aparte de facturar tener el panel a mano para
> cosas de emergencia, cargar fotos de facturas de compra, habilitar cosas
> necesarias"*.

Confirma P1 (superficie enfocada, no el panel entero) y le suma tres cosas que
el plan del 07 no tenía: **configuración de emergencia**, **reportes básicos**
y **captura de facturas de compra**.

El encuadre del owner es el que ordena el alcance: la app no es "el panel en el
teléfono", es **el panel a mano cuando el dueño no está frente a la
computadora**. Eso descarta por sí solo cualquier pantalla de trabajo denso
(catálogo, edición masiva, plantillas de impresión) y define el criterio de
admisión: entra lo que se necesita RESOLVER EN EL MOMENTO, no lo que se
administra sentado.

### D2 — Vive dentro de `frontend/`, no en un proyecto nuevo. **Cerrada por el owner (2026-09-09).**

El pedido llegó con el comando `npx shadcn@latest init --preset b2D0vQ4au
--template next`, que crea un proyecto Next NUEVO. No es lo que hace falta y el
owner lo cerró así: va en el frontend actual.

**"PWA aparte" (D1) no es "proyecto aparte".** Dos PWAs son dos manifests con
`id`, `scope` y `start_url` propios — dos íconos en el teléfono. Eso se logra
sin mover un archivo de lugar: el sitio de marketing ya convive con el panel en
el mismo `frontend/`, ruteado por host en `middleware.ts` (`context/61`), y el
docblock de `app/manifest.ts` ya dice que una segunda PWA es "otra PWA con su
propio manifest e id", nunca otro repositorio.

Lo que un proyecto nuevo costaría, y por eso no se hace: duplicar el
api-client, los tipos de dominio, los hooks de datos y el manejo de sesión —
que a partir de ahí se mantienen dos veces y divergen— más un cuarto container
en Coolify con su propio deploy.

### D3 — El tema es una variación de DENSIDAD, no una marca nueva. **Cerrada por el owner (2026-09-09).**

Textual: *"el tema es solo una variación del framework shadcn que ya usamos;
pasa que esta versión se ve mejor en móviles y la que tenemos ahora se ve mejor
en desktop"*.

Eso cambia la naturaleza del trabajo. No es adoptar otra paleta: **la identidad
de marca no se toca**. Lo que cambia es la ergonomía táctil — radios, escala de
espaciado, altura de controles, tamaño de tipografía base.

El mecanismo existe y no requiere nada nuevo: `globals.css` ya expresa eso como
tokens (`--radius` y sus escalas, `--spacing`, mapeados por `@theme inline` de
Tailwind v4). La variación mobile **redefine esos tokens bajo la clase del
route group**, nunca en `:root`:

```css
/* NO en :root — repintaría panel, POS y sitio. */
.app-duenio {
  --radius: 0.7rem;     /* controles más redondeados, dedo antes que puntero */
  --spacing: 0.3rem;    /* escala base de Tailwind v4: sube TODO el spacing */
}
```

⚠ `--spacing` es la escala base de Tailwind v4: tocarlo mueve cada `p-*`,
`gap-*` y `m-*` del subárbol. Es justamente lo que se quiere acá, y justamente
por eso no puede filtrarse fuera del route group.

**Pendiente de dato**: el preset `b2D0vQ4au` es del registro de shadcn.com y no
se pudo resolver desde la sesión (el MCP de tweakcn no lo tiene). Antes de
implementar hay que extraer sus valores de tokens y portarlos a mano al bloque
de arriba. Correr `init` sobre `frontend/` NO es una opción: reescribiría
`globals.css` y `components.json` del producto entero.

### D4 — Cómo se separa la PWA: host propio. *(propuesta, resuelve P2)*

P2 dejó abiertas dos vías. Recomendación: **host propio** (ej.
`m.punto.la`), la vía (a).

Razón concreta, no estética: Next sirve UN `/manifest.webmanifest` global desde
`app/manifest.ts`, y Chrome solo ofrece instalar cuando la página cae dentro del
`scope` del manifest servido. Con ruteo por host, `middleware.ts` puede servir
un manifest distinto por host —patrón que el sitio de marketing ya ejercita— y
cada app queda con su identidad limpia. Con scope por path (`/m`) hay que
convivir con el manifest global del POS en el mismo origen, que es la fuente
exacta de instalaciones duplicadas que el docblock de `manifest.ts` advierte.

Requiere decisión de infra (subdominio + certificado). Es lo único de esta
fase que no se resuelve solo con código.

### D5 — Qué entra en la app. *(propuesta)*

Criterio: se resuelve en el momento, desde el teléfono.

| Pantalla | Estado del backend |
|---|---|
| Dashboard del día (ventas, caja, cuánta plata hay) | Existe — `DashboardService`, `DrawerService` |
| Asistente (Punto AI) | Existe — `/api/agent/chat`, mismo BFF del panel (P4) |
| Reportes básicos | Existe — rollups (`context/18`); falta decidir CUÁLES |
| Captura de facturas de compra por foto | Pipeline existe (`context/32`, `purchase_draft`); la captura es `context/71` F1 |
| Configuración de emergencia | **Sin definir — es lo único que no tiene lista cerrada** |

"Configuración de emergencia" es la pieza que hay que acotar antes de
construir: hoy es una intención, no un alcance. Candidatos que encajan con el
encuadre (algo se rompió y el dueño no está en la computadora): activar o
desactivar un módulo, habilitar/deshabilitar un usuario, liberar la tenencia de
una caja trabada (`context/29`), cambiar el estado de un dispositivo
(`/settings/devices`). Ninguno confirmado por el owner.

**Lo que NO entra, y conviene decirlo ahora**: nada que emita documento fiscal.
Facturar es de la caja, tiene su propia PWA y su propio mandato de auth.

### D6 — Permisos: los roles existentes, sin excepción móvil. *(propuesta, ratifica P3)*

Cada pantalla mide contra el permiso que la misma acción exige en el panel. La
app no relaja nada por ser "de emergencia": una configuración que el rol no
puede tocar sentado tampoco la puede tocar desde el teléfono. El alcance por
sucursal sale de `context/25` (consolidado para el dueño global, sucursales
asignadas para el resto).

### Hallazgo que corrige `context/71`

`context/71` D1 cerró que el canal de captura de facturas es *"la PWA de
Punto"*. Se escribió el **06**, un día ANTES de que la D1 de este doc creara la
segunda PWA (**07**). Cuando se escribió esa frase había una sola PWA y no era
ambigua; hoy lo es.

**Resolución**: la captura de facturas de compra vive en la app del DUEÑO, no
en la del POS. Es flujo de panel — realm `panel` por cookie, borrador vinculado
a un USUARIO (D2 de `context/71`), permiso de compras — y el POS es token-only
por mandato. Quien lea `context/71` sin esto lo va a construir en la PWA
equivocada.

### 7.5 Fases *(propuestas)*

| Fase | Qué | Depende de |
|---|---|---|
| **A0** | Extraer los tokens del preset y montar el route group + tema scopeado | Acceso al preset `b2D0vQ4au` |
| **A1** | Manifest propio + ruteo (D4) | Decisión de infra del subdominio |
| **A2** | Dashboard del día + asistente mobile-first | Nada — endpoints existen |
| **A3** | Captura de facturas de compra | F0 de `context/71` (la permission key de compras, que es prerequisito de seguridad) |
| **A4** | Reportes básicos | Definir cuáles |
| **A5** | Configuración de emergencia | Acotar la lista (D5) |

A2 es la que el owner nombró primero y la que no depende de nada: puede salir
sola.

### 7.6 Arquitecturas rechazadas — agregadas 2026-09-09

- **Proyecto Next nuevo** (`--template next`). Ver D2: duplica api-client,
  tipos, hooks y sesión, y suma un cuarto container. "PWA aparte" se cumple con
  manifest e `id` propios dentro del mismo proyecto.
- **Correr `npx shadcn init --preset` sobre `frontend/`.** Reescribe
  `globals.css` y `components.json` del producto entero — repinta el panel, el
  POS que está facturando en producción, y el sitio. Los tokens del preset se
  portan a mano al bloque scopeado.
- **Redefinir `--spacing`/`--radius` en `:root` para la variación mobile.**
  Mismo efecto que lo anterior por otra vía. Van bajo la clase del route group.
- **Una tercera identidad de auth para la app.** Ya rechazada en §5 y sigue
  valiendo: la persona existe en el realm `panel`.
- **Facturar desde la app del dueño.** Es de la caja: otra PWA, otro realm,
  otro mandato.

## 8. D1 REVERTIDA — es la misma PWA que `/pos` (owner, 2026-09-09)

> Textual: *"la idea es que sea la misma PWA de /pos"*.

### 8.1 Por qué el argumento original era flojo

§3 hecho 2 decía: *"no pueden ser la misma app aunque se quisiera — por AUTH"*.
**Eso es incorrecto y hay que dejarlo escrito para que nadie lo vuelva a citar.**

- El manifest **no es una barrera de seguridad**. `scope` decide qué es
  instalable y qué navega dentro de la app; no filtra credenciales ni
  requests.
- Panel y `/pos` **ya comparten origen hoy**: `app.punto.la` sirve los dos, y
  los dos tokens ya conviven en un mismo browser (`_jwt_panel` de la persona,
  `_jwt` del device). Eso no lo introduce esta decisión: ya es el estado
  actual.
- Lo que sostiene el mandato token-only es el **código**: `credentials:
  "omit"` en `lib/api/pos-fetch.ts`, que `/api/pos/*` no reenvíe cookies, y el
  guard `lib/bff/__tests__/pos-token-only.test.ts`. Unificar la PWA no toca
  ninguna de esas tres cosas.

El mandato sigue vigente sin cambios. Lo que cae es la afirmación de que el
manifest lo defendía.

### 8.2 Lo que hace innecesaria la separación: el modelo de uso del owner

Textual: *"si la empresa es de solo una persona (el dueño) es una app y tiene
todo ahí, facturación, reportes todo. Si es una empresa con empleados puede que
los empleados instalen para operar el /pos pero no tienen acceso al panel —
mozos, cajeros— y el dueño usa solo la app para el panel pero no usa la caja
porque eso lo usa su personal"*.

**La credencial presente YA determina la superficie.** No hace falta un modo,
un flag ni un rol nuevo:

| Quién | Qué tiene | Qué ve |
|---|---|---|
| Mozo / cajero | Device pareado, sin cookie de panel | Solo la caja |
| Dueño con personal | Cookie de panel, sin device pareado | Solo el panel |
| Dueño unipersonal | Las dos | Todo |

Es el mismo mecanismo que ya gobierna el panel hoy; la app instalada no agrega
una decisión de autorización nueva.

### 8.3 Qué cambia, en concreto

En `frontend/app/manifest.ts`:

- `scope: "/pos"` → `"/"`.
- `description: "Caja registradora del comercio"` → deja de ser cierto.
- `start_url`: hoy `/pos`. Un dueño sin caja aterrizaría en una caja que no
  usa. Necesita una ruta de arranque que derive según la credencial presente
  (`start_url` es estático; la decisión va en la página de arranque).

⚠ **`id: "/pos"` NO SE TOCA.** Es un identificador opaco, no una ruta.
Cambiarlo convierte la app en otra distinta para todo teléfono que ya la tenga
instalada: quedan dos íconos y la instalación vieja deja de actualizarse. Hay
comercios facturando con esa app instalada. El docblock del archivo ya explica
por qué `id` existe; esto es el corolario operativo.

**El service worker no se toca**: se registra desde `public/sw.js`, así que su
scope YA es `/`, no `/pos`. Ampliar el manifest no lo altera.

**Consecuencia declarada**: las pantallas de panel dentro de la app instalada
NO tienen precache. Sin red no cargan. El POS sigue siendo la única superficie
offline-first (`context/43`, `context/51`), y está bien que así sea — lo que se
emite funciona sin internet, el panel no lo necesita.

### 8.4 Qué queda en pie de §7 y qué cae

**Queda**: D3 (el tema es variación de densidad, tokens bajo la clase del route
group), D5 (alcance de pantallas), D6 (los roles existentes gobiernan), el
hallazgo que corrige `context/71`, y las fases A2-A5.

**Cae**: D4 (host propio para la segunda PWA) — ya no hay segunda PWA que
separar. La app móvil es un route group más del mismo origen. Y A1 (manifest
propio + ruteo) se reduce a los tres campos de 8.3.

**Cambia de naturaleza**: D2 decía "vive dentro de `frontend/`, no en un
proyecto nuevo". Sigue valiendo y ahora es más fuerte: no solo el mismo
proyecto, la misma app instalada.

### 8.5 Arquitecturas rechazadas — actualizado

- **Segunda PWA / segundo manifest / subdominio propio.** Revertido por el
  owner. No reabrir sin pedido explícito.
- **Cambiar `id` en `manifest.ts`.** Duplica instalaciones en producción.
- **Usar el manifest como control de acceso.** No lo es. El aislamiento de
  realms vive en `pos-fetch.ts`, en que `/api/pos/*` no reenvíe cookies, y en
  el guard de token-only.
- **Un modo/flag/rol para decidir si alguien ve caja o panel.** La credencial
  presente ya lo resuelve (8.2).
- **Precachear el panel para que ande offline dentro de la app.** El panel no
  es offline-first y no debe serlo: sus pantallas leen estado compartido.
