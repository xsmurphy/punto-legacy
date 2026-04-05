<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
http_response_code(200);

if(isset($_GET['debug'])){
	$htmlUrl  	= 'https://app.encom.app/index.php';
}else{
	$htmlUrl  	= 'https://app.encom.app/index.php';
}

$html 	  	= @file_get_contents($htmlUrl);

$html 		= preg_match("/<body[^>]*>(.*?)<\/body>/is", $html, $matches);
$html  		= preg_replace('#<script type="text/javascript">(.*?)</script>#is', '', $matches[1]);

$out 	 	= ['html' => $html];

die(json_encode($out));
?>