<?php
declare(strict_types=1);

namespace Punto\Api\Tax;

/**
 * Resolver COMPARTIDO del desglose fiscal congelado de una venta
 * (`toTaxObj.toTaxObjText`, F2a — context/38-impuestos-multi-pais.md).
 *
 * Extraído de `Transactions\TransactionDetailService::resolveTaxByRate()`
 * (F1, context/39) al agregar F5 (RG90/Libro Ventas, context/38 §E): ambos
 * consumidores necesitan la MISMA regla (primario `toTaxObj`, degradar a
 * `meta.transactionDetails` si falta o no parsea) y antes vivía privada y
 * atada a un solo transactionId por vez — RG90 la necesita en batch para un
 * rango de fechas completo, así que duplicarla ahí hubiera sido el segundo
 * call site del mismo bug potencial (regla CLAUDE.md: atacar el wrapper
 * compartido, no parchear cada call site).
 *
 * Shape de cada bucket: `{taxId, rate, kind, base, amount}` — `base` es el
 * neto (sin impuesto) y `amount` el impuesto de ese bucket; el gross
 * (base+amount) lo deriva cada caller según lo que necesite mostrar, NO se
 * agrega acá para no cambiar el contrato que ya consume TransactionDetailService.
 */
final class TaxBreakdownResolver
{
    /**
     * Decodifica el JSON crudo de `toTaxObj.toTaxObjText`. Devuelve null si
     * está vacío o no parsea a un array — el caller decide el fallback.
     *
     * @return list<array{taxId:?string,rate:float,kind:string,base:float,amount:float}>|null
     */
    public static function decodeStoredJson(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }
        $out = [];
        foreach ($decoded as $b) {
            if (!is_array($b)) {
                continue;
            }
            $out[] = [
                'taxId'  => $b['taxId'] ?? null,
                'rate'   => (float) ($b['rate'] ?? 0),
                'kind'   => (string) ($b['kind'] ?? 'exempt'),
                'base'   => (float) ($b['base'] ?? 0),
                'amount' => (float) ($b['amount'] ?? 0),
            ];
        }
        return $out === [] ? null : $out;
    }

    /**
     * Reconstruye el desglose por tasa desde las líneas YA congeladas de
     * `meta.transactionDetails` (F2a: taxRate/taxKind/taxNet/taxAmount por
     * línea) — mismo criterio que `SaleService::groupTaxByRate()`, sin
     * re-invocar el motor. Devuelve `[]` si ninguna línea tiene `taxRate`
     * (venta anterior a F2a, D3 del plan: no se inventa desglose para esas).
     *
     * @param list<array<string,mixed>> $metaLines
     * @return list<array{taxId:?string,rate:float,kind:string,base:float,amount:float}>
     */
    public static function fromMetaLines(array $metaLines): array
    {
        $buckets = [];
        $order   = [];
        foreach ($metaLines as $ml) {
            if (!is_array($ml) || ($ml['type'] ?? '') === 'discount' || !array_key_exists('taxRate', $ml)) {
                continue;
            }
            if (is_array($ml['voucher'] ?? null)) {
                continue; // canje de vale: no suma a la base fiscal (SaleService::groupTaxByRate)
            }
            $rate = (float) $ml['taxRate'];
            $kind = (string) ($ml['taxKind'] ?? 'exempt');
            $key  = $rate . '|' . $kind;
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['taxId' => $ml['taxId'] ?? null, 'rate' => $rate, 'kind' => $kind, 'base' => 0.0, 'amount' => 0.0];
                $order[] = $key;
            }
            $buckets[$key]['base']   += (float) ($ml['taxNet']   ?? 0);
            $buckets[$key]['amount'] += (float) ($ml['taxAmount'] ?? 0);
        }
        $out = [];
        foreach ($order as $key) {
            $out[] = $buckets[$key];
        }
        return $out;
    }

    /**
     * Versión BATCH para reportes que recorren un rango de fechas completo
     * (RG90/Libro Ventas, F5). Una query para `toTaxObj`, una segunda SOLO
     * para los transactionId que no resolvieron ahí (fallback a
     * meta.transactionDetails).
     *
     * @param list<string> $txIds
     * @return array{
     *   byTx: array<string, list<array{taxId:?string,rate:float,kind:string,base:float,amount:float}>>,
     *   fallbackCount: int,
     *   missingCount: int
     * } `fallbackCount`: filas que resolvieron por meta.transactionDetails
     *   (toTaxObj ausente o truncado — techo VARCHAR(255) pre-mig 124).
     *   `missingCount`: filas SIN ningún desglose reconstruible (venta
     *   anterior a F2a, D3 del plan) — el caller debe EXCLUIRLAS, no inventar.
     */
    public static function resolveMany(array $txIds, string $companyId): array
    {
        $txIds = array_values(array_unique(array_filter($txIds, static fn ($v) => (string) $v !== '')));
        if ($txIds === []) {
            return ['byTx' => [], 'fallbackCount' => 0, 'missingCount' => 0];
        }

        $ph  = implode(',', array_fill(0, count($txIds), '?'));
        $res = ncmExecute(
            "SELECT transactionId, toTaxObjText FROM toTaxObj WHERE companyId = ? AND transactionId IN ($ph)",
            array_merge([$companyId], $txIds),
            false,
            false,
            true
        );
        $res = is_array($res) ? $res : [];
        $rawByTx = [];
        foreach ($res as $r) {
            $rawByTx[(string) $r['transactionId']] = (string) ($r['toTaxObjText'] ?? '');
        }

        $byTx = [];
        $needFallback = [];
        foreach ($txIds as $id) {
            $decoded = self::decodeStoredJson($rawByTx[$id] ?? null);
            if ($decoded !== null) {
                $byTx[$id] = $decoded;
            } else {
                $needFallback[] = $id;
            }
        }

        $fallbackCount = 0;
        $missingCount  = 0;
        if ($needFallback !== []) {
            $ph2  = implode(',', array_fill(0, count($needFallback), '?'));
            $meta = ncmExecute(
                "SELECT transactionId, meta->>'transactionDetails' AS transactionDetails
                   FROM transaction WHERE companyId = ? AND transactionId IN ($ph2)",
                array_merge([$companyId], $needFallback),
                false,
                false,
                true
            );
            $meta = is_array($meta) ? $meta : [];
            foreach ($meta as $m) {
                $id = (string) $m['transactionId'];
                $decodedLines = json_decode((string) ($m['transactionDetails'] ?? ''), true);
                $lines = is_array($decodedLines) ? $decodedLines : [];
                $buckets = self::fromMetaLines($lines);
                if ($buckets !== []) {
                    $byTx[$id] = $buckets;
                    $fallbackCount++;
                } else {
                    $missingCount++;
                }
            }
            // transactionId sin fila en absoluto en `transaction` para el fallback
            // (no debería pasar — vienen del mismo listado que ya los trajo) cuenta
            // igual como missing, para que fallbackCount+missingCount+count(byTx sin fallback) cierre.
            $resolvedIds = array_map(static fn ($m) => (string) $m['transactionId'], $meta);
            foreach ($needFallback as $id) {
                if (!in_array($id, $resolvedIds, true)) {
                    $missingCount++;
                }
            }
        }

        return ['byTx' => $byTx, 'fallbackCount' => $fallbackCount, 'missingCount' => $missingCount];
    }
}
