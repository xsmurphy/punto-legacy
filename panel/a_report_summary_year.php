<?php
include_once('includes/top_includes.php');

topHook();//error handler
allowUser('sales','view');

$baseUrl        = '/' . basename(__FILE__,'.php');
$limitDetail    = 500;
$offsetDetail   = 0;

 
if(validateHttp('y')){ 
  $year           = validateHttp('y');

  $startDate      = date($year . '-01-01 00:00:00');

  if($year < date('Y')){
    $endDate        = date($year . '-12-t 23:59:59', strtotime('today') );
  }else{
    $endDate        = date($year . '-m-d 23:59:59', strtotime('today') );
  }  
  
}else{
  $year           = date('Y');
  $startDate      = date('Y-01-01 00:00:00');
  $endDate        = date('Y-m-d 23:59:59', strtotime('today') );
}

$roc            = getROC(1);

$statuses = ['Active','Pending','Deactivate'];


if(validateHttp('action') == 'generalTable'){
  $cachedSessionResult = null;
  if(array_key_exists("ncmCache",$_SESSION)){
    $cachedSessionResult = $_SESSION['ncmCache'][$baseUrl . $year . OUTLET_ID ];
  }

  if( !empty( $cachedSessionResult ) && validity( $cachedSessionResult ) ){
   // header('Content-Type: application/json'); 
   // dai(json_encode( $cachedSessionResult ));
  }

  $setting  = [];

  $sql = "SELECT MONTH(transactionDate) as month,
            transactionDate as date,
            SUM(transactionUnitsSold) as usold, 
            COUNT(transactionDate) as count, 
            SUM(transactionDiscount) as discount, 
            SUM(transactionTax) as tax, 
            SUM(transactionTotal) as total
          FROM transaction USE INDEX(transactionDate,transactionType)
          WHERE transactionType IN (0,3)
          AND transactionDate 
          BETWEEN ?
          AND ? 
          " . $roc . "
          GROUP BY MONTH(transactionDate)
          ORDER BY month ASC";

  $result   = ncmExecute($sql,[$startDate,$endDate],(1 * 2.628e+6),true);

  $table = '';
  $head = '<thead class="text-u-c">' .
          ' <tr>' .
          '   <th>Mes</th>' .
          '   <th class="text-center">Nuevos Clientes</th>'.
          '   <th class="text-center">Ventas</th>'.
          '   <th class="text-center">Descuentos</th>'.
          '   <th class="text-center">Ingresos</th>'.
          '   <th class="text-center">Egresos</th>'.
          '   <th class="text-center">Margen</th>'.
          ' </tr>' .
          '</thead>' .
          '<tbody>';
  
  $annotations = [];

  if($result){
    $months     = 0;
    $totalSold  = 0;
    while (!$result->EOF) {
      $fields   = $result->fields;
      $total    = $fields['total'] - $fields['discount'];
      $mNo      = $fields['month'] - 1;
      $elmes    = $meses[$mNo];

      $dateStr  = date('Y-m-01 00:00:00',strtotime($fields['date']) );
      $dateEnd  = date('Y-m-t 23:59:59',strtotime($fields['date']) );

      $nonAddingToSales = getNonAddingToSales(['startDate'=>$dateStr,'endDate'=>$dateEnd,'roc'=>$roc,'backThen'=>false,'cache'=>true]);

      $expenses = ncmExecute("SELECT SUM(transactionTotal) as total FROM transaction WHERE transactionType IN(1,4) AND transactionDate BETWEEN ? AND ?" . $roc,[$dateStr,$dateEnd],(1 * 2.628e+6));

      $return   = ncmExecute("SELECT SUM(ABS(transactionTotal)) as total FROM transaction WHERE transactionType IN(6) AND transactionDate BETWEEN ? AND ?" . $roc,[$dateStr,$dateEnd],(1 * 2.628e+6));

      $total    = ($total - $return['total']) - $nonAddingToSales['total'];

      $expenseTotal = $expenses['total'] ? $expenses['total'] : 0;
      $revenue      = round($total - $expenseTotal);

      $margen   = 100;
      if($total > 0 && $expenses['total'] > 0){
        $margen = ( $revenue / $total ) * 100;
        $margen = ($margen < 0) ? 0 : round($margen);
      }

      $customers   = ncmExecute("SELECT COUNT(contactId) as total FROM contact WHERE type = 1 AND contactDate BETWEEN ? AND ?" . $roc . ' LIMIT ' . $plansValues[PLAN]['max_customers'],[$dateStr,$dateEnd],(1 * 2.628e+6));


      $table .= '<tr class="clickrow pointer" data-from="' . date('Y-m-01 00:00:00', strtotime($fields['date'])) . '" data-to="' . date('Y-m-t 23:59:59', strtotime($fields['date'])) . '">' .
                ' <td class="font-bold" data-order="' . $mNo . '">' . $elmes . '</td>' .
                ' <td data-order="' . $customers['total'] . '" class="text-right bg-light lter"> ' . formatQty($customers['total']) . ' </td>' .
                ' <td data-order="' . $fields['count'] . '" class="text-right bg-light lter">' . formatQty($fields['count']) . '</td>' .
                ' <td data-order="' . $fields['discount'] . '" class="text-right bg-light lter"> ' . formatCurrentNumber($fields['discount']) . ' </td>' .
                ' <td data-order="' . $total . '" class="text-right bg-light lter"> ' . formatCurrentNumber($total) . ' </td>' .
                ' <td data-order="' . $expenseTotal . '" class="text-right bg-light lter"> ' . formatCurrentNumber($expenseTotal) . ' </td>' .
                ' <td data-order="' . $margen . '" class="text-right bg-light lter"> ' . formatQty($margen) . '% </td>' .
                '</tr>';

      if(date('Y', strtotime($fields['date'])) == '2020' && date('m', strtotime($fields['date'])) == '03'){
        $annotations[]  = ['value' => 'Marzo', 'position' => 'end', 'text' => 'COVID-19', 'color' => '#f05050'];
      }
      
      $barLabel[] = $elmes;
      $barData[]  = $total;
      $barDataB[] = $expenseTotal;
      $barDataC[] = $total - $expenseTotal;
      $barDataP[] = '';//$totalPercent;

      $totalSold  += $total;
      $months++;

      //$expTotal += $tExp['total'];
      //$cusTotal += $tCustomers['total'];
      
      $result->MoveNext(); 
      // $c++;
    }
    $result->Close();
  }

  $average = divider($totalSold, $months, true);

  $annotations[]    = ['value' => $average, 'position' => 'left', 'text' => 'Promedio ' . formatCurrentNumber($average), 'color' => '#1ab667', 'orientation' => 'horizontal'];
  $annotations[]    = ['value' => 'Diciembre', 'position' => 'right', 'text' => 'Fin de Año', 'color' => '#1ab667'];

  $foot =   '</tbody>' .
            '<tfoot>' .
            ' <tr>' .
            '   <th>TOTAL</th>' .
            '   <th class="text-right"></th>' .
            '   <th class="text-right"></th>' .
            '   <th class="text-right"></th>' .
            '   <th class="text-right"></th>' .
            '   <th class="text-right"></th>' .
            '   <th class="text-right"></th>' .
            ' </tr>' .
            '</tfoot>';

  $table .= '</tbody>';

  $fullTable            = $head . $table . $foot;
  $jsonResult['table']  = $fullTable;

  $jsonResult['chart']  = [
                            'labels'  => $barLabel,
                            'data'    => $barData,
                            'dataB'   => $barDataB,
                            'dataC'   => $barDataC,
                            'annotations' => $annotations
                          ];

  $_SESSION['ncmCache'][$baseUrl . $year . OUTLET_ID ] = $jsonResult;

  header('Content-Type: application/json'); 
  dai(json_encode($jsonResult));
}

?>


  <?=menuReports('',false);?>
    
  <?php
  $company  = ncmExecute('SELECT companyDate FROM company WHERE companyId = ?',[COMPANY_ID]);
  $yCreated = date('Y', strtotime($company['companyDate']));
  $yNow     = date('Y');

  if($yNow > $yCreated){

    $pickerReplace =  '<span class="btn-group" data-y="' . $yCreated . '">' .
                      ' <span class="dropdown" title="Año" data-placement="right">' .
                      '   <a href="#" class="btn dropdown-toggle b b-light bg-white font-bold r-3x disabled" data-toggle="dropdown" aria-expanded="false" id="yearPickerBtn">' .
                      '     <span class="material-icons m-r-xs">insert_chart_outlined</span>' . (validateHttp('y') ? validateHttp('y') : date('Y')) .
                      '   </a>' .
                      '   <ul class="dropdown-menu animated fadeIn speed-4x" role="menu">';

                      while($yNow >= $yCreated){
                        $pickerReplace .=  '     <li>' .
                                          '       <a class="text-default" href="/@#report_summary_year?y=' . $yNow . '">' .
                                                $yNow .
                                          '       </a>' .
                                          '     </li>';
                        $yNow--;
                      }
    

    $pickerReplace .=  '   </ul>' .
                      ' </span>' .
                      '</span>';

  }else{
    $pickerReplace =  '<a href="#" data-y="' . $yCreated . '" class="btn btn-default btn-rounded text-u-c font-bold disabled" disabled><span class="material-icons m-r-xs">insert_chart_outlined</span>' . $yNow . '</a>';
  }

  echo reportsDayAndTitle([
                            'title'         => '<div class="text-md text-right font-default">Resumen anual de</div> Ingresos y Egresos',
                            'hideDate'      => true,
                            'pickerReplace' => $pickerReplace,
                            'chartId'       => 'summaryYearChart'
                          ]);

  ?>

  <div class="col-xs-12 clear wrapper panel r-24x bg-white push-chat-down">
    <div class="tableContainer table-responsive">
        <table class="table table1 col-xs-12 no-padder" id="tableYear">
          <?=placeHolderLoader('table')?>
        </table>
      </div>
  </div>

  <script>
    $(document).ready(function(){

      FastClick.attach(document.body);

      var baseUrl = '<?=$baseUrl?>';
      var rawUrl  = baseUrl + "?action=generalTable&y=<?=validateHttp('y')?>";
      var url     = rawUrl;
      var offset  = '<?=$offsetDetail?>';
      var limit   = '<?=$limitDetail?>';
      var currency= '<?=CURRENCY?>';

      var xhr = ncmHelpers.load({
        url         : url,
        httpType    : 'GET',
        hideLoader  : true,
        type        : 'json',
        success     : (result) => {

          var info1 = {
                "container"   : ".tableContainer",
                "url"         : url,
                "rawUrl"      : rawUrl,
                "iniData"     : result.table,
                "table"       : ".table1",
                "sort"        : 0,
                "footerSumCol"  : [1,2,3,4,5],
                "currency"    : currency,
                "decimal"     : decimal,
                "thousand"    : thousandSeparator,
                "offset"      : offset,
                "limit"       : limit,
                "noMoreBtn"   : true,
                "tableName"   : 'tableYear',
                "fileTitle"   : 'Resumen de ventas anual',
                "ncmTools"      : {
                                    left  : '',
                                    right   : ''
                                  },
                "clickCB"       : function(event,tis){
                                    var url   = '/@#report_summary';
                                    var from  = tis.data('from');
                                    var to    = tis.data('to');
                                    $.post(url , { 'from' : from, 'to' : to }, function() {
                                        window.location.href = url;
                                    });
                                  }
          };

          ncmDataTables(info1);

          if(result.chart.data){
            $('#summaryYearChart').removeClass('hidden');
            $('#loadingChart').addClass('hidden');

            Chart.defaults.global.responsive          = true;
            Chart.defaults.global.maintainAspectRatio = false;
            Chart.defaults.global.legend.display      = false;

            var barData = result.chart.data.map(function(item) {
                return item;
            });

            var barDataB = result.chart.dataB.map(function(item) {
                return item;
            });

            var barDataC = result.chart.dataC.map(function(item) {
                return item;
            });

            var dataD = {
                labels: result.chart.labels,
                datasets: [
                            {
                              label                     : "Egresos",
                              data                      : barDataB,
                              type                      : 'line',

                              backgroundColor           : chartSecondColor,
                              borderColor               : chartSecondColor,
                              pointColor                : chartSecondColor,
                              pointHoverRadius          : 8,
                              pointHoverBorderColor     : "#fff",
                              pointHoverBackgroundColor : chartSecondColor,
                              pointBorderColor          : chartSecondColor,
                              pointBackgroundColor      : chartSecondColor,
                              pointRadius               : 3,
                              pointHoverBorderWidth     : 3,
                              pointBorderWidth          : 1,
                              pointHitRadius            : 20,
                              borderWidth               : 3,
                              fill                      : false
                              
                            },
                            {
                              label                     : "Margen",
                              data                      : barDataC,
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
                              label                     : "Ingresos",
                              data                      : barData,
                              backgroundColor           : '#4cb6cb'
                            }
                          ]
            };

            var annots    = result.chart.annotations;
            var recAnnots = [];
            if(ncmHelpers.validity(annots)){
              recAnnots = annots.map(function(val, index) {
                var id        = "vline" + index;
                var mode      = "vertical";
                var scaleId   = "x-axis-0";
                var position  = iftn(val.position,'center');

                if(val.orientation == 'horizontal'){
                  id        = "hline" + index;
                  mode      = "horizontal";
                  scaleId   = "y-axis-0";
                }

                return {
                  type      : "line",
                  id        : id,
                  mode      : mode,
                  scaleID   : scaleId,
                  value     : val.value,
                  borderColor: val.color,
                  borderWidth: 2,
                  borderDash : [2, 7],
                  borderDashOffset : 5,
                  label     : {
                    backgroundColor: 'rgba(77,93,110,0.6)',
                    enabled: true,
                    position: position,
                    content: val.text
                  }
                };
              });
            }

            setTimeout(function(){

              chartBarStackedGraphOptions.scales.xAxes[0].stacked = false;
              chartBarStackedGraphOptions.scales.yAxes[0].stacked = false;
              chartBarStackedGraphOptions.annotation = {
                                                          drawTime    : "afterDatasetsDraw",
                                                          annotations : recAnnots
                                                        };
              
              var methods = new Chart($('#summaryYearChart'), {
                  type        : 'bar',
                  data        : dataD,
                  animation   : true,
                  options     : chartBarStackedGraphOptions
              });

              chartBarStackedGraphOptions.annotation = {};
              
            }, 200);
          }

          $('#yearPickerBtn').removeClass('disabled');

        },
        fail        : () => {
          $('#yearPickerBtn').removeClass('disabled');
        }

      });
  
      window.xhrs.push(xhr);

    });

  </script>