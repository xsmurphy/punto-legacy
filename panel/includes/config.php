<?php
//error_reporting(-1);
//ini_set('display_errors', 'Off');

$origin 			= isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$allowed_domains 	= [
    'https://encom.app',
    'https://panel.encom.app',
    'https://app.encom.app',
    'https://assets.encom.app',
    'https://api.encom.app',
    'https://ecom.encom.app',
    'https://encom.site',
    'https://encom.com.py',
    'https://panel.encom.com.py',
    'https://app.encom.com.py',
    'https://assets.encom.com.py',
    'https://api.encom.com.py',
    'http://localhost:8000',
    'http://localhost:8001',
    'http://localhost:8002'
];

if (in_array($origin, $allowed_domains)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST');
		header('Access-Control-Allow-Headers: Content-Type');
}else{
	
}

date_default_timezone_set('America/Asuncion');//si no esta definido el timezone
header('Content-Type: text/html; charset=utf-8');
define('LANGUAGE', 'es'); // pongo fuera del check de session porque si no hay session da un error de que no encuentra el archivo

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
	        include_once(__DIR__ . '/errorPage.inc.php');
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

function enc($str){
	$hashids = new Hashids\Hashids(SALT);
	return $hashids->encode($str);
}

//decode ID
function dec($str){
	if(is_array($str)){ $str = '';};
	$str 			= $str . '';
	$hashids 	= new Hashids\Hashids(SALT);
	$decoded 	= $hashids->decode($str);
	//$decoded 	= $decoded[0];

	if(array_key_exists(0, $decoded)){
		$decoded = $decoded[0];
	}else{
		$decoded = 0;
	}

	return (int)$decoded;
}

function ncmEncode($str){
	$based 		= base64_encode($str);
	$encoded 	= openssl_encrypt($based,"AES-128-ECB",SALT);
	$encoded 	= base64_encode($encoded);//vuelvo a base64 para poder usar en urls
	return $encoded;
}

function ncmDecode($str){
	$str 		= base64_decode($str);
	$based 		= openssl_decrypt($str,"AES-128-ECB",SALT);
	$decoded 	= base64_decode($based);
	return $decoded;
}

function getCompanyLoginSession($companyId,$encomADM = false){
	global $db;
	$contact 			= $db->Execute('SELECT * FROM contact WHERE role = 1 AND type = 0 AND companyId = ? LIMIT 1', [$companyId]);
	$outlet 			= $db->Execute("SELECT outletId FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletId ASC LIMIT 1",[$companyId]);
	$company 			= $db->Execute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[$companyId]);
	$outletCount 	= $db->Execute("SELECT COUNT(outletId) as count FROM outlet WHERE outletStatus = 1 AND companyId = ?",[$companyId]);
	$setting 			= $db->Execute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[$companyId]);
	$modules 			= $db->Execute("SELECT * FROM module WHERE companyId = ? LIMIT 1",[$companyId]);

	if($contact && $contact->RecordCount() > 0){
		unset($contact->fields['salt'],$contact->fields['contactPassword'],$contact->fields['role']);

		$_SESSION['last_activity']      	= time();
		$_SESSION['user']['companyId']  	= enc($contact->fields['companyId']);
		$_SESSION['user']['companyDB']  	= $contact->fields['companyDB'];

		$_SESSION['user']['companySettings']= $setting->fields;	
		$_SESSION['user']['companyModules'] = $modules->fields;

		$_SESSION['user']['companyStatus']  = $company->fields['companyStatus'];
		$_SESSION['user']['companyParent']  = 0;
		//$_SESSION['user']['userName']     = $contact->fields['contactName'];
		//$_SESSION['user']['userEmail']    = $contact->fields['contactEmail'];
		//$_SESSION['user']['role']         = enc($contact->fields['role']);
		$_SESSION['user']['outletId']     = enc($outlet->fields['outletId']);
		$_SESSION['user']['registerId']   = 0;
		$_SESSION['user']['plan']         = enc($company->fields['companyPlan']);
		$_SESSION['user']['planExpires']  = $company->fields['companyExpiringDate'];
		$_SESSION['user']['outletsCount'] = $outletCount->fields['count'];
		$_SESSION['user']['startDate']    = false;
		$_SESSION['user']['endDate']      = false;
		$_SESSION['user']['ENCOM_ADM']    = $encomADM;

	}

	return $_SESSION['user'];
}

$dias 	= ["Domingo","Lunes","Martes","Miercoles","Jueves","Viernes","Sábado"];
$meses 	= ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];

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

	if(isset($sU['companySettings'])){
		$_cmpSettings 	= $sU['companySettings'];
	}else{
		$setting 		= $db->Execute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
		$_cmpSettings 	= $setting->fields;
		//$setting->Close();
	}

	$_fullSettings  = json_decode($_cmpSettings['settingObj'],true);

	if(isset($sU['companyModules'])){
		$_modules 		= $sU['companyModules'];
	}else{
		$modules 		= $db->Execute("SELECT * FROM module WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
		$_modules 		= $modules->fields;
		//$modules->Close();
	}	
									
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
	define('EXPIRED', $_cmpSettings['settingPlanExpired']);
	define('LOYALTY', $_modules['loyalty']);
	define('STORE_CREDIT', $_cmpSettings['settingStoreCredit']);
	define('LINE_CREDIT', $_cmpSettings['settingForceCreditLine']);
	define('COMPANY_IS_TRIAL', $_cmpSettings['settingIsTrial']);
	define('EXTRA_USERS', $_modules['extraUsers']);
	define('SCHEDULE', $_modules['calendar']);
	define('ENCOM_UID', $_cmpSettings['settingEncomID']);

	$companyData = $db->Execute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
	define('BALANCE', $companyData->fields['companyBalance']);
	define('SMS_CREDIT', $companyData->fields['companySMSCredit']);
	define('COMPANY_DATE', $companyData->fields['companyDate']);
	define('LAST_TIME_EDIT', $companyData->fields['companyLastUpdate']);
	define('COMPANY_DISCOUNT', $companyData->fields['companyDiscount']);
	define('API_KEY', sha1($companyData->fields['accountId']));

	//$companyData->Close();

	$SQLcompanyIdANDoutletId 	= 'companyId = '.COMPANY_ID.' AND outletId = '.OUTLET_ID;
	$SQLcompanyId 						= 'companyId = '.COMPANY_ID;
	$SQLregisterId 						= 'registerId = '.REGISTER_ID;
	$SQLcompanyIdJoin 				= 'a.companyId = '.COMPANY_ID;

	date_default_timezone_set(TIMEZONE);	

	//GET ALL OUTLETS ARRAY
	$allOutletsArray 	= [];
	$result 			= $db->Execute("SELECT outletName, outletId FROM outlet WHERE outletStatus = 1 AND companyId = ?",[COMPANY_ID]);

	while (!$result->EOF) {
		$fields = $result->fields;
	  $allOutletsArray[$fields['outletId']] = [
																							"name"	=> $fields['outletName'],
																							"id" 		=> $fields['outletId']
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

define('INTERCOM_IDENTITY_SECRET', $_ENV['INTERCOM_IDENTITY_SECRET'] ?? '');
define('POS_URL','https://app.encom.app');
//ids de los productos de income
define('PLAN_COMPANY_ID', 27558);
define('PLAN_FULL_ID', 32491);
define('PLAN_STARTER_ID', 27559);
define('PLAN_MICRO_ID', 27560);
define('PLAN_FULL_ID', 32491);

define('ASSETS_URL', 'https://assets.encom.app');
define('SYSIMGS_FOLDER', '../assets/sysimages');

define('TODAY', date('Y-m-d H:i:s'));

//$plansValues[PLAN]['max_brands']

//plans data
$plansNames 				= array('Free'=>'0','Company'=>'1','Full S'=>'2','Trial'=>'3','Privilege'=>'4');
$dayHours					= array('00');
$languages 					= ["Español" => "es", "English" => "en", "Portugues" => "pt"];
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

$companyCategories	= [
	'Salud y Fitness' =>[
		'Gimnasio/Club de Bienestar' 	=> '0.1',
		'Entrenador Personal' 		    => '0.2',
		'Medicina Alternativa' 		    => '0.3',
		'Medicina' 					    => '0.4',
		'Profesional de la Salud' 	  	=> '0.5',
		'Hospital/Centro de Salud'    	=> '0.6',
		'Otro' 					        => '0'
	],
	'Alimentos y Bebidas'=>[
		'Panadería/Pastelería'	=>'1.1',
		'Bar/Club'		=>'1.2',
		'Cafetería'		=>'1.3',
		'Food Truck'	=>'1.4',
		'Comida Rápida'	=>'1.6',
		'Restaurante' 	=>'1.7',
		'Comida Saludable'	=>'1.8',
		'Vinos y Bebidas' 	=>'1.9',
		'Jugos y Smoothies' =>'1.10',
		'Heladería'  		=>'1.11',
		'Otro' 			=>'1'
	],
	'Retail'=>[
		'Arte/Fotografía/Filmaciones'=>'2.1',
		'Libros/Música/Videos'=>'2.2',
		'Ropa/Accesorios'=>'2.3',
		'Electrónicos/Tecnología/Informática'=>'2.4',
		'Regalos'=>'2.5',
		'Kiosco/Mercado'=>'2.6',
		'Ferretería'=>'2.7',
		'Joyas/Relojes'=>'2.8',
		'Tienda de Mascotas'=>'2.9',
		'Tienda deportiva'=>'2.10',
		'Hogar/Decoración'=>'2.11',
		'Niños/Bebés'=>'2.12',
		'Otro'=>'2'
	],
	'Reparación'=>[
		'Servicios para automóviles'=>'3.1',
		'Ropas/Reparación de calzados/Lavandería'=>'3.3',
		'Computadoras/Electrónica'=>'3.4',
		'Hogar Servicios'=>'3.5',
		'Otro'=>'3'
	],
	'Transporte'=>[
		'Delivery'=>'4.1',
		'Limousine'=>'4.2',
		'Taxi'=>'4.3',
		'Bus'=>'4.4',
		'Movilización'=>'4.5',
		'Other'=>'4'
	],
	'Belleza'=>[
		'Salón de Belleza'=>'5.1',
		'Peluquería/Barbería'=>'5.2',
		'Masajes'=>'5.3',
		'Spa de Uñas'=>'5.4',
		'Spa'=>'5.5',
		'Salon de bronceado'=>'5.6',
		'Tatuajes/Piercing'=>'5.7',
		'Otro'=>'5'
	],
	'Servicios Profesionales'=>[
		'Contabilidad'=>'6.1',
		'Consultoría'=>'6.2',
		'Diseño'=>'6.3',
		'Marketing'=>'6.4',
		'Real State'=>'6.5',
		'Otro'=>'6'
	],
	'Educación'=>[
		'Instituto'=>'7.1',
		'Universidad'=>'7.2',
		'Cursos y Capacitaciones'=>'7.3',
		'Enseñanza On-line'=>'7.4',
		'Idiomas'=>'7.5',
		'Otro'=>'7'
	],
	'Software'=>[
		'App'=>'8.1',
		'SaaS'=>'8.2',
		'Online Service'=>'8.3',
		'Ecommerce'=>'8.4',
		'Otro'=>'8'
	]
];

$installConfig = [
	[
		'match' => ['0.1','0.2','0','7','7.1','7.2','7.3','7.4','7.5'],
		'items' => [
						[
							'name' => 'Cuota mensual',
							'price' => '12000'
						],
						[
							'name' => 'Clase personalizada',
							'price' => '32000'
						],
						[
							'name' => 'Clase grupal',
							'price' => '22000'
						]
					],
		'modules' => [
						'schedule','dunning'
					]
	],
	[
		'match' => ['0.3','0.4','0.5','0.6'],
		'items' => [
						[
							'name' => 'Consulta General',
							'price' => '12000'
						],
						[
							'name' => 'Consulta personalizada',
							'price' => '32000'
						],
						[
							'name' => 'Tratamiento',
							'price' => '22000'
						]
					],
		'modules' => [
						'schedule','dunning'
					]
	],
	[
		'match' => ['1.1','1.3','0.5','0.6'],
		'items' => [
						[
							'name' => 'Pan Francés',
							'price' => '12000'
						],
						[
							'name' => 'Café Espresso',
							'price' => '32000'
						],
						[
							'name' => 'Sandwich Gourmet',
							'price' => '22000'
						]
					],
		'modules' => [
						'tables', 'ordersPanel', 'proiduction', 'kds','feedback'
					]
	],
	[
		'match' => ['1.2','1.7','1.9','1.8','1.4','1.6','1'],
		'items' => [
						[
							'name' => 'Plato Principal',
							'price' => '12000'
						],
						[
							'name' => 'Menú a la carta',
							'price' => '32000'
						],
						[
							'name' => 'Tragos',
							'price' => '10000'
						],
						[
							'name' => 'Vino en copa',
							'price' => '2000'
						],
						[
							'name' => 'Botella de Vino',
							'price' => '50000'
						]
					],
		'modules' => [
						'tables', 'ordersPanel', 'proiduction', 'kds','feedback'
					]
	],
	[
		'match' => ['1.10','1.11'],
		'items' => [
						[
							'name' => 'Batido de frutas',
							'price' => '23000'
						],
						[
							'name' => 'Helado de Almendras',
							'price' => '32000'
						],
						[
							'name' => 'Jugo de Naranja',
							'price' => '5000'
						]
					],
		'modules' => [
						'tables', 'ordersPanel', 'proiduction', 'kds','feedback'
					]
	],
	[
		'match' => ['2.1','2.2','2.5','2.8','2'],
		'items' => [
						[
							'name' => 'Cuadro Vintage',
							'price' => '23000'
						],
						[
							'name' => 'Libros de Autosuperación',
							'price' => '32000'
						],
						[
							'name' => 'Disco de Jazz Clásico',
							'price' => '5000'
						],
						[
							'name' => 'Mueble de Roble',
							'price' => '60000'
						],
						[
							'name' => 'Reloj de pulcera',
							'price' => '43000'
						],
						[
							'name' => 'Anillo de oro',
							'price' => '171000'
						]
					],
		'modules' => [
						'ecom','feedback'
					]
	],
	[
		'match' => ['2.4'],
		'items' => [
						[
							'name' => 'Tablet 10"',
							'price' => '23000'
						],
						[
							'name' => 'Smart Watch X3',
							'price' => '32000'
						],
						[
							'name' => 'Laptop A12',
							'price' => '225000'
						]
					],
		'modules' => [
							'ecom','feedback','dunning'
					]
	],
	[
		'match' => ['2.7'],
		'items' => [
						[
							'name' => 'Llave Francesa',
							'price' => '23000'
						],
						[
							'name' => 'Taladro Eléctrico',
							'price' => '32000'
						],
						[
							'name' => 'Removedor de Pintura',
							'price' => '2000'
						]
					],
		'modules' => [
						'ecom','feedback'
					]
	],
	[
		'match' => ['2.6'],
		'items' => [
						[
							'name' => 'Limpiador de Vidrios',
							'price' => '23000'
						],
						[
							'name' => 'Tomates Secos',
							'price' => '32000'
						],
						[
							'name' => 'Aceite de Oliva',
							'price' => '25000'
						]
					],
		'modules' => [
						'ecom','feedback'
					]
	],
	[
		'match' => ['2.2','2.9','2.10','2.12'],
		'items' => [
						[
							'name' => 'Prenda M',
							'price' => '23000'
						],
						[
							'name' => 'Pantalones S',
							'price' => '32000'
						],
						[
							'name' => 'Calzados talle 8',
							'price' => '25000'
						],
						[
							'name' => 'Collar',
							'price' => '15000'
						],
						[
							'name' => 'Abrigo',
							'price' => '80000'
						]
					],
		'modules' => [
						'ecom','feedback'
					]
	],
	[
		'match' => ['3','3.1','3.2','3.3','3.4','3.5','6','6.1','6.2','6.3','6.4','6.5'],
		'items' => [
						[
							'name' => 'Servicio por hora',
							'price' => '23000'
						],
						[
							'name' => 'Servicio a Domicilio',
							'price' => '32000'
						],
						[
							'name' => 'Servicio Express',
							'price' => '25000'
						]
					],
		'modules' => [
						'schedule','dunning','feedback'
					]
	],
	[
		'match' => ['4','4.1','4.2','4.3','4.4','4.5'],
		'items' => [
						[
							'name' => 'Tarifa Regular',
							'price' => '23000'
						],
						[
							'name' => 'Tarifa Nocturna',
							'price' => '32000'
						],
						[
							'name' => 'Tarifa Express',
							'price' => '25000'
						]
					],
		'modules' => [
						'schedule','feedback'
					]
	],
	[
		'match' => ['5','5.1','5.3','5.4','5.5','5.6'],
		'items' => [
						[
							'name' => 'Masaje',
							'price' => '23000'
						],
						[
							'name' => 'Tratamiento',
							'price' => '32000'
						],
						[
							'name' => 'Bronzeado completo',
							'price' => '19000'
						],
						[
							'name' => 'Uñas postizas',
							'price' => '25000'
						],
						[
							'name' => 'Limpieza profunda',
							'price' => '67000'
						]
					],
		'modules' => [
							'schedule','feedback'
					]
	],
	[
		'match' => ['5.2'],
		'items' => [
						[
							'name' => 'Corte Simple',
							'price' => '23000'
						],
						[
							'name' => 'Corte de Barba',
							'price' => '32000'
						],
						[
							'name' => 'Lavado',
							'price' => '25000'
						]
					],
		'modules' => [
						'schedule','table','feedback'
					]
	],
	[
		'match' => ['5.7'],
		'items' => [
						[
							'name' => 'Sesión de Tatuajes',
							'price' => '23000'
						],
						[
							'name' => 'Tatuaje Personalizado',
							'price' => '32000'
						],
						[
							'name' => 'Retoques',
							'price' => '25000'
						]
					],
		'modules' => [
						'schedule','feedback'
					]
	],
	[
		'match' => ['8','8.1','8.2','8.3','8.4'],
		'items' => [
						[
							'name' => 'Plan Inicial',
							'price' => '15000'
						],
						[
							'name' => 'Plan Standar',
							'price' => '25000'
						],
						[
							'name' => 'Plan Pro',
							'price' => '55000'
						]
					],
		'modules' => [
						'schedule','dunning','recurring'
					]
	],
];



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

		'quotes' => 
		array (
			'create' 	=> true,
			'view' 		=> true,
		),
		'schedule' => 
		array (
			'create' 	=> true,
			'view' 		=> true,
			'edit' 		=> true,
			'delete' 	=> true
		),
		'tables' => 
		array (
			'create' 	=> true,
			'view' 		=> true,
			'edit' 		=> true,
			'delete' 	=> true
		),
		'orders' => 
		array (
			'create' 	=> true,
			'view' 		=> true,
			'edit' 		=> true,
			'delete' 	=> true
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

    'quotes' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
	),
	'schedule' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
	),
	'tables' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
	),
	'orders' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
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
      'edit' => true,
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

    'quotes' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
	),
	'schedule' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
	),
	'tables' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
	),
	'orders' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
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

    'quotes' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
	),
	'schedule' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
	),
	'tables' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
	),
	'orders' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
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

    'quotes' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
	),
	'schedule' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
	),
	'tables' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
	),
	'orders' => 
	array (
		'create' 	=> true,
		'view' 		=> true,
		'edit' 		=> true,
		'delete' 	=> true
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


$globals = ['COMPANY_ID','USER_ID','ENCOM_COMPANY_ID','USER_NAME','USER_EMAIL','USER_ID','USER_PHONE','INTERCOM_IDENTITY_SECRET','COMPANY_NAME','COMPANY_DATE','PLAN','OUTLETS_COUNT','OUTLET_ID','REGISTER_ID'];

foreach ($globals as $value) {
	if(!defined($value)){
		define($value, 0);
	}
}