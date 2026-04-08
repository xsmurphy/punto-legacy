<?php
require_once(__DIR__ . '/includes/cors.php');
header('Content-Type: application/json');
http_response_code(200);

if(isset($_GET['debug'])){
	$htmlUrl  	= '/index.php';
}else{
	$htmlUrl  	= '/index.php';
}

$html 	  	= @file_get_contents($htmlUrl);

$html 		= preg_match("/<body[^>]*>(.*?)<\/body>/is", $html, $matches);
$html  		= preg_replace('#<script type="text/javascript">(.*?)</script>#is', '', $matches[1]);

$out 	 	= ['html' => $html];

die(json_encode($out));
?>