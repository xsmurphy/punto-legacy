<?php

if(!$companyId){
	die();
}

define('COMPANY_ID', $companyId);
define('OUTLET_ID', $outletId);
define('USER_ID', $userId);
define('REGISTER_ID', $registerId);
define('ROLE_ID', $roleId);

$SQLcompanyId             = "companyId = " . COMPANY_ID;
$SQLcompanyIdANDoutletId  = 'companyId = ' . COMPANY_ID . ' AND outletId = ' . OUTLET_ID;
$SQLcompanyIdJoin         = 'a.companyId = ' . COMPANY_ID;

$setting = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$_modules= ncmExecute("SELECT * FROM module WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$company = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

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
$compLogo       = 'https://assets.encom.app/80-80/0/' . enc(COMPANY_ID) . '.jpg';
$compCountryCode= $setting['settingCountry'];
$compPhoneCode  = '+' . $countries[$compCountryCode]['phone'];
$compSocial     = json_decode($setting['settingSocialMedia'],true);
$_fullSettings  = json_decode($setting['settingObj'],true);

include_once('languages/' . $compLanguage . '.php');

//outletdata
$allOutletData = getAllOutletData(OUTLET_ID);
$outName       = $allOutletData['name'];
define('OUTLET_NAME', $outName);
define('OUTLET_EMAIL', $allOutletData['email']);
define('OUTLET_PHONE', $allOutletData['phone']);
define('OUTLET_ADDRESS', $allOutletData['address']);
define('OUTLET_WHATS_APP',$allOutletData['whatsapp']);

date_default_timezone_set($setting['settingTimeZone']);
define('COUNTRY_CODE', $setting['settingCountry']);
define('COUNTRY', $setting['settingCountry']);
define('TODAY', date('Y-m-d H:i:s'));
define('TODAY_DATE', date('Y-m-d'));
define('TODAY_START', date('Y-m-d 00:00:00'));
define('TODAY_END', date('Y-m-d 23:59:59'));
define('PLAN_ID', $company['companyPlan']);
define('SMS_CREDIT', $company['companySMSCredit']);
define('COMPANY_NAME', $compName);
define('CURRENCY', $compCurrency);
define('DECIMAL', $compDecimal);
define('THOUSAND_SEPARATOR', $compThousand);

define('API_KEY', sha1($company['accountId']));
define('ECOMPANY_ID', enc(COMPANY_ID));

$plansValues = getAllPlans(PLAN_ID);

?>