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
 *   'fiscalNumber' => ?int,             // correlativo CONGELADO en la venta (transaction.invoiceNo,
 *                                       // mig 145). OBLIGATORIO para factura — ver resolveDocumentNumber().
 *   'fiscalAuth'   => ?string,          // timbrado congelado de la venta (transaction.invoiceAuth).
 *                                       // El mapper NO lo manda en el payload: la coherencia contra el
 *                                       // timbrado provisionado la valida EInvoiceService, que es quien
 *                                       // tiene el dato remoto. Viaja acá solo para mensajes de error.
 *   'total'      => float,              // total del documento, CON IVA incluido
 *   'currency'   => string,             // 'PYG'; cualquier otra aborta la emisión
 *   'operationCondition' => 0|1,        // 0 contado, 1 crédito
 *   'securityCode' => ?string,          // 9 dígitos CONGELADOS para este documento
 *                                       // (einvoice_document.security_code, mig 205). Si
 *                                       // falta, el mapper genera uno — pero entonces cambia
 *                                       // en cada reintento y con él el CDC. Ver
 *                                       // resolveSecurityCode().
 *   'issuedDate' => ?string,            // fecha de la OPERACIÓN (no la de emisión). La
 *                                       // resuelve el caller; llega como argumento aparte.
 *   'items' => [
 *     [
 *       'description' => string,
 *       'quantity'    => float,
 *       'unitPrice'   => float,         // precio unitario CON IVA incluido
 *       'total'       => float,         // quantity * unitPrice (con IVA), redondeado.
 *                                       // MANDA sobre unitPrice: el unitario del payload se
 *                                       // deriva de acá (ver fiscalLines()), porque SIFEN
 *                                       // recalcula el total multiplicando.
 *       'taxRate'     => 10|5|0,        // 0 = exenta (ver nota de riesgo abajo)
 *       'isService'   => ?bool,         // true si el ítem es un servicio. Alimenta
 *                                       // transactionTypeCode (mercadería/servicios/mixto).
 *                                       // Ausente = mercadería.
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
 *     'address'  => ?string,            // Los tres viajan al receptor del documento. El
 *     'email'    => ?string,            // caller los saca del contacto; si no los manda,
 *     'phone'    => ?string,            // el campo sale vacío (va igual, no se omite).
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

    // transactionTypeCode. Valores del catálogo de Factomate, verificados
    // contra la implementación de referencia en producción
    // (Automate/efatech, `src/config/constants.ts` TRANSACTION_TYPES).
    // El MIXTO existe: no hay que elegir entre mercadería y servicio cuando
    // la venta tiene las dos cosas.
    private const TRANSACTION_TYPE_MERCADERIA = 1;
    private const TRANSACTION_TYPE_SERVICIOS  = 2;
    private const TRANSACTION_TYPE_MIXTO      = 3;

    // client.operationType. Mismo origen verificado (customer-resolver.ts:176
    // en la implementación de referencia: `hasRuc ? 1 : 2`).
    // 4 = autofactura, que Punto no emite.
    private const OPERATION_TYPE_B2B = 1;
    private const OPERATION_TYPE_B2C = 2;

    // associatedDocumentType: 0 = documento electrónico (se referencia por CDC),
    // 1 = documento impreso (timbrado + establecimiento + punto de expedición).
    // Punto solo emite notas de crédito sobre facturas electrónicas propias.
    private const ASSOCIATED_DOC_ELECTRONICO = 0;

    /**
     * @param array<string,mixed> $sale   Ver shape documentado arriba.
     * @param array<string,mixed> $stamp  Timbrado de la caja: 'Id' (obligatorio) y 'Serie'
     *        (la del BranchDocumentType remoto — vacía si el timbrado no tiene serie).
     * @param array<string,mixed> $config Config de la cuenta (paymentMethodMap, defaultPaymentMethodCode,
     *        series, legacyAutoNumbering, emitterCdc). `emitterCdc` está gateado y sin activar —
     *        ver el bloque "CDC DEL EMISOR" en el cuerpo de este método.
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
        // Sin `?? 'PYG'`: una venta que no declara moneda NO es una venta en
        // guaraníes, es un dato faltante. Asumirlo acá le ponía "PYG" a algo
        // desconocido y lo dejaba pasar el guard de abajo como si estuviera
        // todo bien. Se rechaza explícito y el error dice qué falta.
        $currency = strtoupper(trim((string) ($sale['currency'] ?? '')));
        if ($currency === '') {
            throw new \RuntimeException(
                'La venta no declara moneda y no se pudo determinar la del comercio. ' .
                'No se emite para no declarar los montos en una moneda supuesta.'
            );
        }
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

        // Decimales de la MONEDA del documento — no del país. PYG no tiene
        // decimales, así que un unitario tiene que ser entero.
        $moneyDecimals = self::currencyDecimals($currency);

        $itemsPayload = [];
        $taxSum = 0.0;
        $declaredSum = 0.0;
        foreach ($items as $i => $item) {
            $item = (array) $item;

            // El IVA se calcula sobre la línea ORIGINAL, antes de partirla:
            // partir y redondear cada pedazo puede correr el total un guaraní
            // respecto de lo que declara la venta.
            $taxSum += self::lineTax($item, $i);

            foreach (self::fiscalLines($item, $moneyDecimals) as $line) {
                $itemsPayload[] = $this->buildItem($line, $i, $exchangeRate);
                $declaredSum += round((float) $line['quantity'] * (float) $line['unitPrice'], $moneyDecimals);
            }
        }

        // Invariante: lo que el documento DECLARA tiene que dar el total de la
        // venta. Se compara Σ(quantity × unitPriceWithTax) y no Σ(total de
        // línea) porque el payload no lleva un total por ítem: SIFEN lo
        // recalcula multiplicando, y ésa es la cuenta que puede rechazar.
        // Antes se comparaba el total de línea —que siempre cerraba— mientras
        // el unitario redondeado a 8 decimales hacía que la multiplicación NO
        // cerrara (10.000 / 3 es el caso canónico). fiscalLines() se encarga
        // de que cierre; este guard es la red por si algún caso se le escapa.
        if (abs($declaredSum - $total) > 1.0) {
            throw new \RuntimeException(
                "Lo que declaran los items (cantidad x unitario = $declaredSum) no da el total de la venta " .
                "($total) — revisar redondeo antes de emitir, no se puede facturar así."
            );
        }

        $payload = [
            'documentTypeCode'      => $documentType,
            'issuingType'           => 0,
            // securityCode: obligatorio, 9 dígitos aleatorios. Verificado contra
            // la API real (2026-07-30) — sin este campo Factomate rechaza la
            // emisión, y como lo parsea numéricamente, un carácter no-dígito
            // devuelve un 400 cuyo mensaje no menciona el campo que falló.
            //
            // Generador único: `Cdc::securityCode()`. Este campo NO es un
            // requisito administrativo del proveedor — es el componente 10 del
            // CDC (los 9 dígitos que en el KuDE de referencia salen como
            // `000000001`), o sea que el número que mandamos acá es el que
            // termina DENTRO del código de control del documento. Tener dos
            // generadores para el mismo dígito garantizaba que el día que se
            // active el CDC del emisor uno de los dos quedara desalineado.
            // Ver `Cdc::securityCode()` para por qué es CSPRNG y no secuencial.
            //
            // Se toma el que el CALLER congeló para este documento y solo se
            // genera uno nuevo si no vino: regenerarlo en cada intento hacía
            // que un reintento sobre un documento que Factomate YA había
            // creado (timeout después del alta) saliera con otro CDC para la
            // misma venta — el rechazo 1002 de SIFEN por duplicado. Ver
            // `einvoice_document.security_code` (mig 205).
            'securityCode'          => self::resolveSecurityCode($sale),
            // Typo "aditionalInformation" (una sola 'd') es de la API de Factomate,
            // no se corrige. Obligatorio, string vacío cuando no aplica.
            'aditionalInformation'  => '',
            // El NÚMERO LO PONE EL EMISOR — o sea nosotros. Ver
            // resolveDocumentNumber() para la historia completa de por qué
            // acá decía "SIEMPRE -1" y por qué era falso.
            'number'                => $this->resolveDocumentNumber($sale, $config, $documentType),
            // La serie sale del TIMBRADO, que es quien la tiene. Acá había un
            // 'AA' fijo —copiado de la guía de integración, cuyo emisor sí
            // usaba esa serie— mientras nuestro provisioning crea el
            // BranchDocumentType con `Serie: ''`: el documento declaraba una
            // serie que el talonario no tiene. Se manda lo que el timbrado
            // diga, vacío incluido; `$config['series']` queda como override
            // explícito por si el proveedor exigiera un valor, para poder
            // resolverlo por configuración y no por deploy.
            'series'                => self::resolveSeries($stamp, $config),
            // issuedDate (NO issueDate): naive YYYY-MM-DDTHH:MM:SS en hora local
            // de Asunción, mismo criterio que signDate de la cancelación.
            'issuedDate'            => $issuedDate,
            // Derivado de lo que la venta REALMENTE tiene (1 mercadería,
            // 2 servicios, 3 mixto). Estaba fijo en 2 porque el emisor de la
            // guía de integración vende solo servicios; declarar servicios
            // una venta de mercadería es declararle mal la operación a SIFEN.
            'transactionTypeCode'   => self::resolveTransactionType($items),
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

        // ── CDC DEL EMISOR — preparado, GATEADO, sin activar ─────────────────
        //
        // Hoy el CDC lo devuelve Factomate DESPUÉS de emitir, y como la emisión
        // es asíncrona el primer ticket de la venta sale sin CDC ni QR (el
        // bloque de plantilla queda en blanco y la reimpresión sí los trae).
        // Pero los 44 dígitos son CALCULABLES LOCALMENTE: ningún componente
        // depende del proveedor — tipo de documento, RUC + DV del emisor,
        // establecimiento, punto de expedición, número congelado de la caja,
        // tipo de contribuyente, fecha, tipo de emisión y el código de
        // seguridad que este mismo payload ya genera (`securityCode`, arriba).
        // Ver la anatomía completa en `Cdc`, verificada contra un KuDE real.
        //
        // Si Factomate confirma que su `/Bulk` acepta el CDC del emisor
        // (consulta abierta con su soporte, sin respuesta al 2026-09-07), el
        // comprobante puede salir con CDC y QR IMPRESOS EN EL MOMENTO DE LA
        // VENTA, offline incluido — que es lo que hace falta para que el ticket
        // sea una representación completa de la factura electrónica sin
        // esperar la vuelta de la red.
        //
        // El interruptor es `einvoice_account.config->>'emitterCdc'` (default
        // false, sin UI a propósito: no es una preferencia del comercio sino un
        // hecho sobre la API del proveedor). Cuando se confirme, esto es todo
        // lo que hay que descomentar — el nombre del campo raíz es lo ÚNICO
        // que falta y por eso no se deja escrito a medias: mandar una clave
        // inventada haría que Factomate ignore el CDC en silencio y volvamos a
        // tener un número nuestro y un CDC suyo, justo el escenario que el
        // guard de `EInvoiceService::cdcMismatchFor()` existe para detectar.
        //
        // if (!empty($config['emitterCdc'])) {
        //     $payload['cdc'] = Cdc::build([                        // ← nombre del campo SIN CONFIRMAR
        //         'documentType'    => $documentType,
        //         'ruc'             => $sale['emitterRuc'],          // sin DV
        //         'rucCheckDigit'   => $sale['emitterRucDv'],
        //         'establishment'   => $sale['establishment'],
        //         'expeditionPoint' => $sale['expeditionPoint'],
        //         'number'          => $payload['number'],           // el congelado de la caja
        //         'taxpayerType'    => $sale['taxpayerType'],
        //         'date'            => substr($issuedDate, 0, 10),   // Cdc::build() saca los guiones
        //         'emissionType'    => 1,                            // 1 = normal
        //         'securityCode'    => $payload['securityCode'],     // el MISMO de este payload
        //     ]);
        // }

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
     * El `number` del documento electrónico. **Lo pone el EMISOR** — es el
     * mismo correlativo que la caja ya congeló en la venta y que salió
     * impreso en el ticket (`transaction.invoiceNo`, mig 145).
     *
     * ── La historia, porque el comentario que había acá era falso ─────────
     *
     * Hasta 2026-09-07 esta línea decía `'number' => -1, // SIEMPRE -1:
     * numera la SET, no configurable`. Las dos afirmaciones eran falsas:
     *
     *   - **No numeraba la SET, numeraba FACTOMATE.** El correlativo lo
     *     llevaba el `CurrentNumber` de la fila `BranchDocumentType` del
     *     proveedor (verificado contra la API real el 2026-07-30: el
     *     timbrado estaba en 53 y el CDC emitido terminó en `…0000054`).
     *     En SIFEN estándar el número lo pone el emisor y el CDC se deriva
     *     de él.
     *   - **Sí es configurable.** El propio `context/28` documenta que
     *     `number` acepta un correlativo propio; se eligió `-1` en la
     *     decisión del 2026-07-28 y esa decisión quedó REVERTIDA por el
     *     owner el 2026-09-07: *"desde el inicio nosotros tenemos que ser
     *     dueños de la numeración. Factomate no debe llevar la
     *     numeración"*.
     *
     * El motivo de fondo es que el comprobante impreso es la representación
     * impresa de la factura electrónica: tiene que llevar EL MISMO número.
     * Con `-1` el número lo decidía el proveedor después de que el ticket ya
     * estaba en la mano del cliente.
     *
     * ── Formato ──────────────────────────────────────────────────────────
     *
     * Correlativo ENTERO PELADO (`NNNNNNN` sin ceros a la izquierda y sin
     * el prefijo `EEE-PPP`): establecimiento y punto de expedición salen
     * SIEMPRE del timbrado del lado de Factomate (la fila
     * `BranchDocumentType` que el provisioning creó con el `EEE-PPP` de la
     * caja). Fuente: `context/28` §Numeración, que lo documenta explícito
     * — *"un correlativo propio, pero solo la parte NNNNNNN de
     * EEE-PPP-NNNNNNN"*. Coincide además con `context/29` §1: los 7 dígitos
     * son FORMATO y el correlativo se guarda entero.
     *
     * Si algún día se comprobara que la API espera el string completo
     * `001-001-0000054`, el cambio es de UNA línea acá (armarlo con
     * `DocumentNumber::format()` + el `EEE-PPP` del timbrado remoto) — no
     * se toca nada aguas arriba. Queda pendiente confirmarlo contra la API
     * real: la cuenta DEV está caída (PhoneLogin 500) al momento de
     * escribir esto.
     *
     * ── Los tres casos ───────────────────────────────────────────────────
     *
     *   1. **Kill-switch de emergencia** `config.legacyAutoNumbering` →
     *      `-1`. NO tiene UI y no es un modo soportado: existe solo para
     *      poder volver al comportamiento anterior sin un deploy si la
     *      numeración propia resultara rechazada en producción. Se setea a
     *      mano en `einvoice_account.config`.
     *   2. **Nota de crédito** → `-1` POR AHORA, y es una limitación
     *      declarada, no un olvido: el `invoiceNo` de una devolución sale
     *      de `document_sequence` doctype `nota_credito` con scope OUTLET
     *      (`ReturnService`), no de un talonario por punto de expedición, y
     *      la transacción type=6 ni siquiera congela timbrado. Mandarlo
     *      como número fiscal declararía ante SIFEN un correlativo de otra
     *      rama de numeración. Se resuelve cuando aterrice la F3 de
     *      `context/40` (numeración de NC como doctype propio con rango de
     *      timbrado). Hasta entonces la NC la numera Factomate — que es
     *      exactamente lo que hoy ya pasa.
     *   3. **Factura (FC/FCR)** → el número congelado. Sin número válido se
     *      ABORTA: caer a `-1` en silencio reintroduciría la numeración del
     *      proveedor justo en el caso que nadie mira (una venta vieja sin
     *      B1, un dato corrupto), y el ticket impreso y el documento fiscal
     *      quedarían con números distintos sin que nada lo delate.
     *
     * @param array<string,mixed> $sale
     * @param array<string,mixed> $config
     * @throws \RuntimeException si la factura no trae un correlativo congelado válido.
     */
    private function resolveDocumentNumber(array $sale, array $config, int $documentType): int
    {
        if (!empty($config['legacyAutoNumbering'])) {
            return -1;
        }

        if ($documentType === self::DOC_NOTA_CREDITO) {
            return -1;
        }

        $raw = $sale['fiscalNumber'] ?? null;
        $number = is_numeric($raw) ? (int) $raw : 0;
        if ($number <= 0) {
            throw new \RuntimeException(
                'La venta no tiene número de comprobante propio congelado, así que no se puede emitir el ' .
                'documento electrónico con el mismo número que salió impreso en el ticket. ' .
                'Revisá que la caja tenga timbrado y numeración cargados.'
            );
        }

        return $number;
    }

    /**
     * @return array{0: array<string,mixed>, 1: float} [payload del item, IVA de ese item]
     */
    /**
     * `securityCode` — los 9 dígitos del componente 10 del CDC.
     *
     * Viene congelado en la fila del outbox (`einvoice_document.security_code`,
     * mig 205) y solo se genera cuando el caller no lo trae: es el MISMO
     * número en todos los reintentos de un documento, porque cambiarlo cambia
     * el CDC y un reintento con otro CDC sobre un documento que el proveedor
     * ya creó es un duplicado ante SIFEN (rechazo 1002). Una reemisión es un
     * documento nuevo, con fila nueva, y por lo tanto código nuevo — eso es
     * correcto y sale solo.
     *
     * Generador único: `Cdc::securityCode()` (CSPRNG, ver ahí el porqué).
     */
    private static function resolveSecurityCode(array $sale): string
    {
        $frozen = trim((string) ($sale['securityCode'] ?? ''));
        // Se acepta solo si es lo que el CDC espera: 9 dígitos exactos. Un
        // valor corrupto se descarta en vez de viajar — Factomate lo parsea
        // numéricamente y devuelve un 400 que no nombra el campo que falló.
        if (preg_match('/^\d{9}$/', $frozen) === 1) {
            return $frozen;
        }
        return Cdc::securityCode();
    }

    /**
     * Serie del documento. Sale del timbrado (`BranchDocumentType.Serie`),
     * que es el que la define; `$config['series']` la pisa si está seteada,
     * como escotilla de configuración.
     *
     * Devuelve string vacío cuando el timbrado no tiene serie — que es el
     * caso de todos los que crea nuestro provisioning (`Serie: ''`). El campo
     * se manda igual, vacío: la implementación de referencia siempre lo
     * incluye, y omitir una clave que el proveedor espera es un riesgo
     * distinto (y peor de diagnosticar) que mandarla vacía.
     */
    private static function resolveSeries(array $stamp, array $config): string
    {
        $override = trim((string) ($config['series'] ?? ''));
        if ($override !== '') {
            return $override;
        }
        return trim((string) ($stamp['Serie'] ?? $stamp['serie'] ?? ''));
    }

    /**
     * `transactionTypeCode` a partir de lo que la venta tiene adentro:
     * mercadería, servicios, o las dos cosas (MIXTO).
     *
     * Cada línea llega con `isService` (bool) desde el caller, que es quien
     * conoce el `kind` del ítem de Punto. Una línea sin el dato cuenta como
     * mercadería: es lo que es la enorme mayoría del catálogo, y el default
     * anterior —servicios para TODO— era el que estaba mal.
     *
     * @param array<int,mixed> $items
     */
    private static function resolveTransactionType(array $items): int
    {
        $hasService = false;
        $hasGoods   = false;
        foreach ($items as $item) {
            if (!empty(((array) $item)['isService'])) {
                $hasService = true;
            } else {
                $hasGoods = true;
            }
        }

        if ($hasService && $hasGoods) {
            return self::TRANSACTION_TYPE_MIXTO;
        }
        return $hasService ? self::TRANSACTION_TYPE_SERVICIOS : self::TRANSACTION_TYPE_MERCADERIA;
    }

    /**
     * Decimales de una moneda ISO 4217. La regla es de la MONEDA, no del
     * país del comercio: PYG, CLP, JPY, KRW, VND y compañía no tienen parte
     * decimal, así que su unitario tiene que ser entero; el resto usa 2.
     *
     * (Hoy `build()` aborta si la moneda no es PYG, pero la exactitud del
     * unitario no es un problema paraguayo — cuando se habilite otra moneda
     * esta función ya dice cuántos decimales admite.)
     */
    private static function currencyDecimals(string $currency): int
    {
        static $zeroDecimal = [
            'PYG' => true, 'CLP' => true, 'JPY' => true, 'KRW' => true,
            'VND' => true, 'ISK' => true, 'COP' => true, 'UGX' => true,
            'RWF' => true, 'XAF' => true, 'XOF' => true, 'XPF' => true,
        ];
        return isset($zeroDecimal[strtoupper($currency)]) ? 0 : 2;
    }

    /**
     * IVA de una línea: taxRate/(100+taxRate) * total, redondeado por línea
     * (no al final) para que la suma cierre igual que como SIFEN la deriva de
     * taxRate + taxedProportion. taxRate=0 (exenta) da 0 sin dividir.
     *
     * AVISO: taxRate=0 para exentas está SIN VERIFICAR contra la API real de
     * Factomate — la guía de integración solo documenta 10 y 5, no dice cómo
     * se marca una línea exenta. Si el rechazo de SIFEN menciona
     * taxRate/exenta, este es el primer sospechoso.
     */
    private static function lineTax(array $item, int $index): float
    {
        $taxRate = self::assertTaxRate($item, $index);
        if ($taxRate <= 0) {
            return 0.0;
        }
        $total = (float) ($item['total'] ?? ((float) ($item['unitPrice'] ?? 0) * (float) ($item['quantity'] ?? 0)));
        return round($total * $taxRate / (100 + $taxRate));
    }

    /** @return int 10, 5 o 0 */
    private static function assertTaxRate(array $item, int $index): int
    {
        $taxRate = (int) ($item['taxRate'] ?? 10);
        if (!in_array($taxRate, [10, 5, 0], true)) {
            throw new \RuntimeException("Item #$index tiene taxRate inválido ($taxRate) — solo se admite 10, 5 o 0.");
        }
        return $taxRate;
    }

    /**
     * Convierte UNA línea de la venta en las líneas que van al documento,
     * garantizando que `Σ(quantity × unitPriceWithTax)` dé exactamente el
     * total de la línea en los decimales de la moneda.
     *
     * ── El problema ──────────────────────────────────────────────────
     *
     * El payload NO lleva un total por ítem: SIFEN lo recalcula como
     * `quantity × unitPriceWithTax`. Con un unitario redondeado (a 8
     * decimales antes, a 0 ahora porque PYG no admite centavos) esa
     * multiplicación no vuelve al total: 10.000 Gs en 3 unidades da 3.333,33
     * y 3 × 3.333 = 9.999. Un guaraní de diferencia entre lo que el
     * documento declara y lo que se cobró.
     *
     * ── La solución ──────────────────────────────────────────────────
     *
     * Cuando la división NO es exacta, la línea se parte en dos: (qty-1)
     * unidades al unitario redondeado hacia abajo y 1 unidad que absorbe el
     * resto. 2 × 3.333 + 1 × 3.334 = 10.000, exacto, y cada unitario sigue
     * siendo un entero declarable. El comprobante muestra dos renglones del
     * mismo producto, que es el costo aceptado de que el fisco recalcule
     * multiplicando.
     *
     * La partición solo aplica con cantidad ENTERA ≥ 2. Con cantidad
     * fraccionaria (2,5 kg) no hay "una unidad" que separar, así que se
     * declara el unitario con la precisión necesaria y el guard de `build()`
     * —tolerancia de 1 unidad de moneda— absorbe el resto. No se inventa una
     * partición por peso: cambiaría lo que dice el comprobante sobre lo que
     * se entregó.
     *
     * Idempotente respecto del caso feliz: si la división es exacta (el caso
     * de lejos más común, un precio de lista por una cantidad entera)
     * devuelve la línea tal cual, sin partir nada.
     *
     * @return array<int,array<string,mixed>> Una o dos líneas.
     */
    private static function fiscalLines(array $item, int $decimals): array
    {
        $quantity = (float) ($item['quantity'] ?? 0);
        $unitPrice = (float) ($item['unitPrice'] ?? 0);
        $total = (float) ($item['total'] ?? ($unitPrice * $quantity));

        if ($quantity <= 0) {
            return [$item]; // build() ya filtró estos casos; defensivo.
        }

        $exactUnit = round($total / $quantity, $decimals);
        if (self::sameMoney($exactUnit * $quantity, $total, $decimals)) {
            // La división cierra: se declara el unitario en la precisión de
            // la moneda (no el de 8 decimales que venía del caller).
            $item['unitPrice'] = $exactUnit;
            return [$item];
        }

        $isWholeQty = abs($quantity - round($quantity)) < 1e-9;
        if (!$isWholeQty || $quantity < 2) {
            $item['unitPrice'] = $exactUnit;
            return [$item];
        }

        $wholeQty = (int) round($quantity);
        $step = 10 ** -$decimals;
        // floor a la precisión de la moneda: el resto queda SIEMPRE positivo
        // y se acumula en la última unidad, nunca al revés.
        $baseUnit = floor($total / $wholeQty / $step) * $step;
        $baseUnit = round($baseUnit, $decimals);

        // Caso degenerado: el total no alcanza a una unidad de moneda por
        // unidad vendida (1 Gs repartido en 3). Partir daría renglones con
        // precio unitario CERO, que es peor que la diferencia de redondeo —
        // se declara una sola línea y el guard de build() decide si pasa.
        if ($baseUnit <= 0) {
            $item['unitPrice'] = $exactUnit;
            return [$item];
        }

        $lastUnit = round($total - $baseUnit * ($wholeQty - 1), $decimals);

        $head = $item;
        $head['quantity'] = $wholeQty - 1;
        $head['unitPrice'] = $baseUnit;
        $head['total'] = round($baseUnit * ($wholeQty - 1), $decimals);

        $tail = $item;
        $tail['quantity'] = 1;
        $tail['unitPrice'] = $lastUnit;
        $tail['total'] = $lastUnit;

        return [$head, $tail];
    }

    private static function sameMoney(float $a, float $b, int $decimals): bool
    {
        return abs(round($a, $decimals) - round($b, $decimals)) < (10 ** -($decimals + 3));
    }

    /**
     * Una línea del payload. NO calcula el IVA: eso lo hace `lineTax()` sobre
     * la línea ORIGINAL, antes de que `fiscalLines()` la parta — si cada
     * pedazo redondeara su propio IVA la suma podría correrse.
     *
     * @return array<string,mixed>
     */
    private function buildItem(array $item, int $index, int $exchangeRate): array
    {
        $unitPrice = (float) ($item['unitPrice'] ?? 0);
        $quantity = (float) ($item['quantity'] ?? 0);
        $taxRate = self::assertTaxRate($item, $index);

        // Nombres y campos verificados contra la API real (2026-07-30). No hay
        // campo `total` por item — SIFEN lo deriva de quantity * unitPriceWithTax.
        return [
            'internalCode'                       => '-',
            'description'                        => (string) ($item['description'] ?? ''),
            // Verificado: otros valores de measurementUnitCode rompen la
            // serialización XML del lado de Factomate.
            'measurementUnitCode'                => 0,
            'quantity'                           => $quantity,
            'informationOfInterest'              => '',
            'unitPriceWithTax'                   => $unitPrice,
            // Debe ser igual al exchangeRate del documento (SIFEN valida
            // consistencia); 0 porque el documento siempre va en PYG acá.
            'itemExchangeRate'                   => $exchangeRate,
            'itemUnitPriceDiscountWithTax'       => 0,
            'itemDiscountPercentage'             => 0,
            'itemUnitPriceGlobalDiscountWithTax' => 0,
            'itemUnitPriceAdvanceWithTax'        => 0,
            'itemUnitPriceGlobalAdvanceWithTax'  => 0,
            'taxImpact'                          => 0,
            'taxedProportion'                    => 100,
            'taxRate'                            => $taxRate,
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
                // Con RUC la operación es B2B. Estaba fijo en B2C ("único caso
                // soportado hoy") copiando la implementación de referencia,
                // que sí lo tiene hardcodeado en su document-builder — pero su
                // PROPIO resolver de clientes deriva `hasRuc ? 1 : 2`, que es
                // la regla correcta y la que se aplica acá.
                'operationType'              => self::OPERATION_TYPE_B2B,
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
                // Sin RUC no hay contribuyente del otro lado: B2C.
                'operationType'              => self::OPERATION_TYPE_B2C,
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
            'operationType'              => self::OPERATION_TYPE_B2C,
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
