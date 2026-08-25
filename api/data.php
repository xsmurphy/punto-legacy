<?php

// `head.php` carga `libraries/countries.php` en scope GLOBAL, pero este archivo
// se hace `require` DESDE ADENTRO de `apiAuthTenant()` (api/bootstrap.php), o
// sea en scope de función: sin este `global`, `$countries` no se ve acá y la
// línea de `$compPhoneCode` explota con "Undefined variable $countries" (Whoops
// la promueve a excepción → 500 en todo endpoint que pase por ese camino).
// `global` en scope global es un no-op, así que el include directo sigue
// funcionando igual.
global $countries;

if(!$companyId){
	die();
}

define('COMPANY_ID', $companyId);
define('OUTLET_ID', $outletId);
define('USER_ID', $userId);
define('REGISTER_ID', $registerId);
define('ROLE_ID', $roleId);

// En PG las UUIDs DEBEN ir entre comillas en SQL string concat — sino "trailing junk after numeric literal".
// MySQL int companyIds quedaban sin comillas porque eran numéricas. Migración a PG → quotes obligatorias.
$SQLcompanyId             = "companyId = '" . COMPANY_ID . "'";
$SQLcompanyIdANDoutletId  = "companyId = '" . COMPANY_ID . "' AND outletId = '" . OUTLET_ID . "'";
$SQLcompanyIdJoin         = "a.companyId = '" . COMPANY_ID . "'";

// Una sola query: $setting/$_modules/$company eran 3 SELECT idénticos a la MISMA
// fila de company (config + flags de módulo + plan/accountId viven todos ahí).
// Colapsado a 1 round-trip; los 3 nombres se mantienen para backward-compat con
// los callers que leen cada alias por su semántica.
$company  = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$setting  = $company;
$_modules = $company;

$dec            = $setting['settingDecimal'];
$ts             = $setting['settingThousandSeparator'];

$compEmail      = $setting['settingEmail'];
$compPhone      = $setting['settingPhone'];
$compName       = $setting['settingName'];
$compWebsite    = $setting['settingWebsite'];
$compCurrency   = $setting['settingCurrency'];
$compDecimal    = $setting['settingDecimal'];
$compThousand   = $setting['settingThousandSeparator'];
$compTaxName    = $setting['settingTaxName'];
$compLanguage   = iftn($setting['settingLanguage'],'es');
$compLoyalty    = $_modules['loyalty'];
$compSCredit    = iftn($setting['settingStoreCredit'],0);
$compLogo       = '/assets/80-80/0/' . enc(COMPANY_ID) . '.jpg';
$compCountryCode= $setting['settingCountry'];
$compPhoneCode  = '+' . $countries[$compCountryCode]['phone'];
$compSocial     = json_decode($setting['settingSocialMedia'],true);
$_fullSettings  = json_decode($setting['settingObj'],true);

$_langFile = __DIR__ . '/languages/' . $compLanguage . '.php';
if (is_file($_langFile)) { include_once($_langFile); } // i18n por archivo (opcional; sin archivos hoy)

//outletdata
$allOutletData = getAllOutletData(OUTLET_ID);
$outName       = $allOutletData['name'];
define('OUTLET_NAME', $outName);
define('OUTLET_EMAIL', $allOutletData['email']);
define('OUTLET_PHONE', $allOutletData['phone']);
define('OUTLET_ADDRESS', $allOutletData['address']);
define('OUTLET_WHATS_APP',$allOutletData['whatsapp']);

// Zona horaria del request. Éste es el punto donde el tenant recién queda
// resuelto, así que es acá —y no en la conexión (`includes/db.php`, que corre
// antes de saber quién opera)— donde se fija la TZ real del comercio, tanto
// para PHP como para la sesión de PostgreSQL.
//
// `seed()` le pasa a TenantLocale la fila de company que ya leímos arriba,
// para que resolver el país/TZ no dispare un SELECT extra por request.
//
// Ya NO hay fallback a 'America/Asuncion': si `settingTimeZone` viene vacío o
// inválido, la TZ se deriva del PAÍS del comercio (settingCountry) y recién
// después de la plataforma. Un tenant paraguayo termina igual que antes; uno
// argentino deja de operar con el reloj de Asunción.
require_once __DIR__ . '/lib/Support/CountryDefaults.php';
require_once __DIR__ . '/lib/Support/TenantLocale.php';
require_once __DIR__ . '/lib/Support/TenantClock.php';
\Punto\Api\Support\TenantLocale::seed(COMPANY_ID, $setting);
$__tz = \Punto\Api\Support\TenantClock::apply(COMPANY_ID);
define('COUNTRY_CODE', $setting['settingCountry']);
define('COUNTRY', $setting['settingCountry']);
define('TODAY', date('Y-m-d H:i:s'));
define('TODAY_DATE', date('Y-m-d'));
define('TODAY_START', date('Y-m-d 00:00:00'));
define('TODAY_END', date('Y-m-d 23:59:59'));
define('PLAN_ID', $company['plan']);
define('SMS_CREDIT', $company['smsCredit']);
define('COMPANY_NAME', $compName);
define('CURRENCY', $compCurrency);
define('DECIMAL', $compDecimal);
define('THOUSAND_SEPARATOR', $compThousand);

define('API_KEY', sha1($company['accountId']));
define('ECOMPANY_ID', enc(COMPANY_ID));

$plansValues = getAllPlans(PLAN_ID);

?>