<?php
session_start();
require_once(__DIR__ . '/includes/cors.php');
//ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

ini_set('memory_limit', '2048M');
ini_set('max_execution_time', '700');
ignore_user_abort(false);

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

if(isset($_POST['companyId']) && isset($_POST['outletId'])){
  $rateLimiterId = $_POST['outletId'];

  include_once('head.php');

  $companyId  = $db->Prepare(dec($_POST['companyId']));
  $outletId   = $db->Prepare(dec($_POST['outletId']));

  if(!checkCompanyStatus($companyId)){
    jsonDieMsg('Not found',404);
  }

  define('COMPANY_ID', $companyId);
  define('OUTLET_ID', $outletId);

  session_write_close();
  header('Content-Type: application/json');

  
  //set_time_limit(20);

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

    checkExecTime('## Hasta Plans ' . $companyId);
    
    //USERS
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
                              'permissions'   => getRolePermissions($uFields['role'],COMPANY_ID)
                            ];
      
        //El cero es el register ID, la idea es que al configurar el tablet, baje las opciones de register IDs de la cuenta y se le asigna un register ID al tablet
        $userData->MoveNext();
      }
      $userData->Close();
    }

    checkExecTime('## Hasta Users ' . $companyId);

    //OUTLETS
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
        $open       = $oFields['outletOpenFrom'];
        $close      = $oFields['outletOpenTo'];

        $outletsIdsArray[] = [
                                'outletId'        => enc($oI),
                                'name'            => $oN,
                                'outletAddress'   => $oAddress,
                                'outletPhone'     => $oPhone,
                                'outletRazon'     => $oRazon,
                                'outletRuc'       => $oRuc,
                                'outletOpen'      => $open,
                                'outletClose'     => $close,
                                'attendanceToken' => md5( enc(COMPANY_ID) . enc($oI) ),
                                'outletLatLng'    => toUTF8($oFields['outletLatLng']),
                                'weekHours'       => json_decode(toUTF8($oFields['outletBusinessHours']),true)
                              ];
        
        $outletsIds->MoveNext();
      }
      $outletsIds->Close();
    }

    checkExecTime('## Hasta Outlets ' . $companyId);

    //REGISTER
    $registersIds = ncmExecute("  SELECT *
                                  FROM register
                                  WHERE registerStatus = 1
                                  AND companyId = ? LIMIT " . ($planIt['max_registers'] * $outletCount),
                                  [COMPANY_ID],false,true);


    $registersIdsArray  = [];
    $docsNumArray       = [];

    if($registersIds){
      while (!$registersIds->EOF) {
        $rFields = $registersIds->fields;
        $jrFields = json_decode($rFields['data'] ?? "",true);

        $TODAY_END      = date('Y-m-d 23:59:59');
        $TWO_MONTHS_AGO = date('Y-m-d 00:00:00',strtotime('-2 months'));

        $usedInvoiceNos = ['nos',''];//ncmExecute("SELECT GROUP_CONCAT(invoiceNo) as nos FROM transaction WHERE companyId = ? AND registerId = ? AND transactionType IN(0,3,7) AND transactionDate BETWEEN ? AND ?",[COMPANY_ID, $rFields['registerId'], $TWO_MONTHS_AGO, $TODAY_END]);

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
                                  'returnPrefix'            => $rFields['registerReturnPrefix'] ?? "",
                                  'hotkeys'                 => iftn($rFields['registerHotkeys'],'[]'),
                                  'printers'                => $rFields['registerPrinters'],
                                  'leadingZero'             => $rFields['registerDocsLeadingZeros'],
                                  'usedInvoiceNo'           => explode(',', $usedInvoiceNos['nos']),
                                  'electronicInvoice'       => !is_null($jrFields) ? (array_key_exists("electronicInvoice",$jrFields) ? $jrFields['electronicInvoice'] : "") : ""
                                ];

        if(!validateHttp('lastUpdate','post') || validateHttp('lastUpdate','post') == 'false'){//si es un update no cargo los DOC Nums
          $invoiceNo  = getNextDocNumber($rFields['registerInvoiceNumber'],'0,3',COMPANY_ID,$rFields['registerId']);
          $returnNo   = getNextDocNumber($rFields['registerReturnNumber'],'6',COMPANY_ID,$rFields['registerId']);
          $scheduleNo = getNextDocNumber($rFields['registerScheduleNumber'],'13',COMPANY_ID,$rFields['registerId']);
          $pedidoNo   = getNextDocNumber($rFields['registerPedidoNumber'],'12',COMPANY_ID,$rFields['registerId']);
          $quoteNo    = getNextDocNumber($rFields['registerQuoteNumber'],'9',COMPANY_ID,$rFields['registerId']);

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

        $registersIds->MoveNext();
      }
      $registersIds->Close();
    }

    checkExecTime('## Hasta Registers ' . $companyId);

    //SETTINGS

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
    
    if($settings){

      $settingsFull = json_decode($settings['settingObj'],true);

      $settingsArray[] = [
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
                          'removeTax'             => $settings['settingRemoveTaxes'],
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
                          'loyalty'               => ($_modules['loyalty'] ? $_modules['loyalty'] : false),
                          'storeCredit'           => $settingsFull['settingStoreCredit'],
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
                          'forceCreditLine'       => $settings['settingForceCreditLine'],
                          'upsellList'            => $upsellArray,
                          'mandatoryContact'      => $settings['settingMandatoryContactFields'],
                          'supportLock'           => '0990',
                          'fullSettings'          => json_decode($settings['settingObj'],true),
                          'end'                   => 'is near'
                        ];
    }

    

    checkExecTime('## Hasta Settings ' . $companyId);

    //CUSTOMERS
    if(!validity($lastUpdateApp)){
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
      
        if($_GET['test']){
          //$db->query("SET NAMES 'utf8'");
        }
        
        $customer = ncmExecute("  SELECT contactId, contactName, contactSecondName, contactId, contactTIN, contactCI, contactPhone, contactPhone2, contactEmail, contactBirthDay, contactLoyaltyAmount, contactStatus, type, contactCreditLine, contactStoreCredit
                                  FROM contact 
                                  WHERE companyId = ? 
                                  " . $updated_at . "
                                  ORDER BY contactName ASC
                                  LIMIT " . $planIt['max_customers'],
                                  [COMPANY_ID],30,true);

        //AND contactStatus > 0 
        //AND type        = 1

        checkExecTime('## Hasta Customers SQL ' . $companyId);
      
      if($customer){
        
        
        //creo un arrray con todas las direcciones
        $cAIns = [];
        while (!$customer->EOF) {
          $cFields  = $customer->fields;
          if($cFields['contactStatus'] > 0 && $cFields['type'] == 1){
            $cAIns[] = $cFields['contactId'];
          }
          $customer->MoveNext();
        }
        $customer->MoveFirst();

        forcedAbotion();

        $allAddress     = [];
        $custAddresses  = ncmExecute('SELECT * FROM customerAddress WHERE companyId = ? AND customerAddressDefault = 1 AND customerId IN(' . implodes(',', $cAIns) . ')',[COMPANY_ID],60,true);
        if($custAddresses){
          while (!$custAddresses->EOF) {
            $cAfields = $custAddresses->fields;
            $latLng   = false;
            if($cAfields['customerAddressLat'] && $cAfields['customerAddressLng']){
              $latLng = $cAfields['customerAddressLat'] . ',' . $cAfields['customerAddressLng'];
            }

            $allAddress[$cAfields['customerId']] = [
                                                    'address'   => $cAfields['customerAddressText'],
                                                    'latLng'    => $latLng,
                                                    'location'  => $cAfields['customerAddressLocation'],
                                                    'city'      => $cAfields['customerAddressCity']
                                                    ];
            $custAddresses->MoveNext();
          }
        }

        checkExecTime('## Hasta Customers address ' . $companyId);

        forcedAbotion();
        
        while (!$customer->EOF) {
          $cusArray = [];
          $cFields  = $customer->fields;

          if($cFields['contactStatus'] > 0 && $cFields['type'] == 1){
            //continue;

            $name     = toUTF8($cFields['contactName']);
            if(!$cFields['contactName'] && $cFields['contactSecondName']){
              $name = toUTF8($cFields['contactSecondName']);
            }

            if(validity($cFields['contactId'])){
              $cusArray['customerId']   = enc($cFields['contactId']);
              if(validity($lastUpdateApp)){//si solo quiero acttualizar envio decoded id para match con users recien creados
                $cusArray['customerUnd']   = $cFields['contactId'];
              }
            }
            if(validity($name)){
              $cusArray['name']         = $name;
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
            if(validity($cFields['contactAddress'])){
              $cusArray['address']      = toUTF8($cFields['contactAddress']);
            }
            if(validity($cFields['contactAddress2'])){
              $cusArray['address2']     = toUTF8($cFields['contactAddress2']);
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
            if(validity($cFields['contactCity'])){
              $cusArray['city']         = $cFields['contactCity'];
            }
            if(validity($cFields['contactLocation'])){
              $cusArray['location']      = $cFields['contactLocation'];
            }
            if(validity($cFields['contactCountry'])){
              $cusArray['country']      = $cFields['contactCountry'];
            }
            if(validity($cFields['contactLatLng'])){
              $cusArray['latLng']      = $cFields['contactLatLng'];
            }

            $custAddrs = $allAddress[$cFields['contactId']];

            if($custAddrs){
              $cusArray['latLng']      = $custAddrs['latLng']; 
              $cusArray['location']    = $custAddrs['location'];
              $cusArray['city']        = $custAddrs['city'];
              $cusArray['address']     = $custAddrs['address'];
              $cusArray['address2']    = '';
            }
            
            if(validity($cFields['contactStoreCredit'])){
              $cusArray['storeCredit']  = $cFields['contactStoreCredit'];
            }
            if(validity($cFields['contactCreditLine'])){
              $cusArray['creditLine']   = getContactCreditLine($cFields['contactId'],$cFields['contactCreditLine']);
            }
            if(validity($cFields['categoryId'])){
              $cusArray['category']   = $cFields['categoryId'];
            }

            $customersArray[] = $cusArray;

          }
          
          $customer->MoveNext();
        }

        $customer->Close();
      }
    }
    //CUSTOMERS END

    checkExecTime('## Hasta Customers Loop ' . $companyId);

    //PRODUCTS
    if(!validity($lastUpdateApp)){
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
      forcedAbotion();
      $limit        = " LIMIT " . $planIt['max_items'];
      $order        = 'ASC';
      $child        = [];
      $childrenIds  = getAllCompanyItemsChildren(COMPANY_ID);
      $allTaxonomy  = getAllTaxonomyNames(COMPANY_ID);
      $decimal      = ncmExecute('SELECT settingDecimal FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
      
      $products     = ncmExecute("SELECT * FROM item WHERE companyId = ? AND itemStatus = 1 AND itemCanSale = 1 AND (outletId = ? OR outletId IS NULL OR outletId = 0)" . $updated_at . " ORDER BY itemDate " . $order . $limit, [COMPANY_ID,OUTLET_ID],30,true);

      $catsIds      = getCategoriesIds(COMPANY_ID);
      $categorize   = iftn($catsIds,''," AND (categoryId IN(" . $catsIds . ") OR categoryId IS NULL)");

      checkExecTime('## Hasta Products SQL ' . $companyId);
      
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

          if($pFields['itemIsParent'] != 0){
            $child = $childrenIds[enc($pFields['itemId'])];
          }else{
            $child = [];
          }

          if($decimal['settingDecimal'] == 'no'){//solución para los que cargaban productos con decimales y luego cambiaron
            $finalItemPrice   = round($pFields['itemPrice']);
          }else{
            $finalItemPrice   = $pFields['itemPrice'];
          }

          if($pFields['itemPriceType']){//si el precio es porcentual al costo
            $cogs = $allInventory[$pFields['itemId']]['cogs'];
            if($pFields['itemPricePercent'] < 1){
              $finalItemPrice = $cogs;
            }else{
              $addPrice = ($cogs * $pFields['itemPricePercent']) / 100;
              $finalItemPrice = $cogs + $addPrice;
            }
          }
          
          $brand          = toUTF8($allTaxonomy[$pFields['brandId']]['name']);
          $category       = toUTF8($allTaxonomy[$pFields['categoryId']]['name']);
          $tax            = $allTaxonomy[$pFields['taxId']]['name'];

          $inventoryCount = $allInventory[$pFields['itemId']]['onHand'];
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
            }else if($pFields['itemType'] == 'product' && $pFields['itemTrackInventory'] < 1 && $pFields['compoundId'] != ''){
              $kind = 'direct_production';
            }
          }
          //

          $prodArray["itemId"]          = enc($pFields['itemId']);
          $prodArray["name"]            = toUTF8($pFields['itemName']);
          $prodArray["price"]           = $finalItemPrice;
          $prodArray["index"]           = $z;
          $prodArray["type"]            = $pFields['itemType'];
          $prodArray["kind"]            = $kind;

          if(validity($tax)){
            $prodArray["tax"]             = $tax;
          }
          if(validity($pFields['itemTags']) && $pFields['itemTags'] != '[]'){
            $prodArray["tags"]            = $pFields['itemTags'];
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
            $prodArray["giftCardExpiration"] = $pFields['itemDescription'];
          }
          if(validity($pFields['itemUpsellDescription'])){
            $prodArray["upsell"]          = $pFields['itemUpsellDescription'];
          }

          $productsArray[] = $prodArray;

          $products->MoveNext();
          $z++;
        }
        $products->Close();
      }

    }
    //PRODUCTS END

    checkExecTime('## Hasta Products Loop' . $companyId);

    $out =  [
              'outlets'   => $outletsIdsArray,
              'registers' => $registersIdsArray,
              'docsNum'   => $docsNumArray,
              'users'     => $userDataArray,
              'customers' => $customersArray,
              'products'  => $productsArray,
              'settings'  => $settingsArray
            ];

    
    echo json_encode($out);
    checkExecTime('## Hasta Print ' . $companyId);
    //print_gzipped_page();
    dai();
  }else{
    jsonDieMsg('No new data');
  }
}else{
  http_response_code(401);
  header('Content-Type: application/json');
  die(json_encode(['error'=>'Invalid data']));
}
?>