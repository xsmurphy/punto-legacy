<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));
define('USR_ID', dec($data[1]));
define('FROM', $data[2]);
define('TO', $data[3]);

//print_r($data);
//echo USR_ID;

$setting = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
define('USER_NAME', getValue('contact','contactName','WHERE contactId = ' . USR_ID));

date_default_timezone_set(TIMEZONE);

define(TODAY, date('Y-m-d H:i:s'));

if(validateHttp('action')){

  $result     = ncmExecute( " SELECT  *
                              FROM itemSold
                              WHERE userId = ?
                              AND itemSoldDate BETWEEN ? AND ?
                              AND itemSoldComission > 0
                              ORDER BY itemSoldDate DESC
                            "
                          , [USR_ID,FROM,TO], false, true);

  $comresult  = ncmExecute( " SELECT  *
                              FROM comission
                              WHERE userId = ?
                              AND comissionDate BETWEEN ? AND ?
                              AND comissionTotal > 0
                              ORDER BY comissionDate DESC
                            "
                          , [USR_ID,FROM,TO], false, true);




  if(validateHttp('action') == 'export'){
    $excellRow[]  = ['# DOCUMENTO','FECHA','CLIENTE','ARTICULO','CANTIDAD','METODOS DE PAGO','COMISION'];

    if($result){
      while (!$result->EOF) {
        $fields     = $result->fields;
        $itm        = getItemData($fields['itemId']);

        if($itm['itemType'] == 'product'){
          $comission  = $fields['itemSoldComission'];
          $units      = $fields['itemSoldUnits'];
          $doc        = ncmExecute('SELECT invoiceNo, invoicePrefix,transactionPaymentType, customerId FROM transaction WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);
          $paymentType  = json_decode(iftn($doc['transactionPaymentType'],'{}'),true);
          $pMethods   = [];
          if(validity($paymentType)){
            foreach($paymentType as $pType){    
              $pMethods[] = getPaymentMethodName($pType['type']) . ': ' . formatCurrentNumber($pType['price']);
            }
          }

          $customerName = 'Sin Cliente';
          if($doc['customerId']){
            $cusData      = getCustomerData($doc['customerId'],'uid',true);
            $customerName = getCustomerName($cusData);
          }

          $excellRow[]  = [
                            $doc['invoiceNo'] . $doc['invoicePrefix'],
                            niceDate($fields['itemSoldDate']),
                            $customerName,
                            $itm['itemName'],
                            formatCurrentNumber($units),
                            implodes(' | ', $pMethods),
                            formatCurrentNumber($comission) . ''
                          ];
        }

        $result->MoveNext();
      }
    }

    if($comresult){
      while (!$comresult->EOF) {
        $fields     = $comresult->fields;
        $itm        = $fields['comissionSource'];

        $comission  = $fields['comissionTotal'];
        $units      = 1;

        if($itm == 'session'){
          $parent       = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);//agendamiento
          $doc          = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1',[$parent['transactionParentId']]);//factura de venta
          $paymentType  = json_decode(iftn($doc['transactionPaymentType'],'{}'),true);
          $pMethods     = [];

          $itmsInSession  = json_decode($parent['transactionDetails'],true);
          $servData       = getItemData(dec($itmsInSession[0]['itemId']));
          $itm            = $itm . '/' . $parent['invoiceNo'] . ' ' . $servData['itemName'];
        }

        $customerName = 'Sin Cliente';
        if($doc['customerId']){
          $cusData      = getCustomerData($doc['customerId'],'uid',true);
          $customerName = getCustomerName($cusData);
        }

        $excellRow[]  = [
                          $doc['invoicePrefix'] . $doc['invoiceNo'],
                          niceDate($fields['comissionDate']),
                          $customerName,
                          $itm,
                          formatCurrentNumber($units),
                          implodes(' | ', $pMethods),
                          formatCurrentNumber($comission) . ''
                        ];

        $comresult->MoveNext();
      }
    }

    generateXLSfromArray($excellRow,USER_NAME . '_' . COMPANY_NAME);

    dai();
  }

  /*if(validateHttp('action') == 'exports'){
    $excellRow[]  = ['# DOCUMENTO','FECHA','ARTICULO','CANTIDAD','METODOS DE PAGO','COMISION'];

    if($result){
      $allItems         = getAllItemsRaw();
      while (!$result->EOF) {
        $fields     = $result->fields;
        $itm        = $allItems[$fields['itemId']];

        if($itm['itemType'] == 'product'){
          $comission  = $fields['itemSoldComission'];
          $units      = $fields['itemSoldUnits'];
          $doc        = ncmExecute('SELECT invoiceNo, invoicePrefix,transactionPaymentType FROM transaction WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);
          $paymentType  = json_decode(iftn($doc['transactionPaymentType'],'{}'),true);
          $pMethods   = [];
          if(validity($paymentType)){
            foreach($paymentType as $pType){    
              $pMethods[] = getPaymentMethodName($pType['type']) . ': ' . formatCurrentNumber($pType['price']);
            }
          }

          $excellRow[]  = [
                            $doc['invoiceNo'] . $doc['invoicePrefix'],
                            niceDate($fields['itemSoldDate']),
                            $itm['itemName'],
                            formatCurrentNumber($units),
                            implodes(' | ', $pMethods),
                            formatCurrentNumber($comission)
                          ];
        }

        $result->MoveNext();
      }
    }

    if($comresult){
      while (!$comresult->EOF) {
        $fields     = $comresult->fields;
        $itm        = $fields['comissionSource'];

        $comission  = $fields['comissionTotal'];
        $units      = 1;

        if($itm == 'session'){
          $parent       = ncmExecute('SELECT transactionParentId, invoiceNo FROM transaction WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);
          $doc          = ncmExecute('SELECT invoiceNo,invoicePrefix,transactionPaymentType FROM transaction WHERE transactionId = ? LIMIT 1',[$parent['transactionParentId']]);
          $paymentType  = json_decode(iftn($doc['transactionPaymentType'],'{}'),true);
          $pMethods     = [];

          $itm          = $itm . '/' . $parent['invoiceNo'];
        }

        $excellRow[]  = [
                          $doc['invoiceNo'] . $doc['invoicePrefix'],
                          niceDate($fields['comissionDate']),
                          $itm,
                          formatCurrentNumber($units),
                          implodes(' | ', $pMethods),
                          formatCurrentNumber($comission)
                        ];

        $comresult->MoveNext();
      }
    }

    generateXLSfromArray($excellRow,USER_NAME . '_' . COMPANY_NAME);

    dai();
  }*/

  $isData = false;
  ?>
  
  <div class="col-xs-12 wrapper bg-light lter"> 
    <div class="m-b-lg text-center col-xs-12">
      <a href="#" class="thumb-md animated fadeInDown"> 
        <img src="/assets/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
      </a>
    </div>
    <div class="col-xs-12 h2 font-bold text-dark text-center animated fadeIn"><?=COMPANY_NAME?></div>
    <div class="text-center h4 col-xs-12 m-b">Detalle de Ventas</div> 
    
    
    <div class="col-md-2 col-sm-1"></div>
    <div class="col-md-8 col-sm-10 no-padder">
      <div class="col-sm-6 h2 font-bold m-b">
        <div class="font-normal text-sm"></div>
        <?=USER_NAME?>
      </div>
      <div class="col-sm-6 h2 font-bold m-b text-right">
        <div class="font-normal text-sm">Del <?=niceDate(FROM)?> al <?=niceDate(TO)?></div>
        <div id="totalDebt">0.000</div>
      </div>

      <div class="col-xs-12 wrapper panel r-3x">

          <div class="col-xs-12 no-padder">
            <div class="col-xs-12 no-padder hidden-print">
              <a href="/screens/userItemsSold?s=<?=validateHttp('s')?>&action=export" class="btn btn-default">Exportar a Excel</a>
            </div>
            <div class="col-xs-12 wrapper no-border text-u-c font-bold">
              <div class="col-sm-2 col-xs-6 no-padder"># Documento</div>
              <div class="col-sm-3 col-xs-6 no-padder">Fecha</div>
              <div class="col-sm-3 col-xs-6 no-padder">Artículo</div>
              <div class="col-sm-1 col-xs-6 no-padder text-right">Cantidad</div>
              <!--<div class="col-sm-3 col-xs-6 no-padder text-right">Total</div>-->
              <div class="col-sm-3 col-xs-6 no-padder text-right">Comisión</div>
            </div>

            <?php
            if($result){
              
              while (!$result->EOF) {
                $fields     = $result->fields;
                $itm        = getItemData($fields['itemId']);
                $doc        = ncmExecute('SELECT invoiceNo, invoicePrefix,transactionPaymentType,customerId FROM transaction WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);

                $comission  = $fields['itemSoldComission'];
                $units      = $fields['itemSoldUnits'];
                $total      += $comission;
                $totalU     += $units;
                $paymentType  = json_decode(iftn($doc['transactionPaymentType'],'{}'),true);
                $pMethods   = [];
                if(validity($paymentType)){
                  foreach($paymentType as $pType){    
                    $pMethods[] = getPaymentMethodName($pType['type']) . ': ' . formatCurrentNumber($pType['price']);
                  }
                }

                $customerName = 'Sin Cliente';
                if($doc['customerId']){
                  $cusData      = getCustomerData($doc['customerId'],'uid',true);
                  $customerName = getCustomerName($cusData);
                }
            ?>

                <div class="col-xs-12 wrapper no-border b-b">
                  <div class="col-sm-2 col-xs-6 no-padder"><?=$doc['invoiceNo'] . $doc['invoicePrefix']?></div>
                  <div class="col-sm-3 col-xs-6 no-padder"><?=niceDate($fields['itemSoldDate'])?></div>
                  <div class="col-sm-3 col-xs-6 no-padder"><?=$itm['itemName']?></div>
                  <div class="col-sm-1 col-xs-6 no-padder text-right"><?=formatCurrentNumber($units)?></div>
                  <div class="col-sm-3 col-xs-6 no-padder text-right"> <span data-toggle="tooltip" class="font-bold pointer" title="<?=implodes('<br>', $pMethods)?>"><?=CURRENCY . formatCurrentNumber($comission)?></span></div>
                </div>

            <?php
                  
                  $result->MoveNext();
                }
                $isData = true;
              }
            ?>

            <?php
            if($comresult){
              while (!$comresult->EOF) {
                $fields     = $comresult->fields;
                $itm        = $fields['comissionSource'];
                
                if($itm == 'session'){
                  $itm            = 'Sesión';
                  $parent         = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);
                  $doc            = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1',[$parent['transactionParentId']]);
                  $itmsInSession  = json_decode($parent['transactionDetails'],true);
                  $servData       = getItemData(dec($itmsInSession[0]['itemId']));
                  $itm            = '<span class="text-muted font-bold">' . $itm . '/' . $parent['invoiceNo'] . '</span> ' . $servData['itemName'];
                }else{
                  $doc = [];
                }

                $comission    = $fields['comissionTotal'];
                $units        = 1;
                $total        += $comission;
                $totalU       += $units;
                $paymentType  = json_decode(iftn($doc['transactionPaymentType'],'{}'),true);
                $pMethods     = [];

                if(validity($paymentType)){
                  foreach($paymentType as $pType){    
                    $pMethods[] = getPaymentMethodName($pType['type']) . ': ' . formatCurrentNumber($pType['price']);
                  }
                }
            ?>

                <div class="col-xs-12 wrapper no-border b-b">
                  <div class="col-sm-2 col-xs-6 no-padder" data-id="<?=$fields['comissionId']?>"><?=$doc['invoiceNo'] . $doc['invoicePrefix']?></div>
                  <div class="col-sm-3 col-xs-6 no-padder" data-date="<?=$doc['transactionDate']?>"><?=niceDate($fields['comissionDate'])?></div>
                  <div class="col-sm-3 col-xs-6 no-padder"><?=$itm?></div>
                  <div class="col-sm-1 col-xs-6 no-padder text-right"><?=formatCurrentNumber($units)?></div>
                  <div class="col-sm-3 col-xs-6 no-padder text-right">
                    <span data-toggle="tooltip" class="font-bold pointer"><?=CURRENCY . formatCurrentNumber($comission)?></span>
                  </div>
                </div>

            <?php
               
                  $comresult->MoveNext();
                }
                $isData = true;
              }
            ?>

            <div class="col-xs-12 wrapper no-border font-bold text-u-c">
              <div class="col-sm-3 col-xs-6 no-padder">Totales:</div>
              <div class="col-sm-3 col-xs-6 no-padder"></div>
              <div class="col-sm-3 col-xs-6 no-padder text-right"><?=formatCurrentNumber($totalU)?></div>
              <div class="col-sm-3 col-xs-6 no-padder text-right"><?=CURRENCY . formatCurrentNumber($total)?></div>
            </div>

          </div>
      </div>

      <script type="text/javascript">
        $(document).ready(function(){
          $('#totalDebt').text("<?=CURRENCY . formatCurrentNumber($total)?>");
        });
      </script>

      <?php
      if(!$isData){
      ?>
      <div class="text-center col-xs-12 wrapper noDataMessage m-t-lg">
        <img src="/images/emptystate3.png" height="140">
        <h1 class="font-bold">No posee ventas</h1>
        
      </div>
      <?php
      }
      ?>
    </div>
    <div class="col-md-2 col-sm-1"></div>
    
  </div>
  <?php
  dai();
}
?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title><?=USER_NAME?> Ventas</title>
  <meta property="og:title" content="<?=COMPANY_NAME;?>" />
  <meta property="og:image" content="/assets/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
  <?php
  loadCDNFiles([],'css');
  ?>
</head>
<body class="bg-light lter">
<div id="results" class="col-xs-12 no-padder">
  <div class="col-xs-12 font-bold text-center h2 text-u-c col-xs-12 wrapper-lg">Cargando</div> 
</div>

<a href="/?utm_source=ENCOM_user_item_sold&utm_medium=ENCOM_footer_icon&
utm_campaign=<?=COMPANY_NAME?>" class="m-t-lg m-b-lg text-center block hidden-print">
  <div class="text-muted">Usamos</div>
  <img src="/images/incomeLogoLgGray.png" width="80">
</a>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>
<?php
loadCDNFiles([
              '/assets/vendor/js/Chart-2.9.4.min.js',
              '/scripts/jquery.table2excel.js'
            ],'js');
?>
<script>
  $(document).ready(function(){

    $.get('?action=1&s=<?=$_GET['s']?>',function(result){
      if(result){
        $('#results').html(result);
        $('[data-toggle="tooltip"]').tooltip();
      }
    });
    
  });
</script>

</body>
</html>
<?php
dai();
?>