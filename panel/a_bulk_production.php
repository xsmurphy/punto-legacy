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
  $produced       = [];

  foreach(validateHttp('item','post') as $label){
    $units      = formatNumberToInsertDB($label['units'],true,3);
    $itemId     = dec($label['itemId']);
    $expires    = isset($label['expires']) ? $label['expires'] . ' 01:00:00' : '';

    if($units > 0){
      $producedId = produce($itemId,$units,$outlet,$expires, validateHttp('orderField','post'));
      array_push($produced, $producedId);
    }    
  }

  if(validity($produced,'array')){

    $groupItem  = [];
    foreach($produced as $producedId){
      if(validity($producedId)){
        $details    = ncmExecute('SELECT * FROM production WHERE productionId = ?',[$producedId]);
        $data       = json_decode($details['productionRecipe'],true);
        
        foreach($data as $item => $value){
          if(validity(isset($groupItem[$item]) ? $groupItem[$item] : false)){
            $groupItem[$item] = $groupItem[$item] + (float)$value['units'];
          }else{
            $groupItem[$item] = (float)$value['units'];
          }
        }

      }
    }

    $html   = '<div class="text-center">' .
    '<img src="/assets/150-150/&amp;f=2|4,-50/' . COMPANY_ID . '.jpg" width="100">' .
    '</div>' .
    '<div class="text-center col-xs-12 h2 m-b">Orden de Producción</div>' .
    '<div class="col-xs-12 m-b text-center">' .
    getCurrentOutletName() . ' - ' . USER_NAME .
    '  <div>' . niceDate($details['productionDate'],true) . '</div>' .
    '</div>' .
    '<div class="h4 text-center m-b">Detalles</div>' .
    '<table class="table">' .
    ' <tr>' .
    '   <th>Compuesto</th>' .
    '   <th class="text-center">Cant. Bruta</th>' .
    '   <th class="text-center">% Merma</th>' .
    '   <th>Uni. de Medida</th>' .
    '</tr>';
    $table = '';
    foreach($groupItem as $item => $value){
      $itmData  = getItemData(dec($item));
      $wasteP   = ($itmData['itemWaste']) ? $itmData['itemWaste'] : '0';
      $table .= '<tr>' .
      ' <td>' . $itmData['itemName'] . '</td>' .
      ' <td class="text-right">' . formatCurrentNumber($value,'si',false,'5') . '</td>' .
      ' <td class="text-right">' . formatCurrentNumber($wasteP) . '%</td>' .
      ' <td> <span class="label bg-light">' . $itmData['itemUOM'] . '</span> </td>' .
      '</tr>';
    }
    $table .= '</table>' .
    '<div class="col-xs-12 wrapper m-t-lg b-t">' .
    ' Firma' .
    '</div>';


    $html   = '<div class="wrapper">' . $html . $table . '</div>';

    //{"itemId":30478,"units":1,"compounds":{"15186":"0.10100","13997":"0.20000","13996":"2.00000","32485":"0.00040"}}

    if( validateHttp('orderField','post') ){
      dai($html);
    }else{
      updateLastTimeEdit(false,'item');
      updateRowLastUpdate('item','item_id = ' . $itemId);
      dai('true');
    }

  }else{
    dai('false');
  }
}

if(validateHttp('action') == 'capacity' && validateHttp('id')){
  $id         = dec(validateHttp('id'));

  $inventory  = getAllItemStock();
  $waste      = getAllWasteValue();//obtengo waste de todos los productos de la empresa

  $itemData   = ncmExecute('SELECT itemUOM FROM item WHERE itemId = ? LIMIT 1',[$id]);
  $uom        = iftn($itemData['itemUOM'],'');
  $compound   = getCompoundsArray($id);

  if($compound){
    $out = formatQty( getProductionCapacity($compound,$inventory,$waste) ) . ' ' . $uom;

    $compsOut = [];
    foreach ($compound as $resulta) {
      $qty      = number_format($resulta['toCompoundQty'],2);//dejo en 2 ceros
      $compData = ncmExecute('SELECT itemId,itemName FROM item WHERE itemId = ? LIMIT 1',[$resulta['compoundId']]);

      $compsOut[] = ['name' => unXss($compData['itemName']), 'qty' => $qty];
    }

  }else{
    $out = '0 ' . $uom;
  }

  jsonDieResult(['capacity' => $out, 'compounds' => $compsOut]);
}

$it     = ncmExecute('  SELECT 
  itemId, itemName 
  FROM item 
  WHERE itemStatus = 1 
  AND itemIsParent = 0 
  AND (itemType = \'product\' || itemType = \'production\')
  AND itemProduction = 1 
  AND ' . $SQLcompanyId . ' 
  ORDER BY itemName ASC 
  LIMIT ' . $plansValues[PLAN]['max_items'],[],false,true);

$itmsSelect    = '<option>Seleccionar</option>';

if($it){
  while (!$it->EOF) {
    $name = $it->fields['itemName'];
    $id   = enc($it->fields['itemId']);

    $itmsSelect .= '<option value="' . $id . '">' .
    addslashes($name) . 
    ' ' . addslashes($sku) .
    '</option>';
    
    $it->MoveNext();
  }

  $it->Close();
}

?> 
<form action="<?=$baseUrl;?>?action=insert" method="POST" id="addItem" class="col-xs-12 no-padder">

  <div class="col-xs-12 no-padder">
    <?=headerPrint();?>
    <div class="col-md-1"></div>
    <div class="col-md-10 col-sm-12 col-xs-12 no-padder">


      <div class="col-sm-6">
        <input class="btn btn-info btn-lg rounded m-b-sm m-t m-r font-bold text-u-c pull-right hidden-print pull-left" title="" type="submit" value="Producir" id="productionBtn">

        <span class="pull-left">
          <label class="text-xs font-bold text-u-c">Sucursal y Depósito</label>
          <?php echo selectInputOutlet(OUTLET_ID,false,'m-b simpleSearch rounded inOutlet','outlet',false,false,true); ?>  
        </span>

      </div>

      <div class="col-sm-6 text-right">
        <span class="font-bold h1" id="pageTitle">
          <div class="text-default text-sm  visible-print">Orden de </div>
          <div class="m-t hidden-print"></div>
          Producción
        </span>
      </div>

    </div>
    <div class="col-md-1"></div>
  </div>

  <div class="col-xs-12 no-padder">
    <div class="col-md-1"></div>
    <div class="col-md-10 bg-white no-padder r-24x">
      <div class="col-xs-12 m-b m-t font-bold text-u-c">
        <div class="col-sm-3 col-xs-2 m-b">
          Cantidad
        </div>
        <div class="col-sm-6 col-xs-7 m-b">
          Artículo
        </div>
        <div class="col-sm-3 col-xs-3 m-b text-center hidden-print">
          Capacidad
        </div>
      </div>

      <div id="table">

      </div>

      <div class="wrapper text-center row hidden-print">
        <a href="#" id="add" class="m-r"><span class="text-info font-bold text-u-c">Agregar</span></a>
        <a href="#" class="remove font-bold text-u-c">Eliminar</a>
      </div>
    </div>
    <div class="col-md-1"></div>
  </div>

  <div id="noContentMsg" class="hidden">
    <div class="text-center wrapper-xl col-xs-12 noContentMsgIn">
      <img src="/assets/images/emptystate7.png" height="120">
      <div class="h4 m-t hidden-print">Presione en AGREGAR<br>para comenzar</div>
      <div class="h4 m-t visible-print">Debe añadir productos</div>
    </div>
  </div>
</form>

<?=footerPrint(['signatures'=>2]);?>
<script type="text/html" id="itemRowTpl">
  <div class="col-xs-12 oneRow">
    <div class="col-sm-3 col-xs-2 m-b font-bold">
      <input type="text" name="item[{{index}}][units]" data-id="{{index}}" class="form-control no-bg no-border b-b text-right maskFloat3 animated" placeholder="Cantidad" value="1,000" id="units{{index}}">
    </div>
    <div class="col-sm-6 col-xs-7 no-padder m-b font-bold">
      <select class="form-control searchAjax" name="item[{{index}}][itemId]" data-index="{{index}}"> <?=$itmsSelect?> </select>
      <input type="hidden" name="" id="itemId{{index}}"> <input type="hidden" name="item[{{index}}][name]" class="" id="name{{index}}">
    </div>
    <div class="col-sm-3 col-xs-3 no-padder m-b h4 text-center m-t-xs hidden-print capacity{{index}}">
    </div>
    <div class="col-xs-12 visible-print list{{index}}"></div>
  </div>
</script>
<script type="text/html" id="compoundsTableTpl">
  <table class="table">
    <tbody>
      {{#compounds}}
      <tr>
        <td class="text-right">{{qty}}</td>
        <td>{{name}}</td>
      </tr>
      {{/compounds}}
    </tbody>
  </table>

</script>
<script>
  var baseUrl = '<?=$baseUrl?>';
  FastClick.attach(document.body);
  $(document).ready(function(){

    select2Simple($('.simpleSearch'));
    $('.datepicker').datetimepicker({
      format            : 'YYYY-MM-DD',
      minDate           : moment(),
      ignoreReadonly    : true,
      showClear         : true
    });

    $('#table').html($('.noContentMsgIn').clone());

    $('#addItem').off('submit').on('submit',function(e) {
      $.ajax({ // create an AJAX call...
        data      : $(this).serialize(), // get the form data
        type      : $(this).attr('method'), // GET or POST
        url       : $(this).attr('action'), // the file to call
        success   : function(result) { // on success..e
          if(result == 'limit'){
            ncmDialogs.alert('Error: El producto puede tener un máximo de 30 compuestos');
          }else if(result == 'noinventory'){
            ncmDialogs.alert('Error: No hay suficientes compuestos para producir ' + units + ' unidades');
          }else if(result == 'true'){
            message('Producción realizada','success');
            $('#addItem')[0].reset();
            $('#table').html($('.noContentMsgIn').clone());
          }else if(result == 'false'){
            message('Error de producción','danger');
          }else if(result == 'nooutlet'){
            ncmDialogs.alert('Debe seleccionar una sucursal donde se realizará la producción');
          }else if(result.error == 'No permissions'){
            ncmDialogs.alert('No posee permisos para realizar esta operación');
          }else{
            $(result).print();
            $('#addItem')[0].reset();
            $('#table').html($('.noContentMsgIn').clone());
          }
          spinner('body', 'hide');
        }
      });
      return false; // cancel original event to prevent form submitting
    });

    switchit(function(tis, active){
      if(active){
        $('#dueDateSelect').show();
      }else{
        $('#dueDateSelect').hide();
      }
    });

    var i = 0;

    onClickWrap("#add",function(tis) {
      i++;
      rowManager(i);
    });

    onClickWrap('#productionBtn',function(event,tis){
      if($('#table').find('.oneRow').length < 1){
        message('Debe añadir productos','danger');
        return false;
      }

      var outletName  = $('[name="outlet"]').find('option:selected').text();
      //var cogs        = countPricesFromCompound();

      confirmation('¿Realizar producción en ' + outletName + '?', function (e) {
        if (e) {
          $('input#orderField').val(0);
          $('#addItem').submit();
          spinner('body', 'show');
          window.onbeforeunload = null;
        }
      });
    });

    onClickWrap('#orderBtn',function(event,tis){

      if($('#table').find('.oneRow').length < 1){
        message('Debe añadir productos','danger');
        return false;
      }

      var outletName  = $('[name="outlet"]').find('option:selected').text();

      confirmation('¿Desea generar una orden de producción?', function (e) {
        if (e) {
          $('input#orderField').val(1);
          $('#addItem').submit();
          spinner('body', 'show');
        }
      });
    });

    onClickWrap(".remove",function(tis) {
      $('#table .oneRow:last').remove();

      $('.btn-status').attr('disabled',false);
      if($('#table oneRow').length < 1){
        $('#table').html( $('.noContentMsgIn').clone() );
        window.onbeforeunload = null;
      }
    });

    var rowManager = function(i){
      $('#table .noContentMsgIn').remove();
      var newtr = ncmHelpers.mustacheIt($('#itemRowTpl'),{index : i},false,true);
      
      $('#table').append(newtr);

      itemSelect2(i);
      masksCurrency($('.units'),thousandSeparator,'no',false,false);
      masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');

      window.onbeforeunload = function(){
        return true;
      };
      
    };
  });

  var itemSelect2 = function(i){
    select2Ajax({
      element   : '.searchAjax',
      url       : '/a_items?action=searchItemStockableInputJson',
      type      : 'item',
      onChange  : function($el,data){
        var id    = $el.val();
        var ndx   = $el.data('index');
        var out   = $('.inOutlet').val();

        itemSelect2();

        $('.capacity' + ndx).text('...');
        $.get(baseUrl + '?action=capacity&id=' + id + '&outlet=' + out,function(result){
          $('.capacity' + ndx).html('<span class="badge">' + result.capacity + '</span>');

          ncmHelpers.mustacheIt($('#compoundsTableTpl'),result,$('.list' + ndx));
          
          /*if(result == 0){
            $('#units' + ndx).val('0').attr('disabled');
          }else{
            $('#units' + ndx).val('1').data('max', parseFloat(result)).removeAttr('disabled');
          }*/

        });
      }
    });
  };

  var calculateTotal = function(id,thousandSeparator,decimal){
    var price = unMaskCurrency($('#price'+id).val(),thousandSeparator,decimal);
    var units = $('#units'+id).val();

    $('#total'+id).text(formatNumber((price*units),currency,decimal,thousandSeparator));
    $('#total'+id).attr("data-raw",price*units);

    var amount = 0;
    $(".totalItem").each(function(i,v){
      amount += Number($(this).attr('data-raw'));
    });

    $("#totalPurchase").val('Comprar '+formatNumber(amount,currency,decimal,thousandSeparator));
    $('.btn-status').attr('disabled',false);
  };
</script>
<?php
include_once('includes/compression_end.php');
dai();
?>
