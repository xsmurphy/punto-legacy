
<?php
//die();
require_once('../libraries/whoops/autoload.php');
include_once("../libraries/adodb/adodb.inc.php");

$db = ADONewConnection('mysqli');
$db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_905');
$db->cacheSecs 		= 3600*24;//cache 24 hs
$ADODB_CACHE_DIR 	= '../../../cache/adodb';//desde root panel hasta el cache
$ADODB_COUNTRECS 	= true;

include_once('../includes/simple.config.php');
include_once("../libraries/hashid.php");
include_once("../includes/config.php");
include_once("../includes/functions.php");

//aqui voy a obtener todos los datos que envia 2CO por POST para saber como ir procesando las ventas para comparar y poder saber a que factura corresponde el cobro

if($_POST){
	$all = json_encode($_REQUEST);

	$record = array();
	$record['taxonomyExtra']	= $all;
	$record['taxonomyType'] 	= 'lascatest';
	$record['taxonomyName'] 	= 'lascatest';

	$insert = $db->AutoExecute('taxonomy', $record, 'INSERT'); 

	if($insert === true){
		echo 'ok';
	}else{
		echo 'error';
	}
}else{

	dai('No data');
}
?>