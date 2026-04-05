<?php

require_once(__DIR__ . '/../includes/cors.php');

require_once('../libraries/whoops/autoload.php');
include_once("../libraries/adodb/adodb.inc.php");
$db = ADONewConnection('mysqli');
$db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_905');
include_once('../includes/simple.config.php');
include_once("../libraries/hashid.php");
include_once("../includes/functions.php");

function enc($str){
	$hashids = new Hashids\Hashids(SALT);
	return $hashids->encode($str);
}

//decode ID
function dec($str){
	$hashids = new Hashids\Hashids(SALT);
	$decoded = $hashids->decode($str)[0];
	return (int)$decoded;
}

if(!validateAPIAccess($_GET['company_id'],$_GET['api_key'])){
	dai(jsonError('Acceso denegado'));
}

$limitQ 		= '2000';
$companyId 		= dec($_GET['company_id']);//2158

define('COMPANY_ID', $companyId);
define('OUTLET_ID', 0);
define('TODAY', date('Y-m-d H:i:s'));

$type = 'bankName';

$result = $db->Execute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = ? AND companyId = ".COMPANY_ID,array($type));
	
$out = array();

while (!$result->EOF) {
    $out[] = array('tagid'=>$result->fields['taxonomyId'],'tagname'=>unXss($result->fields['taxonomyName']));

    $result->MoveNext(); 
}

echo json_encode($out);

dai();
?>