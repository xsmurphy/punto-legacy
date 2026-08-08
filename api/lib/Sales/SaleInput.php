<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

use Punto\Api\Sales\Exceptions\InvalidSaleInputException;

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
    ) {
    }

    /**
     * Construye y valida SaleInput desde el array crudo del payload del front.
     * Lanza InvalidSaleInputException con mensaje claro si falta o está mal tipado.
     *
     * El front manda algunos campos como string ("5000.00") o como número crudo;
     * normalizamos a tipos PHP estrictos acá una sola vez.
     */
    public static function fromPayload(array $raw): self
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

        $date = (string) ($payload['date'] ?? '');
        if ($date === '') {
            throw new InvalidSaleInputException('Falta date en el payload');
        }

        // Defense-in-depth: SaleService solo cubre la VENTA SIMPLE. Rechazamos los
        // payloads que requieren paths aún no migrados, para que el caller (front en
        // 35a.7) los rute al legacy processData. El front ya hace este check antes de
        // llamar; esto es la segunda línea por si entra un payload no-simple.
        self::assertSimplePathEligible($payload, $sale);

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
        );
    }

    /**
     * Construye SaleInput para una cotización (type=9).
     * No requiere payment. No llama assertSimplePathEligible.
     */
    public static function fromQuotePayload(array $raw): self
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

        $date = (string) ($payload['date'] ?? '');
        if ($date === '') {
            throw new InvalidSaleInputException('Falta date en el payload');
        }

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
