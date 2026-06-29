<!-- ARCHIVE — secciones de 02-arquitectura.md superseded por el rewrite frontend.
     NO es contexto vivo. Solo referencia histórica. -->

# Archive — Arquitectura legacy (referencia histórica)

Secciones de `02-arquitectura.md` que perdieron vigencia después del pivote frontend
(2026-06-10). El "molde por módulo" del panel legacy ya no se sigue — el frontend
es greenfield. Estas notas existen como referencia de cómo se pensaba el desacople
incremental del monolito antes de la decisión del rewrite.

---

## Estrategia de modernización del monolito (decisión 2026-05-24)

El panel son **48 módulos / ~45K líneas** + `app/action.php` (POS, 3.6K).
Modernizar todo de punta a punta (como se hizo con Items) tomaría meses.
Decisión estratégica para salir del monolito **rápido**:

**1. Backend primero, en TODOS los módulos.** El desacople de mayor valor
es sacar SQL + lógica de negocio del HTML hacia `lib/<x>/{Repository,Service}`
+ `API/v1/<x>.php`. Eso solo ya saca el módulo del monolito y deja base
para cualquier frontend. Es **mecánico y replicable** (ver el molde abajo).

**2. Frontend = `.html` estático (HTML + JS), CERO PHP.** ⚠️ *Superseded 2026-05-26:*
el supuesto previo era "vista PHP pura por defecto". **Ya no.** PHP nunca sirve HTML
(ver REGLA RAÍZ arriba). El front es un `.html` estático que el JS hidrata con datos
del BFF; auth y chrome también client-side. El backend-first sigue siendo válido como
*orden* (primero API+BFF, luego el front estático), pero el destino del front NO es PHP.

**3. Frontend reactivo (Alpine.js) solo donde la UX lo amerita.** Para los
CRUD/POS donde la interactividad importa, usar **Alpine.js** (no Mustache):
reactividad declarativa en el HTML (`x-data`/`x-model`/`x-for`/`x-if`), sin
build, convive con jQuery/BS3, ~15KB. Elimina el view-model manual que hace
Mustache verboso y bug-prone. **Items queda en Mustache** (ya funciona, no
se reescribe); lo nuevo va en Alpine. (Aplica dentro del `.html` estático — Alpine
es JS puro, no rompe la regla de cero-PHP.)

**Priorización por tipo de módulo:**

| Tipo | Módulos | Acción | Esfuerzo |
|------|---------|--------|----------|
| Reportes (read-only) | 5 (~13K líneas) | backend→API + listado data-driven; sin tocar forms | bajo |
| CRUD pesado | items✓, contacts✓ (backend + listado data-driven 3 roles + editform v2 customer — 2026-05-25), purchase | backend Services+API; frontend Alpine si UX lo pide | medio |
| Config/raros | settings, modules, … | dejar legacy; solo backend si se tocan | diferido |

> **Contacts — pendientes post-v2 (2026-05-25)**: (a) editform v2 para roles user/supplier (hoy usan form legacy); (b) custom records (solo en `a_contacts.php` legacy); (c) CSV export (lee columnas ya en JSONB); (d) listado customer muestra note/address/city vacíos (loop generalTable no aplana JSONB — pre-existente).

### El molde por módulo (alineado al BFF de 3 niveles)

> Reemplaza al molde viejo (front → API directo). El cableado canónico es el de
> la sección **"Arquitectura objetivo: BFF de 3 niveles"** arriba.

```
DOMINIO  lib/<modulo>/{<Modulo>Repository.php, <Modulo>Service.php}   SQL + reglas (vive con la API)
API      API/v1/<area>/<modulo>.php   REST raw (apiMiddleware + apiOk/apiError) — reusable por apps externas
BFF      bff/<area>/<modulo>.php?action=…   consume la API (cliente HTTP) + procesa para la app — JSON, NO HTML
FRONT    reports/<modulo>.html (estático) + scripts/<modulo>.js   pinta lo del BFF; habla SOLO con el BFF
```

Reglas: el **front es `.html` estático** (cero PHP) y **nunca pega a `/API/v1`** (pega al BFF).
El **BFF nunca toca BD/`lib/` ni sirve HTML** (pide a la API, devuelve JSON). La **API no formatea
para Punto** (devuelve raw; el BFF compone/cruza/formatea). **PHP nunca sirve HTML.**

Muchos módulos ya tienen endpoints sueltos en `API/*.php` (73 en total,
ej. `get_customers.php`, `edit_customer.php`) — se consolidan bajo el
Service + `API/v1/` canónico (raw).
