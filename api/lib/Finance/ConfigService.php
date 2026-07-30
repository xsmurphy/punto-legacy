<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * Config mínima de Finanzas: mapa método de pago → cuenta
 * (`company.config.settingObj.finAccountMap`).
 *
 * Reglas de negocio (owner, 2026-07-02 — ver context/22 §3/§7):
 *   - Los métodos de pago son los reales del tenant, definidos en su CRUD
 *     de medios de pago (tabla `taxonomy`, taxonomyType='paymentMethod',
 *     scoped por companyId) — NO una lista hardcodeada.
 *   - El método cuyo taxonomyName sea "Efectivo" (case-insensitive) SIEMPRE
 *     mapea a la cuenta Efectivo del sistema (issystem=true). Fijo, no
 *     editable desde la UI — no se persiste en finAccountMap.
 *   - Los demás métodos son asignables por el usuario a cualquier cuenta
 *     type='bank' que haya creado. Si un método no tiene banco asignado,
 *     cae a Efectivo como fallback (nunca se pierde el movimiento).
 *   - El auto-seed NO crea ningún banco placeholder — el usuario crea sus
 *     propias cuentas bancarias.
 *
 * Persistencia: MERGE no-destructivo sobre settingObj (mismo patrón que
 * SettingsService::readSettingObj — lee con `config->>'settingObj'`, nunca
 * pisa el blob completo). finAccountMap se keyea por el taxonomyId (UUID)
 * real del método, no por una key fija.
 */
final class ConfigService
{
    /**
     * Devuelve la lista de métodos de pago reales del tenant, cada uno con
     * el accountId resuelto: el método "Efectivo" siempre trae la cuenta
     * Efectivo del sistema (isCash=true, no editable); los demás traen el
     * accountId asignado en finAccountMap o null si no se asignó todavía
     * (el caller/UI debe tratar null como "cae en Efectivo").
     *
     * @return array<int,array{methodId:string,methodName:string,accountId:?string,isCash:bool}>
     */
    public function read(string $companyId): array
    {
        $cashId = (new AccountService())->ensureCashAccountId($companyId);
        $obj = $this->readSettingObj($companyId) ?? [];
        $map = is_array($obj['finAccountMap'] ?? null) ? $obj['finAccountMap'] : [];

        $methods = $this->paymentMethods($companyId);
        $out = [];
        foreach ($methods as $method) {
            $isCash = strcasecmp($method['name'], 'Efectivo') === 0;
            $out[] = [
                'methodId'   => $method['id'],
                'methodName' => $method['name'],
                'accountId'  => $isCash ? $cashId : (isset($map[$method['id']]) ? (string) $map[$method['id']] : null),
                'isCash'     => $isCash,
            ];
        }
        return $out;
    }

    /**
     * Actualiza el mapa (solo métodos no-cash — "Efectivo" se ignora
     * silenciosamente si viene en el payload, es fijo). MERGE no-destructivo:
     * preserva otras keys de settingObj (currencies, logo, etc.).
     *
     * Fase 3 (FinanceLedger): las ventas guardan el método de pago por NAME
     * en transactionPaymentType (ej [{name:'efectivo',...}]); ese código
     * deberá resolver name→methodId vía la taxonomía paymentMethod para
     * aplicar este finAccountMap.
     *
     * @param array<string,string|null> $map methodId (taxonomyId) => accountId|null
     */
    public function update(string $companyId, array $map): array
    {
        $obj = $this->readSettingObj($companyId);
        if ($obj === null) {
            throw new \RuntimeException('No se pudo leer la configuración actual — abortado para no perder datos');
        }
        $current = is_array($obj['finAccountMap'] ?? null) ? $obj['finAccountMap'] : [];

        $methods = $this->paymentMethods($companyId);
        $validIds = [];
        $cashIds = [];
        foreach ($methods as $method) {
            $validIds[$method['id']] = true;
            if (strcasecmp($method['name'], 'Efectivo') === 0) {
                $cashIds[$method['id']] = true;
            }
        }

        foreach ($map as $methodId => $accountId) {
            $methodId = (string) $methodId;
            if (!isset($validIds[$methodId])) {
                throw new \RuntimeException("Método de pago inválido: {$methodId}");
            }
            if (isset($cashIds[$methodId])) {
                // Efectivo es fijo — se ignora silenciosamente.
                continue;
            }
            if ($accountId === null || $accountId === '') {
                $current[$methodId] = null;
                continue;
            }
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $accountId)) {
                throw new \RuntimeException("accountId inválido para {$methodId}");
            }
            if (!$this->isBankAccountOfCompany($companyId, (string) $accountId)) {
                throw new \RuntimeException("La cuenta {$accountId} no existe o no es una cuenta bancaria de este comercio");
            }
            $current[$methodId] = (string) $accountId;
        }

        $obj['finAccountMap'] = $current;

        ncmUpdate([
            'records'     => ['settingObj' => json_encode($obj)],
            'table'       => 'company',
            'where'       => 'companyId = ?',
            'whereParams' => [$companyId],
        ]);

        return $this->read($companyId);
    }

    /**
     * Resuelve la cuenta destino de un método de pago para un movimiento
     * derivado (Fase 3 — FinanceLedger).
     *
     * La clave del pago (`transactionPaymentType[].type|name`) puede venir como
     * taxonomyId (ventas nuevas) o como slug/nombre legacy (backfill histórico,
     * fallback del POS, servicios internos). Esa resolución NO vive acá: es
     * compartida con facturación electrónica y cualquier otro módulo que mapee
     * pagos a config del tenant — ver PaymentMethods\PaymentMethodResolver.
     *
     * Fallback final SIEMPRE = cuenta Efectivo: nunca se pierde el movimiento
     * (método borrado, slug desconocido, o método sin banco asignado).
     */
    public function resolveAccountId(string $companyId, string $paymentMethodKey): string
    {
        $cashId = (new AccountService())->ensureCashAccountId($companyId);

        $methodId = (new \Punto\Api\PaymentMethods\PaymentMethodResolver())
            ->resolveMethodId($companyId, $paymentMethodKey);
        if ($methodId === null) {
            return $cashId;
        }

        $map = $this->read($companyId);
        foreach ($map as $entry) {
            if ((string) $entry['methodId'] === $methodId) {
                // Cash o sin banco asignado → Efectivo (read() ya resuelve
                // isCash → cuenta Efectivo, y null cuando no hay mapeo).
                return $entry['accountId'] ?? $cashId;
            }
        }
        return $cashId;
    }

    /**
     * Métodos de pago reales del tenant (taxonomía paymentMethod). Delega en
     * PaymentMethods\PaymentMethodResolver — la lectura de la taxonomía y la
     * decodificación de `taxonomyExtra` viven ahí, compartidas con el resto de
     * los módulos que mapean pagos (facturación electrónica).
     *
     * @return array<int,array{id:string,name:string,code:?string,systemKey:?string}>
     */
    private function paymentMethods(string $companyId): array
    {
        return (new \Punto\Api\PaymentMethods\PaymentMethodResolver())->methods($companyId);
    }

    /** Valida que $accountId sea una fin_account de tipo 'bank' perteneciente a $companyId. */
    private function isBankAccountOfCompany(string $companyId, string $accountId): bool
    {
        $res = ncmExecute(
            "SELECT 1 FROM fin_account WHERE accountid = ? AND companyid = ? AND type = 'bank' LIMIT 1",
            [$accountId, $companyId],
            false,
            true
        );
        if (!$res || !is_object($res)) {
            return false;
        }
        $ok = !$res->EOF;
        $res->Close();
        return $ok;
    }

    /**
     * Lee el settingObj crudo. Mismo patrón que SettingsService::readSettingObj
     * (operador JSONB ->> directo — leer `config` completa via el wrapper
     * devuelve un valor no usable y causa clobber). null = lectura falló
     * (abortar write); [] = tenant nuevo sin settingObj (legítimo).
     */
    private function readSettingObj(string $companyId): ?array
    {
        $r = ncmExecute(
            "SELECT config->>'settingObj' AS so FROM company WHERE companyId = ? LIMIT 1",
            [$companyId],
            false,
            true
        );
        if (!$r || !is_object($r) || $r->EOF) {
            return null;
        }
        $so = $r->fields['so'] ?? null;
        $r->Close();
        if ($so === null || $so === '') {
            return [];
        }
        $obj = json_decode((string) $so, true);
        return is_array($obj) ? $obj : [];
    }
}
