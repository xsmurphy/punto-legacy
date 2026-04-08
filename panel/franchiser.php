<?php
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
include_once("libraries/countries.php");

$baseUrl        = '/' . basename(__FILE__,'.php');
$limitDetail    = 500;
$offsetDetail   = 0;
$startDate      = date('Y-m-01 00:00:00');
$endDate        = date('Y-m-d 23:59:59', strtotime('today'));
$startDateBack  = date('Y-m-d 00:00:00', strtotime("-1 months", strtotime($startDate) ) );
$endDateBack    = date('Y-m-d 23:59:59', strtotime("-1 months", strtotime($endDate) ) );

$statuses = ['Active','Pending','Deactivate'];

//enter company
if(validateHttp('u')){
  $url    = base64_decode( validateHttp('u') );
  $c      = explode(',', $url)[0];
  $p      = explode(',', $url)[1];

  $result = ncmExecute('SELECT * FROM contact WHERE role = "1" AND type = 0 AND companyId = ? LIMIT 1', [dec( $c )]);

  $outlet = ncmExecute("SELECT
                            *
                          FROM outlet
                          WHERE
                            companyId = ? LIMIT 1",[dec( $c )] );

  $company = ncmExecute(" SELECT
                            *
                          FROM company
                          WHERE parentId = ?
                          AND companyId = ? LIMIT 1",[dec( $p ),dec( $c )]);

  if(!$company){
    header('location:/');
  }

  $outletCount = ncmExecute("SELECT
                  COUNT(outletId) as count
                FROM outlet
                WHERE
                  outletStatus = 1
                AND companyId = ?",array(dec( $c )));

  //die($outlet->fields['outletId']);

  unset($result['salt'],$result['contactPassword'],$result['role']);

  $_SESSION['last_activity']          = time();
  $_SESSION['user']['companyId']      = enc($result['companyId']);

  $_SESSION['user']['companyStatus']  = $company['status'];
  $_SESSION['user']['companyParent']  = 0;
  //$_SESSION['user']['userId']         = enc($result['contactId']);
  $_SESSION['user']['userName']     = $result['contactName'];
  $_SESSION['user']['userEmail']    = $result['contactEmail'];
  $_SESSION['user']['role']         = enc($result['role']);
  $_SESSION['user']['outletId']     = enc($outlet['outletId']);
  $_SESSION['user']['registerId']   = 0;
  $_SESSION['user']['plan']         = enc($company['plan']);
  $_SESSION['user']['planExpires']  = $company['expiresAt'];
  $_SESSION['user']['outletsCount'] = $outletCount['count'];
  $_SESSION['user']['startDate']    = false;
  $_SESSION['user']['endDate']      = false;

  $_SESSION['ncmCache']             = false;

  header('location:/@#dashboard');
  dai();
}

if(validateHttp('action') == 'delete' && validateHttp('id')){
  dai();
  $delete = $db->Execute('DELETE FROM cinvoice WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM clocking WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM contact WHERE companyId = ? AND type IN (0,2)',array($_GET['id']));//no borro los cliente solo users y suppliers para poder crear un mailing list
  $delete = $db->Execute('DELETE FROM contact WHERE companyId = ? AND type = 1 AND contactEmail = ""',array($_GET['id']));//elimino los clientes que no tienen email
  //$delete = $db->Execute('DELETE FROM cpayments WHERE companyId = ?',array($_GET['id'])); //este no borro
  $delete = $db->Execute('DELETE FROM customerAddress WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM drawer WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM ecommerce WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM expenses WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM inventory WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM inventoryHistory WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM isCustomerOf WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM item WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM itemSold WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM order WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM outlet WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM register WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM registerLog WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM satisfaction WHERE companyId = ?',array($_GET['id']));
  //$delete = $db->Execute('DELETE FROM company WHERE companyId = ?',array($_GET['id'])); //tampoco borro este para tener registro de la empresa
  $delete = $db->Execute('DELETE FROM taxonomy WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM transaction WHERE companyId = ?',array($_GET['id']));
  $delete = $db->Execute('DELETE FROM company WHERE companyId = ?',array($_GET['id']));

  if($delete == true){
    echo 'true';
  }else{
    echo $db->ErrorMsg();
  }
  dai();
  //poner id de empresa al inicio de cada imagen para poder identificar y eliminar posteriormente
}

if(validateHttp('action') == 'generalTable'){

  $setting  = [];
  $result   = ncmExecute('SELECT a.parentId, b.* FROM company a, setting b WHERE a.companyId = b.companyId AND a.parentId = ? LIMIT 100',[COMPANY_ID],false,true);

  $ins = [];
  $tComps = 0;
  if($result){
    while (!$result->EOF) {
      $ins[] = $result->fields['companyId'];
      $setting[$result->fields['companyId']] = [
                                                "name"    =>  $result->fields['settingName'],
                                                "country" =>  $result->fields['settingCountry'],
                                                "category"=>  $result->fields['settingCompanyCategoryId']
                                                ];
      $tComps++;
      $result->MoveNext(); 
    }
    $result->Close();
  }

  $userEmail  = [];
  $contact     = ncmExecute("SELECT contactEmail, companyId FROM contact WHERE main = 'true' AND type = 0 AND companyId IN(" . implode(',', $ins) . ")",[],false,true);

  if($contact){
    while (!$contact->EOF) {
        $userEmail[$contact->fields['companyId']] = array(
                          "email"=>$contact->fields['contactEmail']
                          );
        $contact->MoveNext(); 
    }
    $contact->Close();
  }

  $result = ncmExecute('SELECT * FROM company WHERE parentId = ?',[COMPANY_ID],false,true);

  $table = '';
  $head = '<thead class="text-u-c">' .
          ' <tr>' .
          '   <th></th>' .
          '   <th>Nombre</th>' .
          '   <th>Email</th>' .
          '   <th>Creado</th>' .
          '   <th class="text-center">Clientes</th>' .
          '   <th class="text-center">Egresos</th>' .
          '   <th class="text-center">Ingresos</th>' .
          '   <th class="text-center">Margen</th>' .
          '   <th></th>' .
          ' </tr>' .
          '</thead>' .
          '<tbody>';
  
  if($result){
    while (!$result->EOF) {

      $companyId  = $result->fields['companyId'];
      $encCompId  = enc($companyId);
      $compLast   = strtotime($result->fields['companyLastUpdate']);

      $compStats = '<span class="label bg-light">Inactive</span>';
      if($compLast > strtotime("-7 day")){
        $compStats = '<span class="label bg-success lter">Active</span>';
      }else if($compLast > strtotime("-30 day") || $result->fields['companyLastUpdate'] == ""){
        $compStats = '<span class="label bg-warning lter">Stand By</span>';
      }

      if($result->fields['status'] == 'Active'){
        $status = 'bg-success';
      }else if($result->fields['status'] == 'Pending'){
        $status = 'bg-warning';
      }else{
        $status = 'bg-danger';
      }

      $plan = $plansValues[$result->fields['plan']]['name'];

      $tSold = ncmExecute(
                            "SELECT SUM(transactionTotal) as total, 
                              SUM(transactionDiscount) as discount
                            FROM transaction 
                            WHERE transactionType IN(0,3)
                            AND transactionDate
                            BETWEEN ? 
                            AND ?
                            AND companyId = ?"
                            ,[ $startDate,$endDate,$result->fields['companyId'] ],true
                          );

      $tSoldB = ncmExecute(
                            "SELECT SUM(transactionTotal) as total, 
                              SUM(transactionDiscount) as discount
                            FROM transaction 
                            WHERE transactionType IN(0,3)
                            AND transactionDate
                            BETWEEN ? 
                            AND ?
                            AND companyId = ?"
                            ,[ $startDateBack,$endDateBack,$result->fields['companyId'] ],true
                          );

      $tExp = ncmExecute(
                            "SELECT SUM(transactionTotal) as total
                            FROM transaction 
                            WHERE transactionType IN(1,4)
                            AND transactionDate
                            BETWEEN ? 
                            AND ?
                            AND companyId = ?"
                            ,[ $startDate,$endDate,$result->fields['companyId'] ],true
                          );

      $tCustomers = ncmExecute(
                            "SELECT COUNT(*) as total
                            FROM contact 
                            WHERE type = 1
                            AND companyId = ?"
                            ,[ $result->fields['companyId'] ],true
                          );

      $tTotal   = round($tSold['total'] - $tSold['discount']);
      $tTotalB  = round($tSoldB['total'] - $tSoldB['discount']);
      $revenue  = round($tTotal - $tExp['total']);

      $margen   = 100;
      if($tTotal > 0 && $tExp['total'] > 0){
        $margen = ($revenue / $tTotal) * 100;
        $margen = ($margen < 0) ? 0 : round($margen);
      }


      $totalsDif    = $tTotal - $tTotalB;

      if($tTotal == 0){
        $totalPercent = 0;
      }else{
        $totalPercent = round( ($totalsDif / $tTotal) * 100 );
      }

      $arrow        = ($tTotal > $tTotalB) ? '<span class="text-success">▲</span> ' : '<span class="text-danger">▼</span> ';
      $arrow        = $arrow . $totalPercent . '%';

      $arrowRaw     = ($tTotal > $tTotalB) ? '▲ ' : '▼ ';
      $arrowRaw     = $arrowRaw . $totalPercent . '%';

      $table .= '<tr>' .
                ' <td data-order="' . $totalPercent . '">' . $arrow . '</td>' .
                ' <td class="font-bold">' . $setting[$companyId]['name'] . '</td>' .
                ' <td> '.$userEmail[$companyId]['email'].' </td>' .
                ' <td data-order="' . $result->fields['createdAt'] . '"> ' . niceDate($result->fields['createdAt']) . ' </td>' .
                ' <td data-order="' . $tCustomers['total'] . '" class="text-right bg-light lter"> ' . formatQty($tCustomers['total']) . ' </td>' .
                ' <td data-order="' . round($tExp['total']) . '" class="text-right bg-light lter" data-format="money"> ' . formatCurrentNumber($tExp['total']) . ' </td>' .
                ' <td data-order="' . $tTotal . '" class="text-right bg-light lter" data-format="money"> ' . formatCurrentNumber($tTotal) . '</td>' .
                ' <td data-order="' . $margen . '" class="text-right bg-light lter" data-format="money"> ' . formatQty($margen) . '%</td>' .
                ' <td> <a class="loadDash block" target="_blank" href="?u=' . base64_encode( $encCompId . ',' . enc(COMPANY_ID) ) . '"><i class="material-icons text-info">launch</i></a> </td>' .
                '</tr>';

      
      $barLabel[] = $setting[$companyId]['name'] . ' ' . $arrowRaw;
      $barData[]  = $tTotal;
      $barDataB[] = $tTotalB;
      $barDataP[] = $totalPercent;

      $expTotal += $tExp['total'];
      $cusTotal += $tCustomers['total'];
      
      $result->MoveNext(); 
      $c++;
    }
    $result->Close();
  }

  $foot =   '</tbody>' .
            '<tfoot>' .
            ' <tr>' .
            '   <th colspan="4">TOTAL</th>' .
            '   <th class="text-right"></th>' .
            '   <th class="text-right"></th>' .
            '   <th class="text-right"></th>' .
            '   <th class="text-right"></th>' .
            '   <th></th>' .
            ' </tr>' .
            '</tfoot>';

  $table .= '</tbody>';

  $fullTable            = $head . $table . $foot;
  $jsonResult['table']  = $fullTable;
  $jsonResult['data']   = [
                            'count'     => formatQty($tComps),
                            'income'    => CURRENCY . formatCurrentNumber( array_sum($barData) ),
                            'outcome'   => CURRENCY . formatCurrentNumber( $expTotal ),
                            'customers' => formatQty($cusTotal)
                          ];

  $jsonResult['chart']  = [
                            'labels'  => $barLabel,
                            'data'    => $barData,
                            'dataB'   => $barDataB
                          ];

  header('Content-Type: application/json'); 
  dai(json_encode($jsonResult));
}

?>
<!DOCTYPE html>
<html class="no-js">
<head>
<!-- meta -->
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
<title>Franchiser - <?= APP_NAME ?></title>

<?php
loadCDNFiles([],'css');
?>
</head>
<body>  
  <section class="col-xs-12 wrapper">
    <div class="col-xs-12">
      <div class="col-sm-6 no-padder">
        <a href="/user-register?p=<?=enc(COMPANY_ID)?>" class="btn btn-info btn-rounded text-u-c font-bold m-r" target="_blank">Crear Empresa</a>
        <a href="logout" class="btn btn-default btn-rounded m-r">Cerrar Sesión</a>
      </div>

      <div class="col-sm-6 no-padder">
        <span class="yowhatsnew pull-right hidden-print hidden-xs" style="display: flex;">
          <a href="javascript:;" class="changloglink" style="margin-top: 8px;">¿Qué hay de nuevo?</a>
        </span>
      </div>
    </div>

    <?=reportsTitle(COMPANY_NAME . '<div class="text-sm font-bold m-t-xs text-muted">Ventas del ' . niceDate($startDate) . ' al ' . niceDate($endDate) . '</div>',false,false,false,true);?>
    <div class="col-xs-12 text-right text-xs text-muted">*Los totales anteriores van del <?=niceDate($startDateBack)?> al <?=niceDate($endDateBack)?></div>

    <div class="col-xs-12 no-padder text-center m-t-n">
      <section class="col-md-3 col-sm-6">
          <div class="b-b text-center wrapper-md">
          <div class="h1 m-t total font-bold globalTotal"> <?=placeHolderLoader()?> </div>
          Ingresos
        </div>
      </section>

      <section class="col-md-3 col-sm-6">
          <div class="b-b text-center wrapper-md">
          <div class="h1 m-t total font-bold globalExpenses"> <?=placeHolderLoader()?> </div>
          Egresos
        </div>
      </section>

      <section class="col-md-3 col-sm-6">
          <div class="b-b text-center wrapper-md">
          <div class="h1 m-t total font-bold globalCustomers"><?=placeHolderLoader()?></div>
          Clientes
        </div>
      </section>

      <section class="col-md-3 col-sm-6">
          <div class="b-b text-center wrapper-md">
          <div class="h1 m-t total font-bold globalCompanies"><?=placeHolderLoader()?></div>
          Franquicias
        </div>
      </section>
    </div>

    <div class="col-xs-12 clear wrapper panel r-24x bg-white push-chat-down">
      <div class="tableContainer table-responsive">
          <table class="table table1 col-xs-12 no-padder" id="tableCategories">
            <?=placeHolderLoader('table')?>
          </table>
        </div>
    </div>
    

  </section>

  <div class="modal fade" tabindex="-1" id="modalView" role="dialog">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-body">
          
        </div>
      </div>
    </div>
  </div>

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

 <?php
  footerInjector();
  loadCDNFiles(['/assets/vendor/js/Chart-2.9.4.min.js'],'js');
  ?>

  <script>
    $(document).ready(function(){
      window.onbeforeunload=function(){
          return "You sure?";
      }

      FastClick.attach(document.body);

      var baseUrl = '<?=$baseUrl?>';
      var rawUrl  = baseUrl + "?action=generalTable";
      var url     = rawUrl;

      $.get(url,function(result){

        var options = {
              "container"   : ".tableContainer",
              "url"       : url,
              "rawUrl"    : rawUrl,
              "iniData"   : result.table,
              "table"     : ".table1",
              "sort"      : 3,
              "currency"  : "<?=CURRENCY?>",
              "decimal"   : decimal,
              "thousand"  : thousandSeparator,
              "footerSumCol"  : [4,5,6],
              "noMoreBtn" : true,
              "ncmTools"  : {
                                left  : '<a href="#" class="btn btn-default exportTable" data-table="tableCategories" data-name="reporte_por_franquicia">Exportar Listado</a>',
                                right   : ''
                                }
        };

        manageTableLoad(options,function(){
          onClickWrap('.loadDash',function(event,tis){
            var url = tis.attr('href');
            window.open(url,'_blank');
          });

          onClickWrap('.createItemBtn',function(event,tis){
            loadForm('?action=insertForm','#modalView .modal-content',function(){
              $('#modalView').modal('show');
            });
          });

          onClickWrap('.openTr',function(event,tis){      
            var load = tis.attr('data-load');
            loadForm(load,'#modalView .modal-content',function(){
              $('#modalView').modal('show');
            });
          });

          onClickWrap('.cancelItemView',function(event,tis){
            $('#modalView').modal('hide');
          });
        });

        $('.globalCompanies').text(result.data.count);
        $('.globalTotal').text(result.data.income);
        $('.globalExpenses').text(result.data.outcome);
        $('.globalCustomers').text(result.data.customers);

        if(result.chart.data){
          $('#myChart').removeClass('hidden');
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

          var dataD = {
              labels: result.chart.labels,
              datasets: [
                          {
                            label   : "Total Actual",
                            data    : barData,
                            backgroundColor: '#4cb6cb'
                          },
                          {
                            label   : "Total Anterior",
                            data    : barDataB,
                            backgroundColor: '#939aa0'
                          }
                        ]
          };

          setTimeout(function(){

            chartBarStackedGraphOptions.scales.xAxes[0].stacked = false;
            chartBarStackedGraphOptions.scales.yAxes[0].stacked = false;
            
            var methods = new Chart($('#myChart'), {
                type        : 'bar',
                data        : dataD,
                animation   : true,
                options     : chartBarStackedGraphOptions
            });
            
          }, 400);
        }

      });

    });

  </script>
</body>
</html>
<?php
dai();
?>