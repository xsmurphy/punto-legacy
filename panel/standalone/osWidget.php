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

$totalC = ncmExecute(
                      "SELECT COUNT(contactId) as totalcount
                      FROM contact 
                      WHERE type = 1 AND companyId = ?",[COMPANY_ID],true
                    );

$total = 0;
if($totalC['totalcount']){
  $total = $totalC['totalcount'];
}

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
                        AND companyId = ?
                        AND a.transactionType IN(0,3)
                        AND a.customerId = b.contactUID
                        AND b.contactDate < ?
                        AND b.type = 1
                        GROUP BY a.customerId"
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

$contNewF = formatQty($new);
$contOldF = formatQty($old);
$contTotF = formatQty($total);

$finalTotal = 0;
$customerAverage = 0;

$result = $db->Execute("SELECT SUM(transactionTotal) as total, 
  SUM(transactionDiscount) as discount, 
  SUM(transactionUnitsSold) as units,
  COUNT(transactionId) as count
  FROM transaction 
  WHERE transactionType IN(0,3)
  AND transactionDate
  BETWEEN ? 
  AND ?
  AND companyId = ?"
  ,[DAY_START,DAY_END,COMPANY_ID]);

if($result){
  $finalTotal       = $result->fields['total'] - $result->fields['discount'];
  $customerAverage  = divider($result->fields['total'],$result->fields['count'],true);
}

$expen = $db->Execute("SELECT SUM(transactionTotal) as total, 
  SUM(transactionDiscount) as discount, 
  SUM(transactionUnitsSold) as units,
  COUNT(transactionId) as count
  FROM transaction 
  WHERE transactionType IN(1,4)
  AND transactionDate
  BETWEEN ? 
  AND ?
  AND companyId = ?"
  ,[DAY_START,DAY_END,COMPANY_ID] );

$totalExpenses    = floatval($expen->fields['total']);

$revenue  = $finalTotal - $totalExpenses;
$margen   = 100;

if($finalTotal > 0 && $totalExpenses > 0){
  $margen = ( $revenue / $finalTotal ) * 100;
  $margen = ($margen < 0) ? 0 : $margen;
}

$margen   = round($margen);

$soldF    = formatCurrentNumber($finalTotal);
$expF     = formatCurrentNumber($totalExpenses);
$revF     = formatCurrentNumber($revenue);
$cAveF    = formatCurrentNumber($customerAverage);

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
  <title>Widget <?=COMPANY_NAME?></title>
  <meta property="og:title" content="<?=COMPANY_NAME;?>" />
  <meta property="og:image" content="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
  <?php
  loadCDNFiles([],'css');
  ?>
</head>
<body class="bg-light lter">
<div class="col-xs-12 no-padder bg-light lter"> 

  <div class="col-xs-12 wrapper-md bg-info gradBgBlue" id="totalIncome">
    <div class="col-xs-3 no-padder">
      <a href="#" class="thumb text-center"> 
        <img src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg m-t-xs"> 
      </a>
    </div>
    <div class="col-xs-9 no-padder">
      <div class="text-u-c">
        Ingresos de hoy
      </div>
      <div class="h1 font-bold">
        <span class="text-muted"><?=CURRENCY?></span> <?=$soldF?>
      </div>
    </div>

    <div class="col-xs-12 b-t m-t"></div>

    <div class="col-xs-12 no-padder m-t">
      <div class="col-xs-4 no-padder text-center">
        <div class="text-u-c text-xs">Egresos</div>
        <div class="h3 font-bold"><?=$expF?></div>
      </div>

      <div class="col-xs-4 no-padder text-center">
        <div class="text-u-c text-xs">Margen</div>
        <div class="h3 font-bold"><?=$margen?>%</div>
      </div>

      <div class="col-xs-4 no-padder text-center">
        <div class="text-u-c text-xs">Ganancia bruta</div>
        <div class="h3 font-bold"><?=$revF?></div>
      </div>
    </div>
  </div>

  <div class="col-xs-12 wrapper-md bg-white text-center">

    <div class="col-xs-12 no-padder">
      <div class="text-u-c">
        Ticket Promedio
      </div>
      <div class="h1 font-bold">
        <span class="text-muted"><?=CURRENCY?></span> <?=$cAveF?>
      </div>
    </div>

    <div class="col-xs-12 b-t m-t"></div>

    <div class="col-xs-12 no-padder m-t">
      <div class="col-xs-4 no-padder">
        <div class="text-u-c text-xs">Clientes</div>
        <div class="h3 font-bold"><?=$contTotF;?></div>
      </div>

      <div class="col-xs-4 no-padder">
        <div class="text-u-c text-xs">Nuevos</div>
        <div class="h3 font-bold"><?=$contNewF;?></div>
      </div>

      <div class="col-xs-4 no-padder">
        <div class="text-u-c text-xs">Recurrentes</div>
        <div class="h3 font-bold"><?=$contOldF;?></div>
      </div>
    </div>
  </div>

  <a href="https://encom.app?utm_source=ENCOM_osWidget&utm_medium=ENCOM_footer_icon&
utm_campaign=<?=COMPANY_NAME?>" class="col-xs-12 m-t-lg m-b-lg text-center block">
    <div class="text-muted">Usamos</div>
    <img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
  </a>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>

<?php
loadCDNFiles([],'js');
?>

</body>
</html>
<?php
dai();
?>