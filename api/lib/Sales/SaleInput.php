<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Support\TenantClock;

/**
 * Payload de venta normalizado. Construcción valida y fail-fast.
 *
 * Mirror del shape que arma `saveSale` en el front (app.js:11931+) — solo
 * los campos del path simple (35a). Los sub-slices subsiguientes irán
 * agregando campos (giftCard, electronicInvoice, recurringSaleData, etc.).
 *
 * El SaleService recibe SaleInput tipado; el endpoint hace `fromPayload()`
 * que valida y arroja InvalidSaleInputException si el shape no calza.
 */
final class SaleInput
{
    /**
     * Tope de add-ons por línea de venta (F3, context/41). Espeja
     * `AddonService::MAX_OPTIONS_PER_GROUP`: más que eso no puede venir de un
     * carrito legítimo y evita que un payload inflado haga trabajar de más al
     * validador.
     */
    private const MAX_SELECTIONS_PER_LINE = 50;

    /**
     * Piso de plausibilidad del `timestamp` de emisión: 2020-01-01 UTC.
     * No existe una venta de Punto anterior a eso, así que cualquier valor por
     * debajo (un 0, un campo que se perdió en el camino) es un payload roto, no
     * una venta vieja — y cae al `date` del payload. Ver `resolveDate()`.
     */
    private const MIN_ISSUE_TIMESTAMP = 1577836800;

    /**
     * Techo de plausibilidad, como adelanto máximo sobre el reloj del servidor:
     * 30 días.
     *
     * NO contradice "la fecha es la del dispositivo": el reloj del servidor se
     * usa acá para DESCARTAR un valor imposible, nunca para reemplazarlo. Una
     * caja adelantada unas horas —o un día entero de cola offline— sigue
     * guardando su hora de emisión tal cual.
     *
     * Lo que ataca es el error de escala: `Date.now()` en vez de
     * `Math.floor(Date.now()/1000)` manda milisegundos, pasa cualquier piso, y
     * `atInstant()` lo convierte sin chistar en un año 56639 escrito sobre un
     * campo fiscal. Sin techo, este camino sería PEOR que el anterior, que
     * nunca podía producir esa fecha.
     */
    private const MAX_ISSUE_SKEW_SECONDS = 2592000;

    /**
     * @param array<int,array<string,mixed>> $sale  Items vendidos (cada uno: itemId, count, price, total, tax, …)
     * @param array<int,array<string,mixed>> $payment  Métodos de pago aplicados
     * @param array<int,mixed>|null $tags  IDs de tags asociados a la venta
     */
    public function __construct(
        public readonly string $uid,
        public readonly SaleType $type,
        public readonly array $sale,
        public readonly float $subtotal,
        public readonly float $tax,
        public readonly float $discount,
        public readonly array $payment,
        public readonly string $date,
        public readonly int $timestamp,
        public readonly ?string $clientId = null,
        public readonly ?string $userId = null,
        public readonly ?string $addressId = null,
        public readonly ?string $note = null,
        public readonly ?string $ident = null,
        public readonly ?int $invoiceNo = null,
        public readonly ?string $dueDate = null,
        public readonly ?string $currency = null,
        public readonly ?int $status = null,
        public readonly bool $dontNotify = false,
        public readonly ?array $tags = null,
        // ── 35f: venta recurrente ───────────────────────────────────────────
        public readonly bool $repeat = false,
        /** Frecuencia: daily | weekly | fortnight | monthly | quarterly | yearly */
        public readonly ?string $repeatF = null,
        /** Número de repeticiones */
        public readonly ?int $repeatT = null,
        /**
         * Venta emitida SIN IVA (toggle del POS). Los importes del payload YA
         * vienen netos — esta bandera dice por qué son esos y no los de lista, y
         * es lo que tienen que mirar los reportes que derivan el IVA del `taxId`
         * del ítem. Ver mig 101. Antes el toggle moría en el browser: se cobraba
         * sin IVA pero se registraba con IVA.
         */
        public readonly bool $ivaRemoved = false,
        /**
         * Venta de consumo interno (botón "Interno" del POS), no una venta a
         * cliente. El carrito ya lo mandaba en el payload, pero el DTO no tenía
         * el campo y el flag se descartaba en silencio — la venta interna
         * quedaba registrada como una común y ningún reporte podía separarla.
         * Ver mig 118, que sigue el patrón de `ivaRemoved` (mig 101).
         */
        public readonly bool $interno = false,
        /**
         * Cotización (o venta guardada) de la que sale esta venta — el
         * `quoteParentId` que el carrito ya venía mandando como
         * `parentTransactionId` y que NADIE leía.
         *
         * La mig 115 backfilleó los `quote_to_sale` históricos desde la columna
         * `transactionParentId` y después la dropeó, pero el writer que la
         * reemplazara nunca se construyó (`SaleService.php`: "sub-slices futuros
         * lo agregarán"). Resultado: los vínculos viejos existen, los nuevos no,
         * y una cotización facturada hoy es indistinguible de una pendiente.
         *
         * NO se confunde con `parentId`, que `assertSimplePathEligible()` sigue
         * rechazando: aquél marca una venta con padre que exige el path legacy
         * completo; éste es solo la trazabilidad del documento de origen y no
         * cambia en nada cómo se procesa la venta.
         */
        public readonly ?string $quoteParentId = null,
    ) {
    }

    /**
     * Construye y valida SaleInput desde el array crudo del payload del front.
     * Lanza InvalidSaleInputException con mensaje claro si falta o está mal tipado.
     *
     * El front manda algunos campos como string ("5000.00") o como número crudo;
     * normalizamos a tipos PHP estrictos acá una sola vez.
     *
     * `$companyId` es obligatorio porque la fecha de emisión se resuelve en el
     * reloj DEL COMERCIO — ver `resolveDate()`.
     */
    public static function fromPayload(array $raw, string $companyId): self
    {
        // El payload del front envuelve la venta en `{ transaction: { ... }, uid }`.
        $payload = $raw['transaction'] ?? $raw;
        if (isset($raw['uid']) && !isset($payload['uid'])) {
            $payload['uid'] = $raw['uid'];
        }

        $uid = (string) ($payload['uid'] ?? '');
        if ($uid === '') {
            throw new InvalidSaleInputException('Falta uid en el payload');
        }

        if (!isset($payload['type'])) {
            throw new InvalidSaleInputException('Falta type en el payload');
        }
        // Guard: `(int) 'abc'` da 0 silenciosamente → terminaría tratando un bug del front
        // como una venta cashsale válida. Rechazar non-numeric explícitamente.
        if (!is_numeric($payload['type'])) {
            throw new InvalidSaleInputException('type debe ser numérico: ' . var_export($payload['type'], true));
        }
        $type = SaleType::tryFrom((int) $payload['type']);
        if ($type === null) {
            throw new InvalidSaleInputException("type inválido: {$payload['type']}");
        }
        if (!$type->isSimplePathEligible()) {
            throw new InvalidSaleInputException("SaleService::save solo cubre type ∈ {0,3} en este sub-slice; recibido: {$type->value}");
        }

        $sale = $payload['sale'] ?? [];
        if (!is_array($sale)) {
            throw new InvalidSaleInputException('sale debe ser array');
        }

        $payment = $payload['payment'] ?? [];
        if (!is_array($payment)) {
            throw new InvalidSaleInputException('payment debe ser array');
        }

        $date = self::resolveDate($payload, $companyId);

        // Defense-in-depth: SaleService solo cubre la VENTA SIMPLE. Rechazamos los
        // payloads que requieren paths aún no migrados, para que el caller (front en
        // 35a.7) los rute al legacy processData. El front ya hace este check antes de
        // llamar; esto es la segunda línea por si entra un payload no-simple.
        self::assertSimplePathEligible($payload, $sale);

        // F3 (context/41): shape de `selections` por línea. Solo forma —
        // la validación de NEGOCIO (que la opción exista, pertenezca al ítem,
        // respete min/max/maxQty y cuánto suma) es server-side contra la BD en
        // AddonService::validateSelections, llamado desde SaleService.
        self::assertSelectionsShape($sale);

        return new self(
            uid:        $uid,
            type:       $type,
            sale:       $sale,
            subtotal:   (float) ($payload['subtotal'] ?? 0),
            tax:        (float) ($payload['tax']      ?? 0),
            discount:   (float) ($payload['discount'] ?? 0),
            payment:    $payment,
            date:       $date,
            timestamp:  (int) ($payload['timestamp'] ?? 0),
            clientId:   !empty($payload['client'])    ? (string) $payload['client']    : null,
            userId:     !empty($payload['user'])      ? (string) $payload['user']      : null,
            addressId:  !empty($payload['addressId']) ? (string) $payload['addressId'] : null,
            note:       !empty($payload['note'])      ? (string) $payload['note']      : null,
            ident:      !empty($payload['ident'])     ? (string) $payload['ident']     : null,
            invoiceNo:  isset($payload['invoiceno']) && $payload['invoiceno'] !== '' ? (int) $payload['invoiceno'] : null,
            ivaRemoved: !empty($payload['ivaRemoved']),
            interno:    !empty($payload['interno']),
            dueDate:    !empty($payload['dueDate'])   ? (string) $payload['dueDate']   : null,
            currency:   !empty($payload['currency'])  ? (string) $payload['currency']  : null,
            status:     self::normalizeStatus($payload['status'] ?? null),
            dontNotify: !empty($payload['dontNotify']),
            tags:       self::normalizeTags($payload['tags'] ?? null),
            // ── 35f: recurrente ──────────────────────────────────────────────
            repeat:  !empty($payload['repeat']),
            repeatF: !empty($payload['repeatF']) ? (string) $payload['repeatF'] : null,
            repeatT: isset($payload['repeatT']) && is_numeric($payload['repeatT']) ? (int) $payload['repeatT'] : null,
            quoteParentId: self::normalizeUuid($payload['parentTransactionId'] ?? null),
        );
    }

    /**
     * Construye SaleInput para una cotización (type=9).
     * No requiere payment. No llama assertSimplePathEligible.
     */
    public static function fromQuotePayload(array $raw, string $companyId): self
    {
        $payload = $raw['transaction'] ?? $raw;
        if (isset($raw['uid']) && !isset($payload['uid'])) {
            $payload['uid'] = $raw['uid'];
        }

        $uid = (string) ($payload['uid'] ?? '');
        if ($uid === '') {
            throw new InvalidSaleInputException('Falta uid en el payload');
        }

        if (!isset($payload['type']) || !is_numeric($payload['type'])) {
            throw new InvalidSaleInputException('Falta type numérico en el payload');
        }
        $type = SaleType::tryFrom((int) $payload['type']);
        if ($type !== SaleType::Quote) {
            throw new InvalidSaleInputException('fromQuotePayload requiere type=9');
        }

        $sale = $payload['sale'] ?? [];
        if (!is_array($sale) || empty($sale)) {
            throw new InvalidSaleInputException('sale debe ser array no vacío');
        }

        $date = self::resolveDate($payload, $companyId);

        return new self(
            uid:        $uid,
            type:       $type,
            sale:       $sale,
            subtotal:   (float) ($payload['subtotal'] ?? 0),
            tax:        (float) ($payload['tax']      ?? 0),
            discount:   (float) ($payload['discount'] ?? 0),
            payment:    [],
            date:       $date,
            timestamp:  (int) ($payload['timestamp'] ?? 0),
            clientId:   !empty($payload['client'])    ? (string) $payload['client']    : null,
            userId:     !empty($payload['user'])       ? (string) $payload['user']      : null,
            note:       !empty($payload['note'])       ? (string) $payload['note']      : null,
            ident:      !empty($payload['ident'])      ? (string) $payload['ident']     : null,
            dueDate:    !empty($payload['dueDate'])    ? (string) $payload['dueDate']   : null,
            currency:   !empty($payload['currency'])   ? (string) $payload['currency']  : null,
            status:     self::normalizeStatus($payload['status'] ?? null),
            tags:       self::normalizeTags($payload['tags'] ?? null),
        );
    }

    /**
     * Fecha de EMISIÓN de la venta, naive en la zona del comercio.
     *
     * ── Por qué no alcanza con el `date` del payload ────────────────────────
     *
     * El POS manda las dos cosas: `date`, texto naive 'Y-m-d H:i:s' formateado
     * en el browser, y `timestamp`, el epoch en segundos. Sólo el segundo es un
     * INSTANTE: el primero es una lectura de reloj sin zona, y qué momento
     * representa depende de quién lo interprete. Guardándolo tal cual, el mismo
     * string terminaba en instantes distintos según la TZ que tuviera la sesión
     * de PostgreSQL — que hasta este cambio dependía del embudo de auth por el
     * que hubiera entrado la request (ver `Auth/apiAuthPosContext.php`).
     *
     * Derivarla del epoch la vuelve independiente de estado ambiental: el
     * instante es el mismo lo lea quien lo lea, y `atInstant()` lo baja al
     * reloj del comercio. De paso corrige las cotizaciones, cuyo `date` sale
     * con el offset del DISPOSITIVO (`create-quote.ts`) y no con el del tenant:
     * una tablet en otra zona registraba la cotización con hora ajena.
     *
     * ── Por qué el fallback a `date` NO es opcional ─────────────────────────
     *
     * Hay payloads encolados en el IndexedDB de tablets reales que se van a
     * drenar por `offline-sync` después del deploy. Si alguno viniera sin
     * `timestamp` utilizable, su `date` tiene que seguir funcionando — y con la
     * TZ de sesión ya arreglada, ese camino ahora interpreta bien.
     *
     * NO se pisa con la hora del servidor: la fecha de una venta es la de su
     * emisión. Una caja que estuvo un día sin red sincroniza al otro día y esas
     * ventas pertenecen al día anterior (`DrawerService::resolveDrawerIdForDate`
     * depende de eso: busca el turno que CONTIENE la fecha).
     *
     * @param array<string,mixed> $payload
     */
    private static function resolveDate(array $payload, string $companyId): string
    {
        $raw = $payload['timestamp'] ?? null;
        if (is_numeric($raw)) {
            $epoch = (int) $raw;
            // Un epoch fuera de la ventana plausible NO se corrige ni se
            // aproxima: se ignora y manda el `date` del payload — exactamente
            // lo que pasaba antes de este cambio. Así el camino nuevo nunca
            // puede escribir una fecha peor que la que se escribía sin él.
            if ($epoch >= self::MIN_ISSUE_TIMESTAMP
                && $epoch <= time() + self::MAX_ISSUE_SKEW_SECONDS
            ) {
                return TenantClock::atInstant($companyId, $epoch);
            }
        }

        $date = trim((string) ($payload['date'] ?? ''));
        if ($date === '') {
            throw new InvalidSaleInputException('Falta date en el payload');
        }
        return $date;
    }

    /**
     * Rechaza payloads que NO son venta simple (cashsale/creditsale puro).
     *
     * Paths diferidos a sub-slices futuros — si alguno aparece, lanzamos
     * InvalidSaleInputException (422) para que el caller use el legacy processData:
     *   - repeat               → venta recurrente (35f)
     *   - parentId             → venta con padre (no path simple)
     *   - item.giftcardId      → gift card (35c)
     *   - item.duration > 0    → sesiones agendadas (35d)
     *   - item sin itemId      → líneas de crédito/inCredit (35e/payment loop)
     *
     * @param array<string,mixed> $payload
     * @param array<int,array<string,mixed>> $sale
     */
    private static function assertSimplePathEligible(array $payload, array $sale): void
    {
        // Regla COMPARTIDA con el legacy (processData) vía saleIsSimplePathEligible()
        // en app/includes/functions.php — fuente única de verdad, sin duplicar. Acá la
        // traducimos a 422 (InvalidSaleInputException); processData usa la misma regla
        // para RECHAZAR las ventas simples (que desde 35a.8 posee SaleService).
        $reason = \saleIsSimplePathEligible($payload, $sale);
        if ($reason !== null) {
            throw new InvalidSaleInputException($reason);
        }
    }

    /**
     * Valida el shape de `selections` (add-ons elegidos) en cada línea de venta
     * — F3, context/41.
     *
     * Contrato: `selections?: [{ optionId: uuid, qty: number >= 1 }]`.
     *
     * Una línea SIN la key `selections` no se toca: es el 100% del tráfico
     * hasta que exista la UI (F4) y no debe cambiar en nada. Con la key
     * presente exigimos forma estricta y fail-fast, porque un optionId mal
     * tipado terminaría en una query de add-ons que no matchea nada y el
     * cajero vería "opción inválida" sin saber por qué.
     *
     * `qty` acepta cualquier numérico (el front puede mandar "2"), pero tiene
     * que ser entero: media porción de un add-on no existe en el modelo
     * (`addon_group_option.maxQty` es SMALLINT).
     *
     * @param array<int,array<string,mixed>> $sale
     */
    private static function assertSelectionsShape(array $sale): void
    {
        foreach ($sale as $i => $item) {
            if (!is_array($item) || !array_key_exists('selections', $item)) {
                continue;
            }
            $selections = $item['selections'];
            if (!is_array($selections)) {
                throw new InvalidSaleInputException("sale[$i].selections debe ser array");
            }
            if (count($selections) > self::MAX_SELECTIONS_PER_LINE) {
                throw new InvalidSaleInputException(
                    "sale[$i].selections excede el máximo de " . self::MAX_SELECTIONS_PER_LINE . ' opciones'
                );
            }
            foreach ($selections as $j => $sel) {
                if (!is_array($sel)) {
                    throw new InvalidSaleInputException("sale[$i].selections[$j] debe ser objeto");
                }
                $optionId = (string) ($sel['optionId'] ?? '');
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $optionId)) {
                    throw new InvalidSaleInputException(
                        "sale[$i].selections[$j].optionId debe ser un UUID: " . var_export($sel['optionId'] ?? null, true)
                    );
                }
                $qty = $sel['qty'] ?? 1;
                if (!is_numeric($qty)) {
                    throw new InvalidSaleInputException(
                        "sale[$i].selections[$j].qty debe ser numérico: " . var_export($qty, true)
                    );
                }
                if ((float) $qty < 1 || (float) $qty !== floor((float) $qty)) {
                    throw new InvalidSaleInputException(
                        "sale[$i].selections[$j].qty debe ser un entero >= 1: " . var_export($qty, true)
                    );
                }
            }
        }
    }

    /**
     * Normaliza `tags` a una lista de UUIDs (taxonomyId de tipo 'tag').
     *
     * El front manda `JSON.stringify(addedTags)` → un JSON-string. Aceptamos también
     * array directo. Los tags son taxonomyId (UUID) — NO intval (el legacy hacía
     * `intval($ttag)` que destruía el UUID y rompía el INSERT en `totag.tagid` UUID).
     * Dedup + cap 20 (mismo límite que el legacy action.php:2078).
     *
     * @return list<string>|null
     */
    private static function normalizeTags(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $t) {
            $tag = (string) $t;
            if ($tag !== '' && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
            if (count($out) >= 20) {
                break;
            }
        }
        return $out;
    }

    /**
     * `transactionStatus` (smallint en PG) — el legacy aceptaba > -1; acá enforce
     * rango 0..127 (cabe en smallint). Si viene fuera de rango → InvalidSaleInputException.
     */
    /**
     * UUID o null. Un valor con basura se descarta en silencio en vez de tirar
     * 422: es trazabilidad, no un dato del que dependa la venta — perder el
     * vínculo es preferible a que no se pueda cobrar.
     */
    private static function normalizeUuid(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : '';
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) ? $s : null;
    }

    private static function normalizeStatus(mixed $raw): ?int
    {
        // El front manda `false` (booleano JS) para "sin valor" — tratar igual que null.
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        if (!is_numeric($raw)) {
            throw new InvalidSaleInputException('status debe ser numérico: ' . var_export($raw, true));
        }
        $status = (int) $raw;
        if ($status < 0 || $status > 127) {
            throw new InvalidSaleInputException("status fuera de rango (0..127): {$status}");
        }
        return $status;
    }
}
