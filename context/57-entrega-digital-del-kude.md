# Entrega digital del KuDE — plan del módulo

> Estado: **plan cerrado 2026-08-29, sin implementar.** Decisiones D1–D8 tomadas
> con el owner en la sesión del 2026-08-29 — no relitigar sin motivo nuevo.
> Alcance de esta iteración: **solo email**. WhatsApp queda en el roadmap con la
> investigación ya hecha (§6), para que la próxima sesión no la rehaga.

## Por qué

`context/49` cerró que el impreso por defecto de la caja es un **ticket interno
sin valor fiscal** con QR al portal, y que eso ya cumple el art. 25.2.c del
Decreto 872/2023 (poner el KuDE a disposición para descarga, consulta o
impresión). La fase **P3** de ese plan —"envío digital del KuDE al cerrar la
venta"— quedó enunciada en una línea y sin diseñar.

Este documento la diseña. No cambia nada de `context/49`: el ticket + portal
siguen siendo lo que sostiene el cumplimiento, y el envío digital es la copia
**durable** que el comprador se lleva sin depender de un papel.

## Decisiones cerradas

### D1 — La entrega la hace el COMERCIO, nunca el cliente pidiéndola

Regla del owner (2026-08-29) y es el criterio que ordena todo lo demás. El
art. 25 pone la obligación de envío/puesta a disposición en el **emisor**. Un
diseño donde el comprador tiene que escribir para recibir su factura invierte
quién responde por la entrega, aunque técnicamente funcione.

Consecuencia directa: **todo mecanismo customer-initiated queda descartado como
canal principal.** Puede existir como comodidad adicional, nunca como el camino
por el que Punto dice "la factura fue entregada". Ver §6, donde el patrón
`wa.me` con texto pre-cargado se evaluó y se descartó por esto (no por costo).

### D2 — Email es el único canal digital de esta iteración

Ya está en la tabla de costos **absorbidos** por la empresa (`context/09`:
Resend/Mailgun, "por email, free tier + overage"), no necesita aprobación de
terceros, no tiene ventana de tiempo, y es el único canal donde el PDF puede ir
**adjunto de verdad** en vez de como link.

Lo que email NO cubre, y se asume a propósito: el cliente de mostrador que no
deja email. Ese caso **ya está cubierto por el ticket** (QR al portal, K0/P1 de
`context/49`) — no queda nadie sin vía de acceso al KuDE. Email agrega la copia
durable para quien la quiera, típicamente contribuyentes y B2B.

### D3 — El disparo es `sifen_status = Aprobado`, NO el cierre de la venta

La decisión más contraintuitiva del plan, y la que más caro sale equivocar.

Un CDC con `Success: true` **no** significa que SIFEN aceptó: está comprobado
contra DEV (`context/28`, §"CRÍTICO"). La primera factura de prueba volvió con
CDC válido, `Success: true` y KuDE descargable de 33 KB — y SIFEN la había
rechazado con código 1002. **El único campo que dice la verdad es
`sifen_status`.**

Mandar el email al cerrar la venta es mandarle al cliente un documento que
puede caerse minutos después. La reconciliación por `getBulk` ya existe y ya
escribe `sifen_status`: ese es el trigger, no `SaleService`.

Estados y qué hace cada uno:

| `sifen_status` | Acción |
|---|---|
| `Pendiente` | Nada. No es rechazo (`Success: false` en este estado es transitorio) — se re-consulta |
| `Aprobado` | Encola el envío |
| `Rechazado` | No se manda nada. El comercio lo ve en el DataTable de documentos (F2) |

### D4 — Outbox de notificaciones genérico, no un método en `EInvoiceService`

`notification_outbox` + un adapter por canal. El envío del KuDE es el **primer**
consumidor, no el único: la cotización en PDF (`context/56`) necesita
exactamente el mismo camino, y después van a venir comanda lista y recordatorio
de cobro.

Si esto nace como `EInvoiceService::sendKudeByEmail()`, en tres meses hay tres
copias del reintento, tres criterios de idempotencia y ningún lugar donde ver
qué se mandó. Mismo patrón que el outbox de FE, que ya funciona bien.

Invariantes del outbox:

- **Idempotencia por `(companyId, entityType, entityId, channel, recipient)`** —
  una reconciliación que corre dos veces no manda dos emails.
- **Reintento con backoff** y tope de intentos; el fallo definitivo queda
  visible, no en silencio.
- **El adapter no decide QUÉ mandar**, solo cómo. El contenido lo arma quien
  encola.

### D5 — `Notification::sendEmails()` gana soporte de adjuntos — se arregla el wrapper

El wrapper compartido hoy solo manda `html` (`api/lib/App/Services/Notification.php`):
no hay `attachment` ni `multipart` en ninguna de sus firmas. Mailgun sí lo
soporta.

**El adjunto se agrega en el wrapper**, no con un curl propio en el camino del
KuDE. Es la regla del proyecto (`CLAUDE.md` §5): el call-site que necesita algo
que el wrapper no da es señal de que falta en el wrapper. La cotización PDF va a
necesitar lo mismo.

Además el wrapper es hoy fire-and-forget (devuelve `true` o un string de error,
sin persistencia ni reintento). El outbox de D4 es lo que le da durabilidad —
`Notification` sigue siendo el transporte, no el que recuerda.

### D6 — El email lleva el PDF adjunto Y el link al portal

Los dos, no uno:

- **Adjunto**: es lo que el comprador archiva y le pasa a su contador. Es la
  razón de existir del canal email.
- **Link al portal**: el adjunto queda congelado en el momento del envío. Si el
  documento después se **anula** (`cancel()`, `context/28`), el PDF en la
  bandeja del cliente sigue diciendo que la factura vale. El link siempre
  muestra el estado fiscal real.

El cuerpo del mail dice explícitamente que el estado vigente vive en el portal.

### D7 — De dónde sale la dirección, y qué pasa si no hay

`contact.email` del cliente de la venta. Sin email cargado **no se encola nada**
— no es un error de la venta, es la ausencia de un canal opcional. El ticket ya
entregó.

No se pide email en la caja como paso obligatorio: sería fricción en el momento
de más apuro, contra `project_pos_touch_keyboard_first`. La captura vive en el
alta/edición del contacto, donde ya está.

### D8 — Reenvío manual desde el panel

Acción sobre el documento en el DataTable de FE: reenviar a la misma dirección o
a otra. Es el escape para "no me llegó" / "mandámelo a la del contador", y
cubre al cliente que cargó su email después de la venta.

Gateada por `einvoice.manage`, el permiso que ya existe.

## Arquitectura

```
reconciliación getBulk  ──(sifen_status='Aprobado')──>  NotificationOutbox::enqueue(
                                                          entityType: 'einvoice_document',
                                                          entityId:   <docId>,
                                                          channel:    'email',
                                                          recipient:  <contact.email>)
                                                                  │
                          crond (mismo scheduler del drain de FE) ─┤
                                                                  ▼
                                                     NotificationOutbox::drain()
                                                                  │
                                            EmailAdapter ─────────┤
                                                                  ▼
                                    Notification::sendEmails([... 'attachment' => KuDE PDF])
```

El KuDE se baja de Factomate en el momento del envío (`getkude`), no se
persiste: mismo criterio que hoy: si el PDF todavía no está generado, la
excepción sube y el ítem del outbox se reintenta — nunca se marca `error` por
eso, la factura ya está emitida (`context/28`).

## Fases

| Fase | Qué | Esfuerzo | Depende de |
|---|---|---|---|
| **E0** | `notification_outbox` (mig) + `NotificationOutbox::enqueue/claim/drain` con CAS, idempotencia y backoff. Sin adapters todavía | M | — |
| **E1** | Adjuntos en `Notification::sendEmails()` (D5) + `EmailAdapter` | S | E0 |
| **E2** | Enganche en la reconciliación (D3) + plantilla del cuerpo del mail (D6) | S | E1, `getkude` verificado |
| **E3** | Job en el `crond` de la imagen del API, al lado del drain de FE | S | E0 |
| **E4** | Reenvío manual desde el panel (D8) + columna "enviado" en el DataTable de FE | S | E2 |

Orden: E0 → E1 → E3 → E2 → E4. E3 antes que E2 a propósito: un outbox sin
consumidor es el error que ya se cometió con la cola de OCR (`_handoff` del
2026-08-28, "la cola nació sin consumidor").

## 6. WhatsApp — evaluado y diferido (2026-08-29)

**No está descartado, está sin resolver.** Queda en `context/10-roadmap.md`.
Esto es lo que ya se investigó, para no rehacerlo:

**Evolution API: descartada.** Es WhatsApp no oficial. Mandar PDFs a gente que
nunca escribió al número es el disparador clásico de baneo — y el número que se
quema es **el del comercio**, el de su cartel, no el de Punto. Hoy Evolution
solo alimenta el OTP de signup (`SignupOtp`, `SIGNUP_OTP=off` por default): no
hay una capa de mensajería general que reusar.

**Kapso (Cloud API oficial): viable, con costo por mensaje.** Verificado
2026-08-29: MCP autenticado, proyecto Brixton, 9 números, 11 customers; maneja
números y templates, o sea BSP oficial. El bloqueo no es técnico, es el modelo
de costo: un comercio de 200 ventas/día son ~6.000 mensajes/mes, y `context/09`
dice que Punto **absorbe** todas las APIs externas salvo tokens de IA. A ese
volumen la absorción no cierra.

**El patrón customer-initiated (`wa.me/<numero>?text=Mi factura AB3K9Q` impreso
como QR en el ticket) se evaluó y se DESCARTÓ por D1**, no por costo. Resolvía
bien las tres cosas difíciles —costo cero por ventana de servicio de 24 h,
opt-in explícito, sin templates que mantener— pero invierte quién es
responsable de la entrega, y esa responsabilidad es del emisor. Puede volver
como **comodidad adicional** sobre un canal de push que ya cumpla, nunca como el
canal principal.

**Lo que falta para reabrirlo**, en orden:

1. Confirmar con Kapso cómo factura exactamente la ventana de servicio y el
   template Utility en Paraguay. Meta cambió el modelo de precios varias veces;
   sin ese número no se puede decidir.
2. Decidir el remitente: número único de Punto (un template, cero fricción, pero
   el cliente recibe de alguien que no es su comercio) vs. número por comercio
   (marca correcta, pero verificación de Meta Business Manager por tenant). **El
   diseño de D4 ya lo deja abierto**: el adapter resuelve el remitente por
   tenant, así que esto es config, no reescritura.
3. Decidir si el costo se absorbe o se metera. Si se metera, el patrón ya existe:
   `ai_credit_ledger` (`context/09`, mig 30).

## Preguntas abiertas

- **[O1]** ¿El cuerpo del email lo edita el comercio (plantilla por tenant) o es
  fijo de Punto? Hoy el plan asume fijo. Editable implica editor de plantillas de
  email, que es un módulo propio.
- **[F1]** `getkude` sobre un documento recién aprobado: ¿cuánto tarda en estar
  disponible el PDF? Define el backoff de E2. Sin medirlo, el primer intento
  probablemente falle siempre y se pague un reintento de más en cada venta.
  **Contexto de mercado que relaja esto (owner, 2026-09-06):** en Paraguay es
  normal recibir la factura uno o dos días después de emitida, así que el
  backoff puede ser PACIENTE — estirarlo sale gratis en experiencia y es más
  barato y confiable que reintentar rápido. Ver `context/28` §R5b.
- **[C1]** ¿El contador quiere el XML además del PDF? El KuDE es la
  representación gráfica; algunos contadores piden el XML firmado. No está en el
  alcance de E1.

## Relacionados

- `context/49-kude-y-portal-cliente.md` — de dónde sale P3, y por qué el ticket
  + portal ya cumplen la norma. **Leer antes que este doc.**
- `context/28-facturacion-electronica-plan.md` — `sifen_status`, `getkude`,
  reconciliación por `getBulk`, el hallazgo del CDC que no garantiza aceptación.
- `context/56-cotizacion-pdf.md` — el segundo consumidor del outbox de D4.
- `context/09-costos-y-creditos.md` — qué absorbe Punto y qué se factura.
