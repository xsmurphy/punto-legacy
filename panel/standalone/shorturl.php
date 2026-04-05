<?php
include_once('/home/encom/public_html/panel/includes/db.php');
include_once('/home/encom/public_html/panel/includes/simple.config.php');
include_once("/home/encom/public_html/panel/libraries/pseudocrypt.class.php");

$db->selectDb('shorturl');

$hashLngth 	= 4;
$notFound 	= '/home/encom/public_html/panel/includes/404.inc.php';

if(isset($_GET['u'])){
	$decoded 	= $db->Prepare(PseudoCrypt::unhash($_GET['u']));
	$url	 	= $db->Execute('SELECT url FROM shorturl WHERE id = ? LIMIT 1',[$decoded]);
	$theUrl 	= $url->fields['url'];

	if($_GET['expose']){
		print_r($theUrl);
		die();
	}

	if($theUrl){
		$db->Close();
		header('location: ' . $theUrl);
	}else{
		include_once($notFound);
	}
}else if(isset($_GET['c'])){
	/*$ref 		= $_SERVER['HTTP_REFERER'];
	$refData 	= parse_url($ref);

	if(!in_array($refData['host'],['encom.app','encom.site'])) {
		include_once($notFound);
		$db->Close();
		die();
	}*/

	$url	 	= $db->Execute('SELECT id FROM shorturl WHERE url = ? LIMIT 1',[$_GET['c']]);
	$theId 		= $url->fields['id'];

	if($theId){
		$cryptd = PseudoCrypt::hash($theId,$hashLngth);
		echo 'https://encom.app/u/' . $cryptd;
	}else{
		$record 		= [];
		$record['url'] 	= $_GET['c'];
		$insert 		= $db->AutoExecute('shorturl', $record, 'INSERT');
		$theId 			= $db->Insert_ID();
		$cryptd 		= PseudoCrypt::hash($theId,$hashLngth);
		echo 'https://encom.app/u/' . $cryptd;
	}	
}else{
	include_once($notFound);
}

$db->Close();
die();
?>