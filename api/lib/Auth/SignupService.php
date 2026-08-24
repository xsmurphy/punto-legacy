<?php
/**
 * Servicio de registro de nuevas empresas para la API compartida.
 *
 * Port FIEL de `signUp()` (panel/includes/functions.php:9601-9865) con
 * dos cambios:
 *   - Retorna array estructurado en lugar de string mixto.
 *   - No emite cookie acá; el endpoint que lo llama usa PanelAuth::issuePanelSession.
 *
 * Single source of truth para el flujo de signup en /api. El panel legacy
 * mantiene su copia de `signUp()` hasta que desaparezca — cualquier cambio
 * (campos nuevos, validaciones, modules nuevos) debe replicarse en ambos
 * lados hasta entonces.
 *
 * Dependencias del entorno: ncmInsert, ncmUpdate, ncmExecute, passEncoder,
 * phoneToE164, findPhoneLogin, $countries global. Todo cargado por
 * /api/bootstrap.php → /app/head.php + /app/includes/functions.php.
 */

declare(strict_types=1);

namespace Punto\Api\Auth;

use Punto\Api\Support\Slug;

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

        // El flujo del frontend manda el teléfono como `email` (legacy carry-over:
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
            'status'      => 'active',
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

        // Cada eslabón de la cadena se chequea: si la sucursal no se creó, lo
        // que sigue (depósito, caja, usuario) colgaría de un outletId vacío y
        // el tenant nacería roto en vez de fallar acá.
        if (!$outletInsert) {
            $db->FailTrans();
            $db->CompleteTrans();
            return ['ok' => false, 'error' => 'No se pudo crear la sucursal inicial'];
        }

        // Depósito por defecto de la sucursal inicial. Toda sucursal tiene sí o
        // sí uno (regla del owner 2026-08-24) y este camino de alta lo estaba
        // salteando: el tenant nacía con "Central" sin ningún depósito, así
        // que el stock no tenía lugar físico donde estar. Va dentro de la
        // transacción del signup, ya abierta arriba.
        try {
            (new \Punto\Api\Taxonomies\LocationTaxonomyService($db))
                ->ensureDefault((string) $companyInsert, (string) $outletInsert, 'Central');
        } catch (\Throwable $e) {
            // Sin este catch la excepción sale con la transacción del signup
            // ABIERTA: el endpoint responde 500 y la conexión queda envenenada
            // para el resto del request.
            $db->FailTrans();
            $db->CompleteTrans();
            return ['ok' => false, 'error' => 'No se pudo crear el depósito de la sucursal inicial'];
        }

        $registerInsert = ncmInsert(['records' => [
            'registerName'   => 'Caja Principal',
            'registerStatus' => 1,
            'outletId'       => $outletInsert,
            'companyId'      => $companyInsert,
        ], 'table' => 'register']);

        // La caja es el último eslabón de la cadena obligatoria
        // Company > Sucursal > (Depósito | Caja) (context/08 §58) y hasta acá
        // era el único que fallaba en silencio: el depósito aborta por su
        // try/catch y la sucursal por el chequeo de arriba, pero un
        // `registerInsert` vacío seguía de largo y el tenant terminaba el
        // signup con una sucursal sin caja — sin poder abrir turno ni emitir un
        // solo documento, que es exactamente el estado que este invariante
        // existe para impedir.
        if (!$registerInsert) {
            $db->FailTrans();
            $db->CompleteTrans();
            return ['ok' => false, 'error' => 'No se pudo crear la caja de la sucursal inicial'];
        }

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

        // Slug inicial derivado del nombre — misma normalización/unicidad que
        // Settings > Empresa (Slug::class, mig 113_company_slug_unique.sql).
        // A diferencia del form de Settings, acá el slug es un nice-to-have
        // generado, no un valor pedido explícitamente por el usuario: si el
        // candidato normalizado colisiona, desambiguamos con un sufijo del
        // companyId (alta entropía, sin necesidad de otra query) en vez de
        // abortar el signup — el usuario puede editarlo después en Settings.
        $slugCandidate = Slug::normalize($storeName);
        $slug = null;
        if ($slugCandidate !== null) {
            $slug = Slug::isAvailable($slugCandidate, $companyInsert)
                ? $slugCandidate
                : $slugCandidate . '-' . substr($companyInsert, 0, 6);
        }

        $settingRecord = [
            'settingName'              => $storeName,
            'slug'                     => $slug,
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

        $taxonomyInsert = null;
        $vat = $countryData['currency']['vat'] ?? false;
        if ($vat) {
            // Dual-write tax + taxonomy (F0 del plan de impuestos multi-país,
            // context/38). `tax` pasa a ser la fuente única con rate/kind
            // explícitos (mig 120); `taxonomy` se sigue poblando porque
            // Taxonomy::getTaxValue() y el editor viejo de Ajustes siguen
            // leyendo de ahí hasta que migren (F2/F3) — este dual-write se
            // retira cuando muera el último lector legacy de taxonomy.
            //
            // Se escribe SOLO en `tax`, con rate/kind ya parseados; la fila
            // espejo en `taxonomy` la crea el trigger de la mig 23
            // (trg_tax_to_taxonomy, AFTER INSERT, mismo UUID). Insertar en
            // `tax` primero es lo que preserva rate/kind: si se sembrara
            // `taxonomy` primero, su trigger crearía la fila de `tax` "pelada"
            // (esos campos no están en el shape que sincroniza) y quedaría
            // rate=NULL hasta un backfill manual.
            $taxUuid = generateUuidV7();
            $vatMatches = [];
            $hasNumber = (bool) preg_match('/\d+(?:[.,]\d+)?/', (string) $vat, $vatMatches);
            $vatRate = $hasNumber ? (float) str_replace(',', '.', $vatMatches[0]) : 0.0;
            // Mismo guard <=100 que mig 120 y TaxService: un valor raro en
            // countries.php no puede desbordar DECIMAL(5,2) y matar el alta.
            if ($vatRate > 100) {
                $hasNumber = false;
                $vatRate   = 0.0;
            }
            $vatKind = $hasNumber ? 'rate' : 'exempt';

            ncmInsert(['records' => [
                'taxId'     => $taxUuid,
                'companyId' => $companyInsert,
                'name'      => $vat,
                'rate'      => $vatRate,
                'kind'      => $vatKind,
            ], 'table' => 'tax']);

            // NO insertar en taxonomy a mano: trg_tax_to_taxonomy (mig 23)
            // dispara AFTER INSERT y ya creó la fila espejo con el MISMO
            // UUID. Un insert explícito acá violaría la PK (23505) y
            // envenenaría la transacción — el alta entera moriría, la misma
            // clase de bug del itemKind (2026-08-07). El dual-write del
            // comentario de arriba lo cumple el trigger, no este código.
            $taxonomyInsert = $taxUuid;
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
                // `itemKind` es NOT NULL sin default desde mig 15 y este INSERT
                // no lo mandaba: violaba la constraint, envenenaba la
                // transacción y el alta entera moría con un 25P02 (reporte del
                // tester 2026-08-04). Los ítems demo son productos vendibles
                // comunes. Se arma con ItemKind para que el kind y los flags
                // legacy no puedan quedar desfasados.
                $itemInsert = ncmInsert(['records' => array_merge(
                    \Punto\Api\Items\ItemKind::insertRecord(),
                    [
                        'itemName'   => $item['name'],
                        'itemSKU'    => 'PS 00' . $i,
                        'itemStatus' => 1,
                        'taxId'      => $taxonomyInsert,
                        'itemImage'  => false,
                        'itemPrice'  => round(((float) $item['price']) * $priceScale, $decimals),
                        'companyId'  => $companyInsert,
                    ]
                ), 'table' => 'item']);
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
            'contactPhone'    => normalizePhoneForStorage($email),
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

        // Seed de roles del sistema para la company recién creada.
        require_once __DIR__ . '/RoleService.php';
        // `\` obligatorio: RoleService NO declara namespace (vive en el global),
        // y este archivo sí está en Punto\Api\Auth. Sin la barra, PHP resolvía a
        // `Punto\Api\Auth\RoleService`, que no existe, y el alta moría con
        // "Class not found". Mismo criterio que TransactionService:644.
        \RoleService::seedCompanyRoles((string) $companyInsert);

        // Login automático: recuperar el contact con todos los campos para
        // que PanelAuth::issuePanelSession lo use para emitir la sesion opaca.
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
