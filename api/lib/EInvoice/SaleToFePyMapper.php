<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Venta de Punto → documento electrónico de FE-PY (el motor PROPIO).
 *
 * Toma EXACTAMENTE el mismo `$sale` que `SaleToInvoiceMapper` —lo arma
 * `EInvoiceService::buildSaleArrayForMapper()`, uno solo, sin ramificar por
 * proveedor— y lo traduce al otro idioma.
 *
 * ── Por qué es un mapper aparte y no una rama del de Factomate ───────────
 *
 * Porque no es el mismo payload con otros nombres: es otro modelo. Factomate
 * expone una API propia (`electronicDocumentItems`, `unitPriceWithTax`,
 * `paymentMethodCode`, y hasta sus typos `ammount`/`aditionalInformation`,
 * que se respetan porque son su contrato). FE-PY habla el JSON del motor
 * `xmlgen` —el bloque `data` de SIFEN v150— que es la estructura del
 * documento fiscal en sí: `items[].ivaTipo/ivaProporcion/iva`,
 * `condicion.entregas[]`, `cliente.contribuyente`. Los campos no se
 * corresponden uno a uno y las reglas de validación tampoco.
 *
 * Lo que SÍ se comparte es la aritmética fiscal, y se comparte de verdad
 * (llamadas a `SaleToInvoiceMapper::…`, no copiada): `fiscalLines()`,
 * `lineTax()`, `assertTaxRate()`, `currencyDecimals()`,
 * `resolveTransactionType()` y `resolveSecurityCode()`. Ver el comentario en
 * ese archivo para el porqué.
 *
 * ── Diferencias estructurales con Factomate que hay que conocer ──────────
 *
 *  1. **El bloque del EMISOR no viaja.** FE-PY arma `params` (RUC, razón
 *     social, timbrado, establecimientos, actividades) leyéndolo de su fila
 *     de tenant, y el caller no puede pisarlo. Acá solo va el documento.
 *
 *  2. **El TIMBRADO tampoco viaja.** Es del tenant, uno solo. Lo que sí va
 *     por documento es el par establecimiento/punto — que es lo que en Punto
 *     identifica a la CAJA (`context/29`: cada caja es un punto de
 *     expedición) y sale de `register.registerInvoicePrefix`.
 *
 *  3. **El NÚMERO lo asigna FE-PY.** Su Zod acepta `numero` y su servicio lo
 *     PISA con un contador propio por (tenant, tipo, est, punto). Se manda
 *     igual —cuesta nada y documenta cuál era el correlativo del ticket—
 *     pero el que vale es el de ellos, y la divergencia queda registrada por
 *     `EInvoiceService::cdcMismatchFor()` en `numbering_mismatch` (mig 204).
 *     Es una decisión ABIERTA del owner, no un detalle: hoy el invariante
 *     "el número del DE es el que salió impreso en el ticket" no se puede
 *     garantizar con este proveedor.
 *
 *  4. **El IVA se declara por ítem, no como total.** No hay campo `tax` del
 *     documento: SIFEN lo deriva de `iva` + `ivaProporcion` línea por línea.
 *     `lineTax()` se sigue usando igual, pero solo para el guard interno.
 *
 * ── Lo que NO está verificado contra la API en vivo ──────────────────────
 *
 * Todo este mapper se escribió leyendo el código de FE-PY (sus schemas Zod y
 * su validador `jsonDeMainValidate`), sin poder emitir un documento de
 * prueba. Los puntos marcados `SIN VERIFICAR` en los comentarios de abajo
 * son los primeros sospechosos ante un rechazo — están señalados uno por uno
 * en vez de con un aviso genérico, para que el que debuguee no tenga que
 * releer el archivo entero.
 */
final class SaleToFePyMapper
{
    /** Tipos de documento de SIFEN. FE-PY acepta 1, 4, 5, 6, 7. */
    private const DOC_FACTURA = 1;
    private const DOC_NOTA_CREDITO = 5;

    /** `cliente.tipoOperacion` — 1 B2B, 2 B2C, 3 B2G, 4 B2F. */
    private const OPERATION_TYPE_B2B = 1;
    private const OPERATION_TYPE_B2C = 2;

    /**
     * `cliente.documentoTipo` (Tabla 3 de SIFEN, la que usa xmlgen):
     * 1 cédula paraguaya, 2 pasaporte, 3 cédula extranjera,
     * 4 carnet de residencia, 5 innominado, 6 tarjeta diplomática,
     * 9 no especificado.
     */
    private const DOC_TYPE_CEDULA = 1;
    private const DOC_TYPE_INNOMINADO = 5;

    /**
     * Tabla 3 SET de Punto (`ContactService::ID_TYPE_*`, 11-17) →
     * `documentoTipo` de SIFEN. Se usan literales por el mismo motivo que en
     * `SaleToInvoiceMapper::SET_TO_FACTOMATE_ID_TYPE`: es una tabla de
     * traducción, no un uso de la constante.
     *
     * Diferencia con Factomate que vale la pena: **el 14 (cédula extranjera)
     * SÍ tiene destino acá** (código 3). Con Factomate ese contacto abortaba
     * la emisión porque su catálogo propio no tenía un equivalente verificado.
     *
     * 11 (RUC) no aparece porque ese contacto va por el camino
     * `contribuyente`, que no pasa por acá. 17 (identificación tributaria)
     * queda AFUERA a propósito: su destino sería el código 9 ("no
     * especificado"), que además exige `documentoTipoDescripcion` — un
     * documento fiscal no se emite con un tipo de identificación adivinado.
     */
    private const SET_TO_SIFEN_ID_TYPE = [
        12 => self::DOC_TYPE_CEDULA,      // CÉDULA DE IDENTIDAD → 1
        13 => 2,                          // PASAPORTE → 2
        14 => 3,                          // CÉDULA EXTRANJERA → 3
        15 => self::DOC_TYPE_INNOMINADO,  // SIN NOMBRE → 5
        16 => 6,                          // DIPLOMÁTICO → 6 (tarjeta diplomática de exoneración fiscal)
    ];

    /** Límite legal para facturar a consumidor final sin identificar. */
    private const INNOMINADO_LIMITE_GS = 1_000_000;

    /**
     * `items[].unidadMedida` — 77 = "UNI (unidad)" en la tabla de unidades de
     * medida de SIFEN, y es lo que usa el propio playground de FE-PY. No es
     * el 0 que manda el mapper de Factomate: ese 0 es un valor de SU API
     * (verificado allá porque otros valores rompían su serialización XML), no
     * un código SIFEN. Punto no modela unidad de medida por ítem todavía; el
     * día que lo haga, este es el campo.
     */
    private const UNIDAD_MEDIDA_UNIDAD = 77;

    /** `condicion.tipo`: 1 contado, 2 crédito. */
    private const CONDICION_CONTADO = 1;
    private const CONDICION_CREDITO = 2;

    /** `condicion.credito.tipo`: 1 plazo, 2 cuota. */
    private const CREDITO_PLAZO = 1;

    /** `entregas[].tipo` de la Tabla 22 que necesitan sub-bloque propio. */
    private const PAGO_CHEQUE = 2;
    private const PAGO_TARJETA_CREDITO = 3;
    private const PAGO_TARJETA_DEBITO = 4;
    private const PAGO_OTRO = 99;

    /**
     * @param array<string,mixed> $sale   Mismo shape que SaleToInvoiceMapper (ver su docblock).
     * @param array{establecimiento:string,punto:string} $point Caja de la venta = punto de expedición.
     * @param array<string,mixed> $config `einvoice_account.config` (mapa de medios de pago, kill-switches).
     * @param string $issuedDate Fecha de la OPERACIÓN, naive `Y-m-d\TH:i:s` en el reloj del tenant.
     * @param string $idempotencyKey `einvoicedocid` — viaja como header, no como campo del documento.
     * @return array<string,mixed>
     * @throws \RuntimeException con mensaje en castellano apto para `error_message`.
     */
    public function build(array $sale, array $point, array $config, string $issuedDate, string $idempotencyKey): array
    {
        $total = (float) ($sale['total'] ?? 0);
        $operationCondition = (int) ($sale['operationCondition'] ?? 0);
        $documentType = (int) ($sale['documentType'] ?? self::DOC_FACTURA);
        $isCreditNote = $documentType === self::DOC_NOTA_CREDITO;

        $items = $sale['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new \RuntimeException('La venta no tiene items — no se puede armar la factura electrónica.');
        }

        // Moneda: mismo guard, mismo motivo y mismos mensajes que el mapper de
        // Factomate. No se relaja por ser otro proveedor — declarar en
        // guaraníes un monto que se cobró en otra moneda está mal
        // independientemente de quién firme el XML. (FE-PY sí soporta moneda
        // extranjera vía `condicionTipoCambio`/`cambio`; habilitarlo es una
        // feature con su propia verificación, no un efecto colateral de este
        // slice.)
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

        $establecimiento = self::padCode($point['establecimiento'] ?? '', 'establecimiento');
        $punto           = self::padCode($point['punto'] ?? '', 'punto de expedición');

        $moneyDecimals = SaleToInvoiceMapper::currencyDecimals($currency);

        $itemsPayload = [];
        $declaredSum  = 0.0;
        foreach ($items as $i => $item) {
            $item = (array) $item;
            // `lineTax()` se llama sobre la línea ORIGINAL —antes de partirla—
            // igual que en el otro mapper. Acá el IVA no viaja como total del
            // documento (SIFEN lo deriva por ítem), pero la llamada valida la
            // tasa y mantiene una sola definición de "qué tasas se admiten".
            SaleToInvoiceMapper::lineTax($item, (int) $i);

            foreach (SaleToInvoiceMapper::fiscalLines($item, $moneyDecimals) as $line) {
                $itemsPayload[] = $this->buildItem($line, (int) $i);
                $declaredSum += round((float) $line['quantity'] * (float) $line['unitPrice'], $moneyDecimals);
            }
        }

        // Mismo invariante que en Factomate, y por la misma razón física:
        // el payload no lleva total por ítem, SIFEN lo recalcula
        // multiplicando, y esa es la cuenta que puede rechazar.
        if (abs($declaredSum - $total) > 1.0) {
            throw new \RuntimeException(
                "Lo que declaran los items (cantidad x unitario = $declaredSum) no da el total de la venta " .
                "($total) — revisar redondeo antes de emitir, no se puede facturar así."
            );
        }

        $payload = [
            FePyProvider::IDEMPOTENCY_PAYLOAD_KEY => $idempotencyKey,

            'tipoDocumento'   => $documentType,
            'establecimiento' => $establecimiento,
            'punto'           => $punto,
            // Los 9 dígitos del componente 10 del CDC, CONGELADOS por el
            // caller en `einvoice_document.security_code` (mig 205). FE-PY lo
            // acepta y lo mete en el CDC tal cual — así que el invariante
            // "un reintento no cambia el CDC" se conserva con este proveedor
            // igual que con el otro. Su regex es `^\d{1,9}$`, que admite los
            // ceros a la izquierda que el generador puede producir.
            'codigoSeguridadAleatorio' => SaleToInvoiceMapper::resolveSecurityCode($sale),
            // Naive `YYYY-MM-DDTHH:MM:SS` en hora local del tenant. NUNCA UTC
            // con sufijo `Z`: el propio código de FE-PY documenta un rechazo
            // real de SIFEN (código 1004, "fecha y hora de la firma digital es
            // adelantada") causado por un `toISOString()`. Y es la fecha de la
            // OPERACIÓN, no la del envío — invariante del repo, el ticket que
            // el cliente ya tiene en la mano dice esa fecha.
            'fecha'           => $issuedDate,
            'tipoEmision'     => 1, // 1 = normal (2 = contingencia, no implementado).
            'tipoTransaccion' => SaleToInvoiceMapper::resolveTransactionType($items),
            'tipoImpuesto'    => 1, // 1 = IVA.
            'moneda'          => $currency,
            'cliente'         => $this->buildClient((array) ($sale['client'] ?? []), $total, $operationCondition),
            'condicion'       => $this->buildCondicion($sale, $total, $config, $operationCondition, $currency),
            'items'           => $itemsPayload,
        ];

        // El correlativo propio, cuando existe. Se INSERTA en vez de ir en el
        // literal de arriba porque cuando no hay número que defender (NC, o
        // el kill-switch `legacyAutoNumbering`) la clave no puede viajar
        // vacía: su Zod es `^\d{1,7}$` y un `''` sería un 422. Ausente
        // significa "numera el proveedor", que es exactamente el caso.
        // Ver el punto 3 del docblock: FE-PY lo pisa igual.
        $numero = $this->resolveDocumentNumber($sale, $config, $documentType);
        if ($numero !== '') {
            $payload['numero'] = $numero;
        }

        if (!$isCreditNote) {
            // `factura.presencia`: 1 = operación presencial. Es el único valor
            // que Punto puede afirmar hoy — el POS es una caja física. Cuando
            // haya venta online (`presencia: 2`) va a salir del canal de la
            // venta, no de un default.
            $payload['factura'] = ['presencia' => 1];
        } else {
            $associatedCdc = trim((string) ($sale['associatedCdc'] ?? ''));
            if ($associatedCdc === '') {
                throw new \RuntimeException(
                    'La nota de crédito no tiene el CDC de la factura original — no se puede emitir sin la referencia.'
                );
            }
            // `motivo: 1` = devolución. Es lo que una NC de Punto documenta
            // SIEMPRE hoy: la origina `ReturnService::create`, o sea una
            // devolución de mercadería. El día que haya NC por descuento o
            // por corrección de datos, el motivo sale de la operación.
            $payload['notaCreditoDebito'] = ['motivo' => 1];
            $payload['documentoAsociado'] = [
                // `formato: 1` = electrónico (se referencia por CDC).
                'formato' => 1,
                'cdc'     => $associatedCdc,
                // `tipo: 1` = factura. La NC de Punto siempre corrige una
                // factura.
                'tipo'    => 1,
            ];
        }

        return $payload;
    }

    /**
     * Correlativo propio. MISMA regla que `SaleToInvoiceMapper` —el número
     * del documento electrónico es el que la caja ya congeló en la venta
     * (`transaction.invoiceNo`, mig 145) y salió impreso en el ticket— con la
     * salvedad, documentada en el punto 3 del docblock de la clase, de que
     * FE-PY lo pisa.
     *
     * Se manda igual y el guard de "sin número congelado no se emite" se
     * conserva: relajarlo acá porque "total lo ignoran" convertiría este
     * proveedor en el camino por el que una venta sin numeración propia sale
     * facturada, y el día que FE-PY acepte nuestro número (o que el owner
     * decida lo contrario) el agujero ya estaría abierto y nadie lo miraría.
     *
     * Devuelve string: su Zod pide `^\d{1,7}$`, no un entero.
     */
    private function resolveDocumentNumber(array $sale, array $config, int $documentType): string
    {
        if (!empty($config['legacyAutoNumbering']) || $documentType === self::DOC_NOTA_CREDITO) {
            // Sin correlativo propio que defender: se omite el campo y queda
            // explícito que numera el proveedor. (No existe el `-1` de
            // Factomate: acá el campo simplemente no va.)
            return '';
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
        if ($number > 9_999_999) {
            throw new \RuntimeException(
                "El correlativo de la venta ($number) excede los 7 dígitos que admite un documento electrónico."
            );
        }

        return str_pad((string) $number, 7, '0', STR_PAD_LEFT);
    }

    /**
     * Una línea del documento.
     *
     * El IVA en el modelo de SIFEN son TRES campos que tienen que ser
     * coherentes entre sí, y el validador de xmlgen los cruza:
     *
     *   `ivaTipo`        1 gravado, 2 exonerado (art. 100), 3 exento, 4 parcial
     *   `iva`            la tasa: solo 0, 5 o 10
     *   `ivaProporcion`  proporción gravada 0-100
     *
     * Las reglas que aplican acá: con `ivaTipo 1` la proporción tiene que ser
     * 100 y la tasa 5 o 10; con `ivaTipo 2|3` la proporción tiene que ser 0 y
     * la tasa 0. Punto modela una sola tasa por ítem (10, 5 o 0), así que el
     * caso parcial (4) no se produce.
     *
     * `taxRate = 0` se declara **exento (3)**, no exonerado (2): son cosas
     * distintas ante la SET —la exoneración es del artículo 100 de la ley
     * 6380— y "exenta" es lo que significa la tasa 0 en el catálogo de Punto
     * (ver `SaleToInvoiceMapper::lineTax`). SIN VERIFICAR contra un documento
     * real: si SIFEN rechaza una línea exenta, este es el primer sospechoso.
     *
     * `precioUnitario` va CON IVA incluido, que es como Punto guarda los
     * precios (y como el ticket los muestra). xmlgen espera exactamente eso:
     * su `dPUniProSer` es el precio unitario y el IVA se deriva de la tasa.
     *
     * @param array<string,mixed> $item Línea ya pasada por `fiscalLines()`.
     * @return array<string,mixed>
     */
    private function buildItem(array $item, int $index): array
    {
        $taxRate = SaleToInvoiceMapper::assertTaxRate($item, $index);
        $gravado = $taxRate > 0;

        return [
            // `codigo` es obligatorio para xmlgen. Punto no manda su itemId:
            // es un uuid interno que no le dice nada a nadie en el KuDE, y el
            // campo tiene tope de largo. Se declara un guion, igual criterio
            // que el `internalCode: '-'` del mapper de Factomate.
            'codigo'        => '-',
            'descripcion'   => (string) ($item['description'] ?? ''),
            'unidadMedida'  => self::UNIDAD_MEDIDA_UNIDAD,
            'cantidad'      => (float) ($item['quantity'] ?? 0),
            'precioUnitario' => (float) ($item['unitPrice'] ?? 0),
            // 0 porque el documento va en PYG (ver el guard de moneda).
            'cambio'        => 0,
            // Los descuentos ya están aplicados en el neto de la línea que
            // arma `buildSaleArrayForMapper()`: declararlos otra vez acá los
            // restaría dos veces.
            'descuento'     => 0,
            'anticipo'      => 0,
            'ivaTipo'       => $gravado ? 1 : 3,
            'ivaProporcion' => $gravado ? 100 : 0,
            'iva'           => $taxRate,
        ];
    }

    /**
     * Receptor. Los tres casos fiscales de Punto (`nature`) contra los tres
     * caminos de xmlgen, que se distinguen por el booleano `contribuyente`.
     *
     * Los dos guards de negocio son los MISMOS que en el mapper de Factomate
     * y valen igual acá: no se factura a innominado por encima del millón de
     * guaraníes, y no se factura a crédito a un cliente sin identificar.
     *
     * @param array<string,mixed> $rawClient
     * @return array<string,mixed>
     */
    private function buildClient(array $rawClient, float $total, int $operationCondition): array
    {
        $nature = (string) ($rawClient['nature'] ?? 'innominado');
        $ruc = trim((string) ($rawClient['ruc'] ?? ''));
        $ci  = trim((string) ($rawClient['ci'] ?? ''));

        $isIdentified = $nature !== 'innominado' && ($ruc !== '' || $ci !== '');

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

        // Datos de contacto y geografía. `pais`/`paisDescripcion` los valida
        // xmlgen contra su tabla de países en TODOS los casos. Los códigos de
        // departamento/distrito/ciudad del RECEPTOR quedan afuera a propósito:
        // Punto guarda la dirección del contacto como texto libre y no tiene
        // los códigos SIFEN, y xmlgen solo emite ese bloque si se lo mandan.
        // Inventar un "Asunción/Capital" por defecto sería declararle a la SET
        // un domicilio que nadie verificó.
        $common = [
            'pais'            => 'PRY',
            'paisDescripcion' => 'Paraguay',
            'direccion'       => (string) ($rawClient['address'] ?? ''),
            'telefono'        => (string) ($rawClient['phone'] ?? ''),
            'email'           => (string) ($rawClient['email'] ?? ''),
        ];

        if ($nature === 'contribuyente') {
            if ($ruc === '') {
                throw new \RuntimeException('Falta el RUC del cliente — es obligatorio para facturar a un contribuyente.');
            }
            return $common + [
                'contribuyente' => true,
                'ruc'           => self::rucWithCheckDigit($ruc),
                // Con RUC del otro lado, la operación es B2B.
                'tipoOperacion' => self::OPERATION_TYPE_B2B,
                // 1 = persona física, 2 = persona jurídica. Punto no distingue
                // hoy (no hay campo), y 1 es lo que la implementación de
                // referencia usa para un receptor con RUC. SIN VERIFICAR
                // contra un rechazo real; si aparece uno que mencione
                // `iTiContRec`, es acá.
                'tipoContribuyente' => 1,
                'razonSocial'   => self::requireName($rawClient['name'] ?? '', 'del contribuyente'),
            ];
        }

        if ($nature === 'fisica') {
            if ($ci === '') {
                throw new \RuntimeException(
                    'Falta el documento de identidad del cliente — es obligatorio para facturar a una persona física sin RUC.'
                );
            }
            // Default 12 (cédula) para contactos anteriores al campo
            // `contactIdType`, igual que el mapper de Factomate.
            $idType = (int) ($rawClient['idType'] ?? 12);
            return $common + [
                'contribuyente'   => false,
                'tipoOperacion'   => self::OPERATION_TYPE_B2C,
                'documentoTipo'   => $this->mapIdType($idType),
                // xmlgen rechaza `.` y `/` en el número de documento.
                'documentoNumero' => self::cleanDocumentNumber($ci),
                'razonSocial'     => self::requireName($rawClient['name'] ?? '', 'de la persona'),
            ];
        }

        // Innominado (consumidor final). `documentoNumero: '0'` y
        // `razonSocial: 'Sin Nombre'` son lo que el propio motor fuerza para
        // `documentoTipo: 5` — se mandan explícitos en vez de confiar en que
        // los complete, porque su `razonSocial` se re-asigna DESPUÉS de esa
        // corrección (o sea que el nuestro es el que queda) y además tiene un
        // mínimo de 4 caracteres que un nombre vacío no cumple.
        return $common + [
            'contribuyente'   => false,
            'tipoOperacion'   => self::OPERATION_TYPE_B2C,
            'documentoTipo'   => self::DOC_TYPE_INNOMINADO,
            'documentoNumero' => '0',
            'razonSocial'     => 'Sin Nombre',
        ];
    }

    /**
     * Tabla 3 SET → `documentoTipo` de SIFEN.
     *
     * @throws \RuntimeException si el código no tiene destino. Se aborta la
     *         emisión en vez de adivinar: el tipo de documento del receptor
     *         es un dato declarado ante la SET.
     */
    private function mapIdType(int $setIdType): int
    {
        if (!isset(self::SET_TO_SIFEN_ID_TYPE[$setIdType])) {
            throw new \RuntimeException(
                "El tipo de documento $setIdType (Tabla 3 SET) no tiene equivalente en el documento electrónico. " .
                'Tipos soportados: cédula (12), pasaporte (13), cédula extranjera (14), sin nombre (15), diplomático (16).'
            );
        }
        return self::SET_TO_SIFEN_ID_TYPE[$setIdType];
    }

    /**
     * Condición de la operación: contado con sus entregas, o crédito con su
     * plazo. xmlgen la exige SIEMPRE (su Zod la marca opcional, su validador
     * no) — omitirla da un 422 que no dice que falta esto.
     *
     * @param array<string,mixed> $sale
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function buildCondicion(array $sale, float $total, array $config, int $operationCondition, string $currency): array
    {
        $entregas = $this->buildEntregas($sale, $total, $config, $operationCondition, $currency);

        if ($operationCondition !== 1) {
            if ($entregas === []) {
                // No debería pasar: `buildEntregas()` ya cae al medio por
                // defecto con el total cuando la venta no registró pagos.
                throw new \RuntimeException('La venta al contado no tiene medios de pago declarables.');
            }
            return ['tipo' => self::CONDICION_CONTADO, 'entregas' => $entregas];
        }

        $credit = (array) ($sale['credit'] ?? []);
        $condicion = [
            'tipo'    => self::CONDICION_CREDITO,
            'credito' => $this->buildCredito($credit, $sale),
        ];
        if ($entregas !== []) {
            // Entrega inicial de una venta a crédito. Va junto al bloque de
            // crédito, no en lugar de él.
            $condicion['entregas'] = $entregas;
        }
        return $condicion;
    }

    /**
     * Crédito. Punto modela PLAZO (una fecha de vencimiento), no cuotas —
     * `buildSaleArrayForMapper()` arma `credit.creditOperationCondition = 0`
     * y una `creditDeadline`. Se traduce al `credito.tipo: 1` de xmlgen, cuyo
     * campo `plazo` es un TEXTO de 2 a 15 caracteres, no una fecha.
     *
     * Se declara "N días" calculado contra la fecha de la operación, que es
     * lo que el campo significa. Mandar la fecha cruda (`2026-10-07`) entraría
     * por largo pero diría otra cosa.
     *
     * El camino de CUOTAS (`credito.tipo: 2` + `infoCuotas[]`) no se
     * implementa porque hoy ninguna venta de Punto llega acá con cuotas; si
     * alguna trae `feeNumbers`, se corta con un error explícito en vez de
     * declararla como plazo simple.
     *
     * @param array<string,mixed> $credit
     * @param array<string,mixed> $sale
     * @return array<string,mixed>
     */
    private function buildCredito(array $credit, array $sale): array
    {
        if (!empty($credit['feeNumbers']) || !empty($credit['fees'])) {
            throw new \RuntimeException(
                'La venta a crédito está en cuotas y el documento electrónico todavía no declara cuotas ' .
                'con este proveedor — no se emite para no declarar un plazo simple sobre una venta financiada.'
            );
        }

        $deadline = trim((string) ($credit['creditDeadline'] ?? $credit['deadline'] ?? ''));
        if ($deadline === '') {
            throw new \RuntimeException('Venta a crédito con plazo — falta la fecha/plazo de vencimiento (creditDeadline).');
        }

        return [
            'tipo'  => self::CREDITO_PLAZO,
            'plazo' => self::plazoText($deadline, (string) ($sale['transactionDate'] ?? '')),
        ];
    }

    /**
     * "N días" entre la operación y el vencimiento. Tope de 15 caracteres del
     * campo: `"9999 dias"` mide 9, así que no hay forma de pasarse con un
     * plazo real. Sin tildes a propósito — el campo viaja al XML y no hay
     * ganancia en arriesgar un problema de codificación por una tilde.
     */
    private static function plazoText(string $deadline, string $operationDate): string
    {
        $to = strtotime($deadline);
        $from = $operationDate !== '' ? strtotime($operationDate) : time();
        if ($to === false || $from === false) {
            return '30 dias'; // El caller ya garantiza una fecha; esto es defensivo.
        }
        $days = (int) max(1, round(($to - $from) / 86400));
        return min($days, 9999) . ' dias';
    }

    /**
     * `condicion.entregas[]` — una entrada por medio de pago usado.
     *
     * Reusa el MISMO mapa de configuración que el mapper de Factomate
     * (`config.paymentMethodMap[taxonomyId] → código`, con
     * `defaultPaymentMethodCode` como fallback) y no uno paralelo: los
     * códigos son la Tabla 22 de SIFEN en los dos casos, así que lo que el
     * comercio ya configuró sigue valiendo si migra de proveedor. Ese es
     * justamente el sentido de que el cutover sea por tenant.
     *
     * ── Los sub-bloques que Punto no puede llenar (SIN VERIFICAR) ────────
     *
     * xmlgen exige `infoTarjeta` para los códigos 3/4 (tarjeta) e
     * `infoCheque` para el 2 (cheque). Punto NO guarda la marca de la
     * tarjeta, el código de autorización ni el número del cheque en la
     * transacción, así que no hay de dónde sacarlos.
     *
     * Lo que se hace: declarar la tarjeta como `tipo: 99` (Otro) con la
     * descripción del medio de pago tal como lo llama el comercio. Es
     * verdadero —no sabemos la marca— en vez de inventar "Visa", que sería
     * declararle a la SET un dato falso. Para el cheque se manda el bloque
     * con lo poco que hay; si el motor lo rechaza, el documento queda en
     * `error` con SU mensaje, que es más útil que uno nuestro adivinando.
     *
     * Ninguna de las dos cosas está verificada contra un documento real. Son
     * los primeros sospechosos si un rechazo menciona `gPagTarCD` o `gPagCheq`.
     *
     * @param array<string,mixed> $sale
     * @param array<string,mixed> $config
     * @return array<int,array<string,mixed>>
     */
    private function buildEntregas(array $sale, float $total, array $config, int $operationCondition, string $currency): array
    {
        $lines = $sale['payments'] ?? [];
        $map = (array) ($config['paymentMethodMap'] ?? []);
        $default = (int) ($config['defaultPaymentMethodCode'] ?? 1);

        /** @var array<int,array{amount:float,label:string}> $byCode */
        $byCode = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $amount = (float) ($line['amount'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $methodId = $line['methodId'] ?? null;
                $code = ($methodId !== null && isset($map[$methodId])) ? (int) $map[$methodId] : $default;
                $label = trim((string) ($line['methodKey'] ?? ''));
                $byCode[$code]['amount'] = ($byCode[$code]['amount'] ?? 0.0) + $amount;
                if (!isset($byCode[$code]['label']) || $byCode[$code]['label'] === '') {
                    $byCode[$code]['label'] = $label;
                }
            }
        }

        if ($operationCondition === 1) {
            // Crédito: lo que haya es la entrega inicial. Puede ser nada.
            return $this->entregasPayload($byCode, $currency);
        }

        if ($byCode === []) {
            // Venta al contado sin pagos registrados (ventas migradas, o un
            // flujo que no persistió `transactionPaymentType`): se declara el
            // total con el medio por defecto. La alternativa sería no emitir
            // una venta ya cobrada.
            return [self::entregaLine($default, $total, '', $currency)];
        }

        $sum = 0.0;
        foreach ($byCode as $entry) {
            $sum += $entry['amount'];
        }
        $diff = $total - $sum;
        if (abs($diff) > max(1.0, (float) count($byCode))) {
            throw new \RuntimeException(
                "Los pagos registrados suman $sum y el total de la venta es $total — " .
                'no se emite un documento cuyos medios de pago no cuadran con el total.'
            );
        }
        if ($diff != 0.0) {
            // Residuo de redondeo al código de mayor monto, para que la suma
            // cierre exacto — mismo criterio que el ajuste per-item.
            uasort($byCode, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
            $biggest = array_key_first($byCode);
            $byCode[$biggest]['amount'] += $diff;
        }

        return $this->entregasPayload($byCode, $currency);
    }

    /**
     * @param array<int,array{amount:float,label:string}> $byCode
     * @return array<int,array<string,mixed>>
     */
    private function entregasPayload(array $byCode, string $currency): array
    {
        $out = [];
        foreach ($byCode as $code => $entry) {
            $out[] = self::entregaLine((int) $code, $entry['amount'], (string) ($entry['label'] ?? ''), $currency);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function entregaLine(int $code, float $amount, string $label, string $currency): array
    {
        $line = [
            'tipo' => $code,
            // `monto` como STRING: es como lo documenta xmlgen y como lo manda
            // su propio playground. En PYG no hay decimales, así que el
            // entero es exacto.
            'monto'  => (string) (int) round($amount),
            'moneda' => $currency,
            'cambio' => 0,
        ];

        if ($code === self::PAGO_OTRO) {
            $line['tipoDescripcion'] = $label !== '' ? mb_substr($label, 0, 50) : 'Otro medio de pago';
        }

        if ($code === self::PAGO_TARJETA_CREDITO || $code === self::PAGO_TARJETA_DEBITO) {
            // Ver el docblock de buildEntregas(): 99 = "Otro" con descripción,
            // porque la marca de la tarjeta no está en la venta.
            $line['infoTarjeta'] = [
                'tipo'            => 99,
                'tipoDescripcion' => $label !== '' ? mb_substr($label, 0, 50) : 'Tarjeta',
                // 1 = POS. Es el canal real de una tarjeta cobrada en la caja.
                'medioPago'       => 1,
            ];
        }

        if ($code === self::PAGO_CHEQUE) {
            $line['infoCheque'] = [
                'numeroCheque' => '',
                'banco'        => '',
            ];
        }

        return $line;
    }

    /**
     * RUC en el formato que exige xmlgen: cuerpo + `-` + dígito verificador.
     * Su validador parte por el guion y verifica el DV, así que un RUC sin
     * guion es un rechazo seguro.
     *
     * Si el contacto lo tiene guardado sin DV, se calcula con
     * `Cdc::checkDigit()` — que NO es inventar un dato: es el módulo 11 base
     * 11 de la SET, el mismo algoritmo que produce el DV del RUC y el del
     * CDC, verificado en este repo contra RUCs reales
     * (`api/lib/Sales/verify_chain/verify_cdc.php`). Un RUC que no es
     * puramente numérico no se toca: se corta y se le pide al operador que lo
     * corrija, porque ahí sí habría que adivinar dónde termina el cuerpo.
     */
    private static function rucWithCheckDigit(string $ruc): string
    {
        $ruc = trim($ruc);

        if (str_contains($ruc, '-')) {
            [$body, $dv] = array_pad(explode('-', $ruc, 2), 2, '');
            $body = trim($body);
            $dv = trim($dv);
            if (preg_match('/^\d+$/', $body) !== 1 || preg_match('/^\d$/', $dv) !== 1) {
                throw new \RuntimeException(
                    "El RUC del cliente ($ruc) no tiene el formato que exige el documento electrónico " .
                    '(número, guion y un dígito verificador). Corregilo en la ficha del cliente.'
                );
            }
            return $body . '-' . $dv;
        }

        if (preg_match('/^\d+$/', $ruc) === 1) {
            return $ruc . '-' . Cdc::checkDigit($ruc);
        }

        throw new \RuntimeException(
            "El RUC del cliente ($ruc) tiene caracteres que no son números — no se puede emitir el documento " .
            'electrónico hasta corregirlo en la ficha del cliente.'
        );
    }

    /**
     * `documentoNumero` sin los caracteres que xmlgen rechaza (`.` y `/`).
     * Solo se limpian separadores de miles y barras; no se "arregla" nada
     * más.
     */
    private static function cleanDocumentNumber(string $value): string
    {
        return trim(str_replace(['.', '/'], '', $value));
    }

    /**
     * `razonSocial` del receptor: xmlgen la exige con 4 a 250 caracteres, y
     * un contacto guardado con un nombre de 2 letras haría fallar la emisión
     * con un mensaje del motor que habla de `dNomRec`. Se corta acá con un
     * mensaje que dice qué arreglar y dónde.
     */
    private static function requireName(mixed $name, string $who): string
    {
        $name = trim((string) $name);
        if (mb_strlen($name) < 4) {
            throw new \RuntimeException(
                "El nombre o razón social $who tiene menos de 4 caracteres — el documento electrónico no lo admite. " .
                'Completalo en la ficha del cliente.'
            );
        }
        return mb_substr($name, 0, 250);
    }

    /**
     * `establecimiento`/`punto`: STRINGS de 1 a 3 dígitos para su Zod (que
     * rechaza enteros), rellenados a 3 porque es lo que termina en el CDC.
     */
    private static function padCode(mixed $value, string $what): string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            throw new \RuntimeException(
                "La caja de esta venta no tiene un $what válido (llegó \"$value\"). " .
                'Cargá el timbrado de la caja con el formato EEE-PPP en Sucursales → Cajas.'
            );
        }
        return str_pad($value, 3, '0', STR_PAD_LEFT);
    }
}
