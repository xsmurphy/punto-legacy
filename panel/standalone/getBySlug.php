<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

echo 'bef head';

//OBTENGO DATOS DE LA API DE EMPRESAS POR EL SLUG
include_once('sa_head.php');

$get = validateHttp('s','post');

echo 'after head';

if(!$get){
  die();
}

echo 'after die';

$COMPANY_ID = false;
$API_KEY    = false;
$slug       = $db->Prepare(ncmDecode($get));
$setting    = ncmExecute("SELECT companyId FROM setting WHERE settingSlug = ? LIMIT 1",[$slug]);

if($setting){
  $company = ncmExecute("SELECT accountId,companyId FROM company WHERE companyId = ? LIMIT 1",[$setting['companyId']]);
  if($company){
    $API_KEY      = sha1($company['accountId']);
    $COMPANY_ID   = enc($company['companyId']);
    $credentials  = ncmEncode($API_KEY . ',' . $COMP);

    jsonDieResult(['creds' => $credentials],200);
  }else{
    echo 'not found 2';
  }
}else{
  echo 'not found';
}