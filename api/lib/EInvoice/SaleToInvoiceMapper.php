<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Convierte una venta de Punto en el documento (SIN envolver en
 * `ElectronicDocuments: []`, eso lo hace FactomateProvider::issue()) que
 * `POST /api/electronicDocument/Bulk` de Factomate espera.
 *
 * Shape verificado contra la API real (2026-07-30) — se emitió una factura
 * de prueba exitosa con este shape exacto. Fuente de verdad:
 * `/Users/xstian/Dropbox/Automate/Agent/src/integrations/efatech/efatech.types.ts`
 * y `.../src/services/billing/document-builder.ts` (implementación real en
 * producción de otro cliente de Factomate).
 *
 * Shape esperado de `$sale` (array asociativo armado por el caller a partir
 * de la venta ya persistida — este mapper NO lee de la base, solo transforma):
 *
 * [
 *   'documentType' => ?int,             // 1 factura (default), 5 nota de crédito
 *   'associatedCdc' => ?string,         // CDC de la factura corregida — OBLIGATORIO si documentType=5
 *   'total'      => float,              // total del documento, CON IVA incluido
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
 *     'ci'       => ?string,            // número de documento (CI paraguaya O el número
 *                                       // del documento extranjero — mismo campo, ver idType)
 *     'idType'   => ?int,               // Tabla 3 SET (11-17, ContactService::ID_TYPE_*).
 *                                       // Solo relevante en nature='fisica' (ver buildClient/
 *                                       // mapIdType) — 'contribuyente' e 'innominado' no lo usan.
 *   ],
 *   'credit' => [                       // solo si operationCondition === 1
 *     'deadline'    => ?string,         // ej. "30 dias" — requerido si cuotas no aplica
 *     'feeNumbers'  => ?int,
 *     'fees'        => ?array,
 *   ],
 *   'payments' => [                     // una línea por pago real de la venta
 *     [
 *       'methodId'  => ?string,         // taxonomyId del medio de pago de Punto (null = desconocido)
 *       'methodKey' => string,          // clave cruda del pago, solo para mensajes de error
 *       'amount'    => float,           // monto COBRADO con ese medio (sin vuelto)
 *     ],
 *     ...
 *   ],
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

    // contributorType: con RUC → 1, sin RUC → 2. Ver nota en buildClient().
    private const CONTRIBUTOR_TYPE_CON_RUC = 1;
    private const CONTRIBUTOR_TYPE_SIN_RUC = 2;

    // Tabla 3 SET (ContactService::ID_TYPE_*, 11-17) → identityDocumentTypeCode
    // de Factomate. DOS CODIFICACIONES DISTINTAS — no confundir:
    //   - Tabla 3 es la de la SET ("Especificación Técnica para Importación",
    //     SET, junio 2021), la misma que persiste `contact.contactIdType`.
    //   - El código de la derecha es el catálogo PROPIO de la API Efatech/
    //     Factomate (endpoint GET /api/IdentityDocumentType/get — un
    //     GeneralCodes más, NO documentado con valores estáticos en el manual
    //     "API TaxPro - Facturación Electrónica", solo linkea al endpoint en
    //     vivo). Los 4 valores de abajo SÍ están verificados: vienen de la
    //     implementación de referencia en producción (Automate/efatech,
    //     config/constants.ts ID_DOC_TYPES + facturas emitidas con éxito
    //     2026-07-30) — DOC_TYPE_CEDULA (1) y DOC_TYPE_INNOMINADO (5) ya
    //     existían acá con ese mismo origen; 2 y 3 se suman ahora del mismo
    //     archivo verificado.
    //
    // 14 (CÉDULA EXTRANJERO) y 17 (IDENTIFICACIÓN TRIBUTARIA) QUEDAN AFUERA a
    // propósito: ningún documento verificado (ni el manual, ni la
    // implementación de referencia) confirma su código de Factomate.
    // Adivinarlo arriesga declarar mal el documento del receptor ante SIFEN
    // — mapIdType() aborta la emisión para esos dos códigos en vez de
    // inventar un valor. Antes de habilitarlos: confirmar contra
    // GET /api/IdentityDocumentType/get de la cuenta real.
    private const SET_TO_FACTOMATE_ID_TYPE = [
        12 => self::DOC_TYPE_CEDULA,      // CÉDULA DE IDENTIDAD → 1
        13 => 2,                          // PASAPORTE → 2 (Factomate ID_DOC_TYPES.PASAPORTE)
        16 => 3,                          // DIPLOMÁTICO → 3 (Factomate ID_DOC_TYPES.CARNET_DIPLOMATICO)
        15 => self::DOC_TYPE_INNOMINADO,  // SIN NOMBRE → 5
    ];

    // documentTypeCode (guía §"Enviar DE"): 1=Factura, 4=Autofactura,
    // 5=Nota de crédito, 6=Nota de débito, 7=Nota de remisión.
    private const DOC_FACTURA = 1;
    private const DOC_NOTA_CREDITO = 5;

    // associatedDocumentType: 0 = documento electrónico (se referencia por CDC),
    // 1 = documento impreso (timbrado + establecimiento + punto de expedición).
    // Punto solo emite notas de crédito sobre facturas electrónicas propias.
    private const ASSOCIATED_DOC_ELECTRONICO = 0;

    /**
     * @param array<string,mixed> $sale   Ver shape documentado arriba.
     * @param array<string,mixed> $stamp  Timbrado cacheado de einvoice_account.stamp (trae 'Id').
     * @param array<string,mixed> $config Config de la cuenta (paymentMethodMap, defaultPaymentMethodCode, series).
     * @param string $issuedDate Naive `YYYY-MM-DDTHH:MM:SS` en hora local de Asunción — mismo
     *        criterio que `signDate` de la cancelación (ver FactomateProvider::cancel).
     * @return array<string,mixed> UN documento — el caller (FactomateProvider::issue) lo envuelve en
     *         `{"ElectronicDocuments": [...]}` antes de mandarlo.
     * @throws \RuntimeException Con mensaje en castellano indicando qué dato falta o qué regla fiscal se viola.
     */
    public function build(array $sale, array $stamp, array $config, string $issuedDate): array
    {
        $total = (float) ($sale['total'] ?? 0);
        $operationCondition = (int) ($sale['operationCondition'] ?? 0);
        $documentType = (int) ($sale['documentType'] ?? self::DOC_FACTURA);
        $isCreditNote = $documentType === self::DOC_NOTA_CREDITO;
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

        // exchangeRate: 0 para PYG (único caso soportado, ver guard de moneda
        // arriba). Se pasa el mismo valor a cada item vía itemExchangeRate —
        // SIFEN valida consistencia entre ambos.
        $exchangeRate = 0;

        $itemsPayload = [];
        $taxSum = 0.0;
        $itemTotalSum = 0.0;
        foreach ($items as $i => $item) {
            [$itemPayload, $itemTax] = $this->buildItem((array) $item, $i, $exchangeRate);
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
            'documentTypeCode'      => $documentType,
            'issuingType'           => 0,
            // securityCode: obligatorio, 9 dígitos aleatorios. Verificado contra
            // la API real (2026-07-30) — sin este campo Factomate rechaza la emisión.
            'securityCode'          => $this->randomSecurityCode(),
            // Typo "aditionalInformation" (una sola 'd') es de la API de Factomate,
            // no se corrige. Obligatorio, string vacío cuando no aplica.
            'aditionalInformation'  => '',
            'number'                => -1, // SIEMPRE -1: numera la SET, no configurable.
            'series'                => (string) ($config['series'] ?? 'AA'),
            // issuedDate (NO issueDate): naive YYYY-MM-DDTHH:MM:SS en hora local
            // de Asunción, mismo criterio que signDate de la cancelación.
            'issuedDate'            => $issuedDate,
            'transactionTypeCode'   => 2,
            'taxTypeCode'           => 1,
            'currencyTypeCode'      => 'PYG',
            'exchangeRate'          => $exchangeRate,
            'PresenceIndicatorCode' => 1,
            'branch'                => [
                'branchDocumentTypes' => [
                    ['id' => $stampId],
                ],
            ],
            'client'  => $client,
            'operationCondition' => $operationCondition,
            // electronicDocumentItems (NO 'items' ni 'details') — verificado
            // contra la API real (2026-07-30).
            'electronicDocumentItems' => $itemsPayload,
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
        ];

        if ($isCreditNote) {
            // El cuerpo de la nota de crédito es el mismo de la factura; los
            // únicos cambios son el tipo de documento y esta sección, que
            // referencia por CDC el documento que se corrige (guía
            // §"Documento asociado electrónico"). Sin ella SIFEN no tiene qué
            // corregir y rechaza el documento.
            $associatedCdc = trim((string) ($sale['associatedCdc'] ?? ''));
            if ($associatedCdc === '') {
                throw new \RuntimeException(
                    'La nota de crédito no tiene el CDC de la factura original — no se puede emitir sin referenciarla.'
                );
            }
            $payload['associatedDocuments'] = [[
                'associatedDocumentType' => self::ASSOCIATED_DOC_ELECTRONICO,
                'cdc'                    => $associatedCdc,
            ]];

            // Sin bloque `payments`: una nota de crédito no cobra. La
            // devolución del dinero es un movimiento de caja de Punto, no una
            // forma de pago del documento fiscal.
            return $payload;
        }

        $paymentsPayload = $this->buildPayments($sale, $total, $config, $operationCondition);
        if ($paymentsPayload !== []) {
            $payload['payments'] = $paymentsPayload;
        }

        if ($operationCondition === 1) {
            // Entrega inicial = lo que efectivamente se cobró al concretar la
            // venta a crédito (0 si no se cobró nada). Antes iba fijo en 0, lo
            // que sub-declaraba la entrega inicial de toda venta a crédito con
            // pago parcial.
            $initialDelivery = 0.0;
            foreach ($paymentsPayload as $payment) {
                $initialDelivery += (float) $payment['ammount'];
            }
            $payload['credit'] = $this->buildCredit((array) ($sale['credit'] ?? []), $initialDelivery);
        }

        return $payload;
    }

    /**
     * @return array{0: array<string,mixed>, 1: float} [payload del item, IVA de ese item]
     */
    private function buildItem(array $item, int $index, int $exchangeRate): array
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

        // Nombres y campos verificados contra la API real (2026-07-30). No hay
        // campo `total` por item — SIFEN lo deriva de quantity * unitPriceWithTax.
        return [
            [
                'internalCode'                              => '-',
                'description'                                => (string) ($item['description'] ?? ''),
                // Verificado: otros valores de measurementUnitCode rompen la
                // serialización XML del lado de Factomate.
                'measurementUnitCode'                       => 0,
                'quantity'                                   => $quantity,
                'informationOfInterest'                      => '',
                'unitPriceWithTax'                           => $unitPrice,
                // Debe ser igual al exchangeRate del documento (SIFEN valida
                // consistencia); 0 porque el documento siempre va en PYG acá.
                'itemExchangeRate'                           => $exchangeRate,
                'itemUnitPriceDiscountWithTax'                => 0,
                'itemDiscountPercentage'                      => 0,
                'itemUnitPriceGlobalDiscountWithTax'          => 0,
                'itemUnitPriceAdvanceWithTax'                 => 0,
                'itemUnitPriceGlobalAdvanceWithTax'           => 0,
                'taxImpact'                                   => 0,
                'taxedProportion'                             => 100,
                'taxRate'                                     => $taxRate,
            ],
            $itemTax,
        ];
    }

    /**
     * 9 dígitos aleatorios. Verificado contra la API real (2026-07-30):
     * Factomate parsea este campo numéricamente y un carácter no-dígito
     * produce un 400 cuyo mensaje no tiene nada que ver con el campo real
     * que falló — mismo criterio que `randomSecurityCode()` en la
     * implementación de referencia (Automate/efatech).
     */
    private function randomSecurityCode(): string
    {
        $code = '';
        for ($i = 0; $i < 9; $i++) {
            $code .= (string) random_int(0, 9);
        }
        return $code;
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

        // contributorType: con RUC → 1 (persona física), sin RUC → 2. Es lo que
        // hace la implementación real de referencia (Automate/efatech,
        // CONTRIBUTOR_TYPES) — verificado con una factura emitida con éxito
        // (2026-07-30). No es intuitivo (uno esperaría 2=jurídica ligado a
        // tener RUC) pero es el mapeo que la API acepta; no "corregir".
        //
        // businessName/fantasyName (NO 'name'), operationType, address, email
        // y phoneNumber van SIEMPRE presentes, aunque vacíos — campos
        // verificados contra la API real (2026-07-30).
        if ($nature === 'contribuyente') {
            if (empty($ruc)) {
                throw new \RuntimeException('Falta el RUC del cliente — es obligatorio para facturar a un contribuyente.');
            }
            return [
                'nature'                     => self::NATURE_CONTRIBUYENTE,
                'operationType'              => 2, // B2C — único caso soportado hoy.
                'identityDocumentTypeCode'   => self::DOC_TYPE_CEDULA,
                'identityDocumentNumber'     => $ci !== null && $ci !== '' ? (string) $ci : null,
                'countryCode'                => 107,
                'countryName'                => 'Paraguay',
                'contributorType'            => self::CONTRIBUTOR_TYPE_CON_RUC,
                'ruc'                        => (string) $ruc,
                'businessName'               => (string) ($rawClient['name'] ?? ''),
                'fantasyName'                => (string) ($rawClient['name'] ?? ''),
                'address'                    => (string) ($rawClient['address'] ?? ''),
                'email'                      => (string) ($rawClient['email'] ?? ''),
                'phoneNumber'                => (string) ($rawClient['phone'] ?? ''),
            ];
        }

        if ($nature === 'fisica') {
            if (empty($ci)) {
                throw new \RuntimeException('Falta el documento de identidad del cliente — es obligatorio para facturar a una persona física sin RUC.');
            }
            // Default 12 (CÉDULA, \Punto\Api\Contacts\ContactService::ID_TYPE_CEDULA)
            // preserva el comportamiento verificado antes de esta feature:
            // todo 'fisica' sin idType explícito se asumía cédula paraguaya.
            // Contactos con idType real (pasaporte, diplomático) usan su
            // propio código — ver SET_TO_FACTOMATE_ID_TYPE.
            $idType = (int) ($rawClient['idType'] ?? \Punto\Api\Contacts\ContactService::ID_TYPE_CEDULA);
            return [
                'nature'                     => self::NATURE_FISICA_O_INNOMINADO,
                'operationType'              => 2,
                'identityDocumentTypeCode'   => $this->mapIdType($idType),
                'identityDocumentNumber'     => (string) $ci,
                'countryCode'                => 107,
                'countryName'                => 'Paraguay',
                'contributorType'            => self::CONTRIBUTOR_TYPE_SIN_RUC,
                'ruc'                        => null,
                'businessName'               => (string) ($rawClient['name'] ?? ''),
                'fantasyName'                => (string) ($rawClient['name'] ?? ''),
                'address'                    => (string) ($rawClient['address'] ?? ''),
                'email'                      => (string) ($rawClient['email'] ?? ''),
                'phoneNumber'                => (string) ($rawClient['phone'] ?? ''),
            ];
        }

        // Innominado (consumidor final, sin identificar; ya validado que el total lo permite).
        return [
            'nature'                     => self::NATURE_FISICA_O_INNOMINADO,
            'operationType'              => 2,
            'identityDocumentTypeCode'   => self::DOC_TYPE_INNOMINADO,
            'identityDocumentNumber'     => null,
            'countryCode'                => 107,
            'countryName'                => 'Paraguay',
            'contributorType'            => self::CONTRIBUTOR_TYPE_SIN_RUC,
            'ruc'                        => null,
            'businessName'               => (string) ($rawClient['name'] ?? 'Consumidor final'),
            'fantasyName'                => (string) ($rawClient['name'] ?? 'Consumidor final'),
            'address'                    => (string) ($rawClient['address'] ?? ''),
            'email'                      => (string) ($rawClient['email'] ?? ''),
            'phoneNumber'                => (string) ($rawClient['phone'] ?? ''),
        ];
    }

    /**
     * Tabla 3 SET → identityDocumentTypeCode de Factomate — ver
     * SET_TO_FACTOMATE_ID_TYPE para el porqué de cada valor y por qué 14/17
     * quedan afuera.
     *
     * @throws \RuntimeException Si el código no tiene mapeo de Factomate
     *         VERIFICADO — se aborta la emisión en vez de adivinar un valor
     *         para un documento fiscal.
     */
    private function mapIdType(int $setIdType): int
    {
        if (!isset(self::SET_TO_FACTOMATE_ID_TYPE[$setIdType])) {
            throw new \RuntimeException(
                "El tipo de documento $setIdType (Tabla 3 SET) no tiene código de Factomate " .
                'verificado — no se puede emitir sin confirmar el mapeo real contra ' .
                'GET /api/IdentityDocumentType/get de la cuenta. Tipos soportados hoy: ' .
                'cédula (12), pasaporte (13), diplomático (16), sin nombre (15).'
            );
        }
        return self::SET_TO_FACTOMATE_ID_TYPE[$setIdType];
    }

    /**
     * @param array<string,mixed> $credit
     * @param float $initialDelivery Suma de lo cobrado al concretar la venta (ver build()).
     */
    private function buildCredit(array $credit, float $initialDelivery): array
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
                'initialDeliveryAmmount'   => $initialDelivery,
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
            'initialDeliveryAmmount'   => $initialDelivery,
        ];
    }

    /**
     * Una entrada de `payments[]` por medio de pago usado en la venta, con su
     * monto real. Las líneas del mismo código se suman en una sola entrada
     * (dos pagos con la misma tarjeta son un solo medio de pago para SIFEN).
     *
     * Mapeo: `config.paymentMethodMap[taxonomyId] → código de Factomate`
     * (1=Efectivo, 2=Cheque, 3=T. Crédito, 4=T. Débito, 5=Transferencia,
     * 6=Giro, 7=Billetera electrónica, 8=Tarjeta empresarial — es el campo
     * `Identifier` de `PaymentMethod/get`, verificado 2026-07-30). Un método
     * sin mapear cae en `defaultPaymentMethodCode` (1=Efectivo si no se
     * configuró): declarar el medio equivocado es un dato accesorio del
     * documento, mientras que abortar la emisión por un método sin mapear
     * dejaría al comercio sin factura por un detalle de configuración.
     *
     * CONTADO: la suma de las líneas tiene que dar el total del documento —
     * el POS registra el cobrado sin vuelto, así que cierra. Se absorbe hasta
     * 1 Gs de redondeo por línea en la última entrada; una diferencia mayor
     * aborta la emisión en vez de declarar pagos que no cuadran.
     *
     * CRÉDITO: las líneas son la ENTREGA INICIAL, no el total — el saldo no
     * está pagado. Si no hubo entrega inicial, el documento va SIN bloque
     * `payments` (la condición de venta la describe `credit`).
     * SIN VERIFICAR contra la API real: hasta hoy solo se emitieron facturas
     * al contado con un único medio de pago. Primeros sospechosos si Factomate
     * rechaza una venta a crédito o una con pago dividido.
     *
     * @param array<string,mixed> $sale
     * @param array<string,mixed> $config
     * @return array<int,array{paymentMethodCode:int,ammount:float}>
     */
    private function buildPayments(array $sale, float $total, array $config, int $operationCondition): array
    {
        $lines = $sale['payments'] ?? [];
        $map = (array) ($config['paymentMethodMap'] ?? []);
        $default = (int) ($config['defaultPaymentMethodCode'] ?? 1);

        $byCode = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $amount = (float) ($line['amount'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $methodId = $line['methodId'] ?? null;
                $code = ($methodId !== null && isset($map[$methodId])) ? (int) $map[$methodId] : $default;
                $byCode[$code] = ($byCode[$code] ?? 0.0) + $amount;
            }
        }

        if ($operationCondition === 1) {
            // Crédito: sin entrega inicial no hay nada que declarar como pagado.
            return $this->paymentsPayload($byCode);
        }

        if ($byCode === []) {
            // Venta al contado sin pagos registrados (ventas viejas migradas,
            // o un flujo que no persistió transactionPaymentType): se declara
            // el total con el medio por defecto, que es la única lectura
            // posible — la alternativa sería no emitir una venta ya cobrada.
            return [['paymentMethodCode' => $default, 'ammount' => $total]];
        }

        $sum = array_sum($byCode);
        $diff = $total - $sum;
        if (abs($diff) > max(1.0, (float) count($byCode))) {
            throw new \RuntimeException(
                "Los pagos registrados suman $sum y el total de la venta es $total — " .
                'no se emite un documento cuyos medios de pago no cuadran con el total.'
            );
        }
        if ($diff != 0.0) {
            // Residuo de redondeo: se absorbe en el código de mayor monto para
            // que la suma cierre exacto (mismo criterio que el ajuste de
            // redondeo per-item).
            arsort($byCode);
            $biggest = array_key_first($byCode);
            $byCode[$biggest] += $diff;
        }

        return $this->paymentsPayload($byCode);
    }

    /**
     * @param array<int,float> $byCode
     * @return array<int,array{paymentMethodCode:int,ammount:float}>
     */
    private function paymentsPayload(array $byCode): array
    {
        $out = [];
        foreach ($byCode as $code => $amount) {
            $out[] = [
                'paymentMethodCode' => (int) $code,
                // 'ammount' con doble m: typo de la API de Factomate, no se corrige.
                'ammount' => $amount,
            ];
        }
        return $out;
    }
}
