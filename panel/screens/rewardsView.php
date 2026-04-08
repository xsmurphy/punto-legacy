<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));
define('CUSTOMER_ID', dec($data[1]));

$setting = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID],true);

if(!validity(COMPANY_ID) || !validity(CUSTOMER_ID)){
  include_once('/home/encom/public_html/panel/includes/404.inc.php');
  dai();
}

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);

date_default_timezone_set(TIMEZONE);

$customer = getContactData(CUSTOMER_ID, 'uid');
$AMOUNT   = '0.00';
if($customer){
  $AMOUNT = ($customer['arr']['contactLoyaltyAmount'] > 0) ? formatCurrentNumber($customer['arr']['contactLoyaltyAmount']) : $AMOUNT;
  define('CUSTOMER_NAME', getCustomerName($customer,'first')); 
}


$_modules = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

if(!$_modules['loyalty']){
  include_once('/home/encom/public_html/panel/includes/404.inc.php');
  dai();
}

?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Acumulado</title>
  <meta property="og:title" content="<?=COMPANY_NAME;?>" />
  <meta property="og:image" content="/assets/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
  
  <?php
  loadCDNFiles([],'css');
  ?>
</head>
<body class="bg-light lter">
  <div class="col-xs-12 wrapper bg-light lter"> 
    
    <div class="col-md-4 col-md-offset-4 col-sm-6 col-sm-offset-3 no-padder text-center r-3x clear all-shadows animated zoomIn speed-3x">
      
      <div class="col-xs-12 no-padder bg-primary gradBgPurple animateBg">

        <div id="totalBlock" class="col-xs-12">
          <a href="#" class="thumb"> 
            <img src="/assets/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
          </a>
          <div class="col-xs-12 m-b-n m-t font-bold">
            <?=CUSTOMER_NAME?>, tiene <?=CURRENCY?>
          </div>

          <div class="col-xs-12 font-bold text-warning bounceIn animated delay-3ms">
            <span class="font-bold" style="font-size: 4.5em;"><?=$AMOUNT?></span>
          </div>

          <div class="col-xs-12 font-bold text-u-c">
            - Acumulados -
          </div>

        </div>

      </div>

      <div class="col-xs-12 wrapper bg-white text-center" id="titleBlock">
        
        <p>Por cada <?=CURRENCY . formatCurrentNumber($_modules['loyaltyMin'])?>, acumulas <?=CURRENCY . formatCurrentNumber($_modules['loyaltyValue'])?> que luego podrá utilizar como método de pago.</p>
        
        <!--<a href="<?=$bases?>"> <span class="text-info">Ver Bases y Condiciones</span></a>-->
        
        
      </div>

    </div>

    <div class="col-xs-12 text-center">
      <a href="/?utm_source=saas_rewards_earned&amp;utm_medium=saas_footer_icon&amp;
  utm_campaign=<?=COMPANY_NAME;?>" class="m-t-md block">
        <span class="text-muted">Usamos</span> <br>
        <img src="/images/incomeLogoLgGray.png" width="80">
      </a>
    </div>
    
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
loadCDNFiles([
  '/assets/vendor/js/mustache-4.0.1.min.js',
  '/scripts/confettiKit.min.js'
],'js');
?>
<script>
  $(document).ready(function(){

    var wH          = $(window).height();
    var amountBlk   = $('#totalBlock').height();
    var titleBlk    = $('#titleBlock').height() + 60 + 65;//60 son los margenes top y bottom 39 logo encom
    var blockH      = ( ( (wH - titleBlk) - amountBlk ) / 2 );

    $('#totalBlock').css('margin-top',blockH + 'px').css('margin-bottom',blockH + 'px');

    $('#segmentsBtn').on('click',function(e){
      e.preventDefault();
      $('#segments').toggleClass('hidden');
    });

    setTimeout(function(){
      new confettiKit({
          colors:['#febf00','#fed800','#feb600'],
          confettiCount: 20,
          angle: 90,
          startVelocity: 50,
          elements: {
              'confetti': {
                  direction: 'down',
                  rotation: true,
              },
              'star': {
                  count: 10,
                  direction: 'down',
                  rotation: true,
              },
              'ribbon': {
                  count: 5,
                  direction: 'down',
                  rotation: true,
              },
          },
          position: 'topLeftRight',
      });
    },300);

    
  });
</script>

</body>
</html>
<?php
dai();
?>