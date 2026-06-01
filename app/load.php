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

  if($load == 'calendar_resources_json' || $load == 'calendar_week_json'){
    
    $date       = iftn($get['date'],TODAY_DATE);

    if($load == 'calendar_week_json'){
      $weekRange  = explodes('|',$get['weekRange']);
      $startWeek  = $weekRange[0];//date('Y-m-d', strtotime('monday this week', strtotime($date)));
      $endWeek    = $weekRange[1];//date('Y-m-d', strtotime('sunday this week', strtotime($date)));

      $startDate  = iftn($date, TODAY_START, $startWeek . ' 00:00:00');
      $endDate    = iftn($date, TODAY_END, $endWeek . ' 23:59:59');
    }else{
      $startDate  = iftn($date, TODAY_START, $date . ' 00:00:00');
      $endDate    = iftn($date, TODAY_END, $date . ' 23:59:59');
    }
    
    $openFrom   = $setting['settingOpenFrom'];//apertura negocio
    $openTo     = $setting['settingOpenTo'];//cierre negocio
    $table      = '';
    $calendarArray  = [];
    $user       = '';
    $jsonOut    = [];

    if(validity($get['resource'])){
      $user = ' AND contactId = ' . dec($get['resource']);
    }
    
    $sqlUsers   = "SELECT STRING_AGG(contactId::text, ',') as users FROM contact WHERE type = 0 AND (outletId < 1 OR outletId = ?) AND companyId = ?" . $user . " ORDER BY contactCalendarPosition ASC LIMIT 100";

    $users      = ncmExecute($sqlUsers, [OUTLET_ID,COMPANY_ID], true);

    $sqlDates   = "SELECT *
                  FROM transaction 
                  WHERE transactionType = 13 
                  AND transactionStatus != 4
                  AND transactionStatus != 5
                  AND userId IN(" . $users['users'] . ")
                  AND fromDate > ? 
                  AND toDate < ? 
                  AND outletId = ?
                  AND companyId = ? LIMIT 500";

    if(!empty($_GET['test'])){
      echo $sqlDates;
      die();
    }

    $doCache    = false; //veo de cachear queries que sean menores a hoy
    $trans      = ncmExecute($sqlDates, [$startDate,$endDate,OUTLET_ID,COMPANY_ID],$doCache,true);

    if($trans){
      while (!$trans->EOF) {
        $fields = $trans->fields;

        if($fields['transactionStatus'] == 0){
          $icon = 'stars';
          $color = 'dark';
        }else if($fields['transactionStatus'] == 1){
          $icon = 'thumb_up';
          $color = 'info';
        }else if($fields['transactionStatus'] == 2){
          $icon = 'keyboard_arrow_down';
          $color = 'warning';
        }else if($fields['transactionStatus'] == 3){
          $icon = 'keyboard_arrow_right';
          $color = 'success';
        }else if( in_array($fields['transactionStatus'], [4,7]) ){
          $icon = 'block';
          $color = 'dark';
        }else if($fields['transactionStatus'] == 5){
          $icon = 'person_add_disabled';
          $color = 'danger';
        }else if($fields['transactionStatus'] == 6){
          $icon = 'check';
          $color = 'dark';
        }

        $jsonOut[] = [
                      'userId'        => enc($fields['userId']),
                      'responsibleId' => enc($fields['responsibleId']),
                      'icon'          => $icon,
                      'color'         => $color,
                      'customerId'    => enc($fields['customerId']),
                      'customerUnd'   => $fields['customerId'],
                      'start'         => $fields['fromDate'],
                      'end'           => $fields['toDate'],
                      'items'         => '',
                      'total'         => CURRENCY . formatCurrentNumber($fields['transactionTotal'],$dec,$ts),
                      'id'            => enc($fields['transactionId']),
                      'blocked'       => ($fields['transactionStatus'] == 7) ? true : false,
                      'note'          => $fields['transactionNote'],
                      'status'        => $fields['transactionStatus'],
                      'details'       => json_decode($fields['transactionDetails'], true)
                    ];

        $trans->MoveNext();
      }
    }

    jsonDieMsg($jsonOut,200,'data');
  }

  if($load == 'calendar_agenda_json'){

    $date       = iftn($get['date'],TODAY_DATE);
    $startDate  = iftn($date, TODAY_START, $date . ' 00:00:00');
    $endDate    = iftn($date, TODAY_END, $date . ' 23:59:59');

    $startMonth = date('Y-m-d 00:00:00', strtotime('first day of this month', strtotime($date)));
    $endMonth   = date('Y-m-d 00:00:00', strtotime('last day of this month', strtotime($date)));

    $sqlDates   = "SELECT *
                  FROM transaction 
                  WHERE transactionType = 13 
                  AND transactionStatus != 4
                  AND transactionStatus != 5
                  AND fromDate > ? 
                  AND toDate < ? 
                  AND outletId = ? 
                  AND companyId = ?
                  ORDER BY fromDate ASC";

    $trans      = ncmExecute($sqlDates, [$startMonth,$endMonth,OUTLET_ID,COMPANY_ID],false,true);
    $jsonOut    = [];
    $group      = [];

    if($trans){
      
      while (!$trans->EOF) {
        $fields     = $trans->fields;
        $day        = niceDate($fields['fromDate']);
        $cusData    = ncmExecute('SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',[$fields['customerId'],COMPANY_ID]);
        $cusName    = iftn($cusData['contactSecondName'],$cusData['contactName']);

        $group[$day][] = [
                            'id'      => enc($fields['transactionId']),
                            'hour'    => date('H:i',strtotime($fields['fromDate'])),
                            'name'    => $cusName,
                            'status'  => $fields['transactionStatus']
                          ];

        $trans->MoveNext();
      }

      $i = 0;
      foreach($group as $date => $groupData){
        $jsonOut[$i]['date'] = $date;

        foreach($groupData as $trans){
          if($trans['status'] != 7){
            $jsonOut[$i]['schedules'][] =  [
                                            'id'      => $trans['id'],
                                            'hour'    => $trans['hour'],
                                            'name'    => $trans['name']
                                            ];
          }
        }

        $i++;
      }
      
    }

    jsonDieMsg($jsonOut,200,'data');
  }

  if($load == 'calendar_month'){
    $date       = iftn($get['date'],TODAY_DATE);

    $startWeek  = date('Y-m-d', strtotime('first day of this month', strtotime($date)));
    $endWeek    = date('Y-m-d', strtotime('last day of this month', strtotime($date)));

    $startDate  = iftn($date, TODAY_START, $startWeek . ' 00:00:00');
    $endDate    = iftn($date, TODAY_END, $endWeek . ' 23:59:59');
    
    $sqlDates   = "SELECT DATE(fromDate) as dates,
                          COUNT(transactionId) as count
                  FROM transaction 
                  WHERE transactionType = 13 
                  AND transactionStatus != 4
                  AND transactionStatus != 5
                  AND fromDate > ? 
                  AND toDate < ? 
                  AND outletId = ? 
                  AND companyId = ?
                  GROUP BY dates";

    $transaction  = ncmExecute($sqlDates,[$startDate,$endDate,OUTLET_ID,COMPANY_ID],false,true);

    if($transaction){
      while (!$transaction->EOF) {
        $fields = $transaction->fields;
        $transactions[$fields['dates']] = $fields['count'];
        $transaction->MoveNext(); 
      }
      $transaction->Close();
    }

    $month      = date('m',strtotime($date));
    $year       = date('Y',strtotime($date));

    $daysOfWeek         = array('Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado');
    $firstDayOfMonth    = mktime(0,0,0,$month,1,$year);
    $numberDays         = date('t',$firstDayOfMonth);
    $dateComponents     = getdate($firstDayOfMonth);
    $monthName          = $dateComponents['month'];
    $dayOfWeek          = $dateComponents['wday'];
    $calendar           = "<table class='table scheduler table-bordered m-b-lg'>";
    $calendar           .= "<tr>";

    foreach($daysOfWeek as $day) {
      $calendar .= '<th class="dk" style="width:14.3%;">' . $day . '</th>';
    }

    $currentDay   = 1;
    $calendar     .=  "</tr>" .
                      "<tr>";

    if ($dayOfWeek > 0) {
      $calendar .= "<td colspan='$dayOfWeek' class='bg-light dker'>&nbsp;</td>";
    }

    $month = str_pad($month, 2, "0", STR_PAD_LEFT);

    while($currentDay <= $numberDays){
      if($dayOfWeek == 7){
        $dayOfWeek = 0;
        $calendar .= "</tr><tr>";
      }

      $currentDayRel = str_pad($currentDay, 2, "0", STR_PAD_LEFT);
      $date = $year . '-' . $month . '-' . $currentDayRel;

      $calendar .=  '<td class="ncmCalendarDay ' . ((date('Y-m-d') == $date) ? 'bg-white' : '') . ' scrolleable pointer clickeable" rel="' . $date . '" data-type="calendarView" data-mode="calendar_resources" data-date="' . $date . '" style="height:100px;">' .
                    ' <div>' . 
                    '   <span class="badge ' . ((date('Y-m-d') == $date) ? 'bg-info' : 'bg-white') . '">' . $currentDay . '</span>' .
                    ' </div>' .
                    ' <div class="text-center">' .
                    '   <div class="font-bold h2">' . ( isset($transactions[$date]) ? $transactions[$date] : '0' ) . '</div>' .
                    '   <div class="text-xs text-muted">Reserva(s)</div>' .
                    ' </div>' .
                    '</td>';

      $currentDay++;
      $dayOfWeek++;
    }

    if($dayOfWeek != 7){
      $remainingDays = 7 - $dayOfWeek;
      $calendar .= "<td colspan='$remainingDays' class='bg-light dker'>&nbsp;</td>";
    }

    $calendar .= "</tr>";
    $calendar .= "</table>";

    $table    = $calendar;

    if($get['solo']){
      $title    = '';
    }else{
      $title    = buildCalendarTop(['date'=>$date,'current'=>'month']);
    }

    $bottomSpace = '<div class="col-xs-12 wrapper-xl"></div><div class="col-xs-12 wrapper-xl"></div>';
    
    $wrapper  = '<div class="table-responsive bg-light panel no-padder m-b-lg" style="overflow-y:hidden;">' . $title . $table . $bottomSpace . '</div>';

    echo $wrapper;
    dai();
  }

  if($load == 'ordersPanelAPI'){

    $data =   [
                'api_key'       => API_KEY,
                'company_id'    => enc(COMPANY_ID),
                'order'         => 'lastUpdated',
                'children'      => 'all',
                'customerdata'  => 1
              ];

    $getList        = true;
    $timestamp      = $get['lastChk'];
    $oID            = $get['ID'] ?? false;
    $array          = [];
    $orders         = [];

    if($timestamp){
      //consulto las updated order
      $updated = json_decode(curlContents(API_URL . '/get_last_update.php','POST',$data),true);
      if( strtotime( $updated['orders'] ) < strtotime( $timestamp ) ){
        $getList          = false;
      }
    }

    if($getList){
      $data['type']     = 12;
      $data['limit']    = 80;
      $data['order']    = 'DESC';
      $data['outlet']   = enc(OUTLET_ID);
      $data['status']   = '0,1,2,3,5';
      $date             = iftn($get['date'],date('Y-m-d 23:59:59'));

      $data['from']     = date('Y-m-d H:i:s',strtotime('-1 month'));
      $data['to']       = $date;
      //$data['test']     = 1;

      if( validity($oID) ){
        $data['ID']     = $oID;
      }

      $result           = json_decode(curlContents(API_URL . '/get_orders.php','POST',$data),true);
      //$array['orders']  = $result;
      if(isset($_GET['debug'])){
        echo '<pre>';
        print_r($result);
        echo '</pre>';
        dai();
      }

      if(!isset($result['error']) && validity($result,'array')){
        foreach ($result as $date => $dats) {
          $name       = $dats['order_name'];
          $oDate      = $dats['date'];

          if(is_numeric($name)){
            $source     = 'table';
          }else if($name == 'ecom'){
            $source     = 'ecom';
            $name       = '';  
          }else{
            $source     = false;
            $name       = '';  
          }

          $orders[] = [
                        'id'          => $dats['transaction_id'],
                        'table'       => $name,
                        'orderNo'     => $dats['number_id'],
                        'source'      => $source,
                        'status'      => $dats['order_status'],
                        'note'        => $dats['order_note'],
                        'userId'      => $dats['user_id'],
                        'customerId'  => $dats['customer_id'],
                        'customerName'=> $dats['customer_name'],
                        'statusColor' => $dats['order_status_color'],
                        'statusName'  => $dats['order_status_name'],
                        'created'     => strtotime($oDate),
                        'createdDate' => $oDate,
                        'orderDue'    => $dats['due_date'],
                        'tags'        => $dats['order_tags'],
                        'address'     => $dats['customer_address'],
                        'city'        => $dats['customer_city'],
                        'location'    => $dats['customer_location'],
                        'lat'         => $dats['customer_lat'],
                        'lng'         => $dats['customer_lng']
                      ];
        }
      }
      
    }

    jsonDieMsg($orders,200,'orders');
  }

  // ordersList + isset(t) → migrado a bff/orders (Slice 27)


  // customerRecord → migrado a bff/customers + #customerRecordTpl (Slice 33)

  if($load == 'customerAddress' && $get['id']){
    $jsonOut    = [];

    if(is_numeric($get['id'])){
      $cusId      = $get['id'];
    }else{
      $cusId      = dec($get['id']);
    }

    if(isset($get['aid'])){
      $aid        = dec($get['aid']);
      $records    = ncmExecute('SELECT * FROM customerAddress WHERE customerAddressId = ? AND companyId = ? LIMIT 1',[$aid,COMPANY_ID],false);

      if($records){
        $jsonOut = [
                    "id"        => enc($records['customerAddressId']),
                    "name"      => $records['customerAddressName'],
                    "address"   => $records['customerAddressText'],
                    "default"   => $records['customerAddressDefault'],
                    "location"  => $records['customerAddressLocation'],
                    "city"      => $records['customerAddressCity'],
                    "latLng"    => ($records['customerAddressLat'] ? $records['customerAddressLat'] . ',' . $records['customerAddressLng'] : false),
                    "lat"       => $records['customerAddressLat'],
                    "lng"       => $records['customerAddressLng'],
                    "customerId" => enc($records['customerId'])
                  ];
      }

    }else{
      $records    = ncmExecute('SELECT * FROM customerAddress WHERE customerId = ? AND companyId = ? ORDER BY customerAddressDefault DESC, customerAddressId DESC LIMIT 10',[$cusId,COMPANY_ID],false,true); 

      if($records){
        while (!$records->EOF){
          $field     = $records->fields;
          $jsonOut[] = [
                        "id"        => enc($field['customerAddressId']),
                        "name"      => $field['customerAddressName'],
                        "address"   => $field['customerAddressText'],
                        "default"   => $field['customerAddressDefault'],
                        "location"  => $field['customerAddressLocation'],
                        "city"      => $field['customerAddressCity'],
                        "latLng"    => ($field['customerAddressLat'] ? $field['customerAddressLat'] . ',' . $field['customerAddressLng'] : false),
                        "lat"       => $field['customerAddressLat'],
                        "lng"       => $field['customerAddressLng'],
                        "customerId" => enc($field['customerId'])
                      ];

          $records->MoveNext();
        }
      }

    }

    jsonDieMsg($jsonOut,200,'addresses');
  }

  // customerInfo → migrado a bff/customers (Slice 32)


  if($load == 'userLocation' && $get['id']){
    $jsonOut  = [];
    $id       = dec( $db->Prepare($get['id']) );
    $result   = ncmExecute('SELECT * FROM contact WHERE type = 0 AND contactTrackLocation = 1 AND contactId = ? AND companyId = ? LIMIT 1',[$id,COMPANY_ID]);
    if($result){
      if($result['contactLatLng']){
        $orderD =   [
                      'api_key'       => API_KEY,
                      'company_id'    => enc(COMPANY_ID),
                      'user'          => enc($result['contactId']),
                      'type'          => 12,
                      'limit'         => 1,
                      'order'         => 'ASC',
                      'status'        => '5',//en camino
                      'from'          => date('Y-m-d H:i:s',strtotime('-1 month')),
                      'to'            => date('Y-m-d 23:59:59'),
                      'customerdata'  => 1
                    ];

        $order           = json_decode(curlContents(API_URL . '/get_orders.php','POST',$orderD),true);

        //print_r($orderD);
        
        if(!isset($order['error']) && validity($order,'array')){
          foreach ($order as $date => $dats) {
          
            $jsonOut['orderData'] = [
                                      'id'            => $dats['transaction_id'],
                                      'orderNo'       => $dats['number_id'],
                                      'customerId'    => $dats['customer_id'],
                                      'customerName'  => $dats['customer_name'],
                                      'address'       => $dats['customer_address'],
                                      'lat'           => $dats['customer_lat'],
                                      'lng'           => $dats['customer_lng']
                                    ];
          }
        }

        $lat = floatval( explodes(',',$result['contactLatLng'],0) );
        $lng = floatval( explodes(',',$result['contactLatLng'],1) );

        $jsonOut['lat'] = $lat;
        $jsonOut['lng'] = $lng;
        //obtengo datos de la proxima orden
      }

      jsonDieResult($jsonOut,200);
    }
      
    jsonDieResult(['error'=>'not found'],404);
    
  }

  // transactions → migrado a bff/transactions (Slice 29)

  // sessionsList → migrado a bff/schedule (Slice 30)

  // agendaList → migrado a bff/schedule (Slice 31)

  // ordersList sin `t` → migrado a bff/orders (Slice 27)

  // quotesList + savedList → migrado a bff/transactions (Slice 28)

  if($load == 'tin'){
    echo curlContents(API_URL . '/get_tin?id=' . $get['id'] . '&country=' . $get['country'],'POST',['company_id'=>enc(COMPANY_ID),'api_key'=>API_KEY]);
  }

  checkExecTime($load);

  print_gzipped_page();
  dai();
}else{
  http_response_code(401);
  header('Content-Type: application/json');
  die(json_encode(['error'=>'true']));
}

?>