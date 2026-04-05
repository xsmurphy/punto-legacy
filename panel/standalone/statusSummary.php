<?php
include_once('sa_head.php');

$data     = explodes(',', base64_decode( validateHttp('s') ));

define('COMPANY_ID', dec($data[0]));

$setting  = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID],true);

setTimeZone(false,$setting);
define('TODAY', date('Y-m-d H:i:s'));

$date = iftn($data[1],TODAY);

define('DATE', $date);
define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
define('DAY_START', date('Y-m-d 00:00:00',strtotime(DATE)));
define('DAY_END', date('Y-m-d 23:59:59',strtotime(DATE)));

if(validateHttp('widget') == 'customers'){
  //$db->debug = true;
  $newC = ncmExecute("
                      SELECT COUNT(contactId) as totalnew
                      FROM contact 
                      WHERE type = 1 
                      AND contactDate 
                      BETWEEN ? 
                      AND ? 
                      AND companyId = ?", 
                      [DAY_START,DAY_END,COMPANY_ID],true);

  $oldC     = $db->Execute(
                          "SELECT (a.customerId) as totalold
                          FROM transaction a, contact b
                          WHERE a.transactionDate
                          BETWEEN ?
                          AND ?
                          AND (a.customerId IS NOT NULL AND a.customerId > 1)
                          AND a.companyId = ?
                          AND a.customerId = b.contactUID
                          AND b.contactDate < ?
                          AND b.type = 1"
                        ,
                        [DAY_START,DAY_END,COMPANY_ID,DAY_START],true
                      );

  $oldCount = validateResultFromDB($oldC,true);

  $new = 0;
  if($newC['totalnew']){
    $new = $newC['totalnew'];
  }

  $old = 0;
  if($oldCount){
    $old = $oldCount;
  }

  $out = json_encode( ['new' => formatCurrentNumber($new,'no'), 'old' => formatCurrentNumber($old,'no')] );

  header('Content-Type: application/json');
  dai($out);
}

$outlets = ncmExecute('SELECT * FROM outlet WHERE companyId = ?',[COMPANY_ID],false,true);

if(validateHttp('widget') == 'amounts'){

  $jsonOut = [];
  if($outlets){
    while (!$outlets->EOF) {
        $outletId   = $outlets->fields['outletId'];
        $outlet     = enc($outletId);
        $outletName = $outlets->fields['outletName'];

        $result = $db->Execute("SELECT SUM(transactionTotal) as total, 
          SUM(transactionDiscount) as discount, 
          SUM(transactionUnitsSold) as units,
          COUNT(transactionId) as count
          FROM transaction 
          WHERE transactionType IN(0,3)
          AND transactionDate
          BETWEEN ? 
          AND ?
          AND outletId = ?"
          ,array(DAY_START,DAY_END,$outletId));

        $finalTotal = $result->fields['total'] - $result->fields['discount'];
        
        $expen = $db->Execute("SELECT SUM(transactionTotal) as total, 
          SUM(transactionDiscount) as discount, 
          SUM(transactionUnitsSold) as units,
          COUNT(transactionId) as count
          FROM transaction 
          WHERE transactionType IN(1,4)
          AND transactionDate
          BETWEEN ? 
          AND ?
          AND outletId = ?"
          ,array(DAY_START,DAY_END,$outletId));

        $totalExpenses    = floatval($expen->fields['total']);
        
        $jsonOut[] = [
                        'sold'    => formatCurrentNumber($finalTotal), 
                        'soldRaw' => $finalTotal, 
                        'exp'     => formatCurrentNumber($totalExpenses), 
                        'expRaw'  => $totalExpenses, 
                        'outName' => $outletName,
                        'outId'   => $outlet
                      ];

        $outlets->MoveNext(); 
    }
  }

  $out = json_encode($jsonOut);

  header('Content-Type: application/json');
  dai($out);
}

if(validateHttp('widget') == 'topItems'){
  $sql = "SELECT SUM(a.itemSoldUnits) as count, a.itemId as id, c.itemName as item
          FROM itemSold a, transaction b, item c
          WHERE a.itemSoldDate
          BETWEEN ?
          AND ?
          AND b.companyId = ?
          AND b.transactionType IN(0,3)
          AND a.transactionId = b.transactionId
          AND a.itemId = c.itemId
          GROUP BY item
          ORDER BY count DESC
          LIMIT 5";

  $result    = ncmExecute($sql,[DAY_START,DAY_END,COMPANY_ID],false,true);

  $jsonOut = [];

  if($result){
   while (!$result->EOF) {
     $jsonOut[] = ['count' => round($result->fields['count']), 'name' => $result->fields['item']];
     $result->MoveNext(); 
   }
   $result->Close();  
  }

  $out = json_encode($jsonOut);

  header('Content-Type: application/json');
  dai($out);
}

$dias   = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$meses  = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

$dia    = $dias[date('w',strtotime(DATE))] . ' ' . date('d',strtotime(DATE));
$mes    = $meses[date('n',strtotime(DATE)) - 1];
$ano    = date('Y',strtotime(DATE));
$literalDate = $dia . ' de ' . $mes . ', ' . $ano;
?>

<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Resumen del <?=$literalDate?></title>
  <meta property="og:title" content="<?=COMPANY_NAME;?>" />
  <meta property="og:image" content="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
  <?php
  loadCDNFiles([],'css');
  ?>
</head>
<body class="bg-light lter">
<div class="col-xs-12 wrapper bg-light lter"> 
    
    <div class="col-md-4 col-sm-3"></div>
    <div class="col-md-4 col-sm-6 no-padder">
      
      <div class="col-xs-12 no-padder">

          <div class="col-xs-12 no-padder m-b no-bg" id="incomeOutcomeStatsWidget">
               
            <div class="col-xs-12 wrapper bg-info gradBgBlue animateBg r-3x clear">

              <div class="col-xs-12 no-padder m-b-lg m-t">
                <div class="col-xs-12 h3 font-bold">
                  <a href="#" class="thumb m-r pull-left"> 
                    <img src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
                  </a>

                  <div class="text-muted m-t-xs text-sm"><?=$literalDate?></div>
                  Resumen diario
                </div>
              </div>

              <div class="col-xs-12 no-border b-dashed b-b b-light"></div>

               <div class="col-xs-12 col-sm-12 col-md-6 m-t m-b">
                 <div class="text-center text-sm">
                   <div class="h1 m-t font-bold">
                     <span class="totalSold"><?=placeHolderLoader(false,5)?></span>
                   </div>
                   <div class="text-u-c font-bold text-muted">Ingresos en <?=CURRENCY?></div>
                 </div> 
               </div>

               <div class="col-xs-12 col-sm-6 col-md-6 m-t m-b"> 
                 <div class="text-center text-sm">

                   <div class="h1 m-t font-bold">
                     <span class="totalExp"><?=placeHolderLoader(false,5)?></span>
                   </div>
                   <div class="text-u-c font-bold text-muted">Egresos en <?=CURRENCY?></div>

                 </div> 
               </div>

               <div class="col-xs-12 text-center">
                  <a href="#" class="btn btn-block btn-rounded btn-sm m-b-n" id="segmentsBtn"><i class="material-icons">keyboard_arrow_down</i></a>
              </div>

            </div>

            <div class="col-xs-12 hidden animated fadeInUp speed-3x" id="segments">
              
            </div>

            <div class="panel col-xs-12 wrapper r-3x m-t">
              <div class="col-xs-6 b-r">
                 <div class="text-center text-sm">
                   <div class="h2 m-t-xs m-b-xs font-bold">
                     <span class="newCustomers text-dark"><?=placeHolderLoader(false,2)?></span>
                   </div> 
                   <div class="text-u-c font-bold text-muted">Clientes Nuevos</div>
                 </div> 
               </div>

               <div class="col-xs-6">
                 <div class="text-center text-sm">
                   <div class="h2 m-t-xs m-b-xs font-bold">
                     <span class="oldCustomers text-dark"><?=placeHolderLoader(false,2)?></span>
                   </div> 
                   <div class="text-u-c font-bold text-muted">Recurrentes</div>
                 </div> 
               </div>
              
            </div>

            <div class="panel col-xs-12 wrapper r-3x">
              <div class="h4 font-bold m-b">Artículos más populares</div>
              <div>
                <table class="table" id="topItems">
                  <?=placeHolderLoader('table-sm')?>
                </table>
              </div>
            </div>

          </div>

      </div> 

    </div>
    <div class="col-md-4 col-sm-3"></div>
    
  </div>

  

  <script id="outletsDetail" type="text/html">
    <table class="table text-muted font-bold m-t">
      <tbody>
        {{#data}}
        <tr>
          <td>{{outName}}</td>
          <td class="text-right text-dark">{{sold}}</td>
        </tr>
        <tr class="hidden">
          <td colspan="2">
            <table class="table text-muted font-bold m-t">
              <tbody>
                <tr>
                  <td>TURNO 1</td>
                  <td class="text-right text-dark">2.345.653</td>
                </tr>
                <tr>
                  <td>TURNO 2</td>
                  <td class="text-right text-dark">1.234.654</td>
                </tr>
              </tbody>
            </table>
          </td>
        </tr>
        {{/data}}
      </tbody>
    </table>
  </script>

  <script id="topItemsDetail" type="text/html">
    <table class="table text-muted font-bold m-t">
      <tbody>
        {{#data}}
        <tr>
          <td>{{name}}</td>
          <td class="text-right text-dark">{{count}}</td>
        </tr>
        {{/data}}
      </tbody>
    </table>
  </script>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>
<?php
loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/mustache.js/3.1.0/mustache.min.js'],'js');
?>
<script>
  $(document).ready(function(){

    $('#segmentsBtn').on('click',function(e){
      e.preventDefault();
      $('#segments').toggleClass('hidden');
    });

    $.get('?widget=customers&s=<?=$_GET['s']?>',function(result){
      if(result){
        $('.newCustomers').text(result.new);
        $('.oldCustomers').text(result.old);
      }
    });

    $.get('?widget=topItems&s=<?=$_GET['s']?>',function(result){
      if(result){
        var template    = $('#topItemsDetail').html();
        var mustached   = Mustache.render(template, {'data':result});
        $('#topItems').html(mustached);
      }
    });

    $.get('?widget=amounts&s=<?=$_GET['s']?>',function(result){
      if(result){
        
        var total     = 0;
        var totalExp  = 0;

        var template    = $('#outletsDetail').html();
        var mustached   = Mustache.render(template, {'data':result});

        $('#segments').html(mustached);

        $.each(result,function(i, value){
          total     += parseFloat(value.soldRaw);
          totalExp  += parseFloat(value.expRaw);
        });

        console.log(total,totalExp);

        $('.totalSold').text(formatNumber(total,'','no'));
        $('.totalExp').text(formatNumber(totalExp,'','no'));
      }
    });
    
  });
</script>

</body>
</html>
<?php
dai();
?>