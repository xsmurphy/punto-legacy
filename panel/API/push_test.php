<?php
include_once('api_head.php');

$pushed = sendPush([
			"ids" 		=> 'J9',
			"message" 	=> "Notificaciones activadas", 
			"title" 	=> "ENCOM",
			"filters"   => [
            					[
                                  "key"   => "userId",
                                  "value" => "Vj3a"
                              	],
                              	[
                                  "key"   => "isResource",
	                              "value" => "true"
                              	]
                            ]
		]);

jsonDieResult(['pushed'=>$pushed],500);

?>