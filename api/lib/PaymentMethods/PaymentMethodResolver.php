<?php
declare(strict_types=1);

namespace Punto\Api\PaymentMethods;

/**
 * Resuelve la CLAVE de un pago tal como quedó guardada en
 * `transaction.transactionPaymentType` (`[{type,name,total}]`) al `taxonomyId`
 * real del medio de pago del tenant.
 *
 * Por qué existe: la clave que guarda una venta NO es homogénea.
 *   - Ventas nuevas (POS actual): `type` = taxonomyId (UUID) del método.
 *   - Ventas históricas / fallback del POS / servicios internos: slug legacy
 *     (`efectivo`, `tcredito`, `tdebito`, `giftcard`, `epos`…) o el nombre
 *     legible del método.
 *
 * Cualquier módulo que necesite mapear un pago a algo configurable por el
 * tenant (cuenta de Finanzas, código de medio de pago de SIFEN, …) necesita
 * exactamente esta resolución. Vivía embebida dentro de
 * `Finance\ConfigService::resolveAccountId` — se extrajo acá para que
 * facturación electrónica la reuse en vez de volver a escribir el mismo
 * matcheo (regla: atacar el wrapper compartido, no duplicar el call-site).
 *
 * Devuelve `null` cuando no matchea nada — la política de fallback es de cada
 * consumidor (Finanzas cae en la cuenta Efectivo, facturación electrónica cae
 * en el código de medio de pago por defecto de la cuenta).
 */
final class PaymentMethodResolver
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Cache por request: `resolveMethodId()` se llama una vez por línea de
     * pago (ventas con pago dividido, drenado de outbox en lote), y la
     * taxonomía no cambia dentro de un mismo request.
     *
     * @var array<string,array<int,array{id:string,name:string,code:?string,systemKey:?string}>>
     */
    private static array $cache = [];

    /**
     * Medios de pago reales del tenant, con `code` y `systemKey` (viven en el
     * JSONB `taxonomyExtra` — ver PaymentMethodService).
     *
     * @return array<int,array{id:string,name:string,code:?string,systemKey:?string}>
     */
    public function methods(string $companyId): array
    {
        if (isset(self::$cache[$companyId])) {
            return self::$cache[$companyId];
        }

        // taxonomyExtra es TEXT (no jsonb): el operador ->> no existe sobre esa
        // columna y rompe la query entera (mismo bug que PaymentMethodService::list()).
        // code y systemKey se extraen en PHP tras decodificar el JSON crudo.
        $res = ncmExecute(
            'SELECT taxonomyId, taxonomyName, taxonomyExtra
               FROM taxonomy
              WHERE taxonomyType = ? AND companyId = ?
              ORDER BY taxonomyName ASC',
            ['paymentMethod', $companyId],
            false,
            true
        );

        $out = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $extraRaw = $res->fields['taxonomyextra'] ?? null;
                $extra = is_string($extraRaw) ? (json_decode($extraRaw, true) ?: []) : (is_array($extraRaw) ? $extraRaw : []);
                $out[] = [
                    'id'        => (string) $res->fields['taxonomyid'],
                    'name'      => (string) $res->fields['taxonomyname'],
                    'code'      => isset($extra['code']) && $extra['code'] !== '' ? (string) $extra['code'] : null,
                    'systemKey' => isset($extra['systemKey']) && $extra['systemKey'] !== '' ? (string) $extra['systemKey'] : null,
                ];
                $res->MoveNext();
            }
            $res->Close();
        }

        self::$cache[$companyId] = $out;
        return $out;
    }

    /**
     * Clave de pago → taxonomyId del método, o `null` si no matchea ninguno.
     *
     * Camino 1 — UUID: es el taxonomyId directo (ventas nuevas). Se valida que
     * pertenezca al tenant: un método borrado deja la clave huérfana y devuelve
     * `null` en vez de un id que no existe.
     *
     * Camino 2 — slug/nombre legacy: normaliza el alias y matchea, en orden,
     * contra `systemKey` → `code` → nombre del método. El orden importa:
     * systemKey es estable e inmutable, el code es configurable por el
     * tenant, y el nombre es lo más volátil.
     */
    /**
     * Nombre para mostrar del método de pago — resuelve la clave (UUID o
     * legacy: `efectivo`, `tcredito`…) al `taxonomyName` real del tenant.
     * Usado por los listados de Finanzas para no exponer la clave cruda
     * (UUID o slug) en la UI. `null` si no matchea ningún método.
     */
    public function resolveMethodName(string $companyId, string $key): ?string
    {
        $id = $this->resolveMethodId($companyId, $key);
        if ($id === null) {
            return null;
        }
        foreach ($this->methods($companyId) as $method) {
            if ($method['id'] === $id) {
                return $method['name'];
            }
        }
        return null;
    }

    public function resolveMethodId(string $companyId, string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        $methods = $this->methods($companyId);

        if (preg_match(self::UUID_RE, $key)) {
            foreach ($methods as $method) {
                if (strcasecmp($method['id'], $key) === 0) {
                    return $method['id'];
                }
            }
            return null;
        }

        // systemKey primero con la clave CRUDA: el POS manda 'cash'/'giftcard'
        // tal cual en su fallback local, y esos son systemKeys reales.
        foreach ($methods as $method) {
            if ($method['systemKey'] !== null && strcasecmp($method['systemKey'], $key) === 0) {
                return $method['id'];
            }
        }

        $normalized = $this->normalizeKey($key);

        $slugToSystemKey = [
            'efectivo'  => 'cash',
            'giftcard'  => 'giftcard',
        ];
        if (isset($slugToSystemKey[$normalized])) {
            foreach ($methods as $method) {
                if ($method['systemKey'] === $slugToSystemKey[$normalized]) {
                    return $method['id'];
                }
            }
        }

        $slugToCode = [
            'efectivo'         => 'A',
            'tarjeta_credito'  => 'S',
            'tarjeta_debito'   => 'D',
            'cheque'           => 'F',
            'giftcard'         => 'G',
        ];
        if (isset($slugToCode[$normalized])) {
            foreach ($methods as $method) {
                if ($method['code'] !== null && strcasecmp($method['code'], $slugToCode[$normalized]) === 0) {
                    return $method['id'];
                }
            }
        }

        $slugToName = [
            'efectivo'         => 'Efectivo',
            'tarjeta_credito'  => 'T. Crédito',
            'tarjeta_debito'   => 'T. Débito',
            'cheque'           => 'Cheque',
            'transferencia'    => 'Transferencia',
            'giftcard'         => 'Gift Card',
            'billetera'        => 'Billetera',
        ];
        $wantedName = $slugToName[$normalized] ?? null;
        foreach ($methods as $method) {
            if (strcasecmp($method['name'], $key) === 0
                || ($wantedName !== null && strcasecmp($method['name'], $wantedName) === 0)
            ) {
                return $method['id'];
            }
        }

        return null;
    }

    /** Normaliza claves legacy/POS (cash, creditcard, tcredito…) al vocabulario interno. */
    private function normalizeKey(string $key): string
    {
        $aliases = [
            'cash' => 'efectivo', 'efectivo' => 'efectivo',
            'creditcard' => 'tarjeta_credito', 'tcredito' => 'tarjeta_credito', 'card' => 'tarjeta_credito',
            'debitcard' => 'tarjeta_debito', 'tdebito' => 'tarjeta_debito',
            'transfer' => 'transferencia', 'transferencia' => 'transferencia',
            'check' => 'cheque', 'cheque' => 'cheque',
            'storeCredit' => 'billetera', 'inCredit' => 'billetera', 'billetera' => 'billetera',
            'giftcard' => 'giftcard',
        ];
        return $aliases[$key] ?? 'otro';
    }
}
