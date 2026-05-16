<!-- REGLA: Actualizar cuando cambie el modelo de créditos IA, se agregue/elimine una API
     con costo, o cambie quién paga (empresa vs cliente). -->

# 09 — Costos y Créditos

## Modelo general

La empresa absorbe el costo de todas las APIs externas **excepto** los tokens de IA,
que se facturan al cliente mediante un sistema de créditos.

## APIs absorbidas por la empresa

| API | Propósito | Modelo de costo |
|-----|-----------|----------------|
| EFATech / TaxPro | Facturación electrónica | Por documento emitido |
| Bancard | Pagos tarjeta + QR | Comisión por transacción |
| Twilio | SMS | Por mensaje |
| Infobip | SMS / RCS | Por mensaje |
| Resend / Mailgun | Email transaccional | Por email (free tier + overage) |
| DigitalOcean Spaces | File storage | Por GB almacenado |
| PDF API | Generación de PDFs | Por request |

**Nota**: Estos costos se absorben como parte del plan del cliente. No hay pass-through.

## Créditos de IA (facturados al cliente)

### Modelo

```
1 crédito = 1,000 tokens internos (input + output combinados)
```

### Mecánica

- Cada plan incluye N créditos mensuales (TO-DO: definir por plan)
- Los créditos no usados NO se acumulan (TO-DO: confirmar)
- El agente IA (Phase AI) consume tokens al procesar cada request
- El sistema trackea el uso por tenant

### Implementación (TO-DO)

- Tabla `credit_usage` o campo en `company.config` para tracking
- Middleware en el agente IA que verifica créditos antes de procesar
- Endpoint para consultar balance de créditos
- Lógica de corte cuando se agotan (¿degradar? ¿bloquear? ¿notificar?)

### Costos Anthropic (referencia)

| Modelo | Input (por 1M tokens) | Output (por 1M tokens) |
|--------|----------------------|----------------------|
| Claude Sonnet 4 | $3 | $15 |
| Claude Haiku 3.5 | $0.80 | $4 |

**Cálculo ejemplo**: Si 1 crédito = 1,000 tokens y se usa Haiku:
- 1 crédito ≈ $0.0008 input + $0.004 output ≈ $0.005 total max
- Margen para pricing al cliente: TBD

---

## Consideraciones de control de costos

1. **Rate limiting** — ya existe en /app (80 req/min por register). Extender al agente IA.
2. **Modelo por defecto** — usar Haiku para operaciones simples, Sonnet para complejas.
3. **Caching de respuestas** — para queries repetitivas (ej: "dame el cierre de hoy").
4. **Alertas de consumo** — notificar al tenant cuando alcance 80% de créditos.
