<?php
$ops = 	[
		    "ssl" => [
				        "verify_peer" 		=> false,
				        "verify_peer_name" 	=> false,
				    ],
			'http' => [
						'header' 			=> 
						'Cookie: ' . $_SERVER['HTTP_COOKIE'] . "\r\n"
					]
		];  

$html = file_get_contents('https://public.encom.app/mapIframe?height=' . $_GET["height"] . '&draggable=' . $_GET["draggable"] . '&lat=' . $_GET["lat"] . '&lng=' . $_GET["lng"] . '&theme=' . $_GET["theme"] . '&zoom=' . $_GET["zoom"] . '&key=' . $_GET["key"] . '&debug=1', false, stream_context_create($ops));

echo $html;

?>