<?php
/**
 * Servicio de registro de nuevas empresas para la API compartida.
 *
 * Port FIEL de `signUp()` (panel/includes/functions.php:9601-9865) con
 * dos cambios:
 *   - Retorna array estructurado en lugar de string mixto.
 *   - No emite cookie acá; el endpoint que lo llama usa PanelAuth::issueJwt.
 *
 * Single source of truth para el flujo de signup en /api. El panel legacy
 * mantiene su copia de `signUp()` hasta que desaparezca — cualquier cambio
 * (campos nuevos, validaciones, modules nuevos) debe replicarse en ambos
 * lados hasta entonces.
 *
 * Dependencias del entorno: ncmInsert, ncmUpdate, ncmExecute, passEncoder,
 * phoneToE164, findPhoneLogin, createSlug, $countries global. Todo cargado
 * por /api/bootstrap.php → /app/head.php + /app/includes/functions.php.
 */

declare(strict_types=1);

namespace Punto\Api\Auth;

final class SignupService
{
    /**
     * @param array{
     *   storename: string, username: string, password: string,
     *   category: string, country: string,
     *   email?: string, phone?: string,
     *   parent?: string
     * } $post Datos del formulario de signup.
     *
     * @return array{ok: true, contact: array, companyId: string} | array{ok: false, error: string}
     */
    public static function create(array $post): array
    {
        global $db, $countries;

        $email     = strtolower((string) ($post['email'] ?? $post['phone'] ?? ''));
        $storeName = ucwords((string) $post['storename']);

        // El flujo del panel-next manda el teléfono como `email` (legacy carry-over:
        // la columna BD contactEmail/contactPhone es histórica; el legacy mete el
        // phone en `email` cuando no es valid email). Mantenemos esa convención.
        if ($email === '') {
            return ['ok' => false, 'error' => 'Email/phone requerido'];
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $isEmail = true;
            $resultEmail = ncmExecute(
                'SELECT * FROM contact WHERE type = 0 AND contactEmail = ?',
                [$email]
            );
        } else {
            $isEmail = false;
            // CONVENCIÓN §31: phone storage SIEMPRE E.164 vía libphonenumber.
            $isoCountry = strtoupper((string) ($post['country'] ?? 'PY'));
            $normalized = phoneToE164($email, $isoCountry);
            if ($normalized === null) {
                return ['ok' => false, 'error' => 'Número de teléfono inválido'];
            }
            $email = $normalized;
            $resultEmail = ncmExecute(
                'SELECT * FROM contact WHERE type = 0 AND contactPhone = ?',
                [$email]
            );
        }

        if ($resultEmail) {
            return ['ok' => false, 'error' => 'Ya existe una cuenta con este número o email'];
        }

        $db->StartTrans();

        $accountId = mt_rand(); // placeholder hasta que exista admin de cuentas
        $companyRecord = [
            'companyName' => $storeName,
            'plan'        => 3,
            'status'      => 'Active',
            'expiresAt'   => date('Y-m-d 00:00:00', strtotime('+14 days')),
            'accountId'   => $accountId,
        ];
        if (!empty($post['parent'])) {
            $companyRecord['parentId'] = dec((string) $post['parent']);
        }
        $companyInsert = ncmInsert(['records' => $companyRecord, 'table' => 'company']);

        $outletInsert = ncmInsert(['records' => [
            'outletName'   => 'Central',
            'outletStatus' => 1,
            'companyId'    => $companyInsert,
        ], 'table' => 'outlet']);

        $registerInsert = ncmInsert(['records' => [
            'registerName'   => 'Caja Principal',
            'registerStatus' => 1,
            'outletId'       => $outletInsert,
            'companyId'      => $companyInsert,
        ], 'table' => 'register']);

        // Settings derivados del país del usuario.
        $countryCode = strtoupper((string) ($post['country'] ?? 'PY'));
        $countryData = $countries[$countryCode] ?? $countries['PY'] ?? [];
        $cSymbol     = $countryData['currency']['symbol']         ?? '$';
        $decimals    = (int) ($countryData['currency']['decimal_digits'] ?? 0);
        $lang        = explode(',', (string) ($countryData['languages'] ?? 'es'));
        $decim       = ($decimals < 1) ? 'no' : 'yes';
        $taxName     = $countryData['currency']['vat_name']        ?? 'VAT';
        $tin         = $countryData['tin']                         ?? 'TIN';
        $timezone    = $countryData['timezone']                    ?? 'America/Asuncion';

        $settingRecord = [
            'settingName'              => $storeName,
            'settingCurrency'          => $cSymbol ?: '$',
            'settingCountry'           => $countryCode,
            'settingLanguage'          => $lang[0] ?: 'es',
            'settingTimeZone'          => $timezone,
            'settingAcceptedTerms'     => 1,
            'settingBillTemplate'      => 'ticket',
            'settingDecimal'           => $decim,
            'settingThousandSeparator' => 'dot',
            'settingTaxName'           => $taxName ?: 'VAT',
            'settingTIN'               => $tin ?: 'TIN',
            'settingCompanyCategoryId' => (string) ($post['category'] ?? ''),
        ];
        // Settings van como JSONB en la fila company existente (no insert).
        $settingInsert = ncmUpdate([
            'records' => $settingRecord,
            'table'   => 'company',
            'where'   => "companyId = '" . $companyInsert . "'",
        ]);

        createSlug($companyInsert, $storeName);

        $taxonomyInsert = null;
        $vat = $countryData['currency']['vat'] ?? false;
        if ($vat) {
            $taxonomyInsert = ncmInsert(['records' => [
                'taxonomyName' => $vat,
                'taxonomyType' => 'tax',
                'companyId'    => $companyInsert,
            ], 'table' => 'taxonomy']);
        }

        // Modules + demo items basados en categoría — patrones declarados en
        // InstallConfig (port de panel/includes/config.php $installConfig).
        $category = (string) ($post['category'] ?? '');
        $moduleRecord = [];
        foreach (InstallConfig::all() as $val) {
            if (!in_array($category, $val['match'], true)) {
                continue;
            }
            foreach ($val['modules'] as $mod) {
                // Whitelist de claves de módulo aceptadas en company.config
                if (in_array($mod, [
                    'calendar','schedule','tables','ordersPanel','ecom','dunning',
                    'recurring','kds','production','feedback',
                ], true)) {
                    // schedule del InstallConfig se mapea a la flag 'calendar' del legacy.
                    $key = ($mod === 'schedule') ? 'calendar' : $mod;
                    $moduleRecord[$key] = 1;
                }
            }
        }
        if ($moduleRecord) {
            ncmUpdate(['records' => $moduleRecord, 'table' => 'company',
                'where' => 'companyId = ' . $db->qstr($companyInsert)]);
        }

        // Demo items + hotkeys del register
        // Precios demo en guaraníes; dividir por 100 para currencies con decimales.
        $priceScale = ($decimals >= 2) ? 0.01 : 1.0;
        foreach (InstallConfig::all() as $val) {
            if (!in_array($category, $val['match'], true)) {
                continue;
            }
            $hotKeys = [];
            foreach ($val['items'] as $i => $item) {
                $itemInsert = ncmInsert(['records' => [
                    'itemName'   => $item['name'],
                    'itemSKU'    => 'PS 00' . $i,
                    'itemStatus' => 1,
                    'taxId'      => $taxonomyInsert,
                    'itemImage'  => false,
                    'itemPrice'  => round(((float) $item['price']) * $priceScale, $decimals),
                    'companyId'  => $companyInsert,
                ], 'table' => 'item']);
                $hotKeys[] = ['color' => '', 'itemId' => $itemInsert, 'position' => ($i + 1)];
            }
            ncmUpdate(['records' => ['registerHotkeys' => json_encode($hotKeys)],
                'table' => 'register',
                'where' => 'registerId = ' . $db->qstr($registerInsert)
                        . ' AND companyId = ' . $db->qstr($companyInsert)]);
        }

        // Cliente placeholder ("Primer Cliente")
        ncmInsert(['records' => [
            'contactName' => 'Primer Cliente',
            'companyId'   => $companyInsert,
            'type'        => '1',
        ], 'table' => 'contact']);

        // Usuario admin
        if (!($outletInsert && $companyInsert && $registerInsert && $settingInsert)) {
            $db->FailTrans();
            $db->CompleteTrans();
            return ['ok' => false, 'error' => $db->ErrorMsg() ?: 'No se pudo crear la cuenta'];
        }

        $passSalt = passEncoder((string) $post['password']);
        $userInsert = ncmInsert(['records' => [
            'contactName'     => ucwords((string) $post['username']),
            'contactPassword' => $passSalt[0],
            'contactPhone'    => $email,
            'contactInCalendar' => 1,
            'companyId'       => $companyInsert,
            'outletId'        => $outletInsert,
            'main'            => 'true',
            'role'            => 1, // 1 = Super Admin
            'salt'            => $passSalt[1],
            'lockPass'        => '1111',
            'type'            => '0',
        ], 'table' => 'contact']);

        if ($db->HasFailedTrans()) {
            $db->CompleteTrans();
            return ['ok' => false, 'error' => $db->ErrorMsg() ?: 'No se pudo crear el usuario'];
        }
        $db->CompleteTrans();

        // Login automático: recuperar el contact con todos los campos para
        // que PanelAuth::issueJwt lo use para emitir el JWT.
        $contact = findPhoneLogin($email);
        if (!$contact) {
            // Recién insertado pero no lo encontramos por phone — replication
            // lag o lookup roto. No bloqueamos el signup; el user puede loguear
            // con un retry.
            return ['ok' => true, 'contact' => [
                'contactId' => $userInsert,
                'companyId' => $companyInsert,
                'role'      => 1,
            ], 'companyId' => $companyInsert];
        }
        return ['ok' => true, 'contact' => is_object($contact) ? iterator_to_array($contact) : $contact,
            'companyId' => $companyInsert];
    }
}
