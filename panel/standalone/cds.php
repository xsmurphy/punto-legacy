<?php
//echo number_format(memory_get_usage() / 1048576, 2);
//sleep(5);
//die('En mantenimiento');

include_once('sa_head.php');

$data         = explodes(',', base64_decode($_GET['s']));
$ECOMPANY_ID  = $data[0];
$EOUTLET_ID   = $data[1];

define('COMPANY_ID', dec($ECOMPANY_ID) );
define('OUTLET_ID', dec($EOUTLET_ID) );

$setting      = ncmExecute("SELECT settingThousandSeparator,settingDecimal,settingCurrency,settingTimeZone,settingName FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);

date_default_timezone_set(TIMEZONE);
define('TODAY', date('Y-m-d H:i:s'));

$serverDate = date('Y-m-d H');

$API_KEY    = getAPICreds(COMPANY_ID);

$data       =   [
                  'api_key'       => $API_KEY,
                  'company_id'    => $ECOMPANY_ID,
                  'order'         => 'lastUpdated',
                  'children'      => 'all',
                  'customerdata'  => 1
                ];

if(validateHttp('action') == 'time'){
  jsonDieResult(['date'=>$serverDate]);
}

if(validateHttp('action') == 'manifest'){
  ?>
  {
    "name": "ENCOM CDS",
    "lang": "es",
    "dir": "ltr",
    "short_name": "ENCOM CDS",
    "theme_color": "#405161",
    "background_color": "#405161",
    "start_url": "https://public.encom.app/cds?s=<?=validateHttp('s')?>",
    "scope": "https://public.encom.app/cds?s=<?=validateHttp('s')?>",
    "display": "fullscreen",
    "orientation": "natural",
    "categories": ["restaurant", "proyects"],
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

if(validateHttp('action') == 'list'){

  $array            = [];
  $data['type']     = 12;
  $data['limit']    = 100;
  $data['order']    = 'DESC';
  $data['outlet']   = $EOUTLET_ID;
  $data['status']   = '3,5';

  if(validateHttp('reverse')){
    $data['reverse']= 'true';
  }

  $data['from']     = date('Y-m-d H:i:s',strtotime('-1 day'));
  $data['to']       = date('Y-m-d 23:i:s');

  $result           = json_decode(curlContents('https://api.encom.app/get_orders.php','POST',$data),true);
  $array['orders']  = $result;

  jsonDieResult($array);

}

?>

<!DOCTYPE html>
<html class="noscroll bg-dark dker">
<head>  
  <meta charset="utf-8" /> 
  <title>CDS ENCOM</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />

  <?php
  loadCDNFiles([
    'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.1/css/select2.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-theme/0.1.0-beta.10/select2-bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/themes/offline-language-spanish.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/themes/offline-theme-dark.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css'
  ],'css','?s=' . validateHttp('s') . '&action=manifest');
  ?>

  <style type="text/css">
    .select2-selection__choice{
      border-radius   : 20px!important;
    }

    .select2-selection__choice__display{
      font-weight     : bold;
      text-transform  : uppercase;
    }

    .bg-logo{
      background-image: url(https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/panel/images/logotextgray.png);
      background-repeat: no-repeat; 
      background-position: 50%, 50%;
      max-height: 30%vh;
    }
  </style>

</head>

<body class="noscroll bg-dark dker">

  <section class="col-xs-12 no-padder">

    <section class="bodyBlock">

      <div class="col-xs-12 no-padder text-center m-t">
        <div class="col-xs-2"></div>

        <div class="col-xs-4 h2 font-bold text-u-c">
          En proceso
        </div>

        <div class="col-xs-4 h2 font-bold text-u-c text-success">
          Retirar
        </div>

        <div class="col-xs-2"></div>
      </div>

      <div class="col-xs-12 no-padder">
        <div class="col-xs-2"></div>

        <div class="col-xs-4 b-r fullHeight" id="process">
          
        </div>

        <div class="col-xs-4 fullHeight" id="finished">
          
        </div>

        <div class="col-xs-2"></div>

      </div>

      <div class="col-xs-12 wrapper-sm bg-dark" style="position: fixed; z-index:100; bottom:0;">
        <div class="col-xs-6 no-padder">
          <img src="https://app.encom.app/images/incomeLogoLgGray.png" height="30">
        </div>

        <div class="col-xs-6 no-padder text-right m-t-xs">
          <a href="#" id="fullScreenBtn" class="m-r"><i class="material-icons">fullscreen</i></a>

          <a href="#" id="darkModeBtn" class="m-r hidden"><i class="material-icons">brightness_medium</i></a>   
        </div>
        
      </div>

    </section>
    
  </section>
  <script type="text/javascript">
    var noSessionCheck  = true;
    window.standAlone   = true;
  </script>
  <?php
  include_once("/home/encom/public_html/panel/includes/analyticstracking.php");
  ?>

  <script type="text/html" id="listTpl">
    {{#data}}
    <div class="col-xs-12 wrapper b-b b-dark">
      <div class="font-bold h2 text-white">{{no}}</div>
      <div class="">{{name}}</div>
    </div>
    {{/data}}
  </script>

  <script type="text/javascript" src="https://js.pusher.com/7.2/pusher.min.js"></script> 
  <?php
  loadCDNFiles([
                  'https://cdn.jsdelivr.net/npm/simplestorage.js@0.2.1/simpleStorage.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/mousetrap/1.6.3/mousetrap.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/mustache.js/4.0.1/mustache.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.27.0/locale/es.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.27.0/moment.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.full.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/i18n/es.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/jQuery.print/1.5.1/jQuery.print.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/offline.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js',
                  'https://cdnjs.cloudflare.com/ajax/libs/push.js/1.0.8/push.min.js'
                ],'js');
  ?>

<script type="text/javascript">
  window.ese    = '<?=validateHttp('s')?>';
  var baseUrl   = '<?=$baseUrl?>';
  var outletID  = '<?=$EOUTLET_ID?>';

  <?php
  //if($_GET['update']){
  //  ob_start();
  ?>

  var ncmCDS = {

    init    : () => {
      moment.locale('es');

      var h = $(window).height();
      $('.fullHeight').css({'height':h + 'px'});

      ncmCDS.pusher = new Pusher('24c4d438c59b81f27107', {
        cluster: 'sa1'
      });

      var channel = ncmCDS.pusher.subscribe(outletID + '-KDS');
      channel.unbind('order').bind('order', (result) => {
          ncmCDS.load();
      });

      ncmCDS.load();
      ncmCDS.setUI();
    },

    events : () => {
      ncmHelpers.onClickWrap('#fullScreenBtn',function(event,tis){
        $(document).toggleFullScreen();
      });

      ncmHelpers.onClickWrap('#darkModeBtn',function(event,tis){
        ncmUI.setDarkMode.autoSelected();
      });
    },

    process : (orders) => {

      var inProcess = [];
      var finished  = [];
      var obj       = {};

      if(orders){

        $.each(orders, (i, order) => {

          obj = {no : '#' + order.number_id, name : order.customer_name};

          if(order.order_status == '3'){//si esta en proceso (verificar numero)
            inProcess.push(obj);
          }else if(order.order_status == '5'){ //finalizado
            finished.push(obj);
          }

        });

        ncmCDS.render($('#listTpl'), {data : inProcess}, $('#process'));
        ncmCDS.render($('#listTpl'), {data : finished}, $('#finished'));

        ncmCDS.events();

      }

    },

    load    : (callback) => {
      $.get('/cds.php?s=' + window.ese + '&action=list', (result) => {
        ncmCDS.process(result.orders);
      });
    },

    render  : ($template, data, $wrap, returns) => {
      var template    = $template.html();
      var mustached   = Mustache.render(template, data);

      if(returns){
        return mustached;
      }else{
        $wrap.html(mustached); 
      }

    },

    setUI : () => {
      //CALCULO TAMANO DE LA PANTALLA
      var wH          = $(window).height() - 200;
      var wW          = $(window).width();
      var headerH     = $('.headers').height();
      var viewMode    = 'landscape';
      var bodyBlockH  = wH;

      if(wH > wW){
        viewMode    = 'portrait';
      }
      //

      //APLICO PRIMERA RENDERIZACION
      $('body, html').css({'height' : bodyBlockH + 'px'});

      if(viewMode == 'portrait'){

      }

      $('.logo').css('top', (wH - $('.logo').height()) );

      if(viewMode == 'landscape'){
        ncmUI.verticalAlign( $('iframe'), 0, wH );
      }
      //
    }

  };

  $(document).ready(function(){
    ncmCDS.init();
  });

  <?php
    //$script = ob_gets_contents();
    //echo $script;
    //minifyJS([$script => 'scripts' . $baseUrl . '.js']);
  //}
?>
</script>
<!--<script src="scripts<?=$baseUrl?>.js?<?=date('d.i')?>"></script>-->

</body>

</html>

<?php
include_once('/home/encom/public_html/panel/includes/freememory.php');
include_once('/home/encom/public_html/panel/includes/compression_end.php');
?>