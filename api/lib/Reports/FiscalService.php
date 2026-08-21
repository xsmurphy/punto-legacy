<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Contacts\ContactService;
use Punto\Api\Tax\TaxBreakdownResolver;
use Punto\Api\Tax\TaxEngine;

/**
 * Reportes fiscales de Paraguay — RG90 (registro de ventas para Marangatu) y
 * Libro Ventas. F5, context/38-impuestos-multi-pais.md §E.
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
     * RG90 — 20 columnas en el orden EXACTO que exige Marangatu.
     *
     * @return array{rows: list<array<string,mixed>>, meta: array{totalCount:int, fallbackCount:int, excludedCount:int, truncated:bool}}
     */
    public function rg90(string $from, string $to, string $roc, string $companyId): array
    {
        [$sales, $stats] = $this->loadSales($from, $to, $roc, $companyId);

        $rows = [];
        foreach ($sales as $s) {
            $rows[] = [
                'CODIGO TIPO DE REGISTRO'                              => '1', // 1 = venta (libro compras, fuera de alcance, sería otro código)
                'CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR'          => $s['idType'],
                'NUMERO DE IDENTIFICACION DEL COMPRADOR'               => $s['idNumber'],
                'NOMBRE O RAZON SOCIAL DEL COMPRADOR'                  => $s['customerName'],
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
     * contador (no tiene un layout de importación fijo como RG90).
     *
     * @return array{rows: list<array<string,mixed>>, meta: array{totalCount:int, fallbackCount:int, excludedCount:int, truncated:bool}}
     */
    public function libroVentas(string $from, string $to, string $roc, string $companyId): array
    {
        [$sales, $stats] = $this->loadSales($from, $to, $roc, $companyId);

        $rows = [];
        foreach ($sales as $s) {
            $rows[] = [
                'FECHA DE EMISION' => $s['dateFmt'],
                'FACT.'            => $s['docNo'],
                'RAZON SOCIAL'     => $s['customerName'],
                'RUC/CI'           => $s['idNumber'],
                'TIMBRADO'         => $s['authNo'],
                'GRAV. 10%'        => $s['base10'],
                'IVA 10%'          => $s['tax10'],
                'GRAV. 5%'         => $s['base5'],
                'IVA 5%'           => $s['tax5'],
                'EXENTA'           => $s['exento'],
                'TOTAL 10%'        => $s['grav10'],
                'TOTAL 5%'         => $s['grav5'],
                'TOTAL'            => $s['total'],
            ];
        }

        return ['rows' => $rows, 'meta' => $stats];
    }

    /**
     * Carga y enriquece las ventas del rango — compartido por rg90()/libroVentas()
     * para no repetir la resolución de cliente/timbrado/desglose fiscal.
     *
     * @return array{0: list<array<string,mixed>>, 1: array{totalCount:int, fallbackCount:int, excludedCount:int, truncated:bool}}
     */
    private function loadSales(string $from, string $to, string $roc, string $companyId): array
    {
        $cols = "transactionId, transactionDate, transactionDiscount, transactionTotal,
                 transactionType, invoiceNo, invoicePrefix, customerId, registerId,
                 meta->>'tags' AS tags";
        // LIMIT 5000: mismo cap que el resto de /v1/reports (convención del
        // proyecto). Acá el dato es MÁS sensible — es un documento que se
        // declara ante el SET — así que `truncated` viaja en meta para que
        // el caller AVISE en vez de dejar un RG90 incompleto pasar en
        // silencio (un tenant de alto volumen en un rango largo podría
        // pisar el cap).
        $sql = "SELECT $cols FROM transaction
                WHERE transactionType IN (" . self::TX_TYPES . ")
                AND " . SaleFilters::notVoidedSql() . "
                AND transactionDate BETWEEN ? AND ?" . $roc . "
                ORDER BY invoiceNo DESC LIMIT 5000";
        $res = ncmExecute($sql, [$from, $to], false, false, true);
        $res = is_array($res) ? $res : [];
        $truncated = count($res) >= 5000;
        if ($res === []) {
            return [[], ['totalCount' => 0, 'fallbackCount' => 0, 'excludedCount' => 0, 'truncated' => false]];
        }

        $txIds = $custIds = $regIds = [];
        foreach ($res as $f) {
            $txIds[]   = (string) $f['transactionId'];
            $custIds[] = (string) $f['customerId'];
            $regIds[]  = (string) $f['registerId'];
        }

        $registers = (new TransactionsService())->registerInfo($regIds, $companyId);
        $contacts  = $this->contactInfo($custIds, $companyId);
        $decimals  = $this->currencyDecimals($companyId);

        $taxRes        = TaxBreakdownResolver::resolveMany($txIds, $companyId);
        $bucketsByTx   = $taxRes['byTx'];
        $fallbackCount = $taxRes['fallbackCount'];

        $sales = [];
        $excludedCount = 0;
        foreach ($res as $f) {
            $txId = (string) $f['transactionId'];

            // Venta "interna" (tag 166227, gateado por settingIgnoreInternal del
            // tenant): mismo criterio que el resto de reportes (NonAddingSales,
            // DrawersService) y que el legacy RG90 (isInternalSale). No es venta
            // real declarable.
            $tagsArr = json_decode((string) ($f['tags'] ?? ''), true);
            if (isInternalSale(is_array($tagsArr) ? $tagsArr : [])) {
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
            $base10 = $tax10 = $base5 = $tax5 = 0.0;
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
                    $exento += $gross;
                }
            }

            $grav10 = TaxEngine::roundHalfUp($grav10, $decimals);
            $grav5  = TaxEngine::roundHalfUp($grav5, $decimals);
            $exento = TaxEngine::roundHalfUp($exento, $decimals);
            $total  = TaxEngine::roundHalfUp((float) $f['transactionTotal'] - (float) $f['transactionDiscount'], $decimals);

            // ── Timbrado / número de comprobante — mismo criterio que
            // TransactionsService::detail() (docNo = prefix + '-' + número con
            // ceros a la izquierda de la caja). ──────────────────────────────
            $reg = $registers[(string) $f['registerId']] ?? [];
            $invoicePrefix = (string) ($f['invoicePrefix'] ?? '');
            if ($invoicePrefix === '') {
                $invoicePrefix = (string) ($reg['invoicePrefix'] ?? '');
            }
            $lead      = (int) ($reg['docsLeadingZeros'] ?? 0);
            $invoiceNo = (string) ($f['invoiceNo'] ?? '');
            $paddedNo  = $lead > 0 ? str_pad($invoiceNo, $lead, '0', STR_PAD_LEFT) : $invoiceNo;
            $docNo     = ($invoicePrefix !== '' && $paddedNo !== '') ? $invoicePrefix . '-' . $paddedNo : $invoicePrefix . $paddedNo;

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
                'total'        => $total,
            ];
        }

        $stats = [
            'totalCount'    => count($sales),
            'fallbackCount' => $fallbackCount,
            'excludedCount' => $excludedCount,
            'truncated'     => $truncated,
        ];

        return [$sales, $stats];
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
