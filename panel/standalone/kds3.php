<?php
//echo number_format(memory_get_usage() / 1048576, 2);
//sleep(5);
//die('En mantenimiento');
$memUse['start'] = memory_get_usage();
include_once('sa_head.php');

$memUse['after head'] = memory_get_usage();

$data         = explodes(',', base64_decode($_GET['s']));
$ECOMPANY_ID  = $data[0];

define('COMPANY_ID', dec($ECOMPANY_ID) );
define('OUTLET_ID', dec($data[1]) );

// obtengo limitaciones de KDS del plan
// cuento cantidad de instalaciones activas
// si hay menos instalo una nueva
// si hay igual sobreescribo la ultima
// para resetear elimino instancias activas desde el modulo en el panel
//VER COMO HACER PARA QUE REEMPLACE AMBAS INSTANCIAS PORQUE SI USO SOLO LA ULTIMA SIEMPRE SE VANA A SOBREESCRIBIR Y AL FINAL ES COMO UNA SOLA INSTANCIA

/*$_company = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$plan     = $_company['companyPlan'];

$_plans = ncmExecute("SELECT * FROM plans WHERE planId = ? LIMIT 1",[$plan]);
$maxKDS = $_plans['max_kds'];

$getInstalled = ncmExecute("SELECT COUNT(*) as count FROM taxonomy WHERE taxonomyType = 'kdsInstall' AND outletId = ? AND companyId = ?",[OUTLET_ID,COMPANY_ID]);

$kdsUID                     = generateUID();

if($getInstalled < $maxKDS){
  $record['taxonomyType']   = 'kdsInstall';
  $record['outletId']       = OUTLET_ID;
  $record['companyId']      = COMPANY_ID;
  $record['taxonomyExtra']  = $kdsUID;
  $insert                   = $db->AutoExecute('taxonomy', $record, 'INSERT');
}else{
  $lastActive = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'kdsInstall' AND outletId = ? AND companyId = ? ORDER BY taxonomyId DESC LIMIT 1",[OUTLET_ID,COMPANY_ID]);
  $record['taxonomyExtra']  = $kdsUID;
  $update = $db->AutoExecute('taxonomy', $record, 'UPDATE', 'taxonomyId = ' . $lastActive['taxonomyId'] . ' AND companyId = ' . COMPANY_ID);
}*/

$setting = ncmExecute("SELECT settingThousandSeparator,settingDecimal,settingCurrency,settingTimeZone,settingName FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

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
            'children'      => 'all'
          ];

$memUse['after credentials'] = memory_get_usage();

if(validateHttp('action') == 'time'){
  jsonDieResult(['date'=>$serverDate]);
}

if(validateHttp('action') == 'list'){

  $memUse['list start'] = memory_get_usage();
  
  set_time_limit(60);

  $db->Close();
  unset($db,$setting,$plansValues);

  if(validateHttp('compTime') != $serverDate){
    jsonDieResult(['error'=>'time']);
  }

  $secs             = 5;//segundos de espera
  $timestamp        = validateHttp('time');
  $array            = [];
  $maxSleeps        = 5;
  $sleeped          = 0;
  $i                = 1;

  while(true){
    $memUse['loop' . $i] = memory_get_usage();
    gc_collect_cycles();
    $getList          = true;//true porque cuando no hay timestamp procesa

    if($timestamp){
      //consulto last updated order
      $updated = json_decode(curlContents('https://api.encom.app/get_last_update.php','POST',$data),true);

      if( strtotime( $updated['orders'] ) < strtotime( $timestamp ) ){
        $getList          = false;
      }
    }

    if($getList){
      $array            = [];
      $data['type']     = 12;
      $data['limit']    = 30;
      $data['order']    = 'DESC';
      $data['status']   = '0,1,2,3';//'0,1,2,3,4,5';

      if(validateHttp('reverse')){
        $data['reverse']    = 'true';
      }

      $data['from']     = date('Y-m-d H:i:s',strtotime('-1 day'));
      $data['to']       = date('Y-m-d H:i:s');
      //$data['test']     = 1;

      $result           = json_decode(curlContents('https://api.encom.app/get_orders.php','POST',$data),true);
      $array['orders']  = $result;

      break;

    }else{
      unset($updated,$getList);

      $sleeped++;

      if($sleeped < $maxSleeps){
        sleep($secs);  
      }else{
        $array['error'] = 'timeout';
        break;
      }

    }
    $i++;
  }

  $memUse['before unset'] = memory_get_usage();

  unset($data,$updated,$getList,$timestamp,$result,$sleeped,$maxSleeps);

  $memUse['after unset'] = memory_get_usage();

  $array['memory'] = $memUse;

  include_once('/home/encom/public_html/panel/includes/freememory.php');
  
  jsonDieResult($array);
  
}

if(validateHttp('action') == 'lists'){

  $memUse['list start'] = memory_get_usage();
  
  $secs             = 10;//segundos de espera
  $timestamp        = validateHttp('time');
  $array            = [];
  $maxSleeps        = 5;
  $sleeped          = 0;

  gc_collect_cycles();
  $getList          = true;//true porque cuando no hay timestamp procesa

  if($timestamp){
    //consulto las updated order
    $updated = json_decode(curlContents('https://api.encom.app/get_last_update.php','POST',$data),true);
    if( strtotime( $updated['orders'] ) < strtotime( $timestamp ) ){
      $getList          = false;
    }
  }

  if($getList){
    $array            = [];
    $data['type']     = 12;
    $data['limit']    = 30;
    $data['order']    = 'DESC';
    $data['status']   = '0,1,2,3';//'0,1,2,3,4,5';

    if(validateHttp('reverse')){
      $data['reverse']    = 'true';
    }

    $data['from']     = date('Y-m-d H:i:s',strtotime('-1 day'));
    $data['to']       = date('Y-m-d H:i:s');
    //$data['test']     = 1;

    $result           = json_decode(curlContents('https://api.encom.app/get_orders.php','POST',$data),true);
    $array['orders']  = $result;
  }

  $memUse['before unset'] = memory_get_usage();
  $array['memory'] = $memUse;

  include_once('/home/encom/public_html/panel/includes/freememory.php');

  jsonDieResult($array);
}

if(validateHttp('action') == 'items'){

  //obtengo el listado de items al cargar y guardo en local storage
  $data['nolimit'] = true;
  $items      = json_decode(curlContents('https://api.encom.app/get_items','POST',$data),true);

  jsonDieResult($items);
}

if(validateHttp('action') == 'tags'){

  //obtengo el listado de items al cargar y guardo en local storage
  $tags           = json_decode(curlContents('https://api.encom.app/get_tags.php','POST',$data),true);

  jsonDieResult($tags);
}

if(validateHttp('action') == 'categories'){

  //obtengo el listado de items al cargar y guardo en local storage
  $cats           = json_decode(curlContents('https://api.encom.app/get_categories.php','POST',$data),true);

  jsonDieResult($cats);
}

if(validateHttp('action') == 'update'){
  $id               = validateHttp('i');
  $type             = validateHttp('t');
  $date             = validateHttp('d');

  if($type == 'start'){
    $status = 3;
  }else{
    $status = 5;
  }

  $data['id']       = $id;
  $data['status']   = $status;
  $data['date']     = $date;
  //hago update del stado de la orden
  $update = json_decode(curlContents('https://api.encom.app/edit_order_status.php','POST',$data),true);

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
  ],'css');
  ?>

  <style type="text/css">
    .select2-selection__choice{
      border-radius: 20px!important;
    }

    .select2-selection__choice__display{
      font-weight: bold;
      text-transform: uppercase;
    }

    body{
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
    
    <div id="ordersList" class="carousel slide " data-ride="carousel" data-interval="false">
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

    <div class="col-xs-12 wrapper bg" style="position: fixed; bottom:0;">
      <div class="col-xs-4 no-padder hidden-xs pointer" id="backToFirst">
        <img src="https://app.encom.app/images/incomeLogoLgGray.png" height="20">
      </div>
      <div class="col-sm-4 col-xs-8 no-padder text-center h4 text-white font-bold">
        <span id="kdsNamePlc">KDS</span>
        <span id="waitingOrders" class="text-warning"></span>

      </div>
      <div class="col-sm-4 col-xs-4 no-padder">
        <a href="#" id="settingsBtn" class="pull-right"><i class="material-icons">settings</i></a>
      </div>
    </div>

    <div class="modal fade" tabindex="-1" id="modalSmall" role="dialog">
      <div class="modal-dialog">
        <div class="modal-content r-24x clear no-bg no-border all-shadows">
          
        </div>
      </div>
    </div>

  </section>
  <script type="text/javascript">
    var noSessionCheck  = true;
    window.standAlone   = true;
  </script>
  <?php
  include_once("/home/encom/public_html/panel/includes/analyticstracking.php");
  ?>
  <script type="text/html" id="settingsTpl">
    <div class="col-xs-12 wrapper-lg bg-white">
      <div class="h1 text-dark m-b-lg font-bold">Configuración</div>
      <div class="font-bold m-t-lg text-u-c text-sm">Nombre del KDS</div>
      <input type="text" name="" class="form-control no-border no-bg b-b m-b" id="kdsName">

      <div class="font-bold m-t-lg text-u-c text-sm">Categorías permitidas</div>
      <select class="form-control no-border no-bg b-b" id="allowedCategories" multiple>
        <option></option>
      </select>

      <div class="font-bold m-t-lg text-u-c text-sm">Órdenes por pantalla</div>
      <input type="text" name="" class="form-control no-border no-bg b-b m-b" id="cardsPerScreen" disabled>

      <div class="row">

        <div class="col-sm-6">
          <div class="font-bold m-t-lg text-u-c text-sm">Imprimir orden al iniciar</div>
          <?=switchIn('print')?>
        </div>

        <div class="col-sm-6">
          <div class="font-bold m-t-lg text-u-c text-sm">Invertir orden</div>
          <?=switchIn('orderOrder')?>
        </div>

      </div>

      <div class="col-xs-12 text-center m-t-lg">
        <a href="#" class="btn btn-default btn-rounded text-u-c font-bold text-danger" id="resetConfig">Restaurar</a>
      </div>

    </div>
  </script>
  <script type="text/html" id="blockTpl">
    <div class="col-lg-3 col-md-4 col-xs-12 wrapper animated speed-4x {{animation}} card" id="{{transaction_id}}" data-date="{{date}}">
      <div class="col-xs-12 no-padder r-3x clear">
        <div class="col-xs-12 {{background}} no-padder text-md">
          <div class="{{barColor}} rounded" style="height: 4px; width:{{barWidth}}%;"></div>

          <div class="col-xs-12 wrapper">
            <div class="pull-right text-right">
              <div class="font-bold">{{time_at}}</div>
              <div class="">Por: {{user_name}}</div>
            </div>

            <div class="pull-left m-t-n-xs">
              <div>{{source}}</div>
              <div class="font-bold h2 m-t-n-xs">#{{number_id}}</div>
            </div>
          </div>

        </div>
        <div class="col-xs-12 panel m-n no-padder">
          {{#order_note}}
          <div class="col-xs-12 wrapper-sm bg-light text-dark text-u-c">{{order_note}}</div>
          {{/order_note}}
          <div class="col-xs-12 wrapper-sm no-bg">
            {{#order_tags_name}}
              <span class="label bg-light rounded">{{.}}</span>
            {{/order_tags_name}}
          </div>
          <table class="table">
            <tbody>
              {{#order_details}}
              {{#name}}
              <tr class="font-bold">
                <td>{{count}}</td>
                <td>
                <span class="text-u-c">{{name}}</span>
                {{#note}}
                <em class="block font-normal">{{{note}}}</em>
                {{/note}}
                <div>
                  {{#tags}}
                  <span class="badge">{{name}}</span>
                  {{/tags}}
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
            <a href="#" class="btn btn-rounded {{actionBtnColor}} font-bold text-u-c processOrderBtn" data-type="{{actionBtnType}}" data-id="{{transaction_id}}">{{actionBtn}}</a>
          </div>
            
        </div>
      </div>
    </div>
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
                  'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js'
                ],'js');
  ?>
<script type="text/javascript">
 

  var ncmKDS = {
    cachedResult    : false,
    oldCachedResult : false,
    timeagoInterval : null,
    dataLoadInterval: null,
    getOrdersIntval : 10000,
    updateUIIntval  : 5000,
    userIddleTime   : 20 * 60000,
    sliding         : false,
    waitingOrders   : 0,
    scrollPos       : 0,
    lastChecked     : false,
    loading         : false,
    xhr             : false,
    cardsPerScreen  : iftn(simpleStorage.get('cardsPerScreen'),4),
    orders          : [],
    slide           : 0,
    isUserActive    : true,
    serverDate      : '<?=$serverDate;?>',
    computerHour    : moment().format('YYYY-MM-DD HH'),
    canLoad         : true,
    init            : function(){
      moment.locale('es');

      Mousetrap.bind('left', function() {
          $('.left').trigger('click');
      });

      Mousetrap.bind('right', function() {
          $('.right').trigger('click');
      });

      var h = $(window).height();
      $('.fullHeight').css({'height':h + 'px'});

      $('#kdsNamePlc').text(simpleStorage.get('kdsName'));

      ncmKDS.startDataLoad();
      ncmKDS.getTags();
      ncmKDS.getCategories();
      ncmKDS.listeners();
      ncmKDS.detectIfUserIddle();
      
    },
    listeners : function(){
      onClickWrap('#settingsBtn',function(event,tis){
        ncmKDS.render($('#settingsTpl'),{},$('#modalSmall .modal-content'));
        $('#modalSmall').modal('show');
      },false,true);

      onClickWrap('#backToFirst',function(event,tis){
        ncmKDS.resetScreenPos();
      },false,true);

      onClickWrap('#resetConfig',function(event,tis){
        simpleStorage.flush();
        location.reload(true);
      },false,true);

      onClickWrap('.processOrderBtn',function(event,tis){
        var canPrint = simpleStorage.get('print');
        var id    = tis.data('id');
        var type  = tis.data('type');
        var date  = $('#' + id).data('date');

        if(canPrint){
          $('#' + id).print();   
        }

        if(type == 'end'){
          //$('.carousel-inner #' + id).hide();
          delete ncmKDS.cachedResult.orders[date];
          $('#' + id).addClass('fadeOutUp');
          setTimeout(function(){
            ncmKDS.setUIX(ncmKDS.cachedResult);
          },300);
        }else{
          tis.data('type','end');
          tis.text('Finalizar');
          tis.removeClass('btn-info');
          tis.addClass('btn-success');

          ncmKDS.cachedResult.orders[date].order_status    = 3;

          ncmKDS.setUIX(ncmKDS.cachedResult);
        }

        var success   = function(data){

        };

        var currDate = moment().format('YYYY-MM-DD HH:mm:ss');

        $.get('/kds3?s=<?=$_GET['s']?>&action=update&i=' + id + '&t=' + type + '&d=' + currDate,success);

        //cambiar estado con get
        
      },false,true);

      $(window).on('scroll',function() {
        ncmKDS.scrollPos = $(this).scrollTop();
      });

      $('#modalSmall').off('hidden.bs.modal,show.bs.modal,shown.bs.modal').on('show.bs.modal', function() {
        $('#modalSmall input#kdsName').val(simpleStorage.get('kdsName'));

        $('#modalSmall input#kdsName').off('keyup').on('keyup',function(){
          var name    = $(this).val();
          simpleStorage.set('kdsName',name);
          $('#kdsNamePlc').text(name);
        });

        var canPrint = simpleStorage.get('print');

        if(canPrint){
          $('#print').addClass('selected');
        }

        switchit(function(tis,active){
          simpleStorage.set('print',active);
        },true,'#print');
             
        var orderOrder = simpleStorage.get('orderOrder');
        if(orderOrder){
          $('#orderOrder').addClass('selected');
        }

        switchit(function(tis,active){

          simpleStorage.set('orderOrder',active);

          ncmKDS.lastChecked = false;
          ncmKDS.xhr.abort();

        },true,'#orderOrder');

        $('#modalSmall input#cardsPerScreen').val(ncmKDS.cardsPerScreen);

        $('#modalSmall input#cardsPerScreen').off('change').on('change',function(){
          var no    = $(this).val();
          simpleStorage.set('cardsPerScreen',no);
          ncmKDS.cardsPerScreen = no;
          ncmKDS.setUIX(ncmKDS.cachedResult);
        });

        var $catsEl = $('#modalSmall select#allowedCategories');

        $catsEl.select2({
          placeholder   : "Seleccione",
          theme         : "bootstrap",
          language      : 'es'
        }).on('select2:select select2:unselect', function (e) {

          simpleStorage.set('allowedCategories',$(this).val());

          ncmKDS.resetScreenPos();
          setTimeout(function(){
            var copyOld         = ncmKDS.duplicateJson(ncmKDS.oldCachedResult);
            ncmKDS.cachedResult = copyOld;
            ncmKDS.setUIX(ncmKDS.cachedResult);  
          },700);

          
        });

        ncmKDS.buildCatsList();
      }).on('hidden.bs.modal',function(){
        ncmKDS.setUIX(ncmKDS.cachedResult); 
        setTimeout(function(){
          ncmKDS.resetScreenPos();
        },100);
      });



      $('.carousel').on('slide.bs.carousel', function (e) {  
        var slideFrom   = $(this).find('.active').index();
        var slideTo     = $(e.relatedTarget).index();
        ncmKDS.slide    = slideTo;
        ncmKDS.sliding  = true;
      }).on('slid', function (e) {
        ncmKDS.sliding  = false;
      });

    },
    buildList : function(data,options){

      var out     = [];
      var cols    = 0;
      var block   = '';
      var page    = 0;
      var pages   = '';
      var cnt     = 0;
      var allTags = simpleStorage.get('tags');
      var allCats = simpleStorage.get('allowedCategories');
      var avgOrderTime = 30;
      var times       = [];
      ncmKDS.orders   = data.orders;

      $.each(ncmKDS.orders,function(d,o){
        $.each(o.order_details,function(key,value){
          //voy sumando la duración de cada producto y meto en un array con date key, asi uso como tiempo limite de cada orden.
          var duration  = (value.duration) ? parseInt( value.duration ) : 0;
          times[d]      = ((times[d]) ? times[d] : 0) + duration;

          if(ncmHelpers.validate(allCats)){
            if($.inArray(value.category_id,allCats) < 0){
              //delete ncmKDS.orders[d].order_details[key];
              ncmKDS.orders[d].order_details[key] = false;
            }
          }

        });
      });

      $.each(ncmKDS.orders,function(date,order){

        var tr      = '';
        var skipLine = true;
        
        $.each(order.order_details,function(key,value){
          
          if(!value || !value.name){
            return;
          }else{
            skipLine = false;
          }

          value.note = ncmHelpers.isBase64(value.note);

          var status    = (value.hasOwnProperty("status")) ? value.status : 2;
          var canceled  = '';
          
          if(status == 0){
            canceled = 'text-l-t text-muted';
          }
        });

        if(skipLine){
          return;
        }

        cols++;

        var orderDuration  = (times[date] > 0) ? times[date] : avgOrderTime;

        var tiempo    = explodes(' ',date,1);
        var hora      = explodes(':',tiempo,0);
        var min       = explodes(':',tiempo,1);
        order.time_at = moment(order.date).format('HH:mm');
        var nextBtn     = 'Iniciar';
        var nextBtnType = 'start';
        var nextBtnColor = 'btn-info';
        var animation    = '';

        if(ncmKDS.lastChecked && moment(ncmKDS.lastChecked).isBefore(date)){
          animation    = 'fadeInUp';
        }

        if(order.order_status == 3){
          nextBtn       = 'Finalizar';
          nextBtnType   = 'end';
          nextBtnColor  = 'btn-success';
        }

        //duration
        var now  = moment();//now
        var then = moment(order.date);
        var diff = moment.duration( now.diff(then) );

        var elapsed         = Math.round(diff.asMinutes());
        order.elapsedMins   = diff.minutes();
        order.elapsedHours  = diff.hours();

        //bar
        var halfMax       = orderDuration / 2;
        order.background  = 'bg text-white';
        order.barColor    = 'bg-warning';

        if(elapsed > orderDuration){
          order.background  = 'bg-danger lt text-white';
          order.barColor    = 'bg-danger';
        }else if(elapsed > halfMax){
          order.background  = 'bg-warning text-dark';
          order.barColor    = 'bg-danger';
        }

        if(elapsed < halfMax){
          order.barWidth = ncmKDS.getPercent(elapsed,halfMax);
        }else{
          order.barWidth = ncmKDS.getPercent(elapsed - halfMax,orderDuration);
        }       

        if(cols == 1){
          var active = '';
          if(cnt == 0){
            active = 'active';
          }
          block += '<div class="item ' + active + ' speed-4x" style="height: 95vh;">';
          pages += '<li data-target="#ordersList" data-slide-to="' + page + '" class="' + active + '"></li>';
        }

        var orderSource = order.order_name;
        var orderName   = 'Orden';

        if(orderSource == 'ecom'){
          orderName = 'Online';
        }else if($.isNumeric(orderSource)){
          orderName = 'Mesa ' + orderSource;
        }

        order.actionBtn     = nextBtn;
        order.actionBtnType = nextBtnType;
        order.actionBtnColor= nextBtnColor;
        order.source        = orderName;
        order.animation     = animation;

        //if(!skipLine){
          block +=  ncmKDS.render($('#blockTpl'),order,false,true);
        //}

        if(cols == ncmKDS.cardsPerScreen){
          block += '</div>';
          cols = 0;
        } 

        cnt++;
      });

      return [block,pages];
    },
    duplicateJson : function(value){
      return JSON.parse( JSON.stringify( value ) );
    },
    buildCatsList : function(){
      var $catsEl = $('#modalSmall select#allowedCategories');
      var cats    = simpleStorage.get('categories');
      var allowed = simpleStorage.get('allowedCategories');
      if(cats){
        $.each(cats,function(key,value){
          var selected = false;
          if($.inArray(value.ID,allowed) > -1){
            selected = 'selected';
          }
          $catsEl.append($('<option>', {
              value: value.ID,
              text: value.name,
              selected : selected
          }));
        });
      }
    },
    setUIX  : function(data){
      console.log('ui');
      var content = ncmKDS.buildList(data);

      if(ncmHelpers.validate(content[0])){
        $('.carousel-inner').html(content[0]);
        $('.carousel-indicators').html(content[1]);
      }else{
        $('.carousel-control, .carousel-indicators').addClass('hidden');
      }
      
      $(window).scrollTop(ncmKDS.scrollPos);

      if(ncmKDS.slide > 0){
        $('.carousel .item').eq(0).removeClass('active');
        $('.carousel .item').eq(ncmKDS.slide).addClass('active');
      }

      ncmKDS.countOrders();
    },
    resetScreenPos : function(){
      $('.carousel').carousel(0);
    },
    getTags : function(){
      var success   = function(data){
        simpleStorage.set('tags', data);
      };

      $.get('/kds3?s=<?=$_GET['s']?>&action=tags',success);
    },
    getCategories : function(){
      var success   = function(data){
        simpleStorage.set('categories', data);
      };

      $.get('/kds3?s=<?=$_GET['s']?>&action=categories',success);
    },
    startDataLoad : function(){
      clearInterval(ncmKDS.dataLoadInterval);
      ncmKDS.dataLoaders();
      //ncmKDS.dataLoader();
      ncmKDS.timeagoInterval = setInterval(function(){

        if(!ncmKDS.sliding){
          ncmKDS.setUIX(ncmKDS.cachedResult);
        }

      },ncmKDS.updateUIIntval);
    },
    dataLoader : function(){

      var success   = function(data){
        ncmKDS.lastChecked          = moment().format('YYYY-MM-DD HH:mm:ss');
        simpleStorage.set('lastChk',ncmKDS.lastChecked);

        if(data.error == 'time'){
          ncmKDS.canLoad = false;

          alert('Verifique la fecha y hora de su equipo.');

        }else if(data.error == 'timeout'){
          ncmKDS.dataLoader();
        }else if(data.orders){
          ncmKDS.cachedResult     = data;
          ncmKDS.oldCachedResult  = ncmKDS.duplicateJson(data);
          ncmKDS.dataLoader();
        }

        ncmKDS.setUIX(ncmKDS.cachedResult);
        
      };

      var orderOrder = simpleStorage.get('orderOrder') ? 1 : 0;

      if(ncmKDS.isUserActive && ncmKDS.canLoad){
        ncmKDS.loading      = true;
        ncmKDS.computerHour = moment().format('YYYY-MM-DD HH');
        var lasChk          = simpleStorage.get('lastChk');

        ncmKDS.xhr = $.get('/kds3?s=<?=$_GET['s']?>&action=list&time=' + ncmKDS.lastChecked + '&compTime=' + ncmKDS.computerHour + '&reverse=' + orderOrder,success).fail(function (jqXHR) {
           //ncmKDS.dataLoader();
        });

      }else{
        ncmKDS.loading = false;
      }
    },
    dataLoaders : function(){

      var success   = function(data){
        ncmKDS.lastChecked          = moment().format('YYYY-MM-DD HH:mm:ss');

        if(data.orders){
          ncmKDS.cachedResult     = data;
          ncmKDS.oldCachedResult  = ncmKDS.duplicateJson(data);
        }

        ncmKDS.setUIX(ncmKDS.cachedResult);
        ncmKDS.loading = false;
      };

      var orderOrder = simpleStorage.get('orderOrder') ? 1 : 0;

      var loaderXhr =  function(){
        if(ncmKDS.isUserActive && !ncmKDS.loading){
          ncmKDS.loading = true;
          ncmKDS.xhr = $.get('/kds3?s=<?=$_GET['s']?>&action=lists&time=' + ncmKDS.lastChecked + '&reverse=' + orderOrder,success).fail(function (jqXHR) {
            ncmKDS.loading = false;
          });
        }else{
          //ncmKDS.loading = false;
        }
      };

      clearInterval(ncmKDS.dataLoadInterval);
      loaderXhr();

      ncmKDS.dataLoadInterval = setInterval(function(){
        loaderXhr();
      },ncmKDS.getOrdersIntval);
      
    },
    render : function($template,data,$wrap,returns){
      var template    = $template.html();
      var mustached   = Mustache.render(template, data);
      if(returns){
        return mustached;
      }else{
        $wrap.html(mustached); 
      }
    },
    countOrders : function(){
      ncmKDS.waitingOrders    = $('.card').length;

      var waitingOrders       = 0;
      if(ncmKDS.waitingOrders > ncmKDS.cardsPerScreen){
        waitingOrders         = ncmKDS.waitingOrders;//(ncmKDS.waitingOrders - ncmKDS.cardsPerScreen);
      }

      $('#waitingOrders').text('x' + waitingOrders);
    },
    getPercent : function(oldNumber, newNumber){
        return (oldNumber * 100) / newNumber;
    },
    detectIfUserIddle : function(){
      var timeoutID = null;

      $(document).off('mousemove mousedown keypress DOMMouseScroll mousewheel touchmove touchend MSPointerMove').on('mousemove mousedown keypress DOMMouseScroll mousewheel touchmove touchend MSPointerMove',function(){
        if (timeoutID !== null) {
          ncmKDS.isUserActive = true;

          if(!ncmKDS.loading){
            //ncmKDS.dataLoader();
          }

          //console.log('Active');
          //$('.appVersionCachedInHTML').addClass('text-white');
          window.clearTimeout(timeoutID);
        }

        timeoutID = window.setTimeout(function(){
          ncmKDS.isUserActive = false;
          console.log('In Active');
          //$('.appVersionCachedInHTML').removeClass('text-white');
        }, ncmKDS.userIddleTime);
      });
    }
  };

  $(document).ready(function(){

    ncmKDS.init();

  });
</script>
</body>

</html>

<?php
include_once('/home/encom/public_html/panel/includes/freememory.php');
include_once('/home/encom/public_html/panel/includes/compression_end.php');
?>