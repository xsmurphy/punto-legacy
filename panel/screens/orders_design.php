<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define(COMPANY_ID, dec($data[0]));
define(OUTLET_ID, dec($data[1]));

$setting = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);

date_default_timezone_set(TIMEZONE);

define(TODAY, date('Y-m-d H:i:s'));
?>

<!DOCTYPE html>
<html class="noscroll bg-dark dker">
<head>  
  <meta charset="utf-8" /> 
  <title>ORDENES</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
  
  <link rel="apple-touch-icon-precomposed" sizes="57x57" href="/apple-touch-icon-57x57.png" />
  <link rel="apple-touch-icon-precomposed" sizes="114x114" href="/apple-touch-icon-114x114.png" />
  <link rel="apple-touch-icon-precomposed" sizes="72x72" href="/apple-touch-icon-72x72.png" />
  <link rel="apple-touch-icon-precomposed" sizes="144x144" href="/apple-touch-icon-144x144.png" />
  <link rel="apple-touch-icon-precomposed" sizes="60x60" href="/apple-touch-icon-60x60.png" />
  <link rel="apple-touch-icon-precomposed" sizes="120x120" href="/apple-touch-icon-120x120.png" />
  <link rel="apple-touch-icon-precomposed" sizes="76x76" href="/apple-touch-icon-76x76.png" />
  <link rel="apple-touch-icon-precomposed" sizes="152x152" href="/apple-touch-icon-152x152.png" />
  <link rel="icon" type="image/png" href="/favicon-196x196.png" sizes="196x196" />
  <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/png" href="/favicon-32x32.png" sizes="32x32" />
  <link rel="icon" type="image/png" href="/favicon-16x16.png" sizes="16x16" />
  <link rel="icon" type="image/png" href="/favicon-128.png" sizes="128x128" />
  <link rel="canonical" href="" />
  <link rel="manifest" href="/manifest.json" />
  <meta name="application-name" content=APP_NAME/>
  <meta name="msapplication-TileColor" content="#FFFFFF" />
  <meta name="msapplication-TileImage" content="/mstile-144x144.png" />
  <meta name="msapplication-square70x70logo" content="/mstile-70x70.png" />
  <meta name="msapplication-square150x150logo" content="/mstile-150x150.png" />
  <meta name="msapplication-wide310x150logo" content="/mstile-310x150.png" />
  <meta name="msapplication-square310x310logo" content="/mstile-310x310.png" />

  <?php
  loadCDNFiles([],'css');
  ?>

</head>

<body class="noscroll">

  <section class="col-xs-12 no-padder bg-dark lt fullHeight hidden" id="lockScreen">
    <div class="wrapper text-center" style="position:absolute;top:0;left:0;bottom:0;right:0;margin:auto;width:100%;max-width:330px;height:130px;">
      <img src="/images/iconincomesmwhite.png" alt="Logo" width="60">
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
    
    <div id="myCarousel" class="carousel slide " data-ride="carousel" data-interval="false">
      <!-- Indicators -->
      <ol class="carousel-indicators">
        
      </ol>

      <!-- Wrapper for slides -->
      <div class="carousel-inner">

        <div class="text-center">
          <img src="/images/bg_kds.png" width="100%" id="logoSaleList">
        </div>

        <div class="item active scrollable hidden" style="height: 95vh;">

          <div class="col-xs-3 wrapper">
            <div class="col-xs-12 no-padder r-3x clear">
              <div class="col-xs-12 bg-danger lt wrapper text-md">
                <div class="bg-danger m-t-n m-b-sm" style="height: 4px; width:100%;"></div>

                <div class="pull-right text-right text-white">

                  <div class="font-bold">10:20</div>
                  <div class="">Por: Mesero Jose</div>
                  
                </div>

                <div class="pull-left m-r text-white hidden">
                  <i class="material-icons" style="font-size:3em!important;">shopping_basket</i>
                </div>

                <div class="pull-left text-white">
                  <div class="font-bold text-lg">Llevar</div>
                  <div>#0034</div>
                </div>
              </div>
              <div class="col-xs-12 panel m-n no-padder">
                <div class="col-xs-12 wrapper bg-light">Esperar un poco antes de sacar esta orden</div>
                <table class="table">
                  <tbody>
                    <tr class="font-bold">
                      <td>1</td>
                      <td>SW Jamon y Queso</td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
                    <tr class="text-muted">
                      <td>3</td>
                      <td>Empanadas de 4 quesos</td>
                      <td class="text-center">
                        
                      </td>
                    </tr>
                    <tr class="text-muted">
                      <td>2</td>
                      <td>Jugos de durazno</td>
                      <td class="text-center">
                        
                      </td>
                    </tr>
                    <tr class="font-bold">
                      <td>1</td>
                      <td>
                        <div>SW Jamon y Queso</div>
                        <span class="badge">Sin mayonesa</span> <span class="badge">Queso extra</span> <span class="badge">Palitos</span>
                      </td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
                    <tr class="font-bold">
                      <td>3</td>
                      <td>Empanadas de 4 quesos</td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
                    <tr class="font-bold">
                      <td>2</td>
                      <td>Jugos de durazno</td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
                    <tr class="font-bold">
                      <td>1</td>
                      <td>SW Jamon y Queso</td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
                    <tr class="font-bold">
                      <td>3</td>
                      <td>Empanadas de 4 quesos</td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
                    <tr class="font-bold">
                      <td>2</td>
                      <td>Jugos de durazno</td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="col-xs-12 lter wrapper text-white">

                <div class="pull-left">
                  <div class="h5 font-bold text-success">En Proceso</div>
                  <div><span class="minutes h4">08m</span> <span class="seconds h4">20s</span></div>
                </div>

                <div class="pull-right">
                  <a href="#" class="btn btn-rounded btn-info font-bold text-u-c">Finalizar</a>
                </div>
                  
              </div>
            </div>
          </div>

          <div class="col-xs-3 wrapper">
            <div class="col-xs-12 no-padder r-3x clear">
              <div class="col-xs-12 bg-warning dk wrapper text-md">
                <div class="bg-danger m-t-n m-b-sm" style="height: 4px; width:43%;"></div>

                <div class="pull-right text-right text-white">

                  <div class="font-bold">10:05</div>
                  <div class="">Por: Mesero Jose</div>
                  
                </div>

                <div class="pull-left m-r text-white hidden">
                  <i class="material-icons" style="font-size:3em!important;">shopping_basket</i>
                </div>

                <div class="pull-left text-white">
                  <div class="font-bold text-lg">Barra</div>
                  <div>#0038</div>
                </div>
              </div>
              <div class="col-xs-12 panel m-n no-padder">
                <div class="col-xs-12 wrapper bg-light">Esperar un poco antes de sacar esta orden</div>
                <table class="table">
                  <tbody>
                    <tr class="font-bold">
                      <td>1</td>
                      <td>SW Jamon y Queso</td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
                    
                    <tr class="font-bold">
                      <td>1</td>
                      <td>
                        <div>SW Jamon y Queso</div>
                        <span class="badge">Sin mayonesa</span> <span class="badge">Queso extra</span> <span class="badge">Palitos</span>
                      </td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
  
                  </tbody>
                </table>
              </div>
              <div class="col-xs-12 lter wrapper text-white">

                <div class="pull-left">
                  <div class="h5 font-bold text-success">En Espera</div>
                  <div><span class="minutes h3">02m</span> <span class="seconds h3">10s</span></div>
                </div>

                <div class="pull-right">
                  <a href="#" class="btn btn-rounded btn-info font-bold text-u-c">Finalizar</a>
                </div>
                  
              </div>
            </div>
          </div>

          <div class="col-xs-3 wrapper">
            <div class="col-xs-12 no-padder r-3x clear">
              <div class="col-xs-12 lter wrapper text-md">
                <div class="bg-warning m-t-n m-b-sm" style="height: 4px; width:50%;"></div>

                <div class="pull-right text-right text-white">

                  <div class="font-bold">10:05</div>
                  <div class="">Por: Mesero Jose</div>
                  
                </div>

                <div class="pull-left m-r text-white hidden">
                  <i class="material-icons" style="font-size:3em!important;">shopping_basket</i>
                </div>

                <div class="pull-left text-white">
                  <div class="font-bold text-lg">Delivery</div>
                  <div>#0040</div>
                </div>
              </div>
              <div class="col-xs-12 panel m-n no-padder">
                <div class="col-xs-12 wrapper bg-light hidden">Esperar un poco antes de sacar esta orden</div>
                <table class="table">
                  <tbody>
                    <tr class="font-bold">
                      <td>1</td>
                      <td>
                        <div>SW Jamon y Queso</div>
                        <span class="badge">Sin mayonesa</span> <span class="badge">Queso extra</span> <span class="badge">Palitos</span>
                      </td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>

                    <tr class="font-bold">
                      <td>1</td>
                      <td>SW Jamon y Queso</td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
                    
                    <tr class="font-bold">
                      <td>2</td>
                      <td>
                        <div>Coca cola</div>
                      </td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>

                    <tr class="font-bold">
                      <td>1</td>
                      <td>
                        <div>Agua sin gas</div>
                      </td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
  
                  </tbody>
                </table>
              </div>
              <div class="col-xs-12 lter wrapper text-white">

                <div class="pull-left">
                  <div class="h5 font-bold text-success">En Espera</div>
                  <div><span class="minutes h4">01m</span> <span class="seconds h4">10s</span></div>
                </div>

                <div class="pull-right">
                  <a href="#" class="btn btn-rounded btn-info font-bold text-u-c">Procesar</a>
                </div>
                  
              </div>
            </div>
          </div>

          <div class="col-xs-3 wrapper">
            <div class="col-xs-12 no-padder r-3x clear">
              <div class="col-xs-12 lter wrapper text-md">
                <div class="bg-warning m-t-n m-b-sm" style="height: 4px; width:20%;"></div>

                <div class="pull-right text-right text-white">

                  <div class="font-bold">10:05</div>
                  <div class="">Por: Mesero Jose</div>
                  
                </div>

                <div class="pull-left m-r text-white hidden">
                  <i class="material-icons" style="font-size:3em!important;">shopping_basket</i>
                </div>

                <div class="pull-left text-white">
                  <div class="font-bold text-lg">Mesa 5</div>
                  <div>#0041</div>
                </div>
              </div>
              <div class="col-xs-12 panel m-n no-padder">
                <div class="col-xs-12 wrapper bg-light hidden">Esperar un poco antes de sacar esta orden</div>
                <table class="table">
                  <tbody>
                    
                    <tr class="font-bold">
                      <td>2</td>
                      <td>
                        <div>Coca cola</div>
                      </td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>

                    <tr class="font-bold">
                      <td>1</td>
                      <td>
                        <div>Agua sin gas</div>
                      </td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>

                    <tr class="font-bold">
                      <td>3</td>
                      <td>
                        <div>SW Mixto</div>
                      </td>
                      <td class="text-center">
                        <a href="#"><i class="material-icons text-danger">close</i></a>
                      </td>
                    </tr>
  
                  </tbody>
                </table>
              </div>
              <div class="col-xs-12 lter wrapper text-white">

                <div class="pull-left">
                  <div class="h5 font-bold text-success">En Espera</div>
                  <div><span class="minutes h4">00m</span> <span class="seconds h4">60s</span></div>
                </div>

                <div class="pull-right">
                  <a href="#" class="btn btn-rounded btn-info font-bold text-u-c">Procesar</a>
                </div>
                  
              </div>
            </div>
          </div>

        </div>

        
      </div>

      <!-- Left and right controls -->
      <a class="left carousel-control" href="#myCarousel" data-slide="prev">
        <span class="glyphicon glyphicon-chevron-left"></span>
        <span class="sr-only">Previous</span>
      </a>
      <a class="right carousel-control" href="#myCarousel" data-slide="next">
        <span class="glyphicon glyphicon-chevron-right"></span>
        <span class="sr-only">Next</span>
      </a>
    </div>

    <div class="col-xs-12 wrapper bg" style="position: fixed; bottom:0;">
      <img src="/images/incomeLogoLgGray.png" height="20">
    </div>

  </section>
  <script type="text/javascript">
    var noSessionCheck  = true;
    window.standAlone   = true;
  </script>
  <?php
  loadCDNFiles([
                  '/assets/vendor/js/simpleStorage-0.2.1.min.js',
                  '/assets/vendor/js/mousetrap-1.6.3.min.js'
                ],'js');
  ?>
<script type="text/javascript">
  Mousetrap.bind('left', function() {
      $('.left').trigger('click');
  });

  Mousetrap.bind('right', function() {
      $('.right').trigger('click');
  });
  
  var buildList = function(data,options){

    var out     = [];
    
    var maxPerscreen = 8;
    var cols    = 0;
    var block   = '';
    var page    = 0;
    var pages   = '';
    var cnt     = 0;
    var allTags = simpleStorage.get('tags');

    $.each(data,function(date,info){
      cols++;

      var tiempo  = explodes(' ',date,1);
      var hora    = explodes(':',tiempo,0);
      var min     = explodes(':',tiempo,1);

      var tr      = '';
      //console.log(info);
      $.each(info.order_details,function(key,value){
        var status    = (value.hasOwnProperty("status")) ? value.status : 2;
        var canceled  = '';
        
        if(status == 0){
          canceled = 'text-l-t text-muted';
        }

        if(!value.name){
          return; //si no tiene nombre el item salteo
        }

        tr += '<tr class="font-bold pointer ' + canceled + '" data-id="' + value.itemId + '">' +
              ' <td>' + value.count + '</td>' +
              ' <td>' +
              '   <div>' + value.name + '</div>';
              
              $.each(value.tags,function(tagK,tagId){
                tr += '<span class="badge">' + allTags[tagId].name + '</span>';
              });

        tr += ' </td>' +
              ' <td class="text-right">';

              if(status > 1){
          tr += '   <a href="#"><i class="material-icons text-danger">close</i></a>';
              }

        tr += ' </td>' +

              '</tr>';
           /* 
            <tr class="text-muted">
              <td>3</td>
              <td>Empanadas de 4 quesos</td>
              <td class="text-center">
                
              </td>
            </tr>
            <tr class="text-muted">
              <td>2</td>
              <td>Jugos de durazno</td>
              <td class="text-center">
                
              </td>
            </tr>
            <tr class="font-bold">
              <td>1</td>
              <td>
                <div>SW Jamon y Queso</div>
                <span class="badge">Sin mayonesa</span> <span class="badge">Queso extra</span> <span class="badge">Palitos</span>
              </td>
              <td class="text-center">
                <a href="#"><i class="material-icons text-danger">close</i></a>
              </td>
            </tr>
            </tr>*/
      });

      if(cols == 1){
        var active = '';
        if(cnt == 0){
          active = 'active';
        }
        block += '<div class="item ' + active + ' scrollable" style="height: 95vh;">';
        pages += '<li data-target="#myCarousel" data-slide-to="' + page + '" class="' + active + '"></li>';
      }

      var orderSource = info.order_name;
      var orderName   = 'Orden';

      if(orderSource == 'ecom'){
        orderName = 'Online';
      }else if($.isNumeric(orderSource)){
        orderName = 'Mesa ' + orderSource;
      }

      block +=  '<div class="col-xs-3 wrapper">' +
                ' <div class="col-xs-12 no-padder r-3x clear">' +
                '   <div class="col-xs-12 bg wrapper text-md" id="cardHead' + cnt + '">' +
                '     <div class="bg-warning r-3x m-t-n m-b-sm" style="height: 4px; width:100%;" id="cardElapsedBar' + cnt + '"></div>' +

                '     <div class="pull-right text-right text-white">' +
                '       <div class="font-bold">' + hora + ':' + min + '</div>' +
                '       <div class="text-sm">Por: ' + info.user_name + '</div>' +                          
                '     </div>' +

                '     <div class="pull-left m-r text-white hidden">' +
                '        <i class="material-icons" style="font-size:3em!important;">shopping_basket</i>' +
                '     </div>' +

                '     <div class="pull-left text-white">' +
                '       <div class="font-bold text-lg text-u-c">' + orderName + '</div>' +
                '       <div>#' + info.number_id + '</div>' +
                '     </div>' +
                '   </div>' +

                '   <div class="col-xs-12 panel m-n no-padder text-white lter scrollable" style="max-height:180px; height:180px;">' +
                '     <div class="col-xs-12 wrapper bg-light ' + iftn(info.order_note,'hidden','') + '">' + info.order_note + '</div>' +
                '      <table class="table text-u-c bg-white">' +
                '       <tbody>' +
                          tr +
                '       </tbody>' +
                '    </table>' +
                '   </div>' +
                '   <div class="col-xs-12 lt wrapper text-white">' +

                '     <div class="pull-left">' +
                '       <div class="h5 font-bold text-warning">Pendiente</div>' +
                '       <div class="h4 timeago" data-time="' + date + '" data-index="' + cnt + '"></div>' +
                '     </div>' +

                '     <div class="pull-right">' +
                '       <a href="#" class="btn btn-rounded btn-info font-bold text-u-c">Procesar</a>' +
                '     </div>' +
                        
                '   </div>' +
                '  </div>' +
                '</div>';

      if(cols == maxPerscreen){
        block += '</div>';
        cols = 0;
      } 

      cnt++;
    });

    return [block,pages];
  };

  var setElapsedTime = function(maxWaiting){
    $('.timeago').each(function(){
      var tis     = $(this);
      var times   = tis.data('time');
      var index   = tis.data('index');
      var halMax  = maxWaiting / 2;
      
      //counter
      var ago   = timeAgo(times);
      tis.text(ago);

      //Head color
      var startDate = new Date(times).getTime();
      var today     = new Date().getTime();
      var elapsed   = today - startDate;
      var statusBg  = 'bg';

      if(elapsed > maxWaiting){
        statusBg = 'bg-danger lt';
      }else if(elapsed > halMax){
        statusBg = 'bg-warning';
      }

      $('#cardHead' + index).removeClass('bg bg-danger lt bg-warning').addClass(statusBg);

      //bar
      $('#cardElapsedBar' + index).removeClass('bg-danger lt bg');

      var barWidth = (elapsed * 100) / halMax;
      $('#cardElapsedBar' + index).addClass('bg-warning lt');

      if(barWidth > 100){
        barWidth = (elapsed * 100) / maxWaiting;
        $('#cardElapsedBar' + index).addClass('bg-danger lt');
      }

      $('#cardElapsedBar' + index).width(barWidth + '%');

    });
  };

  var getAllTags = function(){
    var vars      = {
                      company_id  : '<?=enc(COMPANY_ID)?>',
                      api_key     : 'cc58c3ead1b111d48f5c0d677765f362e2a55598'
                    };

    var success   = function(data){
      simpleStorage.set('tags', data);
    };

    postIt(API_URL . '/get_tags',vars,success);
  };

  var dataLoader = function(){
    var ordersUrl = API_URL . '/get_orders';
    var vars      = {
                      company_id  : '<?=enc(COMPANY_ID)?>',
                      api_key     : 'cc58c3ead1b111d48f5c0d677765f362e2a55598',
                      limit       : 20,
                      from        : '<?=date('Y-m-1 00:00:00')?>',
                      to          : '<?=date('Y-m-d 23:59:59')?>',
                      order       : 'DESC'
                    };

    var fail      = function(){
      alert('No data');
    };

    var success   = function(data){
      if(data['error']){
        $('.carousel-control, .carousel-indicators').addClass('hidden');
      }else{
        var content = buildList(data);
        $('.carousel-inner').html(content[0]);
        $('.carousel-indicators').html(content[1]);
        setElapsedTime(500000);
        var timeagoInterval = setInterval(function(){
          setElapsedTime(500000);
        },5000);  
      }
    };

    postIt(ordersUrl,vars,success,fail);
  };

  $(document).ready(function(){
    var h = $(window).height();
    $('.fullHeight').css({'height':h + 'px'});
    getAllTags();
    //$('#lockpass').focus();
    
    dataLoader();
    var loadDatax = setInterval(function(){
      console.log('loading data..');
      //dataLoader();
    },10000); 

  });
</script>
</body>

</html>