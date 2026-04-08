<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));

$setting = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID],true);

if(!validity(COMPANY_ID)){
  include_once('/home/encom/public_html/panel/includes/404.inc.php');
  dai();
}

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
define('LANGUAGE', $setting['settingLanguage']);

loadLanguage(LANGUAGE);
date_default_timezone_set(TIMEZONE);

if(validateHttp('action') == 'scan'){
  $itemId = db_prepare( validateHttp('code') );

  $result = ncmExecute('SELECT * FROM item WHERE itemStatus > 0 AND companyId = ? AND (itemSKU = ? OR itemId = ?) LIMIT 1',[COMPANY_ID,$itemId,$itemId]);

  $out = false;
  if($result){
    $out = [
              "itemName"  => unXss($result["itemName"]),
              "itemPrice" => CURRENCY . ' ' . formatCurrentNumber( $result["itemPrice"] ),
              "itemSKU"   => unXss($result["itemSKU"])
            ];
  }

  header('Content-Type: application/json'); 
  dai(json_encode($out));

  dai();
}


$base_url  = ( isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on' ? 'https' : 'http' ) . '://' .  $_SERVER['HTTP_HOST'];
$url       = $base_url . $_SERVER["REQUEST_URI"];

$_modules = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Verificador de Precios</title>
  <meta property="og:title" content="<?=COMPANY_NAME;?>" />
  <meta property="og:image" content="/assets/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
  
  <?php
  loadCDNFiles([],'css');
  ?>
</head>
<body class="bg-light lter">
  <div class="col-xs-12 wrapper bg-light lter"> 
    
    <div class="col-sm-8 col-sm-offset-2 no-padder text-center r-24x clear all-shadows bodyBlock">
      
      

    </div>

    <div class="col-xs-12 text-center">
      <a href="/?utm_source=ENCOM_rewards_earned&amp;utm_medium=ENCOM_footer_icon&amp;
  utm_campaign=<?=COMPANY_NAME;?>" class="m-t-md block">
        <span class="text-muted">Usamos</span> <br>
        <img src="/images/incomeLogoLgGray.png" width="80">
      </a>
    </div>
    
  </div>

  <script id="codeTpl" type="text/html">
    <div class="col-xs-12 scannedItem">
      <div class="col-sm-2 text-right hidden">
        <img src="{{itemImg}}" width="80%" class="b r-24x hidden">
      </div>
      <div class="col-xs-12 text-center">
        <div class="h1 font-bold text-dark" style="font-size: 5em;">{{{itemPrice}}}</div>
        <div class="h1 font-bold m-t">{{itemName}}</div>
        <div class="h3 font-bold text-muted"><span class="material-icons m-r-sm">qr_code_scanner</span> {{itemSKU}}</div>
      </div>
    </div>
  </script>

  <script id="titleTpl" type="text/html">
    <div class="col-xs-12 wrapper-lg text-center title">
      <span class="material-icons md-68 m-b-sm">qr_code_scanner</span>
      <div class="h1 text-dark font-bold" style="font-size:3.5em;">Consulte el precio</div>
      <p>Escanee el código para ver el precio del producto</p>
    </div>
  </script>

  <script id="notFoundTpl" type="text/html">
    <div class="col-xs-12 wrapper-lg text-center title">
      <span class="material-icons text-warning md-68 m-b-sm">warning</span>
      <div class="h1 text-dark font-bold" style="font-size:3.5em;">No encontrado</div>
      <p>No pudimos encontrar el código ingresado, pruebe con otro código o solicite asistencia</p>
    </div>
  </script>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>
<?php
loadCDNFiles([
  '/assets/vendor/js/mustache-4.0.1.min.js'
],'js');
?>
<script>
  (function ($) {
      $.fn.codeScanner = function (options) {
          var settings = $.extend({}, $.fn.codeScanner.defaults, options);

          return this.each(function () {
              var pressed = false;
              var chars = [];
              var $input = $(this);

              $(window).off('keypress').on('keypress',function (e) {
                  var keycode = (e.which) ? e.which : e.keyCode;
                  if ((keycode >= 65 && keycode <= 90) ||
                      (keycode >= 97 && keycode <= 122) ||
                      (keycode >= 48 && keycode <= 57)
                  ) {
                      chars.push(String.fromCharCode(e.which));
                  }
                  // console.log(e.which + ":" + chars.join("|"));
                  if (pressed == false) {
                      setTimeout(function () {
                          if (chars.length >= settings.minEntryChars) {
                              var barcode = chars.join('');
                              settings.onScan($input, barcode);
                          }
                          chars = [];
                          pressed = false;
                      }, settings.maxEntryTime);
                  }
                  pressed = true;
              });

              $input.keypress(function (e) {
                  if (e.which === 13) {
                      e.preventDefault();
                  }
              });

              return $(this);
          });
      };

      $.fn.codeScanner.defaults = {
          minEntryChars: 8,
          maxEntryTime: 100,
          onScan: function ($element, barcode) {
              $element.val(barcode);
          }
      };
  })(jQuery);

  var setTitle = function(bodyBlockH){
    ncmHelpers.mustacheIt($('#titleTpl'),false,$('.bodyBlock'));
    ncmUI.verticalAlign( $('.title'), 0, bodyBlockH );
  }

  var setError = function(bodyBlockH){
    ncmHelpers.mustacheIt($('#notFoundTpl'),false,$('.bodyBlock'));
    ncmUI.verticalAlign( $('.title'), 0, bodyBlockH );
  }

  var setItem = function(data,bodyBlockH){
    ncmHelpers.mustacheIt($('#codeTpl'),data,$('.bodyBlock'));
    ncmUI.verticalAlign( $('.scannedItem'), false, bodyBlockH );
  }


  $(document).ready(function(){
    var wH = $(window).height();
    var bodyBlockH = (wH / 1.5);
    $('.bodyBlock').css({'height' : bodyBlockH + 'px'});

    ncmUI.verticalAlign( $('.bodyBlock'), bodyBlockH, (wH - 40) );
    setTitle(bodyBlockH);

    $('body').codeScanner({
        maxEntryTime: 1000, // milliseconds
        minEntryChars: 3,  // characters
        onScan: function ($element, code) {
          console.log(code);
          spinner('body', 'show');
          $.get('/priceChecker?s=<?=validateHttp('s')?>&action=scan&code=' + code,function(data){
            if(data){
              setItem(data, bodyBlockH);
            }else{
              setError(bodyBlockH);
            }

            clearTimeout(window.scanTO);        

            window.scanTO = setTimeout(function(){
              setTitle(bodyBlockH);
            },6000);

            spinner('body', 'hide');
          });
        }
    });

    $('#segmentsBtn').on('click',function(e){
      e.preventDefault();
      $('#segments').toggleClass('hidden');
    });
    
  });
</script>

</body>
</html>
<?php
dai();
?>