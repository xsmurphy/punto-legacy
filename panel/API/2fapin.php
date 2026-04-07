<?php
// 2FA AUTH — generación y verificación de PIN por SMS/WhatsApp

$_panelRoot = dirname(__DIR__, 2); // panel/
include_once($_panelRoot . '/includes/db.php');
include_once($_panelRoot . '/includes/simple.config.php');

$debugMode = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
$debugCode = '0000';

$timeout = 240;
$get     = $db->Prepare($_GET);
$QRMODE  = ($get['qr'] == 1);
$randNo  = $QRMODE ? mt_rand(100000000000, 999999999999) : mt_rand(1000, 9999);

define('CODE', $debugMode ? $debugCode : $randNo);
define('TIME', time());

function jsonDieResult($array, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    die(json_encode($array));
}

function enc($str): string { return (string)$str; }
function dec($str): string { return (string)$str; }

function createSession()
{
    global $db;
    $db->Execute('DELETE FROM codes WHERE phone = ? LIMIT 1', [PHONE]);
    return $db->AutoExecute('codes', ['phone' => PHONE, 'code' => CODE, 'time' => TIME], 'INSERT');
}

if (!$get['phone'] && !$get['qr']) {
    jsonDieResult(['error' => 'Not found'], 404);
}

define('PHONE', $get['phone']);

if ($debugMode) {
    // En debug mode: no tocar la DB, solo devolver el código fijo
    if ($get['new']) {
        jsonDieResult(['code' => CODE]);
    } else {
        jsonDieResult(['code' => CODE]);
    }
    // checkCompany y scan no aplican en debug, siguen el flujo normal abajo
}

// Limpiar códigos expirados
$db->selectDb('phone');
$db->Execute('DELETE FROM codes WHERE time < ?', [(TIME - $timeout)]);

if ($get['new']) {

    if (PHONE) {
        createSession();
        jsonDieResult(['code' => CODE]);
    } else {
        jsonDieResult(['error' => 'No phone'], 404);
    }

} elseif ($get['checkCompany']) {

    $code   = $db->Prepare($get['code']);
    $result = $db->Execute('SELECT company, outlet FROM codes WHERE code = ? AND company IS NOT NULL LIMIT 1', [$code]);

    $company = ($result && $result->RecordCount() > 0) ? $result->fields['company'] : false;
    $outlet  = ($result && $result->RecordCount() > 0) ? $result->fields['outlet']  : false;

    jsonDieResult(['company' => $company, 'outlet' => $outlet]);

} elseif ($get['scan']) {

    $code    = $db->Prepare($get['code']);
    $company = $db->Prepare($get['company']);
    $outlet  = $db->Prepare($get['outlet']);

    if (!$company || !$outlet) {
        jsonDieResult(['success' => false]);
    }

    $update = $db->AutoExecute('codes', ['company' => $company, 'outlet' => $outlet], 'UPDATE', 'code = ' . $code . ' LIMIT 1');
    jsonDieResult(['success' => $update, 'data' => 'code:' . $code . ' comp:' . $company . ' ou:' . $outlet]);

} else {

    $result = $db->Execute('SELECT code FROM codes WHERE phone = ? LIMIT 1', [PHONE]);

    if ($result && $result->RecordCount() > 0) {
        $code = (int)$result->fields['code'];
    } else {
        createSession();
        $code = CODE;
    }

    jsonDieResult(['code' => $code]);
}
?>
