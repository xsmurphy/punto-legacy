<?php
require_once(__DIR__ . '/../includes/cors.php');

include_once('/home/encom/public_html/panel/includes/db.php');
include_once('/home/encom/public_html/panel/includes/simple.config.php');
include_once("/home/encom/public_html/panel/includes/functions.php");

function enc($str): string { return (string)$str; }

function dec($str): string { return (string)$str; }

$get        = validity($_GET);

if(validity($get['a']) != NCM_SECRET){
  die('Error');
}

$slug       = db_prepare( $get['s'] );
$setting    = ncmExecute("SELECT companyId FROM company WHERE slug = ? LIMIT 1",[$slug],true);

if($setting){
  $company = ncmExecute("SELECT accountId,companyId FROM company WHERE companyId = ? LIMIT 1",[$setting['companyId']],true);
  if($company){
    $API_KEY      = sha1($company['accountId']);
    $COMPANY_ID   = enc($company['companyId']);

    jsonDieResult(['API_KEY' => $API_KEY, 'COMPANY_ID' => $COMPANY_ID],200);
  }else{
    echo 'not found 2';
  }
}else{
  echo 'not found';
}