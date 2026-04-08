<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$pushed = sendPush([
			"ids" 		=> 'J9',
			"message" 	=> "Notificaciones activadas", 
			"title" 	=> APP_NAME,
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

apiOk(['pushed'=>$pushed], 500);

?>