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

$get        = json_decode(base64_decode($_GET['l']),true);
$post   = $_POST;
$load       = $get['load'];
$companyId  = $get['companyId'];
$outletId   = $get['outletId'];
$userId     = $get['userId'];
$roleId     = $get['roleId'];
$registerId = $get['registerId'];
if(!empty($load) && !empty($companyId) && !empty($outletId) && !empty($userId) && !empty($roleId) && !empty($registerId)){
  $rateLimiterId = $registerId;
  require_once('head.php');
  ob_start();
  ob_implicit_flush(0);
  $companyId  = $db->Prepare(dec($companyId));
  $outletId   = $db->Prepare(dec($outletId));
  $userId     = $db->Prepare(dec($userId));
  $roleId     = $db->Prepare($roleId);
  $registerId = $db->Prepare(dec($registerId));
  $get        = $db->Prepare($get);
  if(!checkCompanyStatus($companyId)){
    jsonDieMsg();
  }
  include_once('data.php');
  
  if($load == 'tweet'){
    require_once 'libraries/twitter.php';

    //Twitter OAuth Settings, enter your settings here:
    $settings = array(
    'oauth_access_token'        => "805568125412507648-lsCvE3B9nIrJyBwIyZmJuJ6i9qaZ0kJ",
    'oauth_access_token_secret' => "aCITiQ3pL4MtlgwpECGJ8cMlZH6P9RFXOrxTBrq13boUG",
    'consumer_key'              => "AK0tHo8hnw8Yb1goP6NwVEEhm",
    'consumer_secret'           => "2R2QBMBVP7X3OACH2bdmvCucVkdORwCop5WLer7YrSRgb0OPuq"
    );

    $screen_name = 'encom_app';

    // Get timeline using TwitterAPIExchange
    $url            = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
    $getfield       = "?screen_name={$screen_name}";
    $requestMethod  = 'GET';

    $twitter = new TwitterAPIExchange($settings);
    $user_timeline = $twitter
    ->setGetfield($getfield)
    ->buildOauth($url, $requestMethod)
    ->performRequest();

    if(!$user_timeline){
      return false;
    }

    function url_to_clickable_link($plaintext) {
      return preg_replace(
      '%(https?|ftp)://([-A-Z0-9-./_*?&;=#]+)%i', 
      '<a class="clickeable" data-type="link" rel="nofollow" href="$0" target="_blank">$0</a>', $plaintext);
    }


    $result     = json_decode($user_timeline,true);
    $time       = strtotime($result[0]['created_at']);
    $createdAt  = date('d M, Y',$time);
    $limit      = 6;

    ?>
    <div class="text-xs text-u-c text-right font-bold text-white m-b-sm">Tips & Novedades</div>
    <div class="carousel slide" data-ride="carousel" id="tips-carousel">
      
      <div class="carousel-inner">
      <?php
      $i = 0;
      while($i < $limit){
      ?>
        <div class="item text-md <?=($i < 1) ? 'active' : ''?>">
          <p style="min-height: 105px;"><?=url_to_clickable_link($result[$i]['text'])?></p>
        </div>
      <?php
        $i++;
      }
      ?>
      </div>
      <ol class="carousel-indicators hidden" style="bottom:none;">
        <?php
        $i = 0;
        while($i < $limit){
        ?>
        <li data-target="#tips-carousel" data-slide-to="<?=$i;?>" class="<?=($i < 1) ? 'active' : ''?>"></li>
        <?php
          $i++;
        }
        ?>
      </ol>
    </div>
    <script type="text/javascript">
      $(document).ready(function() {
        //Set the carousel options
        $('#tips-carousel').carousel({
          pause: true,
          interval: 7500,
        });
      });
    </script>
    <?php
    dai();
  }

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

    $result         = json_decode(curlContents(API_ENCOM_URL .'/get_vpayments','POST',$data), true);
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

    $result         = json_decode(curlContents(API_ENCOM_URL .'/get_vpayments','POST',$data), true);
    if(isset($result['error'])){
      jsonDieResult(['error' => $result['error']], 400);
    } else {
    jsonDieResult(['success' => $result], 200);
    }
    
  }

  if($load == 'calendar_resources'){
    
    $date       = iftn($get['date'],TODAY_DATE);
    $startDate  = iftn($date, TODAY_START, $date . ' 00:00:00');
    $endDate    = iftn($date, TODAY_END, $date . ' 23:59:59');
    $openFrom   = $setting['settingOpenFrom'];//apertura negocio
    $openTo     = $setting['settingOpenTo'];//cierre negocio
    $table      = '';
    $calendarArray  = [];
    $user       = '';

    if(validity($get['resource'])){
      $user = ' AND contactId = ' . dec($get['resource']);
    }
    
    $sqlUsers   = "SELECT contactId, contactName, contactColor, contactId FROM contact WHERE type = 0 AND contactStatus = 1 AND contactInCalendar = 1 AND (outletId < 1 OR outletId = ?) AND companyId = ?" . $user . " ORDER BY contactCalendarPosition ASC";
    $users      = $db->GetAssoc($sqlUsers, [OUTLET_ID,COMPANY_ID]);
    $inUs       = [];
    foreach($users as $id => $data){
      $inUs[] = $id;
    }

    $sqlDates   = "SELECT *
                  FROM transaction 
                  WHERE transactionType = 13 
                  AND transactionStatus != 4
                  AND transactionStatus != 5
                  AND userId IN(" . implodes(',',$inUs) . ")
                  AND fromDate > ? 
                  AND toDate < ? 
                  AND outletId = ? 
                  AND companyId = ?";


    $trans          = $db->GetAssoc($sqlDates, [$startDate,$endDate,OUTLET_ID,COMPANY_ID]);


    if($users){
      $columnTitle    = [];
      $columnMatch    = [];
      $columnId       = [];

      foreach($users as $userId => $userData){
        $columnTitle[]    = $userData['contactName'];
        $columnMatch[]    = $userId;
        $columnId[]       = enc($userId);
        $columnColors[]   = '#' . iftn($userData['contactColor'],'8599D9');
        $columnData[]   = 'data-type="calendarDateBtn" data-mode="calendar_resources" data-user="' . enc($userId) . '"';
      }

      if($trans){

        foreach($trans as $transId => $transData){
          $transData['transactionId'] = $transId;
          //customer data
          $cusData    = ncmExecute('SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',[$transData['customerId'],COMPANY_ID]);
          $cusName    = iftn($cusData['contactSecondName'],$cusData['contactName']);
          $cusPhone   = iftn($cusData['contactPhone'],$cusData['contactPhone2']);
          $cusEmail   = $cusData['contactEmail'];
          $cusContact = false;

          $cusFullPhone           = "<a href='tel:".$cusPhone."' class='clickeable text-u-l' data-type='tel' style='color:white;'>".$cusPhone."</a>";
          $cusFullEmail           = "<a href='mailto:".$cusEmail."' class='clickeable text-u-l' data-type='tel' style='color:white;'>".$cusEmail."</a>";

          if($cusPhone){
            $cusContact = $cusFullPhone;
          }else if($cusEmail){
            $cusContact = $cusFullEmail;
          }

          $cusContact   = ($cusContact) ? $cusContact : 'Sin contacto';

          $list       = '';
          $details    = json_decode($transData['transactionDetails'],true);
          if(counts($details) > 0){
            foreach($details as $index => $js){
              $list .= $js['name'] . '<br>';
            }
          }

          $hourS                      = date('H',strtotime($transData['fromDate']));
          $transData['date']          = $transData['userId'] . ' ' . $hourS;
          $transData['customerName']  = $cusName;
          $transData['customerPhone'] = '';//getPhoneFormat($cusPhone);
          $transData['customerId']    = $cusData['contactId'];

          //ICON
          $stsIcon    = [
                          ($transData['transactionStatus'] == 0) => 'stars',
                          ($transData['transactionStatus'] == 1) => 'thumb_up',
                          ($transData['transactionStatus'] == 2) => 'keyboard_arrow_down',
                          ($transData['transactionStatus'] == 3) => 'keyboard_arrow_right',
                          ( in_array($transData['transactionStatus'], [4,7])) => 'block',
                          ($transData['transactionStatus'] == 5) => 'person_add_disabled',
                          ($transData['transactionStatus'] == 6) => 'check'
                        ];

          $stsColor    = [
                          ($transData['transactionStatus'] == 0) => 'dark',
                          ($transData['transactionStatus'] == 1) => 'info',
                          ($transData['transactionStatus'] == 2) => 'warning',
                          ($transData['transactionStatus'] == 3) => 'success',
                          ( in_array($transData['transactionStatus'], [4,7])) => 'dark',
                          ($transData['transactionStatus'] == 5) => 'danger',
                          ($transData['transactionStatus'] == 6) => 'dark'
                        ];

          $statusIcons = switchVals($stsIcon);
          $statusColor = switchVals($stsColor);

          $disabled = '';
          if($transData['transactionStatus'] == 6){
            $disabled = 'disabled';
          }else if($transData['transactionStatus'] == 7){
            $disabled = 'blocked';
          }

          $transData['icon']    = '<i class="material-icons pull-right">' . $statusIcons . '</i>';
          $transData['color']   = $statusColor;

          $tipData  = [
                        "customerName"      => $cusName,
                        "customerContact"   => $cusContact,
                        "list"              => $list,
                        "startH"            => date('H',strtotime($transData['fromDate'])),
                        "startM"            => date('i',strtotime($transData['fromDate'])),
                        "textEnd"           => '',
                        "endH"              => date('H',strtotime($transData['toDate'])),
                        "endM"              => date('i',strtotime($transData['toDate'])),
                        "userName"          => $users[$transData['userId']]['contactName'],
                        "icon"              => $statusIcons,
                        "total"             => CURRENCY . formatCurrentNumber($transData['transactionTotal'],$compDecimal,$compThousand)
                      ];

          $transData['tooltip'] = 'data-toggle="tooltip" rel="tooltip" title="' . buildCalendarTooltip($tipData) . '"';
          $transData['status']  = $disabled;
          
          //ICON
          $calendarArray[] = $transData;
        }
      }

      $options['hasSessions']     = $transData['transactionParentId'];
      $options['startHour']       = $openFrom;
      $options['endHour']         = $openTo;
      $options['columnTitle']     = $columnTitle;
      $options['columnId']        = $columnId;
      $options['columnDate']      = $columnMatch;
      $options['columnColor']     = $columnColors;
      $options['maxCols']         = counts($users);
      
      $options['columnTitleWidth']= '135px';
      $options['columnData']      = $columnData;
      $options['transaction']     = $calendarArray;
      $options['groupData']       = ' data-toggle="tooltip" rel="tooltip" title="' . buildCalendarTooltip($tipData) . '" ';

      $table    = calendarBuilder($options);

      $table    =  '<table class="table scheduler table-bordered table-hover m-b-lg">' . $table . '</table>';

      $title    = buildCalendarTop(['date'=>$date,'current'=>'resource']);

      $wrapper  = '<div class="table-responsive panel no-padder m-b-lg" style="overflow-y:hidden;">' . $title . $table . '</div>';

      echo $wrapper;

      dai();

    }else{
      $out =  '<div class="col-xs-12 no-padder text-center m-b-sm m-t-md">' .
              ' <img src="/images/emptystate2.png" width="80" class="m-t-md">' .
              ' <div class="m-t-sm m-b h3 font-bold">No hay recursos habilitados</div>' .
              '</div>';
      dai($out);
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

  if($load == 'calendar_week'){
    $date       = iftn($get['date'],TODAY_DATE);

    $startWeek  = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $endWeek    = date('Y-m-d', strtotime('sunday this week', strtotime($date)));

    $startDate  = iftn($date, TODAY_START, $startWeek . ' 00:00:00');
    $endDate    = iftn($date, TODAY_END, $endWeek . ' 23:59:59');
    $openFrom   = $setting['settingOpenFrom'];//apertura negocio
    $openTo     = $setting['settingOpenTo'];//cierre negocio

    $sqlUsers   = "SELECT contactId, contactName, contactColor FROM contact WHERE type = 0 AND contactInCalendar = 1 AND (outletId < 1 OR outletId = ?) AND companyId = ?";
    $users      = $db->GetAssoc($sqlUsers, [OUTLET_ID,COMPANY_ID]);
    $inUs       = [];
    foreach($users as $id => $data){
      $inUs[] = $id;
    }

    $sqlDates   = "SELECT *
                  FROM transaction
                  WHERE transactionType = 13 
                  AND transactionStatus != 4
                  AND transactionStatus != 5
                  AND userId IN(" . implodes(',',$inUs) . ")
                  AND fromDate > ? 
                  AND toDate < ? 
                  AND outletId = ? 
                  AND companyId = ?
                  ORDER BY fromDate DESC";

    $trans          = $db->GetAssoc($sqlDates, [$startDate,$endDate,OUTLET_ID,COMPANY_ID]);

    if($trans){

      foreach($trans as $transId => $transData){
        $transData['transactionId'] = $transId;
        $cusData    = ncmExecute('SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',[$transData['customerId'],COMPANY_ID]);
        $cusName    = iftn($cusData['contactSecondName'],$cusData['contactName']);
        $cusPhone   = iftn($cusData['contactPhone'],$cusData['contactPhone2']);
        $cusEmail   = $cusData['contactEmail'];
        $cusContact = false;
        $cusFullPhone           = "<a href='tel:".$cusPhone."' class='clickeable text-u-l' data-type='tel' style='color:white;'>".$cusPhone."</a>";
        $cusFullEmail           = "<a href='mailto:".$cusEmail."' class='clickeable text-u-l' data-type='tel' style='color:white;'>".$cusEmail."</a>";

        if($cusPhone){
          $cusContact = $cusFullPhone;
        }else if($cusEmail){
          $cusContact = $cusFullEmail;
        }

        $cusContact   = ($cusContact) ? $cusContact : 'Sin contacto';

        $list       = '';
        $details    = json_decode($transData['transactionDetails'],true);
        if(counts($details) > 0){
          foreach($details as $index => $js){
            $list .= $js['name'] . '<br>';
          }
        }

        $transData['date']          = date('Y-m-d H',strtotime($transData['fromDate']));
        $transData['customerName']  = $cusName;

        //ICON
        $stsIcon    = [
                        ($transData['transactionStatus'] == 0) => 'stars',
                        ($transData['transactionStatus'] == 1) => 'thumb_up',
                        ($transData['transactionStatus'] == 2) => 'keyboard_arrow_down',
                        ($transData['transactionStatus'] == 3) => 'keyboard_arrow_right',
                        ( in_array($transData['transactionStatus'], [4,7])) => 'block',
                        ($transData['transactionStatus'] == 5) => 'person_add_disabled',
                        ($transData['transactionStatus'] == 6) => 'check'
                      ];

        $statusIcons = switchVals($stsIcon);
        $disabled = '';

        if($transData['transactionStatus'] == 6){
          $bgColor  = '#778490';
          $disabled = 'disabled';
        }else if($transData['transactionStatus'] == 7){
          $disabled = 'blocked';
        }

        $transData['icon']    = '<i class="material-icons pull-right">' . $statusIcons . '</i>';

        $tipData  = [
                      "customerName"      => $cusName,
                      "customerContact"   => $cusContact,
                      "list"              => $list,
                      "startH"            => date('H',strtotime($transData['fromDate'])),
                      "startM"            => date('i',strtotime($transData['fromDate'])),
                      "textEnd"           => '',
                      "endH"              => date('H',strtotime($transData['toDate'])),
                      "endM"              => date('i',strtotime($transData['toDate'])),
                      "userName"          => $users[$transData['userId']]['contactName'],
                      "icon"              => $statusIcons,
                      "total"             => CURRENCY . formatCurrentNumber($transData['transactionTotal'],$compDecimal,$compThousand)
                    ];

        $transData['tooltip'] = 'data-toggle="tooltip" rel="tooltip" title="' . buildCalendarTooltip($tipData) . '"';
        $transData['status']  = $disabled;

        $calendarArray[] = $transData;
      }
    }

    //build days of week
    $numOfDays  = 7;
    $day        = 1;
    $currentDay = $startWeek;
    $dayTitle   = [];
    $daySubTitle= [];
    $columnDate = [];
    $columnColors=[];
    $columnData = [];

    while($day <= $numOfDays){
      $dayTitle[]     = translateNamesOfWeek(date('l', strtotime($currentDay)));
      $daySubTitle[]  = niceDate($currentDay);
      $columnDate[]   = $currentDay;
      $columnColors[] = '#7DDFA2';
      $columnData[]   = 'data-type="calendarView" data-mode="calendar_resources" data-date="' . $currentDay . '"';

      $currentDay     = date('Y-m-d', strtotime($currentDay . ' +1 day'));
      $day++;
    }

    $options['startHour']       = $openFrom;
    $options['endHour']         = $openTo;
    $options['columnTitle']     = $dayTitle;
    $options['columnSubTitle']  = $daySubTitle;
    $options['columnDate']      = $columnDate;
    $options['columnColor']     = $columnColors;
    $options['columnTitleWidth']= '100px';
    $options['transaction']     = $calendarArray;
    $options['columnData']      = $columnData;
    $options['ignoreBlocked']   = true;

    $table    = calendarBuilder($options);

    $table    = '<table class="table scheduler table-bordered table-hover m-b-lg">' . $table . '</table>';
    $spacer   = '<div class="col-xs-12 wrapper-xl"></div>';
    $title    = buildCalendarTop(['date'=>$date,'title'=>niceDate($date),'current'=>'week']);

    $wrapper  = '<div class="table-responsive bg-light panel no-padder m-b-lg" style="overflow-y:hidden;">' .
                  $title .
                  $table .
                  $spacer .
                '</div>';

    echo $wrapper;

    dai();
  }

  if($load == 'calendar_agenda'){

    $date       = iftn($get['date'],TODAY_DATE);
    $startDate  = iftn($date, TODAY_START, $date . ' 00:00:00');
    $endDate    = iftn($date, TODAY_END, $date . ' 23:59:59');
    $openFrom   = $setting['settingOpenFrom'];//apertura negocio
    $openTo     = $setting['settingOpenTo'];//cierre negocio

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

    $table = '';

    $group = [];
    if($trans){
      
      while (!$trans->EOF) {
        $fields = $trans->fields;
        $day        = date('Y-m-d',strtotime($fields['fromDate']));
        $cusData    = ncmExecute('SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',[$fields['customerId'],COMPANY_ID]);
        $cusName    = iftn($cusData['contactSecondName'],$cusData['contactName']);

        $group[$day][] = [
                          'startHour'               => date('H:m',strtotime($fields['fromDate'])),
                          'customerName'            => $cusName,
                          'customerId'              => $fields['customerId'],
                          'transactionId'           => $fields['transactionId'],
                          'userId'                  => $fields['userId'],
                          'fromDate'                => $fields['fromDate'],
                          'toDate'                  => $fields['toDate'],
                          'transactionDetails'      => $fields['transactionDetails'],
                          'transactionTotal'        => $fields['transactionTotal'],
                          'transactionDiscount'     => $fields['transactionDiscount'],
                          'transactionPaymentType'  => $fields['transactionPaymentType'],
                          'transactionDate'         => $fields['transactionDate'],
                          'timestamp'               => $fields['timestamp'],
                          'transactionNote'         => $fields['transactionNote'],
                          'tags'                    => $fields['tags'],
                          'transactionName'         => $fields['transactionName'],
                          'invoiceNo'               => $fields['invoiceNo'],
                          'invoicePrefix'           => $fields['invoicePrefix'],
                          'transactionStatus'       => $fields['transactionStatus'],
                          'registerId'              => $fields['registerId'] 
                        ];

        $trans->MoveNext();
      }

      foreach($group as $date => $groupData){
        $table .=   '<tr>' .
                    ' <td colspan="2" class="font-bold h3 b-b b-light">' .
                        niceDate($date) .
                    ' </td>' .
                    '</tr>';

        foreach($groupData as $trans){
          if($trans['transactionStatus'] != 7){
            $table .=   '<tr class="pointer clickeable" ' . getSalesDataList($trans,13,'reprintSale',$cusData['contactId']) . '>' .
                        ' <td class="text-lg text-primary text-right b-b b-light">' .
                            $trans['startHour'] .
                        ' </td>' .
                        ' <td class="font-bold b-b b-light">' .
                            $trans['customerName'] .
                        '   <span class="hidden '.enc($trans['transactionId']).'">'.
                              $trans['transactionDetails'].''.
                        '   </span> '.
                        ' </td>' .
                        '</tr>';
          }
        }
        
      }
      
      $table    = '<table class="table m-b-lg">' . $table . '</table>';

    }else{
      $table    = '<tr><td>' .
                  ' <div class="col-xs-12 text-center no-padder m-b-sm">' .
                  '   <img src="/images/emptystate2.png" width="80" class="m-t-md">' .
                  '   <div class="m-t-sm m-b h3">Nada en la agenda</div>' .
                  ' </div>' .
                  '</td></tr>';
    }

    $head     = buildCalendarTop(['date'=>$date,'current'=>'agenda']);

    $wrapper  = '<div class="table-responsive bg-light panel no-padder m-b-lg" style="overflow-y:hidden;">' . 
                  $head . $table . 
                '</div>';

    dai($wrapper);
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

  if($load == 'orders' && $get['t']){
    $out    = '';
    $table  = $get['t'];
    $order  = ncmExecute('SELECT 
                              transactionId,
                              transactionDetails,
                              transactionDate
                            FROM transaction 
                            WHERE
                              outletId = ?
                            AND transactionType = 12
                            AND transactionName = ?',
                            [OUTLET_ID,$table], false, true
                          );

    //genero un array trsId => trsDetails de todas las ordenes de una mesa y les inserto al trsDetail la fecha de la orden
    $orderAsoc = [];
    if($order){
      while (!$order->EOF) {
        $orderF = $order->fields;
        $unJson = json_decode($orderF['transactionDetails'],true);
        for($i=0;$i<counts($unJson);$i++){
          $unJson[$i]['date'] = $orderF['transactionDate'];
        }
        $orderAsoc[$orderF['transactionId']] = json_encode($unJson);
        $order->MoveNext();
      }
    }

    $tableOp  = ncmExecute('SELECT 
                              *
                            FROM transaction 
                            WHERE
                              outletId = ?
                            AND transactionType = 11
                            AND transactionName = ?
                            LIMIT 1',
                            [OUTLET_ID,$table]
                          );
    
    $orders = groupOrdersItems($orderAsoc);

    if($get['json']){    
      $out = [];
      foreach($orders as $key => $val){
        foreach($val as $v){
          if(arrKey($v,'status',1) > 0){
            array_push($out, $v);  
          }
        }
      }
      $out    = json_encode($out);
      
    }else{
      if($orders){
        $allUsers     = getAllContacts(0);
        $allUsers     = $allUsers[1];
        $usrOpenName  = $allUsers[$tableOp['userId']]['name'];
        $running      = niceDate2($tableOp['transactionDate'], 'small');
        $title        = iftn($tableOp['transactionNote'],'Espacio ' . $table);

        $out .= '<div class="h1 font-bold">' . $title . '</div>' .
                '<div class="col-xs-12 text-sm text-center m-b">Por ' . $usrOpenName . ' hace ' . $running . '</div>' .
                '<div class="panel">' .
                ' <table class="table table-striped">' .
                '   <tbody>';
          $x = 0;

          foreach($orders as $trsId => $order){
            $isInCombo  = false;
            $isCombo    = false;

            foreach($order as $item){
              $strike = '';
              $hideX  = false;

              if($item['type'] == 'inCombo'){
                $isInCombo = true;
                $hideX     = true;
              }

              if($item['type'] == 'combo'){
                $isCombo = true;
              }

              if(arrKey($item,'status',1) < 1 || arrKey($item,'status',2) < 1){$strike = 'text-l-t text-muted'; $hideX = true;}

              $itemData = ncmExecute('SELECT itemName FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[dec($item['itemId']),COMPANY_ID]);

              $out .= '<tr class="text-right text-md '.$strike.' '.$item['itemId'].'-'.$x.'">' .
                      ' <td width="13%" class="font-bold">' .
                          $item['count'] .
                      ' </td>' .
                      ' <td width="57%" class="text-left font-bold">' .
                        toUTF8($itemData['itemName']) . ' ' . printOutTags($item['tags'],'bg-light') .
                      ' <div class="text-xs font-normal">' . $allUsers[dec($item['user'])]['name'] . ' hace ' . niceDate2($item['date'], 'small') . '</div>' . 
                      ' </td>' .
                      ' <td width="25%">';
                  
                  if(!$isInCombo){
                    $out .= formatCurrentNumber($item['price'],$dec,$ts);
                  }

              $out .= ' </td>' .
                      ' <td width="10%">';

                  if(!$hideX){
                    $out .= '<a href="#" class="clickeable hidden" data-type="chargeItemFromOrder" data-id="' . $item['itemId'] . '" data-position="' . $x . '" data-oid="' . enc($trsId) . '" data-table="' . $table . '"><i class="material-icons text-info">check</i></a>';

                    $out .= '<a href="#" class="clickeable" data-type="removeItemFromOrder" data-id="'.$item['itemId'].'" data-position="'.$x.'" data-oid="'.enc($trsId).'" data-table="'.$table.'"><i class="material-icons text-danger">close</i></a>';
                  }

              $out .= ' </td>' .
                      '</tr>';
              $x++;
            }
          }

        $out .= '   </tbody>' .
                ' </table>' .
                '</div>' .
                '<div class="text-center wrapper">' .
                ' <a href="#" class="clickeable btn btn-default btn-rounded font-bold text-u-c" data-type="printTable" data-position="'.$table.'">Imprimir Pre cuenta</a>' . 
                '</div>';
      }else{
        $out .= '<div class="h2 m-t">No posee órdenes</div> <a href="#" class="btn btn-info btn-lg btn-rounded m-t clickeable font-bold text-u-c" data-type="addOrder" data-position="'.$table.'">Agregar orden</a>';
      }
    }

    //dai($out);
    echo $out;
  }

  if($load == 'tablesJson'){
    $sql =      'SELECT 
                  transactionName,
                  transactionId,
                  transactionDate,
                  transactionStatus,
                  transactionNote,
                  transactionParentId,
                  userId
                FROM transaction 
                WHERE transactionType = 11
                AND transactionName > 0
                AND outletId = ? LIMIT 150';

    $result = ncmExecute($sql,[OUTLET_ID],false,true);
    $table  = [];
    if($result){
      $userData   = getContactData(USER_ID,false,true);
      $rolName    = getTheRolName($userData['rol']);

      while (!$result->EOF) {
        $fields     = $result->fields;
        $running    = niceDate2($fields['transactionDate'], 'small');
        $editable   = true;
        $cnt        = 6;

        //conteo de cantidad de ordenes inabilitado porque es muy lento
        //$orders     = ncmExecute('SELECT COUNT(transactionId) as count FROM transaction WHERE transactionType = 12 AND transactionStatus IN(0,1,2,3,5) AND transactionName = ? AND companyId = ? LIMIT 1', [$fields['transactionName'],COMPANY_ID]);
        
        if(USER_ID != $fields['userId'] && $rolName == 'Seller'){
          $editable   = false;
        }

        $table[$fields['transactionName']] = [
                                                'id'      => enc($fields['transactionId']),
                                                'no'      => $fields['transactionName'],
                                                'since'   => $running,
                                                'status'  => $fields['transactionStatus'],
                                                'note'    => $fields['transactionNote'],
                                                'userId'  => enc($fields['userId']),
                                                'orders'  => $cnt,
                                                'editable'=> $editable,
                                                'joined'  => intval( $fields['transactionParentId'] )
                                              ];

        $result->MoveNext();
      }
    }

    jsonDieMsg($table,200,'table');
  }

  if($load == 'ordersPanel'){

    $lastChk = '';

    if($get['lastChk']){
      $lasc     = $db->Prepare($get['lastChk']);
      //$lastChk  = " AND transactionDate > '" . $lasc . "' ";
    }

    $sql     =  ' SELECT *
                  FROM transaction 
                  WHERE transactionType = 12
                  AND transactionStatus IN(0,1,2,3,5)
                  AND outletId = ?
                  ' . $lastChk . '
                  AND companyId = ?
                  ORDER BY transactionDate DESC LIMIT 1000';

    $result   = ncmExecute($sql,[OUTLET_ID,COMPANY_ID],false,true);
    $orders   = [];

    if($result){

      while (!$result->EOF) {
        $fields     = $result->fields;

        //1. Nada (Gris) 2. Orden en espera (Amarillo) 3. Orden en Proceso (Azul) 4. Orden finalizada (Verde) 5. Orden Anulada (Rojo) 6. Otro (Negro)

        $running    = niceDate2($fields['transactionDate'], 'small');
        $source     = 'orden';
        $name       = $fields['transactionName'];
        $orderNo    = $fields['invoiceNo'];
        $customer   = iftn($fields['customerId'],'',enc($fields['customerId']));
        $customerName = '';
        $tags       = json_decode($fields['tags'],true);

        if($customer){
          $customerData = getContactData($fields['customerId'], 'uid');
          $customerName = getCustomerName($customerData);
          $addressIdr   = ncmExecute('SELECT customerAddressId FROM toAddress WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);
          $addressId    = $addressIdr ? enc($addressIdr['customerAddressId']) : false;

          if($addressId && enc($addressId) != $customerData['addressId']){
            $addressD = ncmExecute('SELECT * FROM customerAddress WHERE customerAddressId = ? AND companyId = ? LIMIT 1',[$addressId,COMPANY_ID]); 

            $address  = $addressD['customerAddressText'];
            $location = $addressD['customerAddressLocation'];
            $city     = $addressD['customerAddressCity'];
            $lat      = $addressD['customerAddressLat'];
            $lng      = $addressD['customerAddressLng'];

          }else{

            $address  = $customerData['address'];
            $location = $customerData['location'];
            $city     = $customerData['city'];
            $lat      = $customerData['lat'];
            $lng      = $customerData['lng'];

          }
        }
        
        switch ($fields['transactionStatus']) {
          case '2':
            $statusColor  = 'warning';
            $statusName   = 'En Espera';
            break;
          case '3':
            $statusColor  = 'info';
            $statusName   = 'En Proceso';
            break;
          case '4':
            $statusColor  = 'success';
            $statusName   = 'Finalizado';
            break;
          case '5':
            $statusColor  = 'dark';
            $statusName   = 'Enviado';
            break;
          case '6':
            $statusColor  = 'danger';
            $statusName   = 'Cancelado';
            break;
          default:
            $statusColor  = 'light';
            $statusName   = 'Pendiente';
            break;
        }

        if(is_numeric($name)){
          $source     = 'table';
        }else if($name == 'ecom'){
          $source     = 'ecom';
          $name       = '';  
        }

        /*if($fields['customerId'] && !$name){
          $source     = 'customer';
          $name    = '';
        }else if($name == 'external'){
          $source     = $name;
          $name    = '';  
        }else if($name == 'pickup'){
          $source     = $name;
          $name    = '';  
        }else if($name == 'takeout'){
          $source     = $name;
          $name    = '';  
        }else if($name == 'takein'){
          $source     = $name;
          $name    = '';  
        }else if($name == 'delivery'){
          $source     = $name;
          $name    = '';  
        }else if($name == 'monchis'){
          $source     = $name;
          $name    = '';  
        }else if($name == 'pedidosya'){
          $source     = $name;
          $name    = '';  
        }else if($name == 'web'){
          $source     = $name;
          $name    = '';  
        }else if($name == 'ecom'){
          $source     = 'ecom';
          $name    = '';  
        }*/

        $orders[] = [
                      'id'          => enc($fields['transactionId']),
                      'table'       => $name,
                      'orderNo'     => $orderNo,
                      'since'       => $running,
                      'source'      => $source,
                      'status'      => $fields['transactionStatus'],
                      'note'        => $fields['transactionNote'],
                      'userId'      => enc($fields['userId']),
                      'customerId'  => $customer,
                      'customerName'=> $customerName,
                      'statusColor' => $statusColor,
                      'statusName'  => $statusName,
                      'created'     => strtotime($fields['transactionDate']),
                      'tags'        => $tags,
                      'addressId'   => $addressId,
                      'address'     => $address,
                      'location'    => $location,
                      'city'        => $city,
                      'lat'         => $lat,
                      'lng'         => $lng
                    ];

        $result->MoveNext();
      }
    }

    //$orders['checkSum'] = crc32(json_encode($orders));

    jsonDieMsg($orders,200,'orders');
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
      $updated = json_decode(curlContents(API_ENCOM_URL . '/get_last_update.php','POST',$data),true);
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

      $result           = json_decode(curlContents(API_ENCOM_URL . '/get_orders.php','POST',$data),true);
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

  if($load == 'ordersList' && isset($get['t'])){
    $out          = [];

    if($get['kind'] == 'customer'){
      $table        = '';
      $customerId   = dec($get['t']);
      $order        = ncmExecute('  SELECT *
                                    FROM transaction 
                                    WHERE
                                      outletId = ?
                                    AND transactionType = 12
                                    AND transactionStatus IN(0,1,2,3,5)
                                    AND customerId = ?',
                                    [OUTLET_ID, $customerId], false, true
                                  );
      //

      $filterBy     = 'transactionStatus';
      $filterValues = [0,1,2,3,5];

      $out['orderId'] = $get['t'];
    }else if($get['kind'] == 'any'){
      $table        = '';
      $orderId      = dec($get['t']);
      $order        = ncmExecute('  SELECT *
                                    FROM transaction
                                    WHERE
                                      outletId = ?
                                    AND transactionId = ?',
                                    [OUTLET_ID,$orderId], false, true
                                  );
      $filterBy     = false;

      $out['orderId'] = $get['t'];
    }else{
      $table        = $get['t'];

      $order        = ncmExecute("SELECT 
                                  *
                                FROM transaction 
                                WHERE
                                  outletId = ?
                                AND transactionType = 12
                                AND transactionStatus IN(0,1,2,3,5)
                                AND transactionName = ?",
                                [ OUTLET_ID, $table ], false, true
                              );

      
      //

      $filterBy     = 'transactionStatus';
      $filterValues = [0,1,2,3,5];

      $tableOp      = ncmExecute('SELECT 
                                    *
                                  FROM transaction 
                                  WHERE outletId      = ?
                                  AND transactionType = 11
                                  AND transactionName = ?
                                  LIMIT 1',
                                  [ OUTLET_ID, $table ]
                                );
    }

    //genero un array trsId => trsDetails de todas las ordenes de una mesa y les inserto al trsDetail la fecha de la orden
    $orderAsoc  = [];
    $tags       = [];
    $ids        = [];
    $i          = 0;

    if($order){
      while (!$order->EOF) {

        $orderF     = $order->fields;

        if( in_array($orderF['transactionName'], [$table, 'ecom']) ){

          $orderNo  = $orderF['invoiceNo'];
          $unJson   = json_decode($orderF['transactionDetails'],true);
          $tags[]   = json_decode($orderF['tags'],true);
          $ids[]    = enc($orderF['transactionId']);

          foreach($unJson as $k => $value){
            $unJson[$k]['date'] = $orderF['transactionDate'];
          }

          $orderAsoc[$orderF['transactionId']]            = json_encode($unJson);
        }

        $order->MoveNext();

      }
    }
    
    $orders = groupOrdersItems($orderAsoc);

    if(isset($get['json'])){  //lista para cierre de mesa  
      $out = [];
      foreach($orders as $key => $val){
        foreach($val as $v){

          /*if(array_key_exists('status', $v)){
            if($v != 0 || $v != 2){
              array_push($out, $v);
            }
          }*/

          //comentar y probar antes de borrar
          if(arrKey($v,'status',1) == 1){
            if(validity($v)){
              array_push($out, $v);
            }
          }
          
        }
      }

      $ttags = array_flatten($tags);

      if($get['kind'] == 'table' || $get['kind'] == 'customer'){
        $preOut = [];

        foreach ($out as $k => $item) {
          $key = array_search($item['itemId'], array_column($preOut, 'itemId'));
          if($key > -1){

            if( in_array($item['type'], ['product','production','direct_production','dynamic']) && $preOut[$key]['type'] != 'inCombo' ){
              $preOut[$key]['count']    += $item['count'];
              $preOut[$key]['oQty']     += $item['oQty'];
              $preOut[$key]['total']    += $item['total'];    
            }else{
              array_push($preOut, $item);
            }
           
          }else{
            array_push($preOut, $item);
          }
        }

        $out = $preOut;
      }

      //$out['type'] = $get['kind'];
      $outs = ['list' => $out, 'tags' => $ttags, 'ids' => $ids];

      jsonDieResult($outs);
      
    }else{ //lista para mostrar orden
      if($orders){
        $allUsers     = getAllContacts(0);
        $allUsers     = $allUsers[1];
        $usrOpenName  = $allUsers[$tableOp['userId']]['name'];
        $running      = niceDate2($tableOp['transactionDate'], 'small');
        $customerData = isset($customerId) ? getContactData($customerId,'uid') : null;

        $title          = iftn($tableOp['transactionNote'],'Espacio ' . $table);
        $subTitle       = 'Por ' . $usrOpenName . ' hace ' . $running;
        $out['orderId'] = $table;

        if($get['kind'] == 'customer'){
          $title          = getCustomerName($customerData);
          $subTitle       = '';
          $out['orderId'] = enc($customerId);
        }else if($get['kind'] == 'any'){
          $title          = 'Orden #' . $orderNo;
          $subTitle       = $tableOp['transactionNote'];
          $out['orderId'] = $out['orderId'];
        }

        $out['title']       = $title;
        $out['subTitle']    = $subTitle;
        
        $i = 0;
        foreach($orders as $trsId => $order){
          $isInCombo  = false;
          $isCombo    = false;

          //$out['data'][] = '';
          $oi = 0;
          foreach($order as $item){
            $strike       = '';
            $hideX        = '';

            if($item['type'] == 'inCombo'){
              $isInCombo  = true;
              $hideX      = 'hidden';
            }

            if($item['type'] == 'combo'){
              $isCombo    = true;
            }

            //if(arrKey($item,'status',1) < 1){
            if(array_key_exists('status', $item)){
              $strike = ($item['status'] == 2) ? 'text-muted' : 'text-l-t text-muted'; 
              $hideX  = 'hidden';
            }
            //Calculamos el total del rawPrice
            $rawPrice = $item['price'] * $item['count'];

            if($strike){
              $rawPrice = 0;
            }

            $itemData = ncmExecute('SELECT itemName FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[dec($item['itemId']),COMPANY_ID]);

            $out['data'][] =  [
                                'index'     => $i,
                                'oindex'    => $oi,
                                'strike'    => $strike,
                                'hideX'     => $hideX,
                                'itemId'    => $item['itemId'],
                                'count'     => $item['count'],
                                'note'      => iftn($item['note']),
                                'unitPrice' => formatCurrentNumber($item['price'],$dec,$ts),
                                'price'     => formatCurrentNumber($rawPrice,$dec,$ts),
                                'rawPrice'  => $rawPrice,
                                'name'      => toUTF8($itemData['itemName']),
                                'tagsList'  => iftn(printOutTags($item['tags'],'bg-light'),''),
                                'userName'  => $allUsers[dec($item['user'])]['name'],
                                'date'      => niceDate2($item['date'], 'small'),
                                'type'      => $item['type'],
                                'orderNo'   => $orderNo,
                                'transactionId' => enc($trsId)
                              ];
            $i++;
            $oi++;
          }
        }
      }
    }

    $out['type'] = $get['kind'];

    jsonDieMsg($out,200,'list');
  }

  if($load == 'customerHasOrders' && $get['id']){
    if(is_numeric($get['id'])){
      $id             = $get['id'];
    }else{
      $id             = dec($get['id']);  
    }
    
    $order        = ncmExecute('  SELECT transactionId
                                  FROM transaction 
                                  WHERE companyId = ?
                                  AND outletId = ?
                                  AND transactionType = 12
                                  AND transactionStatus != 4
                                  AND customerId = ? 
                                  ORDER BY transactionId DESC
                                  LIMIT 1',
                                  [COMPANY_ID, OUTLET_ID,$id]);
    if($order){
      jsonDieMsg('true',200,'true');
    }else{
      jsonDieMsg();
    }
  }

  if($load == 'printServer'){
    $out          = [];
    $result       = ncmExecute('  SELECT *
                                  FROM printServer 
                                  WHERE outletId = ?
                                  AND companyId = ?
                                  LIMIT 100',
                                  [OUTLET_ID,COMPANY_ID], false, true
                              );

    if($result){

      while (!$result->EOF) {
        $field  = $result->fields;

        $out[]  =   [
                      'ID' => enc($field['transactionId'])
                    ];

        $result->MoveNext();
      }

    }

    jsonDieResult($out);
    
  }

  if($load == 'singleTransaction' && $get['id']){
    $out          = [];
    $transId      = dec($get['id'] . '');
    $fields       = ncmExecute('  SELECT *
                                  FROM transaction 
                                  WHERE
                                    transactionId = ?
                                  AND companyId   = ?
                                  LIMIT 1',
                                  [$transId, COMPANY_ID]
                              );

    if(!$fields){
      jsonDieMsg(['error' => 'not found'], 404, 'list');
    }

    $tags           = json_decode($fields['tags'], true);
    $tags           = @implodes(',', $tags);
    $tags           = ($tags) ? $tags : '';

    $discount       = $fields['transactionDiscount'];
    $total          = $fields['transactionTotal'] - $discount;

    $paymentType    = json_decode($fields['transactionPaymentType'],true);
    $paymentTypes   = [];
    $payed          = 0;

    if($paymentType){

      foreach ($paymentType as $key => $value) {
        $paymentTypes[] = [
                            'amount'  => $value['price'], 
                            'name'    => getPaymentMethodName($value['type']),
                            'type'    => $value['type'],
                            'extra'   => $value['extra'],
                            'UID'        => $value['UID'] ?? ''
                          ];
      }

    }

    if($fields['transactionType'] == '3'){
      $payedData  = getAllTransactionPayments($transId,100);
      $payed      = ncmExecute('SELECT SUM(ABS(transactionTotal)) as payed FROM transaction WHERE transactionType IN(5,6) AND transactionParentId = ? AND companyId = ?',[$transId,COMPANY_ID]);
      $payed      = $payed['payed'];
    }

    $address      = false;
    if($fields['transactionType'] == '12'){
      $address    = getCustomerTransactionAddress($fields['transactionId'],true);
    }

    if($fields['transactionType'] == '13'){
      
    }

    $startDate  = false;
    $startH     = false;
    $endH       = false;

    if($fields['fromDate'] && $fields['toDate']){
      $startDate = $fields['fromDate'];
      list($startDate,$startH,$endH) = dateStartEndTime($fields['fromDate'],$fields['toDate']);
    }

    $isSession  = ( ($fields['transactionParentId']) ? enc($fields['transactionParentId']) : false );
    $parentID   = ( ($fields['transactionParentId']) ? enc($fields['transactionParentId']) : false );

    $transactionDatas = json_decode( $fields['transactionDetails'], true );

    // if($transactionDatas){

    //   foreach ($transactionDatas as $key => $value) {
    //     $transactionDatas[$key]['price'] = divider($value['price'], $value['count'], true);
    //   }

    // }

    $out =  [
              'transactionId'     => enc($fields['transactionId']),
              'customerId'        => enc($fields['customerId']),
              'customerUnd'       => $fields['customerId'],
              'userId'            => enc($fields['userId']),
              'note'              => ($fields['transactionNote']),
              'tags'              => $tags,
              'documentNo'        => $fields['invoiceNo'],
              'invoicePrefix'     => $fields['invoicePrefix'] ? $fields['invoicePrefix'] : '',
              'name'              => $fields['transactionName'],
              'type'              => $fields['transactionType'],
              'status'            => $fields['transactionStatus'],
              'date'              => $fields['transactionDate'],
              'dueDate'           => $fields['transactionDueDate'],
              'startDate'         => $startDate,
              'endDate'           => $fields['toDate'],
              'startHour'         => $startH,
              'endHour'           => $endH,
              'hasSession'        => $isSession,
              'isSession'         => $isSession,
              'parentID'          => $parentID,
              'UID'               => $fields['timestamp'],
              'pMethods'          => $paymentTypes,//@implodes('|',$paymentTypes),
              'toPay'             => ($total - $payed),
              'total'             => number_format($total, 2, '.', ''),
              'discount'          => number_format($discount, 2, '.', ''),
              'payedData'         => !empty($payedData) ? $payedData[$transId] : [],
              'transactionData'   => $fields['transactionDetails'],
              'transactionDatas'  => $transactionDatas,
              'address'           => $address
            ];

    jsonDieMsg($out,200,'data');
  }

  if($load == 'loadDrawerList'){

      $jsonOut  = [];
      $drwr     = ncmExecute("SELECT drawerOpenDate, drawerOpenAmount 
                            FROM drawer 
                            WHERE registerId = ?
                            AND outletId = ?
                            AND companyId = ?
                            AND (drawerCloseDate IS NULL OR drawerCloseDate < '2000-01-01 01:00:00') 
                            ORDER BY drawerOpenDate DESC
                            LIMIT 1",[REGISTER_ID,OUTLET_ID,COMPANY_ID]);

      if($drwr){

        if(isset($get['chk']) && $get['chk']){
          jsonDieMsg('true',200,'success');
        }

        $exp            = ncmExecute("SELECT SUM(expensesAmount) as expense FROM expenses WHERE expensesDate > ? AND type IS NULL AND registerId = ?",[$drwr['drawerOpenDate'],REGISTER_ID]);
        //$inc            = ncmExecute("SELECT SUM(expensesAmount) as expense FROM expenses WHERE expensesDate > ? AND type = 1 AND registerId = ?",[$drwr['drawerOpenDate'],REGISTER_ID]);
        $inc            = ncmExecute("SELECT expensesAmount, expensesDescription FROM expenses WHERE expensesDate > ? AND type = 1 AND registerId = ?",[$drwr['drawerOpenDate'],REGISTER_ID],false,true);

        $totalIncome    = 0;
        $totalTips      = 0;
        if($inc){
          while (!$inc->EOF) {
            $fields = $inc->fields;

            if($fields['expensesDescription'] == 'PROPINA'){
              $totalTips += $fields['expensesAmount'];
            }

            $totalIncome += $fields['expensesAmount'];
            $inc->MoveNext();
          }
          $inc->Close();
        }
        
        $incomeAmount   = $totalIncome; //$inc['expense'] ? $inc['expense'] : 0;
        $expenseAmount  = $exp['expense'] ? $exp['expense'] : 0;
        $cajaInicial    = $drwr['drawerOpenAmount'] ? $drwr['drawerOpenAmount'] : 0;
        $cashPrice      = 0;
        $total          = 0;
        $return         = 0;

        $detailArray    = getSalesByPayment($drwr['drawerOpenDate'],false,REGISTER_ID);

        $jsonOut['list'][] = [ 'name' => 'Caja Inicial', 'amount' => $cajaInicial ];

        if(validity($detailArray,'array')){
          foreach ($detailArray as $arr){

            $name     = str_replace('u00e9','é',$arr['name']);
            $type     = $arr['type'];
            $price    = (float)$arr['price'];

            if($type == 'cash'){
              $cashPrice  = $price;
            }

            if($type == 'return'){
              $return += $price;
            }else{
              $total += $price;
              $jsonOut['list'][] = [ 'name' => $name, 'amount' => $price ];
            }

          }
        }

        $rtotal               = ($cajaInicial + $total + $incomeAmount) - $expenseAmount - $return;
        $rtotalCash           = ($cajaInicial + $cashPrice + $incomeAmount) - $expenseAmount;

        $jsonOut['list'][]    = [ 'name' => 'Extracciones (Efectivo)', 'amount' => $expenseAmount ];
        $jsonOut['list'][]    = [ 'name' => 'Ingresos (Efectivo)', 'amount' => $incomeAmount ];
        $jsonOut['date']      = $drwr['drawerOpenDate'];
        $jsonOut['subtotal']  = $rtotalCash;
        $jsonOut['total']     = $rtotal;
        $jsonOut['tips']      = $totalTips;
        $jsonOut['returns']   = -$return;

        jsonDieMsg($jsonOut,200,'data');
        
      }else{
        jsonDieMsg('Closed',200,'closed');
      }
  }

  if($load == 'customerRecord' && $get['id']){

    $record   = ncmExecute('SELECT * FROM customerRecord WHERE companyId = ? ORDER BY customerRecordSort ASC LIMIT 10000',[COMPANY_ID],false,true);
    $cId      = db_prepare($get['id']);

    $customer = getContactData(dec($cId),'uid');
    $name     = getCustomerName($customer);    
    ?>
    <a href="#" class="thumb-md"> 
      <img src="/assets/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle"> 
    </a>

    <div class="text-center m-t">Fichas de</div>
    <div class="h2 font-bold"><?=$name?></div>
    <div class="text-center m-b"><?=niceDate(TODAY,false,false,false,true);?></div>

    <section class="col-xs-12 text-left wrapper b no-bg m-b r-3x">
      <span class="col-xs-12 no-padder text-dark font-bold text-u-c m-b">Datos personales</span>

      <?php if($customer['ci']){?>
      <div class="col-xs-4 m-b">
        <div class="font-bold">Documento Nro.</div>
        <?=$customer['ci'];?>
      </div>
      <?php }?>

      <?php if($customer['gender']){?>
      <div class="col-xs-4 m-b">
        <div class="font-bold">Sexo</div>
        <?=$customer['gender'];?>
      </div>
      <?php }?>

      <?php if($customer['age']){?>
      <div class="col-xs-4 m-b">
        <div class="font-bold">Edad</div>
        <?=$customer['age'];?>
      </div>
      <?php }?>

      <?php if($customer['bDay']){?>
      <div class="col-xs-4 m-b">
        <div class="font-bold">Fecha de Nacimiento</div>
        <?=$customer['bDay'];?>
      </div>
      <?php }?>

      <?php if($customer['phone']){?>
      <div class="col-xs-4 m-b">
        <div class="font-bold">Teléfono</div>
        <?=$customer['phone'];?>
      </div>
      <?php }?>

      <?php if($customer['email']){?>
      <div class="col-xs-4 m-b">
        <div class="font-bold">Email</div>
        <?=$customer['email'];?>
      </div>
      <?php }?>

      <?php if($customer['countryName']){?>
      <div class="col-xs-4 m-b">
        <div class="font-bold">País</div>
        <?=$customer['countryName'];?>
      </div>
      <?php }?>

      <?php if($customer['city']){?>
      <div class="col-xs-4 m-b">
        <div class="font-bold">Ciudad</div>
        <?=$customer['city'];?>
      </div>
      <?php }?>

      <?php if($customer['location']){?>
      <div class="col-xs-4 m-b">
        <div class="font-bold">Localidad</div>
        <?=$customer['location'];?>
      </div>
      <?php }?>

      <?php if($customer['address']){?>
      <div class="col-xs-6 m-b">
        <div class="font-bold">Dirección</div>
        <?=$customer['address'];?>
      </div>
      <?php }?>
    </section>
    <?php
    if($record){
      $customerId = dec($get['id']);
      while (!$record->EOF) {
        $name       = $record->fields['customerRecordName'];
        $id         = enc($record->fields['customerRecordId']);
    ?>
      <section class="col-xs-12 no-padder text-left b no-bg m-b r-3x pagebreak">
        <div class="col-xs-12 no-padder hidden-print"><!--head-->
          <span id="name<?=$id?>" class="text-dark font-bold text-u-c pull-left wrapper"><?=$name?></span>
          <a href="#" data-type="toggleView" data-target="#collapse<?=$id?>" class="wrapper clickeable pull-right">
            <i class="material-icons">keyboard_arrow_down</i>
          </a>
        </div>
        <div class="col-xs-12 no-padder" id="collapse<?=$id?>" style="display:none;"><!--body-->
          <div class="col-xs-12 wrapper font-bold text-u-c visible-print"><?=$name?></div>
          <div class="col-xs-12 no-padder" id="options<?=$id?>">
            
                <?php
                $field  = ncmExecute('SELECT * FROM cRecordField WHERE customerRecordId = ? ORDER BY cRecordFieldSort ASC',[dec($id)],false,true);

                $repFrom  = ['<!--','-->'];
                $repTo    = ['',''];

                if($field){
                  while (!$field->EOF) {
                    $fName      = $field->fields['cRecordFieldName'];
                    $fType      = $field->fields['cRecordFieldType'];
                    $fProgress  = $field->fields['cRecordFieldProgress'];
                    $fId        = enc($field->fields['cRecordFieldId']);
                    $value      = getValue('cRecordValue', 'cRecordValueName', 'WHERE cRecordFieldId = ' . $field->fields['cRecordFieldId'] . ' AND customerId = ' . dec($cId) . ' ORDER BY cRecordValueDate DESC LIMIT 1','string');

                    //$value      = ncmExecute('SELECT cRecordValueName FROM cRecordValue WHERE cRecordFieldId = ? AND customerId = ? ORDER BY cRecordValueDate DESC LIMIT 1',[$field->fields['cRecordFieldId'], dec($cId)]);
                    //$value      = $value['name'];

                    $progressIcon = ($fProgress) ? '<span class="material-icons text-info">show_chart</span>' : '';

                    $value = html_entity_decode( str_replace($repFrom, $repTo, $value) );
                ?>

                      <?php
                      if($fType == 0){//texto corto
                        ?>
                        <div class="col-xs-12 col-sm-6 wrapper hidden-print">
                          <div class="col-sm-5 col-xs-12 m-t-sm no-padder font-bold">
                            <?=$progressIcon . ' ' . $fName?>
                          </div>
                          <div class="col-sm-7 col-xs-12 hidden-print">
                            <input type="text" class="form-control no-bg no-border b-b customerRecordValue" name="" value="<?=$value?>" id="<?=$fId?>">
                          </div>
                        </div>

                        <div class="col-xs-4 m-b-sm visible-print">
                          <div class="col-xs-12 no-padder font-bold">
                            <?=$fName?>
                          </div>
                          <?=$value?>
                        </div>
                        <?php
                      }else if($fType == 1){//texto largo
                      ?>
                        
                        <div class="col-xs-12 wrapper text-left">
                          <div class="col-xs-4 no-padder font-bold">
                            <?=$progressIcon . ' ' . $fName?>
                          </div>
                          <div class="col-xs-8 text-right hidden-print">
                            <div class="btn-group">
                              <a href="#" class="clickeable btn hidden" data-type="wysiwyg" data-role="heading" data-tag="h4"><i class="material-icons">title</i></a>
                              <a href="#" class="clickeable btn hidden" data-type="wysiwyg" data-role="insertUnorderedList"><i class="material-icons">list</i></a>
                              <a href="#" class="clickeable btn" data-type="wysiwyg" data-role="bold"><i class="material-icons">format_bold</i></a>
                              <a href="#" class="clickeable btn" data-type="wysiwyg" data-role="italic"><i class="material-icons">format_italic</i></a>
                              <a href="#" class="clickeable btn" data-type="wysiwyg" data-role="underline"><i class="material-icons">format_underlined</i></a>
                            </div>
                          </div>
                          <div class="b-b col-xs-12 wrapper customerRecordValue contenteditable" id="<?=$fId?>" contenteditable>
                            <?=markupt2HTML(['text' => $value, 'type' => 'MtH'])?>
                          </div>
                        </div>
                        
                      <?php
                      }else if($fType == 2){//numero
                      ?>
                        <div class="col-xs-12 col-sm-6 wrapper hidden-print">
                          <div class="col-sm-7 col-xs-12 m-t-sm no-padder font-bold">
                            <?=$progressIcon . ' ' . $fName?>
                          </div>
                          <div class="col-sm-5 col-xs-12 hidden-print">
                            <input type="tel" pattern="\d*" class="form-control no-bg no-border b-b customerRecordValue text-right" name="" value="<?=$value?>" id="<?=$fId?>" autocomplete="off">
                          </div>
                        </div>

                        <div class="col-xs-4 m-b-sm visible-print">
                          <div class="col-xs-12 no-padder font-bold">
                            <?=$fName?>
                          </div>
                          <?=$value?>
                        </div>
                      <?php
                      }else if($fType == 3){ //switch
                      ?>
                        <div class="col-xs-12 col-sm-6 wrapper hidden-print">
                          <div class="col-sm-8 col-xs-12 m-t-sm no-padder font-bold">
                            <?=$progressIcon . ' ' . $fName?>
                          </div>
                          <div class="col-sm-4 col-xs-12 text-right hidden-print">
                            <?=switchIn($fId,(($value>0)?true:false),'customerRecordValue')?>
                          </div>
                        </div>

                        <div class="col-xs-4 m-b-sm visible-print">
                          <div class="col-xs-12 no-padder font-bold">
                            <?=$fName?>
                          </div>
                          <?=(($value > 0) ? '✓' : '')?>
                        </div>
                      <?php
                      }else if($fType == 4){//date
                      ?>
                        <div class="col-xs-12 col-sm-6 wrapper hidden-print">
                          <div class="col-sm-6 col-xs-12 m-t-sm no-padder font-bold">
                            <?=$progressIcon . ' ' . $fName?>
                          </div>
                          <div class="col-sm-6 col-xs-12 hidden-print">
                            <div class="bg-light no-bg">
                              <input type="text" id="<?=$fId?>" class="form-control no-bg datePicker pointer customerRecordValue no-border b-b b-light">
                            </div>
                          </div>
                        </div>

                        <div class="col-xs-4 m-b-sm visible-print">
                          <div class="col-xs-12 no-padder font-bold">
                            <?=$fName?>
                          </div>
                          <?=niceDate($value,false,false,true,true);?>
                        </div>

                      <?php
                      }else if($fType == 5){//imagen
                      ?>
                        <div class="col-xs-12 wrapper">
                          
                          <div class="col-xs-12 m-t-sm no-padder font-bold">
                            <?=$progressIcon . ' ' . $fName?>
                          </div>
                          <?php
                          $fileClass = 'ncmFile' . rand(2,20);
                          ?>
                          <div class="col-xs-12 wrapper r-3x <?=$fileClass;?>"></div>
                          <script type="text/javascript">
                            var opts = {
                                        "loadEl" : '.<?=$fileClass;?>',
                                        "listEl" : '.<?=$fileClass;?>',
                                        "token"  : ncmGlobals.settings[0].dropbox,
                                        "folder" : '/customer/<?=$cId;?>/records/<?=$fId;?>'
                                      };

                            if(ncmGlobals.settings[0].dropbox){
                              ncmDropbox(opts);
                            }
                          </script>

                        </div>
                      <?php
                      }else if($fType == 6){//titulo
                      ?>
                        <div class="col-xs-12 wrapper">
                          
                          <div class="col-xs-12 h4 m-t-sm no-padder font-bold">
                            <?=$fName?>
                          </div>

                        </div>
                      <?php
                      }
                      ?>

                      <input type="hidden" id="progress<?=$fId?>" value="<?=$fProgress?>">

                <?php
                    $field->MoveNext(); 
                  }
                }

                ?>
          </div>
        </div>
      </section>
    <?php
        $record->MoveNext();
      }
      ?>
      <div class="col-xs-12 no-padder m-t hidden-print">
        <a href="#" class="m-t pull-left clickeable" data-type="printPage">Imprimir</a>
        <a href="#" class="btn btn-info btn-rounded btn-lg text-u-c font-bold clickeable pull-right" data-type="modifyCustomerRecord" data-id="<?=$cId?>">Guardar</a>
      </div>
      <?php
    }else{
      ?>
      <div class="text-center col-xs-12 wrapper noDataMessage">
        <img src="/assets/images/emptystate7.png" height="120">
        <h2 class="font-bold">No ha creado fichas</h2>
        <div class="text-muted m-t">
          <p>Puede crear fichas personalizadas en la sección Contactos del Panel de Control</p>
        </div>
      </div>
      <?php
    }

    dai();
  }

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

  if($load == 'customerProgress' && $get['id']){
    $customerId   = dec($get['id']);
    $customerName = $get['name'];
    $records      = $db->Execute('SELECT 
                                      a.customerRecordName as rName,
                                      b.cRecordFieldType as type, 
                                      b.cRecordFieldName as name, 
                                      c.cRecordValueName as value, 
                                      c.cRecordValueDate as date, 
                                      c.cRecordValueId as valueId 
                                FROM 
                                      customerRecord a, 
                                      cRecordField b, 
                                      cRecordValue c 
                                WHERE 
                                      a.companyId = ?
                                  AND 
                                      b.customerRecordId = a.customerRecordId 
                                  AND 
                                      b.cRecordFieldProgress = 1 
                                  AND 
                                      c.cRecordFieldId = b.cRecordFieldId
                                  AND
                                      c.customerId = ?
                                  ORDER BY c.cRecordValueDate DESC',
                                  array(COMPANY_ID,$customerId));
    if(validateResultFromDB($records)){
      echo '<div class="col-xs-12 wrapper"> <div class="text-center text-muted">Progresos de</div> <div class="col-xs-12 h1 font-bold text-center m-b-lg">'.$customerName.'</div>';
      echo '<div class="timeline">';   
      $num_rows = 0;

      while (!$records->EOF){
        $value    = $records->fields['value'];
        $date     = $records->fields['date'];
        $valueId  = $records->fields['valueId'];
        $type     = $records->fields['type'];
        $name     = $records->fields['name'];
        $rName    = $records->fields['rName'];

        $even           = "";
        $roww           = "left";
        $toolPlacement  = 'right';
        $iconColor      = 'lter';
        if($num_rows % 2 == 0){
            $even           = "alt";
            $roww           = "right";
            $toolPlacement  = 'left';
            $iconColor      = 'dk';
        }
        $num_rows++;

        if($type == 0){//texto corto
          $valueOut = '<span class="h4">'.$value.'</span>';
          $icon     = 'chat_bubble_outline';
        }else if($type == 1){//text largo
          $valueOut = '<p>'.$value.'</p>';
          $icon     = 'chat_bubble_outline';
        }else if($type == 2){//numero
          $valueOut = '<div class="font-bold h3">'.$value.'</div>';
          $icon     = 'timeline';
        }else if($type == 3){//check
          if($value == 1){
            $valueOut = '<i class="material-icons text-success md-36">check</i>'; 
          }else{
            $valueOut = '<i class="material-icons text-danger md-36">close</i>'; 
          }
          $icon     = 'thumbs_up_down';
        }else if($type == 4){//fecha
          $valueOut = '<div class="font-thin h4">'.niceDate($value).'</div>';
          $icon     = '&#xe916;';
        }
        ?>
        <article class="timeline-item <?=$even?>"> 
          <div class="timeline-caption"> 
            <div class="panel panel-default no-border"> 
              <div class="panel-body all-shadows no-border r-3x"> 
                <span class="arrow <?=$roww?>"></span> 
                <span class="timeline-icon">
                  <i class="material-icons time-icon bg-primary <?=$iconColor?>">
                    <?=$icon?>
                  </i>
                </span> 
                <a href="#" class="timeline-date" data-toggle="tooltip" data-original-title="<?=$date?>" data-placement="<?=$toolPlacement?>">
                  <?=niceDate2($date)?>
                </a> 
                <div class="text-sm">
                  <span class="font-bold text-muted">
                    <?=$name?> 
                  </span>
                  <span class="pull-right label bg-light">
                    <?=$rName?>
                  </span>
                </div> 
                <?=$valueOut?>
              </div> 
            </div> 
          </div> 
        </article>
        <?php
        $records->MoveNext(); 
      }

      echo '<div class="timeline-footer"><a href="#"><i class="material-icons time-icon inline-block bg-dark lter">power_settings_new</i></a></div>';
      echo '</div> </div>';
    }else{
      ?>
      <div class="text-center col-xs-12 wrapper noDataMessage">
        <img src="/images/emptystate2.png" height="140">
        <h1 class="font-thin">No posee progresos</h1>
        <div class="text-muted m-t">
          <p>Puede crear fichas de clientes en la sección Contactos del Panel de Control y luego podrá asignar progresos</p>
        </div>
      </div>
      <?php
    }

    dai();
  }

  if($load == 'customerInfo'){

    if(is_numeric($get['id'])){
      $id             = $get['id'];
    }else{
      $id             = dec($get['id']);  
    }

    $jsonOut          = [];
    $purchasedItems   = [];
    $giftCards        = [];
    $notes            = [];

    $customer         = ncmExecute('SELECT 
                                      *
                                    FROM
                                      contact 
                                    WHERE type      = 1
                                    AND contactId  = ?
                                    AND companyId   = ?
                                    LIMIT 1',
                                    [$id,COMPANY_ID]);


    if($customer){

        $qtotal     = ncmExecute('SELECT 
                                      COUNT(transactionId) as sales, 
                                      SUM(transactionTotal) as total, 
                                      STRING_AGG(transactionId::text, \',\') as ids, 
                                      SUM(transactionUnitsSold) as units,
                                      transactionDate as date
                                    FROM 
                                      transaction 
                                    WHERE 
                                      customerId = ?
                                    ORDER BY date DESC', [$id]);

        if($qtotal){

          //$qdate      = $db->Execute('SELECT transactionDate as date FROM transaction WHERE customerId = ? ORDER BY transactionDate DESC LIMIT 1', array($id));

          $qitemsD    = ncmExecute("SELECT  
                                        a.itemId as id, 
                                        a.itemSoldUnits as usold,
                                        a.itemSoldDate as date,
                                        a.userId as user
                                      FROM
                                        itemSold a, transaction b 
                                      WHERE
                                        b.transactionType = '0'
                                      AND
                                        b.customerId = ?
                                      AND
                                        a.transactionId = b.transactionId 
                                     ORDER BY a.itemSoldDate DESC LIMIT 5",[$id],false,true);

          $allUsers         = getAllContacts(0);
          $row              = '';
          $lastPurchaseDate = '';
          $x                = 0;

          if($qitemsD){

            while (!$qitemsD->EOF) {
              $qIFields = $qitemsD->fields;

              if($x < 1){
                $lastPurchaseDate = $qIFields['date'];
              }

              $itItemName = getItemName($qIFields['id']);
              $itUserName = getTheContactField($qIFields['user'],$allUsers);

              $row .= '<tr>'.
                      ' <td>' . toUTF8($itItemName) . '</td>'.
                      ' <td>' . toUTF8($itUserName) . '</td>'.
                      ' <td class="text-right"><span class="label bg-light dker">' . $qIFields['usold'] . '</span></td>'.
                      '</tr>';

              $purchasedItems[] = [
                                    'itemName'  => toUTF8($itItemName),
                                    'userName'  => toUTF8($itUserName),
                                    'itemQty'   => $qIFields['usold']
                                  ];
                      
              $x++;
              $qitemsD->MoveNext(); 
            }

            $qitemsD->Close();
          }



          //Cuenta corriente

          $totalC  = ncmExecute(' SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount, STRING_AGG(transactionId::text, \',\') as ids 
                                  FROM transaction 
                                  WHERE customerId = ? 
                                  AND transactionType = 3
                                  AND transactionComplete = 0
                                  ', [$id]
                                );

          $totalComprado  = 0;
          $totalPagado    = 0;

          if(validity($totalC,'array')){
            $totalRetrns  = ncmExecute(' SELECT SUM(ABS(transactionTotal)) as total 
                                          FROM transaction 
                                          WHERE customerId = ? 
                                          AND transactionType = 6
                                          AND transactionParentId IN(' . $totalC["ids"] . ')
                                          ', [$id]
                                        );

            $payedC       = ncmExecute(' SELECT SUM(transactionTotal) as payed 
                                          FROM transaction 
                                          WHERE transactionType = 5
                                          AND transactionParentId IN(' . $totalC["ids"] . ')
                                          AND customerId = ?', [$id]
                                        );

            $totalComprado  = $totalC['total'] - $totalC['discount'];
            $totalPagado    = $payedC['payed'] + abs( $totalRetrns['total'] ?? 0 );

            if($totalPagado >= $totalComprado){
              $totalDeuda     = 0;
              $debtList       = '';
            }else{
              $totalDeuda     = $totalComprado - $totalPagado;
              $debtList       = json_encode( getDebtListByTransaction($id) );
            }

          }

          //vencidas
          $totalV  = ncmExecute(" SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount, STRING_AGG(transactionId::text, ',') as ids 
                                  FROM transaction 
                                  WHERE customerId = ? 
                                  AND transactionDueDate <= '" . TODAY . "'
                                  AND transactionType = 3
                                  AND transactionComplete = 0
                                  ", [$id]);
          $totalCompradoV     = 0;
          $totalPagadoV       = 0;

          if(validity($totalV,'array') && validity($totalV["ids"])){
            $totalRetrnsV  = ncmExecute(" SELECT SUM(transactionTotal) as total 
                                    FROM transaction 
                                    WHERE customerId = ? 
                                    AND transactionType = 6
                                    AND transactionParentId IN(" . $totalV["ids"] . ")
                                    ", [$id]
                                  );

            $payedV  = ncmExecute(' SELECT SUM(transactionTotal) as payed 
                                    FROM transaction 
                                    WHERE transactionType = 5
                                    AND transactionParentId IN(' . $totalV["ids"] . ')
                                    AND customerId = ?', [$id]);

            $totalCompradoV     = $totalV['total'] - $totalV['discount'];
            $totalPagadoV       = $payedV['payed'] + abs($totalRetrns['total']);
          }

          if($totalPagadoV >= $totalCompradoV){
            $totalDeudaV     = 0;
            $debtListV       = '';
          }else{
            $totalDeudaV     = $totalCompradoV - $totalPagadoV;  
            $debtListV       = json_encode(getDebtListByTransaction($id,true));
          }

          $creditLine       = $customer['contactCreditLine'];

          $total    = $qtotal['total'];
          $sales    = $qtotal['sales'];
          $average  = 0;
          
          if($total > 0 && $sales > 0){
            $average  = $total/$sales;
          }
          
          $units    = $qtotal['units'];

          $getGiftCards = ncmExecute('SELECT * FROM giftCardSold WHERE giftCardSoldBeneficiaryId = ? AND companyId = ?',[$id,COMPANY_ID],false,true);

          if($getGiftCards){
            while (!$getGiftCards->EOF) {
              $gFields = $getGiftCards->fields;
              $code = iftn($gFields['giftCardSoldCode'],$gFields['timestamp']);

              $giftCards[] = [
                              'giftCardCode'  => $code,
                              'giftCardUID'   => $gFields['timestamp'],
                              'giftCardTotal' => formatCurrentNumber($gFields['giftCardSoldValue'])
                            ];
                      
              $x++;
              $getGiftCards->MoveNext(); 
            }
          }
          
        }

        $address  = $customer['contactAddress'];
        $latLng   = $customer['contactLatLng'];

        //customer addresses
        $custAddresses  = ncmExecute('SELECT * FROM customerAddress WHERE companyId = ? AND customerAddressDefault = 1 AND customerId = ? LIMIT 1',[COMPANY_ID, $customer['contactId']]);

        if(!empty($_GET['debug'])){
          print_r(['direcciones' => $custAddresses]);
        }

        if($custAddresses){
          $address  = $custAddresses['customerAddressText'];
          if($custAddresses['customerAddressLat'] && $custAddresses['customerAddressLng']){
            $latLng   = $custAddresses['customerAddressLat'] . ',' . $custAddresses['customerAddressLng'];
          }
        }else if($address){
          ncmInsert([
                      'table'   => 'customerAddress', 
                      'records' => [
                                      'customerAddressText'     => $address, 
                                      'customerAddressDefault'  => 1,
                                      'customerAddressDate'     => TODAY,
                                      'companyId'               => COMPANY_ID, 
                                      'customerId'              => $customer['contactId']
                                    ] 
                    ]);
        }

        $jsonOut = [
                      'customerId'          => enc($customer['contactId']),
                      'customerName'        => toUTF8($customer['contactName']),
                      'customerFullName'    => $customer['contactSecondName'],
                      'customerTIN'         => $customer['contactTIN'],
                      'customerCI'          => $customer['contactCI'],
                      'customerPhone1'      => $customer['contactPhone'],
                      'customerPhone2'      => $customer['contactPhone2'],
                      'customerEmail'       => $customer['contactEmail'],
                      'customerAddress1'    => $address,
                      'customerAddress2'    => $customer['contactAddress2'],
                      'customerNote'        => $customer['contactNote'],
                      'customerMemberSince' => niceDate($customer['contactDate']),
                      'customerBDay'        => niceDate($customer['contactBirthDay']),
                      'latLng'              => $latLng,
                      'lastPurchase'        => niceDate($lastPurchaseDate),

                      'totalDebt'           => formatCurrentNumber($totalDeuda,$dec,$ts),
                      'totalDebtRaw'        => $totalDeuda,
                      'totalDebtData'       => $debtList,
                      'expiredDebt'         => formatCurrentNumber($totalDeudaV,$dec,$ts),
                      'expiredDebtRaw'      => $totalDeudaV,
                      'expiredDebtData'     => $debtListV,
                      'loyalty'             => formatCurrentNumber($customer['contactLoyaltyAmount'],$dec,$ts),
                      'inCredit'            => formatCurrentNumber($customer['contactStoreCredit'],$dec,$ts),
                      'creditLine'          => formatCurrentNumber($creditLine,$dec,$ts),
                      'latestPurchasedItems'=> $purchasedItems,
                      'giftCards'           => $giftCards,
                      'notes'               => $notes                      

                    ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($jsonOut);
    
  }

  if($load == 'itemInfo' && $get['i']){
    $id         = dec($get['i']);
    $userOutlet = iftn($get['o'],'',dec($get['o']));
    $jsonOut    = [];
    $item       = ncmExecute('SELECT 
                                *
                              FROM
                                item 
                              WHERE itemId    = ?
                              AND companyId   = ?
                              LIMIT 1',
                              [$id,COMPANY_ID]);
    if($item){
      $eItemId      = enc($item['itemId']);
      $eCompanyId   = enc(COMPANY_ID);
      $categoryName = iftn(getTaxonomyName($item['categoryId'],true),'Sin categoría');
      $brandName    = iftn(getTaxonomyName($item['brandId'],true),'Sin marca');
      $taxName      = iftn(getTaxonomyName($item['taxId'],true),'0');
      $typeName     = getItemTypeName($item);
      $inventory    = [];
      
      

      if($item['itemTrackInventory']){

        /*if($userOutlet){
          $outlet = ncmExecute("SELECT * FROM outlet WHERE outletStatus = 1 AND outletId = ? AND companyId = ? LIMIT 1",[$userOutlet,COMPANY_ID],false,true);
        }else{*/
          $outlet = ncmExecute("SELECT * FROM outlet WHERE outletStatus = 1 AND companyId = ? LIMIT 100",[COMPANY_ID],false,true);
        //}

        if($outlet){
          while (!$outlet->EOF) {
            $deposits     = [];
            $oCount       = 0;
            $mCount       = 0;

            $oStock       = getItemStock($item['itemId'],$outlet->fields['outletId']);
            $oCount       = $oStock['stockOnHand'];
            $mCount       = $oCount;

            $depo         = ncmExecute('SELECT * FROM taxonomy WHERE taxonomyType = "location" AND outletId = ? ORDER BY taxonomyName ASC',[$outlet->fields['outletId']],false,true);

            if($depo){
              $dTotal     = 0;
              while (!$depo->EOF) {
                $dCount   = 0;
                $depCount = ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',[$depo->fields['taxonomyId'],$item['itemId']]);

                if($depCount){
                  $dCount = $depCount['toLocationCount'];
                }

                $dTotal += $dCount;

                $deposits[]   = [
                                'depositName' => $depo->fields['taxonomyName'],
                                'qty'         => formatQty($dCount)
                                ];

                $mCount  = $mCount - $dTotal;

                $depo->MoveNext();
              }

            }

            $deposits[]   = [
                                'depositName' => 'Principal',
                                'qty'         => formatQty($mCount)
                                ];


            $inventory['outlets'][]  = [
                                          'outletName'  => $outlet->fields['outletName'],
                                          'deposits'    => $deposits,
                                          'total'       => formatQty($oCount)
                                        ];

            $outlet->MoveNext();
          }
        }
        $outlet->Close();
      }

      $jsonOut    = [
                    'id'          => $eItemId,
                    'name'        => $item['itemName'],
                    'img'         => '/assets/250-250/0/' . $eCompanyId . '_' . $eItemId . '.jpg?' . mt_rand(),
                    'price'       => CURRENCY . ' ' . formatCurrentNumber($item['itemPrice'],$dec,$ts),
                    'sku'         => iftn($item['itemSKU'],'Sin SKU'),
                    'type'        => $typeName,
                    'description' => $item['itemDescription'],
                    'tax'         => $taxName,
                    'category'    => $categoryName,
                    'outlet'      => iftn($item['outletId'],'Todas',getCurrentOutletName($item['outletId'])),
                    'brand'       => $brandName,
                    'duration'    => iftn($item['itemDuration'],''),
                    'sessions'    => iftn($item['itemSessions'],''),
                    'inventory'   => $inventory
                    ];
    }

    header('Content-Type: application/json');
    echo json_encode($jsonOut);
  }

  if($load == 'walink' && $get['id']){
    $link         = '#notfound';
    $url          = '/screens/scheduleConfirm?s=' . base64_encode($get['ti'] . ',' . enc(COMPANY_ID));
    $phone        = ncmExecute('SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',[dec($get['id']),COMPANY_ID]);

    if($phone){
      $cellphone    = iftn($phone['contactPhone'],$phone['contactPhone2']);
      $name         = iftn($phone['contactSecondName'],$phone['contactName']);
      $name         = getContactName($name,'first');
      $url          = getShortURL($url);

      $intCellphone = getValidPhone($cellphone);
      $intCellphone = str_replace('+','',$intCellphone['phone']);

      $text         = ('Hola ' . $name . ', le contactamos desde ' . COMPANY_NAME . ' para recordarle su agendamiento. Puede ver los detalles y confirmar o cancelar su asistencia en: ' . $url);

      if($intCellphone){
        $link         = 'https://wa.me/' . $intCellphone . '?text=' . $text;  
      }

      dai($link);
    }else{
      dai('false');
    }
  }

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

        $order           = json_decode(curlContents(API_ENCOM_URL . '/get_orders.php','POST',$orderD),true);

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

  if($load == 'transactions'){
      /*
        Tipos de Transacciones 
        0 = Venta al contado 
        1 = Compra al contado
        2 = Guardada 
        3 = Venta a crédito
        4 = Compra a crédito
        5 = Pago de ventas a crédito
        6 = Devolución
        7 = Venta anulada
        8 = Venta recursiva
        9 = cotizacion
        10 = Delivery
        11 = open table
        12 = order
        13 = schedule
        */

      $cuid = 0;
      // if(!empty($get['customerId']) && is_numeric($get['customerId'])){
      //   $cuid      = $get['customerId'];
      // }else if(!empty($get['customerId'])){
      //   $cuid      = dec($get['customerId']);
      // }
      if(!empty($get['customerId']) ){
        $cuid      = dec($get['customerId']);
      }
      $ecuid        = enc($cuid);

      $limit          = !empty($get['limit']) ? $get['limit'] : 30;
      $datePicked     = '';
      $userLookUp     = '';
      $jsonOut        = [];
      if(validity($get['date'])){
        $dtPckd       = $db->Prepare($get['date']);
        $datePicked   = " AND transactionDate BETWEEN '" . $dtPckd . " 00:00:00' AND '" . $dtPckd . " 23:59:59' ";
        $limit        = 2000;
      }
      
      //no uso IN() porque OR es más rápido segun stackoverflow
      $inT    = ' AND (transactionType = 0 OR transactionType = 3 OR transactionType = 6 OR transactionType = 7 OR transactionType = 9 OR transactionType = 10) ';

      if(in_array($roleId, ['5','4'])){
        $inT            = '2,10';
        $inT            = ' AND (transactionType = 2 OR transactionType = 10) ';
        $userLookUp     = ' AND userId = ' . $userId;
      }

      if($cuid){
        $customerLookUp = ' customerId IN (' . $cuid . ') AND companyId = ' . COMPANY_ID;
      }else{
        $customerLookUp = ' outletId = ' . OUTLET_ID;
      }

      
      $table =  ' <div class="col-sm-8 wrapper">' .
                '   <div class="h1 font-bold text-left text-dark">Transacciones</div>' . 
                ' </div>' .
                ' <div class="col-sm-4 wrapper" id="saleDatePickerParent">' .
                '   <input type="form" value="' . $get['date'] . '" class="form-control datePicker pointer no-border no-bg b-b text-center" readonly>' .
                ' </div>' .
                ' <div class="col-xs-12 m-b-n-lg hidden" id="transactionListInput">' .
                '   <input type="search" class="form-control bg-light lter no-border rounded input-lg datatableFilter" placeholder="Buscar..." value="" autocomplete="off">' .
                ' </div>' .
                ' <table class="table table-hover" id="salesPrimaryTable"> ' . 
                '   <thead><tr><td>&nbsp;</td><td>&nbsp;</td></tr></thead>' .
                '   <tbody>';

      $sql = 'SELECT 
                  *
              FROM transaction 
              WHERE ' .
              $customerLookUp .
              $userLookUp .
              $inT .
              $datePicked . 
              ' ORDER BY transactionDate 
              DESC LIMIT ' . $limit;

      $result = ncmExecute($sql,[],false,true);

      $jsonOut['date']              = $get['date'];
      $jsonOut['listName']          = 'Transacciones';
      $jsonOut['transactionsList']  = [];

      if($result){

        $whereCustomer  = [];
        $whereTrsId     = [];

        while (!$result->EOF) {//primer loop para generar arrasy para consultas extras
          if($result->fields['customerId']){
            $whereCustomer[]  = $result->fields['customerId'];
          }
          $whereTrsId[]     = $result->fields['transactionId'];

          $result->MoveNext();
        }
        $result->MoveFirst();

        $allToPayTransactions = getAllToPayTransactions(' AND transactionParentId IN ('.implodes(',',$whereTrsId).')');
        $allPayedTransactions = getAllTransactionPayments(implodes(',',$whereTrsId));
        
        while (!$result->EOF){
          $fields   = $result->fields;
          $tTotal   = $fields['transactionTotal'] - $fields['transactionDiscount'];
          $topay    = 0;
          $type     = $fields['transactionType'];
          $status   = $fields['transactionStatus'];
          $isPackage= false;

          //1. Nada (Gris) 2. Orden en espera (Amarillo) 3. Orden en Proceso (Azul) 4. Orden finalizada (Verde) 5. Orden Anulada (Rojo) 6. Otro (Negro)
          $stat = 'b-5x b-l ';
          switch ($status) {
            case '0':
                $caseOColor = 'b-light';
                $stat .= $caseOColor;
                break;
            case '1':
                $stat .= 'b-light';
                break;
            case '2':
                $stat .= 'b-info';
                break;
            case '3':
                $stat .= 'b-primary';
                break;
            case '4':
                $stat .= 'b-success';
                break;
            case '5':
                $stat .= 'b-danger';
                break;
            case '6':
                $stat = 'b-3x b-l b-dark';
                break;
          }

        $typeAttr   = 'reprintSale';
        if ($type == '2' || $type == '9') {
          $typeAttr = 'unsaveSale';
          $topay    = 0;
        } else if ($type == '3') {
          $typeAttr   = 'payCredit';
          $topay      = $tTotal - ($allToPayTransactions[$fields['transactionId']] ?? 0);
        }

          if($type == '0' || $type == '3'){
            $typeText   = 'text-muted';
          }else if($type == '2'){
            $typeText   = 'text-muted';
          }
          
          if($type == '3'){
            if($topay > ($tTotal/2)){
              $typeText   = 'bg-light text-danger ';
            }
            if($topay <= ($tTotal/2)){
              $typeText   = 'bg-light text-warning-dker';
            }
            if($topay < 1){
              $typeText   = 'bg-light text-success';
            }

            $typeOfSale = '<span class="label '.$typeText.' text-xs">Crédito</span>';
          }else if($type == '2'){
            $typeOfSale = '<span class="label text-dark b b-light text-xs">Guardado</span>';
          }else if($type == '6'){
            $typeOfSale = '<span class="label bg-danger lter text-xs">Devolución</span>';
          }else if($type == '7'){
            $typeOfSale = '<span class="label bg-dark text-xs">Anulado</span>';
          }else if($type == '9'){
            $typeOfSale = '<span class="label bg-warning dk text-xs">Cotización</span>';
          }else if($type == '10'){
            $typeOfSale = '<span class="label text-info b b-light text-xs">Envío</span>';
          }else if($type == '11'){
            $typeOfSale = '<span class="label bg-success text-xs">Orden</span>';
          }else if($type == '13'){
           
            $typeOfSale = '<span class="label bg-primary text-xs">Agenda</span>';
            
            if($fields['transactionParentId'] > 0 && $fields['invoicePrefix']){
              $isPackage  = true;
            }

            switch ($status) {
              case '0':
                $caseOColor = 'b-light';
                
                if($fields['transactionComplete'] == 1){
                  $caseOColor = 'b-primary';
                }
                
                $stat .= $caseOColor;
                break;
              case '1':
                $stat .= 'b-info';
                break;
              case '2':
                $stat .= 'b-warning';
                break;
              case '3':
                $stat .= 'b-success';
                break;
              case '4':
                $stat .= 'b-danger';
                break;
              case '5':
                $stat .= 'b-dark';
                break;
              case '6':
                $stat = ''; //realizado, ya sea session o no
                break;
            }

          }else{
            $typeOfSale = '<span class="label bg-light text-xs">Contado</span>';
          }

          $name     = '';
          if($fields['transactionName'] != 'Sale' && $fields['transactionName'] != 'Quote' && $fields['transactionName']){
            $name   = '<span class="text-info">'.$fields['transactionName'].'</span>';
          }

          $discount   = $fields['transactionDiscount'];
          $total      = $fields['transactionTotal'];

          $cusData = ncmExecute('SELECT contactName,contactSecondName,contactId FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',[$fields['customerId'],COMPANY_ID]);

          if(!$cusData){
            $customerD = 'Sin Nombre';
          }else{
            $customerD = $cusData['contactName'] ? toUTF8($cusData['contactName']) : toUTF8($cusData['contactSecondName']);
          }

          $jsonDetails = $fields['transactionDetails'];
          $jsonPayed   = !empty($allPayedTransactions[$fields['transactionId']]) ? json_encode($allPayedTransactions[$fields['transactionId']]) : '';

          if($jsonPayed){
            $jsonPayed =  ' <span class="hidden payed' . enc($fields['transactionId']) . '"> '.
                              $jsonPayed .
                          '  </span> ';
          }

          $rawDate = $fields['transactionDate'];
          $inTotal = formatCurrentNumber($total - $discount,$dec,$ts);
          $date    = niceDate($rawDate,true);

          $table .=   '<tr '.
                        'class="clickeable text-left" ' .
                        'data-sort="' . $rawDate . '"' .
                        (!empty($cusData['contactId']) ? getSalesDataList($fields,$type,$typeAttr,$cusData['contactId'],$topay) : "") .
                        '> '.
                        '<td class="'.$stat.'"> ' .
                        '  <span class="block text-ellipsis font-bold text-md">' . $name . ' ' . $customerD . '</span> '.
                        '  <small class="text-muted text-xs">' . $date .
                        '   #' . $fields['invoicePrefix'] . $fields['invoiceNo'] . '<span class="font-thin text-success count"></span>' .
                        '  </small> ' .

                        '  <span class="hidden '.enc($fields['transactionId']).'">' .
                              $jsonDetails .
                        '  </span> ' .
                          $jsonPayed .
                        '</td> '.
                        '<td class="text-right text-dark text-md">' . $inTotal . '<br>' . $typeOfSale . '</td> '.
                      '</tr>';

          $jsonOut['transactionsList'][] = [
                                              'id'        => enc($fields['transactionId']),
                                              'title'     => $name . ' ' . $customerD,
                                              'date'      => $date, 
                                              'docNumber' => ' #' . $fields['invoicePrefix'] . $fields['invoiceNo'],
                                              'amount'    => $inTotal,
                                              'label'     => $typeOfSale,
                                              'type'      => $fields['transactionType'],
                                              'borderColor' => $stat
                                            ];

          $result->MoveNext();
        }
            
        $result->Close();

        if(!$get['date']){
          $foot =   '<tfoot> <tr>' . 
                  ' <td colspan="2" class="text-center wrapper">' .
                  '   <span class="btn btn-info btn-lg btn-rounded all-shadows clickeable hide-basic font-bold text-u-c" data-more="'.($limit+50).'" data-type="openTransactions" data-customer-id="' . $ecuid . '">Cargar más</span>' .
                  ' </td>' .
                  '</tr> </tfoot>';

                  $jsonOut['footBtn'] = '<a href="#transactions&l=' . ($limit + 50) . '&c=' . $ecuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</a>';
        }else{
          $foot =   '<tfoot> <tr>' . 
                  ' <td colspan="2" class="text-center wrapper">' .
                  '   <span class="btn btn-info btn-lg btn-rounded all-shadows clickeable hide-basic font-bold text-u-c" data-type="openTransactions" data-customer-id="'.$ecuid.'">Atrás</span>' .
                  ' </td>' .
                  '</tr> </tfoot>';

                  $jsonOut['footBtn'] = '<a href="#transactions&c=' . $ecuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</a>';
        }
      }else{
        $foot =   '<tfoot> <tr>' . 
                  ' <td colspan="2" class="text-center wrapper">' .
                  '   <span class="btn btn-info btn-lg btn-rounded all-shadows clickeable hide-basic font-bold text-u-c" data-type="openTransactions" data-customer-id="'.$ecuid.'">Atrás</span>' .
                  ' </td>' .
                  '</tr> </tfoot>';

                  $jsonOut['footBtn'] = '<a href="#transactions&c=' . $ecuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</a>';
      }

      $table .= '</tbody> ' . $foot . ' </table>';

      if($get['json']){
        header('Content-Type: application/json');
        echo json_encode($jsonOut);
      }else{
        echo $table;
      }
  }

  if($load == 'sessionsList' && $get['customerId']){
    $date = '';

    if($get['date']){
      $dDate  = $db->Prepare($get['date']);
      $date   = ' AND a.fromDate > ' . $dDate . ' 00:00:00 AND a.toDate < ' . $dDate . ' 23:59:59 ';
    }

    $sql = 'SELECT a.transactionId, a.invoicePrefix, a.invoiceNo, b.itemSoldId, b.itemSoldDate, c.itemName, c.itemPrice
            FROM transaction a, itemSold b, item c
            WHERE a.customerId = ?
            ' . $date . '
            AND a.companyId = ?
            AND a.transactionId = b.transactionId
            AND b.itemId = c.itemId
            AND c.itemSessions > 0
            ORDER BY b.itemSoldDate DESC';

    if(isset($_GET['test'])){
      echo $sql . ' ' . dec($get['customerId']) . ' ' . COMPANY_ID;
      dai();
    }

    $result = ncmExecute($sql,[dec($get['customerId']),COMPANY_ID],false,true);

    $jsonOut                      = [];
    $jsonOut['date']              = $get['date'];
    $jsonOut['transactionsList']  = [];

    if($result){
      $cusData                      = getContactData(dec($get['customerId']),'uid');
      $jsonOut['listName']          = 'Sesiones de ' . toUTF8(iftn($cusData['secondName'],$cusData['name']));

      while (!$result->EOF) {
        $fields     = $result->fields;
        $date       = niceDate($fields['itemSoldDate']);


        //obtengo sesiones
        $sessions   = ncmExecute('SELECT * FROM transaction WHERE transactionType = 13 AND customerId = ? AND companyId = ? AND packageId = ? LIMIT 50',[dec($get['customerId']),COMPANY_ID,$fields['itemSoldId']],false,true);

        $sessionsList = [];

        if($sessions){
          while (!$sessions->EOF) {
            $sFields = $sessions->fields;
            list($dateS,$startH,$endH) = dateStartEndTime($sFields['fromDate'],$sFields['toDate']);

            $sessionsList[enc($fields['itemSoldId'])][] = [
                                "id"            => enc($sFields['transactionId']),
                                "status"        => $sFields['transactionStatus'],
                                "startH"        => counts($startH) > 2 ? $startH : '',
                                "endH"          => counts($endH) > 2 ? $endH : '',
                                "date"          => $dateS,
                                "userId"        => enc($sFields['userId']),
                                "customerId"    => enc($sFields['customerId']),
                                "session"       => $sFields['invoiceNo'],
                                "prefix"        => $sFields['invoicePrefix']
                              ];

            $sessions->MoveNext();
          }
        }

        $jsonOut['transactionsList'][] = [
                                              'id'        => enc($fields['transactionId']),
                                              'title'     => $fields['itemName'],
                                              'date'      => $date, 
                                              'docNumber' => ' #' . $fields['invoicePrefix'] . $fields['invoiceNo'],
                                              'amount'    => formatCurrentNumber($fields['itemPrice'],$dec,$ts),
                                              'label'     => '<span class="label bg-primary text-xs">Sesiones</span>',
                                              'type'      => 13,
                                              'dataList'  => json_encode($sessionsList[enc($fields['itemSoldId'])])
                                            ];

        $result->MoveNext();
      }
            
      $result->Close();
    }

    if(isset($_GET['debug'])){
      print_r($jsonOut);
      die();
    }

    header('Content-Type: application/json');
    echo json_encode($jsonOut);
  }

  if($load == 'agendaList'){
  
      $cuid           = $get['customerId'] ?? "";
      $limit          = $get['limit'] ?? 30;
      $date           = '';
      $jsonOut        = [];
      $orderBy        = ' ORDER BY fromDate DESC';

      if(validity($get['date'])){
        $date         = " AND fromDate BETWEEN '" . $get['date'] . " 00:00:00' AND '" . $get['date'] . " 23:59:59'";
        $limit        = 1500;
      }else{
        //$date         = " AND fromDate BETWEEN '" . TODAY_START . "' AND '" . TODAY_END . "'";
      }

      if($cuid){
        $customer = ' customerId IN (' . dec($cuid) . ')';
      }else{
        $customer = ' outletId = ' . OUTLET_ID;
      }
      
      $table =  ' <div class="col-sm-8 wrapper">' .
                '   <div class="h1 font-bold text-left text-dark">Agenda</div>' . 
                ' </div>' .
                ' <div class="col-sm-4 wrapper" id="saleDatePickerParent">' .
                ' </div>' .
                ' <div class="col-xs-12 m-b-n-lg hidden" id="transactionListInput">' .
                '   <input type="search" class="form-control bg-light lter no-border rounded input-lg datatableFilter" placeholder="Buscar..." value="" autocomplete="off">' .
                ' </div>' .
                ' <table class="table table-hover" id="salesPrimaryTable"> ' . 
                '   <thead><tr><td>&nbsp;</td><td>&nbsp;</td></tr></thead>' .
                '   <tbody>';

      $sql = 'SELECT 
                  *
              FROM transaction 
              WHERE
              ' .
              $customer .
              ' AND transactionType = 13' .
              $date .
              ' AND (fromDate IS NOT NULL AND toDate IS NOT NULL)' .
              ' AND companyId = ' . COMPANY_ID .
              ' AND transactionStatus != 7' .
              $orderBy .  
              ' LIMIT ' . $limit;

      $result = ncmExecute($sql,[],false,true);

      $jsonOut['date']              = $get['date'];
      $jsonOut['listName']          = 'Agenda';
      $jsonOut['transactionsList']  = [];

      if($result){

        while (!$result->EOF){
          $fields     = $result->fields;
          $status     = $fields['transactionStatus'];
          $discount   = $fields['transactionDiscount'];
          $total      = $fields['transactionTotal'];
          $cusData    = getCustomerData($fields['customerId'],'uid');
          $cusName    = iftn(getCustomerName($cusData),'Sin Nombre');
          $typeOfSale = '<span class="label bg-primary text-xs">Agenda</span>';
          $name       = '';
          $icon       = '';
          $jsonDetails = json_decode($fields['transactionDetails'],true);

          if(!empty($_GET['debug'])){
            print_r($jsonDetails);
            //die();
          }

          for($i=0;$i<counts($jsonDetails);$i++){
            //$jsonDetails[$i]['user'] = enc($fields['userId']);
          }
          
          $stat       = 'b-5x b-l ';

          list($scDate,$scStart,$scEnd) = dateStartEndTime($fields['fromDate'],$fields['toDate']);
          $timeFrame  = $scStart . ' - ' . $scEnd;

          //1. Nada (Gris) 2. Orden en espera (Amarillo) 3. Orden en Proceso (Azul) 4. Orden finalizada (Verde) 5. Orden Anulada (Rojo) 6. Otro (Negro)
          
          switch ($status) {
            case '0':
              $caseOColor = 'b-light';
              
              if($fields['transactionComplete'] == 1){
                $caseOColor = 'b-primary';
              }
              
              $stat .= $caseOColor;
              break;
            case '1':
              $stat .= 'b-info';
              break;
            case '2':
              $stat .= 'b-warning';
              break;
            case '3':
              $stat .= 'b-success';
              break;
            case '4':
              $stat .= 'b-danger';
              break;
            case '5':
              $stat .= 'b-dark';
              break;
            case '6':
              $stat = ''; //realizado, ya sea session o no
              $icon = '<i class="material-icons text-success m-r">check</i>';
              break;
          }
          
          if($fields['transactionName'] != 'Sale' && $fields['transactionName'] != 'Quote' && $fields['transactionName']){
            $name   = '<span class="text-info">' . $fields['transactionName'] . '</span>';
          }

          $table .=   '<tr '.
                      'class="clickeable text-left" ' .
                      getSalesDataList($fields,$fields['transactionType'],'reprintSale',$cusData['uid']) .
                      '> '.
                      ' <td class="'.$stat.'"> ' .
                      '   <span class="block text-ellipsis font-bold text-md">' . $name . ' ' . $cusName . '</span> '.
                      '   <small class="text-muted text-xs">' . niceDate($fields['fromDate']) .
                      '     #' . $fields['invoicePrefix'] . $fields['invoiceNo'] . '<span class="font-thin text-success count"></span>' .
                      '   </small> ' .
                      '   <span class="hidden '.enc($fields['transactionId']).'">' .
                            json_encode($jsonDetails) .
                      '   </span> ' .
                      ' </td> '.
                      ' <td class="text-right text-dark text-md">' . 
                          $icon . $timeFrame . '<br>' . $typeOfSale . 
                      ' </td> '.
                      '</tr>';

          $jsonOut['transactionsList'][] = [
                                              'id'            => enc($fields['transactionId']),
                                              'title'         => $name . ' ' . $cusName,
                                              'date'          => niceDate($fields['fromDate']), 
                                              'docNumber'     => ' #' . $fields['invoicePrefix'] . $fields['invoiceNo'],
                                              'amount'        => $icon . $timeFrame,
                                              'label'         => $typeOfSale,
                                              'type'          => $fields['transactionType'],
                                              'borderColor'   => $stat,
                                              'details'       => json_decode($fields['transactionDetails'],true)
                                            ];

          $result->MoveNext();
        }
            
        $result->Close();

        if(!$get['date']){
          $foot =   '<tfoot> <tr>' . 
                  ' <td colspan="2" class="text-center wrapper">' .
                  '   <span class="btn btn-info btn-lg btn-rounded all-shadows clickeable hide-basic font-bold text-u-c" data-more="'.($limit+50).'" data-type="openTransactions" data-customer-id="'.$cuid.'">Cargar más</span>' .
                  ' </td>' .
                  '</tr> </tfoot>';

          $jsonOut['footBtn'] = '<a href="#openAgenda&l=' . ($limit + 50) . '&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</span>';
        }else{
          $foot =   '<tfoot> <tr>' . 
                  ' <td colspan="2" class="text-center wrapper">' .
                  '   <span class="btn btn-info btn-lg btn-rounded all-shadows clickeable hide-basic font-bold text-u-c" data-type="openTransactions" data-customer-id="'.$cuid.'">Atrás</span>' .
                  ' </td>' .
                  '</tr> </tfoot>';

          $jsonOut['footBtn'] = '<a href="#openAgenda&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
        }
      }else{
        $foot =   '<tfoot> <tr>' . 
                  ' <td colspan="2" class="text-center wrapper">' .
                  '   <span class="btn btn-info btn-lg btn-rounded all-shadows clickeable hide-basic font-bold text-u-c" data-type="openTransactions" data-customer-id="'.$cuid.'">Atrás</span>' .
                  ' </td>' .
                  '</tr> </tfoot>';

        $jsonOut['footBtn'] = '<a href="#openAgenda&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
      }

      $table .= '</tbody> ' . $foot . ' </table>';

      if($get['json']){
        header('Content-Type: application/json');
        echo json_encode($jsonOut);
      }else{
        echo $table;
      }
  }

  if($load == 'ordersList'){
  
      $cuid           = isset($get['customerId']) ? $get['customerId'] : false;
      $limit          = iftn(isset($get['limit']) ? $get['limit'] : false ,30);
      $date           = '';
      $actives        = '';
      $jsonOut        = [];

      if(validity($get['date'])){
        $date         = " AND transactionDate BETWEEN '" . $get['date'] . " 00:00:00' AND '" . $get['date'] . " 23:59:59'";
        $limit        = 1500;
      }

      if($cuid){
        $customer = ' customerId IN (' . dec($cuid) . ')';
      }else{
        $customer = ' outletId = ' . OUTLET_ID;
      }

      if(isset($get['active'])){//solo muestro ordenes activas, saco las canceladas y las finalizadas
        $actives = ' AND transactionStatus IN(0,1,2,3,5)';
      }
      
      $sql = 'SELECT 
                  *
              FROM transaction 
              WHERE
              ' .
              $customer .
              ' AND transactionType = 12' .
              $actives .
              $date .
              ' AND companyId = ' . COMPANY_ID .
              ' ORDER BY transactionDate 
              DESC LIMIT ' . $limit;

      $result = ncmExecute($sql,[],false,true);

      $jsonOut['date']              = $get['date'];
      $jsonOut['listName']          = 'Órdenes';
      $jsonOut['transactionsList']  = [];

      if($result){
        
        while (!$result->EOF){
          $fields     = $result->fields;
          $status     = $fields['transactionStatus'];
          $discount   = $fields['transactionDiscount'];
          $total      = $fields['transactionTotal'];
          $cusData    = getCustomerData($fields['customerId'],'uid');
          $cusName    = iftn(getCustomerName($cusData),'Sin Nombre');
          $typeOfSale = '<span class="label bg-success text-xs">Orden</span>';
          $name       = '';
          $icon       = '';
          $jsonDetails = json_decode($fields['transactionDetails'],true);

          for( $i = 0; $i < counts($jsonDetails); $i++ ){
            $jsonDetails[$i]['user'] = enc($fields['userId']);
          }
          
          $stat       = 'b-5x b-l ';

          list($scDate,$scStart,$scEnd) = dateStartEndTime($fields['fromDate'],$fields['toDate']);
          $timeFrame  = $scStart . ' - ' . $scEnd;

          //1. Nada (Gris) 2. Orden en espera (Amarillo) 3. Orden en Proceso (Azul) 4. Orden finalizada (Verde) 5. Orden Anulada (Rojo) 6. Otro (Negro)
          
          switch ($status) {
            case '0':
              $stat .= 'b-light';
              break;
            case '1':
              $stat .= 'b-light';
              break;
            case '2':
              $stat .= 'b-warning';
              break;
            case '3':
              $stat .= 'b-info';
              break;
            case '4':
              $stat .= 'b-success';
              break;
            case '5':
              $stat .= 'b-dark';
              break;
            case '6':
              $stat .= 'b-danger';
              break;
          }

          $jsonOut['transactionsList'][] = [
                                              'id'          => enc($fields['transactionId']),
                                              'title'       => $name . ' ' . $cusName,
                                              'date'        => niceDate($fields['transactionDate']), 
                                              'docNumber'   => ' #' . $fields['invoicePrefix'] . $fields['invoiceNo'],
                                              'amount'      => formatCurrentNumber($fields['transactionTotal'],$dec,$ts),
                                              'label'       => $typeOfSale,
                                              'type'        => $fields['transactionType'],
                                              'borderColor' => $stat
                                            ];

          $result->MoveNext();
        }
            
        $result->Close();

        if(!$get['date']){
          $jsonOut['footBtn'] = '<a href="#openOrders&l=' . ($limit + 50) . '&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</span>';
        }else{
          $jsonOut['footBtn'] = '<a href="#openOrders&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
        }

      }else{
        $jsonOut['footBtn'] = '<a href="#openOrders&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
      }

      header('Content-Type: application/json');
      echo json_encode($jsonOut);
  }

  if($load == 'quotesList'){
  
      $cuid           = $get['customerId'];
      $limit          = iftn($get['limit'],30);
      $date           = '';
      $actives        = '';
      $jsonOut        = [];

      if(validity($get['date'])){
        $date         = " AND transactionDate BETWEEN '" . $get['date'] . " 00:00:00' AND '" . $get['date'] . " 23:59:59'";
        $limit        = 1500;
      }

      if($cuid){
        $customer = ' customerId IN (' . dec($cuid) . ')';
      }else{
        $customer = ' outletId = ' . OUTLET_ID;
      }
      
      $sql = 'SELECT 
                  *
              FROM transaction 
              WHERE
              ' .
              $customer .
              ' AND transactionType = 9' .
              $date .
              ' AND companyId = ' . COMPANY_ID .
              ' ORDER BY transactionDate 
              DESC LIMIT ' . $limit;

      $result = ncmExecute($sql,[],false,true);

      $jsonOut['date']              = $get['date'];
      $jsonOut['listName']          = 'Cotizaciones';
      $jsonOut['transactionsList']  = [];

      if($result){
        
        while (!$result->EOF){
          $fields     = $result->fields;
          $status     = $fields['transactionStatus'];
          $discount   = $fields['transactionDiscount'];
          $total      = $fields['transactionTotal'];
          $cusData    = getCustomerData($fields['customerId'],'uid');
          $cusName    = iftn(getCustomerName($cusData),'Sin Nombre');
          $typeOfSale = '<span class="label bg-warning dk text-xs">Cotización</span>';
          $name       = '';
          $icon       = '';
          $jsonDetails = json_decode($fields['transactionDetails'],true);
          for($i=0;$i<counts($jsonDetails);$i++){
            $jsonDetails[$i]['user'] = enc($fields['userId']);
          }
          
          $stat       = 'b-5x b-l ';

          list($scDate,$scStart,$scEnd) = dateStartEndTime($fields['fromDate'],$fields['toDate']);
          $timeFrame  = $scStart . ' - ' . $scEnd;

          //1. Nada (Gris) 2. Orden en espera (Amarillo) 3. Orden en Proceso (Azul) 4. Orden finalizada (Verde) 5. Orden Anulada (Rojo) 6. Otro (Negro)

          switch ($status) {
            case '0':
                $caseOColor = 'b-light';
                $stat .= $caseOColor;
                break;
            case '1':
                $stat .= 'b-light';
                break;
            case '2':
                $stat .= 'b-info';
                break;
            case '3':
                $stat .= 'b-primary';
                break;
            case '4':
                $stat .= 'b-success';
                break;
            case '5':
                $stat .= 'b-danger';
                break;
            case '6':
                $stat = 'b-3x b-l b-dark';
                break;
          }

          $jsonOut['transactionsList'][] = [
                                              'id'          => enc($fields['transactionId']),
                                              'title'       => $name . ' ' . $cusName,
                                              'date'        => niceDate($fields['transactionDate']), 
                                              'docNumber'   => ' #' . $fields['invoicePrefix'] . $fields['invoiceNo'],
                                              'amount'      => formatCurrentNumber($fields['transactionTotal'],$dec,$ts),
                                              'label'       => $typeOfSale,
                                              'type'        => $fields['transactionType'],
                                              'borderColor' => $stat
                                            ];

          $result->MoveNext();
        }
            
        $result->Close();

        if(!$get['date']){
          $jsonOut['footBtn'] = '<a href="#openQuotes&l=' . ($limit + 50) . '&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</span>';
        }else{
          $jsonOut['footBtn'] = '<a href="#openQuotes&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
        }

      }else{
        $jsonOut['footBtn'] = '<a href="#openQuotes&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
      }

      header('Content-Type: application/json');
      echo json_encode($jsonOut);
  }

  if($load == 'savedList'){
  
      $cuid           = $get['customerId'] ?? false;
      $limit          = iftn($get['limit'] ?? false,30);
      $date           = '';
      $actives        = '';
      $jsonOut        = [];

      if(validity($get['date'])){
        $date         = " AND transactionDate BETWEEN '" . $get['date'] . " 00:00:00' AND '" . $get['date'] . " 23:59:59'";
        $limit        = 1500;
      }

      if($cuid){
        $customer = ' customerId IN (' . dec($cuid) . ')';
      }else{
        $customer = ' outletId = ' . OUTLET_ID;
      }
      
      $sql = 'SELECT 
                  *
              FROM transaction 
              WHERE
              ' .
              $customer .
              ' AND transactionType = 2' .
              $date .
              ' AND companyId = ' . COMPANY_ID .
              ' ORDER BY transactionDate 
              DESC LIMIT ' . $limit;

      $result = ncmExecute($sql,[],false,true);

      $jsonOut['date']              = $get['date'];
      $jsonOut['listName']          = 'Guardado';
      $jsonOut['transactionsList']  = [];

      if($result){
        
        while (!$result->EOF){
          $fields     = $result->fields;
          $status     = $fields['transactionStatus'];
          $discount   = $fields['transactionDiscount'];
          $total      = $fields['transactionTotal'];
          $cusData    = getCustomerData($fields['customerId'],'uid');
          $cusName    = iftn(getCustomerName($cusData),'Sin Nombre');
          $typeOfSale = '<span class="label text-dark b b-light text-xs">Guardado</span>';
          $name       = '';
          $icon       = '';
          $jsonDetails = json_decode($fields['transactionDetails'],true);
          for($i=0;$i<counts($jsonDetails);$i++){
            $jsonDetails[$i]['user'] = enc($fields['userId']);
          }
          
          $stat       = 'b-5x b-l ';

          list($scDate,$scStart,$scEnd) = dateStartEndTime($fields['fromDate'],$fields['toDate']);
          $timeFrame  = $scStart . ' - ' . $scEnd;

          $name   = '<span class="text-info">' . $fields['transactionName'] . '</span>';

          //1. Nada (Gris) 2. Orden en espera (Amarillo) 3. Orden en Proceso (Azul) 4. Orden finalizada (Verde) 5. Orden Anulada (Rojo) 6. Otro (Negro)

          switch ($status) {
            case '0':
                $caseOColor = 'b-light';
                $stat .= $caseOColor;
                break;
            case '1':
                $stat .= 'b-light';
                break;
            case '2':
                $stat .= 'b-info';
                break;
            case '3':
                $stat .= 'b-primary';
                break;
            case '4':
                $stat .= 'b-success';
                break;
            case '5':
                $stat .= 'b-danger';
                break;
            case '6':
                $stat = 'b-3x b-l b-dark';
                break;
          }

          $jsonOut['transactionsList'][] = [
                                              'id'          => enc($fields['transactionId']),
                                              'title'       => $name . ' ' . $cusName,
                                              'date'        => niceDate($fields['transactionDate']), 
                                              'docNumber'   => ' #' . $fields['invoicePrefix'] . $fields['invoiceNo'],
                                              'amount'      => formatCurrentNumber($fields['transactionTotal'],$dec,$ts),
                                              'label'       => $typeOfSale,
                                              'type'        => $fields['transactionType'],
                                              'borderColor' => $stat
                                            ];

          $result->MoveNext();
        }
            
        $result->Close();

        if(!$get['date']){
          $jsonOut['footBtn'] = '<a href="#openSaved&l=' . ($limit + 50) . '&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</span>';
        }else{
          $jsonOut['footBtn'] = '<a href="#openSaved&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
        }

      }else{
        $jsonOut['footBtn'] = '<a href="#openSaved&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
      }

      header('Content-Type: application/json');
      echo json_encode($jsonOut);
  }

  if($load == 'tin'){
    echo curlContents(API_ENCOM_URL . '/get_tin?id=' . $get['id'] . '&country=' . $get['country'],'POST',['company_id'=>enc(COMPANY_ID),'api_key'=>API_KEY]);
  }

  if($load == 'chkInvoiceNo' && $get['no']){
    $no       = $get['no'];
    $from     = date('Y-m-d 00:00:00',strtotime('-30 days'));
    $result   = ncmExecute('SELECT transactionId FROM transaction WHERE companyId = ? AND registerId = ? AND transactionType IN(0,3) AND invoiceNo = ? AND transactionDate BETWEEN "' . $from . '" AND "' . TODAY_END . '" LIMIT 1',[COMPANY_ID,REGISTER_ID,$no]);

    if($result){
      echo '1';
    }else{
      echo '0';
    }

    dai();
  }

  if($load == 'docsNum'){

    $register = ncmExecute("SELECT * FROM register WHERE registerStatus = 1 AND registerId = ? AND companyId = ? LIMIT 1", [dec($get['id']),COMPANY_ID]);

    $docsNumArray   = [];

    if($register){
      $invoiceNo  = $register['registerInvoiceNumber'];
      $returnNo   = getNextDocNumber($register['registerReturnNumber'],'6',COMPANY_ID,$register['registerId']);
      $scheduleNo = getNextDocNumber($register['registerScheduleNumber'],'13',COMPANY_ID,$register['registerId']);
      $pedidoNo   = getNextDocNumber($register['registerPedidoNumber'],'12',COMPANY_ID,$register['registerId']);
      $quoteNo    = getNextDocNumber($register['registerQuoteNumber'],'9',COMPANY_ID,$register['registerId']);

      $docsNumArray = [
                          'registerId'              => enc($register['registerId']),
                          'invoiceNo'               => iftn($invoiceNo,0),
                          'ticketNo'                => iftn($register['registerTicketNumber'],0),
                          'returnNo'                => iftn($returnNo,0),
                          'scheduleNo'              => iftn($scheduleNo,0),
                          'orderNo'                 => iftn($pedidoNo,0),
                          'quoteNo'                 => iftn($quoteNo,0)
                        ];
    }

    jsonDieResult($docsNumArray);
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