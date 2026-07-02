<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * Config mínima de Finanzas: mapa método de pago → cuenta
 * (`company.config.settingObj.finAccountMap`).
 *
 * Reglas de negocio (owner, 2026-07-02 — ver context/22 §3/§7):
 *   - "efectivo" SIEMPRE mapea a la cuenta Efectivo (issystem=true). Fijo,
 *     no editable desde la UI — no se persiste en el map (se resuelve en
 *     read()).
 *   - Los demás métodos (tarjeta débito/crédito, transferencia, billetera,
 *     cheque, etc.) son asignables por el usuario a cualquier cuenta type=
 *     'bank' que haya creado. Si un método no tiene banco asignado, cae a
 *     Efectivo como fallback (nunca se pierde el movimiento).
 *   - El auto-seed NO crea ningún banco placeholder — el usuario crea sus
 *     propias cuentas bancarias.
 *
 * Persistencia: MERGE no-destructivo sobre settingObj (mismo patrón que
 * SettingsService::readSettingObj — lee con `config->>'settingObj'`, nunca
 * pisa el blob completo).
 */
final class ConfigService
{
    private const MANAGED_METHODS = [
        'tarjeta_debito', 'tarjeta_credito', 'transferencia', 'billetera', 'cheque', 'otro',
    ];

    /**
     * Devuelve el mapa completo resuelto: 'efectivo' siempre → cuenta
     * Efectivo (fijo); los demás métodos → accountId asignado o null si no
     * se asignó todavía (el caller/UI debe tratar null como "cae en Efectivo").
     */
    public function read(string $companyId): array
    {
        $cashId = (new AccountService())->ensureCashAccountId($companyId);
        $obj = $this->readSettingObj($companyId) ?? [];
        $map = is_array($obj['finAccountMap'] ?? null) ? $obj['finAccountMap'] : [];

        $resolved = ['efectivo' => $cashId];
        foreach (self::MANAGED_METHODS as $method) {
            $resolved[$method] = isset($map[$method]) ? (string) $map[$method] : null;
        }
        return $resolved;
    }

    /**
     * Actualiza el mapa (solo métodos gestionables — 'efectivo' se ignora
     * silenciosamente si viene en el payload, es fijo). MERGE no-destructivo:
     * preserva otras keys de settingObj (currencies, logo, etc.).
     *
     * @param array<string,string|null> $map method => accountId|null
     */
    public function update(string $companyId, array $map): array
    {
        $obj = $this->readSettingObj($companyId);
        if ($obj === null) {
            throw new \RuntimeException('No se pudo leer la configuración actual — abortado para no perder datos');
        }
        $current = is_array($obj['finAccountMap'] ?? null) ? $obj['finAccountMap'] : [];

        foreach (self::MANAGED_METHODS as $method) {
            if (!array_key_exists($method, $map)) {
                continue;
            }
            $accountId = $map[$method];
            if ($accountId === null || $accountId === '') {
                $current[$method] = null;
                continue;
            }
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $accountId)) {
                throw new \RuntimeException("accountId inválido para {$method}");
            }
            $current[$method] = (string) $accountId;
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
     * derivado (Fase 3 — FinanceLedger). 'efectivo' (y aliases legacy 'cash')
     * → siempre Efectivo. Otro método sin banco asignado → fallback Efectivo.
     */
    public function resolveAccountId(string $companyId, string $paymentMethodKey): string
    {
        $cashId = (new AccountService())->ensureCashAccountId($companyId);
        $normalized = $this->normalizeMethodKey($paymentMethodKey);
        if ($normalized === 'efectivo') {
            return $cashId;
        }
        $map = $this->read($companyId);
        $accountId = $map[$normalized] ?? null;
        return $accountId !== null && $accountId !== '' ? $accountId : $cashId;
    }

    /** Normaliza keys legacy/POS (cash, creditcard, debitcard, tcredito...) al vocabulario de Finanzas. */
    private function normalizeMethodKey(string $key): string
    {
        $aliases = [
            'cash' => 'efectivo', 'efectivo' => 'efectivo',
            'creditcard' => 'tarjeta_credito', 'tcredito' => 'tarjeta_credito', 'card' => 'tarjeta_credito',
            'debitcard' => 'tarjeta_debito', 'tdebito' => 'tarjeta_debito',
            'transfer' => 'transferencia', 'transferencia' => 'transferencia',
            'check' => 'cheque', 'cheque' => 'cheque',
            'storeCredit' => 'billetera', 'inCredit' => 'billetera', 'billetera' => 'billetera',
        ];
        return $aliases[$key] ?? 'otro';
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
