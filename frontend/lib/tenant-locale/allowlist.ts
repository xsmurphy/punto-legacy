/**
 * Excepciones del guard de literales paraguayos.
 *
 * Lo hace cumplir `lib/tenant-locale/__tests__/no-hardcoded-paraguay.test.ts`.
 * Leé el docblock de ese archivo para el porqué del guard.
 *
 * REGLA PARA AGREGAR UNA ENTRADA: el valor es el MOTIVO, escrito para alguien
 * que no estuvo en esta sesión. "es necesario" o "no se puede cambiar" no son
 * motivos. Un motivo válido explica por qué el literal NO es una asunción
 * sobre dónde está el tenant. Hay exactamente cinco formas de que eso sea
 * cierto:
 *
 *   1. CATÁLOGO — el archivo enumera países/monedas/TZ. Paraguay es una FILA,
 *      no un default; sacarla rompería el soporte de Paraguay.
 *   2. FIXTURE / SEED / TEST — datos falsos de un tenant paraguayo coherente,
 *      que nunca llegan a un tenant real.
 *   3. FEATURE PY-ONLY GATEADA — el código solo corre si el país del tenant
 *      ES Paraguay (SIFEN, RG90, padrón de la SET). El gate tiene que existir
 *      y estar señalado en el motivo.
 *   4. PUNTO S.A. — los libros del emisor (facturación del SaaS en `/admin`)
 *      o su marketing (`app/(site)`). Punto es una empresa paraguaya; acá el
 *      guaraní es un hecho sobre Punto, no sobre el tenant.
 *   5. ÚLTIMO RECURSO DE UI — un control que no puede existir sin un país
 *      seleccionado y se monta sin tenant conocido (login/signup).
 *
 * Si tu caso no entra en ninguna de las cinco, no es una excepción: es un bug,
 * y se arregla con los resolvers de `lib/tenant-locale.ts`.
 *
 * El prefijo "PENDIENTE" en un motivo marca deuda conocida — un literal que SÍ
 * es un bug pero cuyo fix excede este trabajo. El guard lo tolera pero el test
 * de allowlist podrida no lo da por saldado.
 *
 * Las rutas son relativas a la raíz del repo.
 */
export const PARAGUAY_LITERAL_ALLOWLIST: Record<string, string> = {
  // ── 1. Catálogos ───────────────────────────────────────────────────────────
  "frontend/lib/countries.ts":
    "CATÁLOGO — países soportados por el selector de teléfono (nombre, prefijo, bandera). " +
    "Paraguay es una fila más. `DEFAULT_COUNTRY` es el caso 5 (último recurso de UI): un " +
    "`<PhoneInput>` no puede existir sin país seleccionado, y login/signup se montan sin " +
    "tenant. Quien tenga bootstrap usa `useTenantPhoneCountry()`, no esta constante.",
  "frontend/lib/format-money.ts":
    "CATÁLOGO — `NO_DECIMAL_CURRENCIES` lista los códigos ISO 4217 que no usan decimales " +
    "(PYG, CLP, JPY, KRW, VND, IDR). Es una propiedad de esas monedas, no del tenant: " +
    "`formatCurrencyAmount` la consulta para formatear una divisa EXPLÍCITA del documento.",
  "frontend/lib/tenant-locale.ts":
    "CATÁLOGO — `COUNTRY_LOCALE` es la tabla país → moneda/TZ/impuesto/TIN/separadores. " +
    "Es la fuente que permite NO asumir Paraguay: la fila PY existe para que un tenant " +
    "paraguayo siga funcionando igual que el resto.",
  "api/libraries/countries.php":
    "CATÁLOGO — tabla de los ~240 países con moneda, símbolo, prefijo y TZ.",
  "api/lib/Settings/resources/countries_hispanic.json":
    "CATÁLOGO — subset hispanohablante con moneda/TZ/prefijo, usado por el alta de tenant " +
    "para derivar los defaults DEL PAÍS ELEGIDO.",

  // ── 2. Fixtures, seeds y tests ─────────────────────────────────────────────
  "frontend/lib/catalog/fixtures.ts":
    "FIXTURE — dev seed del catálogo del POS: un tenant paraguayo ficticio y coherente " +
    "(country PY + currency Gs + TZ Asunción juntos). Solo hidrata el store en desarrollo; " +
    "en producción el store viene de `/api/pos/bootstrap`.",
  "frontend/lib/__tests__/country-flag.test.ts":
    "TEST — verifica el catálogo de banderas; PY/PYG son los casos de prueba.",
  "frontend/lib/hardware/printers/__tests__/roll-ticket-layout.test.ts":
    "FIXTURE — ticket de ejemplo de un comercio paraguayo. El `country: 'PY'` es " +
    "deliberado y explícito: el test verifica que la etiqueta de moneda salga del país " +
    "del tenant. Antes el fixture no lo declaraba y el ticket salía en Gs por el default " +
    "escondido, o sea el test tapaba el bug en vez de detectarlo.",
  "frontend/lib/hardware/printers/__tests__/escpos-bytes.test.ts":
    "FIXTURE — mismo caso, más el detalle de que estos tests miden alineación de columnas " +
    "y el ancho de la etiqueta de moneda cambia el padding. 'Gs' también aparece como la " +
    "secuencia de bytes ESC/POS 0x47,0x73 (comando GS), que no tiene nada que ver con la " +
    "moneda.",
  "frontend/lib/__tests__/format-money-currency-label.test.ts":
    "TEST — cubre la cadena de fallbacks de `resolveCurrencyLabel`, incluido el caso " +
    "'tenant paraguayo sin moneda configurada → Gs por su país'.",
  "frontend/lib/tenant-locale/__tests__/no-hardcoded-paraguay.test.ts":
    "GUARD — este mismo test. Los literales son los PATRONES que busca.",
  "frontend/lib/tenant-locale/allowlist.ts":
    "GUARD — esta misma allowlist. Los literales están dentro de los motivos escritos.",
  "api/database/seeds/01_base.sql": "SEED — datos base de desarrollo.",
  "api/database/seeds/postgres/02_sample_company.sql":
    "SEED — empresa de ejemplo paraguaya para desarrollo.",
  "api/lib/Sales/verify_chain/seed.sql": "SEED — fixture del harness de verificación de ventas.",
  "api/lib/Sales/verify_chain/fixtures.json":
    "FIXTURE — datos esperados del harness de verificación de ventas.",
  "api/tests/cost_center_test.php": "TEST — fixture de tenant paraguayo.",
  "api/tests/psp_payment_methods_test.php": "TEST — fixture de tenant paraguayo.",
  "api/tests/drawer_cash_count_test.php": "TEST — fixture de tenant paraguayo.",
  "api/tests/drawer_count_by_method_test.php": "TEST — fixture de tenant paraguayo.",

  // ── 3. Features PY-only, gateadas por país ─────────────────────────────────
  "api/lib/EInvoice/SaleToInvoiceMapper.php":
    "FEATURE PY-ONLY GATEADA — SIFEN es el protocolo de facturación electrónica " +
    "paraguayo; 'PYG', 'Paraguay' y el código de país 107 son campos del XML, no " +
    "defaults del tenant. El guard de moneda aborta explícitamente si la venta no es " +
    "en PYG en vez de convertirla.",
  "frontend/app/api/ocr-invoice/route.ts":
    "PENDIENTE — el prompt de OCR está escrito para facturas paraguayas (timbrado, RUC " +
    "con DV, IVA 0/5/10) pero NO está gateado por país. El default 'PYG' de la moneda ya " +
    "se sacó; falta gatear el módulo por `settingCountry` o escribir variantes por país " +
    "antes de habilitarlo fuera de Paraguay.",

  // ── 4. Punto S.A. — emisor, no tenant ──────────────────────────────────────
  "frontend/lib/punto-saas-locale.ts":
    "PUNTO S.A. — constantes del locale del EMISOR (Punto factura su SaaS en guaraníes). " +
    "El propio archivo prohíbe usarlas en superficies de tenant: (panel), (pos) y (screen) " +
    "van por `lib/tenant-locale.ts`.",
  "frontend/lib/site/markets.ts":
    "PUNTO S.A. — configuración del mercado del sitio comercial. Hoy Punto le habla solo " +
    "al mercado paraguayo; el archivo ya está estructurado por `MarketCode` para cuando " +
    "se sume otro.",
  "frontend/app/(site)/precios/page.tsx":
    "PUNTO S.A. — landing de precios del SaaS, una empresa paraguaya hablándole a su " +
    "mercado. No es la moneda de ningún tenant.",
  "frontend/lib/site/modulos.ts":
    "PUNTO S.A. — catálogo de módulos del sitio comercial; `mercados: ['PY']` declara en " +
    "qué mercado se ofrece cada módulo, no dónde está un tenant.",

  // ── 5. Textos de ayuda y opciones de lista con Paraguay como ejemplo ───────
  "frontend/app/(panel)/settings/page.tsx":
    "CATÁLOGO + AYUDA — la lista de zonas horarias incluye Paraguay como una opción entre " +
    "todas las demás, y los textos de ayuda de los campos moneda/decimales lo usan como " +
    "ejemplo ('para Paraguay: ₲ o Gs'). Los DEFAULTS del formulario ya no son paraguayos: " +
    "`emptyValues()` deja moneda/TZ/impuesto/TIN vacíos y los completa el país elegido " +
    "desde `COUNTRY_LOCALE`.",
  "frontend/app/api/agent/chat/route.ts":
    "CONDICIONAL, NO DEFAULT — la instrucción al modelo dice 'SI la moneda es Gs/PYG, no " +
    "uses decimales'. La moneda real se inyecta desde la config del tenant en la misma " +
    "línea; Paraguay es un caso del condicional, no la asunción.",

  // ── Deuda conocida ─────────────────────────────────────────────────────────
  "api/database/migrations/postgres/157_period_close.sql":
    "PENDIENTE — `period_is_closed()` y `fn_period_guard()` truncan el mes con " +
    "`AT TIME ZONE 'America/Asuncion'`. Es una migración YA APLICADA: editarla no cambia " +
    "la base. Corregirlo requiere una migración NUEVA que redefina esas funciones para " +
    "leer la TZ del tenant. Riesgo alto (toca el cierre de período): pendiente de " +
    "aprobación del owner.",
  "api/database/migrations/postgres/160_rollup_daily_grain.sql":
    "PENDIENTE — mismo caso que la 157: el guard del rollup diario trunca en TZ de " +
    "Asunción dentro de una migración ya aplicada. Requiere migración nueva.",
  "api/v1/period-close.php":
    "PENDIENTE — el lado PHP del cierre de período trunca el mes con la misma TZ literal " +
    "que las funciones SQL de la mig 157. Cambiarlo solo acá desalinearía PHP y base " +
    "(el guard rechazaría escrituras que el endpoint considera abiertas): se corrige " +
    "junto con la migración nueva, no antes.",
  "api/database/migrations/postgres/139_satelite_touch_parent.sql":
    "PENDIENTE — `fn_tenant_wall_clock()` cae a 'America/Asuncion' cuando no resuelve la " +
    "TZ del tenant. Migración ya aplicada; requiere migración nueva para redefinirla.",
}
