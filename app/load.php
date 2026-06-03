<?php
session_start();
require_once(__DIR__ . '/includes/cors.php');

$GLOBALS['_execution_start'] = microtime(true);

function checkExecTime($reference = false){
  $toPrint = ($reference) ? $reference : $_SERVER;

  $executionTimeLast = microtime(true) - $GLOBALS['_execution_start'];

  if($executionTimeLast >= 1){
    file_put_contents(
        'cach/mysql_profiling_results.txt',
        $executionTimeLast . ':' . print_r($toPrint, true) . "\n",
        FILE_APPEND
    );
  }
  $GLOBALS['_execution_start'] = microtime(true);
}

require_once('includes/jwt_middleware.php');

// `?l=` se mantiene como sobre base64 pero SOLO para extraer la operación
// (`load`). Los IDs de tenant/usuario vienen exclusivamente del JWT firmado.
$get   = json_decode(base64_decode($_GET['l'] ?? ''), true) ?? [];
$post  = $_POST;
$load  = $get['load'] ?? null;

if (!empty($load)) {
  // rateLimiterId se setea por IP hasta que JWT defina registerId server-side.
  $rateLimiterId = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  require_once('head.php');
  ob_start();
  ob_implicit_flush(0);

  // JWT obligatorio — sin fallback. Project status: pre-producción
  // (ver context/01-producto.md "Estado actual del proyecto").
  if (!jwtAuthenticate()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Autenticación requerida']);
    exit;
  }

  $companyId  = AUTHED_COMPANY_ID;
  $outletId   = AUTHED_OUTLET_ID;
  $userId     = AUTHED_USER_ID;
  $roleId     = AUTHED_ROLE_ID;
  $registerId = AUTHED_REGISTER_ID;
  $get        = $db->Prepare($get);
  if(!checkCompanyStatus($companyId)){
    jsonDieMsg();
  }
  include_once('data.php');
  
  if($load == 'bancardQR'){

    $header =   [
                  "Accept: application/json",
                  "Authorization: Bearer " . BANCARD_QR_API_TOKEN,
                  "Content-Type: application/json"
                ];

    $data   =   [];

    if($get['type'] == 'create' && $get['QRAmount']){

      $companyName = str_replace('&', 'y', COMPANY_NAME);

      $data   =   [
                    "amount"      => $get['QRAmount'],
                    "description" => 'Pago a ' . $companyName,
                    "identifier"  => json_encode([
                                                  'companyID'   => enc(COMPANY_ID),
                                                  'outletID'    => enc(OUTLET_ID),
                                                  'registerID'  => enc(REGISTER_ID),
                                                  'UID'         => $get['UID'],
                                                  'amount'      => $get['QRAmount'],
                                                  'saleAmount'  => $get['saleAmount'],
                                                  'comission'   => $get['comission'] ?? NULL,
                                                  'tax'         => $get['tax'] ?? NULL
                                                ])
                  ];

      echo curlContents(BANCARD_QR_API . '/create', 'POST', json_encode($data), $header);

    }else if($get['type'] == 'refresh' && $get['id']){

      echo curlContents(BANCARD_QR_API . '/refresh/' . $get['id'], 'POST', json_encode($data), $header);

    }else if($get['type'] == 'cancel' && $get['id']){

      echo curlContents(BANCARD_QR_API . '/revert/' . $get['id'], 'POST', json_encode($data), $header);

    }

    dai();
  }

  if($load == 'pixQR'){

    $headerToken =   [
      "Accept: application/json",
      "Content-Type: application/json"
    ];

    $dataPixToken = [
      "grant_type" => "client_credentials",
      "client_id" => API_PIX_CLIENT_ID,
      "secret"   => API_PIX_SECRET
    ];

    $pixToken =  json_decode(curlContents(API_PIX_URL . "/api/token", 'POST', json_encode($dataPixToken), $headerToken), true);

    if(!isset($pixToken['token'])){
      jsonDieResult(['error' => 'Pix token not found'], 400);
    }else{
      $pixToken = $pixToken['token'];
    }

    $header =   [
                  "Accept: application/json",
                  "Authorization: Bearer " . $pixToken,
                  "Content-Type: application/json"
                ];

    $data   =   [];

    if($get['type'] == 'create' && $post['QRAmount'] && $post['description'] && $post['name'] && $post['cpf']){

      $companyName = str_replace('&', 'y', COMPANY_NAME);

      $data   =   [
                    "amount"      => $post['QRAmount'],
                    "name"      => $post['name'],
                    "phone"      => $post['phone'] ?? '',
                    "email"      => $post['email'] ?? '',
                    "description" => $post['description'] . ' - ' . $companyName,
                    "cpf"      => $post['cpf'],
                  ];
      
      $result = json_decode(curlContents(API_PIX_URL . '/api/generate_qr', 'POST', json_encode($data), $header), true);

      if(isset($result['error'])){
        jsonDieResult(['error' => $result['error']], 400);
      } 

      $result['token'] = $pixToken;

      echo json_encode($result);

    }else if($get['type'] == 'cancel' && $get['id']){

      //echo curlContents(API_PIX_URL . '/revert/' . $get['id'], 'POST', json_encode($data), $header);

    }

    dai();
  }

  if($load == 'verifyTransactionPix'){

    $header =   [
      "Accept: application/json",
      "Authorization: Bearer " . $get['token'],
      "Content-Type: application/json"
    ];

    $result         = json_decode(curlContents(API_PIX_URL .'/api/transaction/' . $get['referenceId'],'GET', false, $header), true);

    if(isset($result['error'])){
      jsonDieResult(['error' => $result['error']], 400);
    } else {
      jsonDieResult(['success' => $result], 200);
    }
    
  }

  if($load == 'ePOSPending'){
    $data           = [
                        'api_key'       => API_KEY,
                        'company_id'    => enc(COMPANY_ID)
                      ];

    $result         = json_decode(curlContents(API_URL .'/get_vpayments','POST',$data), true);
    $returns        = [];

    if(validity($result['success'])){
      foreach ($result['success'] as $key => $value) {
        if(!validity($value['UID'])){
          $returns[] = $value;
        }
      }
    }

    jsonDieResult(['success' => $returns], 200);
  }


  if($load == 'verifyTransactionEPOS'){
    $data           = [
                        'api_key'       => API_KEY,
                        'company_id'    => enc(COMPANY_ID),
                        'UID'           => $get['uid']
                      ];

    $result         = json_decode(curlContents(API_URL .'/get_vpayments','POST',$data), true);
    if(isset($result['error'])){
      jsonDieResult(['error' => $result['error']], 400);
    } else {
    jsonDieResult(['success' => $result], 200);
    }
    
  }

  // calendar_resources_json / calendar_week_json / calendar_agenda_json / calendar_month → migrados a bff/schedule (Slice 41, 2026-06-03 — ScheduleService::getCalendar*)

  // ordersPanelAPI → migrado a bff/orders (Slice 40, 2026-06-02 — OrderService::getPanelList)

  // ordersList + isset(t) → migrado a bff/orders (Slice 27)


  // customerRecord → migrado a bff/customers + #customerRecordTpl (Slice 33)


  // userLocation → migrado a bff/orders (Slice 39, 2026-06-02 — OrderService::getNextDeliveryForUser)

  // transactions → migrado a bff/transactions (Slice 29)

  // sessionsList → migrado a bff/schedule (Slice 30)

  // agendaList → migrado a bff/schedule (Slice 31)

  // ordersList sin `t` → migrado a bff/orders (Slice 27)

  // quotesList + savedList → migrado a bff/transactions (Slice 28)

  // tin → migrado a bff/tin (Slice 38, 2026-06-02 — TinService llama directo a Marangatu)

  checkExecTime($load);

  print_gzipped_page();
  dai();
}else{
  http_response_code(401);
  header('Content-Type: application/json');
  die(json_encode(['error'=>'true']));
}

?>