<?php
//echo number_format(memory_get_usage() / 1048576, 2);
//sleep(5);
//die('En mantenimiento');

include_once('sa_head.php');

$data         = explodes(',', base64_decode($_GET['s']));
$ECOMPANY_ID  = $data[0];
$EOUTLET_ID   = $data[1];

define('COMPANY_ID', dec($ECOMPANY_ID));
define('OUTLET_ID', dec($EOUTLET_ID));

$setting = ncmExecute("SELECT settingThousandSeparator,settingDecimal,settingCurrency,settingTimeZone,settingName FROM setting WHERE companyId = ? LIMIT 1", [COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);

date_default_timezone_set(TIMEZONE);
define('TODAY', date('Y-m-d H:i:s'));

$serverDate = date('Y-m-d H');

$API_KEY = getAPICreds(COMPANY_ID);

$data =   [
  'api_key'       => $API_KEY,
  'company_id'    => $ECOMPANY_ID,
  'order'         => 'lastUpdated',
  'children'      => 'all',
  'customerdata'  => 1
];

if (validateHttp('action') == 'time') {
  jsonDieResult(['date' => $serverDate]);
}

if (validateHttp('action') == 'manifest') {
?>
  {
  "name": "ENCOM KDS",
  "lang": "es",
  "dir": "ltr",
  "short_name": "ENCOM KDS",
  "theme_color": "#405161",
  "background_color": "#405161",
  "start_url": "https://public.encom.app/kds?s=<?= validateHttp('s') ?>",
  "scope": "https://public.encom.app/kds?s=<?= validateHttp('s') ?>",
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

if (validateHttp('action') == 'lists') {

  if (validateHttp('compTime') != $serverDate) {
    jsonDieResult(['error' => 'time', 'servers' => $serverDate, 'sent' => validateHttp('compTime')]);
  }

  $timestamp        = validateHttp('time');
  $array            = [];
  $getList          = true; //true porque cuando no hay timestamp procesa

  if ($timestamp) {
    //consulto las updated order
    $updated = json_decode(curlContents(API_URL . '/get_last_update.php', 'POST', $data), true);
    if (strtotime($updated['orders']) < strtotime($timestamp)) {
      $getList          = false;
    }
  }

  if ($getList) {
    $array            = [];
    $data['type']     = 12;
    $data['limit']    = 100;
    $data['order']    = 'DESC';
    $data['outlet']   = $EOUTLET_ID;
    $data['status']   = '0,1,2,3'; //'0,1,2,3,4,5';

    if (validateHttp('reverse')) {
      $data['reverse']    = 'true';
    }

    $data['from']     = date('Y-m-d H:i:s', strtotime('-1 day'));
    $data['to']       = date('Y-m-d H:i:s');
    //$data['test']     = 1;

    $result           = json_decode(curlContents(API_URL . '/get_orders.php', 'POST', $data), true);
    $array['orders']  = $result;
  }

  jsonDieResult($array);
}

if (validateHttp('action') == 'items') {

  //obtengo el listado de items al cargar y guardo en local storage
  $data['nolimit'] = true;
  $items      = json_decode(curlContents(API_URL . '/get_items', 'POST', $data), true);

  jsonDieResult($items);
}

if (validateHttp('action') == 'tags') {
  //obtengo el listado de items al cargar y guardo en local storage
  $tags           = json_decode(curlContents(API_URL . '/get_tags.php', 'POST', $data), true);

  jsonDieResult($tags);
}

if (validateHttp('action') == 'categories') {

  //obtengo el listado de items al cargar y guardo en local storage
  $cats           = json_decode(curlContents(API_URL . '/get_categories.php', 'POST', $data), true);

  jsonDieResult($cats);
}

if (validateHttp('action') == 'update') {
  $id               = validateHttp('i');
  $type             = validateHttp('t');
  $date             = validateHttp('d');

  if ($type == 'start') {
    $status = 3;
  } else {
    $status = 5;
  }

  $data['id']       = $id;
  $data['status']   = $status;
  $data['date']     = $date;
  //hago update del stado de la orden
  $update           = json_decode(curlContents(API_URL . '/edit_order_status.php', 'POST', $data), true);

  if ($update['success']) {

    $data['channel']  = enc(OUTLET_ID) . '-register';
    $data['event']    = 'order';
    $data['message']  = json_encode(['ID' => $id, 'registerID' => false]);
    curlContents(API_URL . '/send_webSocket.php', 'POST', $data);


    $data['channel']  = enc(OUTLET_ID) . '-KDS';
    $data['event']    = 'order';
    $data['message']  = $id;
    curlContents(API_URL . '/send_webSocket.php', 'POST', $data);
  }

  jsonDieResult($update);
}
?>

<!DOCTYPE html>
<html class="noscroll bg-dark dker">

<head>
  <meta charset="utf-8" />
  <title>KDS ENCOM</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />

  <?php
  loadCDNFiles([
    'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.1/css/select2.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-theme/0.1.0-beta.10/select2-bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/themes/offline-language-spanish.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/themes/offline-theme-dark.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css'
  ], 'css', '?s=' . validateHttp('s') . '&action=manifest');
  ?>

  <style type="text/css">
    body,
    html {
      height: 100%;
    }

    .middle-section {
      display: flex;
      flex-direction: column;
      height: 100%;
      width: 100%;
      padding: 0 10px;
    }

    .section-container {
      background-color: #fff;
      margin-bottom: 50px !important;
      flex-grow: 1;
    }

    .select2-selection__choice {
      border-radius: 20px !important;
    }

    .select2-selection__choice__display {
      font-weight: bold;
      text-transform: uppercase;
    }

    body {
      background-image: url(https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/panel/images/logotextgray.png);
      background-repeat: no-repeat;
      background-position: 50%, 50%;
      min-height: 90%vh;
    }

    .carousel-item {
      overflow-y: auto;
      min-height: 50vh;
      margin-bottom: 0px;
    }

    .card {
      display: flex;
      flex-direction: column;
    }

    .middle-section {
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .section-container {
      background-color: #fff;
      margin-bottom: 0px !important;
      /* overflow-y: auto; Remove the overflow property */
    }

    .col-xs-12.text-md {
      position: relative;
    }

    .bottom-container {
      flex: 1;
      height: 100%;
      flex-grow: 1;
    }

    .paddingCols {
      padding-bottom: 15px !important;
      padding-top: 15px !important;
      padding-left: 8px !important;
      padding-right: 8px !important;
    }

    /* ESTILOS DE COLUMNAS*/
    /* XXL */
    @media (min-width: 1400px) {
      .col-16 {
        flex: 0 0 auto;
        width: 11.11111111%;
      }
    }

    /* XL */
    @media (min-width: 1200px) {
      .col-xl-12 {
        flex: 0 0 auto;
        width: 11.11111111%;
      }
    }
  </style>

<style id="dynamic-style" type="text/css"></style>

</head>

<body class="noscroll">

  <section class="col-xs-12 no-padder bg-dark lt fullHeight hidden" id="lockScreen">
    <div class="wrapper text-center" style="position:absolute;top:0;left:0;bottom:0;right:0;margin:auto;width:100%;max-width:330px;height:130px;">
      <img src="https://app.encom.app/images/iconincomesmwhite.png" alt="Logo" width="60">
      <div>
        <input type="password" name="lockpass" id="lockpass" class="form-control input-lg no-bg no-border b-b text-center font-bold text-white m-t" style="font-size:5em; letter-spacing:10px; height:50px;" maxlength="4">
        <div class="text-xs col-xs-12 m-t" id="belowLockPadScreen">
          <i class="material-icons md-18">fingerprint</i><br>
          Ingrese su código de usuario
        </div>
      </div>
    </div>
  </section>

  <section class="col-xs-12 no-padder">

    <div style="padding-bottom: 100px" id="ordersList" class="carousel slide " data-ride="carousel" data-interval="false">
      <!-- Indicators -->
      <ol class="carousel-indicators"> </ol>

      <!-- Wrapper for slides -->
      <div class="carousel-inner">
        <div class="text-center">
          <img src="https://panel.encom.app/images/bg_kds.png" class="hidden" width="100%" id="logoSaleList">
        </div>

      </div>
      <div class="visible-xs wrapper-lg col-xs-12"></div>

      <!-- Left and right controls -->
      <a class="left carousel-control" href="#ordersList" data-slide="prev">
        <span class="glyphicon glyphicon-chevron-left"></span>
        <span class="sr-only">Previous</span>
      </a>
      <a class="right carousel-control" href="#ordersList" data-slide="next">
        <span class="glyphicon glyphicon-chevron-right"></span>
        <span class="sr-only">Next</span>
      </a>

    </div>

    <div class="col-xs-12 wrapper bg" style="position: fixed; z-index:100; bottom:0;">
      <div class="col-xs-4 no-padder hidden-xs pointer" id="backToFirst">
        <img src="https://app.encom.app/images/incomeLogoLgGray.png" height="20">
      </div>
      <div class="col-sm-4 col-xs-8 no-padder text-center h4 text-white font-bold">
        <span id="kdsNamePlc">KDS</span>
        <span id="waitingOrders" class="text-warning"></span>

      </div>
      <div class="col-sm-4 col-xs-4 no-padder">

        <a href="#" id="settingsBtn" class="pull-right m-r"><i class="material-icons">settings</i></a>
        <a href="#" id="fullScreenBtn" class="pull-right m-r"><i class="material-icons">fullscreen</i></a>

      </div>
    </div>
    <div>

      <div class="">
        <div class="modal fade" tabindex="-1" id="modalSmall" role="dialog">
          <div class="modal-dialog">
            <div class="modal-content r-24x clear no-bg no-border all-shadows">

            </div>
          </div>
        </div>
      </div>

  </section>
  <script type="text/javascript">
    var noSessionCheck = true;
    window.standAlone = true;
  </script>
  <?php
  include_once("/home/encom/public_html/panel/includes/analyticstracking.php");
  ?>
  <script type="text/html" id="settingsTpl">
    <div class="col-xs-12 wrapper-lg bg-white">
      <div class="h1 text-dark m-b-lg font-bold">Configuración</div>

      <div class="row">
        <div class="col-sm-6">
          <div class="font-bold m-t-lg text-u-c text-sm">Nombre del KDS</div>
          <input type="text" name="" class="form-control no-border no-bg b-b m-b" id="kdsName">
        </div>
        <div class="col-sm-6">
          <div class="font-bold m-t-lg text-u-c text-sm">Órdenes por pantalla</div>
          <select class="form-control no-border no-bg b-b" id="cardsPerScreen">
            <option value="4">4 (cuatro)</option>
            <option value="6">6 (seis)</option>
            <option value="8">8 (ocho)</option>
            <option value="12">12 (doce)</option>
            <option value="18">18 (dieciocho)</option>
          </select>
          <!--<input type="text" name="" class="form-control no-border no-bg b-b m-b" id="cardsPerScreen" disabled>-->
        </div>
      </div>


      <div class="font-bold text-u-c text-sm">Categorías permitidas</div>
      <select class="form-control no-border no-bg b-b" id="allowedCategories" multiple>
        <option></option>
      </select>



      <div class="col-xs-12 no-padder">

        <div class="col-sm-6 col-xs-12">
          <div class="font-bold m-t-lg text-u-c text-sm">Imprimir al iniciar</div>
          <?= switchIn('print') ?>
        </div>

        <div class="col-sm-6 col-xs-12">
          <div class="font-bold m-t-lg text-u-c text-sm">Sonidos</div>
          <?= switchIn('soundOn') ?>
        </div>

        <div class="col-sm-6 col-xs-12 offset-sm-6">
          <div class="font-bold m-t-lg text-u-c text-sm">Invertir orden</div>
          <?= switchIn('orderOrder') ?>
        </div>

        <div class="col-sm-6 col-xs-12 offset-sm-6">
          <div class="font-bold m-t-lg text-u-c text-sm">Dividir Pantalla</div>
          <?= switchIn('splitScreen') ?>
        </div>

        <div class="col-sm-6 col-xs-12 offset-sm-6">
          <div class="font-bold m-t-lg text-u-c text-sm">Ordenar por fecha</div>
          <?= switchIn('orderByDate') ?>
        </div>

        <div class="col-sm-6 col-xs-12"></div>

      </div>

      <div class="col-xs-12 text-center m-t-lg">
        <a href="#" class="btn btn-default btn-rounded text-u-c font-bold text-danger" id="resetConfig">Restaurar</a>
      </div>

    </div>
  </script>
  <script type="text/html" id="blockTpl">
    <div class=" carousel-item {{cols_combo}} col-xs-12 paddingCols animated speed-4x {{animation}} m-b-lg card" id="{{transaction_id}}" data-index="{{index}}">
      <div class="col-xs-12 no-padder r-3x clear">
        <div class="col-xs-12 {{background}} no-padder text-md">
          <div class="{{barColor}} rounded" style="height: 4px; width:{{barWidth}}%;"></div>

          <div class="col-xs-12 wrapper">
            <div class="col-xs-4 col-sm-4 col-md-4 col-lg-4 col-xl-4 no-padder m-t-n-xs">
              <div>{{source}}</div>
              <div class="font-bold h2 m-t-n-xs">#{{number_id}}</div>
            </div>

            <div class="col-xs-8 no-padder text-right">
              <div class="font-bold"><span class="font-normal">{{user_name}}</span> {{time_at}}</div>
              <div>{{customer_name}}</div>
            </div>
          </div>
        </div>

        <div class="col-xs-12 panel text-black m-n no-padder section-container">
          {{#order_note}}
            <div class="col-xs-12 wrapper-sm bg-light text-dark text-u-c">{{{order_fnote}}}</div>
          {{/order_note}}
          <div class="col-xs-12 {{tagsClass}} no-bg">
            {{#order_tags_name}}
              <span class="label bg-light text-xs text-dark rounded">{{.}}</span>
            {{/order_tags_name}}
          </div>
          <table class="table">
            <tbody>
              {{#order_details}}
                {{#name}}
                  <tr class="{{#status}}font-bold{{/status}}{{^status}}text-l-t text-danger{{/status}}">
                    <td style="{{styleCount}}">{{count}}</td>
                    <td style="{{style}}">
                      <span class="text-u-c">{{name}}</span>
                      {{#note}}
                        <em class="block font-normal">{{{fnote}}}</em>
                      {{/note}}
                      <div>
                        {{#tag_names}}
                          <span class="label bg-light text-xs text-dark rounded">{{.}}</span>
                        {{/tag_names}}
                      </div>
                    </td>
                    <td class="text-right" style="{{ style }}">
                      <a href="#" class="hidden-print" data-id="{{itemId}}"><i class="material-icons text-muted">check</i></a>
                    </td>
                  </tr>
                {{/name}}
              {{/order_details}}
            </tbody>
          </table>
        </div>

        <div class="col-xs-12 lter wrapper text-white hidden-print bottom-container">

          <div class="pull-left">
            <div class="h5 font-bold text-{{order_status_color}}">{{order_status_name}}</div>
            <div><span class="h3">{{elapsedHours}}h</span> <span class="h3">{{elapsedMins}}m</span></div>
          </div>

          <div class="pull-right">
            <a href="#" class="btn btn-rounded {{actionBtnColor}} font-bold text-u-c processOrderBtn" data-order="{{number_id}}" data-type="{{actionBtnType}}" data-id="{{transaction_id}}">{{actionBtn}}</a>
          </div>

        </div>

      </div>
    </div>
    <div id="sound" style="display:none;"></div>
  </script>
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
  ], 'js');
  ?>
  <script type="text/javascript" src="/standalone/scripts/ncm-ws.js"></script>
  <script type="text/javascript">
    window.ese = '<?= validateHttp('s') ?>';
    var baseUrl  = '<?= $baseUrl ?>';
    var outletID = '<?= $EOUTLET_ID ?>';
    var WS_URL   = '<?= WS_URL ?>';

    <?php
    if ($_GET['update']) {
      ob_start();
    ?>

      var ncmKDS = {
        cachedResult: false,
        oldCachedResult: false,
        timeagoInterval: null,
        dataLoadInterval: null,
        lastChecked: false,
        loading: false,
        isUserActive: true,
        xhr: false,
        canLoad: true,
        sliding: false,
        slide: 0,
        getOrdersIntval: 60000,
        updateUIIntval: 30000,
        userIddleTime: 20 * 60000,
        waitingOrders: 0,
        scrollPos: 0,
        cardsPerScreen: iftn(simpleStorage.get('cardsPerScreen'), 4),
        orders: [],
        computerHour: moment().format('YYYY-MM-DD HH'),
        init: () => {
          moment.locale('es');

          Mousetrap.bind('left', function() {
            $('.left').trigger('click');
          });

          Mousetrap.bind('right', function() {
            $('.right').trigger('click');
          });

          var h = $(window).height();
          $('.fullHeight').css({
            'height': h + 'px'
          });

          $('#kdsNamePlc').text(simpleStorage.get('kdsName'));

          ncmKDS.pusher = new NcmWS(WS_URL);

          var channel = ncmKDS.pusher.subscribe(outletID + '-KDS');
          channel.bind('order', (result) => {
            ncmKDS.dataLoaders();
          });

          ncmKDS.startDataLoad();
          ncmKDS.getTags();
          ncmKDS.getCategories();
          ncmKDS.listeners();
        },
        listeners: () => {

          ncmHelpers.onClickWrap('#settingsBtn', function(event, tis) {
            ncmKDS.render($('#settingsTpl'), {}, $('#modalSmall .modal-content'));
            $('#modalSmall').modal('show');
          });

          ncmHelpers.onClickWrap('#fullScreenBtn', function(event, tis) {
            $(document).toggleFullScreen();
          });

          ncmHelpers.onClickWrap('#backToFirst', function(event, tis) {
            ncmKDS.resetScreenPos();
          });

          ncmHelpers.onClickWrap('#resetConfig', function(event, tis) {
            simpleStorage.flush();
            location.reload(true);
          });

          ncmHelpers.onClickWrap('.processOrderBtn', function(event, tis) {
            var canPrint = simpleStorage.get('print');
            var id = tis.data('id');
            var type = tis.data('type');
            var oNo = tis.data('order');
            var index = $('#' + id).data('index');
            index = !index ? 0 : index;

            if (type == 'end') {

              ncmDialogs.confirm('¿Finalizar orden #' + oNo + '?', '', 'question', function(res) {
                if (res) {
                  delete ncmKDS.activeOrders[oNo];
                  delete ncmKDS.cachedResult.orders[index];

                  var $tisCard = $('#' + id);
                  var $tisItm = $tisCard.parent('.item');
                  var remainingCards = $tisItm.find('.card').length;

                  $tisCard.addClass('fadeOutUp');

                  setTimeout(function() {
                    if (remainingCards < 2) {
                      ncmKDS.resetScreenPos();
                    }

                    ncmKDS.setUIX(ncmKDS.cachedResult);
                  }, 400);

                  $.get('/kds.php?s=' + window.ese + '&action=update&i=' + id + '&t=' + type + '&d=' + currDate, success);
                }
              });

            } else {
              if (canPrint) {
                $('#' + id).print();
              }

              tis.data('type', 'end');
              tis.text('Finalizar');
              tis.removeClass('btn-info');
              tis.addClass('btn-success');

              ncmKDS.cachedResult.orders[index].order_status = 3;

              tis.prop('disabled', true);
              tis.addClass('disabled');

              setTimeout(function() {
                ncmKDS.setUIX(ncmKDS.cachedResult);
              }, 3000);

              $.get('/kds.php?s=' + window.ese + '&action=update&i=' + id + '&t=' + type + '&d=' + currDate, success);

            }

            var success = (data) => {};

            var currDate = moment().format('YYYY-MM-DD HH:mm:ss');

          });

          $(window).on('scroll', function() {
            ncmKDS.scrollPos = $(this).scrollTop();
          });

          $('#modalSmall').off('hidden.bs.modal,show.bs.modal,shown.bs.modal').on('show.bs.modal', function() {
            $('#modalSmall input#kdsName').val(simpleStorage.get('kdsName'));

            $('#modalSmall input#kdsName').off('keyup').on('keyup', function() {
              var name = $(this).val();
              simpleStorage.set('kdsName', name);
              $('#kdsNamePlc').text(name);
            });

            var canPrint = simpleStorage.get('print');

            if (canPrint) {
              $('#print').addClass('selected');
            }

            var playSound = simpleStorage.get('sound');
            if (playSound) {
              $('#soundOn').addClass('selected');
            }

            switchit(function(tis, active) {
              simpleStorage.set('print', active);
            }, true, '#print');

            switchit(function(tis, active) {
              simpleStorage.set('sound', active);

              if (active) {
                ncmHelpers.playSound('newOrder');
                ncmDialogs.push('Notificaciones Activadas', 'Aquí recibirá las notificaciones de cada pedido', 4000);
              }
            }, true, '#soundOn');

            var orderOrder = simpleStorage.get('orderOrder');
            if (orderOrder) {
              $('#orderOrder').addClass('selected');
            }

            switchit(function(tis, active) {

              simpleStorage.set('orderOrder', active);

              ncmKDS.lastChecked = false;
              ncmKDS.xhr.abort();

              location.reload();

            }, true, '#orderOrder');

            $('#modalSmall select#cardsPerScreen').val(ncmKDS.cardsPerScreen);

            $('#modalSmall select#cardsPerScreen').off('change').on('change', function() {
              var no = $(this).val();
              simpleStorage.set('cardsPerScreen', no);
              ncmKDS.cardsPerScreen = no;
              ncmKDS.setUIX(ncmKDS.cachedResult);
            });

            var $catsEl = $('#modalSmall select#allowedCategories');

            $catsEl.select2({
              placeholder: "Seleccione",
              theme: "bootstrap",
              language: 'es'
            }).off('select2:select select2:unselect').on('select2:select select2:unselect', function(e) {

              console.log('allowedCats', $(this).val());

              simpleStorage.set('allowedCategories', $(this).val());

              ncmKDS.resetScreenPos();
              setTimeout(function() {
                var copyOld = ncmKDS.duplicateJson(ncmKDS.oldCachedResult);
                ncmKDS.cachedResult = copyOld;
                ncmKDS.setUIX(ncmKDS.cachedResult);
              }, 700);


            });

            ncmKDS.buildCatsList();
          }).on('hidden.bs.modal', function() {
            ncmKDS.setUIX(ncmKDS.cachedResult);
            setTimeout(function() {
              ncmKDS.resetScreenPos();
            }, 100);
          });

          $('#ordersList .carousel').on('slide.bs.carousel', function(e) {
            var slideFrom = $(this).find('.active').index();
            var slideTo = $(e.relatedTarget).index();
            ncmKDS.slide = slideTo;
            ncmKDS.sliding = true;
          }).on('slid', function(e) {
            ncmKDS.sliding = false;
          });

          $('#ordersList .carousel').off('touchstart').on('touchstart', function(event) {
            const xClick = event.originalEvent.touches[0].pageX;
            $(this).one('touchmove', function(event) {
              const xMove = event.originalEvent.touches[0].pageX;
              const sensitivityInPx = 5;

              if (Math.floor(xClick - xMove) > sensitivityInPx) {
                $(this).carousel('next');
              } else if (Math.floor(xClick - xMove) < -sensitivityInPx) {
                $(this).carousel('prev');
              }
            });
            $(this).off('touchend').on('touchend', function() {
              $(this).off('touchmove');
            });
          });

        },
        buildList: (data, options) => {

          console.log('buildlist start');

          var out = [];
          var cols = 0;
          var block = '';
          var page = 0;
          var pages = '';
          var cnt = 0;
          var allTags = simpleStorage.get('tags');
          var allCats = simpleStorage.get('allowedCategories');
          var avgOrderTime = 30;
          var times = [];
          var playingSound = false;
          var date = '';
          var duration = '';
          ncmKDS.orders = data.orders;

          $.each(ncmKDS.orders, function(k, o) {

            //if(!ncmHelpers.validInObj(o,'order_details')){    
            ncmKDS.orders[k].index = k;
            $.each(o.order_details, function(key, value) {
              date = o.date;
              //voy sumando la duración de cada producto y meto en un array con date key, asi uso como tiempo limite de cada orden.
              duration = (value.duration) ? parseInt(value.duration) : 0;
              times[date] = ((times[date]) ? times[date] : 0) + duration;

              if (ncmHelpers.validate(allCats)) {
                if ($.inArray(value.category_id, allCats) < 0) {
                  ncmKDS.orders[k].order_details[key] = false;
                }
              }
            });
            //}     

          });

          $.each(ncmKDS.orders, function(key, order) {
            var tr = '';
            var skipLine = true;
            var date = order.date;

            //cards per screen
            if (ncmKDS.cardsPerScreen == 4) {
              order.cols_combo = 'col-lg-3 col-md-3';
            } else if (ncmKDS.cardsPerScreen == 6) {
              order.cols_combo = 'col-lg-2 col-md-2';
            } else if (ncmKDS.cardsPerScreen == 12) {
              order.cols_combo = 'col-lg-1 col-md-1';
            }

            //if(!ncmHelpers.validInObj(order,'order_details')){

            $.each(order.order_details, function(k, value) {

              if (!value || !value.name) {
                return;
              } else {
                skipLine = false;
              }

              value.fnote = ncmHelpers.markupt2HTML({
                text: value.note,
                type: 'MtH'
              }); //ncmHelpers.isBase64(value.note);

              var status = (value.hasOwnProperty("status")) ? value.status : 2;
              var canceled = '';
              value.style = '';

              //if(value.type == 'inComboAddons' || value.type == 'inCombo'){
              if (value.parent && !value.isParent) {
                value.style = 'padding:5px!important; font-size:12px;!important; color:#788188 !important';
                value.name = (value.name.charAt(1) !== "-") ? ' - ' + value.name : value.name;
              }

              if (status == 0) {
                canceled = 'text-l-t text-muted';
              }

            });

            console.log(order.order_details);



            //}

            if (skipLine) {
              return;
            }

            cols++;

            var orderDuration = (times[date] > 0) ? times[date] : avgOrderTime;

            var dateX = moment(date).utc().format("X");

            var tiempo = explodes(' ', date, 1);
            var hora = explodes(':', tiempo, 0);
            var min = explodes(':', tiempo, 1);
            order.time_at = moment(date).format('HH:mm');
            var nextBtn = 'Iniciar';
            var nextBtnType = 'start';
            var nextBtnColor = 'btn-info';
            var animation = '';

            if (order.order_status == 3) {
              nextBtn = 'Finalizar';
              nextBtnType = 'end';
              nextBtnColor = 'btn-success';
            }

            //duration
            var now = moment(); //now
            var then = moment(date);
            var diff = moment.duration(now.diff(then));

            var elapsed = Math.round(diff.asMinutes());
            order.elapsedMins = diff.minutes();
            order.elapsedHours = diff.hours();

            if ($.inArray(parseInt(order.number_id), ncmKDS.activeOrders) < 0) {

              if (order.elapsedMins < 2) {
                animation = 'fadeInUp';

                ncmDialogs.push('KDS', 'Nueva orden # ' + order.number_id);

                if (simpleStorage.get('sound')) {
                  ncmHelpers.playSound('newOrder');
                }
              }

              ncmKDS.activeOrders.push(parseInt(order.number_id));
            }

            //bar
            var halfMax = orderDuration / 2;
            order.background = 'bg text-white';
            order.barColor = 'bg-warning';

            if (elapsed > orderDuration) {
              order.background = 'bg-danger lt text-white';
              order.barColor = 'bg-danger';
            } else if (elapsed > halfMax) {
              order.background = 'bg-warning text-dark';
              order.barColor = 'bg-danger';
            }

            if (elapsed < halfMax) {
              order.barWidth = ncmKDS.getPercent(elapsed, halfMax);
            } else {
              order.barWidth = ncmKDS.getPercent(elapsed - halfMax, orderDuration);
            }

            if (cols == 1) {
              var active = '';
              if (cnt == 0) {
                active = 'active';
              }
              block += '<div class="item ' + active + ' speed-4x" style="display:flex; justify-content: center">';
              pages += '<li data-target="#ordersList" data-slide-to="' + page + '" class="' + active + '"></li>';
            }

            var orderSource = order.order_name;
            var orderName = 'Orden';

            if (orderSource == 'ecom') {
              orderName = 'Online';
            } else if ($.isNumeric(orderSource)) {
              orderName = 'Mesa ' + orderSource;
            }

            order.actionBtn = nextBtn;
            order.actionBtnType = nextBtnType;
            order.actionBtnColor = nextBtnColor;
            order.source = orderName;
            order.animation = animation;

            order.order_fnote = ncmHelpers.markupt2HTML({
              text: order.order_note,
              type: 'MtH'
            });



            //if(!skipLine){
            block += ncmKDS.render($('#blockTpl'), order, false, true);
            //}

            if (cols == ncmKDS.cardsPerScreen) {
              block += '</div>';
              cols = 0;
            }

            cnt++;
          });

          return [block, pages];
        },
        duplicateJson: (value) => {
          return JSON.parse(JSON.stringify(value));
        },
        buildCatsList: () => {
          var $catsEl = $('#modalSmall select#allowedCategories');
          var cats = simpleStorage.get('categories');
          var allowed = simpleStorage.get('allowedCategories');

          $catsEl.html('');

          if (cats) {

            $.each(cats, function(key, value) {

              var selected = false;

              if ($.inArray(value.ID, allowed) > -1) {
                selected = 'selected';
              }

              $catsEl.append($('<option>', {
                value: value.ID,
                text: value.name,
                selected: selected
              }));

            });
          }
        },
        setUIX: (data) => {
          //rebuild data

          if (!$.isEmptyObject(data.orders)) {

            console.log('obj not empty', data.orders, ncmHelpers.validate(data.orders, 'error'));

            if (ncmHelpers.validate(data.orders, 'error')) {
              $('.carousel-inner').html('');
            }

            var newData = {
              orders: []
            };
            $.each(data.orders, function(i, val) {

              if (ncmHelpers.validInObj(val, 'UID')) {
                newData.orders.push(val);
              }

            });

            data = newData;
            ncmKDS.cachedResult.orders = newData.orders;

            var content = ncmKDS.buildList(data);

            if (ncmHelpers.validate(content[0])) {
              $('.carousel-inner').html(content[0]);
              $('.carousel-indicators').html(content[1]);
            } else {
              $('.carousel-control, .carousel-indicators').addClass('hidden');
            }

            $(window).scrollTop(ncmKDS.scrollPos);

            if (ncmKDS.slide > 0) {
              $('.carousel .item').eq(0).removeClass('active');
              $('.carousel .item').eq(ncmKDS.slide).addClass('active');
            }

            ncmKDS.countOrders();
            ncmKDS.listeners();
          } else {
            console.log('obj is empty', ncmHelpers.validate(data.orders, 'error'));
            $('.carousel-inner').html('');
          }
        },
        activeOrders: [],
        resetScreenPos: function() {
          $('.carousel').carousel(0);
        },
        getTags: function() {
          var success = function(data) {
            simpleStorage.set('tags', data);
          };

          $.get('/kds.php?s=' + window.ese + '&action=tags', success);
        },
        getCategories: function() {
          var success = function(data) {
            simpleStorage.set('categories', data);
          };

          $.get('/kds.php?s=' + window.ese + '&action=categories', success);
        },
        startDataLoad: function() {
          clearInterval(ncmKDS.dataLoadInterval);
          ncmKDS.dataLoaders();

          //para actualizar el tiempo transcurrido
          ncmKDS.timeagoInterval = setInterval(function() {

            if (!ncmKDS.sliding) {
              ncmKDS.setUIX(ncmKDS.cachedResult);
            }

          }, ncmKDS.updateUIIntval);
        },
        dataLoaders: function() {

          var success = function(data) {
            ncmKDS.lastChecked = moment().format('YYYY-MM-DD HH:mm:ss');

            ncmKDS.cachedResult = data;
            ncmKDS.oldCachedResult = ncmKDS.duplicateJson(data);

            ncmKDS.setUIX(ncmKDS.cachedResult);
            ncmKDS.loading = false;
          };

          var orderOrder = simpleStorage.get('orderOrder') ? 1 : 0;

          var loaderXhr = function() {
            if (ncmKDS.isUserActive && !ncmKDS.loading && ncmKDS.canLoad) {
              ncmKDS.loading = true;
              ncmKDS.computerHour = moment().format('YYYY-MM-DD HH');

              ncmKDS.xhr = $.get('/kds.php?s=' + window.ese + '&action=lists&time=' + ncmKDS.lastChecked + '&compTime=' + ncmKDS.computerHour + '&reverse=' + orderOrder, success).fail(function(jqXHR) {
                ncmKDS.loading = false;
              });
            } else {
              //ncmKDS.loading = false;
            }
          };

          loaderXhr();
        },
        render: function($template, data, $wrap, returns) {
          var template = $template.html();
          var mustached = Mustache.render(template, data);
          if (returns) {
            return mustached;
          } else {
            $wrap.html(mustached);
          }
        },
        countOrders: function() {
          ncmKDS.waitingOrders = $('.card').length;

          $('#waitingOrders').text('x' + ncmKDS.waitingOrders);
        },
        getPercent: function(oldNumber, newNumber) {
          return (oldNumber * 100) / newNumber;
        }
      };

      $(document).ready(function() {
        ncmKDS.init();
      });

    <?php
      $script = ob_gets_contents();
      //echo $script;
      minifyJS([$script => 'scripts' . $baseUrl . '.js']);
    }
    ?>
  </script>
  <script src="scripts<?= $baseUrl ?>.js?<?= date('d.i') ?>"></script>

</body>

</html>

<?php
include_once('/home/encom/public_html/panel/includes/freememory.php');
include_once('/home/encom/public_html/panel/includes/compression_end.php');
?>