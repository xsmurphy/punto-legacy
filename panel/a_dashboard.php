<?php
  include_once('includes/top_includes.php');

  topHook();
  allowUser('dashboard','view');

  $MAX_DAYS_RANGE = 31;
  $baseUrl  = '/' . basename(__FILE__,'.php');
  $roc      = getRoc(1);

  list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(0);
  $daysInterval = count($calendar);

  //DATE RANGE LIMITS FOR REPORTS
  $maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
  if(!$maxDate){
    $startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
  }
  //

  $dashCache = $_SESSION['ncmCache']['dashboard'][OUTLET_ID][$startDate . $endDate];

  //NOTIFY
  if(validateHttp('widget') == 'notifications'){
    if(!$plansValues[PLAN]['notify']){
     // dai('not allowed');
    }

    $type = iftn(validateHttp('type'),'notes');
    $data = [
              'api_key'     => API_KEY,
              'company_id'  => enc(COMPANY_ID),
              'user'        => enc(USER_ID), 
              'type'        => $type,
              'outlet'      => enc(OUTLET_ID)
            ];
    $result = curlContents(API_URL . '/get_notifications','POST',$data);
    //error_log(print_r($result,true));
    if($result){
      header('Content-Type: application/json;'); 
      echo $result;
    }
    dai();
  }

  if(validateHttp('widget') == 'notificationsCount'){
    if(!$plansValues[PLAN]['notify']){
      //dai('not allowed');
    }

    $type = iftn(validateHttp('type'),'notes');
    $data = [
              'api_key'     => API_KEY,
              'company_id'  => enc(COMPANY_ID),
              'user'        => enc(USER_ID),
              'type'        => $type,
              'outlet'      => enc(OUTLET_ID)
            ];
    $result = curlContents(API_URL . '/get_notifications_count','POST',$data);
    if($result){
      header('Content-Type: application/json;'); 
      echo $result;
    }
    dai();
  }
  //NOTIFY END

  //REMINDER
  if(validateHttp('widget') == 'getReminders'){
    header('Content-Type: application/json;'); 
    if(COMPANY_ID == 10){ // TODO: replace integer 10 with company UUID
      echo json_encode([
          ['note'=>'Este es otro recordatorio pero malo','type'=>'danger'],
          ['note'=>'Este es un recordatorio genial sobre el vencimiento del producto Rubbble el 25 de Junio, 2019','type'=>'default']
          
        ]);
    }
    dai();
  }

  if(validateHttp('widget') == 'customers'){
    //theErrorHandler('json');

    if(validateHttp('week')){
      $startDate  = date('Y-m-d 00:00:00', strtotime('-1 week'));
      $endDate    = date('Y-m-d 23:59:59');
    }

    if(validateHttp('prev')){
      $startF     = strtotime($startDate);
      $endF       = strtotime($endDate);
      $diference  = $endF - $startF;
      $startDate  = date('Y-m-d H:i:00',($startF - $diference));
      $endDate    = date('Y-m-d H:i:00',($endF - $diference));
    }  

    $totalC = ncmExecute(
                          "SELECT COUNT(contactId) as totalcount
                          FROM contact 
                          WHERE type = 1 AND " . $SQLcompanyId,[],true
                        );

    $total = 0;
    if($totalC['totalcount']){
      $total = $totalC['totalcount'];
    }

    //TENER EN CUENTA QUE AQUI CUENTO TODOS LOS CLIENTES NUEVOS SIN IMPORTAR SI INGRESARON POR ORDEN, COTIZACION O LO QUE SEA NO SOLO POR VENTAS
    $newC = ncmExecute("
                        SELECT COUNT(contactId) as totalnew
                        FROM contact 
                        WHERE type = 1 
                        AND contactDate 
                        BETWEEN ? 
                        AND ? 
                        AND " . $SQLcompanyId, 
                        [$startDate,$endDate],true
                      );

    $new = 0;
    if($newC['totalnew']){
      $new = $newC['totalnew'];
    }

    $oldRoc   = str_replace(['outletId','companyId'], ['a.outletId','a.companyId'], $roc);
    $oldC     = $db->Execute(
                                "SELECT 
                                COUNT(a.customerId) as totalold
                                FROM transaction a, contact b
                                WHERE a.transactionDate
                                BETWEEN ?
                                AND ?
                                AND (a.customerId IS NOT NULL AND a.customerId > 1)
                                " . $oldRoc . "
                                AND a.transactionType IN(0,3)
                                AND a.customerId = b.contactId
                                AND b.contactDate < ?
                                AND b.type = 1
                                GROUP BY a.customerId"
                              ,
                              [$startDate,$endDate,$startDate],true
                            );

    $oldCount = validateResultFromDB($oldC,true);

    $old = 0;
    if($oldCount){
      $old = $oldCount;
    }

    $recurring = $old;
    $recurring = ($recurring < 0) ? 0 : $recurring;

    $out = [
              'total'       => formatCurrentNumber($total,'no'),
              'totalPeriod' => formatCurrentNumber(($new + $recurring),'no'),
              'new'         => formatCurrentNumber($new,'no'),
              'old'         => formatCurrentNumber($recurring,'no'),
              'returnRate'  => round(divider($recurring, ($new + $recurring), true) * 100, 2)
            ];

    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'paymentStatus'){
    theErrorHandler('json');

    if( validity($dashCache['paymentStatus']) ){
      jsonDieResult($dashCache['paymentStatus']);
    }

    $db->cacheSecs    = 3600;
    $allTransactions  = getAllTransactions(true);
    $payTransactions  = getAllToPayTransactions(true);

    $totalContado     = 0;
    $totalCocount     = 0;
    $totalCredito     = 0;
    $totalCcount      = 0;
    $totalPorCobrar   = 0;
    $totalPorcount    = 0;
    $totalCobrado     = 0;
    $totalCobcount    = 0;
    $isCreditSale     = false;
    $extraHtml        = '';

    foreach($allTransactions as $trans){
      if($trans['type'] == '3'){
        $tTotal = $trans['total'] - $trans['discount'];
        $tPayed = $payTransactions[$trans['id']];
        $topay  = ($tTotal - $tPayed);

        $totalCobrado += $tPayed;

        if($topay <= 0){
          $totalCobcount++;
        }

        if($topay > 0){
          $totalPorcount++;
        }

        $totalCredito += $tTotal;
        $totalCcount++;
        $isCreditSale  = true;
      }else{
        $totalContado += $trans['total'] - $trans['discount'];
        $totalCocount++;
      }
    }

    $totalPorCobrar = $totalCredito - $totalCobrado;

    $out =  [
              'contado'       => $totalContado,
              'contadoF'      => formatCurrentNumber($totalContado),
              'credito'       => $totalCredito,
              'creditoF'      => formatCurrentNumber($totalCredito),
              'cobrado'       => $totalCobrado,
              'cobradoF'      => formatCurrentNumber($totalCobrado),
              'porcobrar'     => $totalPorCobrar,
              'porcobrarF'    => formatCurrentNumber($totalPorCobrar),
              'contadoCount'  => $totalCocount,
              'creditoCount'  => $totalCcount,
              'cobradoCount'  => $totalCobcount,
              'porcobrarCount'=> $totalPorcount
            ];

    $dashCache['paymentStatus'] = $out;

    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'info'){
    theErrorHandler('json');

    $giftCards = ncmExecute("SELECT COUNT(*) as count FROM giftCardSold WHERE transactionId IS NOT NULL AND giftCardSoldValue > 0 " . $roc,[],true);

    $users    = ncmExecute("SELECT COUNT(*) as count FROM contact WHERE type = 0 AND companyId = ?",[COMPANY_ID],true);
    $items    = ncmExecute("SELECT COUNT(*) as count FROM item WHERE companyId = ?",[COMPANY_ID],true);
    $drawers  = ncmExecute("SELECT COUNT(*) as count FROM drawer WHERE (drawerCloseDate IS NULL OR drawerCloseDate < '2010-01-01 00:00:00') " . $roc . " limit 10");

    $startD   = date('Y-m-01 00:00:00');
    $endD     = date('Y-m-t 23:59:59');

    $trans    = ncmExecute("SELECT COUNT(*) as count FROM transaction WHERE companyId = ? AND transactionDate BETWEEN ? AND ?",[COMPANY_ID,$startD,$endD],true);



    //$inventario     = getAllInventoryAndItemsModule();
    $out            = [
                        'giftCardsCount'    => $giftCards['count'],
                        'openDrawersCount'  => ($drawers['count']) ? $drawers['count'] : '0',
                        'outletsCount'      => OUTLETS_COUNT,
                        'plan'              => $plansValues[PLAN]['name'],
                        'usersCount'        => formatQty($users['count']) . '/' . formatQty($plansValues[PLAN]['max_users'] * OUTLETS_COUNT),
                        'itemsCount'        => formatQty($items['count']) . '/' . formatQty($plansValues[PLAN]['max_items']),
                        'transactionsCount' => formatQty($trans['count'])
                      ];
    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'incomeOutcomeStats'){
    theErrorHandler('json');

    if( validity($dashCache['incomeOutcomeStats']) ){
      jsonDieResult($dashCache['incomeOutcomeStats']);
    }

    if(validateHttp('week')){
      $startDate  = date('Y-m-d 00:00:00', strtotime('-1 week'));
      $endDate    = date('Y-m-d 23:59:59');
    }

    if(validateHttp('prev')){
      $startF     = strtotime($startDate);
      $endF       = strtotime($endDate);
      $diference  = $endF - $startF;
      $startDate  = date('Y-m-d H:i:00',($startF-$diference));
      $endDate    = date('Y-m-d H:i:00',($endF-$diference));
    }

    $COGS         = 0;//getItemsCOGS($startDate,$endDate,false,false,true);
    $finalTotal   = 0;
    $divid        = 0;
    $totalExpenses= 0;
    $margen       = 100;
    $customerAverage= 0;

    $result = ncmExecute("SELECT SUM(transactionTotal) as total, 
      SUM(transactionDiscount) as discount, 
      SUM(transactionUnitsSold) as units,
      COUNT(transactionId) as count
      FROM transaction FORCE INDEX(transactionType,transactionDate)
      WHERE transactionType IN(0,3,6)
      AND transactionDate >= ? 
      AND transactionDate <= ?
      " . $roc
      ,[$startDate,$endDate],(($daysInterval > 8) ? true : false)); 

    if($result){
      $internals = lessInternalTotals($roc,$startDate,$endDate);
      
      $divid      = ($lessDays < 1) ? 1 : $lessDays;
      $finalTotal = ($result['total'] - $result['discount']) - $internals['total'];
      $customerAverage = divider($result['total'],$result['count'],true);
    }

    $expen = ncmExecute("SELECT SUM(transactionTotal) as total,
      SUM(transactionDiscount) as discount, 
      SUM(transactionUnitsSold) as units,
      COUNT(transactionId) as count
      FROM transaction FORCE INDEX(transactionType,transactionDate)
      WHERE transactionType IN(1,4)
      AND transactionDate >= ? 
      AND transactionDate <= ?
      AND transactionStatus = 1
      " . $roc
      ,[$startDate,$endDate],true);

    if($expen){
      $totalExpenses = $expen['total'];
    }

    $revenue = $finalTotal - $totalExpenses;

    if($finalTotal > 0 && $totalExpenses > 0){
      $margen = ( $revenue / $finalTotal ) * 100;
      $margen = ($margen < 0) ? 0 : $margen;
    }

    $out = [
              "total"             => floatval($finalTotal),
              "totalF"            => formatCurrentNumber($finalTotal),
              "expenses"          => floatval($totalExpenses),
              "expensesF"         => formatCurrentNumber($totalExpenses),
              "revenue"           => $revenue,
              "revenueF"          => formatCurrentNumber($revenue),
              "margin"            => floatval(round($margen)),
              "marginF"           => round($margen) . '%',
              "count"             => floatval($result['count']),
              "countF"            => formatCurrentNumber($result['count'],'no'),
              "customerAverage"   => $customerAverage,
              "customerAverageF"  => formatCurrentNumber($customerAverage)
            ];

    $dashCache['incomeOutcomeStats'] = $out;

    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'customersRates'){
    $out = getCustomersRate($startDate,$endDate,COMPANY_ID);
    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'topHours'){
    if( validity($dashCache['topHours']) ){
      jsonDieResult($dashCache['topHours']);
    }

    $result   = ncmExecute("SELECT 
                  transactionDate,
                  COUNT(transactionId) as total,
                  SUM(transactionUnitsSold) as units,
                  HOUR(transactionDate) as hora
                  FROM transaction FORCE INDEX(transactionType,transactionDate)
                  WHERE transactionType IN (0,3)
                  AND transactionDate 
                  BETWEEN ?
                  AND ? 
                   " . $roc . "
                  GROUP BY hora
                  ORDER BY units DESC LIMIT 6",[$startDate,$endDate],true,true);
    $hour   = [];
    $total  = [];

    if($result){
      while (!$result->EOF) {
        $timestamp  = strtotime($result->fields['transactionDate']);
        $hour[]     = date("H:00", $timestamp) . ' Ventas';
        $total[]    = round( $result->fields['total'],2 );

        $result->MoveNext();
      }
    }

    $out =   [
          'hour'  => $hour,
          'total' => $total
          ];

    $dashCache['topHours'] = $out;

    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'topItems'){
    if( validity($dashCache['topItems']) ){
      jsonDieResult($dashCache['topItems']);
    }

    $chart    = '';
    $list     = '';
    $out      = [];

    $roc       = str_replace(['outletId','companyId'],['b.outletId','b.companyId'],$roc);

    $sql = "SELECT  a.itemId as id, 
              SUM(a.itemSoldUnits) as count, 
              SUM(a.itemSoldTotal) as total 
            FROM itemSold a, transaction b
            WHERE b.transactionType IN (0,3)
            AND b.transactionDate BETWEEN ? AND ? 
            " . $roc . "
            AND a.transactionId = b.transactionId
            AND a.itemSoldTotal > 0
            GROUP BY id ORDER BY count DESC LIMIT 5";



    $result    = ncmExecute($sql,[$startDate,$endDate],true,true);

    if($result){
     while (!$result->EOF) {
      $item   = getItemData($result->fields['id']);
      $out[]  = [
                  'name'  => truncate(toUTF8($item['itemName']), 4),
                  'count' => formatQty($result->fields['count']),
                  'total' => formatCurrentNumber($result->fields['total'])
                ];

       $barLabel[] = round($result->fields['count']);
       $barData[]  = addslashes($item['itemName']);

       $result->MoveNext(); 
     }

     $result->Close();  
    }

    $dashCache['topItems'] = $out;

    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'topCategories'){
    $top    = getTopCategories($startDate,$endDate,10,true);
    $out    = [];

    foreach($top as $name => $amount){
      $name = ($name == 'None') ? 'Sin categoría' : $name;
      $out[] = ['title' => $name, 'total' => round($amount, 2)];
    }

    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'topBrands'){
    $top    = getTopBrands($startDate,$endDate,10,true);
    $out    = [];

    foreach($top as $name => $amount){
      $name   = ($name == 'None') ? 'Sin marca' : $name;
      $out[]  = ['title' => $name, 'total' => round($amount, 2)];
    }

    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'topPayments'){
    if( validity($dashCache['topPayments']) ){
      jsonDieResult($dashCache['topPayments']);
    }

    $top    = getSalesByPayment($startDate,$endDate,$roc);
    $out    = [];

    usort($top, function($a, $b) {
        return (float)$b['price'] - (float)$a['price'];
    });

    foreach($top as $methd){
      $out['title'][]   = toUTF8(getPaymentMethodName($methd['type']));
      $out['amount'][]  = $methd['price'];
    }

    $dashCache['topPayments'] = $out;

    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'satisfaction'){
    if(!validity($_modules['feedback'])){
      jsonDieResult([]);
    }
    $uno = ncmExecute('SELECT satisfactionLevel as level, COUNT(*) as count FROM satisfaction WHERE satisfactionLevel = 1 AND satisfactionDate BETWEEN ? AND ? ' . $roc ,[$startDate,$endDate]);
    $dos = ncmExecute('SELECT satisfactionLevel as level, COUNT(*) as count FROM satisfaction WHERE satisfactionLevel = 2 AND satisfactionDate BETWEEN ? AND ? ' . $roc ,[$startDate,$endDate]);
    $tres = ncmExecute('SELECT satisfactionLevel as level, COUNT(*) as count FROM satisfaction WHERE satisfactionLevel = 3 AND satisfactionDate BETWEEN ? AND ? ' . $roc ,[$startDate,$endDate]);

    $face = '🤬';
    $bg   = 'gradBgRed';


    //echo 'SELECT satisfactionLevel as level, COUNT(*) as count FROM satisfaction WHERE satisfactionLevel = 1 AND satisfactionDate BETWEEN '.$startDate.' AND '.$endDate.' ' . $roc;

    /*$percent = satisfactionToPercent($uno['count'],$dos['count'],$tres['count']);

    if($percent > 80){
      $face = '😀';
      $bg   = 'gradBgGreen';
    }else if($percent > 50){
      $face = '😐';
      $bg   = 'gradBgYellow';
    }*/

    $total  = (float) $uno['count'] + (float) $dos['count'] + (float) $tres['count'];

    $unoP    = round( divider($uno['count'],$total,true) * 100 );//formatQty( ($uno['count'] * 100) / $total );
    $dosP    = round( divider($dos['count'],$total,true) * 100 );//formatQty( ($dos['count'] * 100) / $total );
    $tresP   = round( divider($tres['count'],$total,true) * 100 );//formatQty( ($tres['count'] * 100) / $total );

    $out    =   [
                  'detractors'  => ['percent' => $unoP, 'count'  => $uno['count']],
                  'passives'    => ['percent' => $dosP, 'count'  => $dos['count']],
                  'promoters'   => ['percent' => $tresP, 'count' => $tres['count']]
                ];
    
    jsonDieResult($out);
  }

  if(validateHttp('widget') == 'orders'){
    if(!validity($_modules['ordersPanel'])){
      jsonDieResult([]);
    }

    $occupacy     = 100;

    $total        = ncmExecute('SELECT COUNT(*) as count FROM transaction WHERE transactionType = 12 AND transactionStatus IN(0,1,2,3,5) ' . $roc);
    $online       = ncmExecute("SELECT COUNT(*) as count FROM transaction WHERE transactionType = 12 AND transactionStatus IN(0,1,2,3,5) AND transactionName = 'ecom' " . $roc);

    $ordersCount  = $total['count'];
    $onlineCount  = $online['count'];

    jsonDieResult([
                    'ordersCount' => $ordersCount,
                    'onlineCount' => $onlineCount
                  ]);

  }

  if(validateHttp('widget') == 'tables'){
    if(!validity($_modules['tables'])){
      jsonDieResult([]);
    }

    $occupacy     = 100;

    $count        = ncmExecute('SELECT COUNT(*) as count FROM transaction WHERE transactionType = 11 AND transactionName > 0 ' . $roc);

    $occupTables  = $count['count'];
    $totalTables  = $_modules['tablesCount'] ? $_modules['tablesCount'] : 90;
    $freeTables   = $totalTables - $count['count'];

    if($totalTables){
      $occupacy     =  ($occupTables * 100) / $totalTables;
    }

    if(!$occupTables){
      $occupacy     =  0;
    }

    jsonDieResult([
                    'tablesCount' => $occupTables,
                    'totalTables' => $totalTables,
                    'occupacy'    => round($occupacy),
                    'freeTables'  => $freeTables
                  ]);

  }

  if(validateHttp('widget') == 'schedule'){
    if(!validity($_modules['calendar'])){
      jsonDieResult([]);
    }

    $active = ncmExecute('SELECT * FROM transaction WHERE transactionType = 13 AND (fromDate >= ? AND toDate <= ?) AND transactionStatus IN(0,1,2,3,6,7) ' . $roc,[$startDate,$endDate],false,true);

    $workingHours       = 0;
    $blockedHours       = 0;
    $scheduledCount     = 0;
    $out                = [];

    $days               = getTimeDifference($startDate,$endDate);

    if($active){
      while (!$active->EOF) {
        $field      = $active->fields;
        $difference = getTimeDifference($field['fromDate'],$field['toDate']);

        if($field['transactionStatus'] == 7){
          $blockedHours   += $difference->h;
        }else{
          $workingHours   += $difference->h;
          $scheduledCount++;
        }

        $active->MoveNext(); 
      }
    }

    $agendables = ncmExecute('SELECT COUNT(*) as count FROM contact WHERE type = 0 AND contactStatus > 0 AND contactInCalendar > 0 AND companyId = ? AND (outletId = ? OR outletId = 0 OR outletId IS NULL)',[COMPANY_ID,OUTLET_ID],true);

    $timeOpen = getTimeDifference('2020-01-01 ' . $_cmpSettings['settingOpenFrom'] . ':00','2020-01-01 ' . $_cmpSettings['settingOpenTo'] . ':59');
    $shift    = ($timeOpen->h * $agendables['count']) * iftn($days->d,1);

    $out['scheduledCount']  = $scheduledCount;//cantidad reservas
    $out['occupancy']       = ($workingHours > 0) ? round( divider( ($workingHours * 100), $shift, true ) ) : 0;//% ocupación
    $out['shiftHours']      = $shift;//durecion
    $out['workingHours']    = $workingHours;//horas agendadas
    $out['freeHours']       = $shift - $workingHours;//horas free
    $out['blockedHours']    = $blockedHours;//horas bloqueadas

    jsonDieResult($out);
  }
  ?>


      <?=headerPrint();?>

        <div class="row">
          <div class="col-sm-8 col-xs-12 m-t">

            <div class="col-sm-9 col-xs-12 no-padder">
              <span class="h2 font-bold hidden-print" id="pageTitle">
                Resumen general de su negocio
                <a href="#" class="hidden-print m-l iguiderStart"  data-toggle="tooltip" title="Hacer un tour" data-placement="bottom">
                  <span class="material-icons text-info m-b-xs">live_help</span>
                </a>
              </span>
            </div>

            <div class="col-sm-3 col-xs-12 no-padder">
              <span class="yowhatsnew pull-right hidden-print hidden-xs" style="display: flex;">
                <a href="javascript:;" class="changloglink" style="margin-top: 8px;">¿Qué hay de nuevo?</a>
              </span>
            </div>

          </div>
          <div class="col-sm-4 col-xs-12 text-right m-t m-b hidden-print">
            <form action="" class="" method="post" id="manualDate" name="manualDate">
              <input type="text" id="customDateR" class="form-control no-border bg-white font-bold pointer text-center rounded needsclick" name="range" value="" data-max="<?=$MAX_DAYS_RANGE;?>"/>
            </form>
          </div>
        </div>

        <?php
         if(PLAN == 0){
         ?>
         <div class="col-xs-12">
           <div class="col-sm-2"></div>
           <div class="col-sm-8 text-center"><?=plansTables()?></div>
           <div class="col-sm-2"></div>
         </div>
         <?php
         }else{
         ?>
          <div id="sortable" class="row">

            <div class="col-md-8 col-sm-6 col-xs-12 m-b" id="incomeOutcomeStatsWidget">
              
              <div class="row">
                <div class="col-md-6 col-xs-12 m-b">
                  <div class="r-24x wrapper bg-info gradBgBlue" id="totalIncome">
                    <div class="text-u-c">
                      Ingresos
                    </div>
                    <div class="h1 font-bold">
                      <?=placeHolderLoader(13)?>
                    </div>
                  </div>
                </div>
              
                <div class="col-md-6 col-xs-12 m-b">
                  <div class="r-24x wrapper bg-white" id="totalOutcome">
                    <div class="text-u-c">
                      Egresos
                    </div>
                    <div class="h1 font-bold">
                      <?=placeHolderLoader(13)?>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row hidden" id="myChartHolder">
                <div class="col-md-8 col-sm-12 col-xs-12 m-b no-padder">
                  <div class="r-24x">
                    <canvas id="summaryChart" height="200" style="width: 100%; height: 200px;"></canvas>
                  </div>
                </div>

                <div class="col-md-4 col-sm-12 col-xs-12 m-b">
                  <div class="col-xs-12 wrapper bg-dark m-b r-24x clear text-center">
                    <h3 class="font-bold m-t-xs m-b-xs salesRevenue">...</h3>
                    <div>
                      <span class="salesRevenueArrow">...</span> Ganancias
                    </div>
                  </div>

                  <div class="col-xs-12 wrapper bg-dark m-b r-24x clear">
                    <div class="col-xs-6 no-padder text-center b-r b-dark">
                      <h3 class="font-bold m-t-xs m-b-xs salesMargin">...</h3>
                      <div>
                        <span class="salesMarginArrow">...</span> Margen
                      </div>
                    </div>

                    <div class="col-xs-6 no-padder text-center">
                      <h3 class="font-bold m-t-xs m-b-xs salesCount">...</h3>
                      <div>
                        <span class="salesCountArrow">...</span> Cant. Ventas
                      </div>
                    </div>
                  </div>

                </div>
              </div>
              

              <div class="row hidden" id="paymentStatusWidget">
                <div class="col-md-6 col-xs-12 m-b">
                  <div class="col-xs-12 text-center wrapper bg-white r-24x" id="salesType">
                    <?=placeHolderLoader('chart')?>
                  </div>
                </div>

                <div class="col-md-6 col-xs-12 m-b">
                  <div class="col-xs-12 text-center wrapper bg-white r-24x" id="creditSales">
                    <?=placeHolderLoader('chart')?>
                  </div>
                </div>
              </div>

              <div class="row">

                <div class="col-md-6 col-xs-12 m-b">
                  <div class="col-xs-12 wrapper clear bg-white r-24x m-b">
                    <div class="h4 font-bold m-b">
                      Clientes
                      <a href="/@#report_customers" class="pull-right hidden-print">
                        <i class="material-icons md-24">keyboard_arrow_right</i>
                      </a>
                    </div>
                    <div class="col-xs-6 text-center no-padder b-r b-light">
                      <div class="h1 font-bold">
                        <span class="customersNew">...</span>
                      </div>
                      <div><span class="customersNewArrow">...</span> Nuevos</div>
                    </div>
                    <div class="col-xs-6 text-center no-padder">
                      <div class="h1 font-bold">
                        <span class="customersOld">...</span>
                      </div>
                      <div><span class="customersOldArrow">...</span> Recurrentes</div>
                    </div>
                  </div>

                  <div class="col-xs-12 wrapper bg-white m-b r-24x clear">
                    <img src="" width="60" class="pull-left m-l-n retentionRateImg">
                    <span class="pull-left m-t-sm">Tasa de Retención</span>
                    <h3 class="font-bold pull-right m-t-xs m-b-n retentionRate">...</h3>
                  </div>
                  <div class="col-xs-12 wrapper bg-white m-b r-24x clear">
                    <img src="" width="60" class="pull-left m-l-n growthRateImg">
                    <span class="pull-left m-t-sm">Tasa de Crecimiento</span>
                    <h3 class="font-bold pull-right m-t-xs m-b-n growthRate">...</h3>
                  </div>
                  <div class="col-xs-12 wrapper bg-white m-b r-24x clear">
                    <img src="" width="60" class="pull-left m-l-n churnRateImg">
                    <span class="pull-left m-t-sm">Tasa de pérdida (Churn)</span>
                    <h3 class="font-bold pull-right m-t-xs m-b-n churnRate">...</h3>
                  </div>
                </div>

                <div class="col-md-6 col-xs-12 m-b">
                  <div class="col-xs-12 wrapper panel m-n r-24x clear" id="topItems" style="min-height: 380px;">
                    <div class="h4 font-bold m-b">
                      Top 5 Artículos 
                      <a href="/@#report_products" class="pull-right hidden-print">
                        <i class="material-icons md-24">keyboard_arrow_right</i>
                      </a>
                    </div>
                    
                    <table class="table no-border">
                      <tbody>
                        <?=placeHolderLoader('table-sm')?>
                      </tbody>
                    </table>
                    
                  </div>
                </div>
                
              </div>

              <div class="row">

                <div class="col-md-6 col-xs-12 m-b">
                  <div class="col-xs-12 wrapper bg-white m-n r-24x clear" style="min-height: 250px;">
                    <div class="h4 font-bold m-b">
                      Horarios Pico
                      <a href="/@#report_summary" class="pull-right hidden-print">
                        <i class="material-icons md-24">keyboard_arrow_right</i>
                      </a>
                    </div>
                    <div id="topHours">
                      <canvas id="topHoursChart" height="250" style="max-height:250px;"></canvas>
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-xs-12 m-b">
                  <div class="col-xs-12 wrapper bg-white m-n r-24x clear" style="min-height: 250px;">
                    <div class="h4 font-bold m-b">
                      Top 10 Categorías 
                      <a href="/@#report_categories" class="pull-right hidden-print">
                        <i class="material-icons md-24">keyboard_arrow_right</i>
                      </a>
                    </div>
                    <div id="topCategories">
                      <canvas id="topCategoriesChart" height="250" style="max-height:250px;"></canvas>
                    </div>
                  </div>
                </div>    

              </div>

              <div class="row">
                

              </div>

            </div>

            <div class="col-md-4 col-sm-6 col-xs-12">

              
              <?php
              if($_modules['feedback']){
              ?>
              <div class="col-xs-12 no-bg no-padder" id="customerSatisfactionLevel">
                <div class="h4 font-bold m-b">
                  Nivel de satisfacción de clientes o <a href="/preguntas-frecuentes/panel-de-control/que-es-satisfaccion-del-cliente-o-net-promoter-score-nps" target="_blank"> <span class="font-normal text-u-l">(NPS)</span></a>
                  <a href="/@#report_satisfaction" class="pull-right hidden-print">
                    <i class="material-icons md-24">keyboard_arrow_right</i>
                  </a>
                </div>
                <div class="progress progress-xs dker progress-striped"> 
                  <div class="progress-bar gradBgRed satisfactionBarDetractors" data-toggle="tooltip" data-original-title="0%" style="width: 0%"></div> 

                  <div class="progress-bar gradBgYellow satisfactionBarPassives" data-toggle="tooltip" data-original-title="0%" style="width: 0%"></div> 

                  <div class="progress-bar gradBgGreen satisfactionBarPromoters" data-toggle="tooltip" data-original-title="0%" style="width: 0%"></div> 

                </div>
              </div>
              <?php
              }
              ?>

              <div class="col-xs-12 m-b-md r-24x wrapper bg-dark text-center hidden pointer b-l b-4x b-info" id="orders">
                <div class="col-xs-6 no-padder">
                  <div class="text-u-c">Órdenes</div>
                  <div class="h1 font-bold text-info">
                    <?=placeHolderLoader(4)?>
                  </div>
                </div>
                <div class="col-xs-6 no-padder">
                  <div class="text-u-c">Online</div>
                  <div class="h1 font-bold text-info">
                    <?=placeHolderLoader(3)?>
                  </div>
                </div>
              </div>

              <?php
              if($_modules['tables']){
              ?>
              <div class="col-xs-12 m-b-md r-24x wrapper bg-dark text-center pointer b-l b-4x b-success" id="tables">
                <div class="col-xs-6 no-padder">
                  <div class="text-u-c">Espacios ocupados</div>
                  <div class="h1 font-bold text-success">
                    <?=placeHolderLoader(4)?>
                  </div>
                  <div class="wrap-r m-t text-left">
                    <?=placeHolderLoader(10)?>
                  </div>
                </div>
                <div class="col-xs-6 no-padder">
                  <div class="text-u-c">Ocupación</div>
                  <div class="h1 font-bold text-success">
                    <?=placeHolderLoader(3)?>
                  </div>

                  <div class="wrap-l m-t text-left">
                    <?=placeHolderLoader(10)?>
                  </div>
                </div>
              </div>
              <?php
              }
              ?>

              <?php
              if($_modules['calendar']){
              ?>
              <div class="col-xs-12 m-b-md r-24x wrapper bg-dark text-center pointer b-l b-4x b-primary" id="schedule">
                <div class="col-xs-6 no-padder">
                  <div class="text-u-c">Reservas</div>
                  <div class="h1 font-bold text-primary">
                    <?=placeHolderLoader(4)?>
                  </div>
                  <div class="wrap-r m-t text-left">
                    <?=placeHolderLoader(10)?>
                    <br>
                    <?=placeHolderLoader(10)?>
                  </div>
                </div>
                <div class="col-xs-6 no-padder">
                  <div class="text-u-c">Ocupación</div>
                  <div class="h1 font-bold text-primary">
                    <?=placeHolderLoader(3)?>
                  </div>

                  <div class="wrap-l m-t text-left">
                    <?=placeHolderLoader(10)?>
                    <br>
                    <?=placeHolderLoader(10)?>
                  </div>
                </div>
              </div>
              <?php
              }
              ?>

              

              <div class="col-xs-12 wrapper panel m-b r-24x clear panelGeneralInfo">
                <div class="h4 font-bold m-b">Información general</div>
                <table class="table no-border">
                  <tbody>
                    <tr>
                      <td>
                        <span class="customerAverageArrow">...</span> Ticket Promedio
                      </td>
                      <td class="font-bold text-right">
                        <span class="customerAverage">...</span>
                      </td>
                    </tr>
                    <tr>
                      <td>
                        <a href="/@#contacts">Clientes en total</a>
                      </td>
                      <td class="font-bold text-right">
                        <span class="customersTotal">...</span>
                      </td>
                    </tr>

                    <tr>
                      <td>
                        <a href="/@#report_drawers">Cajas Abiertas</a>
                      </td>
                      <td class="font-bold text-right">
                        <span class="registersCount">...</span>  
                      </td>
                    </tr>

                    <tr class="hidden">
                      <td>
                        <span class="customersOldArrow">...</span> Promedio de visitas
                      </td>
                      <td class="font-bold text-right">
                        <span class="customersOld">...</span>
                      </td>
                    </tr>

                    <tr class="hidden">
                      <td>
                        <span class="customersChurnArrow">...</span> % pérdida de clientes
                      </td>
                      <td class="font-bold text-right">
                        <span class="customersChurn">...</span>
                      </td>
                    </tr>

                    <tr>
                      <td>
                        <a href="/@#report_giftCards">Gift Cards Vigentes</a>
                      </td>
                      <td class="font-bold text-right giftCards">
                        ...
                      </td>
                    </tr>

                  <tbody>
                </table>
              </div>

              <a href="" target="_blank" class="col-xs-12 wrapper-md bg-dark text-center r-24x m-b">
                <span class="h3 font-bold text-white"><i class="material-icons pull-left md-24">help_outline</i>Ver guías y tutoriales</span>
              </a>

              <div class="col-xs-12 wrapper panel m-b r-24x clear panelAccountInfo">
                <div class="h4 font-bold m-b">Plan <span class="planName">...</span></div>
                <table class="table no-border">
                  <tbody>

                    <tr>
                      <td>
                        Productos y servicios
                      </td>
                      <td class="font-bold text-right itemsCount">
                        ...
                      </td>
                    </tr>

                    <tr>
                      <td>
                        Usuarios
                      </td>
                      <td class="font-bold text-right usersCount">
                        ...
                      </td>
                    </tr>

                    <tr>
                      <td>
                        Transacciones (este mes)
                      </td>
                      <td class="font-bold text-right transactionsCount">
                        ...
                      </td>
                    </tr>

                    <tr>
                      <td>
                        Sucursales
                      </td>
                      <td class="font-bold text-right outletsCount">
                        ...
                      </td>
                    </tr>

                  <tbody>
                </table>
              </div>


            </div>
            

          </div>
         <?
         }
         ?>

      <?=menuFrame('bottom');?>

      <div class="modal fade" tabindex="-1" id="successModal" role="dialog">
        <div class="modal-dialog">
          <div class="modal-content r-24x clear all-shadows no-border">
            <div class="modal-body no-padder bg-white">
               
              <div class="col-xs-12 wrapper text-center text-white bg-info gradBgBlue animateBg">
                 <div class="font-bold" style="font-size: 3.5em;">
                   <img src="/images/iconincomesmwhite.png" alt="Income" width="80"><br>
                   Bienvenido a <?= APP_NAME ?>
                   <div class="h3 text-u-c">Siguientes pasos</div>
                 </div>
              </div>

              <div class="col-sm-12 no-padder">
                <div class="list-group list-group-lg no-bg m-n">
                  <a href="/@#settings" class="list-group-item clearfix hidden-print"> 
                    <span class="pull-right h2 text-muted m-l">
                      <i class="material-icons md-36"> keyboard_arrow_right </i>
                    </span> 
                    <span> 
                      <div class="font-bold text-u-c">Configura tu empresa</div> 
                      <small class="text-muted">
                        Añade y personaliza toda la información y parámetros necesarios para ajustar al máximo a las necesidades de tu negocio.
                      </small> 
                    </span> 
                  </a>

                  <a href="/@#items" class="list-group-item clearfix hidden-print"> 
                    <span class="pull-right h2 text-muted m-l">
                      <i class="material-icons md-36"> keyboard_arrow_right </i>
                    </span> 
                    <span> 
                      <div class="font-bold text-u-c">Añade productos y servicios</div> 
                      <small class="text-muted">
                        Comienza a cargar tus productos y/o servicios, asignar inventario, imagenes, categorías y todo lo que necesites para poder tener un preciso control de lo que vendas.
                      </small> 
                    </span> 
                  </a>

                  <a href="/@#contacts?rol=user" class="list-group-item clearfix hidden-print"> 
                    <span class="pull-right h2 text-muted m-l">
                      <i class="material-icons md-36"> keyboard_arrow_right </i>
                    </span> 
                    <span> 
                      <div class="font-bold text-u-c">Personaliza tu equipo</div> 
                      <small class="text-muted">
                        En la sección de Usuarios, podrás crear perfiles con distintos tipos de roles, asignar accesos a la caja registradora y al panel.
                      </small> 
                    </span> 
                  </a>

                  <a href="/@#contacts" class="list-group-item clearfix hidden-print"> 
                    <span class="pull-right h2 text-muted m-l">
                      <i class="material-icons md-36"> keyboard_arrow_right </i>
                    </span> 
                    <span> 
                      <div class="font-bold text-u-c">Añade tus clientes</div> 
                      <small class="text-muted">
                        Tienes un listado de clientes? Añadelos desde un archivo Excel o a mano, también podrás crearlos directamente desde la caja registradora a la hora de la venta.
                      </small> 
                    </span> 
                  </a>

                  <a href="" class="list-group-item clearfix hidden-print"> 
                    <span class="pull-right h2 text-muted m-l">
                      <i class="material-icons md-36"> keyboard_arrow_right </i>
                    </span> 
                    <span> 
                      <div class="font-bold text-u-c">Comienza a vender</div> 
                      <small class="text-muted">
                        Utiliza la caja registradora en tu computadora o dispositivo móvil iOS o Android y comienza a procesar ventas de forma rápida y sencilla, luego, visita la sección de Reportes (aquí en el Panel administrativo), y visualiza todos los detalles que colectamos para que tengas un panorama preciso y en tiempo real de lo que sucede en tu negocio.
                      </small> 
                    </span> 
                  </a>

                  <a href="/@#purchase" class="list-group-item clearfix hidden-print"> 
                    <span class="pull-right h2 text-muted m-l">
                      <i class="material-icons md-36"> keyboard_arrow_right </i>
                    </span> 
                    <span> 
                      <div class="font-bold text-u-c">Registra compras y gastos</div> 
                      <small class="text-muted">
                        Registra cada gasto de tu negocio para saber con exactitud cuando y a donde van dirigidos los egresos.
                      </small> 
                    </span> 
                  </a>

                  <a href="/@#outlets" class="list-group-item clearfix hidden-print"> 
                    <span class="pull-right h2 text-muted m-l">
                      <i class="material-icons md-36"> keyboard_arrow_right </i>
                    </span> 
                    <span> 
                      <div class="font-bold text-u-c">Expándete</div> 
                      <small class="text-muted">
                        Crea sucursales de forma sencilla, todos los artículos, inventario y clientes serán sincronizados a cada sucursal automáticamente.
                      </small> 
                    </span> 
                  </a>

                </div>
              </div>

              <div class="col-xs-12 wrapper text-center m-t-lg">
                  <a href="#" class="btn btn-info btn-lg font-bold text-u-c rounded" data-dismiss="modal" aria-hidden="true">Cerrar</a>
              </div>

            </div>
          </div>
        </div>
      </div>

      <script id="topItemsTpl" type="text/html">
        
          <tbody>
            {{#items}}
              <tr>
                <td>
                  {{name}}
                </td>
                <td class="text-right">
                  {{count}}
                </td>
                <td class="font-bold text-right">
                  {{total}}
                </td>
              </tr>
            {{/items}}
            {{^items}}
              <tr>
                <td colspan="3" class="text-center font-bold text-muted">
                  <div class="text-center font-bold text-muted">
                    <img src="/assets/images/emptystate7.png" height="130" class="m-b m-t-md">
                  </div>
                </td>
              </tr>
            {{/items}}
          <tbody>

      </script>

      <script id="totalIncomeTpl" type="text/html">
        <div class="text-u-c">
          <span class="salesTotalArrow"></span> Ingresos
          <a href="/@#report_summary" class="pull-right hidden-print">
            <i class="material-icons md-24">keyboard_arrow_right</i>
          </a>
        </div>
        <div class="h1 font-bold">
          {{{total}}}
        </div>
      </script>

      <script id="totalOutcomeTpl" type="text/html">
        <div class="text-u-c">
          <span class="salesExpensesArrow"></span> Egresos
          <a href="/@#report_purchases" class="pull-right hidden-print">
            <i class="material-icons md-24">keyboard_arrow_right</i>
          </a>
        </div>
        <div class="h1 font-bold">
          {{{total}}}
        </div>
      </script>

      <script id="salesTypeTpl" type="text/html">
        <div class="h4 font-bold m-b text-left">
          Tipos de ventas
          <a href="/@#report_transactions" class="pull-right hidden-print">
            <i class="material-icons md-24">keyboard_arrow_right</i>
          </a>
        </div>

        <canvas id="chart-contado" class="hidden-print" height="200" style="max-height:200px;"></canvas>
        <div class="donut-inner hidden-print" style=" margin-top: -140px; margin-bottom: 70px;">
          <div class="h1 m-t creditoCount font-bold">{{creditCount}}</div>
          <span>Ventas</span>
        </div>
        <div class="m-t-n h4">&nbsp;</div>
      
        <div class="col-xs-6 text-center">
          <div class="text-u-c"><span class="text-info m-r-xs">●</span>A Crédito</div>
          <div class="h3 font-bold">
            {{creditTotal}}
          </div> 
        </div>

        <div class="col-xs-6 text-center">
          <div class="text-u-c"><span class="text-muted m-r-xs">●</span>Al Contado</div>
          <div class="h3 font-bold">
            {{cashTotal}}
          </div>
        </div>
      </script>

      <script id="creditSalesTpl" type="text/html">
        <div class="h4 font-bold m-b text-left">
          Cuentas por cobrar
          <a href="/@#report_open_invoices" class="pull-right hidden-print">
            <i class="material-icons md-24">keyboard_arrow_right</i>
          </a>
        </div>

        <canvas id="chart-porcobrar" class="hidden-print" height="200" style="max-height:200px;"></canvas>
        <div class="donut-inner hidden-print" style=" margin-top: -140px; margin-bottom: 70px;">
          <div class="h1 m-t porcobrarCount font-bold">{{pendingCount}}</div>
          <span>Ventas</span>
        </div>
        <div class="m-t-n h4">&nbsp;</div>

        <div class="col-xs-6 text-center">
          <div class="text-u-c"><span class="text-info m-r-xs">●</span>Por cobrar</div>
          <div class="h3 font-bold">
            {{pendingTotal}}
          </div>
        </div>

        <div class="col-xs-6 text-center"> 
          <div class="text-u-c"><span class="text-muted m-r-xs">●</span>Cobrado</div>
          <div class="h3 font-bold">
            {{payedTotal}}
          </div>
        </div>
      </script>

      <script id="scheduleTpl" type="text/html">
        <div class="col-xs-6 no-padder b-r b-dark">
          <div class="text-u-c">Reservas</div>
          <div class="h1 font-bold" style="color:#9d8cd8;">
            {{scheduledCount}}
          </div>
          <div class="wrap-r m-t text-left">
            H. disponibles <span class="pull-right font-bold">{{shiftHours}}h</span>
            <br>
            H. libres <span class="pull-right font-bold">{{freeHours}}h</span>
          </div>
        </div>
        <div class="col-xs-6 no-padder">
          <div class="text-u-c">Ocupación</div>
          <div class="h1 font-bold" style="color:#9d8cd8;">
            {{occupancy}}%
          </div>

          <div class="wrap-l m-t text-left">
            H. ocupadas <span class="pull-right font-bold">{{workingHours}}h</span>
            <br>
            H. bloqueadas <span class="pull-right font-bold">{{blockedHours}}h</span>
          </div>
        </div>
      </script>

      <script id="tablesTpl" type="text/html">
        <div class="col-xs-6 no-padder b-r b-dark">
          <div class="text-u-c">Espacios Ocupados</div>
          <div class="h1 font-bold text-success">
            {{tablesCount}}
          </div>
          <div class="wrap-r m-t text-left">
            Espacios disponibles <span class="pull-right font-bold">{{totalTables}}</span>
          </div>
        </div>
        <div class="col-xs-6 no-padder">
          <div class="text-u-c">Ocupación</div>
          <div class="h1 font-bold text-success">
            {{occupacy}}%
          </div>
          <div class="wrap-l m-t text-left">
            Espacios libres <span class="pull-right font-bold">{{freeTables}}</span>
          </div>
        </div>
      </script>

      <script id="ordersTpl" type="text/html">
        <div class="col-xs-6 no-padder b-r b-dark">
          <div class="text-u-c">Órdenes</div>
          <div class="h1 font-bold text-info">
            {{ordersCount}}
          </div>
        </div>
        <div class="col-xs-6 no-padder">
          <div class="text-u-c">Online</div>
          <div class="h1 font-bold text-info">
            {{onlineCount}}
          </div>
        </div>
      </script>

      <?php if (defined('HEADWAY_ACCOUNT_ID') && HEADWAY_ACCOUNT_ID): ?>
      <script>
        var HW_config = {
                          selector  : ".yowhatsnew",
                          account   :  "<?= HEADWAY_ACCOUNT_ID ?>",
                          trigger   : ".changloglink",
                          position  : {x : "left"},
                          translations: {
                                          title     : "Novedades",
                                          readMore  : "Leer más",
                                          labels    : {
                                                        "new"         : "Nuevos",
                                                        "improvement" : "Actualizaciones",
                                                        "fix"         : "Mejoras"
                                          },
                                          footer: "Ver todo"
                          }
                        };
      </script>
      <script async src="https://cdn.headwayapp.co/widget.js"></script>
      <?php endif; ?>

    <script>
      $(document).ready(function(){
        $('[data-toggle="tooltip"]').tooltip();
        FastClick.attach(document.body);
        dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>","left",false,true);

        <?php
        if(PLAN > 0 && !validateHttp('locked')){
        ?>

        Chart.defaults.global.responsive           = true;
        Chart.defaults.global.maintainAspectRatio  = false;
        Chart.defaults.global.legend.display       = false;
        var baseUrl                                = '<?=$baseUrl?>';

        var xhr = $.get(baseUrl + '?widget=incomeOutcomeStats',function(result){
          
          var currency  = '<span class="text-muted"><?=CURRENCY?></span> ';

          $('.salesRevenue').html(currency + result.revenueF);
          $('.salesMargin').html(result.marginF);
          $('.salesCount').html(result.countF);
          $('.customerAverage').html(currency + result.customerAverageF);

          ncmHelpers.mustacheIt($('#totalIncomeTpl'),{"total": currency + result.totalF},$('#totalIncome'));
          ncmHelpers.mustacheIt($('#totalOutcomeTpl'),{"total": currency + result.expensesF},$('#totalOutcome'));

          if(validity(result)){
            var varGetincomeOutcomeStats = $.get(baseUrl + '?widget=incomeOutcomeStats&prev=true',function(result2){

              var wrap      = '#incomeOutcomeStatsWidget';
              var success   = '<span class="text-success m-r-xs m-l-xs"><i class="material-icons">trending_up</i></span>';
              var fail      = '<span class="text-danger m-r-xs m-l-xs"><i class="material-icons">trending_down</i></span>';
              var even      = '<span class="font-bold m-r-xs m-l-xs"><i class="material-icons">trending_flat</i></span>';
              
              if(result2.total>result.total){
                var total = fail;
              }else if(result2.total<result.total){
                var total = success;
              }else{
                var total = even;
              }

              $('.salesTotalArrow').html(total);

              if(result2.expenses<result.expenses){
                var expenses = '<span class="text-danger m-r-xs m-l-xs"><i class="material-icons">trending_up</i></span>';
              }else if(result2.expenses>result.expenses){
                var expenses = '<span class="text-success m-l-xs m-r-xs"><i class="material-icons">trending_down</i></span>';
              }else{
                var expenses = even;
              }

              $('.salesExpensesArrow').html(expenses);

              if(result2.revenue > result.revenue){
                var revenue = fail;
              }else if(result2.revenue < result.revenue){
                var revenue = success;
              }else{
                var revenue = even;
              }

              $('.salesRevenueArrow').html(revenue);

              if(result2.margin>result.margin){
                var margin = fail;
              }else if(result2.margin<result.margin){
                var margin = success;
              }else{
                var margin = even;
              }

              $('.salesMarginArrow').html(margin);

              if(result2.count>result.count){
                var count = fail;
              }else if(result2.count<result.count){
                var count = success;
              }else{
                var count = even;
              }

              $('.salesCountArrow').html(count);

               if(result2.customerAverage>result.customerAverage){
                var customerAverage = fail;
              }else if(result2.customerAverage<result.customerAverage){
                var customerAverage = success;
              }else{
                var customerAverage = even;
              }

              $('.customerAverageArrow').html(customerAverage);
            });
          }
        });

        window.xhrs.push(xhr);

        var xhr = $.get(baseUrl + '?widget=paymentStatus',function(result){
          
          var wrap = '#paymentStatusWidget';

          if(result.creditoCount>0 || result.cobradoCount>0 || result.porcobrarCount>0){
            $(wrap).removeClass('hidden');
          }

          ncmHelpers.mustacheIt($('#salesTypeTpl'),{"creditCount" : result.creditoCount, "creditTotal" : result.creditoF, "cashTotal" : result.contadoF},$('#salesType'));

          ncmHelpers.mustacheIt($('#creditSalesTpl'),{"pendingCount" : result.porcobrarCount, "pendingTotal" : result.porcobrarF, "payedTotal" : result.cobradoF},$('#creditSales'));

          var chartContado = $('#chart-contado')[0].getContext("2d");

          var gradientStroke = chartContado.createLinearGradient(500, 0, 100, 0);
          gradientStroke.addColorStop(0, "#4cb6cb");
          gradientStroke.addColorStop(1, "#54cfc7");

          var methods = new Chart(chartContado, {
            type      : 'doughnut',
            data      : {
              labels: ['Contado','Crédito'],
              datasets: [{
                data            : [result.contado,result.credito],
                backgroundColor : [(ncmUI.setDarkMode.isSet ? '#3b464d' : '#d7e5e8'),gradientStroke]
              }]
            },
            animation : true,
            options   : {
              cutoutPercentage : 85,
              tooltips: chartTooltipStyle.tooltips
            }
          });

          var chartPorcobrar = $('#chart-porcobrar')[0].getContext("2d");

          var gradientStroke = chartPorcobrar.createLinearGradient(500, 0, 100, 0);
          gradientStroke.addColorStop(0, "#4cb6cb");
          gradientStroke.addColorStop(1, "#54cfc7");

          var methods = new Chart(chartPorcobrar, {
            type      : 'doughnut',
            data      : {
              labels: ['Por Cobrar','Cobrado'],
              datasets: [
              {
                data: [result.porcobrar,result.cobrado],
                backgroundColor: [gradientStroke, (ncmUI.setDarkMode.isSet ? '#3b464d' : '#d7e5e8') ]
              }]
            },
            animation : true,
            options   : {
              cutoutPercentage:85,
              tooltips: chartTooltipStyle.tooltips
            }
          });
        });

        window.xhrs.push(xhr);

        /*var xhr = $.get(baseUrl + '?widget=topPayments',function(result){
          if(validity(result)){
            $('#topPaymentsChart').html('');
            var paymentChart    = $('#topPaymentsChart')[0].getContext("2d");
            var gradientStroke  = paymentChart.createLinearGradient(0, 0, 0, 250);
            gradientStroke.addColorStop(0, "#4cb6cb");
            gradientStroke.addColorStop(1, "#54cfc7");

            var dataM = {
                labels  : result.title,
                datasets: [
                            {
                                label: "Total <?=CURRENCY?>",
                                data: result.amount,
                                backgroundColor: [
                                    gradientStroke,'#2f3940','#405161','#778490','#d7e5e8','#4cb6cb','#2f3940','#405161','#778490','#d7e5e8'
                                ]
                            }
                          ]
            };

            var methods = new Chart(paymentChart, {
               type: 'bar',
               data: dataM,
               animation : true,
               options:chartBarStackedGraphOptions
            });
          }else{
            $('#topPayments').html('<div class="text-center font-bold text-muted"><img src="/assets/images/emptystate7.png" height="130" class="m-b m-t-lg"></div>');
          }
        });

        window.xhrs.push(xhr);*/

        var xhr = $.get('/a_report_summary?action=getChartSales&noBack=1',function(result){
          if(result){
            var array = result.chart.sales.gross;
            var newar = [];
            var total = array.reduce((a, b) => parseInt(a) + parseInt(b));
            $.each(array,function(i,v){
                var percent = parseInt( (v * 100) / total );
                newar.push(percent);
            });

            var newarr = newar.toString();

            var url   = encodeURI('https://quickchart.io/chart?cht=lc&chd=t:' + newarr + '&chco=ffffff4d&chf=a,s,000000&chls=4.0&chs=400x80');
            var css   = 'background:url(' + url + ') no-repeat center center / cover, linear-gradient(314deg, #54CFC7,#6BC0D1);';
            $('#totalIncome').removeClass('gradBgBlue').addClass('text-white');
            $('#totalIncome').attr('style',css);
          }
        });

        window.xhrs.push(xhr);

        <?php
        if($_modules['calendar']){
        ?>
        var xhr = $.get(baseUrl + '?widget=schedule',function(result){
          if(validity(result)){
            ncmHelpers.mustacheIt($('#scheduleTpl'),result,$('#schedule'));  
          }
        });

        window.xhrs.push(xhr);

        ncmHelpers.onClickWrap('#schedule',function(event,tis){
          window.location.hash = '#report_schedule';
        });
        <?php
        }
        ?>

        <?php
        if($_modules['tables']){
        ?>
        var xhr = $.get(baseUrl + '?widget=tables',function(result){
          if(validity(result)){
            ncmHelpers.mustacheIt($('#tablesTpl'),result,$('#tables'));  
          }
        });

        window.xhrs.push(xhr);
        <?php
        }
        ?>

        var xhr = $.get(baseUrl + '?widget=orders',function(result){
          if(validity(result.ordersCount)){
            ncmHelpers.mustacheIt($('#ordersTpl'),result,$('#orders'));  
            $('#orders').removeClass('hidden');
          }
        });

        window.xhrs.push(xhr);

        ncmHelpers.onClickWrap('#tables,#orders',function(event,tis){
          window.location.hash = '#report_orders';
        });

        var xhr = $.get(baseUrl + '?widget=info',function(result){
          $('.giftCards').text(result.giftCardsCount);
          $('.registersCount').text(result.openDrawersCount);
          $('.outletsCount').text(result.outletsCount);
          $('.planName').text(result.plan);
          $('.usersCount').text(result.usersCount);
          $('.itemsCount').text(result.itemsCount);
          $('.transactionsCount').text(result.transactionsCount);
        }); 

        window.xhrs.push(xhr);       

        <?php
        if($_modules['feedback']){
        ?>
        var xhr = $.get(baseUrl + '?widget=satisfaction',function(result){
          $('.satisfactionBarDetractors').attr('data-original-title', 'Detractores: ' + result.detractors.count + ' voto(s)');
          $('.satisfactionBarDetractors').addClass(result.detractors.percent).css('width',result.detractors.percent + '%');

          $('.satisfactionBarPassives').attr('data-original-title', 'Pasivos: ' + result.passives.count + ' voto(s)');
          $('.satisfactionBarPassives').addClass(result.passives.percent).css('width',result.passives.percent + '%');

          $('.satisfactionBarPromoters').attr('data-original-title', 'Promotores: ' + result.promoters.count + ' voto(s)');
          $('.satisfactionBarPromoters').addClass(result.promoters.percent).css('width',result.promoters.percent + '%');
        });

        window.xhrs.push(xhr);
        <?php
        }
        ?>

        var xhr = $.get(baseUrl + '?widget=topItems',function(result){
          ncmHelpers.mustacheIt($('#topItemsTpl'),{'items':result},$('#topItems table.table'));
        });

        window.xhrs.push(xhr);

        var xhr = $.get(baseUrl + '?widget=customersRates',function(result){

          var retentionRate = '.retentionRate';
          var growthRate    = '.growthRate';
          var churnRate     = '.churnRate';

          var retentionRateImg = 'https://quickchart.io/chart?backgroundColor=transparent&c={ type: "doughnut", data: { datasets: [ { data: [' + result.retention_rate + ', ' + (result.retention_rate - 100) + '], backgroundColor: [ "%2362bcce", "%23e8eff0" ] } ] }, options: { rotation: 16, plugins: { datalabels: { display: false } }, cutoutPercentage:80 }}';

          var growthRateImg = 'https://quickchart.io/chart?backgroundColor=transparent&c={ type: "doughnut", data: { datasets: [ { data: [' + result.customer_growth_rate + ', ' + (result.customer_growth_rate - 100) + '], backgroundColor: [ "%2362bcce", "%23e8eff0" ] } ] }, options: { rotation: 16, plugins: { datalabels: { display: false } }, cutoutPercentage:80 }}';

          var churnRateImg = 'https://quickchart.io/chart?backgroundColor=transparent&c={ type: "doughnut", data: { datasets: [ { data: [' + result.churn_rate + ', ' + (result.churn_rate - 100) + '], backgroundColor: [ "%23f06a6a", "%23e8eff0" ] } ] }, options: { rotation: 16, plugins: { datalabels: { display: false } }, cutoutPercentage:80 }}';//"https://quickchart.io/chart?c={type:'radialGauge',data:{datasets:[{data:[" + result.churn_rate + "],backgroundColor:['%23f06a6a','%23e8eff0'],borderWidth:0}]}}";

          $(retentionRate).text(result.retention_rate + '%');
          $(growthRate).text(result.customer_growth_rate + '%');
          $(churnRate).text(result.churn_rate + '%');

          $(retentionRate + 'Img').attr('src',retentionRateImg);
          $(growthRate + 'Img').attr('src',growthRateImg);
          $(churnRate + 'Img').attr('src',churnRateImg);
        });

        window.xhrs.push(xhr);

        var xhr = $.get(baseUrl + '?widget=customers',function(result){
            if(validityChecker(result)){
              $('.customersTotal').text(result.total);
              $('.customersNew').text(result.new);
              $('.customersOld').text(result.old);
              $('.customersChurn').text(result.churn);
              

              $('#customersWidget .average div span.h1').text(result.average);

              var xhr = $.get(baseUrl + '?widget=customers&prev=true',function(result2){
                  var wrap      = '#customersWidget';
                  var success   = '<span class="text-success m-r-xs m-l-xs"><i class="material-icons">trending_up</i></span>';
                  var fail      = '<span class="text-danger m-r-xs m-l-xs"><i class="material-icons">trending_down</i></span>';
                  var even      = '<span class="font-bold m-r-xs m-l-xs"><i class="material-icons">trending_flat</i></span>';
                  //var success   = '<span class="text-success m-r-xs m-l-xs">▲</span>';
                  //var fail      = '<span class="text-danger m-r-xs m-l-xs">▼</span>';
                  //var even      = '<span class="font-bold m-r-xs m-l-xs">=</span>';
                  
                  if(result2.new > result.new){
                    var news = fail;
                  }else if(result2.new < result.new){
                    var news = success;
                  }else{
                    var news = even;
                  }
                  $('.customersNewArrow').html(news);

                  if(result2.old > result.old){
                    var old = fail;
                  }else if(result2.old < result.old){
                    var old = success;
                  }else{
                    var old = even;
                  }

                  $('.customersOldArrow').html(old);

                  if(result2.churn > result.churn){
                    var churn = fail;
                  }else if(result2.churn < result.churn){
                    var churn = success;
                  }else{
                    var churn = even;
                  }

                  $('.customersChurnArrow').html(churn);
              });     
            }
        });

        window.xhrs.push(xhr);

        var xhr = $.get(baseUrl + '?widget=topCategories',function(result){

          if(validity(result)){
            var chartArea       = $('#topCategoriesChart')[0].getContext("2d");
            var gradientStroke  = chartArea.createLinearGradient(500, 0, 100, 0);
            gradientStroke.addColorStop(0, "#4cb6cb");
            gradientStroke.addColorStop(1, "#54cfc7");
 
            var catsToolTips = ncmHelpers.cloneObj(chartTooltipStyle);

            catsToolTips.tooltips.callbacks.title = function(item, data) {
              return false;
            }

            catsToolTips.tooltips.callbacks.label = function(item, data) {
              var dataset   = data.datasets[item.datasetIndex];
              var dataItem  = dataset.data[item.index];
              return dataItem.g + ': ' + dataItem.v;
            }

            var categories = new Chart(chartArea, {
              type  : 'treemap',
              data  : {
                datasets: [{
                  tree    : result,
                  data    : result.amount,
                  backgroundColor: gradientStroke,
                  borderColor: function(ctx) {
                    const item = ctx.dataset.data[ctx.dataIndex];
                    if (!item) {
                      return;
                    }
                    return colorFromValueForCharts(item.v, true);
                  },
                  spacing     : 3,
                  borderWidth : 0,
                  borderColor : "rgba(180,180,180, 0.15)",
                  key         : 'total',
                  groups      : ['title'],
                  fontColor   : '#fff',
                  fontFamily  : 'Source Sans Pro',
                }]
              },

              options: {
                maintainAspectRatio: false,
                title: {
                  display: false
                },
                legend: {
                  display: false
                },
                tooltips : catsToolTips.tooltips,
              }

            });

          }else{
            $('#topCategories').html('<div class="text-center font-bold text-muted"><img src="/assets/images/emptystate7.png" height="130" class="m-b m-t-xl"></div>');
          }
        });

        window.xhrs.push(xhr);

        var xhr = $.get(baseUrl + '?widget=topHours',function(result){

          if(validity(result.hour)){

            var hoursChart      = $('#topHoursChart')[0].getContext("2d");
            var gradientStroke  = hoursChart.createLinearGradient(300, 0, 100, 0);
            gradientStroke.addColorStop(0, "#4cb6cb");
            gradientStroke.addColorStop(1, "#54cfc7");

            var dataH = {
                labels    : result.hour,
                datasets  : [{
                              data: result.total,
                              backgroundColor: [
                                  gradientStroke,gradientStroke,'#2f3940','#2f3940','#405161','#405161','#778490','#778490','#d7e5e8','#d7e5e8','#e8eff0','#e8eff0','#edf2f3','#edf2f3','#f2f5f5','#f2f5f5'
                              ]
                          }]
            };

            var methods = new Chart(hoursChart, {
                type      : 'polarArea',
                data      : dataH,
                animation : true,
                options   : chartTooltipStyle
             }); 

          }else{
            $('#topHours').html('<div class="text-center font-bold text-muted"><img src="/assets/images/emptystate7.png" height="130" class="m-b m-t-xl"></div>');
          }
        });

        window.xhrs.push(xhr);

        <?php
        }
        ?>

          ncmiGuiderConfig.tourTitle  = 'guide.dashboard';
          ncmiGuiderConfig.loc        = '/@#dashboard';
          ncmiGuiderConfig.intro    = {
                                      cover:'//wordpress/wp-content/uploads/2020/07/retail_horizontal_banner.png',
                                      title:'¡Hola <?=USER_NAME?>! Te damos la bienvenida a tu Panel de Control',
                                      content:'Con <?= APP_NAME ?> podrás manejar tu negocio por completo, desde registrar todas tu compras y ventas, inventario, clientes, marketing y cientos de funciones y herramientas que se adaptan a todos los rubros.<br><br> <div class="g-modal-header text-white">Hagamos un tour rápido para conocer mejor tu panel.</div>',
                                      width : 600
                                    };

          if(isMobile.phone){
            ncmiGuiderConfig.steps = [{
                                      title     : 'Menú Principal',       
                                      content   : 'Desde aquí podrás navegar a las distintas secciones de tu panel de control.', 
                                      target    : '#openMobileMenu',
                                      event     : 'click'
                                    },{
                                      title:'Artículos',       
                                      content:'Aquí se encuentran todos tus productos, servicios y todo lo relacionado a inventario y herramientas para administrarlo.',  
                                      target:'.mmnItemsBtn',
                                      disable:true
                                    },{
                                      title:'Contactos',       
                                      content:'Desde la sección de contactos podrás crear, modificar, ver y eliminar usuarios, clientes y proveedores.',  
                                      target:'.mmnContactsBtn',
                                      disable:true
                                    },{
                                      title:'Reportes',       
                                      content:'Accede al menú de reportes de todo tipo, ventas, administrativos, inventario y otros.',
                                      target:'.mmnReportsBtn',
                                      disable:true
                                    },{
                                      title:'Submenú',       
                                      content:'En este submenú podrás acceder al formulario de compras y gastos, la sección de módulos y configuración general de tu cuenta. Cuando añadas el logo de tu empresa aparecerá arriba.',  
                                      target:'#mSubMenu',
                                      disable:true,
                                      waitElementTime:1000,
                                      before:function(target){
                                        window.snapper.open('left');
                                      }
                                    },{
                                      title:'Dashboard',       
                                      content:'El dashboard muestra un vistazo general de los sectores más importantes de la empresa.',
                                      target  :'#pageTitle',
                                      disable:true,
                                      delayBefore :250,
                                      before    :function(target){
                                        window.snapper.close();
                                      }
                                    },{
                                      title:'Notificaciones',       
                                      content:'Recordatorios, acciones que hagan tus clientes, estados de tu inventario y muchas otras notificaciones importantes aparecen en este sector, no olvides revisarlo periódicamente.',
                                      target:'.notifybtn',
                                      disable:true
                                    },{
                                      title   : 'Fecha',       
                                      content : 'Todos los reportes se manejan por un rango de fecha que se puede seleccionar en cada sector.',  
                                      target  :'#bodyContent > div:nth-child(2) > div.col-sm-4.text-right.m-t.m-b.hidden-print',
                                      disable : true
                                    },{
                                      title:'Estadísticas generales',       
                                      content:'Aquí podrás ver rápidamente el estado actual de tu empresa, total de ingresos, egresos, cuentas pendientes por cobrar, ranking de productos, categorías, medios de pago y más.',  
                                      target  :'#incomeOutcomeStatsWidget > div:nth-child(1)',
                                      trigger :'click'
                                    },{
                                      title:'Calificaciones de tus clientes',       
                                      content:'Esta barra mide el nivel de satisfacción de tus clientes basado en sus calificaciones con el módulo de feedback. También puedes ingresar a ver un reporte detallado de las calificaciones y comentarios de tus clientes.',  
                                      target  :'#customerSatisfactionLevel',
                                      delayBefore :250,
                                      before  : ncmiGuiderConfig.scrollToIt
                                    },{
                                      title:'Información General',       
                                      content:'Un vistazo al business analitycs que genera <?= APP_NAME ?> basado en tus datos, información vital para tu negocio.',
                                      target  :'div.panelGeneralInfo',
                                      delayBefore :250,
                                      before  : ncmiGuiderConfig.scrollToIt
                                    },{
                                      title:'Información relacionada a tu plan',       
                                      content:'Datos generales sobre tu plan y sus límites.',  
                                      target  :'div.panelAccountInfo',
                                      delayBefore :250,
                                      before  : ncmiGuiderConfig.scrollToIt
                                    },{
                                      title:'¿Necesitas guías de cada sector?',       
                                      content:'Vistá aquí el Help Center donde encontrarás tutoriales que te ayudarán a entender y aprender a usar <?= APP_NAME ?>.',
                                      target  :'a.gradBgGray',
                                      delayBefore :250,
                                      before  : ncmiGuiderConfig.scrollToIt
                                    }
                                    
                                  ];


          }else{
            ncmiGuiderConfig.steps = [{
                                      title:'Menú Principal',       
                                      content:'Desde aquí podrás navegar a las distintas secciones de tu panel de control.', 
                                      target:'#nav > section',
                                      disable:true
                                    },{
                                      title:'Artículos',       
                                      content:'Aquí se encuentran todos tus productos, servicios y todo lo relacionado a inventario y herramientas para administrarlo.',  
                                      target:'.mnItemsBtn',
                                      disable:true,
                                      shape :1
                                    },{
                                      title:'Contactos',       
                                      content:'Desde la sección de contactos podrás crear, modificar, ver y eliminar usuarios, clientes y proveedores.',  
                                      target:'.mnContactsBtn',
                                      disable:true,
                                      shape :1
                                    },{
                                      title:'Reportes',       
                                      content:'Accede al menú de reportes de todo tipo, ventas, administrativos, inventario y otros.',
                                      target:'.mnReportsBtn',
                                      disable:true,
                                      shape :1
                                    },{
                                      title:'Caja',       
                                      content:'Te lleva directamente al módulo de caja, el sector donde podrás realizar ventas, crear órdenes, agendamientos y cientos de funciones más.',  
                                      target:'.mnPOSBtn',
                                      disable:true,
                                      shape :1
                                    },{
                                      title:'Notificaciones',       
                                      content:'Recordatorios, acciones que hagan tus clientes, estados de tu inventario y muchas otras notificaciones importantes aparecen en este sector, no olvides revisarlo periódicamente.',
                                      target:'.notifybtn',
                                      disable:true,
                                      shape :1
                                    },{
                                      title:'Submenú',       
                                      content:'En este submenú podrás acceder al formulario de compras y gastos, la sección de módulos y configuración general de tu cuenta. Cuando añadas el logo de tu empresa aparecerá aquí.',  
                                      target:'#nav > section > footer > div > a',
                                      trigger :'click',
                                      disable:true,
                                      shape :1
                                    },{
                                      title:'Dashboard',       
                                      content:'El dashboard muestra un vistazo general de los sectores más importantes de la empresa.',
                                      target  :'#pageTitle',
                                      disable:true
                                    },{
                                      title   : 'Novedades y noticias',       
                                      content : 'Mantente siempre informado, en <?= APP_NAME ?> estamos constantemente añadiendo y mejorando funciones. Accede aquí a las últimas noticias y novedades de la plataforma.',  
                                      target  :'#bodyContent > div:nth-child(2) > div.col-sm-8.col-xs-12.m-t > div.col-sm-3.no-padder > span.yowhatsnew',
                                      disable : true
                                    },{
                                      title   : 'Fecha',       
                                      content : 'Todos los reportes se manejan por un rango de fecha que se puede seleccionar en cada sector.',  
                                      target  :'#bodyContent > div:nth-child(2) > div.col-sm-4.text-right.m-t.m-b.hidden-print',
                                      disable : true
                                    },{
                                      title:'Estadísticas generales',       
                                      content:'Aquí podrás ver rápidamente el estado actual de tu empresa, total de ingresos, egresos, cuentas pendientes por cobrar, ranking de productos, categorías, medios de pago y más.',  
                                      target  :'#incomeOutcomeStatsWidget',
                                      trigger :'click'
                                    },{
                                      title:'Calificaciones de tus clientes',       
                                      content:'Esta barra mide el nivel de satisfacción de tus clientes basado en sus calificaciones con el módulo de feedback. También puedes ingresar a ver un reporte detallado de las calificaciones y comentarios de tus clientes.',  
                                      target  :'#sortable > div.col-md-4.col-sm-6.col-xs-12 > div.col-xs-12.no-bg.no-padder'
                                    },{
                                      title:'Información General',       
                                      content:'Un vistazo al business analitycs que genera <?= APP_NAME ?> basado en tus datos, información vital para tu negocio.',
                                      target  :'#sortable > div.col-md-4.col-sm-6.col-xs-12 > div.col-xs-12.wrapper.panel.m-b.r-24x.clear.panelGeneralInfo',
                                      delayBefore :250,
                                      before  : ncmiGuiderConfig.scrollToIt
                                    },{
                                      title:'Información relacionada a tu plan',       
                                      content:'Datos generales sobre tu plan y sus límites.',  
                                      target  :'#sortable > div.col-md-4.col-sm-6.col-xs-12 > div.col-xs-12.wrapper.panel.m-b.r-24x.clear.panelAccountInfo',
                                      delayBefore :250,
                                      before  : ncmiGuiderConfig.scrollToIt
                                    },{
                                      title:'¿Necesitas guías de cada sector?',       
                                      content:'Vistá aquí el Help Center donde encontrarás tutoriales que te ayudarán a entender y aprender a usar <?= APP_NAME ?>.',
                                      target  :'#sortable > div.col-md-4.col-sm-6.col-xs-12 > a',
                                      delayBefore :250,
                                      before  : ncmiGuiderConfig.scrollToIt
                                    }
                                    
                                  ];
          }

        var guideMade = simpleStorage.get('iguide_dashboard');

        if(!guideMade){
          simpleStorage.set('iguide_dashboard',true);
          ncmiGuiderConfig.start();
        }
              

        <?php
        if(validateHttp('action') == 'tutorial'){
        ?>
            ncmiGuiderConfig.end = function(){
              ncmHelpers.reHashUrl('dashboard');
            };

            ncmiGuiderConfig.start();
        <?php
        }
        ?>

        ncmHelpers.onClickWrap('.iguiderStart',function(event,tis){
          ncmiGuiderConfig.start();
        });

        var xhr = ncmHelpers.load({
          url         : "a_report_summary?action=getChartSales&noBack=1&expenses=1",
          httpType    : 'GET',
          hideLoader  : true,
          type        : 'json',
          warnTimeout : false,
          success     : function(result){
            if(result.chart.sales.gross.length){
              $('#myChartHolder').removeClass('hidden');
              drawChart(result);
            }else{
              $('#myChartHolder').addClass('hidden');
            }
          }
        });

        window.xhrs.push(xhr);

        var drawChart = function(result){
          var charter = result.chart;

          Chart.defaults.global.legend.display        = true;
          Chart.defaults.global.responsive            = true;
          Chart.defaults.global.maintainAspectRatio   = false;

          var myChart         = $('#summaryChart')[0].getContext("2d");
          var gradientStroke  = myChart.createLinearGradient(1600, 0, 0, 0);
          gradientStroke.addColorStop(0, "#4cb6cb");
          gradientStroke.addColorStop(0.5, "#54cfc7");
          gradientStroke.addColorStop(1, "#54cfc7");

          var annots    = charter.annotations;
          var recAnnots = [];

          if(ncmHelpers.validity(annots)){
            recAnnots = annots.map(function(val, index) {
              var id        = 'vline' + index;
              var mode      = 'vertical';
              var scaleId   = "x-axis-0";
              var position  = iftn(val.position,'center');

              if(val.orientation == 'horizontal'){
                id        = 'hline' + index;
                mode      = 'horizontal';
                scaleId   = "y-axis-0";
              }

              var value = val.value;

              return {
                type      : "line",
                id        : id,
                mode      : mode,
                scaleID   : scaleId,
                value     : value.toFixed(2),
                borderColor : val.color,
                borderWidth : 2,
                borderDash  : [2, 7],
                borderDashOffset : 5,
                label             : {
                  backgroundColor : 'rgba(77,93,110,0.6)',
                  enabled         : true,
                  position        : position,
                  content         : val.text,
                  font            : {
                    size : 7
                  }
                }
              };
            });
          }


          chartBarStackedGraphOptions.annotation = {
                                                    drawTime    : "afterDatasetsDraw",
                                                    annotations : recAnnots
                                                  };

          var data = {
              labels  : charter.sales.labels,
              datasets: [
                  {
                    label                     : "Margen",
                    data                      : charter.sales.margin,
                    type                      : 'line',
                    borderColor               : '#FF9469',

                    pointColor                : '#FF9469',
                    pointHoverRadius          : 8,
                    pointHoverBorderColor     : "#fff",
                    pointHoverBackgroundColor : '#FF9469',
                    pointBorderColor          : '#FF9469',
                    pointBackgroundColor      : '#FF9469',
                    pointRadius               : 3,
                    pointHoverBorderWidth     : 3,
                    pointBorderWidth          : 1,
                    pointHitRadius            : 20,
                    borderWidth               : 3,
                    fill                      : false
                  },
                  {
                      label           : "Ingresos",
                      backgroundColor : gradientStroke,
                      data            : charter.sales.gross
                  },
                  {
                      label           : "Egresos",
                      backgroundColor : chartSecondColor,
                      data            : charter.sales.grossE
                  }                  
              ]
          };

          var chart = new Chart(myChart, { 
              type        : 'bar',
              data        : data,
              animation   : true,
              options     : chartBarStackedGraphOptions
          });
          chartBarStackedGraphOptions.annotation = {};
          Chart.defaults.global.legend.display        = false;
          

        };

      });
    </script>
  
<?php
         } // cierre del else de PLAN != 0 (linea 666)
include_once('includes/compression_end.php');
dai();
?>