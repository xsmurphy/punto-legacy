<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));
define('USR_ID', dec($data[1]));

//print_r($data); 
//echo USR_ID;

$setting = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$_company = ncmExecute("SELECT accountId FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('API_KEY', sha1($_company['accountId']) );
define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
define('USER_NAME', getValue('contact','contactName','WHERE contactId = ' . USR_ID));

date_default_timezone_set(TIMEZONE);
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
            'order'         => 'ASC'
          ];

  $result = json_decode(curlContents(API_URL . '/get_orders.php','POST',$data));

  if($result){
    header('Content-Type: application/json;');
    $data = ['data' => $result];
    echo json_encode($data);
  }

  dai();
}

$data = [
            'api_key'       => API_KEY,
            'company_id'    => enc(COMPANY_ID),
            'children'      => 1,
            'nolimit'       => true
          ];

$itms = curlContents(API_URL . '/get_items.php','POST',$data);
?>

  
  
  <div class="wrapper text-center col-md-6 col-md-offset-3 col-sm-6 col-sm-offset-3 col-xs-12">

    <div class="col-xs-12 text-left no-padder">
      <a href="#" class="btn pull-left btn-md myMenuBack m-r m-t-xs"><i class="material-icons md-24">chevron_left</i></a>

      <img src="/assets/80-80/0/<?=enc(COMPANY_ID)?>.jpg" width="40" class="img-circle m-r m-b"> 
      <span class="h3 m-t-xs m-b-md font-bold"><?=COMPANY_NAME?></span>
    </div>
    
    <div class="r-24x md-whiteframe-16dp col-xs-12 no-padder clear">
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

        <ul class="list-group list-group-lg no-bg auto m-b-lg col-xs-12 no-padder no-border" id="scheduleList"></ul>

      </div>
    </div>
  </div>

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
    <?=noDataMessage('Nada en la agenda','No tiene reservas para esta fecha','/assets/images/emptystate7.png')?>
  </script>

  <script>
    $(document).ready(function(){
      if(typeof ncmPageCache === 'undefined'){
        var ncmPageCache = [];
      }
      
      var ese   = "<?=$_GET['s'];?>";
      var itms  = <?=iftn($itms,"''")?>;

      var getAgendaList = {
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
              if(index && ncmHelpers.validInObject(items[index],'name')){
                details.push('● ' + items[index].name);
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
              //$next.removeClass('disabled').removeAttr('disabled');
            }else{
              $prev.removeClass('disabled').removeAttr('disabled');
              $next.removeClass('disabled').removeAttr('disabled');
            }

            $prev.removeClass('disabled').removeAttr('disabled');
            $next.removeClass('disabled').removeAttr('disabled');
          }
        },
        events : function(options){
          ncmHelpers.onClickWrap('.nextBtn,.prevBtn', function(event,tis){
            if(tis.hasClass('disabled')){
              return false;
            }

            getAgendaList.arrows(true);

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
          var lista = '';
          if(ncmHelpers.validity(result) && !ncmHelpers.validInObject(result.data,'error')){
            var ops = {
                        data      : result.data,
                        items     : items
                      };

            lista = getAgendaList.buildList(ops);
          }else{
            lista = $('#noDataTpl').html();
          }

          $('#scheduleList').html(lista);
          getAgendaList.arrows();
        },
        getData : function(nextDate,items){
          $('#scheduleList').html($('.placeholders').html());
          var url = '/screens/userDayAgenda?action=load&s=' + ese + '&from=' + nextDate.format('YYYY-MM-DD');

          if(ncmPageCache[url]){
            getAgendaList.setList(ncmPageCache[url],items);
          }else{
            ncmHttp.getit(url,function(result){

              ncmPageCache[url] = result;
              getAgendaList.setList(result,items);

            },false,false,'json');
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

<?php
dai();
?>