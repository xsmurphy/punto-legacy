<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Contacts\ContactService;
use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Tax\TaxBreakdownResolver;
use Punto\Api\Tax\TaxEngine;

/**
 * Reportes fiscales de Paraguay — RG90 (registro de ventas para Marangatu) y
 * Libro Ventas. F5, context/38-impuestos-multi-pais.md §E.
 *
 * ── EL FORMATO ES EL DEL LEGACY (decisión del owner, 2026-09-11) ────────────
 *
 * Estos dos archivos los presenta el CONTADOR, que ya viene presentando los
 * del panel ENCOM: mismas columnas, mismos encabezados, mismo número de
 * comprobante pegado sin guiones, mismo archivo TSV con extensión .xls. Así
 * que acá el legacy no es solo referencia funcional — es el formato de
 * salida, y cualquier diferencia se justifica o se elimina.
 *
 * Lo que NO se copió, y por qué, está anotado inline: el RUC de RG90 sale sin
 * dígito verificador (Marangatu rechaza la fila con el DV pegado), el Libro
 * Ventas no replica el intercambio GRAV. 5% / IVA 5% del legacy (era un bug,
 * ver `libroVentas()`), y el universo ya no se corta en 5000 filas.
 *
 * El FONDO sigue siendo el de Punto: los montos salen del desglose fiscal
 * congelado por venta, no del prorrateo /21 hardcodeado que hacía el legacy.
 * Se copia cómo se PRESENTA el archivo, no cómo se calculaba.
 *
 * Referencia funcional (QUÉ, no CÓMO): la implementación legacy vivía en
 * `panel/a_reports.php?action=rg90|libro-ventas` (borrada junto con el resto
 * de `panel/`, sobrevive en git history). Layout de 20 columnas de RG90
 * (orden, nombres, códigos) replicado TAL CUAL — Marangatu lo rechaza si una
 * columna se corre. Lo que NO se replicó, documentado inline en cada método
 * abajo y en context/38.
 *
 * Diferencia arquitectónica con el legacy (la razón por la que este export
 * recién ahora es correcto): los montos gravados NO se derivan del catálogo
 * actual ni de una tasa fija — salen del desglose fiscal CONGELADO por venta
 * (F2a: `toTaxObj`/`meta.transactionDetails`, ver `Tax\TaxBreakdownResolver`).
 * El legacy mezclaba catálogo actual + hardcode PY-10% (auditoría §diagnóstico
 * de context/38) — eso ya no es posible ni necesario.
 *
 * PY-only: el gate por país (`COUNTRY === 'PY'`) vive en el endpoint
 * (api/v1/reports/fiscal.php), no acá — mismo criterio que
 * `ContactService::isPyTenant()` para no repetir la feature en un tenant que
 * no la necesita.
 *
 * Alcance: SOLO ventas (contado=0, crédito=3). Excluye a propósito:
 *   - Ventas anuladas (`voidedAt`, mig 154): `loadSales()` filtra con
 *     `SaleFilters::notVoidedSql()` (F1/F4, context/40-anulacion-y-nota-credito.md)
 *     — una factura anulada no suma al RG90/Libro Ventas.
 *   - Notas de crédito / devoluciones (transactionType=6): NO implementadas
 *     todavía (F3-F6 de context/40, plan abierto). El legacy declaraba código
 *     110 y columnas de "comprobante asociado" para NC, pero su propio WHERE
 *     nunca las traía (`transactionType IN(0,3)` — el código de NC era
 *     muerto). Cuando context/40 F3-F5 aterricen, este Service necesita
 *     incluir las NC como filas propias (transType=110, comprobante asociado
 *     = factura original).
 *   - Libro de compras: fuera de alcance (brief F5) — otra fuente de datos
 *     (`purchase`), documento separado. Pendiente, no planificado acá.
 */
final class FiscalService
{
    private const TX_TYPES = '0,3';

    /**
     * Tamaño de página del barrido de ventas. El universo NO se corta (un
     * archivo fiscal incompleto es peor que uno lento): se pagina para que ni
     * el recordset ni el `IN (...)` del resolver de impuestos crezcan con el
     * rango. Ver `loadSales()`.
     */
    private const PAGE_SIZE = 2000;

    /**
     * RG90 — 20 columnas en el orden EXACTO que exige Marangatu.
     *
     * @return array{rows: list<array<string,mixed>>, meta: array{totalCount:int, fallbackCount:int, excludedCount:int}}
     */
    public function rg90(string $from, string $to, string $roc, string $companyId): array
    {
        [$sales, $stats] = $this->loadSales($from, $to, $roc, $companyId);

        $rows = [];
        foreach ($sales as $s) {
            $rows[] = [
                'CODIGO TIPO DE REGISTRO'                              => '1', // 1 = venta (libro compras, fuera de alcance, sería otro código)
                'CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR'          => $s['idType'],
                // RUC SIN dígito verificador: Marangatu identifica al
                // contribuyente por el número base y RECHAZA la fila si viaja
                // el DV pegado ("80012345-6" → "80012345"). Solo aplica al
                // RUC (tipo 11): una CI no tiene DV y se manda entera. El
                // Libro Ventas, que es interno del contador, lo conserva.
                'NUMERO DE IDENTIFICACION DEL COMPRADOR'               => self::stripCheckDigit((string) $s['idNumber'], (int) $s['idType']),
                // html_entity_decode: los nombres viejos se guardaron
                // HTML-escapados (`&amp;`, `&quot;`) por el panel legacy y así
                // salían al archivo. Mismo decode que hacía el legacy.
                'NOMBRE O RAZON SOCIAL DEL COMPRADOR'                  => html_entity_decode((string) $s['customerName'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'CODIGO TIPO DE COMPROBANTE'                           => 109, // 109 = factura. 110 = NC, no implementada — ver docblock de la clase
                'FECHA DE EMISION DEL COMPROBANTE'                     => $s['dateFmt'],
                'NUMERO DE TIMBRADO'                                   => $s['authNo'],
                'NUMERO DEL COMPROBANTE'                               => $s['docNo'],
                'MONTO GRAVADO AL 10%'                                 => $s['grav10'],
                'MONTO GRAVADO AL 5%'                                  => $s['grav5'],
                'MONTO NO GRAVADO O EXENTO'                            => $s['exento'],
                'MONTO TOTAL DEL COMPROBANTE'                          => $s['total'],
                'CODIGO CONDICION DE VENTA'                            => $s['condicion'],
                'OPERACION EN MONEDA EXTRANJERA'                       => 'N', // sin flujo de venta en moneda extranjera hoy — ver docblock
                'IMPUTA AL IVA'                                        => 'S', // el sistema no modela el régimen fiscal del COMPRADOR — ver docblock
                'IMPUTA AL IRE'                                        => 'N',
                'IMPUTA AL IRP-RSP'                                    => 'N',
                'NO IMPUTA'                                            => 'N',
                'NUMERO DEL COMPROBANTE DE VENTA ASOCIADO'             => '', // NC no implementada
                'TIMBRADO DEL COMPROBANTE DE VENTA ASOCIADO'           => '',
            ];
        }

        return ['rows' => $rows, 'meta' => $stats];
    }

    /**
     * Libro Ventas — desglose base/IVA/total por tasa, uso interno del
     * contador.
     *
     * Layout CALCADO del legacy (14 columnas, mismo orden y mismos
     * encabezados): los contadores ya presentan este archivo así, y una
     * columna corrida les rompe la planilla con la que trabajan. De ahí que el
     * TOTAL del comprobante esté en la posición 11 —en medio del desglose— y
     * que las tres últimas (`10%`, `5%`, `EXENTO`) sean los totales BRUTOS por
     * tramo (base + IVA), no otra vez la base.
     *
     * ── Un bug del legacy que NO se replica ──
     * El legacy intercambiaba sus columnas de 5%: escribía el IVA bajo
     * "GRAV. 5%" y la base bajo "IVA 5%" (`$grav5 += $totalTaxes['tax']['5']`
     * y `$tax5 += $totalTaxes['grav']['5']`, a_report_transactions.php). Acá
     * cada una lleva su valor correcto. Es el único punto donde este archivo
     * difiere del legacy a propósito: copiar el formato no incluye copiar un
     * dato equivocado.
     *
     * @return array{rows: list<array<string,mixed>>, meta: array{totalCount:int, fallbackCount:int, excludedCount:int}}
     */
    public function libroVentas(string $from, string $to, string $roc, string $companyId): array
    {
        [$sales, $stats] = $this->loadSales($from, $to, $roc, $companyId);

        $rows = [];
        foreach ($sales as $s) {
            $rows[] = [
                'FECHA DE EMISION'      => $s['dateFmt'],
                'FACT.'                 => $s['docNo'],
                'NOMBRE O RAZON SOCIAL' => $s['customerName'],
                // Con DV, al revés que RG90: acá el archivo lo lee una
                // persona, y el RUC completo es el que figura en la factura.
                'R.U.C N.'              => $s['idNumber'],
                'TIMBRADO'              => $s['authNo'],
                'GRAV. 10%'             => $s['base10'],
                'IVA 10%'               => $s['tax10'],
                'GRAV. 5%'              => $s['base5'],
                'IVA 5%'                => $s['tax5'],
                'EXENTA'                => $s['baseExento'],
                'TOTAL'                 => $s['total'],
                '10%'                   => $s['grav10'],
                '5%'                    => $s['grav5'],
                'EXENTO'                => $s['exento'],
            ];
        }

        return ['rows' => $rows, 'meta' => $stats];
    }

    /**
     * RUC sin dígito verificador para RG90. Solo toca al tipo 11 (RUC): la CI
     * y el resto de los documentos de la Tabla 3 no tienen DV, y el fallback
     * 'X' de un comprador sin identificar tampoco.
     */
    private static function stripCheckDigit(string $number, int $idType): string
    {
        if ($idType !== ContactService::ID_TYPE_RUC) {
            return $number;
        }
        $dash = strrpos($number, '-');

        return $dash === false ? $number : substr($number, 0, $dash);
    }

    /**
     * Carga y enriquece las ventas del rango — compartido por rg90()/libroVentas()
     * para no repetir la resolución de cliente/timbrado/desglose fiscal.
     *
     * @return array{0: list<array<string,mixed>>, 1: array{totalCount:int, fallbackCount:int, excludedCount:int}}
     */
    private function loadSales(string $from, string $to, string $roc, string $companyId): array
    {
        $cols = "transactionId, transactionDate, transactionDiscount, transactionTotal,
                 transactionType, invoiceNo, invoicePrefix, customerId, registerId,
                 meta->>'tags' AS tags";
        // SIN cap de filas. El resto de /v1/reports corta en 5000 —es un
        // listado en pantalla, donde ver menos filas es una molestia— pero
        // esto es el documento que el contador presenta ante la SET: cortarlo
        // produce una declaración INCOMPLETA que parece completa. Se recorre
        // paginado (`PAGE_SIZE`), enriqueciendo cada página, así ni el
        // recordset ni el `IN (...)` del resolver de impuestos crecen con el
        // rango.
        //
        // El ORDER BY necesita desempate para que la paginación sea estable:
        // `invoiceNo` NO es único entre cajas (cada punto de expedición tiene
        // su propio correlativo), y con un orden parcial dos páginas pueden
        // repetir y saltear filas. El orden visible sigue siendo el del
        // legacy: número de comprobante descendente.
        $sql = "SELECT $cols FROM transaction
                WHERE transactionType IN (" . self::TX_TYPES . ")
                AND " . SaleFilters::notVoidedSql() . "
                AND transactionDate BETWEEN ? AND ?" . $roc . "
                ORDER BY invoiceNo DESC, transactionId
                LIMIT " . self::PAGE_SIZE . " OFFSET ?";

        $decimals      = $this->currencyDecimals($companyId);
        $sales         = [];
        $fallbackCount = 0;
        $excludedCount = 0;

        for ($offset = 0; ; $offset += self::PAGE_SIZE) {
            $res = ncmExecute($sql, [$from, $to, $offset], false, false, true);
            $res = is_array($res) ? $res : [];
            if ($res === []) {
                break;
            }

            $this->appendPage($res, $companyId, $decimals, $sales, $fallbackCount, $excludedCount);

            if (count($res) < self::PAGE_SIZE) {
                break;
            }
        }

        return [
            $sales,
            [
                'totalCount'    => count($sales),
                'fallbackCount' => $fallbackCount,
                'excludedCount' => $excludedCount,
            ],
        ];
    }

    /**
     * Enriquece una página de ventas crudas y la agrega a `$sales`.
     *
     * @param list<array<string,mixed>> $res
     * @param list<array<string,mixed>> $sales
     */
    private function appendPage(
        array $res,
        string $companyId,
        int $decimals,
        array &$sales,
        int &$fallbackCount,
        int &$excludedCount,
    ): void {
        $txIds = $custIds = $regIds = [];
        foreach ($res as $f) {
            $txIds[]   = (string) $f['transactionId'];
            $custIds[] = (string) $f['customerId'];
            $regIds[]  = (string) $f['registerId'];
        }

        $registers = (new TransactionsService())->registerInfo($regIds, $companyId);
        $contacts  = $this->contactInfo($custIds, $companyId);

        $taxRes         = TaxBreakdownResolver::resolveMany($txIds, $companyId);
        $bucketsByTx    = $taxRes['byTx'];
        $fallbackCount += $taxRes['fallbackCount'];
        foreach ($res as $f) {
            $txId = (string) $f['transactionId'];

            // Venta "interna" (tag 166227): SIEMPRE fuera, con `force = true`.
            // En los reportes de gestión la exclusión la decide el tenant con
            // `settingIgnoreInternal`, pero acá no es una preferencia: una
            // venta interna no es una operación declarable, y dejar que un
            // checkbox del panel la meta en el RG90 sería declarar de más.
            // Mismo criterio que el legacy (`isInternalSale($tags, true)`).
            $tagsArr = json_decode((string) ($f['tags'] ?? ''), true);
            if (isInternalSale(is_array($tagsArr) ? $tagsArr : [], true)) {
                continue;
            }

            $buckets = $bucketsByTx[$txId] ?? null;
            if ($buckets === null) {
                // D3 (context/38): venta anterior a F2a, sin desglose congelado
                // reconstruible. No se inventa un desglose — se excluye y se
                // cuenta, el caller lo reporta.
                $excludedCount++;
                continue;
            }

            $grav10 = $grav5 = $exento = 0.0;
            $base10 = $tax10 = $base5 = $tax5 = $baseExento = 0.0;
            foreach ($buckets as $b) {
                $rate  = (float) $b['rate'];
                $kind  = (string) $b['kind'];
                $base  = (float) $b['base'];
                $tax   = (float) $b['amount'];
                $gross = $base + $tax;

                // "MONTO GRAVADO AL X%" del RG90/SET incluye el IVA (es el monto
                // FACTURADO que cae en ese tramo, no la base neta) — así es como
                // las 3 columnas gravado10+gravado5+exento suman el total del
                // comprobante (ver verificación en verify_chain). El legacy hacía
                // lo mismo (usaba el bucket 'total'=base+tax para esta columna).
                if ($kind === 'rate' && abs($rate - 10.0) < 0.0001) {
                    $grav10 += $gross;
                    $base10 += $base;
                    $tax10  += $tax;
                } elseif ($kind === 'rate' && abs($rate - 5.0) < 0.0001) {
                    $grav5 += $gross;
                    $base5 += $base;
                    $tax5  += $tax;
                } else {
                    // "MONTO NO GRAVADO O EXENTO": kind=exempt (exento real) Y
                    // también kind=rate con una tasa que no es 10 ni 5 (0% real,
                    // o cualquier tasa custom de un tenant multi-país) — el
                    // layout SET de RG90 solo tiene 3 columnas de monto, sin una
                    // 4ta para "gravado a otra tasa". Ver context/38 D2:
                    // kind=exempt y kind=rate/rate=0 son fiscalmente distintos,
                    // pero ambos caen en esta columna por falta de columna propia
                    // — limitación del LAYOUT fijo, no del dato (que sigue
                    // distinguible en toTaxObj/Libro Ventas interno).
                    $exento     += $gross;
                    // Base sin impuesto del mismo tramo. Para un exento real
                    // coincide con el bruto (no hay IVA), pero NO para una
                    // tasa custom que cae acá por falta de columna propia —
                    // por eso se acumula aparte en vez de reusar `$exento`.
                    // El Libro Ventas usa las dos: "EXENTA" (base, col. 10) y
                    // "EXENTO" (bruto, col. 14), como el legacy.
                    $baseExento += $base;
                }
            }

            $grav10 = TaxEngine::roundHalfUp($grav10, $decimals);
            $grav5  = TaxEngine::roundHalfUp($grav5, $decimals);
            $exento = TaxEngine::roundHalfUp($exento, $decimals);
            $total  = TaxEngine::roundHalfUp((float) $f['transactionTotal'] - (float) $f['transactionDiscount'], $decimals);

            // ── Timbrado / número de comprobante ─────────────────────────────
            //
            // El prefijo sale CONGELADO de la transacción (`invoicePrefix`) y
            // solo cae a la caja cuando la venta no lo guardó: el punto de
            // expedición de un comprobante ya emitido es el que tenía al
            // emitirse, no el que la caja tenga hoy (context/29, mig 209).
            //
            // El FORMATO, en cambio, es el del legacy: pegado y sin guiones,
            // ancho fijo 7 (`formatFlat`) — así lo presentan los contadores y
            // así lo espera Marangatu. Es la única diferencia con el número
            // impreso en la factura, que lleva guiones; el documento es el
            // mismo.
            $reg = $registers[(string) $f['registerId']] ?? [];
            $invoicePrefix = (string) ($f['invoicePrefix'] ?? '');
            if ($invoicePrefix === '') {
                $invoicePrefix = (string) ($reg['invoicePrefix'] ?? '');
            }
            $invoiceNo = (string) ($f['invoiceNo'] ?? '');
            $docNo     = DocumentNumber::formatFlat($invoiceNo, $invoicePrefix);

            // ── Cliente: tipo/número de identificación — Tabla 3 SET
            // (contact.contactIdType, mig 125), NO el parseo de string
            // "tiene guion → RUC" que hacía el legacy contra contactTIN. ──────
            $cust = $contacts[(string) $f['customerId']] ?? null;
            [$idType, $idNumber, $customerName] = $this->resolveBuyerIdentity($cust);

            $sales[] = [
                'dateFmt'      => date('d/m/Y', strtotime((string) $f['transactionDate'])),
                'docNo'        => $docNo,
                'authNo'       => (string) ($reg['invoiceAuth'] ?? ''),
                'customerName' => $customerName,
                'idType'       => $idType,
                'idNumber'     => $idNumber,
                'condicion'    => ((int) $f['transactionType'] === 3) ? '2' : '1', // 1=contado, 2=crédito
                'grav10'       => $grav10,
                'grav5'        => $grav5,
                'exento'       => $exento,
                'base10'       => TaxEngine::roundHalfUp($base10, $decimals),
                'tax10'        => TaxEngine::roundHalfUp($tax10, $decimals),
                'base5'        => TaxEngine::roundHalfUp($base5, $decimals),
                'tax5'         => TaxEngine::roundHalfUp($tax5, $decimals),
                'baseExento'   => TaxEngine::roundHalfUp($baseExento, $decimals),
                'total'        => $total,
            ];
        }
    }

    /**
     * Tipo/número de identificación + razón social del comprador, Tabla 3 SET
     * (mismo código que `contact.contactIdType` — mig 125, ContactService).
     * RUC(11) vive en `contactTIN`; el resto comparte `contactCI`. Sin
     * contacto o sin número identificable → "SIN NOMBRE"/código 15/'X',
     * mismo fallback que el legacy pero con la fuente correcta.
     *
     * @return array{0:int,1:string,2:string}
     */
    private function resolveBuyerIdentity(?array $cust): array
    {
        if ($cust === null) {
            return [ContactService::ID_TYPE_SIN_NOMBRE, 'X', 'SIN NOMBRE'];
        }

        $tin  = trim((string) ($cust['tin'] ?? ''));
        $ci   = trim((string) ($cust['ci'] ?? ''));
        $name = trim((string) ($cust['name'] ?? ''));

        $idType = $cust['idType'] ?? null;
        $idType = $idType !== null ? (int) $idType : ContactService::inferIdType($tin ?: null, $ci ?: null);

        $number = $idType === ContactService::ID_TYPE_RUC ? $tin : $ci;

        if ($number === '' || $name === '') {
            return [ContactService::ID_TYPE_SIN_NOMBRE, $number !== '' ? $number : 'X', 'SIN NOMBRE'];
        }

        return [$idType, $number, $name];
    }

    /** Batch: contactId → {name, tin, ci, idType}. */
    private function contactInfo(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName, contactTIN, contactIdType, data->>'contactCI' AS contactCI
               FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = [
                'name'   => trim((string) ($c['contactName'] ?? '')),
                'tin'    => (string) ($c['contactTIN'] ?? ''),
                'ci'     => (string) ($c['contactCI'] ?? ''),
                'idType' => $c['contactIdType'] ?? null,
            ];
        }
        return $map;
    }

    /** Decimales del tenant (D1, context/38) — mismo criterio que SaleService::currencyDecimals(). */
    private function currencyDecimals(string $companyId): int
    {
        $row  = ncmExecute(
            "SELECT config->>'settingDecimal' AS decimalflag FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );
        $flag = $row ? (string) ($row['decimalflag'] ?? '') : '';
        return $flag === 'yes' ? 2 : 0;
    }
}
