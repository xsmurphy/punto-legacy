<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode( validateHttp('s') ));

define('COMPANY_ID', dec($data[0]));
define('USR_ID', dec($data[1])); //debo usar USR_ID y no USER porque sino abre INTERCOM

$setting  = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
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
 
if(validateHttp('action') == 'load'){

  $data = [
            'api_key'       => API_KEY,
            'company_id'    => enc(COMPANY_ID),
            'type'          => '12',
            'user'          => enc(USR_ID),
            'status'        => '5',
            'from'          => date('Y-m-d H:i:s',strtotime("-1 months")),
            'to'            => date('Y-m-d 23:59:59'),
            'customerdata'  => 1,
            'order'         => 'ASC'
          ];

  $result = json_decode(curlContents(API_URL .'/get_orders.php','POST',$data));

  header('Content-Type: application/json;');

  if($result){
    $data = ['data' => $result];
    echo json_encode($data);
  }else{
    echo json_encode($result);
  }

  dai();
}

if(validateHttp('action') == 'setPos' && validateHttp('lat') && validateHttp('lng')){
  //records (arr), table (str), where (str)
  $lat = floatval( base64_decode( validateHttp('lat')) );
  $lng = floatval( base64_decode( validateHttp('lng')) );

  $result['records']  = ['contactLatLng' => $lat . ',' . $lng];
  $result['table']    = 'contact';
  $result['where']    = 'contactId = ' . USR_ID . ' AND companyId = ' . COMPANY_ID;

  $response = ncmUpdate($result);

  if($response['error']){
    dai('false'); 
  }else{
    dai('true');
  }
}

?>

  <div class="wrapper text-center col-md-6 col-md-offset-3 col-sm-6 col-sm-offset-3 col-xs-12">

    <div class="col-xs-12 text-left no-padder">
      <img src="https://assets.encom.app/80-80/0/<?=enc(COMPANY_ID)?>.jpg" width="40" class="img-circle m-r m-b"> 
      <span class="h3 m-t-xs m-b-md font-bold"><?=COMPANY_NAME?></span>
    </div>
    
    <div class="panel r-24x md-whiteframe-16dp col-xs-12 no-padder clear text-left">
      <div class="userName col-xs-12 wrapper h3 font-bold text-dark"></div>
      <div class="col-xs-12 no-padder list-group list-group-lg auto no-bg no-border" id="myMenuMyOrderslist">
      </div>
      
    </div>

  </div>

  <div class="col-sm-2 col-xs-12 no-padder"></div>

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

  <script type="text/html" id="rowTpl">
    <a href="{{url}}" class="list-group-item clearfix bg b-b b-light myMenuLoadPage" data-container="#externalSource">
     <span class="pull-left m-t-sm m-l text-md">
       <i class="material-icons text-muted">{{icon}}</i>
     </span>
     <span class="pull-right m-r h4 font-bold m-t-sm">
      {{kms}}
     </span>
     <div class="clear">
       <span class="block text-ellipsis text-md">{{customer}}</span>
       <small class="text-muted text-ellipsis">
        {{address}}
       </small>
     </div>
    </a>
  </script>

  <script type="text/html" id="noDataTpl">
    <?=noDataMessage('No tiene órdenes','No tiene órdenes pendientes','https://assets.encom.app/images/emptystate7.png')?>
  </script>

  <script type="text/html" id="noGpsTpl">
    <?=noDataMessage('Habilite su GPS','Para poder mostrar los pedidos necesita permitir el acceso al GPS','https://assets.encom.app/images/emptystate7.png')?>
  </script>

  <script>
    $(document).ready(function(){

      $('#myMenuMyOrderslist').html($('.placeholders').html());

      var ese           = '<?=validateHttp('s')?>';
      
      var myOrdersList  = {
        myLat       : 0,
        myLng       : 0,
        hoy         : moment(),
        currentDate : moment(),
        buildList   : (options) => {
          var data  = ncmHelpers.valid(options, 'data'),
          items     = ncmHelpers.valid(options, 'items'),
          list      = '', customerName, details, index, startH, now, isNow, address, kms, 
          row       = {}, 
          distance  = 0;
          
          $.each(data,function(i,val){
            details       = [];
            customerName  = (val.customer_name) ? val.customer_name : 'Sin Cliente';
            address       = val.customer_address !== false ? val.customer_address : '';
            startH        = val.from_hour;
            isNow         = '';
            blocked       = '';

            if(ncmAuth.activeUser.lat && ncmAuth.activeUser.lng && val.customer_lat && val.customer_lng){
              distance = ncmMaps.getDistanceInKM( ncmAuth.activeUser.lat, ncmAuth.activeUser.lng, val.customer_lat, val.customer_lng );
            }
            
            if(val.order_status == 4 || val.order_status == 7){
              blocked = 'text-l-t';
            }

            details = (!details) ? ['Sin información'] : details;
            
            //get vcurrent time
            now     = parseInt(moment().format("HH")) + 1;
            current = parseInt(startH.substring(0, startH.length-3));
            
            if(now == current){
              isNow = 'text-info';
            }

            var url = '<?=PUBLIC_URL?>/userOrderView?s=' + window.btoa(val.transaction_id + ',<?=enc(COMPANY_ID)?>,' + 'driver') + '&fromapp=true&isdarkmode=' + (ncmUIX.isDarkMode ? 1 : 0 ) + '&ese=' + ese;

            row =   {
                      icon      : val.icon,
                      customer  : customerName,
                      address   : '#' + val.number_id + ' ' + address,
                      kms       : distance + ' Km',
                      url       : url
                    };
            
            list += ncmUIX.mustache(false,row,$('#rowTpl'),true);
          });

          //console.log('buildList returning', list);
          
          return list;

        },
        events      : (options) => {},
        getData     : () => {
          $('#myMenuMyOrderslist').html($('.placeholders').html());

          ncmHttp.getit('<?=PUBLIC_URL?>/userOrders?action=load&s=' + ese, function(result){
            var data  = result.data,
            lista     = {};
            
            if(ncmHelpers.valid(result.data.error)){

              $('#myMenuMyOrderslist').html($('#noDataTpl').html());

            }else{

              var ops = {
                          data      : result.data
                        };

              $('#myMenuMyOrderslist').html( myOrdersList.buildList(ops) );
              ncmUIX.myMenu.events();

              ncmAlerts.toast('Obteniendo ubicación','info');

              ncmCustomer.getGeo(() => {
                
                $('#myMenuMyOrderslist').html( myOrdersList.buildList(ops) );
                ncmUIX.myMenu.events();

              },
              () => {
                
                $('#myMenuMyOrderslist').html( myOrdersList.buildList(ops) );
                ncmUIX.myMenu.events();

              });

            }

          },false,false,'json');
        },
        init        : () => {
          myOrdersList.getData();
          myOrdersList.events();
        }
      };

      myOrdersList.init();
      
    });
  </script>

<?php
dai();
?>