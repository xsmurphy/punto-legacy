<?php
define('SALT',2147483647);
define('HASH_TIMES',65646);
// Cargar secretos desde .env
(function() {
    $envFile = __DIR__ . '/../../../.env';
    if (!file_exists($envFile)) $envFile = __DIR__ . '/../../.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
})();
define('INTERCOM_IDENTITY_SECRET',         $_ENV['INTERCOM_IDENTITY_SECRET']         ?? '');
define('INTERCOM_IDENTITY_SECRET_IOS',     $_ENV['INTERCOM_IDENTITY_SECRET_IOS']     ?? '');
define('INTERCOM_IDENTITY_SECRET_ANDROID', $_ENV['INTERCOM_IDENTITY_SECRET_ANDROID'] ?? '');
define('TWILIO_PHONE', '+13518881790');
define('TWILIO_SID',                       $_ENV['TWILIO_SID']                       ?? '');
define('TWILIO_AUTH_TOKEN',                $_ENV['TWILIO_AUTH_TOKEN']                ?? '');
define('SENDGRID_API_KEY',                 $_ENV['SENDGRID_API_KEY']                 ?? '');
define('INFOBIP_PHONE', '29000');
define('FACTURACION_ELECTRONICA_URL', 'https://facturas.encom.com.py');
define('FACTURACION_ELECTRONICA_TOKEN',    $_ENV['FACTURACION_ELECTRONICA_TOKEN']    ?? '');
define('INFOBIP_AUTH',                     $_ENV['INFOBIP_AUTH']                     ?? '');
define('PDF_API_KEY',                      $_ENV['PDF_API_KEY']                      ?? '');
define('API_LAYER_KEY',                    $_ENV['API_LAYER_KEY']                    ?? '');
define('INCOME_COMPANY_ID', 15);
define('SYSIMGS_FOLDER', '../assets/sysimages');
define('SYSFILES_FOLDER', '../assets/sysfiles');
define('SYSFILES_URL', 'https://assets.encom.app/sysfiles');
define('API_ENCOM_URL',"https://api.encom.app");
define('BANCARD_QR_API', 'https://integraciones.epagos.com.py/api/bancard/qr-payments');
define('BANCARD_QR_API_TOKEN',             $_ENV['BANCARD_QR_API_TOKEN']             ?? '');
define('PUBLIC_URL', 'https://public.encom.app');
define('AUDITORIA_URL', 'http://auditoria.encom.com.py');
define('AUDITORIA_TOKEN',                  $_ENV['AUDITORIA_TOKEN_APP']              ?? '');
define('API_PIX_URL','https://pix.encom.com.py');
define('API_PIX_CLIENT_ID', 1);
define('API_PIX_SECRET',                   $_ENV['API_PIX_SECRET']                   ?? '');
define('MAILGUN_TOKEN',                    $_ENV['MAILGUN_TOKEN']                    ?? '');

$companyCategories  = [
  'Salud y Fitness' =>[
    'Gimnasio/Club de Bienestar'  => '0.1',
    'Entrenador Personal'         => '0.2',
    'Medicina Alternativa'        => '0.3',
    'Medicina'              => '0.4',
    'Profesional de la Salud'       => '0.5',
    'Hospital/Centro de Salud'      => '0.6',
    'Otro'                  => '0'
  ],
  'Alimentos y Bebidas'=>[
    'Panadería/Pastelería'  =>'1.1',
    'Bar/Club'    =>'1.2',
    'Cafetería'   =>'1.3',
    'Food Truck'  =>'1.4',
    'Comida Rápida' =>'1.6',
    'Restaurante'   =>'1.7',
    'Comida Saludable'  =>'1.8',
    'Vinos y Bebidas'   =>'1.9',
    'Jugos y Smoothies' =>'1.10',
    'Heladería'     =>'1.11',
    'Otro'      =>'1'
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

function theErrorHandler($type=false){
  $whoops = new \Whoops\Run;

  if(isset($_GET['debug'])){
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
	        include_once('../panel/includes/errorPage.inc.php');
	        return true;
	    });
	    $whoops->register();
	}
  }
}

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
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'schedule' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'tables' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'orders' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
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
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'schedule' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'tables' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'orders' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
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

    'quotes' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'schedule' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'tables' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'orders' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
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
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'schedule' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'tables' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'orders' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
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
      'edit' => true, //
      'delete' => false,//anular devolver
    ),
    'sales' => 
    array (
      'discounts' => false,
      'price' => false,
    ),


    'quotes' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'schedule' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'tables' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),
      'orders' => 
      array (
        'create'  => true,
        'view'    => true,
        'edit'    => true,
        'delete'  => true
      ),

    'settings' => 
    array (
      'view' => false,
      'edit' => false,
    ),
  ),
);

$_ROLES_DATA = [$_BOSS,$_MANAGER,$_MANAGER_LIGHT,$_CASHIER,$_CASHIER_LIGHT];

/*$globals = [];

foreach ($globals as $value) {
    if(!defined($value)){
        define($value, 0);
    }
}*/
?>