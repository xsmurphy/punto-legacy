<?php
define('HASH_TIMES',65646);

// Cargar variables de entorno
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

// Detección automática de entorno local
$_isLocal = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);

define('API_URL',    $_isLocal ? 'http://localhost:8002/API'      : ($_ENV['API_URL']    ?? ''));
define('PUBLIC_URL', $_isLocal ? 'http://localhost:8002/screens'  : ($_ENV['PUBLIC_URL'] ?? ''));
define('POS_URL',    $_isLocal ? 'http://localhost:8000'          : ($_ENV['POS_URL']    ?? ''));
define('APP_URL',    $_isLocal ? 'http://' . $_SERVER['HTTP_HOST']: ($_ENV['APP_URL']    ?? ''));
define('HTTPS',      $_isLocal ? 'http' : 'https');
define('WS_URL',     $_isLocal ? 'ws://localhost:6001'            : ($_ENV['WS_URL']     ?? ''));
define('ECOMMERCE_URL',     $_ENV['ECOMMERCE_URL']     ?? '');
define('MASTER_COMPANY_ID', $_ENV['MASTER_COMPANY_ID'] ?? '');

unset($_isLocal);

// Branding
define('APP_NAME',              $_ENV['APP_NAME']             ?? 'Punto');
define('MAILGUN_DOMAIN',        $_ENV['MAILGUN_DOMAIN']       ?? '');
define('EMAIL_FROM',            $_ENV['EMAIL_FROM']           ?? '');
define('EMAIL_NOTIFICATION',    $_ENV['EMAIL_NOTIFICATION']   ?? '');
define('EMAIL_NOTIFICATION_TO', $_ENV['EMAIL_NOTIFICATION_TO'] ?? '');

// Seguridad
define('SALT',                              $_ENV['HASHIDS_SALT']                      ?? '');

// Servicios externos
define('FACTURACION_ELECTRONICA_URL',   $_ENV['FACTURACION_ELECTRONICA_URL']   ?? '');
define('FACTURACION_ELECTRONICA_TOKEN', $_ENV['FACTURACION_ELECTRONICA_TOKEN'] ?? '');
define('API_LAYER_KEY',                 $_ENV['API_LAYER_KEY']                 ?? '');
define('PDF_API_KEY',                   $_ENV['PDF_API_KEY']                   ?? '');
define('DO_SPACES_ACCESS',              $_ENV['DO_SPACES_ACCESS']              ?? '');
define('DO_SPACES_SECRET',              $_ENV['DO_SPACES_SECRET']              ?? '');
define('NCM_SECRET',                    $_ENV['NCM_SECRET']                    ?? '');
define('IPAPI_KEY',                     $_ENV['IPAPI_KEY']                     ?? '');
define('BANCARD_CARD_API',              'https://multipay.epagos.com.py');
define('BANCARD_CARD_API_TOKEN',        $_ENV['BANCARD_CARD_API_TOKEN']        ?? '');
define('BANCARD_QR_API',                'https://integraciones.epagos.com.py/api/bancard/qr-payments');
define('BANCARD_QR_API_TOKEN',          $_ENV['BANCARD_QR_API_TOKEN']          ?? '');
define('PHONE_DB',                      'phone');
define('AUDITORIA_URL',                 $_ENV['AUDITORIA_URL']                 ?? '');
define('AUDITORIA_TOKEN',               $_ENV['AUDITORIA_TOKEN_PANEL']         ?? '');
define('MAILGUN_TOKEN',                 $_ENV['MAILGUN_TOKEN']                 ?? '');

// WhatsApp (Evolution API)
define('EVOLUTION_API_URL',             $_ENV['EVOLUTION_API_URL']             ?? '');
define('EVOLUTION_API_KEY',             $_ENV['EVOLUTION_API_KEY']             ?? '');
define('EVOLUTION_INSTANCE',            $_ENV['EVOLUTION_INSTANCE']            ?? '');

// Web Push / VAPID
define('VAPID_SUBJECT',                 $_ENV['VAPID_SUBJECT']                 ?? '');
define('VAPID_PUBLIC_KEY',              $_ENV['VAPID_PUBLIC_KEY']              ?? '');
define('VAPID_PRIVATE_KEY',             $_ENV['VAPID_PRIVATE_KEY']             ?? '');

// Analytics y tracking
define('GOOGLE_ANALYTICS_ID',           $_ENV['GOOGLE_ANALYTICS_ID']           ?? '');
define('FACEBOOK_PIXEL_ID',             $_ENV['FACEBOOK_PIXEL_ID']             ?? '');
define('HEADWAY_ACCOUNT_ID',            $_ENV['HEADWAY_ACCOUNT_ID']            ?? '');

$dias  = ["Domingo","Lunes","Martes","Miercoles","Jueves","Viernes","Sábado"];
$meses = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
