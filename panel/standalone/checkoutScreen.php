<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));
define('REGISTER_ID', dec($data[1]));

$setting  = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID],true);
$register = ncmExecute("SELECT * FROM register WHERE registerId = ? AND companyId = ? LIMIT 1",[REGISTER_ID, COMPANY_ID],true);
$outlet   = ncmExecute("SELECT * FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1",[$register['outletId'], COMPANY_ID],true);

if($setting['companyId'] != COMPANY_ID){
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

$_modules = ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

if(validateHttp('action') == 'manifest'){
  ?>
  {
    "name": "ENCOM Display",
    "lang": "es",
    "dir": "ltr",
    "short_name": "ENCOM Display",
    "theme_color": "#62bcce",
    "background_color": "#62bcce",
    "start_url": "https://public.encom.app/checkoutScreen?s=<?=validateHttp('s')?>",
    "scope": "https://public.encom.app/checkoutScreen?s=<?=validateHttp('s')?>",
    "display": "fullscreen",
    "orientation": "landscape",
    "categories": ["retail", "stores"],
    "icons": [
      {
       "src": "https://panel.encom.app/android-icon-36x36.png",
       "sizes": "36x36",
       "type": "image\/png",
       "density": "0.75"
      },
      {
       "src": "https://panel.encom.app/android-icon-48x48.png",
       "sizes": "48x48",
       "type": "image\/png",
       "density": "1.0"
      },
      {
       "src": "https://panel.encom.app/android-icon-72x72.png",
       "sizes": "72x72",
       "type": "image\/png",
       "density": "1.5"
      },
      {
       "src": "https://panel.encom.app/android-icon-96x96.png",
       "sizes": "96x96",
       "type": "image\/png",
       "density": "2.0"
      },
      {
       "src": "https://panel.encom.app/android-icon-144x144.png",
       "sizes": "144x144",
       "type": "image\/png",
       "density": "3.0"
      },
      {
       "src": "https://panel.encom.app/android-icon-192x192.png",
       "sizes": "192x192",
       "type": "image\/png",
       "density": "4.0"
      }
    ]
  }
  <?php
  jsonDieResult(false);
  dai();
}

$companyLogo = companyLogo(150);

?>
<!DOCTYPE html>
<html class="no-js bg-info lt">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
  <title>ENCOM</title>
  
  <?php
  loadCDNFiles([],'css','?s=' . validateHttp('s') . '&action=manifest');
  ?>
</head>
<body class="bg-info lt">
  <div class="col-xs-12 wrapper bg-info lt"> 
    <div class="col-sm-12 col-xs-12 no-padder text-center scrolleable bodyBlock"> </div>
  </div>

  <script id="titleTpl" type="text/html">
    
    <div class="col-xs-12 text-left no-padder hidden-xs">
      <a href="#" id="fullScreenBtn" class="m-r"><i class="material-icons">fullscreen</i></a>
    </div>
    
    {{#processed}}

    <div class="col-xs-12 text-white wrapper-lg text-center title" id="cosConfirmChk">
      <i class="material-icons confirmIcon animated bounceIn" style="font-size: 15em !important; line-height: 1 !important;">check</i>
    </div>
    <div class="col-xs-12 no-padder m-t-md text-center">
        <span class="text-muted">Usamos</span> <br>
        <img src="https://panel.encom.app/images/logotextwhite.png" height="60"> <br>
        <span class="text-muted font-bold">www.encom.app</span> 
    </div>

    {{/processed}}
    {{^processed}}

    <div class="col-xs-12 col-sm-7 col-md-7 col-lg-8 text-white wrapper-lg text-left title noPortrait">

      <div class="col-xs-12 text-center visible-xs">
        <img src="https://assets.encom.app<?=$companyLogo;?>" width="50" height="50" class="m-b rounded">
      </div>
      
      {{#comission}} <span class="text-xs font-bold">Servicio de cobro digital: +{{comission}}</span> {{/comission}}
      <div class="h1 font-bold hidden-xs" style="font-size:5.5em;">{{amount}}</div>
      <div class="h1 font-bold visible-xs" style="font-size:4.7em;">{{amount}}</div>

      <p class="h1 text-thin hidden-xs">Total a pagar en {{currency}}</p>
      <p class="h3 text-thin visible-xs">Total a pagar en {{currency}}</p>

      <p class="qr-text m-t-lg h2">
        <em>{{customer}}</em>
        <br>
        <em>{{TIN}}</em>
      </p>
      <p class="">
        {{registerName}} - {{outletName}}
      </p>

    </div>    

    {{#if qrImg}}

    <div class="col-xs-12 col-sm-5 col-md-5 col-lg-4 no-padder text-right qr noPortrait" >
      <div class="col-xs-12 no-padder text-center qrWrapper">
        <img src="{{qrImg}}" class="m-b m-t r-24x" id="qrImg" height="{{qrHi}}px">
      </div>
    </div>

    {{else}}

    <div class="col-xs-12 col-sm-5 col-md-5 col-lg-4 no-padder text-right qr noPortrait" >
      {{#hasItems}}
        <div class="col-xs-12 h3 font-bold text-center m-b text-u-c">Artículos</div>

        {{#items}}
        <div class="col-xs-12 no-padder font-bold text-lg">
          <span class="col-xs-1 text-right b-b wrapper">{{count}}</span>
          <span class="col-xs-8 text-left b-b wrapper">{{name}}</span>
          <span class="col-xs-3 text-right b-b wrapper">{{total}}</span>
        </div>
        {{/items}}
      
      {{/hasItems}}
      {{^hasItems}}
        <div class="col-xs-12 no-padder m-t text-center">
          <img src="https://assets.encom.app<?=$companyLogo;?>" width="100" height="100" class="m-b rounded hidden-xs">
          <div class="text-white text-xs">Usamos</div>
          <img src="https://panel.encom.app/images/logotextwhite.png" height="20" width="80">
        </div>
      {{/hasItems}}
    </div>
    
    {{/if}}
    

    {{/processed}}

  </script>

<script type="text/javascript">
  var noSessionCheck  = true;
  var companyId       = '<?=enc(COMPANY_ID)?>';
  var registerId      = '<?=enc(REGISTER_ID)?>';
  var registerName    = '<?=$register['registerName']?>';
  var outletName      = '<?=$outlet['outletName']?>';
  var currency        = '<?=CURRENCY?>';
  var decimal         = '<?=DECIMAL?>';
  var tseparator      = '<?=THOUSAND_SEPARATOR?>';
  window.standAlone   = true;
</script>

<?php
loadCDNFiles([
  'https://cdnjs.cloudflare.com/ajax/libs/handlebars.js/4.7.7/handlebars.min.js'
],'js');
?>
<script type="text/javascript" src="/standalone/scripts/ncm-ws.js"></script>
<script>var WS_URL = '<?= WS_URL ?>';</script>

<script>
  $(document).ready(() => {

    var ncmPusher = new NcmWS(WS_URL);
    //

    ncmHelpers.mustacheIt = ($template, data, $container) => {
      var source    = $template.html();
      var template  = Handlebars.compile(source);
      var html      = template(data);
      $container.html(html);
    };

    var setUI = () => {
      //CALCULO TAMANO DE LA PANTALLA
      var wH          = $(window).innerHeight();
      var wW          = $(window).width();
      var footerH     = $('.footer').height();
      var viewMode    = 'landscape';
      var bodyBlockH  = wH - 40;

      if(wH > wW){
        viewMode    = 'portrait';
      }
      //

      //APLICO PRIMERA RENDERIZACION
      $('.bodyBlock').css({'height' : bodyBlockH + 'px'});

      if(viewMode == 'portrait'){
        $('.noPortrait').removeClass('col-xs-6').addClass('col-xs-12');
        $('.qrWrapper').removeClass('pull-right');
      }

      if(viewMode == 'landscape'){
        ncmUI.verticalAlign( $('.title'), 0, bodyBlockH );
        ncmUI.verticalAlign( $('.qr'), 0, bodyBlockH );  
      }
      //
    };

    ncmHelpers.mustacheIt( $('#titleTpl'), {'amount' : '0.00', 'currency' : currency, 'isQR' : false, 'customer' : 'SIN CLIENTE', 'TIN' : '', 'registerName' : registerName, 'outletName' : outletName}, $('.bodyBlock') );

    setUI();

    var channel = ncmPusher.subscribe(companyId + '-' + registerId + '-register');

    channel.unbind('checkoutScreen').bind('checkoutScreen', function(data) {

      data              = JSON.parse(data.message);

      if(data.processed && $('#cosConfirmChk').is(':visible')){
        return false;
      }

      data.currency     = currency;
      data.qrHi         = $('.qr').width();
      data.qrH          = data.qrHi - 50; //wH / 2;
      data.registerName = registerName;
      data.outletName   = outletName;
      
      if(!data.customer){
        data.customer = 'SIN CLIENTE';
      }

      if(parseFloat(data.amount) == 0){
        data.amount = '0.00';
      }
      //

      if(data.processed && $('#cosConfirmChk').is(':visible')){
        return false;
      }

      if(data.items){
        data.hasItems = true;
        $.each(data.items,function(i,val){
          val.total = formatNumber(val.total, '', decimal, tseparator);
        });
      }

      if(isMobile.phone){
        data.hasItems = false;
      }

      //RENDERIZO RESULTADOS
      ncmHelpers.mustacheIt( $('#titleTpl'), data, $('.bodyBlock') );

      setUI();
      
      //

    });
    //

    ncmHelpers.onClickWrap('#fullScreenBtn',function(event,tis){
      $(document).toggleFullScreen();
    });

    $(window).on('resize',setUI);
    
  });
</script>

</body>
</html>
<?php
dai();
?>