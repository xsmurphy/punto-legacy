<?php
define('HASH_TIMES',65646);
define('API_URL','https://api.encom.app');
// Detección automática de entorno local
$_isLocal = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);
define('APP_URL', $_isLocal ? 'http://' . $_SERVER['HTTP_HOST'] : 'https://panel.encom.app');
define('HTTPS', $_isLocal ? 'http' : 'https'); //EN PRODUCCION DEBE SER https
unset($_isLocal);
define('ECOMMERCE_URL','.encom.site'); //EN PRODUCCION DEBE SER .encom.site
define('PUBLIC_URL','https://public.encom.app');
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
define('SALT', $_ENV['HASHIDS_SALT'] ?? '5b3e72e014a7892e9c0579adc4ac1ca99c27fa6deab3ed1404af9289c12070a0');
define('INTERCOM_IDENTITY_SECRET',          $_ENV['INTERCOM_IDENTITY_SECRET']          ?? '');
define('INTERCOM_IDENTITY_SECRET_IOS',      $_ENV['INTERCOM_IDENTITY_SECRET_IOS']      ?? '');
define('INTERCOM_IDENTITY_SECRET_ANDROID',  $_ENV['INTERCOM_IDENTITY_SECRET_ANDROID']  ?? '');
define('FACTURACION_ELECTRONICA_URL', 'https://facturas.encom.com.py');
define('FACTURACION_ELECTRONICA_TOKEN',     $_ENV['FACTURACION_ELECTRONICA_TOKEN']      ?? '');
define('TWILIO_PHONE', '+13518881790');
define('TWILIO_SID',                        $_ENV['TWILIO_SID']                        ?? '');
define('TWILIO_AUTH_TOKEN',                 $_ENV['TWILIO_AUTH_TOKEN']                 ?? '');
define('SENDGRID_API_KEY',                  $_ENV['SENDGRID_API_KEY']                  ?? '');
define('INFOBIP_PHONE', '29000');
define('INFOBIP_AUTH',                      $_ENV['INFOBIP_AUTH']                      ?? '');
define('API_LAYER_KEY',                     $_ENV['API_LAYER_KEY']                     ?? '');
define('PDF_API_KEY',                       $_ENV['PDF_API_KEY']                       ?? ''); // html2pdfrocket
define('DO_SPACES_ACCESS',                  $_ENV['DO_SPACES_ACCESS']                  ?? '');
define('DO_SPACES_SECRET',                  $_ENV['DO_SPACES_SECRET']                  ?? '');
define('NCM_SECRET',                        $_ENV['NCM_SECRET']                        ?? '');
define('IPAPI_KEY',                         $_ENV['IPAPI_KEY']                         ?? '');
define('BANCARD_CARD_API', 'https://multipay.epagos.com.py');
define('BANCARD_CARD_API_TOKEN',            $_ENV['BANCARD_CARD_API_TOKEN']            ?? '');
define('BANCARD_QR_API', 'https://integraciones.epagos.com.py/api/bancard/qr-payments');
define('BANCARD_QR_API_TOKEN',              $_ENV['BANCARD_QR_API_TOKEN']              ?? '');
define('PHONE_DB', 'phone');
define('AUDITORIA_URL', 'https://auditoria.encom.com.py');
define('AUDITORIA_TOKEN',                   $_ENV['AUDITORIA_TOKEN_PANEL']             ?? '');
define('EMAIL_NOTIFICATION', 'info@encom.com.py');
define('EMAIL_NOTIFICATION_TO', 'l.insfran@encom.com.py');
define('MAILGUN_TOKEN',                     $_ENV['MAILGUN_TOKEN']                     ?? '');


$dias 	= ["Domingo","Lunes","Martes","Miercoles","Jueves","Viernes","Sábado"];
$meses 	= ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];

?>
