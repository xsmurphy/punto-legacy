<?php
//error_reporting(-1);
//ini_set('display_errors', 'Off');

$origin 			= $_SERVER['HTTP_ORIGIN'];
$allowed_domains 	= [
    '/',
    '',
    '',
    '/assets',
    API_URL,
    '',
    // ecommerce — configurar via ECOMMERCE_URL
	'https://ncmsite.com'
];

if (in_array($origin, $allowed_domains)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST');
	header('Access-Control-Allow-Headers: Content-Type');
}else{
	
}

function theErrorHandler($type=false){
  $whoops = new \Whoops\Run;

  if($_GET['debug']){
    if($type == 'plain'){
      $whoops->pushHandler(new \Whoops\Handler\PlainTextHandler);
      $whoops->register();
    }else if($type == 'json'){
      $whoops->pushHandler(new \Whoops\Handler\JsonResponseHandler);
      $whoops->register();
    }else{
      $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
      $whoops->register();
    }
  }else{
  	if($type == 'json'){
      $whoops->pushHandler(new \Whoops\Handler\JsonResponseHandler);
      $whoops->register();
    }else{
	    $whoops->pushHandler(function($exception, $inspector, $run) {
	        include_once('includes/errorPage.inc.php');
	        return true;
	    });
	    $whoops->register();
	}
  }
}

if(function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()){
	function undo_magic_quotes_gpc(&$array){
		foreach($array as &$value){
			if(is_array($value)){
				undo_magic_quotes_gpc($value);
			}else{
				$value = stripslashes($value);
			}
		}
	} 

	undo_magic_quotes_gpc($_POST);
	undo_magic_quotes_gpc($_GET);
	undo_magic_quotes_gpc($_COOKIE);
}

function printSessionData($data){ //anhade seguridad al utilizar las variables de sesion
	
	//$data = $_SESSION['user']['companyId'];
	if(isset($data) && !empty($data)){
		return htmlentities($data, ENT_QUOTES, 'UTF-8');
	}else{
		return '0';
	}
}

function enc($str): string { return (string)$str; }

function dec($str): string { return (string)$str; }

function ncmEncode($str){
	$based 		= base64_encode($str);
	$encoded 	= openssl_encrypt($based,"AES-128-ECB",SALT);
	return $encoded;
}

function ncmDecode($str){
	$based 		= openssl_decrypt($str,"AES-128-ECB",SALT);
	$decoded 	= base64_decode($based);
	return $decoded;
}

function getCompanyLoginSession($companyId,$encomADM = false){
	global $db;
	$contact 		= $db->Execute('SELECT * FROM contact WHERE role = 1 AND type = 0 AND companyId = ? LIMIT 1', [$companyId]);
	$outlet 		= $db->Execute("SELECT * FROM outlet WHERE companyId = ? LIMIT 1",[$companyId]);
	$company 		= $db->Execute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[$companyId]);
	$outletCount 	= $db->Execute("SELECT COUNT(outletId) as count FROM outlet WHERE outletStatus = 1 AND companyId = ?",[$companyId]);

	if($contact && $contact->RecordCount() > 0){
		unset($contact->fields['salt'],$contact->fields['contactPassword'],$contact->fields['role']);

		$_SESSION['last_activity']      	= time();
		$_SESSION['user']['companyId']  	= enc($contact->fields['companyId']);
		$_SESSION['user']['companyDB']  	= $contact->fields['companyDB'];

		$_SESSION['user']['companyStatus']  = $company->fields['status'];
		$_SESSION['user']['companyParent']  = 0;
		$_SESSION['user']['userName']     = $contact->fields['contactName'];
		$_SESSION['user']['userEmail']    = $contact->fields['contactEmail'];
		$_SESSION['user']['role']         = enc($contact->fields['role']);
		$_SESSION['user']['outletId']     = enc($outlet->fields['outletId']);
		$_SESSION['user']['registerId']   = 0;
		$_SESSION['user']['plan']         = enc($company->fields['plan']);
		$_SESSION['user']['planExpires']  = $company->fields['expiresAt'];
		$_SESSION['user']['outletsCount'] = $outletCount->fields['count'];
		$_SESSION['user']['startDate']    = false;
		$_SESSION['user']['endDate']      = false;
		$_SESSION['user']['ENCOM_ADM']    = $encomADM;

	}

	return;
}

date_default_timezone_set('America/Asuncion');//si no esta definido el timezone

define('LANGUAGE', 'es_es'); // pongo fuera del check de session porque si no hay session da un error de que no encuentra el archivo

$dias 	= ["Domingo","Lunes","Martes","Miercoles","Jueves","Viernes","Sábado"];
$meses 	= ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];

header('Content-Type: text/html; charset=utf-8');

if(isset($_SESSION['user'])){
	$sU = $_SESSION['user'];
	//define('SESSION_TIMEOUT', 604800);
	define('COMPANY_ID', printSessionData(dec($sU['companyId'])));
	define('COMPANY_DB', printSessionData($sU['companyDB']));
	define('COMPANY_IS_PARENT', printSessionData(($sU['companyParent'])));
	define('USER_ID', printSessionData(dec($sU['userId'])));
	define('USER_NAME', printSessionData($sU['userName']));
	define('USER_EMAIL', printSessionData($sU['userEmail']));
	define('USER_ADDRESS', printSessionData($sU['userAddress']));
	define('USER_PHONE', printSessionData($sU['userPhone']));
	define('ROLE_ID', printSessionData(dec($sU['role'])));
	define('OUTLET_ID', printSessionData(dec($sU['outletId'])));
	define('FIXED_OUTLET_ID', printSessionData(dec($sU['outletId'])));
	define('OUTLETS_COUNT', printSessionData($sU['outletsCount']));
	define('REGISTER_ID', printSessionData(dec($sU['registerId'])));
	define('PLAN', printSessionData(dec($sU['plan']))); //0 free, 1 Company, 2 full, 3 trial, 4 Privilege, 5 Starter, 6 Nada, 7 Micro
	define('PLAN_EXPIRING_DATE', printSessionData($sU['planExpires']));

	if($sU['ENCOM_ADM']){
		if($_GET['backToENCOM'] == 'true'){
			getCompanyLoginSession(15,true);
			header('location:/main');
  			die();
		}else{
			define('ENCOM_ADM', true);
		}
	}else{
		define('ENCOM_ADM', false);
	}
	
	$setting 		= $db->Execute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
	$_cmpSettings 	= $setting->fields;

	$modules 		= $db->Execute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
	$_modules 		= $modules->fields;
									
	define('COMPANY_NAME', $_cmpSettings['settingName']);
	define('COMPANY_PHONE', $_cmpSettings['settingPhone']);
	define('TAX_NAME', ($_cmpSettings['settingTaxName']) ? $_cmpSettings['settingTaxName'] : 'IVA');
	define('TIN_NAME', $_cmpSettings['settingTIN']);
	define('DECIMAL', $_cmpSettings['settingDecimal']);
	define('THOUSAND_SEPARATOR', $_cmpSettings['settingThousandSeparator']);
	define('CURRENCY', $_cmpSettings['settingCurrency']);
	define('COUNTRY', $_cmpSettings['settingCountry']);
	define('CITY', $_cmpSettings['settingCity']);
	define('WEBSITE', $_cmpSettings['settingWebSite']);
	define('TIMEZONE', $_cmpSettings['settingTimeZone']);
	define('CATEGORY_ID', $_cmpSettings['settingCompanyCategoryId']);
	setcookie('category_id', $_cmpSettings['settingCompanyCategoryId'], time() + (86400 * 300), "/");
	define('REGISTER_CONTROL', 'yes');
	define('ACCEPTED_TERMS', $_cmpSettings['settingAcceptedTerms']);
	define('EXPIRED', $_cmpSettings['planExpired']);
	define('LOYALTY', $_modules['loyalty']);
	define('STORE_CREDIT', $_cmpSettings['settingStoreCredit']);
	define('LINE_CREDIT', $_cmpSettings['settingForceCreditLine']);
	define('COMPANY_IS_TRIAL', $_cmpSettings['settingIsTrial']);
	define('EXTRA_USERS', $_modules['extraUsers']);
	define('SCHEDULE', $_modules['calendar']);
	define('ENCOM_UID', $_cmpSettings['settingEncomID']);

	$setting->Close();
	$modules->Close();

	$companyData = $db->Execute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
	define('BALANCE', $companyData->fields['balance']);
	define('SMS_CREDIT', $companyData->fields['smsCredit']);
	define('COMPANY_DATE', $companyData->fields['createdAt']);
	define('LAST_TIME_EDIT', $companyData->fields['companyLastUpdate']);
	define('COMPANY_DISCOUNT', $companyData->fields['discount']);
	define('API_KEY', sha1($companyData->fields['accountId']));

	$companyData->Close();

	$SQLcompanyIdANDoutletId 	= 'companyId = '.COMPANY_ID.' AND outletId = '.OUTLET_ID;
	$SQLcompanyId 				= 'companyId = '.COMPANY_ID;
	$SQLregisterId 				= 'registerId = '.REGISTER_ID;
	$SQLcompanyIdJoin 			= 'a.companyId = '.COMPANY_ID;

	date_default_timezone_set(TIMEZONE);	

	//GET ALL OUTLETS ARRAY
	$allOutletsArray 	= [];
	$result 			= $db->Execute("SELECT outletName, outletId FROM outlet WHERE companyId = ?",[COMPANY_ID]);

	while (!$result->EOF) {
		$fields = $result->fields;
	    $allOutletsArray[$fields['outletId']] = [
													"name"	=>$fields['outletName'],
													"id" 	=>$fields['outletId']
												];
	    $result->MoveNext(); 
	}
	$result->Close();
	//

	if($_GET['acceptTerms'] && ACCEPTED_TERMS < 1){
		$record['settingAcceptedTerms'] = 1;
		$update = $db->AutoExecute('setting', $record, 'UPDATE', 'companyId = '.COMPANY_ID);
		if($update === true){
			header('location: /@#dashboard');
		}
	}

}

define('INCOME_COMPANY_ID', 15);
define('ENCOM_COMPANY_ID', 15);
define('INCOME_USER_ID', 32);
define('INCOME_OUTLET_ID', 254);
define('INCOME_REGISTER_ID', 2244);


//ids de los productos de income
define('PLAN_COMPANY_ID', 27558);
define('PLAN_FULL_ID', 32491);
define('PLAN_STARTER_ID', 27559);
define('PLAN_MICRO_ID', 27560);
define('PLAN_FULL_ID', 32491);

define('ASSETS_URL', '/assets');
define('SYSIMGS_FOLDER', '../assets/sysimages');

define('TODAY', date('Y-m-d H:i:s'));

//$plansValues[PLAN]['max_brands']

//plans data
$plansNames 				= array('Free'=>'0','Company'=>'1','Full S'=>'2','Trial'=>'3','Privilege'=>'4');
$dayHours					= array('00');
$languages 					= array("Español"=>"es","English"=>"en","Portugues"=>"pt");
$yesNo 						= array('Si'=>'yes','No'=>'no');
$yesNoBool					= array('Si'=>1,'No'=>0);
$status 					= array('Deshabilitado','Habilitado');
$thousandSeparator 			= array('Coma'=>'comma','Punto'=>'dot');
$billTemplate 				= array('Ticket'=>'ticket','Factura'=>'bill');
$transactionState			= array('sale'=>'0','buy'=>'1','save'=>'2');

$inventoryControlType 		= array('FIFO'=>0,'LIFO'=>1,'Aleatorio'=>2,'FEFO'=>3);

$expenses					= array('Alquileres','Utilidades','Seguros','Impuestos','Servicios','Salarios','Equipamiento','Suministros','Prestamos','Mantenimiento','Mercaderías','Viajes','Capacitación','Otros','Extracción de caja');
//Utilities, Insurance, Fees, Wages, Taxes, Interest, Supplies, Depreciation, Maintenance, Travel, Entertainment, Training
//Utilidades (Luz, agua, Internet, etc), //Seguro (Medico, IPS, negocio), //Servicios (contadfor, abogados, etc), 
//Salarios (empleados, contratistas,etc), //Impuestos (IVA, IRP, etc), //Prestamos (Pagos mensuales de prestamos), 
//Suministros (de oficina, recargas de tinta, etc), //Equipamientos (computadoras, vehiculos, muebles), //Mercaderias, //Mantenimiento (limpieza, seguridad, etc)
//Viajes (de negocios, colectivos, etc), Capacitación, Otros

$companyCategories			= array( 
									'Health and Fitness' =>array(
										'Gym/Health Club' 		=>'0.1',
										'Personal Trainer' 		=>'0.2',
										'Alternative Medicine' 	=>'0.3',
										'Other' 				=>'0'
									),
									'Food and Beverages'=>array(
										'Bakery'=>'1.1',
										'Bar/Club'=>'1.2',
										'Coffe/Tea Shop'=>'1.3',
										'Food Truck'=>'1.4',
										'Fast Food'=>'1.6',
										'Restaurant'=>'1.7',
										'Healthy Food'=>'1.8',
										'Wines/Drinks'=>'1.9',
										'Beverage'=>'1.10',
										'Other'=>'1'
									),
									'Retail'=>array(
										'Art/Photo/Film'=>'2.1',
										'Books/Music/Video'=>'2.2',
										'Clothing/Accessories'=>'2.3',
										'Electronics'=>'2.4',
										'Gifts'=>'2.5',
										'Grocery/Market'=>'2.6',
										'Hardware Store'=>'2.7',
										'Jewelry/Watches'=>'2.8',
										'Pets Store'=>'2.9',
										'Sporting Goods'=>'2.10',
										'Home/Decoration'=>'2.11',
										'Kids/Babies'=>'2.12',
										'Other'=>'2'
									),
									'Repair'=>array(
										'Automotive Services'=>'3.1',
										'Clothing/Shoe Repair/Laundry'=>'3.3',
										'Computer/Electronics'=>'3.4',
										'Home Services'=>'3.5',
										'Other'=>'3'
									),
									'Transportation'=>array(
										'Delivery'=>'4.1',
										'Limousine'=>'4.2',
										'Taxi'=>'4.3',
										'Bus'=>'4.4',
										'Moving'=>'4.5',
										'Other'=>'4'
									),
									'Beauty'=>array(
										'Beauty Salon'=>'5.1',
										'Hair Salon/Barber Shop'=>'5.2',
										'Massage'=>'5.3',
										'Nail Salon'=>'5.4',
										'Spa'=>'5.5',
										'Tanning Salon'=>'5.6',
										'Tattoon/Piercing'=>'5.7',
										'Other'=>'5'
									),
									'Professional Services'=>array(
										'Accounting'=>'6.1',
										'Consulting'=>'6.2',
										'Design'=>'6.3',
										'Marketing'=>'6.4',
										'Real State'=>'6.5',
										'Other'=>'6'
									),
								);




$regions = array(
    'Africa' 		=> DateTimeZone::AFRICA,
    'America' 		=> DateTimeZone::AMERICA,
    'Antarctica' 	=> DateTimeZone::ANTARCTICA,
    'Aisa' 			=> DateTimeZone::ASIA,
    'Atlantic' 		=> DateTimeZone::ATLANTIC,
    'Europe' 		=> DateTimeZone::EUROPE,
    'Indian' 		=> DateTimeZone::INDIAN,
    'Pacific' 		=> DateTimeZone::PACIFIC
);

$measurements = array(
					"Unidad" 	=>array(
										"Unidad" 					=>"uni",
										"Pieza" 					=>"pieza",
										),
					"Peso" 		=>array(
										"Gramo" 					=>"g",
										"Kilogramo"					=>"kg",
										"Tonelada" 					=>"t",
										"Libra" 					=>"lb"
										),
					"Longitud" 	=>array(
										"Centímetro" 				=>"cm",
										"Metro"						=>"m",
										"Pulgada"					=>"in",
										"Área"						=>"a",
										"Centímetro cuadrado)" 		=>"cm2",
										"Metro cuadrado" 			=>"m2",
										"Pulgada cuadrada" 			=>"in2",
										),
					"Volúmen" 	=>array(
										"Mililitro" 				=>"ml",
										"Litro" 					=>"l",
										"Galón" 					=>"gal",
										),
					"Tiempo" 	=>array(
										"Hora" 						=>"h",
										"Minuto" 					=>"min",
										"Segundo" 					=>"s",
										)
				);

//DEFAULT PROFILE PERMISIONS
//obtengo el index del rol

//obtengo los permisos configurados de la empresa


$_BOSS = array (
	'panel' => 
	array (
		'blocked' => true,
		'access' => true,
		'dashboard' => 
		array (
			'view' => true,
		),
		'items' => 
		array (
			'view' => true,
			'edit' => true,
			'delete' => true,
		),
		'contacts' => 
		array (
			'view' => true,
			'edit' => true,
			'delete' => true,
		),
		'reports' => 
		array (
			'sales' => 
			array (
				'view' => true,
				'edit' => true,
				'delete' => true,
			),
			'expenses' => 
			array (
				'view' => true,
				'edit' => true,
				'delete' => true,
			),
		),
		'settings' => 
		array (
			'view' => true,
			'edit' => true,
			'delete' => true,
		),
	),
	'register' => 
	array (
		'blocked' => true,
		'access' => true,
		'transactions' => 
		array (
			'create' => true,
			'view' => true,
			'edit' => true,
			'delete' => true,
		),
		'sales' => 
		array (
			'discounts' => true,
			'price' => true,
		),
		'settings' => 
		array (
			'view' => true,
			'edit' => true,
		),
	),
);

$_MANAGER = array (
  'panel' => 
  array (
    'blocked' => false,
    'access' => true,
    'dashboard' => 
    array (
      'view' => true,
    ),
    'items' => 
    array (
      'view' => true,
      'edit' => true,
      'delete' => true,
    ),
    'contacts' => 
    array (
      'view' => true,
      'edit' => true,
      'delete' => true,
    ),
    'reports' => 
    array (
      'sales' => 
      array (
        'view' => true,
        'edit' => true,
        'delete' => true,
      ),
      'expenses' => 
      array (
        'view' => true,
        'edit' => true,
        'delete' => true,
      ),
    ),
    'settings' => 
    array (
      'view' => true,
      'edit' => true,
      'delete' => true,
    ),
  ),
  'register' => 
  array (
    'blocked' => false,
    'access' => true,
    'transactions' => 
    array (
      'create' => true,
      'view' => true,
      'edit' => true,
      'delete' => true,
    ),
    'sales' => 
    array (
      'discounts' => true,
      'price' => true,
    ),
    'settings' => 
    array (
      'view' => true,
      'edit' => true,
    ),
  ),
);

$_MANAGER_LIGHT = array (
  'panel' => 
  array (
    'blocked' => false,
    'access' => true,
    'dashboard' => 
    array (
      'view' => false,
    ),
    'items' => 
    array (
      'view' => true,
      'edit' => false,
      'delete' => false,
    ),
    'contacts' => 
    array (
      'view' => true,
      'edit' => false,
      'delete' => false,
    ),
    'reports' => 
    array (
      'sales' => 
      array (
        'view' => false,
        'edit' => false,
        'delete' => false,
      ),
      'expenses' => 
      array (
        'view' => true,
        'edit' => true,
        'delete' => false,
      ),
    ),
    'settings' => 
    array (
      'view' => false,
      'edit' => false,
      'delete' => false,
    ),
  ),
  'register' => 
  array (
    'blocked' => false,
    'access' => true,
    'transactions' => 
    array (
      'create' => true,
      'view' => true,
      'edit' => true,
      'delete' => false,
    ),
    'sales' => 
    array (
      'discounts' => false,
      'price' => false,
    ),
    'settings' => 
    array (
      'view' => false,
      'edit' => false,
    ),
  ),
);

$_CASHIER = array (
  'panel' => 
  array (
    'blocked' => true,
    'access' => false,
    'dashboard' => 
    array (
      'view' => false,
    ),
    'items' => 
    array (
      'view' => true,
      'edit' => false,
      'delete' => false,
    ),
    'contacts' => 
    array (
      'view' => true,
      'edit' => false,
      'delete' => false,
    ),
    'reports' => 
    array (
      'sales' => 
      array (
        'view' => false,
        'edit' => false,
        'delete' => false,
      ),
      'expenses' => 
      array (
        'view' => true,
        'edit' => true,
        'delete' => false,
      ),
    ),
    'settings' => 
    array (
      'view' => false,
      'edit' => false,
      'delete' => false,
    ),
  ),
  'register' => 
  array (
    'blocked' => false,
    'access' => true,
    'transactions' => 
    array (
      'create' => true,
      'view' => true,
      'edit' => true,
      'delete' => false,
    ),
    'sales' => 
    array (
      'discounts' => false,
      'price' => false,
    ),
    'settings' => 
    array (
      'view' => false,
      'edit' => false,
    ),
  ),
);

$_CASHIER_LIGHT = array (
  'panel' => 
  array (
    'blocked' => true,
    'access' => false,
    'dashboard' => 
    array (
      'view' => false,
    ),
    'items' => 
    array (
      'view' => true,
      'edit' => false,
      'delete' => false,
    ),
    'contacts' => 
    array (
      'view' => true,
      'edit' => false,
      'delete' => false,
    ),
    'reports' => 
    array (
      'sales' => 
      array (
        'view' => false,
        'edit' => false,
        'delete' => false,
      ),
      'expenses' => 
      array (
        'view' => true,
        'edit' => true,
        'delete' => false,
      ),
    ),
    'settings' => 
    array (
      'view' => false,
      'edit' => false,
      'delete' => false,
    ),
  ),
  'register' => 
  array (
    'blocked' => false,
    'access' => true,
    'transactions' => 
    array (
      'create' => true,
      'view' => true,
      'edit' => true,
      'delete' => false,
    ),
    'sales' => 
    array (
      'discounts' => false,
      'price' => false,
    ),
    'settings' => 
    array (
      'view' => false,
      'edit' => false,
    ),
  ),
);

$_ROLES_DATA = [$_BOSS,$_MANAGER,$_MANAGER_LIGHT,$_CASHIER,$_CASHIER_LIGHT];

/*
Tipos de Transacciones 
0 = Venta al contado 
1 = Compra al contado
2 = Guardada 
3 = Venta a crédito
4 = Compra a crédito
5 = Pago de ventas a crédito
6 = Devolución
7 = Venta anulada
8 = Venta recursiva
*/


$globals = ['COMPANY_ID','USER_ID','ENCOM_COMPANY_ID','USER_NAME','USER_EMAIL','USER_ID','USER_PHONE','COMPANY_NAME','COMPANY_DATE','PLAN','OUTLETS_COUNT','OUTLET_ID','REGISTER_ID'];

foreach ($globals as $value) {
	if(!defined($value)){
		define($value, 0);
	}
}