# Plan: Checkout Screen (Visor al cliente)

**Estado:** En ejecución (iniciado 2026-06-20).
**Objetivo:** una pantalla standalone (`/checkout`) que se abre en una segunda tablet/monitor frente al cliente. Muestra el carrito en vivo + total + datos del cliente conforme el cajero opera en el POS. Sin interacción del cliente (read-only).

## Decisiones (cerradas con el owner)

1. **Vinculación = device pairing por token persistente** (no PIN de sesión). Modelo "Connected devices" estilo apps modernas — pareás una vez, queda permanente en localStorage, se revoca desde el panel. Mismo patrón que `device` (mig 11) usa el POS.
2. **Visual**: design system propio (Linear-inspired + brand verde `#01D7A1`), shadcn primitives, light/dark mode con `ThemePicker` existente. **NO replicar el legacy turquesa.**
3. **Tres estados visibles**: `live` (carrito conforme cargás) → `confirmed` (5s "¡Cobrado!" con total y cambio) → `idle` (esperando próxima venta). Cancelación → directo a idle.
4. **Alcance MVP**: read-only. Sin publicidades, sin tip selector, sin encuestas. Eso queda para v2.
5. **Realtime**: usa la infra que ya tenemos (`wsPublish` + ws-server + `lib/realtime.ts`).

## Arquitectura

```
┌─ POS (frontend/app/(pos)/pos) ─────────┐
│  cartStore cambia                        │
│  → debounce 200ms                        │
│  → POST /v1/screens/publish              │──┐
│  → PHP: realtimePublish(...)             │  │
└──────────────────────────────────────────┘  │
                                              ▼
                                        Redis Pub/Sub
                                        canal: <companyId>:checkout:<registerId>
                                              │
                                              ▼
                                        ws-server (Node)
                                              │
                                              ▼
┌─ Checkout Screen (frontend/app/(screen)/checkout) ─┐
│  WS suscribe al canal del registerId             │
│  recibe cart-update / sale-confirmed / cleared   │
│  re-render UI                                    │
└──────────────────────────────────────────────────┘
```

**Pairing flow** (una vez por dispositivo):

```
Screen sin token              POS (cajero)              Backend
     │                              │                       │
     │  POST /v1/screens/request    │                       │
     ├──────────────────────────────┼──────────────────────▶│
     │                              │                       │ genera PIN 6 dig
     │                              │                       │ TTL 5min en Redis
     │                              │                       │
     │  { pin, channel }            │                       │
     │◀─────────────────────────────┼───────────────────────┤
     │                              │                       │
     │ muestra PIN gigante          │                       │
     │ suscribe canal pairing:<pin> │                       │
     │                              │                       │
     │                              │ Ajustes → "Conectar   │
     │                              │ pantalla nueva"       │
     │                              │ ingresa PIN + nombre  │
     │                              │                       │
     │                              │ POST /v1/screens/pair │
     │                              ├──────────────────────▶│
     │                              │                       │ valida PIN
     │                              │                       │ crea customer_display
     │                              │                       │ genera JWT permanente
     │                              │                       │ publish pairing:<pin>
     │                              │                       │ con {token, registerId}
     │                              │ { ok }                │
     │                              │◀──────────────────────┤
     │  WS evento "paired"          │                       │
     │  con { token, registerId }   │                       │
     │◀─────────────────────────────┼───────────────────────┤
     │                              │                       │
     │ guarda token en localStorage │                       │
     │ pasa a modo live             │                       │
```

## Slices

| # | Slice | Resultado |
|---|---|---|
| A | **Schema + API** — mig 40 `customer_display`, endpoints `/v1/screens/*`, JWT helpers | Backend completo |
| B | **POS cart publish** — debounce en cartStore + sección "Pantalla cliente" en AjustesPanel | El POS publica + el cajero parea |
| C | **Screen `/checkout`** — layout fullscreen, 3 estados, WS auth con token persistente | La pantalla funciona |
| D | **Panel `/settings/devices`** — DataTable con listado + revocar | Gestión de pantallas pareadas |
| E | **Doc vivo** + cronología | Trazabilidad |

### Slice A — Schema + API

**Mig 40**: tabla `customer_display`.

```sql
CREATE TABLE IF NOT EXISTS customer_display (
  id           UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId    UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  registerId   UUID         NOT NULL,
  name         TEXT         NOT NULL DEFAULT '',
  tokenHash    TEXT         NOT NULL,       -- SHA256 del JWT para revocación
  ipFirst      INET,
  ipLast       INET,
  lastSeenAt   TIMESTAMPTZ,
  status       SMALLINT     NOT NULL DEFAULT 1,
  revokedAt    TIMESTAMPTZ,
  revokedBy    UUID,
  createdAt    TIMESTAMPTZ  NOT NULL DEFAULT now(),
  CHECK (status IN (0, 1))
);
CREATE INDEX idx_customer_display_company ON customer_display(companyId);
CREATE INDEX idx_customer_display_token ON customer_display(tokenHash) WHERE status = 1;
```

**Endpoints**:

- `POST /v1/screens/request` — público (sin auth, rate-limit por IP). Genera PIN 6 dígitos, lo guarda en Redis con TTL 5 min mapeando `pin → { ip, requestedAt }`. Devuelve `{ pin, channel: 'pairing:<pin>' }`. La screen suscribe al canal.
- `POST /v1/screens/pair` — auth `apiAuthTenant(['pos-app'])`. Body: `{ pin, name }`. Valida que el PIN existe en Redis, crea `customer_display` con `registerId` del JWT del POS, genera JWT `_jwt_screen` con `did=customerDisplayId`, `cid=companyId`, `rid=registerId`, TTL 10 años. Hash SHA256 del token → guarda en `customer_display.tokenHash`. Publica al canal Redis `pairing:<pin>` evento `paired` con `{ token, registerId, companyId, name }`. Borra el PIN de Redis.
- `GET /v1/screens` — auth `apiAuthTenant(['panel'])`. Lista pantallas del tenant con `{ id, name, registerName, ipLast, lastSeenAt, status, createdAt }`.
- `DELETE /v1/screens/:id` — auth `apiAuthTenant(['panel'])`. Soft-delete (`status=0`, `revokedAt=now()`, `revokedBy=userId`). Publica al canal `screen:<id>` evento `revoked` para que la screen pareada se desconecte inmediato.
- `POST /v1/screens/publish` — auth `apiAuthTenant(['pos-app'])`. Body: `{ type: 'cart-update' | 'sale-confirmed' | 'cart-cleared' | 'idle', data }`. Publica al canal `<companyId>:checkout:<registerId>`. Endpoint dedicado para que el cliente JS no tenga que conectar directo a Redis.

**Auth del WS para screens** (futuro): MVP usa canal por registerId sin auth al subscribe — la screen ya conoce el canal porque viene del token. Defensa: el `customerDisplayId` del token va con cada `POST /v1/screens/heartbeat` (cada 30s) que actualiza `lastSeenAt`. Si el row está `status=0`, backend devuelve 401 → screen entra a modo pairing.

### Slice B — POS cart publish + UI de pareo

**Cart publish** (en `frontend/lib/cart/store.ts` o componente que escucha el cart):

- `useCartPublisher()` hook: `useEffect` con dependencias `[cart, customer]`. Debounce 200ms. POST a `/v1/screens/publish` con tipo `cart-update`. Si el carrito vacío y no hubo cambio reciente, mandar `idle`.
- Al confirmar venta (después del `pay-dialog` exitoso): publicar `sale-confirmed` con `{ total, change }`. La screen muestra 5s y vuelve a idle solo.

**UI de pareo** (en `AjustesPanel` de `pos-main-menu.tsx`):

Agregar sección "Pantalla cliente" después de las existentes:

```tsx
<section>
  <h3>Pantalla cliente</h3>
  <p>Conectá una pantalla externa para que el cliente vea el carrito y el total.</p>
  <Button onClick={openPairDialog}>Conectar pantalla nueva</Button>

  {pairedDisplays.length > 0 && (
    <ul>
      {pairedDisplays.map(d => (
        <li>{d.name} · pareada {fromNow(d.createdAt)} <Button variant="ghost" onClick={() => revoke(d.id)}>Desconectar</Button></li>
      ))}
    </ul>
  )}
</section>
```

Dialog: input para PIN (6 dígitos con `InputOTP`) + input nombre ("Mostrador 1", "Caja recepción") + Confirmar → llama `POST /v1/screens/pair`.

### Slice C — Screen `/checkout`

Estructura:
```
frontend/app/(screen)/
  layout.tsx               ← fullscreen, sin nav/sidebar, color-scheme aware
  checkout/
    page.tsx               ← state machine pairing/live/confirmed/idle
    pairing-view.tsx       ← PIN gigante + spinner esperando
    live-view.tsx          ← carrito + total + cliente
    confirmed-view.tsx     ← "¡Cobrado!" + total + cambio (5s timer)
    idle-view.tsx          ← "Esperando próxima venta"
```

**State machine**:
- Mount → leer `localStorage.getItem('punto_screen_token')`.
- Si no hay token → `pairing-view` → llama `POST /v1/screens/request` → muestra PIN → suscribe al canal `pairing:<pin>` via WS → en `paired` event guarda token y va a `live`.
- Si hay token → conecta WS al canal `<companyId>:checkout:<registerId>` (extraídos del token JWT decoded client-side, no validado — el server valida los heartbeats).
- WS event `cart-update` → state `live` con el carrito.
- WS event `sale-confirmed` → state `confirmed` con timer 5s → `idle`.
- WS event `cart-cleared` o `idle` → state `idle`.
- WS event `revoked` (canal `screen:<id>`) → borra token de localStorage → `pairing-view`.

**Design system — reglas estrictas** (Sonnet tiende a romper esto, hay que vigilarlo):
- Colores: SOLO tokens del tema (`bg-background`, `text-foreground`, `bg-card`, `text-card-foreground`, `bg-primary`, `text-primary`, `bg-muted`, `text-muted-foreground`, `border-border`, `text-brand`). NUNCA hex hardcoded ni clases tipo `bg-teal-500`.
- Brand verde Punto solo en acentos: total grande, badge "Cobrado", ring del PIN focused. No fondos completos verdes.
- Tipografía: `font-sans` (heredado), tamaños `text-7xl/text-6xl/text-5xl` para el total, `text-3xl` para nombres de cliente, `text-2xl` para líneas del carrito, `text-base` para metadatos. NO custom fonts.
- Tipos shadcn: `Card`, `Badge`, `Separator`, `Button` (solo para "Reconectar" si WS falla), `InputOTP` para el PIN.
- Layout: grid 2-col en `live`: izquierda total (`bg-card` o `bg-muted`), derecha lista (`bg-background`). Padding generoso (`p-12`). En `pairing/confirmed/idle` el contenido va centrado vertical+horizontal.
- Dark/light: el ThemePicker existente debe estar accesible. Botón discreto top-right.
- Sin emojis, sin íconos decorativos. Solo lucide cuando aporta (CheckCircle2 en confirmed, ShoppingCart en idle).
- Cursor oculto después de 5s sin movimiento (CSS `cursor: none` con setTimeout).
- Auto-fullscreen sugerido (botón top-left que pide fullscreen API; no forzar).

### Slice D — Panel `/settings/devices`

Nueva ruta `frontend/app/(panel)/settings/devices/page.tsx`. Patrón DataTable estándar del proyecto.

Columnas: Nombre · Caja · IP última · Última conexión · Estado · Acciones.
Acciones: solo "Revocar" con AlertDialog confirmación.

Tab nueva en sidebar de settings con icono `Monitor` de lucide.

Hooks: `useScreens()`, `useRevokeScreen()` en `hooks/use-screens.ts`.

### Slice E — Doc vivo

Cronología al final del archivo a medida que se cierra cada slice.

## Cronología de commits

(Se completa a medida que se ejecuta cada slice.)
