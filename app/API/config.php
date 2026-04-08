<?php
/**
 * App config endpoint — serves environment variables as JS.
 * Loaded by index.html as: <script src="/API/config.php"></script>
 */
require_once(__DIR__ . '/../app_version.php');
require_once(__DIR__ . '/../includes/simple.config.php');

header('Content-Type: application/javascript');
header('Cache-Control: no-store');

$config = [
    'version' => APP_VERSION,
    'wsUrl'   => WS_URL,
    'appUrl'  => APP_URL,
    'appName' => APP_NAME,
];
?>
var APP_CONFIG = <?= json_encode($config, JSON_UNESCAPED_SLASHES) ?>;
var APP_VERSION = APP_CONFIG.version;
var WS_URL = APP_CONFIG.wsUrl;
