<?php
if(!$_GET['a'] || $_GET['a'] != NCM_SECRET){
	die('I died');
}

include_once("../libraries/adodb/adodb.inc.php");
$db = ADONewConnection('mysqli');
//$db->debug = true;
$db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_905');

include_once('../includes/simple.config.php');
include_once("../includes/config.php");
include_once("../languages/".LANGUAGE.".php");
include_once("../includes/functions.php");

$result = $db->Execute("SELECT companyId, expiresAt FROM company WHERE expiresAt = '".date('Y-m-d 00:00:00', strtotime('+2 day'))."' AND plan = 3");
$expires = array();
$c = 0;
$e = 0;
while (!$result->EOF) {
	$id 			= $result->fields['companyId'];
	$where 			.= $id.',';
	$expires[$id] 	= $result->fields['expiresAt'];

	$c++;
	$result->MoveNext(); 
}

if($result->RecordCount() > 0){
	
	$user = $db->Execute("SELECT contactEmail, companyId FROM contact WHERE role = 1 AND type = 0 AND companyId IN('.rtrim($where,',').')");
	while (!$user->EOF) {
		$mail 		= $user->fields['contactEmail'];
		$compId 	= $user->fields['companyId'];
		$expDate 	= niceDate($expires[$compId]);

	   	$options = json_encode(array(
	              "to"=> array($mail),
	              "sub"=> array(
              		":email"=>array($mail),
              		":expiring_date"=>array($expDate)
              	   ),
	              "filters"=> array(
	                        "templates"=>array(
	                                  "settings"=>array("enable"=>1,"template_id"=>"ad009943-8d99-4cf9-886a-070a356d486c")
	                                )
	                      )
	              ));
	   	$e++;
	   sendSMTPEmail($options,$user->fields['contactEmail'],'Su version de prueba esta por finalizar','Income','Income');
	   	
	   //	echo $options;

	   $user->MoveNext(); 
	}

	$user->Close();
}

$file = basename(__FILE__, '.php');
$sub = 'Cron  '.$file.' processed successfully';
$nueva = $sub.' \n '.$c.' empresas procesadas y '.$e.' emails enviados';
sendEmail('drahgster+ncm.cronjob@gmail.com',$sub,$nueva,$nueva,'info@incomepos.com',false);


dai();
?>