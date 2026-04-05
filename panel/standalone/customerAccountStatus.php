<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));
define('CUSTOMER_ID', dec($data[1]));

$setting  = ncmExecute("SELECT * FROM setting WHERE companyId = ?",[COMPANY_ID]);
$modules  = ncmExecute("SELECT * FROM module WHERE companyId = ?",[COMPANY_ID]);
$outlet   = ncmExecute("SELECT outletId FROM outlet WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
define('OUTLET_ID', $outlet['outletId']);

date_default_timezone_set(TIMEZONE);

define('TODAY', date('Y-m-d H:i:s'));

if($modules['epos']){
  $epos = json_decode($modules['eposData'],true);
}

if(validateBool('action')){
  $result   = ncmExecute("SELECT  customerId as id, 
                  transactionId as saleId,
                  transactionDate as date,
                  transactionDueDate as dueDate,
                  invoiceNo as invoice,
                  invoicePrefix as prefix,
                  transactionTotal as total,
                  transactionDiscount as discount,
                  transactionParentId as parent,
                  transactionUID as tUID,
                  outletId as outlet,
                  registerId as register
              FROM transaction
              WHERE transactionComplete < 1
              AND transactionType = 3
              AND customerId = " . CUSTOMER_ID . "
              AND companyId = " . COMPANY_ID . "
              ORDER BY dueDate ASC",[],false,true);

  $contactData  = getContactData(CUSTOMER_ID,'uid');
  $customerName = getCustomerName($contactData);
  $customerRUC  = $contactData['ruc'];
  ?>
  
  <div class="col-xs-12 wrapper"> 
    <div class="m-b-lg text-center col-xs-12">
      <a href="#" class="thumb-md animated fadeInDown"> 
        <img src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
      </a>
    </div>
    <div class="col-xs-12 h2 font-bold text-dark text-center animated fadeIn"><?=COMPANY_NAME?></div>
    <div class="text-center h4 col-xs-12 m-b">Estado de cuenta</div> 
    
    
    <div class="col-md-2 col-sm-1"></div>
    <div class="col-md-8 col-sm-10 no-padder">
      <div class="col-xs-6 h2 font-bold m-b">
        <div class="font-normal text-sm"><?=iftn($customerRUC,'-')?></div>
        <?=iftn($customerName,'Sin Cliente Asociado')?>
      </div>
      <div class="col-xs-6 h2 font-bold m-b text-right">
        <div class="font-normal text-sm">Deuda total</div>
        <div id="totalDebt"><?=CURRENCY?> 0.00</div>
      </div>

      <?php
      if($result){
        $SQLcompanyId     = ' companyId = ' . COMPANY_ID;
        $allPayments      = getAllToPayTransactions(false, ' AND customerId = ' . CUSTOMER_ID);
        $totalToPay       = 0;

        while (!$result->EOF) {
          $fields     = $result->fields;
          $total      = $fields['total']-$fields['discount'];

          $payed      = $allPayments[$fields['saleId']];
          $topay      = $total - $payed;

          $totalToPay += $topay;

          $totalPaid    = 0;
          $totalSales   = 0;
          $totalDebt    = 0;
          $needsTopay   = false;
      ?>

      <div class="col-xs-12 wrapper panel r-24x">

          <div class="col-xs-12 no-padder">

            <div class="col-xs-12 wrapper no-border text-u-c font-bold">
              <div class="col-sm-3 col-xs-6 no-padder"># Documento</div>
              <div class="col-sm-3 col-xs-6 no-padder">Fecha</div>
              <div class="col-sm-3 col-xs-6 no-padder">Vencimiento</div>
              <div class="col-sm-3 col-xs-6 no-padder text-right">Total</div>
            </div>

            <div class="col-xs-12 wrapper no-border b-b bg-light lter">
              <div class="col-sm-3 col-xs-6 no-padder"><?=$fields['prefix'] . $fields['invoice']?></div>
              <div class="col-sm-3 col-xs-6 no-padder"><?=niceDate($fields['date'])?></div>
              <div class="col-sm-3 col-xs-6 no-padder"><?=niceDate($fields['dueDate'])?></div>
              <div class="col-sm-3 col-xs-6 no-padder text-right"><?=CURRENCY . formatCurrentNumber($total)?></div>
            </div>

            <div class="col-xs-12 wrapper">
              <?php
              $items = ncmExecute("SELECT itemSoldUnits, itemId, itemSoldTotal FROM itemSold WHERE transactionId = ?",[$fields['saleId']],false,true);
              if($items){
                while (!$items->EOF) {
                  $item     = $items->fields;
                  $allItems = getItemData($item['itemId']);
              ?>
                  <div class="col-sm-3 hidden-xs no-padder"></div>
                  <div class="col-sm-9 col-xs-12 no-padder b-l b-5x">
                    <div class="col-sm-5 col-xs-12 wrapper text-left b-b"><?=$allItems['itemName']?></div>
                    <div class="col-sm-2 col-xs-6 wrapper text-right b-b"><?=formatCurrentNumber($item['itemSoldUnits'])?></div>
                    <div class="col-sm-5 col-xs-6 wrapper text-right b-b"><?=CURRENCY . formatCurrentNumber($item['itemSoldTotal'])?></div>
                  </div> 

              <?php
                  $items->MoveNext();
                }
              }
              ?>
              
            </div>

            <div class="col-xs-12 no-padder font-bold text-u-c text-right">
              <div class="col-sm-3 col-xs-6 wrapper">Pagado:</div>
              <div class="col-sm-3 col-xs-6 wrapper"><?=CURRENCY . formatCurrentNumber($payed)?></div>
              <div class="col-sm-3 col-xs-6 wrapper">Deuda:</div>
              <div class="col-sm-3 col-xs-6 wrapper"><?=CURRENCY . formatCurrentNumber($topay)?></div>
            </div> 

            <?php
            if($modules['epos']){
              $comission       = round( ($epos['rate'] / 100) * $topay );
              $comTax          = round( ($epos['tax'] / 100) * $comission );

              $url = ePOSLink([
                                'company'   => enc(COMPANY_ID), 
                                'outlet'    => enc($fields['outlet']), 
                                'register'  => enc($fields['register']), 
                                'amount'    => $topay, 
                                'saleAmount'=> $topay, 
                                'tax'       => $comTax,
                                'comission' => $comission,
                                'customer'  => enc(CUSTOMER_ID), 
                                'uid'       => $fields['tUID'],
                                'date'      => TODAY
                              ]);
            ?>
            <div class="col-xs-12 text-center m-t-md">
              <a href="<?=$url;?>" class="btn btn-info btn-lg text-u-c font-bold rounded" target="_blank">Realizar pago online</a> 
            </div>
            <?php
            }
            ?>

          </div>
      </div>

      <?php
          $result->MoveNext();
        }
      ?>
      <script type="text/javascript">
        $(document).ready(function(){
          $('#totalDebt').text("<?=CURRENCY . formatCurrentNumber($totalToPay)?>");
        });
      </script>
      <?php
      }else{
      ?>
      <div class="text-center col-xs-12 wrapper noDataMessage m-t-lg">
        <img src="https://panel.encom.app/images/emptystate3.png" height="140">
        <h1 class="font-bold">No posee cuentas pendientes</h1>
        
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
  <title>Estado de Cuenta</title>
  <meta property="og:title" content="<?=COMPANY_NAME;?>" />
  <meta property="og:image" content="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
  <?php
  loadCDNFiles([],'css');
  ?>
</head>
<body class="bg-light lter">
<div id="results" class="col-xs-12 no-padder">
  <div class="col-xs-12 font-bold text-center h2 text-u-c col-xs-12 wrapper-lg">Cargando</div> 
</div>
<a href="https://encom.app?utm_source=ENCOM_account_status&utm_medium=ENCOM_footer_icon&
utm_campaign=<?=COMPANY_NAME?>" class="m-t-lg m-b-lg text-center block">
  <div class="text-muted">Usamos</div>
  <img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
</a>
<div class="wrapper-lg"></div>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>
<?php
loadCDNFiles([],'js');
?>
<script>
    $(document).ready(function(){
      ncmUI.setDarkMode.auto();
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
