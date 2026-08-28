/**
 * Excepciones del guard de literales paraguayos.
 *
 * Lo hace cumplir `lib/tenant-locale/__tests__/no-hardcoded-paraguay.test.ts`.
 * Leé el docblock de ese archivo para el porqué del guard.
 *
 * La excepción es por (ARCHIVO, PATRÓN, CANTIDAD), no por archivo. Antes era
 * por archivo y tenía un agujero: exceptuar `format-money.ts` porque contiene
 * una lista de códigos ISO dejaba entrar invisible cualquier "Gs" nuevo en ese
 * mismo archivo. Ahora hay que declarar QUÉ patrón se excusa y CUÁNTAS veces
 * aparece; un literal de más rompe el test aunque el archivo ya esté acá.
 *
 * REGLA PARA AGREGAR UNA ENTRADA: `reason` es el motivo, escrito para alguien
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
 * El prefijo "PENDIENTE" en `reason` marca deuda conocida — un literal que SÍ
 * es un bug pero cuyo fix excede este trabajo.
 *
 * Las rutas son relativas a la raíz del repo.
 */
export interface AllowlistEntry {
  /** Por qué este literal NO es una asunción sobre dónde está el tenant. */
  reason: string
  /** Patrón → cantidad EXACTA de líneas que lo contienen hoy. */
  allow: Record<string, number>
}

export const PARAGUAY_LITERAL_ALLOWLIST: Record<string, AllowlistEntry> = {
  // ── 1. Catálogos ───────────────────────────────────────────────────────────
  "frontend/lib/countries.ts": {
    reason:
      "CATÁLOGO + ÚLTIMO RECURSO DE UI — `DEFAULT_COUNTRY` es el país con el que arranca el " +
      "selector de teléfono cuando no hay tenant conocido. Un <PhoneInput> no puede existir " +
      "sin país seleccionado (libphonenumber no parsea un número nacional sin saber de dónde " +
      "es), así que acá no existe la opción 'ninguno' que usan los demás resolvers. Solo lo " +
      "consumen login y signup, que se montan sin bootstrap; quien lo tenga usa " +
      "`useTenantPhoneCountry()`.",
    allow: { 'país "PY" como default': 1 },
  },
  "frontend/lib/format-money.ts": {
    reason:
      "CATÁLOGO — `NO_DECIMAL_CURRENCIES` lista los códigos ISO 4217 que no usan decimales " +
      "(PYG, CLP, JPY, KRW, VND, IDR). Es una propiedad de esas monedas, no del tenant: " +
      "`formatCurrencyAmount` la consulta para formatear una divisa EXPLÍCITA del documento.",
    allow: { 'código "PYG"': 1 },
  },
  "frontend/lib/tenant-locale.ts": {
    reason:
      "CATÁLOGO — `COUNTRY_LOCALE` es la tabla país → moneda/TZ/impuesto/TIN/separadores. Es " +
      "la fuente que permite NO asumir Paraguay: la fila PY existe para que un tenant " +
      "paraguayo siga funcionando igual que el resto.",
    allow: { 'símbolo "Gs"': 1, 'TZ "America/Asuncion"': 1 },
  },
  "api/libraries/countries.php": {
    reason:
      "CATÁLOGO — tabla de los ~273 países con moneda, símbolo, prefijo telefónico y código " +
      "ISO. Paraguay es una fila entre todas.",
    allow: {
      'símbolo "Gs"': 2,
      'código "PYG"': 2,
      'país "PY" como default': 2,
      "prefijo telefónico 595": 2,
    },
  },
  "api/lib/Settings/resources/countries_hispanic.json": {
    reason:
      "CATÁLOGO — subset hispanohablante curado (23 países) con moneda, TZ elegida a mano y " +
      "prefijo. Es de donde `CountryDefaults` deriva los defaults DEL PAÍS ELEGIDO por el " +
      "comercio, o sea justamente lo que reemplaza al default paraguayo.",
    allow: {
      'símbolo "Gs"': 1,
      'símbolo "₲"': 1,
      'código "PYG"': 1,
      'país "PY" como default': 1,
      'TZ "America/Asuncion"': 1,
      "prefijo telefónico 595": 1,
    },
  },
  "api/lib/Support/CountryDefaults.php": {
    reason:
      "CATÁLOGO — el mapa de padrones públicos de contribuyentes por país. La fila PY es la " +
      "URL del padrón paraguayo, que es un servicio DE Paraguay; sumar otro país es agregar " +
      "una fila. Es lo que permitió sacar la URL cableada global sin romper a los tenants " +
      "paraguayos.",
    allow: { 'país "PY" como default': 1 },
  },

  // ── 2. Fixtures, seeds y tests ─────────────────────────────────────────────
  "frontend/lib/catalog/fixtures.ts": {
    reason:
      "FIXTURE — dev seed del catálogo del POS: un tenant paraguayo ficticio y coherente " +
      "(country PY + moneda + TZ juntos). Solo hidrata el store en desarrollo; en producción " +
      "el store viene de `/api/pos/bootstrap`.",
    allow: { 'símbolo "Gs"': 1, 'país "PY" como default': 1, 'TZ "America/Asuncion"': 1 },
  },
  "frontend/lib/__tests__/country-flag.test.ts": {
    reason: "TEST — verifica el catálogo de banderas; PY y PYG son los casos de prueba.",
    allow: { 'código "PYG"': 1, 'país "PY" como default': 2 },
  },
  "frontend/lib/__tests__/format-money-currency-label.test.ts": {
    reason:
      "TEST — cubre la cadena de fallbacks de `resolveCurrencyLabel`. Los literales son las " +
      "ASERCIONES: que un tenant paraguayo sin moneda configurada vea guaraníes por su PAÍS, " +
      "y que uno brasileño vea R$ en el mismo caso.",
    allow: { 'símbolo "Gs"': 6, 'país "PY" como default': 5 },
  },
  "frontend/lib/hardware/printers/__tests__/roll-ticket-layout.test.ts": {
    reason:
      "FIXTURE — ticket de ejemplo de un comercio paraguayo. El `country: 'PY'` es deliberado " +
      "y explícito: el test verifica que la etiqueta de moneda salga del país del tenant. " +
      "Antes el fixture no lo declaraba y el ticket salía en guaraníes por el default " +
      "escondido, o sea el test tapaba el bug en vez de detectarlo.",
    // 4 → 2: desde 2026-08-26 los importes de ÍTEM salen sin moneda (el
    // símbolo va solo en el total), así que el fixture tiene dos "Gs" menos.
    allow: { 'símbolo "Gs"': 2, 'país "PY" como default': 1 },
  },
  "frontend/lib/hardware/printers/__tests__/escpos-bytes.test.ts": {
    reason:
      "FIXTURE — mismo caso, más el detalle de que estos tests miden alineación de columnas y " +
      "el ancho de la etiqueta de moneda cambia el padding. 'Gs' también aparece como la " +
      "secuencia de bytes ESC/POS 0x47,0x73 (comando GS), que no tiene nada que ver con la " +
      "moneda.",
    allow: { 'símbolo "Gs"': 8, 'país "PY" como default': 1 },
  },
  "api/database/seeds/01_base.sql": {
    reason: "SEED — datos base de desarrollo: un tenant paraguayo coherente.",
    allow: { 'código "PYG"': 2, 'país "PY" como default': 2, 'TZ "America/Asuncion"': 2 },
  },
  "api/database/seeds/postgres/02_sample_company.sql": {
    reason: "SEED — empresa de ejemplo paraguaya para desarrollo.",
    allow: { 'código "PYG"': 1, 'país "PY" como default': 1, 'TZ "America/Asuncion"': 1 },
  },
  "api/lib/Sales/verify_chain/seed.sql": {
    reason: "SEED — fixture del harness de verificación de cadena de ventas.",
    allow: { 'código "PYG"': 1, 'país "PY" como default': 1, 'TZ "America/Asuncion"': 1 },
  },
  "api/lib/Sales/verify_chain/fixtures.json": {
    reason: "FIXTURE — montos esperados del harness de verificación de cadena de ventas.",
    allow: { 'símbolo "Gs"': 3 },
  },
  "api/tests/cost_center_test.php": {
    reason: "TEST — fixture de tenant paraguayo para el test de centros de costo.",
    allow: { 'código "PYG"': 1, 'país "PY" como default': 1, 'TZ "America/Asuncion"': 1 },
  },
  "api/tests/psp_payment_methods_test.php": {
    reason: "TEST — fixture de tenant paraguayo para el test de métodos de pago por PSP.",
    allow: { 'código "PYG"': 1, 'país "PY" como default': 1, 'TZ "America/Asuncion"': 1 },
  },
  "api/tests/drawer_cash_count_test.php": {
    reason: "TEST — fixture de tenant paraguayo para el test de arqueo de caja.",
    allow: {
      'símbolo "Gs"': 2,
      'código "PYG"': 1,
      'país "PY" como default': 1,
      'TZ "America/Asuncion"': 1,
    },
  },
  "api/tests/drawer_count_by_method_test.php": {
    reason: "TEST — fixture de tenant paraguayo para el conteo por método de pago.",
    allow: { 'código "PYG"': 1, 'país "PY" como default': 1, 'TZ "America/Asuncion"': 1 },
  },

  // ── 3. Features PY-only, gateadas por país ─────────────────────────────────
  "api/lib/EInvoice/SaleToInvoiceMapper.php": {
    reason:
      "FEATURE PY-ONLY GATEADA — SIFEN es el protocolo de facturación electrónica paraguayo; " +
      "'PYG' y el umbral de 1.000.000 para exigir identificación del receptor son requisitos " +
      "del XML y de la ley paraguaya, no defaults del tenant. El guard de moneda aborta " +
      "explícitamente si la venta no es en guaraníes, en vez de convertirla.",
    allow: { 'símbolo "Gs"': 1, 'código "PYG"': 2 },
  },
  "frontend/lib/ai/extract-invoice.ts": {
    reason:
      "PENDIENTE — el prompt de OCR está escrito para facturas paraguayas (timbrado, RUC con " +
      "dígito verificador, tasas de IVA 0/5/10) pero NO está gateado por país. El default de " +
      "moneda ya se sacó (queda null si no se detecta); falta gatear el módulo por " +
      "`settingCountry` o escribir variantes del prompt por país antes de habilitarlo fuera " +
      "de Paraguay. (El prompt vivía en `app/api/ocr-invoice/route.ts`; se movió acá al " +
      "aparecer el segundo consumidor —el drain de la cola— para que los dos usen el mismo " +
      "texto. Los literales son los mismos, solo cambiaron de archivo.)",
    allow: { 'código "PYG"': 2 },
  },

  // ── 4. Punto S.A. — emisor, no tenant ──────────────────────────────────────
  "frontend/lib/punto-saas-locale.ts": {
    reason:
      "PUNTO S.A. — constantes del locale del EMISOR (Punto factura su SaaS en guaraníes). El " +
      "propio archivo prohíbe usarlas en superficies de tenant: (panel), (pos) y (screen) van " +
      "por `lib/tenant-locale.ts`.",
    allow: { 'código "PYG"': 1, 'locale "es-PY"': 1 },
  },
  "frontend/lib/site/markets.ts": {
    reason:
      "PUNTO S.A. — configuración del mercado del sitio comercial. Hoy Punto le habla solo al " +
      "mercado paraguayo; el archivo ya está estructurado por `MarketCode` para cuando se " +
      "sume otro.",
    allow: {
      'símbolo "Gs"': 1,
      'código "PYG"': 1,
      'locale "es-PY"': 2,
      'país "PY" como default': 3,
    },
  },
  "frontend/lib/site/modulos.ts": {
    reason:
      "PUNTO S.A. — catálogo de módulos del sitio comercial; `mercados: ['PY']` declara en qué " +
      "mercado se ofrece cada módulo, no dónde está un tenant.",
    allow: { 'país "PY" como default': 1 },
  },
  "frontend/app/(site)/precios/page.tsx": {
    reason:
      "PUNTO S.A. — landing de precios del SaaS, una empresa paraguaya hablándole a su " +
      "mercado. No es la moneda de ningún tenant.",
    allow: { 'locale "es-PY"': 1 },
  },

  // ── 5. Ayuda y condicionales con Paraguay como ejemplo ─────────────────────
  "frontend/app/(panel)/settings/page.tsx": {
    reason:
      "CATÁLOGO + AYUDA — la lista de zonas horarias incluye Paraguay como una opción entre " +
      "todas las demás, y los textos de ayuda de moneda/decimales lo usan como ejemplo. Los " +
      "DEFAULTS del formulario ya no son paraguayos: `emptyValues()` deja moneda/TZ/impuesto/" +
      "TIN vacíos y los completa el país que el usuario elija desde `COUNTRY_LOCALE`.",
    allow: { 'símbolo "Gs"': 1, 'símbolo "₲"': 2, 'código "PYG"': 1, 'TZ "America/Asuncion"': 1 },
  },
  "frontend/app/api/agent/chat/route.ts": {
    reason:
      "CONDICIONAL, NO DEFAULT — la instrucción al modelo dice 'SI la moneda es Gs/PYG, no " +
      "uses decimales'. La moneda real se inyecta desde la config del tenant en la misma " +
      "línea; Paraguay es un caso del condicional, no la asunción.",
    allow: { 'símbolo "Gs"': 1, 'código "PYG"': 1 },
  },

  // ── Deuda conocida: desalineación PHP/SQL del cierre de período ────────────
  "api/database/migrations/postgres/157_period_close.sql": {
    reason:
      "PENDIENTE — `period_is_closed()` y `fn_period_guard()` truncan el mes con TZ de " +
      "Asunción. Es una migración YA APLICADA: editarla no cambia la base. Corregirlo " +
      "requiere una migración NUEVA que redefina esas funciones para leer la TZ del tenant. " +
      "TIENE QUE ENTRAR ANTES DE DAR DE ALTA EL PRIMER TENANT NO PARAGUAYO (ver context/48).",
    allow: { 'TZ "America/Asuncion"': 9 },
  },
  "api/database/migrations/postgres/160_rollup_daily_grain.sql": {
    reason:
      "PENDIENTE — mismo caso que la 157: el grano diario del rollup trunca en TZ de Asunción " +
      "dentro de una migración ya aplicada. Requiere la misma migración nueva. TIENE QUE " +
      "ENTRAR ANTES DE DAR DE ALTA EL PRIMER TENANT NO PARAGUAYO (ver context/48).",
    allow: { 'TZ "America/Asuncion"': 3 },
  },
  "api/database/migrations/postgres/139_satelite_touch_parent.sql": {
    reason:
      "PENDIENTE — `fn_tenant_wall_clock()` cae a TZ de Asunción cuando no resuelve la del " +
      "tenant. Migración ya aplicada; requiere migración nueva para redefinirla. Ver " +
      "context/48.",
    allow: { 'TZ "America/Asuncion"': 2 },
  },
  "api/v1/period-close.php": {
    reason:
      "PENDIENTE — el lado PHP del cierre de período trunca el mes con la misma TZ literal que " +
      "las funciones SQL de la mig 157. Cambiarlo SOLO acá desalinearía PHP y base (el guard " +
      "rechazaría escrituras que el endpoint considera abiertas): se corrige junto con la " +
      "migración nueva, no antes. Ver context/48.",
    allow: { 'TZ "America/Asuncion"': 4 },
  },
}
