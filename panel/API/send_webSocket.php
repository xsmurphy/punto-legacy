<?php
require '/home/encom/vendor/autoload.php';
include_once('api_head.php');

$channel 	= validateHttp('channel','post');
$event 		= validateHttp('event','post');
$message	= validateHttp('message','post');

$app_id 	= "1524560";
$key 		= "24c4d438c59b81f27107";
$secret 	= "4b7be98a829b23c34882";
$cluster 	= "sa1";

//error_log(json_encode([$channel,$event,$message]), 3, "error_log");

$pusher 	= new Pusher\Pusher($key, $secret, $app_id, ['cluster' => $cluster, 'useTLS' => true]);
$pushed 	= $pusher->trigger($channel, $event, ['message' => $message]);

?>