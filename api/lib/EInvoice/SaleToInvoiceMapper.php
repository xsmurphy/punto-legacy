<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Convierte una venta de Punto en el payload de `POST /api/electronicDocument/Bulk`
 * de Factomate.
 *
 * Shape esperado de `$sale` (array asociativo armado por el caller a partir
 * de la venta ya persistida — este mapper NO lee de la base, solo transforma):
 *
 * [
 *   'total'      => float,              // total de la venta, CON IVA incluido
 *   'currency'   => string,             // 'PYG'; cualquier otra aborta la emisión
 *   'operationCondition' => 0|1,        // 0 contado, 1 crédito
 *   'items' => [
 *     [
 *       'description' => string,
 *       'quantity'    => float,
 *       'unitPrice'   => float,         // precio unitario CON IVA incluido
 *       'total'       => float,         // quantity * unitPrice (con IVA), redondeado
 *       'taxRate'     => 10|5|0,        // 0 = exenta (ver nota de riesgo abajo)
 *     ],
 *     ...
 *   ],
 *   'client' => [
 *     'nature'   => 'contribuyente'|'fisica'|'innominado',
 *     'name'     => string,
 *     'ruc'      => ?string,            // sin DV separado; el mapper no calcula DV
 *     'ci'       => ?string,
 *   ],
 *   'credit' => [                       // solo si operationCondition === 1
 *     'deadline'    => ?string,         // ej. "30 dias" — requerido si cuotas no aplica
 *     'feeNumbers'  => ?int,
 *     'fees'        => ?array,
 *   ],
 *   'paymentMethod' => string,          // medio de pago de Punto, se mapea vía config
 * ]
 *
 * Elegí este shape (en vez de pasar el array crudo de `sale`/SaleInput) porque
 * el mapper no debe conocer el formato interno de persistencia de Punto ni
 * recorrer joins de cliente/impuestos — esa traducción vive en el caller
 * (EInvoiceService), que sí tiene acceso a la venta completa y a Contact.
 * Mantiene este archivo testeable sin base de datos.
 */
final class SaleToInvoiceMapper
{
    private const NATURE_CONTRIBUYENTE = 1;
    private const NATURE_FISICA_O_INNOMINADO = 2;

    // OJO con estos códigos: el `1` es CÉDULA, no RUC — lo usan tanto el
    // contribuyente (que además manda `ruc`) como la persona física sin RUC.
    // El `5` es el que marca al innominado. Nombrarlos por lo que son evita
    // que alguien "corrija" el 1 del caso `fisica` creyendo que está mal.
    private const DOC_TYPE_CEDULA = 1;
    private const DOC_TYPE_INNOMINADO = 5;

    private const INNOMINADO_LIMITE_GS = 1_000_000;

    /**
     * @param array<string,mixed> $sale   Ver shape documentado arriba.
     * @param array<string,mixed> $stamp  Timbrado cacheado de einvoice_account.stamp (trae 'Id').
     * @param array<string,mixed> $config Config de la cuenta (paymentMethodMap, defaultPaymentMethodCode, series).
     * @return array<string,mixed> Payload listo para POST /api/electronicDocument/Bulk.
     * @throws \RuntimeException Con mensaje en castellano indicando qué dato falta o qué regla fiscal se viola.
     */
    public function build(array $sale, array $stamp, array $config): array
    {
        $total = (float) ($sale['total'] ?? 0);
        $operationCondition = (int) ($sale['operationCondition'] ?? 0);
        $items = $sale['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            throw new \RuntimeException('La venta no tiene items — no se puede armar la factura electrónica.');
        }

        $stampId = $stamp['Id'] ?? null;
        if ($stampId === null || $stampId === '') {
            throw new \RuntimeException('No hay timbrado vigente cacheado para esta cuenta — falta sincronizar con Factomate.');
        }

        // El payload sale siempre en PYG con exchangeRate 0. Punto admite otras
        // monedas, así que si la venta no es en guaraníes hay que ABORTAR, no
        // emitir: mandarla igual declararía montos en moneda extranjera como si
        // fueran guaraníes, y el documento quedaría fiscalmente mal sin que
        // nada lo delate. Facturar en otra moneda requiere exchangeRate > 0 y
        // itemExchangeRate consistente — no está implementado.
        $currency = strtoupper((string) ($sale['currency'] ?? 'PYG'));
        if ($currency !== 'PYG') {
            throw new \RuntimeException(
                "La venta está en $currency y la facturación electrónica solo está implementada para guaraníes. " .
                'No se emite para no declarar un monto en la moneda equivocada.'
            );
        }

        $client = $this->buildClient((array) ($sale['client'] ?? []), $total, $operationCondition);

        $itemsPayload = [];
        $taxSum = 0.0;
        $itemTotalSum = 0.0;
        foreach ($items as $i => $item) {
            [$itemPayload, $itemTax] = $this->buildItem((array) $item, $i);
            $itemsPayload[] = $itemPayload;
            $taxSum += $itemTax;
            $itemTotalSum += (float) ($item['total'] ?? 0);
        }

        // Invariante: el total del documento tiene que coincidir con el total de
        // la venta. Comparación con tolerancia de redondeo (1 Gs, la moneda no
        // tiene decimales) — nunca se emite un documento cuyo total difiera.
        if (abs($itemTotalSum - $total) > 1.0) {
            throw new \RuntimeException(
                "El total de los items ($itemTotalSum) no coincide con el total de la venta ($total) — " .
                'revisar redondeo antes de emitir, no se puede facturar así.'
            );
        }

        $payload = [
            'documentTypeCode'      => 1,
            'issuingType'           => 0,
            'number'                => -1, // SIEMPRE -1: numera la SET, no configurable.
            'series'                => (string) ($config['series'] ?? 'AA'),
            'transactionTypeCode'   => 2,
            'taxTypeCode'           => 1,
            'currencyTypeCode'      => 'PYG',
            'exchangeRate'          => 0, // 0 para PYG. Si algún día se factura en otra moneda, > 0 obligatorio.
            'PresenceIndicatorCode' => 1,
            'branch'                => [
                'branchDocumentTypes' => [
                    ['id' => $stampId],
                ],
            ],
            'client'  => $client,
            'operationCondition' => $operationCondition,
            'items'   => $itemsPayload,
            // subTotal === total, AMBOS con IVA incluido. La documentación de
            // integración lo describe como "sin IVA" pero el flujo probado en
            // producción manda subTotal === total y SIFEN deriva el desglose de
            // taxRate + taxedProportion por item. NO "corregir" a total - tax.
            'subTotal' => $total,
            'total'    => $total,
            // La guía de referencia usa total/11 (asume 10% en todos los items).
            // Punto tiene 10%, 5% y exentas mezcladas en la misma venta, así que
            // acá nos apartamos a propósito: sumamos el IVA calculado per-item
            // (con redondeo por item, no sobre el total) en vez de aplicar una
            // fórmula global que solo es válida para el caso 100%-10%.
            'tax'      => round($taxSum),
            'payments' => [$this->buildPayment($sale, $total, $config)],
        ];

        if ($operationCondition === 1) {
            $payload['credit'] = $this->buildCredit((array) ($sale['credit'] ?? []));
        }

        return $payload;
    }

    /**
     * @return array{0: array<string,mixed>, 1: float} [payload del item, IVA de ese item]
     */
    private function buildItem(array $item, int $index): array
    {
        $unitPrice = (float) ($item['unitPrice'] ?? 0);
        $quantity = (float) ($item['quantity'] ?? 0);
        $itemTotal = (float) ($item['total'] ?? ($unitPrice * $quantity));
        $taxRate = (int) ($item['taxRate'] ?? 10);

        if (!in_array($taxRate, [10, 5, 0], true)) {
            throw new \RuntimeException("Item #$index tiene taxRate inválido ($taxRate) — solo se admite 10, 5 o 0.");
        }

        // IVA per-item: taxRate/(100+taxRate) * total del item, redondeado por
        // item (no al final) para que la suma cierre igual que como SIFEN va a
        // derivarla de taxRate + taxedProportion. taxRate=0 (exenta) da IVA 0
        // directo, sin división por cero.
        //
        // AVISO: taxRate=0 para exentas está SIN VERIFICAR contra la API real de
        // Factomate — la guía de integración solo documenta 10 y 5, no dice cómo
        // se marca una línea exenta. Si el rechazo de SIFEN menciona
        // taxRate/exenta, este es el primer sospechoso.
        $itemTax = $taxRate > 0 ? round($itemTotal * $taxRate / (100 + $taxRate)) : 0.0;

        return [
            [
                'description'          => (string) ($item['description'] ?? ''),
                'quantity'             => $quantity,
                'measurementUnitCode'  => 0,
                'internalCode'         => '-',
                'unitPriceWithTax'     => $unitPrice,
                'total'                => $itemTotal,
                'taxImpact'            => 0,
                'taxRate'              => $taxRate,
                'taxedProportion'      => 100,
                // Debe ser igual al exchangeRate del documento (SIFEN valida
                // consistencia); 0 porque el documento siempre va en PYG acá.
                'itemExchangeRate'     => 0,
                'discount'             => 0,
                'advance'              => 0,
            ],
            $itemTax,
        ];
    }

    /**
     * @param array<string,mixed> $rawClient
     */
    private function buildClient(array $rawClient, float $total, int $operationCondition): array
    {
        $nature = (string) ($rawClient['nature'] ?? 'innominado');
        $ruc = $rawClient['ruc'] ?? null;
        $ci = $rawClient['ci'] ?? null;

        $isIdentified = $nature !== 'innominado' && (!empty($ruc) || !empty($ci));

        if ($nature === 'innominado' && $total >= self::INNOMINADO_LIMITE_GS) {
            throw new \RuntimeException(
                'La venta supera Gs. 1.000.000 — no se puede facturar a consumidor final sin identificar. ' .
                'Cargá el RUC o CI del cliente antes de emitir.'
            );
        }

        if ($operationCondition === 1 && !$isIdentified) {
            throw new \RuntimeException(
                'La venta es a crédito — no se puede emitir a un cliente innominado. ' .
                'Cargá el RUC o CI del cliente antes de emitir.'
            );
        }

        if ($nature === 'contribuyente') {
            if (empty($ruc)) {
                throw new \RuntimeException('Falta el RUC del cliente — es obligatorio para facturar a un contribuyente.');
            }
            return [
                'nature'                     => self::NATURE_CONTRIBUYENTE,
                'identityDocumentTypeCode'   => self::DOC_TYPE_CEDULA,
                'ruc'                        => (string) $ruc,
                'identityDocumentNumber'     => $ci !== null && $ci !== '' ? (string) $ci : null,
                'contributorType'            => (int) ($rawClient['contributorType'] ?? 2),
                'name'                       => (string) ($rawClient['name'] ?? ''),
                'countryCode'                => 107,
                'countryName'                => 'Paraguay',
            ];
        }

        if ($nature === 'fisica') {
            if (empty($ci)) {
                throw new \RuntimeException('Falta la cédula del cliente — es obligatoria para facturar a una persona física sin RUC.');
            }
            return [
                'nature'                     => self::NATURE_FISICA_O_INNOMINADO,
                'identityDocumentTypeCode'   => self::DOC_TYPE_CEDULA,
                'ruc'                        => null,
                'identityDocumentNumber'     => (string) $ci,
                'contributorType'            => 1,
                'name'                       => (string) ($rawClient['name'] ?? ''),
                'countryCode'                => 107,
                'countryName'                => 'Paraguay',
            ];
        }

        // Innominado (consumidor final, sin identificar; ya validado que el total lo permite).
        return [
            'nature'                     => self::NATURE_FISICA_O_INNOMINADO,
            'identityDocumentTypeCode'   => self::DOC_TYPE_INNOMINADO,
            'ruc'                        => null,
            'identityDocumentNumber'     => null,
            'contributorType'            => 1,
            'name'                       => (string) ($rawClient['name'] ?? 'Consumidor final'),
            'countryCode'                => 107,
            'countryName'                => 'Paraguay',
        ];
    }

    /**
     * @param array<string,mixed> $credit
     */
    private function buildCredit(array $credit): array
    {
        $condition = (int) ($credit['creditOperationCondition'] ?? 0);

        if ($condition === 0) {
            $deadline = $credit['deadline'] ?? $credit['creditDeadline'] ?? null;
            if (empty($deadline)) {
                throw new \RuntimeException('Venta a crédito con plazo — falta la fecha/plazo de vencimiento (creditDeadline).');
            }
            return [
                'creditOperationCondition' => 0,
                'creditDeadline'           => (string) $deadline,
                'initialDeliveryAmmount'   => (float) ($credit['initialDeliveryAmmount'] ?? 0),
            ];
        }

        // Cuotas: requiere feeNumbers + fees[].
        $feeNumbers = $credit['feeNumbers'] ?? null;
        $fees = $credit['fees'] ?? null;
        if (empty($feeNumbers) || !is_array($fees) || empty($fees)) {
            throw new \RuntimeException('Venta a crédito en cuotas — faltan feeNumbers y/o el detalle de fees.');
        }
        return [
            'creditOperationCondition' => 1,
            'feeNumbers'               => (int) $feeNumbers,
            'fees'                     => $fees,
            'initialDeliveryAmmount'   => (float) ($credit['initialDeliveryAmmount'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $sale
     * @param array<string,mixed> $config
     */
    private function buildPayment(array $sale, float $total, array $config): array
    {
        $puntoMethod = (string) ($sale['paymentMethod'] ?? '');
        $map = (array) ($config['paymentMethodMap'] ?? []);
        $default = (int) ($config['defaultPaymentMethodCode'] ?? 1);

        $code = isset($map[$puntoMethod]) ? (int) $map[$puntoMethod] : $default;

        return [
            'paymentMethodCode' => $code,
            // 'ammount' con doble m: typo de la API de Factomate, no se corrige.
            'ammount' => $total,
        ];
    }
}
