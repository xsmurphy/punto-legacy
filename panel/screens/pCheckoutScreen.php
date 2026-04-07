<?php

http_response_code(200);
header('Content-Type: application/json');

$channel = '';
$message = '';
$ese     = $_GET['s'] ?? null;

if (isset($_POST['channel']) && isset($_POST['d'])) {
    $channel = $_POST['channel'];
    $message = $_POST['d'];
} elseif ($ese) {
    $eses = json_decode(base64_decode($ese), true);
    if ($eses === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        die('Error: JSON inválido en la entrada. ' . json_last_error_msg());
    }
    $channel = $eses['channel'];
    $message = json_encode($eses['d']);
    if ($message === false && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        die('Error: JSON inválido al codificar. ' . json_last_error_msg());
    }
} else {
    die();
}

require_once dirname(__DIR__) . '/includes/ws_publish.php';

wsPublish($channel, 'checkoutScreen', ['message' => $message]);

echo '{a:1}';
?>
