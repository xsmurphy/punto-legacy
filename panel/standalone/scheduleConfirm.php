<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[1]));
define('TRANS_ID', dec($data[0]));

$setting = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);

$apiKey = getAPICreds(COMPANY_ID);
define('API_KEY', $apiKey);

date_default_timezone_set(TIMEZONE);

if(validateHttp('confirm') && validateHttp('id')){

  $record = [];
  if(validateHttp('confirm') == 'confirm'){
    
    $record['transactionStatus'] = 1;
    $title  = 'Confirmación de Cita';
    $msg    = validateHttp('name') . ' confirmó su asistencia para el ' . validateHttp('date');
    $id     = dec( validateHttp('id') );
    $update = $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = '.$id.' AND companyId = '.COMPANY_ID);

    if($update !== false){
      $ops = [
        "title"     => $title,
        "message"   => $msg,
        "type"      => 1,
        "link"      => $url,
        "date"      => date("Y-m-d H:i:s"),
        "outlet"    => dec(validateHttp('outlet')),
        "register"  => 1,
        "company"   => COMPANY_ID,
        "push"      => [
                        "tags" => [
                                    [
                                        "key"   => "outletId",
                                        "value" => validateHttp('outlet')
                                    ],
                                    [
                                        "key"   => "isResource",
                                        "value" => "false"
                                    ]],
                        "where"     => 'caja'
                        ]
        
      ];

      insertNotifications($ops);

      dai('true');
    }else{
      dai('false');
    }
  }else if(validateHttp('confirm') == 'cancel'){
    $title  = 'Cancelación de Cita';
    $msg    =  validateHttp('name') . ' solicita la cancelación de su asistencia para el ' . validateHttp('date');
    $ops = [
        "title"     => $title,
        "message"   => $msg,
        "type"      => 1,
        "link"      => $url,
        "date"      => date("Y-m-d H:i:s"),
        "outlet"    => dec(validateHttp('outlet')),
        "register"  => 1,
        "company"   => COMPANY_ID
      ];

      insertNotifications($ops);
  }else{
    dai('false');
  }
}

$allOk              = false;
$trans              = ncmExecute("SELECT * FROM transaction WHERE transactionId = ? AND transactionType = 13 AND companyId = ? LIMIT 1",[TRANS_ID,COMPANY_ID]);

if($trans){
  $customerData     = getCustomerData($trans['customerId'], 'uid');
  $customerName     = getCustomerName($customerData,'first');
  $customerFullname = getCustomerName($customerData);

  $outName          = getValue('outlet','outletName','WHERE outletId = '.$trans['outletId'] . ' LIMIT 1');
  $outAddress       = getValue('outlet','outletAddress','WHERE outletId = '.$trans['outletId'] . ' LIMIT 1');

  $date             = explodes(' ',$trans['fromDate'],false,0);
  $hour             = explodes(' ',$trans['fromDate'],false,1);
  $hour             = explodes(':',$hour);
  $hour             = $hour[0] . ':' . $hour[1];

  $details          = json_decode($trans['transactionDetails'], true);
  $detailsTxt       = [];

  if(validity($details)){
    foreach ($details as $key => $detail) {
      $detailsTxt[] = $detail['name'];
    }
  }

  $allOk            = true;

  define('WHATSAPP', getValue('outlet','outletWhatsApp','WHERE outletId = ' . $trans['outletId']));

  if(WHATSAPP){
    $walink = 'https://wa.me/' . WHATSAPP . '?text=Hola, solicito la cancelación de mi asistencia para el ' . niceDate($date) . ' a nombre de ' . $customerFullname . ', en ' . COMPANY_NAME . ' (' . $outName . ')';
    
    $cancelSchedule = '<a href="' . $walink . '" class="text-sm" id="cancel" data-id="' . enc(TRANS_ID) . '" data-date="' . niceDate($date) . '" data-name="' . $customerName . '"><span class="text-danger">Cancelar</span></a>';
    
  
  }
          
}

?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Detalles de Asistencia</title>
  <meta property="og:title" content="<?=COMPANY_NAME;?>" />
  <meta property="og:description" content="Detalle del agendamiento" />
  <meta property="og:image" content="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
  
  <?php
  loadCDNFiles([],'css');
  ?>
  <style type="text/css">
    .svg {
            cursor: pointer;
            filter: invert(.3) sepia(1) saturate(1) hue-rotate(175deg);
          }
  </style>
</head>
<body class="bg-light lter col-xs-12 no-padder clear">

<div class="col-lg-4 col-md-3 col-sm-2 no-padder hidden-xs"></div>
<div class="col-lg-4 col-md-6 col-sm-8 wrapper m-t text-center">
  <div class="m-b-lg">
    <a href="#" class="thumb-md animated speed-3x fadeInDown"> 
      <img src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
    </a>
  </div>
  <div id="giftCard" class="col-xs-12 no-padder text-left all-shadows r-24x clear animated speed-3x fadeInUp bg-white" style="display: none;">
    <?php
    if($trans['transactionStatus'] < 1 && $allOk){
    ?>
    <div class="col-xs-12 text-center m-b wrapper text-md">
      <div class="col-xs-12 text-lg text-dark m-t">Hola <?=$customerName?>, <br> estos son los detalles de su agendamiento.</div>

      <?php
      if($detailsTxt){
        ?>
        <div class="col-xs-12">
          <strong>Detalle:</strong> <?= implode(' | ', $detailsTxt);?>
        </div>
        <?php
      }
      ?>
      
      <div class="col-xs-12 wrapper-sm m-t-md text-md font-bold">El <?=niceDate($date)?></div> 
      <div class="col-xs-12 m-b-md">
        <div class="col-sm-3"></div>
        <div class="col-sm-6 h1 font-bold text-dark wrapper-sm rounded bg-light lter">
          <i class="material-icons text-muted md-36 m-r-sm">access_time</i>
          <?=$hour?>
        </div>
        <div class="col-sm-3"></div>
      </div>

      <div class="col-xs-12 text-xs text-u-c">
        <?=COMPANY_NAME?> <span class="text-muted"><?=$outName?></span> <div class="text-xs"><?=iftn($outAddress);?></div>
      </div>

      <div class="col-xs-12 wrapper text-center m-t-md">
        <div class="col-xs-12">
          <a href="#" class="btn btn-info btn-lg text-u-c font-bold rounded all-shadows" id="confirm" data-id="<?=enc(TRANS_ID)?>" data-date="<?=niceDate($date)?>" data-name="<?=$customerName?>">Confirmar</a>
        </div>
        <div class="col-xs-12 m-t-sm">
          <?=$cancelSchedule;?>
        </div>
      </div>

      <div class="col-xs-12">
        <div class="text-sm m-b-xs font-bold text-u-c"> 
          Añadir a mi calendario
        </div>
        <?php
        $location     = iftn($outAddress);
        $title        = 'Cita con ' . COMPANY_NAME;
        $description  = 'El ' . niceDate($date) . ' a las ' . $hour;
        $from         = date("Ymd\THis",strtotime($trans['fromDate']));
        $to           = date("Ymd\THis",strtotime($trans['toDate']));
        $page         = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        ?>

        <p id="social-buttons"> 
          <a href="https://www.google.com/calendar/render?action=TEMPLATE&amp;text=<?=$title?>&amp;dates=<?=$from;?>/<?=$to;?>&amp;details=<?=$description?>&amp;location=<?=$location?>&amp;sprop=&amp;sprop=name:" class="btn m-b-xs text-u-c font-bold btn-sm btn-default btn-rounded">
            Google
          </a> 

          <a href="data:text/calendar;charset=utf8,BEGIN:VCALENDAR%0AVERSION:2.0%0ABEGIN:VEVENT%0AURL:<?=$page;?>%0ADTSTART:<?=$from?>%0ADTEND:<?=$to?>%0ASUMMARY:<?=$title?>%0ADESCRIPTION:<?=$description?>%0ALOCATION:<?=$location?>%0AEND:VEVENT%0AEND:VCALENDAR" class="btn m-b-xs text-u-c font-bold btn-sm btn-default btn-rounded">
            Apple/Windows
          </a>
        </p>
      </div>


      <div class="text-center col-xs-12 wrapper lter r-24x">
        <?php
        $social     = json_decode($setting['settingSocialMedia'],true);

        $utm        = '?utm_source=ENCOM_schedule_confirm&utm_medium=ENCOM_footer_icons&utm_campaign=ENCOM_social_media_marketing';

        $facebook   = 'https://facebook.com/' . str_replace('@','',$social['facebook']) . $utm;
        $instagram  = 'https://instagram.com/' . str_replace('@','',$social['instagram']) . $utm;
        $youtube    = 'https://youtube.com/' . str_replace('@','',$social['youtube']) . $utm;
        $twitter    = 'https://twitter.com/' . str_replace('@','',$social['twitter']) . $utm;
        $whatsapp   = 'https://wa.me/' . WHATSAPP;
        ?>
        <?php
        if($social['facebook']){
        ?>
          <a href="<?=$facebook;?>"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/facebook.svg" class="svg" width="20"></a>
        <?php
        }
        if($social['instagram']){
        ?>
          <a href="<?=$instagram;?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/instagram.svg" class="svg" width="20"></a>
        <?php
        }
        if($social['youtube']){
        ?>
          <a href="<?=$youtube;?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/youtube.svg" class="svg" width="20"></a>
        <?php
        }
        if($social['twitter']){
        ?>
          <a href="<?=$twitter;?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/twitter.svg" class="svg" width="20"></a>
        <?php
        }
        if(WHATSAPP){
        ?>
          <a href="<?=$whatsapp;?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/whatsapp.svg" class="svg" width="20"></a>
        <?php
        }
        ?>
      </div>

    </div>   
    <?php
    }else if($trans['transactionStatus'] == 1 && $allOk){
      echo '<div class="wrapper-lg h2 text-center">' .
            ' <i class="material-icons md-48 text-success m-b">check</i>' .
            ' <div class="font-bold">Su asistencia fue confirmada</div>' .
            ' <div class="text-lg font-normal m-t">El '.niceDate($trans['fromDate'],true).' en '.COMPANY_NAME.' ('.$outName.') <div class="text-xs">'.$outAddress.'</div> </div>' .
            ' <div class="col-xs-12 m-t-sm m-b"> ' . $cancelSchedule . '</div>' .
          '</div>';

      ?>
      <div class="col-xs-12 text-center">
        <div class="text-sm m-b-xs font-bold text-u-c"> 
          Añadir a mi calendario
        </div>
        <?php
        $location     = iftn($outAddress);
        $title        = 'Cita con ' . COMPANY_NAME;
        $description  = 'El ' . niceDate($date) . ' a las ' . $hour;
        $from         = date("Ymd\THis",strtotime($trans['fromDate']));
        $to           = date("Ymd\THis",strtotime($trans['toDate']));
        $page         = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        ?>

        <p id="social-buttons"> 
          <a href="https://www.google.com/calendar/render?action=TEMPLATE&amp;text=<?=$title?>&amp;dates=<?=$from;?>/<?=$to;?>&amp;details=<?=$description?>&amp;location=<?=$location?>&amp;sprop=&amp;sprop=name:" class="btn m-b-xs text-u-c font-bold btn-sm btn-default btn-rounded">
            Google
          </a> 

          <a href="data:text/calendar;charset=utf8,BEGIN:VCALENDAR%0AVERSION:2.0%0ABEGIN:VEVENT%0AURL:<?=$page;?>%0ADTSTART:<?=$from?>%0ADTEND:<?=$to?>%0ASUMMARY:<?=$title?>%0ADESCRIPTION:<?=$description?>%0ALOCATION:<?=$location?>%0AEND:VEVENT%0AEND:VCALENDAR" class="btn m-b-xs text-u-c font-bold btn-sm btn-default btn-rounded">
            Apple/Windows
          </a>
        </p>
      </div>
      <?php
    }else if($trans['transactionStatus'] == 4 && $allOk){
      echo '<div class="wrapper-lg h2 text-center">' .
            ' <i class="material-icons md-48 text-muted m-b">block</i>' .
            ' <div class="font-bold">Su cita fue cancelada</div>' .
          '</div>';
    }else{
      echo '<div class="wrapper-lg h2 text-center">' .
            ' <i class="material-icons md-48 text-muted m-b">block</i>' .
            ' <div class="font-bold">Esta cita no existe</div>' .
          '</div>';
    }
    ?>
    
  </div>
  <div class="col-xs-12 wrapper text-muted text-center text-sm animated speed-3x bounceInUp" id="policy" style="display:none;">
    <a href="https://encom.app?utm_source=ENCOM_schedule_confirm&utm_medium=ENCOM_footer_icon&
utm_campaign=<?=COMPANY_NAME?>" class="m-t-md block">
      <span class="text-muted">Usamos</span> <br>
      <img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
    </a>

  </div>

</div>
<div class="col-lg-4 col-md-3 col-sm-2 no-padder hidden-xs"></div>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>

<?php
loadCDNFiles([],'js');
?>

<script>
    $(document).ready(function(){

      onClickWrap('#confirm',function(event,tis){
        var id    = tis.data('id');
        var name  = tis.data('name');
        var date  = tis.data('date');
        $.get('?s=<?=$_GET['s']?>&confirm=confirm&id=' + id + '&name=' + name + '&date=' + date + '&outlet=<?=enc($trans['outletId'])?>',function(result){
          if(result == 'true'){
            location.reload();
          }else{
            alert('Error al procesar, por favor contáctenos');
          }
        });
      });
      
      setTimeout(function(){
        $('#giftCard').show();
      },200);

      setTimeout(function(){
        $('#policy').show();
      },350);
      
    });
  </script>

</body>
</html>
<?php
include_once('../includes/compression_end.php');
dai();
?>
