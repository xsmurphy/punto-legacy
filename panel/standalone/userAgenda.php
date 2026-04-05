<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));
define('USR_ID', dec($data[1])); //debo usar USR_ID y no USER porque sino abre INTERCOM

//print_r($data); 
//echo USR_ID;

$setting = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$_company = ncmExecute("SELECT accountId FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('API_KEY', sha1($_company['accountId']) );
define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);

setTimeZone(COMPANY_ID,$setting);

define('TODAY', date('Y-m-d H:i:s'));

if(validateHttp('action') == 'load' && validateHttp('from')){

  $data = [
            'api_key'       => API_KEY,
            'company_id'    => enc(COMPANY_ID),
            'type'          => 13,
            'scheduledat'   => validateHttp('from') . ' 00:00:00',
            'scheduledtill' => validateHttp('from') . ' 23:59:59',
            'customerdata'  => 1,
            'user'          => enc(USR_ID),
            'status'        => '0,1,2,3,4,6,7',
            'order'         => 'ASC',
            'test'          => validateHttp('test')
          ];

  $result = json_decode(curlContents('https://api.encom.app/get_orders.php','POST',$data));

  if($result){
    header('Content-Type: application/json;');
    $data = ['data' => $result];
    echo json_encode($data);
  }

  dai();
}

define('USER_NAME', getValue('contact','contactName','WHERE contactId = ' . USR_ID));

$data = [
            'api_key'       => API_KEY,
            'company_id'    => enc(COMPANY_ID),
            'children'      => 1,
            'nolimit'       => true
          ];

$itms = curlContents('https://api.encom.app/get_items.php','POST',$data);
?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title><?=USER_NAME?> Agenda</title>
  <meta property="og:title" content="Agenda de <?=USER_NAME?>" />
  <meta property="og:image" content="https://assets.incomepos.com/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
  <?php
  loadCDNFiles([],'css');
  ?>
</head>
<body class="bg-light lter">
  <section class="col-xs-12 no-padder momentumit" id="content">
  
    <div class="wrapper text-center col-md-4 col-md-offset-4 col-sm-6 col-sm-offset-3 col-xs-12">
      <img height="40" width="40" src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle m-r m-b animated bounceIn" id="logo" />
      <span class="h3 m-t-xs m-b-md font-bold"><?=COMPANY_NAME;?></span>
      
      <div class="animated fadeInUp speed-3x r-24x md-whiteframe-16dp col-xs-12 no-padder clear">
        <div class="panel no-border col-xs-12 no-padder m-n text-left">
          <div class="col-xs-12 wrapper font-bold h3 text-left text-dark m-b-md bg-light lter">
            <div class="col-xs-3 no-padder text-left">
              <a href="#" class="btn wrapper prevBtn dk disabled"> 
                <i class="material-icons">keyboard_arrow_left</i>
              </a>
            </div>
            <div class="col-xs-6 no-padder text-center">
              <a href="#" class="btn wrapper dateName"><?=placeHolderLoader();?></a>
            </div>
            <div class="col-xs-3 no-padder text-right">
              <a href="#" class="btn wrapper nextBtn dk disabled"> 
                <i class="material-icons">keyboard_arrow_right</i>
              </a>
            </div>
          </div>

          <ul class="list-group list-group-lg no-bg auto m-b-lg col-xs-12 no-padder" id="scheduleList"></ul>

        </div>
      </div>
    </div>

    <div class="col-xs-12">
      <a href="https://encom.app?utm_source=ENCOM_user_item_sold&utm_medium=ENCOM_footer_icon&
    utm_campaign=<?=COMPANY_NAME?>" class="m-t-lg m-b-lg text-center block hidden-print">
        <div class="text-muted">Usamos</div>
        <img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
      </a>
    </div>

  </section>

  <div class="hidden placeholders">
    <a href="#" class="list-group-item clearfix bg b-b b-light ">
      <span class="pull-right m-t-sm m-l text-md"> <?=placeHolderLoader(false,2);?> </span>
      <span class="pull-left m-r h4 font-bold m-t-sm">
        <?=placeHolderLoader(false,5);?>
      </span>  
      <div class="clear">   
        <span class="block text-ellipsis text-md"><?=placeHolderLoader(false,30);?></span> 
        <small class="text-muted text-ellipsis"><?=placeHolderLoader(false,80);?></small>   
      </div> 
    </a>

    <a href="#" class="list-group-item clearfix bg b-b b-light ">
      <span class="pull-right m-t-sm m-l text-md"> <?=placeHolderLoader(false,2);?> </span>
      <span class="pull-left m-r h4 font-bold m-t-sm">
        <?=placeHolderLoader(false,5);?>
      </span>  
      <div class="clear">   
        <span class="block text-ellipsis text-md"><?=placeHolderLoader(false,20);?></span> 
        <small class="text-muted text-ellipsis"><?=placeHolderLoader(false,60);?></small>   
      </div> 
    </a>

    <a href="#" class="list-group-item clearfix bg b-b b-light ">
      <span class="pull-right m-t-sm m-l text-md"> <?=placeHolderLoader(false,2);?> </span>
      <span class="pull-left m-r h4 font-bold m-t-sm">
        <?=placeHolderLoader(false,5);?>
      </span>  
      <div class="clear">   
        <span class="block text-ellipsis text-md"><?=placeHolderLoader(false,15);?></span> 
        <small class="text-muted text-ellipsis"><?=placeHolderLoader(false,50);?></small>   
      </div> 
    </a>
  </div>

  

  <script type="text/html" id="noDataTpl">
    <?=noDataMessage('Nada en la agenda','No tiene reservas para esta fecha','https://assets.encom.app/images/emptystate7.png')?>
  </script>

  <script type="text/javascript">
    var noSessionCheck  = true;
    window.standAlone   = true;
  </script>
  <?php
  loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/moment.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/locale/es.js'
],'js');
  ?>
  <script>
    $(document).ready(function(){
      ncmUI.setDarkMode.auto();
      
      var ese   = "<?=$_GET['s'];?>";
      var itms  = <?=iftn($itms,"''")?>;
      //postIt('?action=load',vars,success,fail);
      var getAgendaList = {
        cache       : [],
        hoy         : moment(),
        currentDate : moment(),
        nothingAgenda : $('#noDataTpl').html(),
        buildList   : function(options){
          var data  = options.data,
          items     = options.items,
          list      = '',customerName,details,index,startH,now,isNow;
          
          $.each(data,function(i,val){
            details       = [];
            customerName  = (val.customer_name) ? val.customer_name : 'Sin Cliente';
            startH        = val.from_hour;
            isNow         = '';
            blocked       = '';
            
            if(val.order_status == 4){
              blocked = 'text-l-t';
            }else if(val.order_status == 7){
              customerName = 'Bloqueado';
            }

            //order details
            $.each(val.order_details,function(v,det){
              index = arraySearch(items, 'ID', det.itemId);
              if(index && validity(items[index],false,true,name)){
                details.push('● ' + items[index].name);
              }else if(det.name){
                details.push('● ' + det.name);
              }
            });

            details = (details.length < 1) ? ['Sin información'] : details;
            
            //get vcurrent time
            now = parseInt(moment().format("HH")) + 1;
            current = parseInt(startH.substring(0, startH.length-3));
            
            if(now == current){
              isNow = 'text-info';
            }
            
            list += '<a href="#" class="list-group-item clearfix">' +
                    ' <span class="pull-right m-t-sm m-l text-md">' +
                    '   <i class="material-icons text-muted">' + val.icon + '</i>' +
                    ' </span>' +
                    ' <span class="pull-left m-r h4 font-bold m-t-sm ' + isNow + ' ' + blocked + '">' +
                    startH +
                    ' </span> ' +
                    ' <div class="clear">' +
                    '   <span class="block text-ellipsis text-md">' + customerName + '</span>' +
                    '   <small class="text-muted text-ellipsis">' +
                          details.join('<br>') +
                    '   </small> ' +
                    ' </div> ' +
                    '</a>';
          });
          
          return list;

        },
        buildHead : function(options){
          $('.userName').html(options.userName);
          //datename
          var dateTitle = getAgendaList.currentDate.format('dddd, DD MMM YYYY')
          $('.dateName').html(dateTitle);
        },
        arrows : function(block){
          $prev = $('.prevBtn');
          $next = $('.nextBtn');

          if(block){
            $('.nextBtn,.prevBtn').addClass('disabled').attr('disabled');
          }else{
            if(getAgendaList.currentDate.isSame(getAgendaList.hoy, 'day')){
              //$prev.addClass('disabled').attr('disabled');
              $prev.removeClass('disabled').removeAttr('disabled');
              $next.removeClass('disabled').removeAttr('disabled');
            }else{
              $prev.removeClass('disabled').removeAttr('disabled');
              $next.removeClass('disabled').removeAttr('disabled');
            }
          }
        },
        events : function(options){
          onClickWrap('.nextBtn,.prevBtn', function(event,tis){
            if(tis.hasClass('disabled')){
              //return false;
            }

            getAgendaList.arrows();

            if(tis.hasClass('prevBtn')){
              var nextDay = getAgendaList.currentDate.subtract(1, 'days');
            }else{
              var nextDay = getAgendaList.currentDate.add(1, 'days');
            }

            getAgendaList.currentDate = nextDay;
            getAgendaList.getData(nextDay,itms);
            getAgendaList.buildHead(options);              
          });
        },
        starters : function(options){
          getAgendaList.buildHead(options);
        },
        setList : function(result,items){
          var lista = getAgendaList.nothingAgenda;
          if(result && !validity(result.data.error)){
            var ops = {
                        data      : result.data,
                        items     : items
                      };

            lista = getAgendaList.buildList(ops);
          }

          $('#scheduleList').html(lista);
          getAgendaList.arrows();
        },
        getData : function(nextDate,items){
          $('#scheduleList').html($('.placeholders').html());
          var url = '?action=load&s=' + ese + '&from=' + nextDate.format('YYYY-MM-DD');
          if(getAgendaList.cache[url]){
            getAgendaList.setList(getAgendaList.cache[url],items);
          }else{
            $.get(url,function(result){

              getAgendaList.cache[url] = result;
              getAgendaList.setList(result,items);

            });
          }
        },
        init : function(options){
          getAgendaList.getData(getAgendaList.currentDate,itms);
          getAgendaList.starters(options);
          getAgendaList.events(options);
        }
      };

      getAgendaList.init({
        userName  : '<?=USER_NAME;?>'
      });
      
    });
  </script>

</body>
</html>
<?php
dai();
?>