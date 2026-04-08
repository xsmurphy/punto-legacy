<?php
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

  $outlet         = dec(validateHttp('outlet','post'));
  $type           = validateHttp('type','post') ? true : false; //si es true doy de alta, si no de baja

  list($outlet,$location)    = outletOrLocation($outlet);

  foreach(validateHttp('item','post') as $label){
    $units  = formatNumberToInsertDB($label['units'],true,3);
    $itemId = dec($label['itemId']);
    $cogs   = formatNumberToInsertDB($label['cogs']);
    $ops    = [];

    if($units >= 0){//en este caso se permite cero para poder ajustar precio de costo masivamente
      if($type){
        
          $ops['itemId']    = $itemId;
          $ops['outletId']  = $outlet;
          $ops['locationId']= $location;
          $ops['cogs']      = $cogs;
          $ops['count']     = $units;
          $ops['note']      = truncate($_POST['note'],50);

          manageStock($ops);
       
      }else{

          $ops['itemId']    = $itemId;
          $ops['outletId']  = $outlet;
          $ops['locationId']= $location;
          $ops['count']     = $units;
          $ops['type']      = '-';
          $ops['note']      = truncate($_POST['note'],50);

          manageStock($ops);

      }
    }
    
  }
	dai('true');
}

$it     = ncmExecute('SELECT itemId, itemName, itemSKU
                        FROM item 
                        WHERE itemStatus = 1 
                        AND itemIsParent = 0 
                        AND itemTrackInventory > 0 
                        AND itemType IN(\'product\',\'compound\',\'production\',\'giftcard\')
                        AND ' . $SQLcompanyId . ' ORDER BY itemName ASC LIMIT ' . $plansValues[PLAN]['max_items'],[],false,true);

$out    = '<option>Seleccionar</option>';

if($it){
  while (!$it->EOF) {
    $name = $it->fields['itemName'];
    $id   = enc($it->fields['itemId']);
    $sku  = iftn($it->fields['itemSKU'],$id);

    $out .= '<option value="'.$id.'">'.addslashes($name).' (' . addslashes($sku) . ')</option>';
    
    $it->MoveNext();
  }

  $it->Close();
}
?>
  

<div class="col-lg-10 col-lg-offset-1">
  <span class="font-bold h1 m-t pull-right m-b" id="pageTitle">
    Ajuste de Stock
  </span>
  
  <form action="<?=$baseUrl;?>?action=insert" method="POST" id="addItem" class="col-xs-12 no-padder">

    <div class="col-lg-4 col-sm-5 col-xs-12">
      <div class="col-xs-12 bg-info gradBgBlue text-white wrapper r-24x">
        <label class="font-bold text-xs text-u-c">Sucursal y Depósito</label>
        <?php echo selectInputOutlet(OUTLET_ID,false,'search','outlet',false,false,true); ?>

        <div class="col-xs-12 no-padder m-b m-t">
          <div class="col-xs-4 m-t-sm text-xs font-bold text-u-c">
            Baja
          </div>
          <div class="col-xs-4">
            <?=switchIn('type')?>
          </div>
          <div class="col-xs-4 m-t-sm text-right text-xs font-bold text-u-c">
            Alta
          </div>
        </div>

        <label class="font-bold text-xs text-u-c">Nota de Ajuste</label>
        <textarea class="form-control m-b no-border no-bg b-b text-white" name="note" style="height: 236px;"></textarea>

        <div class="text-center">
          <input class="btn btn-default no-border btn-lg btn-rounded m-b-sm m-t font-bold text-u-c" title="" type="submit" value="Procesar" id="productionBtn">
        </div>
      </div>
    </div>

    <div class="col-lg-8 col-sm-7 col-xs-12 bg-white no-padder r-24x" style="min-height:500px;">
      <div class="col-xs-12 m-b m-t font-bold text-u-c">
        <div class="col-xs-6">
          Artículo
        </div>
        <div class="col-xs-2">
          Costo
        </div>
        <div class="col-xs-4 text-right">
          Cantidad
        </div>
        
      </div>

      <div id="table">
        
        <div class="col-xs-12 m-b">
          <div class="col-sm-5 no-padder">
            <select class="form-control search" name="item[0][itemId]" data-id="0">
              <?=$out?>
            </select>
          </div>
           <div class="col-xs-3">
            <input type="text" name="item[0][cogs]" data-id="0" class="form-control no-border b-b text-right maskCurrency costo hidden" placeholder="" value="">
           </div>
          <div class="col-xs-4">
            <input type="text" name="item[0][units]" data-id="0" class="form-control no-border b-b text-right maskFloat3" placeholder="" value="">
          </div>
        </div>

      </div>
      
      <div class="wrapper text-center row m-t-lg">
        <a href="#" id="add" class="m-r text-u-c font-bold"><span class="text-info">Agregar</span></a>
        <a href="#" class="remove text-u-c font-bold">Eliminar</a>
      </div>
    </div>

  </form>
</div>

<script>
  var baseUrl = '<?=$baseUrl?>';
  $(document).ready(function(){

      onClickWrap('#productionBtn',function(event,tis){
        var outletName  = $('[name="outlet"]').find('option:selected').text();

        ncmDialogs.confirm('¿Realmente desea continuar?','No podrá revertir esta acción','question',function(e){
          if (e) {
            $('#addItem').submit();
            spinner('body', 'show');
            window.onbeforeunload = null;
          }
        });
      });

      $('#addItem').off('submit').on('submit',function(e) {
        $.ajax({ // create an AJAX call...
          data: $(this).serialize(), // get the form data
          type: $(this).attr('method'), // GET or POST
          url: $(this).attr('action'), // the file to call
          success: function(result) { // on success..e
            if(result == 'limit'){
              ncmDialogs.alert('Error: El producto puede tener un máximo de 30 compuestos');
            }else if(result == 'true'){
              message('Procesado','success');
            }else if(result == 'nooutlet'){
              ncmDialogs.alert('Debe seleccionar una sucursal');
            }else if(result.error == 'No permissions'){
              ncmDialogs.alert('No posee permisos para realizar esta operación');
            }else{
              ncmDialogs.alert(result);
            }
            $('#addItem')[0].reset();
            $('#table').html('');
            spinner('body', 'hide');
            $(".search").select2({theme: "bootstrap"});
          }
        });
        return false; // cancel original event to prevent form submitting
      });

      switchit(function(tis,active){
        if(active){
          $('.costo').removeClass('hidden');
        }else{
          $('.costo').addClass('hidden');
        }
      },true);

      masksCurrency($('.units'),thousandSeparator,'no');
      masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
      masksCurrency($('.maskCurrency'),thousandSeparator,decimal);

      var options = {
        placeholder: "Seleccione...",
        allowClear: true
      };

      $(".search").select2({theme: "bootstrap"});

      var i = 0;

      onClickWrap("#add",function(tis) {
        i++;

        if(i>=20){
          alert('Solo se pueden añadir 20 artículos por vez');
          return false;
        }

        var costoHidden = 'hidden';
        if($('.typeClass:checked').length){
          var costoHidden = '';
        }

        var newtr = $('<div class="col-xs-12 m-b"> <div class="col-xs-5 no-padder"> <select class="form-control search" name="item[' + i + '][itemId]" data-id="' + i + '"> <?=$out?> </select> </div> <div class="col-xs-3"> <input type="text" name="item[' + i + '][cogs]" data-id="' + i + '" class="form-control no-border b-b text-right maskCurrency costo ' + costoHidden + '" placeholder="" value=""> </div> <div class="col-xs-4"> <input type="text" name="item[' + i + '][units]" data-id="' + i + '" class="form-control no-border b-b text-right maskFloat3" placeholder="" value=""> </div> </div>');
        
        $('#table').append(newtr);
        $(".search").select2({theme: "bootstrap"});
        
        masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
        masksCurrency($('.maskCurrency'),thousandSeparator,decimal);

        window.onbeforeunload = function(){return true;};

      },false,true);

      onClickWrap(".remove",function(tis) {
        $('#table .col-xs-12:last').remove();

        var amount = 0;
        $(".totalItem").each(function(i,v){
          amount += Number($(this).attr('data-raw'));
        });

        $('.btn-status').attr('disabled',false);

        if($('#table div').length < 1){
          window.onbeforeunload = null;
        }

      });

  });
</script>

<?php
dai();
?>
