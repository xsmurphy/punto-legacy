<?php
include_once('../tp_head.php');

//Aqui guardo el token del usuario
$DB_APPKEY      = 'rxl1dgd24nrjixg';
$DB_APPSECRET   = '2lb8zvzbldszurj';

$token      = urldecode( validateHttp('code') );
$state      = base64_decode( urldecode( validateHttp('state') ) );

$COMPANY_ID = dec($state);

$params   =   [
                      'code'          => $token,
                      'grant_type'    => 'authorization_code',
                      'redirect_uri'  => '/thirdparty/dropbox/auth.php',
                      'client_id'     => $DB_APPKEY,
                      'client_secret' => $DB_APPSECRET
                  ];

$tokens    = json_decode(curlContents('https://api.dropbox.com/1/oauth2/token','POST',$params),true);

$updated    = ncmUpdate([
                      'table' => 'company',
                      'records' => [ 
                                      'dropboxToken' => $tokens['access_token']
                                    ],
                      'where'   => 'companyId = ' . $COMPANY_ID
                    ]);

if(!$updated['error']){
  header('location:/@#modules');
}else{
  echo '<div style="text-align:center;">No se pudo conectar ' . $updated['error'] . '</div>';
}

dai();
?>