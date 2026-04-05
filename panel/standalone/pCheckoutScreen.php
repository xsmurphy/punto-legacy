<?php

http_response_code(200);
header('Content-Type: application/json');

$ese         = $_GET['s'];
$channel    = '';
$message    = '';

if (isset($_POST['channel']) && isset($_POST['d'])) {
    // Si envía los datos por post
    $channel = $_POST['channel'];
    $message = $_POST['d'];
} else if (isset($ese)) {
    // Si envía los datos codificados en base64
    $eses         = json_decode(base64_decode($ese), true);
    if ($eses === null && json_last_error() !== JSON_ERROR_NONE) {
        // Error al decodificar JSON
        $jsonError = json_last_error_msg();
        http_response_code(400);
        die('Error: JSON inválido en la entrada. Mensaje de error: ' . $jsonError);
    }

    $channel    = $eses['channel'];
    $message = json_encode($eses['d']);

    if ($message === false && json_last_error() !== JSON_ERROR_NONE) {
        // Error al codificar JSON
        $jsonError = json_last_error_msg();
        http_response_code(400);
        die('Error: JSON inválido al codificar. Mensaje de error: ' . $jsonError);
    }
} else {
    die();
}

require '/home/encom/vendor/autoload.php';

$event         = 'checkoutScreen'; //$eses['event'];
$app_id     = "1524560";
$key         = "24c4d438c59b81f27107";
$secret     = "4b7be98a829b23c34882";
$cluster     = "sa1";

$pusher 	= new Pusher\Pusher($key, $secret, $app_id, ['cluster' => $cluster, 'useTLS' => true]);
$pushed 	= $pusher->trigger($channel, $event, ['message' => $message]);

echo '{a:1}';

?>