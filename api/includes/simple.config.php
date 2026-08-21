<?php
define('HASH_TIMES',65646);
// Cargar secretos desde .env
// Soporta: comentarios con #, valores con o sin quotes ('...' o "..."),
// = dentro del valor (explode con limit=2).
(function() {
    $envFile = __DIR__ . '/../../../.env';
    if (!file_exists($envFile)) $envFile = __DIR__ . '/../../.env';
    if (!file_exists($envFile)) return;

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) continue;

        $value = trim($m[2]);
        $len   = strlen($value);

        if ($len >= 2) {
            $first = $value[0];
            $last  = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // No pisar una variable YA seteada en $_ENV — mismo criterio que
        // cualquier librería dotenv estándar (phpdotenv, Symfony Dotenv):
        // el entorno real (Coolify/docker-compose env, o un override
        // explícito hecho por código ANTES de este require) tiene prioridad
        // sobre el archivo .env. Sin este guard, `verify_realtime.php`
        // (arnés de context/15) apuntaba REDIS_HOST/REDIS_PORT a un
        // listener TCP fake ANTES de requerir bootstrap.php, y este loader
        // los pisaba de vuelta con los valores reales de `.env` al
        // cargarse más abajo en la misma cadena de requires — el arnés
        // quedaba silenciosamente roto (0 eventos capturados, ver
        // hallazgo 2026-08-16) apenas `.env` empezó a declarar
        // REDIS_HOST/REDIS_PORT (son "Requeridas", context/06).
        if (!array_key_exists($m[1], $_ENV)) {
            $_ENV[$m[1]] = $value;
        }
    }
})();
// Detección automática de entorno local
// Entorno local = NO hay URLs configuradas por env Y el Host es localhost.
// Antes bastaba el Host: cualquier request interno a localhost (healthcheck,
// cron de mantenimiento en api/docker/cron/maintenance.sh) hacía que TODAS las
// constantes de URL de abajo se resolvieran a localhost AUNQUE el env de
// producción estuviera completo — un documento electrónico drenado por el
// cron armaba su link de portal con http://localhost. Por eso el HEALTHCHECK
// del Dockerfile tuvo que hardcodear un Host, contra la regla del proyecto.
// Con el env configurado, el Host ya no decide nada (2026-08-21).
$_isLocal = (($_ENV['API_URL'] ?? '') === '')
    && isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false;

define('API_URL',       $_isLocal ? 'http://localhost:8002/API'     : ($_ENV['API_URL']    ?? ''));
define('PUBLIC_URL',    $_isLocal ? 'http://localhost:8002/screens' : ($_ENV['PUBLIC_URL'] ?? ''));
define('POS_URL',       $_isLocal ? 'http://localhost:8000'         : ($_ENV['POS_URL']    ?? ''));
define('APP_URL',       $_isLocal ? 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000') : ($_ENV['APP_URL'] ?? ''));
define('WS_URL',        $_isLocal ? 'ws://localhost:6001'            : ($_ENV['WS_URL']     ?? ''));

unset($_isLocal);

// Branding
define('APP_NAME',              $_ENV['APP_NAME']             ?? 'Punto');
define('MAILGUN_DOMAIN',        $_ENV['MAILGUN_DOMAIN']       ?? '');
define('EMAIL_FROM',            $_ENV['EMAIL_FROM']           ?? '');
define('EMAIL_NOTIFICATION',    $_ENV['EMAIL_NOTIFICATION']   ?? '');
define('EMAIL_NOTIFICATION_TO', $_ENV['EMAIL_NOTIFICATION_TO'] ?? '');

define('SALT',                             $_ENV['HASHIDS_SALT']                     ?? '');
define('INTERCOM_IDENTITY_SECRET',         $_ENV['INTERCOM_IDENTITY_SECRET']         ?? '');
define('INTERCOM_IDENTITY_SECRET_IOS',     $_ENV['INTERCOM_IDENTITY_SECRET_IOS']     ?? '');
define('INTERCOM_IDENTITY_SECRET_ANDROID', $_ENV['INTERCOM_IDENTITY_SECRET_ANDROID'] ?? '');
define('TWILIO_PHONE',                     $_ENV['TWILIO_PHONE']                     ?? '');
define('TWILIO_SID',                       $_ENV['TWILIO_SID']                       ?? '');
define('TWILIO_AUTH_TOKEN',                $_ENV['TWILIO_AUTH_TOKEN']                ?? '');
define('SENDGRID_API_KEY',                 $_ENV['SENDGRID_API_KEY']                 ?? '');
define('INFOBIP_PHONE',                    $_ENV['INFOBIP_PHONE']                    ?? '29000');
define('INFOBIP_AUTH',                     $_ENV['INFOBIP_AUTH']                     ?? '');
// Evolution API (WhatsApp) — usada por api/v1/signup/start.php para enviar
// el OTP cuando SIGNUP_OTP=on. Sin definir acá, `defined('EVOLUTION_API_URL')`
// es siempre false aunque la env esté seteada (bug preexistente).
define('EVOLUTION_API_URL',                $_ENV['EVOLUTION_API_URL']                ?? '');
define('EVOLUTION_INSTANCE',               $_ENV['EVOLUTION_INSTANCE']               ?? '');
define('EVOLUTION_API_KEY',                $_ENV['EVOLUTION_API_KEY']                ?? '');
define('PDF_API_KEY',                      $_ENV['PDF_API_KEY']                      ?? '');
define('API_LAYER_KEY',                    $_ENV['API_LAYER_KEY']                    ?? '');
define('INCOME_COMPANY_ID', 15);
define('SYSIMGS_FOLDER', '../assets/sysimages');
define('SYSFILES_FOLDER', '../assets/sysfiles');
define('SYSFILES_URL', '/assets/sysfiles');
define('BANCARD_QR_API',         'https://integraciones.epagos.com.py/api/bancard/qr-payments');
define('BANCARD_QR_API_TOKEN',   $_ENV['BANCARD_QR_API_TOKEN']  ?? '');
define('AUDITORIA_URL',          $_ENV['AUDITORIA_URL']         ?? '');
define('AUDITORIA_TOKEN',        $_ENV['AUDITORIA_TOKEN_APP']   ?? '');

// S3-compatible storage (DO Spaces por defecto — overridable por env).
// S3_KEY_PREFIX: si está seteado, todos los objetos van bajo ese prefijo
// (ej: 'puntosys' → puntosys/items/<co>/<item>/<img>.jpg). Útil cuando
// varios proyectos comparten el mismo bucket.
define('S3_ENDPOINT',   $_ENV['S3_ENDPOINT']   ?? 'https://nyc3.digitaloceanspaces.com');
define('S3_REGION',     $_ENV['S3_REGION']     ?? 'us-east-1');
define('S3_BUCKET',     $_ENV['S3_BUCKET']     ?? 'ncmaspace');
define('S3_KEY',        $_ENV['S3_KEY']        ?? ($_ENV['DO_SPACES_ACCESS'] ?? ''));
define('S3_SECRET',     $_ENV['S3_SECRET']     ?? ($_ENV['DO_SPACES_SECRET'] ?? ''));
define('S3_KEY_PREFIX', $_ENV['S3_KEY_PREFIX'] ?? '');

// dLocal Go — pasarela de pagos para compra de packs de créditos.
// El webhook se verifica con HMAC-SHA256 usando DLOCAL_GO_WEBHOOK_SECRET.
// ENVIRONMENT: 'sandbox' (default) o 'production'. BASE_URL override opcional.
define('DLOCAL_GO_API_KEY',        $_ENV['DLOCAL_GO_API_KEY']        ?? '');
define('DLOCAL_GO_SECRET_KEY',     $_ENV['DLOCAL_GO_SECRET_KEY']     ?? '');
define('DLOCAL_GO_WEBHOOK_SECRET', $_ENV['DLOCAL_GO_WEBHOOK_SECRET'] ?? '');
define('DLOCAL_GO_ENVIRONMENT',    $_ENV['DLOCAL_GO_ENVIRONMENT']    ?? 'sandbox');
define('DLOCAL_GO_BASE_URL',       $_ENV['DLOCAL_GO_BASE_URL']       ?? '');
// URLs de retorno/notificación del checkout (overridables por env).
define('DLOCAL_GO_SUCCESS_URL',      $_ENV['DLOCAL_GO_SUCCESS_URL']      ?? '');
define('DLOCAL_GO_BACK_URL',         $_ENV['DLOCAL_GO_BACK_URL']         ?? '');
define('DLOCAL_GO_NOTIFICATION_URL', $_ENV['DLOCAL_GO_NOTIFICATION_URL'] ?? '');

// Facturación electrónica — Factomate/SIFEN (PY), context/28-facturacion-electronica-plan.md.
// Pivot 2026-07-28: Automate NO era el proveedor, era otro cliente de
// Factomate — se corrigió antes de implementar emisión real.
// APP_ENCRYPTION_KEY: base64 de 32 bytes, usada por CredentialVault (AES-256-GCM)
// para cifrar usuario/contraseña/teléfono/token del proveedor de FE. Sin ella
// el vault no arranca (RuntimeException explícito) — nunca hay fallback
// silencioso a texto plano ni a una clave derivada débil.
define('APP_ENCRYPTION_KEY', $_ENV['APP_ENCRYPTION_KEY'] ?? '');
// FACTOMATE_BASE_URL_TEST / FACTOMATE_BASE_URL_PROD: prod y test son HOSTS
// DISTINTOS en Factomate (a diferencia de Automate). El host efectivo sale
// de einvoice_account.environment por company — nunca de una sola constante
// global. Si environment='prod' y la constante de prod está vacía,
// FactomateProvider tira error explícito en vez de caer a test (o viceversa):
// mandar facturas de prueba a producción, o al revés, es el tipo de bug que
// no se detecta hasta que ya es tarde.
define('FACTOMATE_BASE_URL_TEST', $_ENV['FACTOMATE_BASE_URL_TEST'] ?? 'https://facturadordev.automate.com.py');
define('FACTOMATE_BASE_URL_PROD', $_ENV['FACTOMATE_BASE_URL_PROD'] ?? '');
// FACTOMATE_ADMIN_* — credencial ADMIN de Punto en Factomate (F7 white-label):
// usuario global SIN tenant, el secreto más poderoso del módulo (crea
// emisores y emite bearers de cualquier tenant vía PhoneLogin). SIEMPRE en
// env, NUNCA en BD ni alcanzable desde un endpoint con auth de tenant. Una
// por entorno — test y prod son hosts distintos con cuentas distintas.
define('FACTOMATE_ADMIN_USERNAME_TEST', $_ENV['FACTOMATE_ADMIN_USERNAME_TEST'] ?? '');
define('FACTOMATE_ADMIN_PASSWORD_TEST', $_ENV['FACTOMATE_ADMIN_PASSWORD_TEST'] ?? '');
define('FACTOMATE_ADMIN_USERNAME_PROD', $_ENV['FACTOMATE_ADMIN_USERNAME_PROD'] ?? '');
define('FACTOMATE_ADMIN_PASSWORD_PROD', $_ENV['FACTOMATE_ADMIN_PASSWORD_PROD'] ?? '');
// Entorno donde se provisionan los emisores NUEVOS (F7). Global, no elección
// del tenant. 'test' hasta que el white-label esté validado contra prod.
define('EINVOICE_DEFAULT_ENVIRONMENT', $_ENV['EINVOICE_DEFAULT_ENVIRONMENT'] ?? 'test');
// EINVOICE_DRAIN_SECRET: secreto compartido de jobs internos del sistema —
// gatea POST /v1/einvoice?action=drain (F1, drainer del outbox de FE) y
// POST /v1/maintenance?job=... (rollup-reconcile / purge-tenant-audit /
// purge-deleted-row / einvoice-drain, ver context/06-infraestructura.md §
// Jobs de mantenimiento). El nombre quedó del drainer de FE (primer caso de
// uso), pero cubre cualquier job que el cron de la imagen invoque SIN pasar
// por apiAuthTenant (no hay realm de panel/device en un cron). No se separó
// en una env var nueva a propósito: una sola var menos que cargar en Coolify.
// Vacía/no definida → esos endpoints responden 503 SIEMPRE, nunca abiertos.
define('EINVOICE_DRAIN_SECRET', $_ENV['EINVOICE_DRAIN_SECRET'] ?? '');
// TAXPAYER_LOOKUP_URL: padrón público de contribuyentes para el lookup de RUC
// (GET {url}/{documento sin DV}). Fallback de Contacts\TaxpayerLookupService
// cuando el comercio no tiene facturación electrónica conectada. Vacía →
// el lookup solo responde con la fuente del proveedor de FE.
define('TAXPAYER_LOOKUP_URL', $_ENV['TAXPAYER_LOOKUP_URL'] ?? 'https://turuc.com.py/api/contribuyente');

define('API_PIX_URL',            $_ENV['API_PIX_URL']           ?? '');
define('API_PIX_CLIENT_ID', 1);
define('API_PIX_SECRET',         $_ENV['API_PIX_SECRET']        ?? '');
define('MAILGUN_TOKEN',          $_ENV['MAILGUN_TOKEN']         ?? '');
define('SENDGRID_SMTP_USER',     $_ENV['SENDGRID_SMTP_USER']    ?? '');
define('SENDGRID_SMTP_PASS',     $_ENV['SENDGRID_SMTP_PASS']    ?? '');
define('NCM_SMS_API_KEY',        $_ENV['NCM_SMS_API_KEY']       ?? '');
define('NCM_SMS_COMPANY_ID',     $_ENV['NCM_SMS_COMPANY_ID']    ?? '');

// OpenRouter — gateway multi-modelo del agente IA (context/17) y del test de
// conectividad admin (context/34 F6 §2, AiAdminService::testModel()).
define('OPENROUTER_API_KEY',     $_ENV['OPENROUTER_API_KEY']    ?? '');

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

  if(isset($_GET['debug']) && ($_ENV['APP_DEBUG'] ?? 'false') === 'true'){
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