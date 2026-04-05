<?php
include_once("includes/simple.config.php");
include_once("libraries/hashid.php");
include_once("libraries/countries.php");
include_once("includes/functions.php");

if(!$_GET['s']){
  dai();
}

$source     = base64_decode($_GET['s']);
$sources    = explodes(',',$source);
$companyId  = $sources[0];
$outletId   = $sources[1];
?>

<!DOCTYPE html>
<html class="bg-light dker">
<head>  
  <meta charset="utf-8" />
  <title>CALENDARIO</title>
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
  <link rel="canonical" href="https://app.encom.app" />
  <meta name="application-name" content="Encom"/>
  <meta name="msapplication-TileColor" content="#FFFFFF" />
  <meta name="msapplication-TileImage" content="/mstile-144x144.png" />
  <meta name="msapplication-square70x70logo" content="/mstile-70x70.png" />
  <meta name="msapplication-square150x150logo" content="/mstile-150x150.png" />
  <meta name="msapplication-wide310x150logo" content="/mstile-310x150.png" />
  <meta name="msapplication-square310x310logo" content="/mstile-310x310.png" />

  <link rel="stylesheet" href="/vendor.css" type="text/css" />

</head>

<body class="">
<div id="calendarHolder">
  
</div>

<div id="incomeSpinner" class="rounded animated bounceIn" style="display: none; width: 75px; height: 75px; top:50%; left:50%; margin:-37px 0 0 -37px; position: absolute; z-index:1000; overflow:hidden;">
  <div class="la-ball-clip-rotate-pulse la-dark la-2x">
      <div></div>
      <div></div>
  </div>
</div>

<script type="text/javascript">
  window.dontStart = true;
</script>
<script type="text/javascript" src="/vendor.js"></script> 
<script type="text/javascript">
  $(document).ready(function(){
    var container = $('#calendarHolder');
    window.currentDate = '<?=date("Y-m-d")?>';
    window.settingsObj = [{
                            companyId   : '<?=$companyId?>'
                          }];

    window.activeUser = [{
                          activeUserId  : '1',
                          role          : '1'
                        }];

    window.appConfigObj = [{
                            outletId    : '<?=$outletId?>',
                            registerId  : '1'
                          }];

    standAloneLoadCalendar('calendar_week','calendarView');

    $(document).on('click','.clickeable',function(){
      var tis             = $(this);
      var type            = tis.data('type');
      var load            = tis.data('mode');
      window.currentDate  = tis.data('date');
      var container       = $('#calendarHolder');

      if(type == 'calendarView' || type == 'calendarDateBtn'){
        standAloneLoadCalendar(load,type);
      }
    });

    function standAloneLoadCalendar(load,type){
      var url   = 'https://app.encom.app/load?l=' + masterUrlParams({load:load,date:window.currentDate});
      preloader('show');
      $.get(url,function(result){

        $('#calendarHolder').html(result);
        preloader('hide');
        loadToolTip({html: true},false,'.btnSchedule');

        $('.btnSchedule').hover(function(){
          $(this).parent('.ncmCalendarBlockWrap').css({'z-index':100});
          $(this).addClass('all-shadows');
        },function(){
          $(this).parent('.ncmCalendarBlockWrap').css({'z-index':1});
          $(this).removeClass('all-shadows');
        });

        if(load == 'calendar_week' || load == 'calendar_resources'){
          calendarTimeMarker({container:container,mTop:115}); 
        }
      });
    }
  
  });
</script>

</body>
</html>