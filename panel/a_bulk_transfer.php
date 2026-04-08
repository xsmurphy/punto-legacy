<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
topHook();
allowUser('items','view');

$baseUrl = '/' . basename(__FILE__,'.php');

if(validateHttp('action') == 'insert'){
  if(!allowUser('items','edit',true)){
      jsonDieResult(['error'=>'No permissions']);
  }
  
  $outlet         = dec( validateHttp('outlet','post') );
  $order          = ($_GET['ord']=='true') ? true : false;
  $tr             = '';
  $items          = validateHttp('item','post');

  if(!$items){
    jsonDieResult(['error'=>'No Data']);
  }

  foreach($items as $label){
    $units    = ($label['units']);
    $itemId   = dec($label['itemId']);
    $oFrom    = dec($label['from']);
    $oTo      = dec($label['to']);
    $unitsChk = abs( formatNumberToInsertDB($label['units'],true,5) );

    if($unitsChk > 0){//valido que la cantidad sea mayor a 0
      list($oFrom,$oFromL)    = outletOrLocation($oFrom);
      list($oTo,$oToL)        = outletOrLocation($oTo);
      
      if(!$order){
        $transferedId = transferInventoryBatch($itemId,$units,$oFrom,$oTo,$oFromL,$oToL);
      }else{
        $tr  .= '<tr>' .
                ' <th>' . getValue('item', 'itemName', 'WHERE itemId = ' . $itemId) . '</th>' .
                ' <th class="text-right">' . formatCurrentNumber($units,'si',false,'5') . '</th>' .
                ' <th>' . getCurrentOutletName($oFrom) . '</th>' .
                ' <th>' . getCurrentOutletName($oTo) . '</th>' .
                '</tr>';
      }
    }

  }

  if($order){
    $html   = ' <div class="h3 text-center m-b-sm col-xs-12">' .
              ' </div>' .
              ' <div class="text-center m-b">Transferencia</div>' .
              ' <div class="col-xs-12 m-b">' .
              '   <div class="col-xs-12 text-center">' . USER_NAME . '</div>' .
              ' </div>' .
              ' <div class="h3 text-center m-b">Detalles</div>';

    $table  = '<table class="table">' .
              ' <tr>' .
              '   <th>Artículo</th>' .
              '   <th class="text-right">Cantidad</th>' .
              '   <th>Desde</th>' .
              '   <th>Hasta</th>' .
              ' </tr>' .
                $tr .
              '</table>';

    $html   = '<div class="wrapper">' . $html . $table . '</div>';

    dai($html);
  }else{
    dai('true');
  }
  updateLastTimeEdit();
  dai();
}

if(validateHttp('action') == 'itemInventory'){
  $outlet = dec(validateHttp('outlet'));
  $item   = dec(validateHttp('item'));
  header('Content-Type: application/json');
  if(validateHttp('outlet') && validateHttp('item')){
    $result = getItemStock($item,$outlet);
    if($result){
      echo json_encode(["count" => $result['stockOnHand']]); 
    }else{
      echo json_encode(['error'=>'false']); 
    }
  }else{
    echo json_encode(['error'=>'false']);
  }
  dai();
}
 
?>
 
<div class="col-md-1 col-xs-12"></div>
<div class="col-md-10 col-xs-12" id="contentAppear">
  
  <form action="<?=$baseUrl?>?action=insert" method="POST" id="addItem" class="col-xs-12 no-padder">
    <div class="col-sm-6">
      <span class="font-bold pull-left h1 m-t">
        Transferencia de Stock
      </span>  
    </div>
    <div class="col-sm-6 text-right">
      <a href="#" class="btn btn-info btn-lg btn-rounded m-b-sm m-t font-bold text-u-c" data-order="false" id="transferBtn">Transferir</a>
    </div>
    <div class="col-xs-12 bg-white no-padder table-responsive r-24x">
      
      <div class="col-xs-12 no-padder font-bold text-u-c">
        <div class="col-sm-3 col-xs-6 wrapper">
          Desde
        </div>
        <div class="col-sm-4 wrapper">
          Artículo
        </div>
        <div class="col-sm-2 wrapper">
          Cantidad
        </div>
        <div class="col-sm-3 col-xs-6 wrapper">
          Hasta
        </div>
      </div>

      <div id="table" class="col-xs-12 no-padder"></div>

      <div id="empty" class="hidden">
        <div class="text-center wrapper-xl" id="noContentMsg">
          <img src="/assets/images/emptystate7.png" height="120">
          <div class="h4 m-t">Presione en AGREGAR<br>para comenzar</div>
        </div>
      </div>
      
      <div class="col-xs-12 wrapper text-center m-t">
        <a href="#" id="add" class="m-r-lg font-bold text-u-c"><span class="text-info">Agregar</span></a>
        <a href="#" class="remove font-bold text-u-c"><span class="text-dark">Eliminar</span></a>
      </div>
    </div>
  </form>
  
</div>
<div class="col-md-1 col-xs-12"></div>

<script type="text/html" id="lineTpl">
  <div class="col-xs-12 b-b no-padder oneRow animates fadeInDown speed-4x" id="row{{index}}">
   <div class="col-sm-3 wrapper col-xs-6">
     <?=selectInputOutlet(OUTLET_ID,false,"bg-white no-border outletFrom b-b outlet{{index}}","item[{{index}}][from]",false,false,true);?>
   </div>

   <div class="col-sm-4 wrapper">
    <select class="form-control bg-white no-border b-b search item{{index}}" name="item[{{index}}][itemId]" data-id="{{index}}">
      {{#itemId}}
      <option value="{{itemId}}">{{itemName}}</option>
      {{/itemId}}
    </select>
   </div>

   <div class="col-sm-2 wrapper">
    <input type="text" name="item[{{index}}][units]" data-id="{{index}}" class="form-control bg-white no-border b-b text-right maskFloat3 units" id="units{{index}}" placeholder="1.000" value="{{qty}}" data-max="">
   </div>
    
   <div class="col-sm-3 wrapper col-xs-6">
     <?=selectInputOutlet("{{outletId}}",false,"bg-white no-border outletTo b-b","item[{{index}}][to]",false,false,true);?>
   </div>

   <input type="hidden" name="" id="itemId{{index}}">
   <input type="hidden" name="item[{{index}}][name]" id="name{{index}}">
  </div>
</script>

<script>
  var baseUrl = '<?=$baseUrl?>';
  $(document).ready(function(){

    var ncmTransfer = {
      index : -1,
      load : function(){
        $('#contentAppear').show();

        $('#table').html($('#empty').html());
        
        ncmTransfer.actions();
        ncmTransfer.listeners();
        ncmTransfer.unOrder();
      },
      actions : function(){
        onClickWrap('#transferBtn,#orderBtn',function(event,tis){
          var $form       = $('#addItem');
          var formAction  = $form.attr('action');
          var order       = tis.data('order');
          $form.attr('action', formAction + '&ord=' + order);

          var outletName  = $('[name="outlet"]').find('option:selected').text();

          ncmDialogs.confirm('¿Realmente desea realizar la transferencia?','No podrá revertir esta operación','warning',function(e){
            if (e) {
              $('#addItem').submit();
              spinner('body', 'show');
              window.onbeforeunload = null;
            }
          });
        },false,true);

        switchit(function(tis, active){
          if(active){
            $('#dueDateSelect').show();
          }else{
            $('#dueDateSelect').hide();
          }
        });

        onClickWrap("#add",function(tis) {
          ncmTransfer.index++;

          if(ncmTransfer.index >= 50){
            alert('Solo se pueden añadir 50 líneas por transferencia');
            return false;
          }

          ncmTransfer.addNewLine(ncmTransfer.index,'1.000');
        });

        onClickWrap(".remove",function(tis) {
          ncmTransfer.index--;
          $('#table .col-xs-12:last').remove();

          var amount = 0;
          $(".totalItem").each(function(i,v){
            amount += Number($(this).attr('data-raw'));
          });

          $('.btn-status').attr('disabled',false);

          if($('#table').find('.oneRow').length < 1){
            $('#table').html( $('#empty').html() );
            $('#noContentMsg').show();
            window.onbeforeunload = null;
          }

        });
      },
      listeners : function(){
        masksCurrency($('.units'),thousandSeparator,'no');
        masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
        $('#addItem').off('submit').on('submit',function(e) {
          $.ajax({ // create an AJAX call...
            data: $(this).serialize(), // get the form data
            type: $(this).attr('method'), // GET or POST
            url: $(this).attr('action'), // the file to call
            success: function(result) { // on success..e
              if(result == 'true'){
                message('Transferencia realizada','success');
                $('#addItem')[0].reset();
                $('#table').html($('#empty').html());
                window.onbeforeunload = null;
              }else if(result == 'false'){
                message('No se pudo transferir','danger');
              }else if(result == 'nooutlet'){
                ncmDialogs.alert('Debe seleccionar una sucursal donde se realizará la transferencia');
              }else if(result.length > 255){
                $(result).print();
                 spinner('body', 'hide');
                 return false;
              }else if(result.error){
                if(result.error == 'No Data'){
                  var msg = 'Debe añadir artículos';
                }else if(result.error == 'No permissions'){
                  var msg = 'No tiene permisos para transferir';
                }

                ncmDialogs.alert(msg);
              }else{
                ncmDialogs.alert(result);
                return false;
              }
              
              $('#noContentMsg').show();
              spinner('body', 'hide');
            }
          });
          return false; // cancel original event to prevent form submitting
        });
      },
      addNewLine : function(index,qty,itemId,itemName,updated){
        $('#noContentMsg').hide();
        var newtr = ncmHelpers.mustacheIt($('#lineTpl'),{index : index, qty : qty, itemId : itemId, itemName : itemName},false,true);
        $('#table').append(newtr);

        select2Ajax({
          element   : '.search',
          url       : '/a_items?action=searchItemStockableInputJson',
          type      : 'item',
          onChange  : function($el,data){
              var count         = 0;
              var id            = $el.data('id');

              if(validity(data,false,true,'id')){}
          }
        });

        var preId = simpleStorage.get('bulk_transfer_default_outletTo');
        if(preId){
          $(".outletTo:last").val(preId);
        }

        select2Simple($('.outletFrom'));
        select2Simple($('.outletTo'),false,function(tis,data){
          var preId = data.id;
          simpleStorage.set('bulk_transfer_default_outletTo',preId);
        });

        masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');

        $('.units').off('formatted').on('formatted',function(){
            var max = parseFloat($(this).data('max'));
            var val = unMaskCurrency($(this).val(),thousandSeparator,'yes');
            if(val > max){
              $(this).val($(this).data('max'));
            }
        });

        window.onbeforeunload = function() {
            return true;
        };

      },
      unOrder : function(){
        <?php
        $id = validateHttp('i');
        if($id){
          $result = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ?',[dec($id),COMPANY_ID]);
          if($result){
            $items = json_decode($result['transactionDetails'],true);
            foreach ($items as $key => $value) {
              $itemName = getItemName(dec($value['itemId']));
              ?>
              ncmTransfer.index++;
              ncmTransfer.addNewLine(ncmTransfer.index,"<?=iftn($value['qty'],'1.000')?>","<?=$value['itemId']?>","<?=$itemName;?>");
              <?php
            }
          }
        }
        ?>
      }
    };

    var ncmCaheTransfers = {
      update : function(name,push){
          var prePro = simpleStorage.get(name) ? simpleStorage.get(name) : [];
          prePro.push(push);
          simpleStorage.set(name,prePro);
      }
    };

    ncmTransfer.load();
    
  });
</script>
<?php
include_once('includes/compression_end.php');
dai();
?>
