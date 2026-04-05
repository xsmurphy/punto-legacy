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

if (validateHttp('action') == 'list') {

  $memUse['list start'] = memory_get_usage();

  set_time_limit(60);

  $db->Close();
  unset($db, $setting, $plansValues);

  if (validateHttp('compTime') != $serverDate) {
    jsonDieResult(['error' => 'time', 'servers' => $serverDate, 'sent' => validateHttp('compTime')]);
  }

  $secs             = 5; //segundos de espera
  $timestamp        = validateHttp('time');
  $array            = [];
  $maxSleeps        = 5;
  $sleeped          = 0;
  $i                = 1;

  while (true) {
    $memUse['loop' . $i] = memory_get_usage();
    gc_collect_cycles();
    $getList          = true; //true porque cuando no hay timestamp procesa

    if ($timestamp) {
      //consulto last updated order
      $updated = json_decode(curlContents('https://api.encom.app/get_last_update.php', 'POST', $data), true);

      if (strtotime($updated['orders']) < strtotime($timestamp)) {
        $getList          = false;
      }
    }

    if ($getList) {
      $array            = [];
      $data['type']     = 12;
      $data['limit']    = 30;
      $data['order']    = 'DESC';
      $data['status']   = '0,1,2,3'; //'0,1,2,3,4,5';

      if (validateHttp('reverse')) {
        $data['reverse']    = 'true';
      }

      $data['from']     = date('Y-m-d H:i:s', strtotime('-1 day'));
      $data['to']       = '2050-01-01 00:00:00'; //date('Y-m-d H:i:s');
      //$data['test']     = 1;

      $result           = json_decode(curlContents('https://api.encom.app/get_orders.php', 'POST', $data), true);
      $array['orders']  = $result;

      break;
    } else {
      unset($updated, $getList);

      $sleeped++;

      if ($sleeped < $maxSleeps) {
        sleep($secs);
      } else {
        $array['error'] = 'timeout';
        break;
      }
    }
    $i++;
  }

  $memUse['before unset'] = memory_get_usage();

  unset($data, $updated, $getList, $timestamp, $result, $sleeped, $maxSleeps);

  $memUse['after unset'] = memory_get_usage();

  $array['memory'] = $memUse;

  include_once('/home/encom/public_html/panel/includes/freememory.php');

  jsonDieResult($array);
}

if (validateHttp('action') == 'lists') {

  $db->Close();
  unset($db, $setting, $plansValues);

  if (validateHttp('compTime') != $serverDate) {
    jsonDieResult(['error' => 'time', 'servers' => $serverDate, 'sent' => validateHttp('compTime')]);
  }

  $secs             = 10; //segundos de espera
  $timestamp        = validateHttp('time');
  $array            = [];
  $maxSleeps        = 5;
  $sleeped          = 0;

  gc_collect_cycles();
  $getList          = true; //true porque cuando no hay timestamp procesa

  if ($timestamp) {
    //consulto las updated order
    $updated = json_decode(curlContents('https://api.encom.app/get_last_update.php', 'POST', $data), true);
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

    $result           = json_decode(curlContents('https://api.encom.app/get_orders.php', 'POST', $data), true);
    $array['orders']  = $result;
    print_r($array['orders']);
  }

  //$memUse['before unset'] = memory_get_usage();
  //$array['memory'] = $memUse;

  include_once('/home/encom/public_html/panel/includes/freememory.php');

  jsonDieResult($array);
}

if (validateHttp('action') == 'items') {

  //obtengo el listado de items al cargar y guardo en local storage
  $data['nolimit'] = true;
  $items      = json_decode(curlContents('https://api.encom.app/get_items', 'POST', $data), true);

  jsonDieResult($items);
}

if (validateHttp('action') == 'tags') {
  //obtengo el listado de items al cargar y guardo en local storage
  $tags           = json_decode(curlContents('https://api.encom.app/get_tags.php', 'POST', $data), true);

  jsonDieResult($tags);
}

if (validateHttp('action') == 'categories') {

  //obtengo el listado de items al cargar y guardo en local storage
  $cats           = json_decode(curlContents('https://api.encom.app/get_categories.php', 'POST', $data), true);

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
  $update           = json_decode(curlContents('https://api.encom.app/edit_order_status.php', 'POST', $data), true);

  if ($update['success']) {

    $data['channel']  = enc(OUTLET_ID) . '-register';
    $data['event']    = 'order';
    $data['message']  = json_encode(['ID' => $id, 'registerID' => false]);
    curlContents('https://api.encom.app/send_webSocket.php', 'POST', $data);


    $data['channel']  = enc(OUTLET_ID) . '-KDS';
    $data['event']    = 'order';
    $data['message']  = $id;
    curlContents('https://api.encom.app/send_webSocket.php', 'POST', $data);
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
  </style>

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
    <div id="ordersList" class="carousel slide" data-ride="carousel" data-interval="false">
      <!-- Indicators -->
      <ol class="carousel-indicators">
        <li data-target="#ordersList" data-slide-to="0" class="active"></li>
        <li data-target="#ordersList" data-slide-to="0" class=""></li>
      </ol>

      <!-- Wrapper for slides -->
      <div class="carousel-inner">
        <div class="item wrapper m-t-n active speed-4x" style="height: 95vh;">
          <div id="container" class="row">

          </div>
          <div id="container2" class="row">

          </div>

        </div>

        <!-- Remaining slides -->
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
            <option value="12">12 (doce)</option>
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

        <div class="col-sm-6 col-xs-12"></div>

      </div>

      <div class="col-xs-12 text-center m-t-lg">
        <a href="#" class="btn btn-default btn-rounded text-u-c font-bold text-danger" id="resetConfig">Restaurar</a>
      </div>

    </div>
  </script>
  <script type="text/html" id="blockTpl">
    <div class="{{cols_combo}} col-xs-12 wrapper animated speed-4x {{animation}} m-b-lg card m-r-n" id="{{transaction_id}}" data-index="{{index}}">
      <div class="col-xs-12 no-padder r-3x clear">
        <div class="col-xs-12 {{background}} no-padder text-md">
          <div class="{{barColor}} rounded" style="height: 4px; width:{{barWidth}}%;"></div>

          <div class="col-xs-12 wrapper">
            <div class="col-xs-4 no-padder m-t-n-xs">
              <div>{{source}}</div>
              <div class="font-bold h2 m-t-n-xs">#{{number_id}}</div>
            </div>

            <div class="col-xs-8 no-padder text-right">
              <div class="font-bold"><span class="font-normal">{{user_name}}</span> {{time_at}}</div>
              <div>{{customer_name}}</div>
            </div>

          </div>

        </div>
        <div class="col-xs-12 panel text-black m-n no-padder">
          {{#order_note}}
            <div class="col-xs-12 wrapper-sm bg-light text-dark text-u-c">{{{order_fnote}}}</div>
          {{/order_note}}
          <div class="col-xs-12 wrapper-sm no-bg">
            {{#order_tags_name}}
              <span class="label bg-light text-xs text-dark rounded">{{.}}</span>
            {{/order_tags_name}}
          </div>
          <table class="table">
            <tbody>
              {{#order_details}}
                {{#name}}
                  <tr class="{{#status}}font-bold{{/status}}{{^status}}text-l-t text-danger{{/status}}">
                    <td>{{count}}</td>
                    <td>
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
                    <td class="text-right">
                      <a href="#" class="hidden-print" data-id="{{itemId}}"><i class="material-icons text-muted">check</i></a>
                    </td>
                  </tr>
                {{/name}}
              {{/order_details}}
            </tbody>
          </table>
        </div>
        <div class="col-xs-12 lter wrapper text-white hidden-print">

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
  ], 'js');
  ?>
  <script type="text/javascript">
    window.ese = '<?= validateHttp('s') ?>';
    var baseUrl = '<?= $baseUrl ?>';
    var outletID = '<?= $EOUTLET_ID ?>';
    const container2 = document.querySelector("#container2");
const container = document.getElementById("container");

function generateBox(jsonResult) {
  const fragment = document.createDocumentFragment();

  jsonResult.forEach((item) => {
    const card = document.createElement("div");
    card.className = "col-xs-2 wrapper animated speed-4x m-b-lg card m-r-n";
    card.id = item.dataId;
    card.setAttribute("data-index", "0");

    const wrapper = document.createElement("div");
    wrapper.className = "col-xs-12 no-padder r-3x clear";
    card.appendChild(wrapper);

    const bg = document.createElement("div");
    bg.className = "col-xs-12 bg text-white no-padder text-md";
    wrapper.appendChild(bg);

    const innerWrapper = document.createElement("div");
    innerWrapper.className = "col-xs-12 wrapper";
    bg.appendChild(innerWrapper);

    const menuDiv = document.createElement("div");
    menuDiv.className = "col-xs-4 no-padder m-t-n-xs";
    menuDiv.textContent = "Menu";
    innerWrapper.appendChild(menuDiv);

    const emptyDiv = document.createElement("div");
    emptyDiv.className = "col-xs-8 no-padder text-right";
    innerWrapper.appendChild(emptyDiv);

    const panel = document.createElement("div");
    panel.className = "col-xs-12 panel text-black m-n no-padder";
    wrapper.appendChild(panel);

    const wrapperSm = document.createElement("div");
    wrapperSm.className = "col-xs-12 wrapper-sm no-bg";
    panel.appendChild(wrapperSm);

    const table = document.createElement("table");
    table.className = "table";
    wrapperSm.appendChild(table);

    const tbody = document.createElement("tbody");
    tbody.innerHTML = generateTableRows(item.items);
    table.appendChild(tbody);

    const lterWrapper = document.createElement("div");
    lterWrapper.className = "col-xs-12 lter wrapper text-white hidden-print";
    wrapper.appendChild(lterWrapper);

    const pullLeftDiv = document.createElement("div");
    pullLeftDiv.className = "pull-left";
    lterWrapper.appendChild(pullLeftDiv);

    const pullRightDiv = document.createElement("div");
    pullRightDiv.className = "pull-right";
    lterWrapper.appendChild(pullRightDiv);

    const totalPlatosLink = document.createElement("a");
    totalPlatosLink.href = "#";
    totalPlatosLink.className = "label bg-dark font-bold text-u-c processOrderBtn";
    totalPlatosLink.setAttribute("data-order", "649");
    totalPlatosLink.setAttribute("data-type", "start");
    totalPlatosLink.setAttribute("data-id", item.dataId);
    totalPlatosLink.textContent = item.totalItems;
    pullRightDiv.textContent = "Total de platos: ";
    pullRightDiv.appendChild(totalPlatosLink);

    fragment.appendChild(card);
  });

  container2.appendChild(fragment);

  jsonResult.forEach((interval) => {
    const div = document.createElement("div");
    div.className = "col-xs-2 m-r-n";

    const h2 = document.createElement("h2");
    h2.textContent = interval.intervalStart;
    h2.className = "text-white font-bold";

    div.appendChild(h2);
    container.appendChild(div);
  });
}

function generateTableRows(items) {
  return Object.entries(items).map(([itemName, quantity]) => {
    return `
      <tr class="font-bold">
        <td>${quantity}</td>
        <td>
          <span class="text-u-c">${itemName}</span>
          <div></div>
        </td>
        <td class="text-right">
          <a href="#" class="hidden-print" data-id="${generateDataId()}"><i class="material-icons text-muted">check</i></a>
        </td>
      </tr>
    `;
  }).join('');
}

function generateDataId() {
  return Math.random().toString(36).substr(2, 4);
}

function compararIntervalos(a, b) {
  const intervaloA = a.intervalStart;
  const intervaloB = b.intervalStart;

  if (intervaloA < intervaloB) {
    return -1;
  }
  if (intervaloA > intervaloB) {
    return 1;
  }
  return 0;
}

function prepareJSON(intervalGroups) {
  const result = [];

  for (const intervalKey in intervalGroups) {
    const orders = intervalGroups[intervalKey];
    const interval = moment(intervalKey, "YYYY-MM-DD HH:mm");
    const intervalStart = interval.format("HH:mm");
    const intervalEnd = interval.add(30, "minutes").format("HH:mm");
    let totalOrders = orders.length;

    const items = {};
    orders.forEach((order) => {
      order?.order_details?.forEach((detail) => {
        const itemName = detail.itemName;
        const itemQty = detail.count;
        items[itemName] = (items[itemName] || 0) + parseInt(itemQty);
      });
    });

    let totalItems = 0;
    for (const itemName in items) {
      totalItems += items[itemName];
    }

    const resultItem = {
      intervalStart,
      intervalEnd,
      totalOrders,
      totalItems,
      items,
    };

    result.push(resultItem);
  }

  return result.sort(compararIntervalos);
}

const ncmKDSGroupByTime = {
  load: async () => {
    try {
      const response = await fetch(`/kds.php?s=${window.ese}&action=lists&compTime=${ncmKDS.computerHour}`);
      const jsonOrders = await response.json();
      container.innerHTML = "";
      container2.innerHTML = "";

      const processedOrders = ncmKDSGroupByTime.processArray(jsonOrders);
      generateBox(processedOrders);
    } catch (error) {
      console.error(error);
    }
  },
  processArray: (jsonOrders) => {
    const separatedJSON = {};
    jsonOrders.orders.forEach((order) => {
      const uid = order.UID;

      if (!separatedJSON[uid]) {
        separatedJSON[uid] = {
          UID: uid,
          DUE_DATE: "",
          order_details: [],
          order_total: order.order_total,
        };
      }

      const currentDueDate = moment(order.due_date);
      separatedJSON[uid].DUE_DATE = currentDueDate.format("YYYY-MM-DD HH:mm:ss");

      order.order_details.forEach((detail) => {
        const orderDetails = {
          itemId: detail.itemId,
          itemName: detail.name,
          count: detail.count,
          oQty: detail.oQty,
          parent: detail.parent,
          isParent: detail.isParent,
        };
        separatedJSON[uid].order_details.push(orderDetails);
      });
    });

    const separatedJSONArr = Object.values(separatedJSON);

    const intervalGroups = ncmKDSGroupByTime.groupOrdersByInterval(separatedJSONArr, 30);

    return prepareJSON(intervalGroups);
  },
  groupOrdersByInterval: (orders, interval) => {
    const intervalGroups = {};

    orders?.forEach((order) => {
      const dueDate = moment(order.DUE_DATE);
      const intervalKey = dueDate.format("YYYY-MM-DD HH:mm");

      if (!intervalGroups[intervalKey]) {
        intervalGroups[intervalKey] = [];
      }

      intervalGroups[intervalKey].push(order);
    });

    return intervalGroups;
  },
};

const ncmKDS = {
  computerHour: moment().format("YYYY-MM-DD HH"),
};

document.addEventListener("DOMContentLoaded", () => {
  ncmKDSGroupByTime.load();
  setInterval(ncmKDSGroupByTime.load, 30000);
});
  </script>


</body>

</html>

<?php
include_once('/home/encom/public_html/panel/includes/freememory.php');
include_once('/home/encom/public_html/panel/includes/compression_end.php');
?>