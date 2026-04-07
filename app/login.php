<?php
session_start();
require_once(__DIR__ . '/includes/cors.php');

require_once('includes/jwt.php');
require_once('includes/jwt_middleware.php');

$get    = $_GET;
$post   = $_POST;

$email  = strtolower($post['email']);
$pass   = $post['password'];

if($email && $pass){

  //check if email or phone

  $rateLimiterId = $_SERVER['REMOTE_ADDR'];
  include_once('head.php');

  $email  = db_prepare($email);
  $pass   = db_prepare($pass);
  $result = findEmailOrPhoneLogin($email);
  
  if($result){

    $check_password = passBuilder($pass,$result['salt']);

    if($check_password == $result['contactPassword']){
      // If they do, then we flip this to true
      $companyId      = $result['companyId'];
      $userId         = $result['contactId'];

      if(!checkCompanyStatus($companyId)){
        jsonDieMsg("Su cuenta está inhabilitada, por favor contáctenos");
      }

      $outlet = ncmExecute("SELECT outletId
                            FROM outlet
                            WHERE companyId = ? 
                            ORDER BY outletId ASC
                            LIMIT 1",
                            [$companyId]);

      $outletId = $outlet['outletId'];

      $register = ncmExecute("SELECT registerId
                              FROM register
                              WHERE outletId = ?
                              ORDER BY registerId ASC
                              LIMIT 1",
                              [$outletId]);
      $registerId = $register['registerId'];

      if(!validity($companyId) || !validity($outletId) || !validity($registerId) || !validity($userId)){
        jsonDieMsg("Error inesperado, por favor contáctenos");
      }

      // Emitir JWT y establecer cookie HttpOnly
      $jwtSecret = $_ENV['JWT_SECRET'] ?? '';
      $jwtToken  = '';
      if ($jwtSecret) {
          $ttl = (int)($_ENV['JWT_TTL'] ?? 28800);
          $now = time();
          $jwtPayload = [
              'sub'  => $userId,
              'cid'  => $companyId,
              'oid'  => $outletId,
              'rid'  => $registerId,
              'role' => (int)$result['role'],
              'iat'  => $now,
              'exp'  => $now + $ttl,
          ];
          $jwtToken = jwtEncode($jwtPayload, $jwtSecret);
          jwtSetCookie($jwtToken, $ttl);
      }

      $data = json_encode([
                            'companyId'  => enc($companyId),
                            'outletId'   => enc($outletId),
                            'registerId' => enc($registerId),
                            'userId'     => enc($userId),
                            'token'      => $jwtToken,
                          ]);

      header('Content-Type: application/json');
      dai($data);
    }else{
      jsonDieMsg("Su e-mail o contraseña no coinciden");
    }
  }else{
    jsonDieMsg("Su e-mail o contraseña no coinciden");
  }

}else{ //proceso de validación de telefono via SMS

  if($get['action'] == 'phone'){
    $rateLimiterId = $_SERVER['REMOTE_ADDR'];
    include_once('head.php');

    //gCaptcha($get['gtoken']);

    $phone     = getValidPhone($get['phone'],$get['country']);

    if($phone['type'] < 1 || (isset($phone['error']) && $phone['error'])){ //es linea baja o invalido
      jsonDieResult(['error'=>'landline'],500);
    }

    if((isset($phone['error']) && $phone['error'])){ //es linea baja o invalido
      jsonDieResult(['error'=>'invalid'],500);
    }

    $phone  = $phone['phone'];

    if($get['phoneaction'] == 'send'){

      $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
      $new     = ($get['new']) ? '&new=1' : '';
      $newpin  = json_decode(getFileContent(API_ENCOM_URL . '/2fapin.php?phone=' . $phone . $new),true);

      if((isset($newpin['error']) && $newpin['error'])){
        jsonDieResult($newpin,500);
      }

      $newpin = $newpin['code'];

      $msg = '[' . APP_NAME . '] ' . $newpin . ' es su código de verificación.';

      if(!$isDebug){
        $sentSMS = sendNCMSMS($phone,$msg,$get['country'],isset($get['id']) ?? '');
      }else{
        $sentSMS = true;
      }

      jsonDieResult([
                      'success' => $sentSMS,
                      'code'    => $isDebug ? $newpin : '',  // en debug retorna el código al cliente para autocomplete
            ]);
      
    }else if($get['phoneaction'] == 'check'){
      $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
      $pin     = $get['code'];

      if($isDebug){
        // En debug mode el código siempre es 0000
        $valid = ($pin === '0000');
      }else{
        $oldpin = json_decode(getFileContent(API_ENCOM_URL . '/2fapin.php?phone=' . $phone),true);
        $valid  = ($pin == $oldpin['code']);
      }

      if($valid){
        jsonDieResult(['success' => 'valid', 'phone' => $phone]);
      }else{
        jsonDieResult(['error' => 'invalid'], 500);
      }
    }
  }else if($get['action'] == '2FAQR'){
    include_once('head.php');

    if($get['chk']){
      $newpin = json_decode(getFileContent(API_ENCOM_URL . '/2fapin.php?checkCompany=1&code=' . base64_decode($get['chk']) . '&qr=1'),true);
      jsonDieResult([
                            'success'   => true,
                            'company'   => $newpin['company']
                    ]);
    }else if($get['scan']){

      $company  = $get['company'];
      $outlet   = $get['outlet'];
      $code     = $get['code'];

      $newpin   = json_decode(getFileContent(API_ENCOM_URL . '/2fapin.php?scan=1&company=' . $company . '&outlet=' . $outlet . '&code=' . $code . '&qr=1'),true);

      jsonDieResult([
                            'success'   => $newpin['success']
                  ]);
    }else{
      $new    = ($get['new']) ? '&new=1' : '';
      $dui    = $get['dui'];
      $newpin = json_decode(getFileContent(API_ENCOM_URL . '/2fapin.php?phone=' . $dui . $new . '&qr=1'),true);

      $newpin = $newpin['code'];

      jsonDieResult([
                            'success'   => true,
                            'code'      => $newpin
                  ]);
    }
    
  }

  http_response_code(401);
  header('Content-Type: application/json');
  die(json_encode(['error'=>'Debe completar todos los campos']));
}
?>