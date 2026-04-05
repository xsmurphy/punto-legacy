<?php
require_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define(COMPANY_ID, dec($data[1]));
define(GIFT_UID, $data[0]);

$setting = $db->Execute("SELECT * FROM setting WHERE companyId = ?",array(COMPANY_ID));

define('THOUSAND_SEPARATOR', $setting->fields['settingThousandSeparator']);
define('DECIMAL', $setting->fields['settingDecimal']);
define('CURRENCY', $setting->fields['settingCurrency']);
define('TIMEZONE', $setting->fields['settingTimeZone']);
define('COMPANY_NAME', $setting->fields['settingName']);

date_default_timezone_set(TIMEZONE);

$gift          = ncmExecute("SELECT * FROM giftCardSold WHERE timestamp = ? AND companyId = ? LIMIT 1",array(GIFT_UID,COMPANY_ID));

$customerData  = getCustomerData($gift['giftCardSoldBeneficiaryId'], 'uid');
$beneficiary   = getCustomerName($customerData,'first');

$bgClass = 'gradBgOrange';
$bgStyle = '';
if($gift['giftCardSoldColor']){
  $bgClass = '';
  $bgStyle = 'background:#'.$gift['giftCardSoldColor'];
}

$timestamp = ($gift['timestamp'])?$gift['timestamp']:'#############';

//VALUE
$value   = CURRENCY.formatCurrentNumber($gift['giftCardSoldValue']);
if($gift['giftCardSoldStatus'] < 1){
  $value = 'Inactivo';
}
if(strtotime($gift['giftCardSoldExpires']) < strtotime('now')){
  $value = 'Vencido';
}
if($gift['giftCardSoldValue'] < 0.001){
  $value = 0;
}
?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Gift Card</title>
  <meta property="og:title" content="<?=COMPANY_NAME;?>" />
  <meta property="og:image" content="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />

  <?php
  loadCDNFiles(array(),'css');
  ?>
</head>
<body class="bg-white col-xs-12 no-padder clear">

<div class="col-lg-4 col-md-3 col-sm-2 no-padder hidden-xs"></div>
<div class="col-lg-4 col-md-6 col-sm-8 wrapper m-t text-center">
  <div class="m-b-lg">
    <a href="#" class="thumb-md animated fadeInDown speed-3x"> 
      <img src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
    </a>
  </div>
  <div id="giftCard" class="col-xs-12 no-padder text-left all-shadows r-24x clear animated fadeInUp speed-3x text-white" style="display: none;">
    <div class="col-xs-12 <?=$bgClass;?> wrapper" style="<?=$bgStyle;?>">
      <div class="col-xs-12 h4 text-center text-center font-bold m-b">
        <?=COMPANY_NAME?>
      </div>
      <div class="col-sm-6 b-l b-3x b-white wrapper-sm m-b">
        <div class="text-sm">
          Beneficiario:
        </div>
          <div class="h3 font-bold">
           <?=$beneficiary?>
          </div>
          <div class="text-sm">
            #<?=($gift['giftCardSoldCode'])?$gift['giftCardSoldCode']:' ####'?>
          </div>
          <div class="">
              Vence el 
              <?php
              $expires = explode(' ',$gift['giftCardSoldExpires']);
              echo ($expires[0])?$expires[0]:'####-##-##';
              ?>
          </div>
        </div>
        <div class="col-sm-6 text-right no-padder">
          <?php
          if(!validity($gift['giftCardSoldNote'])){
          ?>
            Saldo:
            <?php
            $fSize      = '3';
            $valength   = strlen($value);
            if($valength < 4){
              $fSize = '5';
            }else if($valength < 5){
              $fSize = '4';
            }
            ?>
            <div class="font-bold" style="font-size:<?=$fSize?>em;">
              <?=$value?>
            </div>
          <?php
          }else{
            ?>
            <div class="text-md m-t wrapper-sm r-3x b b-white">
              <?=$gift['giftCardSoldNote'];?>
            </div>
            <?php
          } 
          ?>
        </div>
        
        <div class="text-center col-xs-12 m-t m-b">
          <div class="rounded bg-light lter font-bold h3 wrapper-xs m-l m-r"><?=chunk_split($timestamp, 4, ' ')?></div>
          Presente estos números en el momento de canje 
        </div>
      </div>
    </div>
    <div class="col-xs-12 wrapper text-muted text-center text-sm animated bounceInUp" id="policy" style="display:none;">
      Llegada su fecha de vencimiento o al quedar el saldo en 0 ya no podrá volver a utilizar esta Gift Card

      <a href="https://encom.app?utm_source=ENCOM_gift_card_redeem&utm_medium=ENCOM_footer_icon&
utm_campaign=<?=COMPANY_NAME?>" class="m-t-md block">
        <span class="text-muted">Usamos</span> <br>
        <img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
      </a>

    </div>
</div>
<div class="col-lg-4 col-md-3 col-sm-2 no-padder hidden-xs"></div>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>

<?php
loadCDNFiles(array(),'js');
?>

<script>
    $(document).ready(function(){
      
      setTimeout(function(){
        $('#giftCard').show();
      },300);

      setTimeout(function(){
        $('#policy').show();
      },500);
      
    });
  </script>

</body>
</html>
<?php
dai();
?>
