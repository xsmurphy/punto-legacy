<?php
session_start();
require_once(__DIR__ . '/includes/cors.php');
//ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

ini_set('memory_limit', '2048M');
ini_set('max_execution_time', '700');

$GLOBALS['_execution_start']       = microtime(true);
$GLOBALS['_execution_start_glob']  = microtime(true);


function checkExecTime($reference = false){
  return false;
  $toPrint = ($reference) ? $reference : $_SERVER;

  $executionTimeLast    = microtime(true) - $GLOBALS['_execution_start'];
  $executionTimeLastAll = microtime(true) - $GLOBALS['_execution_start_glob'];

  if($executionTimeLast >= 1){
    file_put_contents(
        'cach/mysql_profiling_results.txt',
        'FETCH [' . date('m-d H:i:s') . '] Since loaded took: ' . number_format($executionTimeLastAll,3) . ' Process took ' . number_format($executionTimeLast,3) . ' : ' . print_r($toPrint, true) . "\n",
        FILE_APPEND
    );
  }
  $GLOBALS['_execution_start'] = microtime(true);
}

require_once('includes/jwt_middleware.php');

if(isset($_POST['companyId']) && isset($_POST['outletId'])){
  $rateLimiterId = $_POST['outletId'];

  include_once('head.php');

  // Autenticación JWT (cookie HttpOnly, header Bearer, o POST _jwt)
  $jwtValid = jwtAuthenticate();

  if ($jwtValid) {
    // Identidad validada server-side
    $companyId = AUTHED_COMPANY_ID;
    $outletId  = AUTHED_OUTLET_ID;
    // Verificar que el companyId del POST coincide con el del token (previene mezcla)
    $postedCompanyId = dec(validateHttp('companyId', 'post'));
    if ($postedCompanyId && $postedCompanyId !== $companyId) {
      http_response_code(403);
      header('Content-Type: application/json');
      die(json_encode(['error' => 'Mismatch de identidad', 'code' => 403]));
    }
  } else {
    // Ruta legacy: decodificar Hashids del POST
    header('X-Legacy-Auth: 1');
    $companyId = dec( validateHttp('companyId','post') );
    $outletId  = dec( validateHttp('outletId','post') );
  }

  $LOAD = validateHttp('load');

  if(!checkCompanyStatus($companyId)){
    jsonDieMsg('Not found',404);
  }

  define('COMPANY_ID', $companyId);
  define('OUTLET_ID', $outletId);

  session_write_close();
  header('Content-Type: application/json');

  //ob_start();
  //ob_implicit_flush(0);

  $login_ok   = true;

  //si no hay fecha puede ser un refresh del browser, entonces dejo que cargue todo
  if(validateHttp('lastUpdate','post') && validateHttp('lastUpdate','post') != 'false'){
  
    $lastUpdateApp        = validateHttp('lastUpdate','post');
    $lstUpdt              = ncmExecute("SELECT companyLastUpdate, itemsLastUpdate, customersLastUpdate FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
    
    $lastUpdateServ       = $lstUpdt['companyLastUpdate'];
    $lastUpdateItems      = $lstUpdt['itemsLastUpdate'];
    $lastUpdateCustomers  = $lstUpdt['customersLastUpdate'];

    if(strtotime($lastUpdateApp) > strtotime($lastUpdateServ)){
      $login_ok   = false;
    }
  }

  if($login_ok){

    if($LOAD == 'ping'){
      echo json_encode(['success'=>'nd']);
      dai();
    }

    $outletCount  = getOutletCount(COMPANY_ID);
    $settings     = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
    $_modules     = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

    $cmy = ncmExecute(" SELECT
                            *
                        FROM company
                        WHERE
                        companyId = ? 
                        LIMIT 1",[COMPANY_ID]);

    if($cmy['plan'] < 1 || $settings['blocked'] == 1){
      jsonDieMsg('true',200,'locked');
    }

    $planIt       =  getAllPlans($cmy['plan']);
    
    $__modules   = json_decode($_modules['moduleData'], true);
    $__modules 	= is_array($__modules) ? $__modules : [];
    
    if (isset($__modules['extraItems']) && is_numeric($__modules['extraItems'])) {
      $planIt['max_items'] = $planIt['max_items'] + $__modules['extraItems'];
    }


    //USERS
    if($LOAD == 'users'){
      $userData     = ncmExecute("  SELECT
                                      *
                                    FROM contact
                                    WHERE
                                      companyId = ? 
                                    AND type = 0 
                                    AND contactStatus > 0
                                    ORDER BY main ASC, outletId DESC, contactInCalendar DESC, role ASC
                                    LIMIT " . (($planIt['max_users'] * $outletCount) + $_modules['extraUsers']),
                                    [COMPANY_ID],false,true);

      $userDataArray = [];

      if($userData){
        while (!$userData->EOF) {
          $uFields = $userData->fields;
          // Here I am preparing to store the $row array into the $_SESSION by
          // removing the salt and password values from it.  Although $_SESSION is
          // stored on the server-side, there is no reason to store sensitive values
          // in it unless you have to.  Thus, it is best practice to remove these
          // sensitive values first.

          $userToken       = $uFields['contactPassword'] ? ncmEncode( enc($uFields['contactId']) . '[@]' . $uFields['contactPassword'] ) : '';

          unset($uFields['salt'],$uFields['contactPassword']);

          $userDataArray[] =  [
                                'userId'        => enc($uFields['contactId']),
                                'name'          => toUTF8($uFields['contactName']),
                                'email'         => $uFields['contactEmail'],
                                'phone'         => $uFields['contactPhone'],
                                'lockPass'      => $uFields['lockPass'],
                                'role'          => $uFields['role'],
                                'outlet'        => ($uFields['outletId']) ? enc($uFields['outletId']) : false,
                                'roleName'      => getRoleName($uFields['role']),
                                'inCalendar'    => $uFields['contactInCalendar'],
                                'calendarPosition' => $uFields['contactCalendarPosition'],
                                'trackGPS'      => $uFields['contactTrackLocation'],
                                'color'         => $uFields['contactColor'],
                                'intercom'      => hash_hmac('sha256',enc($uFields['contactId']),INTERCOM_IDENTITY_SECRET),
                                'permissions'   => getRolePermissions($uFields['role'],COMPANY_ID),
                                'token'         => $userToken
                              ];
        
          //El cero es el register ID, la idea es que al configurar el tablet, baje las opciones de register IDs de la cuenta y se le asigna un register ID al tablet
          $userData->MoveNext();
        }
        $userData->Close();
      }

      echo json_encode($userDataArray);

    }

    //OUTLETS
    if($LOAD == 'outlets'){
      $outletsIds = ncmExecute("SELECT
                                    *
                                FROM outlet
                                WHERE companyId = ? 
                                AND outletStatus = 1 LIMIT " . $planIt['max_outlets'],
                                [COMPANY_ID],false,true);

      $outletsIdsArray = [];
    
      if($outletsIds){
        while (!$outletsIds->EOF) {
          $oFields    = $outletsIds->fields;
          $oI         = $oFields['outletId'];
          $oN         = toUTF8($oFields['outletName']);
          $oAddress   = toUTF8($oFields['outletAddress']);
          $oPhone     = $oFields['outletPhone'];
          $oRuc       = $oFields['outletRUC'];
          $oRazon     = toUTF8($oFields['outletBillingName']);
          $open       = $oFields['outletOpenFrom'] ?? "";
          $close      = $oFields['outletOpenTo'] ?? "";
          $saleTax    = !empty($oFields['taxId']) ? getTaxValue($oFields['taxId']) : false ;

          $outletsIdsArray[] = [
                                  'outletId'        => enc($oI),
                                  'name'            => $oN,
                                  'outletAddress'   => toUTF8($oAddress),
                                  'outletPhone'     => $oPhone,
                                  'outletRazon'     => $oRazon,
                                  'outletRuc'       => $oRuc,
                                  'outletOpen'      => $open,
                                  'outletClose'     => $close,
                                  'attendanceToken' => md5( enc(COMPANY_ID) . enc($oI) ),
                                  'outletLatLng'    => toUTF8($oFields['outletLatLng']),
                                  'saleTax'         => $saleTax,
                                  'weekHours'       => json_decode(toUTF8($oFields['outletBusinessHours']),true)
                                ];
          
          $outletsIds->MoveNext();
        }
        $outletsIds->Close();
      }

      echo json_encode($outletsIdsArray);

    }

    //REGISTER
    if($LOAD == 'registers'){
      $registersIds = ncmExecute("  SELECT *
                                    FROM register
                                    WHERE registerStatus = 1
                                    AND companyId = ? LIMIT " . ( ($planIt['max_registers'] * $outletCount) + $_modules['extraRegisters'] ),
                                    [COMPANY_ID],false,true);

      $registersIdsArray  = [];
      $docsNumArray       = [];

      if($registersIds){
        while (!$registersIds->EOF) {
          $rFields        = $registersIds->fields;
          $jrFields       = json_decode($rFields['data'] ?? "",true);

          $activeOutlet   = ncmExecute('SELECT outletStatus FROM outlet WHERE outletId = ? AND outletStatus = 1 AND companyId = ? LIMIT 1',[$rFields['outletId'],COMPANY_ID]);

          if($activeOutlet){

            $TODAY_END      = date('Y-m-d 23:59:59');
            $TWO_MONTHS_AGO = date('Y-m-d 00:00:00',strtotime('-2 months'));

            $usedInvoiceNosA = [];
            $usedInvoiceNosB = [];
            $usedInvoiceNos = false;
            //$usedInvoiceNos = ncmExecute("SELECT invoiceNo as nos, tags as tag FROM transaction WHERE companyId = ? AND registerId = ? AND transactionType IN(0,3,7) AND transactionDate BETWEEN ? AND ? ORDER BY invoiceNo DESC",[COMPANY_ID, $rFields['registerId'], $TWO_MONTHS_AGO, $TODAY_END],false,true);
            //$usedInvoiceNos = ncmExecute("SELECT invoiceNo as nos, tags as tag FROM transaction WHERE companyId = ? AND registerId = ? AND transactionType IN(0,3,7) ORDER BY transactionDate DESC LIMIT 50",[COMPANY_ID, $rFields['registerId']],false,true);
            //$usedInvoiceNos = ncmExecute("SELECT invoiceNo as nos, tags as tag FROM transaction FORCE INDEX (idx_transaction_optimization) WHERE companyId = ? AND registerId = ? AND transactionType IN(0,3,7) ORDER BY transactionDate DESC LIMIT 50",[COMPANY_ID, $rFields['registerId']],false,true);
		$usedInvoiceNos = ncmExecute("SELECT t1.invoiceNo as nos,t1.tags as tag
                          FROM transaction t1
                          JOIN (
                              SELECT t.transactionId
                              FROM transaction t
                              FORCE INDEX (idx_transaction_optimization_2)
                              WHERE t.companyId = ?
                              AND t.registerId = ?
                              AND t.invoiceNo IS NOT NULL
                              AND t.transactionType IN (0,3,7)
                              ORDER BY t.transactionDate DESC
                              LIMIT 50
                          ) AS subquery
                          ON t1.transactionId = subquery.transactionId",[COMPANY_ID, $rFields['registerId']],false,true);
            if($usedInvoiceNos){
              while (!$usedInvoiceNos->EOF) {
                
                $isInternal         = false;
                $tags               = json_decode(toUTF8($usedInvoiceNos->fields['tag']), true);

                if(is_array($tags)){

                  foreach ($tags as $key => $value) {
                    if($value == 166227){
                      $isInternal = true;
                    }
                  }

                }

                if(!$isInternal){
                  $usedInvoiceNosA[]  = $usedInvoiceNos->fields['nos'];
                }
                if($isInternal){
                  $usedInvoiceNosB[]  = $usedInvoiceNos->fields['nos'];
                }

                $usedInvoiceNos->MoveNext();
              }

              $usedInvoiceNos->Close();
            }

            $authExpiration = $rFields['registerInvoiceAuthExpiration'];
            $authExpiration = $authExpiration ? date('Y-m-d',strtotime($authExpiration)) . ' 23:59:59' : '';

            $returnAuthExpiration = $jrFields['registerReturnAuthExpiration'] ?? null;
            $returnAuthExpiration = $returnAuthExpiration ? date('Y-m-d',strtotime($returnAuthExpiration)) : '';

            $registersIdsArray[] = [
                                      'registerId'              => enc($rFields['registerId']),
                                      'name'                    => toUTF8($rFields['registerName']),
                                      'outletId'                => enc($rFields['outletId']),
                                      'invoiceAuthNo'           => $rFields['registerInvoiceAuth'],
                                      'returnAuthNo'            => $jrFields['registerReturnAuth'] ?? "",
                                      'invoiceAuthExpiration'   => $authExpiration,
                                      'returnAuthExpiration'    => $returnAuthExpiration,
                                      'invoiceAuthStart'        => $jrFields['registerInvoiceAuthStart'] ?? "",
                                      'invoiceNoMax'            => $jrFields['registerInvoiceNoMax'] ?? "",
                                      'returnAuthStart'         => $jrFields['registerReturnAuthStart'] ?? "",
                                      'returnNoMax'             => $jrFields['registerReturnNoMax'] ?? "",
                                      'invoiceSufix'            => $rFields['registerInvoiceSufix'],
                                      'invoicePrefix'           => $rFields['registerInvoicePrefix'],
                                      'returnPrefix'            => $jrFields['registerReturnPrefix'] ?? "",
                                      'hotkeys'                 => iftn($rFields['registerHotkeys'],'[]'),
                                      'printers'                => $rFields['registerPrinters'],
                                      'leadingZero'             => $rFields['registerDocsLeadingZeros'],
                                      'usedInvoiceNo'           => $usedInvoiceNosA,
                                      'usedTicketNo'            => $usedInvoiceNosB,
                                      'electronicInvoice'            => !is_null($jrFields) ? (array_key_exists("electronicInvoice",$jrFields) ? $jrFields['electronicInvoice'] : "") : ""
                                    ];

            if(!validateHttp('lastUpdate','post') || validateHttp('lastUpdate','post') == 'false'){//si es un update no cargo los DOC Nums
              $invoiceNo  = getNextDocNumber($rFields['registerInvoiceNumber'],'0,3',COMPANY_ID,$rFields['registerId']);
              // $invoiceNo  = $rFields['registerInvoiceNumber'];
              $returnNo   = getNextDocNumber($rFields['registerReturnNumber'],'6',COMPANY_ID,$rFields['registerId']);
              $scheduleNo = getNextDocNumber($rFields['registerScheduleNumber'],'13',COMPANY_ID,$rFields['registerId']);
              $pedidoNo   = getNextDocNumber($rFields['registerPedidoNumber'],'12',COMPANY_ID,$rFields['registerId']);
              $quoteNo    = getNextDocNumber($rFields['registerQuoteNumber'],'9',COMPANY_ID,$rFields['registerId']);
              //$remissionNo   = getNextDocNumber($rFields['registerRemitoNumber'],'9',COMPANY_ID,$rFields['registerId']);

              $docsNumArray[] = [
                                  'registerId'              => enc($rFields['registerId']),
                                  'invoiceNo'               => iftn($invoiceNo,0),
                                  'ticketNo'                => iftn($rFields['registerTicketNumber'],0),
                                  'returnNo'                => iftn($returnNo,0),
                                  'scheduleNo'              => iftn($scheduleNo,0),
                                  'orderNo'                 => iftn($pedidoNo,0),
                                  'quoteNo'                 => iftn($quoteNo,0)
                                ];

            }

          }

          $registersIds->MoveNext();
        }
        $registersIds->Close();
      }

      echo json_encode(['registers' => $registersIdsArray, 'docsNum' => $docsNumArray]);

    }

    //SETTINGS
    if($LOAD == 'settings'){
      if($settings){
        //obtengo upsell triggers
        $upsellArray  = [];
        $upsell       = ncmExecute('SELECT * FROM upsell WHERE companyId = ?',[COMPANY_ID],false,true);
        if($upsell){
          while (!$upsell->EOF) {
            $ups      = $upsell->fields;
            $child    = enc($ups['upsellChildId']);
            $parent   = enc($ups['upsellParentId']);

            $upsellArray[$child][] = $parent;

            $upsell->MoveNext();
          }

          $upsell->Close();
        }

        $settingsFull     = json_decode($settings['settingObj'],true);
        $ecomData         = json_decode(stripslashes($_modules['ecom_data']) ,true);

        if (isset($ecomData['tiers']) && is_array($ecomData['tiers'])) {
          foreach ($ecomData['tiers'] as &$tier) {
            if (isset($tier['id'])) {
              $id = dec($tier['id']);
              $result = ncmExecute('SELECT itemPrice FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID], true);
              $tier['price'] = 0;

              if ($result) {
                $tier['price'] = $result['itemPrice'];
              }
            }
          }
        }

        $settingsArray[]  = [
                            'companyId'             => enc(COMPANY_ID),
                            'companyName'           => toUTF8($settings['settingName']),
                            'companyBillingName'    => toUTF8($settings['settingBillingName']),
                            'companyAddress'        => toUTF8($settings['settingAddress']),
                            'companyTIN'            => $settings['settingRUC'],
                            'companyPhone'          => $settings['settingPhone'],
                            'companyEmail'          => $settings['settingEmail'],
                            'companyWebsite'        => $settings['settingWebSite'],
                            'companyCategory'       => $settings['settingCompanyCategoryId'],
                            'companyDate'           => strtotime($cmy['createdAt']),
                            'companyBalance'        => $cmy['balance'],
                            'currency'              => $settings['settingCurrency'],
                            'taxName'               => $settings['settingTaxName'],
                            'removeTax'             => (int)$settings['settingRemoveTaxes'],
                            'taxPY'                 => false,
                            'tin'                   => iftn($settings['settingTIN'],'I.D.'),
                            'decimal'               => $settings['settingDecimal'],
                            'thousandSeparator'     => $settings['settingThousandSeparator'],
                            'customTemplates'       => getCustomTemplates(COMPANY_ID),
                            'tags'                  => getTaxonomyArray('tag',COMPANY_ID,true),
                            'tagsSys'               => getTagsDefaults(true),
                            'paymentMethods'        => getTaxonomyArray('paymentMethod',COMPANY_ID),
                            'bankNames'             => getTaxonomyArray('bankName',COMPANY_ID),
                            'sellSoldOut'           => $settings['settingSellSoldOut'],
                            'blindDrawer'           => $settings['settingDrawerBlind'],
                            'paymentMethodId'       => $settings['settingPaymentMethodId'],
                            'itemSerialized'        => $settings['settingItemSerialized'],
                            'clocking'              => false,
                            'lockScreen'            => $settings['settingLockScreen'],
                            'itemsSaleLimit'        => $settings['settingItemsSaleLimit'],
                            'country'               => $settings['settingCountry'],
                            'countryISO'            => $countries[$settings['settingCountry']]['iso'],
                            'loyalty'               => ($_modules['loyalty'] ? $_modules['loyalty'] : false),
                            'epos'                  => ($_modules['epos'] ? json_decode($_modules['eposData'],true) : false),
                            'storeCredit'           => (int)$settings['settingStoreCredit'],
                            'storeTables'           => ($_modules['tables'] ? $_modules['tables'] : false),
                            'tablesCount'           => $_modules['tablesCount'],
                            'calendar'              => ($_modules['calendar'] ? $_modules['calendar'] : false),
                            'ordersPanel'           => ($_modules['ordersPanel'] ? $_modules['ordersPanel'] : false),
                            'orderAverageTime'      => ($_modules['orderAverageTime'] ? $_modules['orderAverageTime'] : 60),
                            'spotify'               => ( ($_modules['spotify'] && $_modules['spotifyUrl']) ? $_modules['spotifyUrl'] : false),
                            'dropbox'               => ( ($_modules['dropbox'] && $_modules['dropboxToken']) ? $_modules['dropboxToken'] : false),
                            'phonePrefix'           => $countries[$settings['settingCountry']]['phone'],
                            'plan'                  => $planIt,
                            'itemsCategories'       => getAllItemCategories(COMPANY_ID),
                            'opensFrom'             => $settings['settingOpenFrom'],
                            'opensTo'               => $settings['settingOpenTo'],
                            'phoneCode'             => $countries[$settings['settingCountry']]['phone'],
                            'hideCombo'             => $settings['settingHideComboItems'],
                            'webAppVersion'         => APP_VERSION,
                            'forceCreditLine'       => (int)$settings['settingForceCreditLine'],
                            'upsellList'            => $upsellArray,
                            'mandatoryContact'      => $settings['settingMandatoryContactFields'],
                            'supportLock'           => '0890',
                            'fullSettings'          => json_decode(toUTF8($settings['settingObj']),true),
                            'modules'               => json_decode(toUTF8($_modules['moduleData']),true),
                            'ecomData'              => $ecomData,
                            'digitalInvoice'        => $_modules['digitalInvoice'] ? true : false,
                            'accountBlockingAlert'  => ['is' => $settings['planExpired'], 'txt' => 'Le recordamos que posee facturas vencidas en su cuenta ENCOM.'],
                            'end'                   => 'is near',
                            'publicURL'                      => PUBLIC_URL
                          ];
      } 
      echo json_encode($settingsArray);

    }
    
    //CUSTOMERS
    if($LOAD == 'customers'){
      if(!isset($lastUpdateApp) || !validity($lastUpdateApp)){
        //descargo todo
        $downloadItems    = true;
        $updated_at       = '';
      }else{
        if(strtotime($lastUpdateApp) > strtotime($lastUpdateCustomers)){// nada nuevo
          $downloadItems  = false;
        }else{
          $downloadItems  = true;
        }
        $updated_at       = " AND (updated_at > '" . $lastUpdateApp . "' AND updated_at IS NOT NULL)";
      }

      $customersArray = [];

      if($downloadItems){
          
          $customer = ncmExecute("  SELECT contactId, contactName, contactNote, contactSecondName, contactId, contactTIN, contactCI, contactPhone, contactPhone2, contactEmail, contactBirthDay, contactLoyaltyAmount, contactStatus, type, contactCreditLine, contactStoreCredit, data
                                    FROM contact 
                                    WHERE companyId = ? 
                                    " . $updated_at . "
                                    ORDER BY contactName ASC
                                    LIMIT " . $planIt['max_customers'],
                                    [COMPANY_ID],30,true);

          //AND contactStatus > 0 
          //AND type        = 1
        
        if($customer){
          
          //creo un arrray con todas las direcciones
          $cAIns = [];
          while (!$customer->EOF) {
            $cFields  = $customer->fields;
            if($cFields['contactStatus'] > 0 && $cFields['type'] == 1 && validity($cFields['contactId'])){
              $cAIns[] = $cFields['contactId'];
            }
            $customer->MoveNext();
          }
          $customer->MoveFirst();

          $allAddress     = [];
          $custAddresses  = ncmExecute('SELECT * FROM customerAddress WHERE companyId = ? AND customerAddressDefault = 1 AND customerId IN(' . implodes(',', $cAIns) . ')',[COMPANY_ID],false,true);

          if($custAddresses){

            while (!$custAddresses->EOF) {
              $cAfields = $custAddresses->fields;
              $latLng   = false;
              if($cAfields['customerAddressLat'] && $cAfields['customerAddressLng']){
                $latLng = $cAfields['customerAddressLat'] . ',' . $cAfields['customerAddressLng'];
              }

              if( validity($cAfields['customerAddressId']) ){
                $allAddress[$cAfields['customerId']]['addressId'] = enc( $cAfields['customerAddressId'] );
              }

              if( validity($cAfields['customerAddressText']) ){
                $allAddress[$cAfields['customerId']]['address']   = rtrim( $cAfields['customerAddressText'] );
              }

              if( $latLng ){
                $allAddress[$cAfields['customerId']]['latLng']    = rtrim( $latLng );
              }

              if( validity($cAfields['customerAddressLocation']) ){
                $allAddress[$cAfields['customerId']]['location']  = rtrim( $cAfields['customerAddressLocation'] );
              }

              if( validity($cAfields['customerAddressCity']) ){
                $allAddress[$cAfields['customerId']]['city']      = rtrim( $cAfields['customerAddressCity'] );
              }

              $custAddresses->MoveNext();
            }

          }
          
          while (!$customer->EOF) {
            $cusArray = [];
            $cFields  = $customer->fields;

            if($cFields['contactStatus'] > 0 && $cFields['type'] == 1){

              $jcFields       = json_decode($cFields['data'] ?? "",true);

              if(!is_null($jcFields) && array_key_exists("diplomatic", $jcFields)){
                $cusArray['diplomatic'] = $jcFields['diplomatic'];
              }

              $name     = toUTF8($cFields['contactName']);
              if(!$cFields['contactName'] && $cFields['contactSecondName']){
                $name = toUTF8($cFields['contactSecondName']);
              }

              if(validity($cFields['contactId'])){
                $cusArray['customerId']   = enc($cFields['contactId']);
                if(isset($lastUpdateApp) && validity($lastUpdateApp)){//si solo quiero acttualizar envio decoded id para match con users recien creados
                  $cusArray['customerUnd']   = $cFields['contactId'];
                }
              }

              if(validity($name)){
                $cusArray['name']         = toUTF8($name);
              }
              if(validity($cFields['contactTIN'])){
                $cusArray['ruc']          = $cFields['contactTIN'];
              }
              if(validity($cFields['contactCI'])){
                $cusArray['ci']          = $cFields['contactCI'];
              }
              if(validity($cFields['contactSecondName'])){
                $cusArray['fullName']     = toUTF8($cFields['contactSecondName']);
              }
              if(validity($cFields['contactPhone'])){
                $cusArray['phone']        = $cFields['contactPhone'];
              }
              if(validity($cFields['contactPhone2'])){
                $cusArray['phone2']       = $cFields['contactPhone2'];
              }
              if(isset($cFields['contactAddress']) && validity($cFields['contactAddress'])){
                $cusArray['address']      = toUTF8($cFields['contactAddress']);
              }
              if(validity($cFields['contactEmail'])){
                $cusArray['email']        = $cFields['contactEmail'];
              }
              if(validity($cFields['contactBirthDay'])){
                $cusArray['birthDay']     = explodes(' ',$cFields['contactBirthDay'],0);
              }
              if(validity($cFields['contactNote'])){
                $cusArray['note']         = toUTF8($cFields['contactNote']);
              }
              if(validity($cFields['contactLoyaltyAmount'])){
                $cusArray['loyalty']      = $cFields['contactLoyaltyAmount'];
              }
              if(isset($cFields['contactCity']) && validity($cFields['contactCity'])){
                $cusArray['city']         = toUTF8($cFields['contactCity']);
              }
              if(isset($cFields['contactLocation']) && validity($cFields['contactLocation'])){
                $cusArray['location']      = $cFields['contactLocation'];
              }
              if(isset($cFields['contactCountry']) && validity($cFields['contactCountry'])){
                $cusArray['country']      = toUTF8($cFields['contactCountry']);
              }
              if(isset($cFields['contactLatLng']) && validity($cFields['contactLatLng'])){
                $cusArray['latLng']      = $cFields['contactLatLng'];
              }

              $custAddrs = $allAddress[$cFields['contactId']] ?? false;

              if(validity($custAddrs)){

                if(isset($custAddrs['latLng']) && $custAddrs['latLng']){
                  $cusArray['latLng']       = $custAddrs['latLng']; 
                }

                if(!empty($custAddrs['location']) && $custAddrs['location']){
                  $cusArray['location']     = $custAddrs['location'];
                }

                if(!empty($custAddrs['city']) && $custAddrs['city']){
                  $cusArray['city']         = toUTF8($custAddrs['city']);
                }

                if(!empty($custAddrs['address']) && $custAddrs['address']){
                  $cusArray['address']      = toUTF8($custAddrs['address']);
                }

                if($custAddrs['addressId']){
                  $cusArray['addressId']    = $custAddrs['addressId'];
                }

              }else{
                if(isset($cFields['contactAddress']) && validity($cFields['contactAddress'])){
                  ncmInsert([
                              'table'   => 'customerAddress', 
                              'records' => [
                                              'customerAddressText'     => $cFields['contactAddress'], 
                                              'customerAddressDefault'  => 1,
                                              'customerAddressDate'     => TODAY,
                                              'companyId'               => COMPANY_ID, 
                                              'customerId'              => $cFields['contactId']
                                            ] 
                            ]);
                }
              }
              
              if(validity($cFields['contactStoreCredit'])){
                $cusArray['storeCredit']  = $cFields['contactStoreCredit'];
              }
              if(validity($cFields['contactCreditLine'])){
                $cusArray['creditLine']   = getContactCreditLine($cFields['contactId'],$cFields['contactCreditLine']);
              }
              if(isset($cFields['categoryId']) && validity($cFields['categoryId'])){
                $cusArray['category']   = $cFields['categoryId'];
              }

              $customersArray[] = $cusArray;

            }
            
            $customer->MoveNext();
          }

          $customer->Close();
        }
      }

      echo json_encode($customersArray);

    }
    //CUSTOMERS END

    //PRODUCTS
    if($LOAD == 'items'){
      if(!isset($lastUpdateApp) || !validity($lastUpdateApp)){
        $downloadItems    = true;
        $updated_at       = '';
      }else{
        if(strtotime($lastUpdateApp) > strtotime($lastUpdateItems)){// nada nuevo
          $downloadItems  = false;
        }else{
          $downloadItems  = true;
        }
        $updated_at       = " AND (updated_at > '" . $lastUpdateApp . "' AND updated_at IS NOT NULL)";
      }

      $productsArray      = [];

      if($downloadItems){

        $limit        = " LIMIT " . $planIt['max_items'];
        $order        = 'ASC';
        $child        = [];
        $childrenIds  = getAllCompanyItemsChildren(COMPANY_ID);
        $allTaxonomy  = getAllTaxonomyNames(COMPANY_ID);
        $decimal      = ncmExecute('SELECT settingDecimal FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
        
        $products     = ncmExecute("SELECT * FROM item WHERE companyId = ? AND itemStatus = 1 AND itemCanSale = 1 AND (outletId = ? OR outletId IS NULL OR outletId = 0)" . $updated_at . " ORDER BY itemDate " . $order . $limit, [COMPANY_ID,OUTLET_ID],30,true);

        $catsIds      = getCategoriesIds(COMPANY_ID);
        $categorize   = iftn($catsIds,''," AND (categoryId IN(" . $catsIds . ") OR categoryId IS NULL)");
        
        //selecciono el inventario solo de los items obtenidos
        $invItemsIds = [];
        if($products){
          while (!$products->EOF) {
            $invItemsIds[] = $products->fields['itemId'];
            $products->MoveNext();
          }
          $products->MoveFirst();

          $allInventory = getAllItemStock(OUTLET_ID);
        }

        $z = 0;
        if($products){
          while (!$products->EOF) {

            $pFields    = $products->fields;
            $inv        = [];
            $prodArray  = [];
            $pData      = json_decode($pFields['data'] ?? "",true);

            if($pFields['itemIsParent'] != 0){
              $child = $childrenIds[enc($pFields['itemId'])];
            }else{
              $child = [];
            }

            if($decimal['settingDecimal'] == 'no'){//solución para los que cargaban productos con decimales y luego cambiaron
              $finalItemPrice   = round($pFields['itemPrice'] ?? 0);
            }else{
              $finalItemPrice   = $pFields['itemPrice'];
            }

            if($pFields['itemPriceType']){//si el precio es porcentual al costo
              $cogs = $allInventory[$pFields['itemId']]['cogs'] ?? 0;
              if($pFields['itemPricePercent'] < 1){
                $finalItemPrice = $cogs;
              }else{
                $addPrice = ($cogs * $pFields['itemPricePercent']) / 100;
                $finalItemPrice = $cogs + $addPrice;
              }
            }
            $itemSort       = round($pFields['itemSort'] ?? 0);
            
            $brand          = toUTF8($allTaxonomy[$pFields['brandId']]['name'] ?? "");
            $category       = toUTF8($allTaxonomy[$pFields['categoryId']]['name'] ?? "");
            $tax            = $allTaxonomy[$pFields['taxId']]['name'] ?? "";

            $inventoryCount = $allInventory[$pFields['itemId']]['onHand'] ?? 0;
            /*if($pFields['locationId']){
              $getLocation    = ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',[$pFields['locationId'],$pFields['itemId']]);
              $inventoryCount = $getLocation['toLocationCount'];
            }else{
              $inventoryCount = getItemMainStock($pFields['itemId'],OUTLET_ID);
            }*/

            $itemInventory  = [ 'count' => $inventoryCount ];

            $kind           = iftn($pFields['itemType'],'product');

            if($kind == 'product'){
              if($pFields['itemProduction'] > 0){
                $kind = 'production';
              }else if(isset($pFields['itemType']) && $pFields['itemType'] == 'product' && $pFields['itemTrackInventory'] < 1 && isset($pFields['compoundId']) && $pFields['compoundId'] != ''){
                $kind = 'direct_production';
              }
            }

            if($kind == 'direct_production'){
              $pFields['itemTrackInventory'] = 1;
              $waste          = getAllWasteValue($pFields['itemId']);
              $inventoryCount = getProductionCapacity(getCompoundsArray($pFields['itemId']),$allInventory,$waste);
              $itemInventory  = [ 'count' => $inventoryCount ];
            }
            //

            $prodArray["itemId"]          = enc($pFields['itemId']);
            $prodArray["name"]            = toUTF8($pFields['itemName']);
            $prodArray["price"]           = $finalItemPrice;
            $prodArray["index"]           = $z;
            $prodArray["type"]            = iftn($pFields['itemType'],'product');
            $prodArray["kind"]            = $kind;
            $prodArray["itemSort"]          = $itemSort;

            
            if(validity($pFields['itemDateHour'])){
              $prodArray["daysNHours"]      = json_decode( stripslashes($pFields['itemDateHour']), true );
            }
            if(validity($pFields['itemCurrencies'])){
              $prodArray["currencies"]      = json_decode( stripslashes($pFields['itemCurrencies']), true );
            }
            if(validity($tax)){
              $prodArray["tax"]             = $tax;
            }
            if(isset($pFields['itemTags']) && validity($pFields['itemTags']) && $pFields['itemTags'] != '[]'){
              $prodArray["tags"]            = toUTF8($pFields['itemTags']);
            }
            if(validity($pFields['itemTrackInventory'])){
              $prodArray["trackInventory"]  = $pFields['itemTrackInventory'];
            }
            if(validity($itemInventory) && validity($pFields['itemTrackInventory'])){
              $prodArray["inventory"]       = [$itemInventory];
            }
            if(validity($pFields['itemDiscount'])){
              $prodArray["fixedDiscount"]   = $pFields['itemDiscount'];
            }
            if($pFields['itemImage'] == 'true'){
              $prodArray["image"]           = $pFields['itemImage'];
            }
            if(validity($child)){
              $prodArray["children"]        = $child;
            }
            if(validity($pFields['itemParentId'])){
              $prodArray["parentId"]        = enc($pFields['itemParentId']);
            }
            if(validity($pFields['itemIsParent'])){
              $prodArray["isParent"]        = $pFields['itemIsParent'];
            }

            if(validity($pFields['categoryId'])){
              $prodArray["category"]        = $category;
              $prodArray["categoryId"]      = enc($pFields['categoryId']);
            }
            if(validity($brand)){
              $prodArray["brand"]           = $brand;
            }
            if(validity($pFields['itemSKU'])){
              $prodArray["sku"]             = $pFields['itemSKU'];
            }
            if(validity($pFields['itemDuration'])){
              $prodArray["duration"]        = $pFields['itemDuration'];
            }
            if(in_array($kind, ['combo','precombo','comboAddons'])){
              $prodArray["compound"]        = json_encode(displayableCompounds($pFields['itemId']));
            }
            if($kind == 'giftcard'){
              $prodArray["giftCardExpiration"] = toUTF8($pFields['itemDescription']);
            }
            if(validity($pFields['itemUpsellDescription'])){
              $prodArray["upsell"]          = toUTF8($pFields['itemUpsellDescription']);
            }

            if(isset($pData['priceRule']) && validity($pData['priceRule'])){
              $prodArray["priceRule"]       = $pData['priceRule'];
            }
            

            $productsArray[] = $prodArray;

            $products->MoveNext();
            $z++;
          }
          $products->Close();
        }

      }

      echo json_encode($productsArray);

    }
    //PRODUCTS END

    /*$out =  [
              'outlets'   => $outletsIdsArray,
              'registers' => $registersIdsArray,
              'docsNum'   => $docsNumArray,
              'users'     => $userDataArray,
              'customers' => $customersArray,
              'products'  => $productsArray,
              'settings'  => $settingsArray
            ];*/

    
    //echo json_encode($out);

    //print_gzipped_page();
    dai();
  }else{
    jsonDieMsg('nnd');
  }
}else{
  http_response_code(401);
  header('Content-Type: application/json');
  die(json_encode(['error'=>'Invalid data']));
}
?>
