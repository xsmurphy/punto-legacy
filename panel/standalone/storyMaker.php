<?php
$nombre     = $_GET['name'];
$country    = $_GET['country'];
$color      = $_GET['color'] ? $_GET['color'] : 'gradBgBlue';
$catName    = $_GET['catname'];

$type       = $_GET['type'];
$width      = 56.25;
$height     = 100;//1920
$nlng       = strlen($nombre);
$fnt        = 4.3;

if($nlng < 11){
  $fnt        = 5.5;
}

if($nlng < 6){
  $fnt        = 7;
}

if($type == 'post'){
  $width = 500;
  $height = 500;

  $mTopTitle  = 20; 
  if($nlng > 16){
    $mTopTitle  = 20;
  }

  if($nlng > 32){
    $mTopTitle  = 10;
  }
}else if($type == 'story'){
  $mTopTitle  = 50; 
  if($nlng > 16){
    $mTopTitle  = 40;
  }

  if($nlng > 32){
    $mTopTitle  = 30;
  }
}

if($country == 'Spain'){
  $country = 'España';
}


?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title><?=$nombre?></title>

  <link rel="stylesheet" href="https://app.encom.app/vendor.css?1.1.4.8" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Kaushan+Script&display=swap" rel="stylesheet">
  <style type="text/css">
    html, body { height: 100%; width: 100%; margin: 0; };
    /*div { height: 100%; width: 100%; background: #F52887; }*/
  </style>
</head>
<body class="no-padder clear">

<!--1080 x 1920-->
<div class="col-xs-12 no-padder <?=$color?> text-center" style="width:<?=$width?>px!important;height:<?=$height?>px!important;">
  <div class="col-xs-12 wrapper fullHeight">
    <div class="halfHeight b b-white b-4x col-xs-12 wrapper-md r-3x text-center">

      <div class="twilio-banner animated fadeIn" style="position: absolute; left: 0px; top: 0px;">
        <div class="twilio-banner-squares">
          <svg xmlns="http://www.w3.org/2000/svg" width="130%" height="130" viewBox="0 0 376 75">
            <g fill="#FFFFFE" fill-rule="evenodd">
              <path d="M220 82h12V70h-12z" opacity=".242"></path>
              <path d="M278 6h12V-6h-12z" opacity=".1"></path>
              <path d="M179 10h12V-2h-12z" opacity=".05"></path>
              <path d="M139 79h12V67h-12zM129 10h12V-2h-12z" opacity=".1"></path>
              <path d="M179 59h10V49h-10z" opacity=".25"></path>
              <path d="M25 58h10V48H25z" opacity=".14"></path>
              <path d="M53 41h12V29H53z" opacity=".07"></path>
              <path d="M93 38h12V26H93zM69 79h12V67H69z" opacity=".15"></path>
              <path d="M30 6h12V-6H30z" opacity=".3"></path>
              <path d="M13 32h12V20H13z" opacity=".12"></path>
              <path d="M-3 79h9v-9h-9z" opacity=".26"></path>
              <path d="M338 46h6v-6h-6zM282 67h8v-8h-8zM151 36h8v-8h-8z" opacity=".2"></path>
              <path d="M360 64h4v-4h-4zM314 37h4v-4h-4z" opacity=".1"></path>
              <path d="M372 20h4v-4h-4zM316 69h4v-4h-4z" opacity=".2"></path>
              <path d="M210 27h8v-8h-8zM257 37h8v-8h-8zM292 25h6v-6h-6zM125 58h10V48h-10z" opacity=".095"></path>
            </g>
          </svg>
        </div><div class="twilio-banner-circles">
          <svg xmlns="http://www.w3.org/2000/svg" width="120%" height="120" viewBox="0 0 184 63">
            <g fill="#FFF" fill-rule="evenodd">
              <circle cx="168" cy="4" r="4" opacity=".1"></circle>
              <circle cx="55" cy="14" r="2" opacity=".08"></circle>
              <circle cx="182" cy="58" r="2" opacity=".08"></circle>
              <circle cx="140" cy="60" r="2" opacity=".14"></circle>
              <circle cx="2" cy="54" r="2" opacity=".11"></circle>
              <circle cx="161" cy="34" r="6" opacity=".1"></circle>
              <circle cx="134" cy="16" r="4" opacity=".1"></circle>
              <circle cx="81.5" cy="31.5" r="3.5" opacity=".1"></circle>
              <circle cx="41.5" cy="40.5" r="2.5" opacity=".19"></circle>
              <circle cx="7.5" cy="7.5" r="1.5" opacity=".1"></circle>
              <circle cx="103.5" cy="46.5" r="1.5" opacity=".1"></circle>
              <circle cx="64.5" cy="61.5" r="1.5" opacity=".1"></circle>
              <circle cx="131" cy="43" r="3" opacity=".2"></circle>
              <circle cx="96.5" cy="3.5" r="2.5" opacity=".2"></circle>
            </g>
          </svg>
        </div><div class="twilio-banner-squares">
          <svg xmlns="http://www.w3.org/2000/svg" width="100%" height="150" viewBox="0 0 376 75">
            <g fill="#FFFFFE" fill-rule="evenodd">
              <path d="M220 82h12V70h-12z" opacity=".242"></path>
              <path d="M278 6h12V-6h-12z" opacity=".1"></path>
              <path d="M179 10h12V-2h-12z" opacity=".05"></path>
              <path d="M139 79h12V67h-12zM129 10h12V-2h-12z" opacity=".1"></path>
              <path d="M179 59h10V49h-10z" opacity=".25"></path>
              <path d="M25 58h10V48H25z" opacity=".14"></path>
              <path d="M53 41h12V29H53z" opacity=".07"></path>
              <path d="M93 38h12V26H93zM69 79h12V67H69z" opacity=".15"></path>
              <path d="M30 6h12V-6H30z" opacity=".3"></path>
              <path d="M13 32h12V20H13z" opacity=".12"></path>
              <path d="M-3 79h9v-9h-9z" opacity=".26"></path>
              <path d="M338 46h6v-6h-6zM282 67h8v-8h-8zM151 36h8v-8h-8z" opacity=".2"></path>
              <path d="M360 64h4v-4h-4zM314 37h4v-4h-4z" opacity=".1"></path>
              <path d="M372 20h4v-4h-4zM316 69h4v-4h-4z" opacity=".2"></path>
              <path d="M210 27h8v-8h-8zM257 37h8v-8h-8zM292 25h6v-6h-6zM125 58h10V48h-10z" opacity=".095"></path>
            </g>
          </svg>
        </div>
        
        

        <div class="twilio-banner-circles">
          <svg xmlns="http://www.w3.org/2000/svg" width="110%" height="100" viewBox="0 0 184 63">
            <g fill="#FFF" fill-rule="evenodd">
              <circle cx="168" cy="4" r="4" opacity=".1"></circle>
              <circle cx="55" cy="14" r="2" opacity=".08"></circle>
              <circle cx="182" cy="58" r="2" opacity=".08"></circle>
              <circle cx="140" cy="60" r="2" opacity=".14"></circle>
              <circle cx="2" cy="54" r="2" opacity=".11"></circle>
              <circle cx="161" cy="34" r="6" opacity=".1"></circle>
              <circle cx="134" cy="16" r="4" opacity=".1"></circle>
              <circle cx="81.5" cy="31.5" r="3.5" opacity=".1"></circle>
              <circle cx="41.5" cy="40.5" r="2.5" opacity=".19"></circle>
              <circle cx="7.5" cy="7.5" r="1.5" opacity=".1"></circle>
              <circle cx="103.5" cy="46.5" r="1.5" opacity=".1"></circle>
              <circle cx="64.5" cy="61.5" r="1.5" opacity=".1"></circle>
              <circle cx="131" cy="43" r="3" opacity=".2"></circle>
              <circle cx="96.5" cy="3.5" r="2.5" opacity=".2"></circle>
            </g>
          </svg>
        </div>
      </div>
      
      <div style="height:70%;" class="col-xs-12 no-padder clear">

        <div class="col-xs-12 no-padder" style="margin-top: <?=$mTopTitle?>%;">
          <div class="col-xs-12 no-padder text-md m-b-sm">
            Desde <?=$country?>
          </div>
          
          <div class="col-xs-12 no-padder text-u-c font-bold" style="font-size:<?=$fnt?>em; line-height:65px;">
            <?=$nombre?>
          </div>

          <div class="col-xs-12 no-padder text-md m-t-sm">
            <i>"<?=$catName?>"</i>
          </div>
        </div>

      </div>

      <div class="col-xs-12 no-padder" style="height:30%;">
        <img src="https://app.encom.app/images/iconincomesmwhite.png" width="40" class="m-b-xs">
        <div class="h1" style="font-family: 'Kaushan Script', cursive;">
          ¡Bienvenidos!
        </div>
        <div class="m-t-xs font-bold">
          Ahora utilizan @encom.app <br> para hacer crecer su empresa
        </div>
      </div>

    </div>
  </div>

</div>

<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<!--<script type="text/javascript" src="https://app.encom.app/vendor.js?1.1.4.8"></script>-->
<script>
    $(document).ready(function(){
      var h = $(window).height();

      $('.fullHeight').css('height',<?=$height;?> + 'px');
      $('.halfHeight').css('height',(<?=$height;?> - 30) + 'px');

      var start = 1000;

      console.log('will start in ' + start);

      setTimeout(function(){
        console.log('showing first');
        $('.fadeIn').show();
      },start);

      start = start + 700;
      
      setTimeout(function(){
        console.log('showing first');
        $('.bounceIn').show();
      },start);

      start = start + 700;
      setTimeout(function(){
        console.log('showing second');
        $('.fadeInDown').show();
      },start);

      start = start + 500;
      setTimeout(function(){
        console.log('showing thirth');
        $('.fadeInUp').show();
      },start);
      
    });
  </script>

</body>
</html>
