<?php
include_once('includes/compression_start.php');
require_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/config.php");
include_once("languages/" . LANGUAGE . ".php");
include_once("includes/functions.php");
topHook();
allowUser('items','view');

$baseUrl 		= '/' . basename(__FILE__,'.php');

$roc 			= (OUTLETS_COUNT > 1) ? getROC(1) : getROC(1,1);
$limitDetail	= 100;
$offsetDetail	= 0;
//---------------UI-------------------

if(validateHttp('action') == 'searchItemInputJson'){
  $query  	= db_prepare(validateHttp('q'));
  $queryl  	= strtolower($query);
  $sql    = 'SELECT itemId, itemName, itemSKU, itemUOM, taxId FROM item WHERE (itemName LIKE "%' . $queryl . '%" OR itemSKU LIKE "%' . $queryl . '%") AND ' . $SQLcompanyId . ' AND itemStatus = 1 LIMIT 200';

  $result = ncmExecute($sql,[],false,true);
  $json   = [];

  if($result){
    while (!$result->EOF) {
    	$stock 	= getItemStock($result->fields['itemId']);
        $json[] = [	
        			'name' 	=> toUTF8($result->fields['itemName']),
        			'sname' => toUTF8(strtolower($result->fields['itemName'])),
        			'ssku' 	=> strtolower($result->fields['itemSKU']),
        			'id' 	=> enc($result->fields['itemId']),
        			'uom' 	=> $result->fields['itemUOM'],
        			'cost'	=> formatCurrentNumber($stock['stockOnHandCOGS']),
        			'tax' 	=> enc($result->fields['taxId'])
        		];      
        $result->MoveNext(); 
    }
    $result->Close();
  }

  dai(json_encode($json));
}

if(validateHttp('action') == 'searchItemStockableInputJson'){
  $query  = db_prepare(validateHttp('q'));
  $sql    = 'SELECT itemId, itemName, itemSKU, itemUOM, taxId FROM item WHERE (itemName LIKE "%' . $query . '%" OR itemSKU LIKE "%' . $query . '%") AND itemType IN("product","compound","production") AND itemTrackInventory > 0 AND itemStatus = 1 AND ' . $SQLcompanyId . ' LIMIT 300';



  $result = ncmExecute($sql,[],false,true);
  $json   = [];

  if($result){
    while (!$result->EOF) {
    	$fields = $result->fields;
    	$stock 	= getItemStock($result->fields['itemId']);
        $json[] = [	
        			'name' 	=> toUTF8($fields['itemName']),
        			'sname' => toUTF8(strtolower($fields['itemName'])),
        			'ssku' 	=> toUTF8(strtolower($fields['itemSKU'])),
        			'id' 	=> enc($fields['itemId']),
        			'uom' 	=> toUTF8($fields['itemUOM']),
        			'cost'	=> formatCurrentNumber($stock['stockCOGS']),
        			'tax' 	=> getTaxValue($fields['taxId'])
        		];      
        $result->MoveNext(); 
    }
    $result->Close();
  }

  dai(json_encode($json));
}

if(validateHttp('action') == 'bulkEditForm'){
	?>

	<div class="col-xs-12 no-padder bg-white r-24x clear">

		<form action="<?=$baseUrl;?>?action=bulkUpdate" method="post" id="editItemBulk" name="editItemBulk">
			<div class="col-xs-12 wrapper">
				<div class="h2 text-u-c font-bold wrapper m-b">
					Edición Masiva
					<div class="text-xs text-muted font-normal">
						Todo lo que modifique en esta sección se aplicará automáticamente a todos los artículos y grupos seleccionados
					</div>
				</div>
				

				<div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Precio en <?=CURRENCY?></span>
			    	<input type="text" class="form-control no-padder no-border maskCurrency no-bg b-b" name="sellingPrice" value="" autocomplete="off"/>
			    </div>
			    <div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs text-u-l pointer" data-toggle="tooltip" title="Ajustará el precio actual basado en el porcentaje asignado, ej. si coloca 5, aumentará el precio un 5%, si coloca -10, disminuirá el precio un 10%">Ajuste de Precio en %</span>
			    	<input type="text" class="form-control no-padder no-border no-bg b-b text-right" placeholder="0" name="percentPrice" value="" autocomplete="off">
			    </div>

			    <div class="col-sm-6 m-b">
		          <span class="font-bold text-u-c text-xs">Impuesto</span>
		          <?php 
		            echo selectInputTax(false,false,'no-bg no-border searchSimple b-b b-light m-b',true);
		          ?>
		        </div>
		        
		        <div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Tipo de Precio</span>
			    	<div class="input-group">
			    		<input type="text" class="form-control no-border maskPercentInt no-bg b-b text-right disabled" placeholder="0" name="itemPricePercent" id="itemPricePercent" value="" autocomplete="off" disabled>

			    		<div class="input-group-btn">
			    			<button class="btn btn-default dropdown-toggle" data-toggle="dropdown"> 
			    				<span class="dropdown-label" id="priceType"><b>-</b></span> 
			    				<span class="caret"></span> 
			    				<input class="priceType" type="hidden" value="-" name="priceType">
			    			</button> 
			    			<ul class="dropdown-menu dropdown-select pull-right">
			    				<li class="active priceTypeBtn" data-symbol="-"> 
			    					<a href="#"><b>-</b></a> 
			    				</li> 
			    				<li class="priceTypeBtn" data-symbol="%"> 
			    					<a href="#"><b>%</b></a> 
			    				</li> 
			    				<li class="priceTypeBtn" data-symbol="<?=CURRENCY?>"> 
			    					<a href="#"><b><?=CURRENCY?></b></a> 
			    				</li> 
			    			</ul> 
			    		</div>

			    	</div>
			    </div>

			    <div class="col-sm-6 m-b">
		          <span class="font-bold text-u-c text-xs">Categoría</span>
		          	<?php 
	                  	echo selectInputCategory(enc($result->fields['categoryId']),false,'category no-bg no-border b-b b-light m-b searchSimple needsclick','category',true);
			        ?>
			    	<div class="m-t-xs">
						<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear"  data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
						<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Renombrar"  data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
						<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Remover"  data-toggle="tooltip" data-placement="top"><i class="material-icons text-danger">close</i></a>
					</div>
		      	</div>
			    <div class="col-sm-6 m-b">
		          <span class="font-bold text-u-c text-xs">Marca</span>
					<?php 
						$brand = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "brand" AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC LIMIT '.$plansValues[PLAN]['max_brands'],[],false,true);
					?>
					<select id="brandEdit" name="brand" tabindex="1" data-placeholder="Seleccione una Marca" class="form-control brand no-bg no-border b-b b-light m-b searchSimple needsclick" autocomplete="off">
						<option value="">Seleccionar</option>
						<?php
						if($brand){
							while (!$brand->EOF) {
								$brandId = enc($brand->fields['taxonomyId']);
						?>
								<option value="<?=$brandId;?>">
									<?=$brand->fields['taxonomyName'];?>
								</option>
						<?php 
								$brand->MoveNext(); 
							}
							$brand->Close();
						}
						?>
					</select>
			        <div class="m-t-xs">
				        <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="brand" title="Crear" data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
			    		<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="brand" data-select="brandEdit" title="Renombrar" data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
						<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="brand" data-select="brandEdit" data-toggle="tooltip" data-placement="top" title="Remover"><i class="material-icons text-danger">close</i></a>
					</div>
		        </div>

			    <div class="col-sm-6 m-b">
		    		<span class="font-bold text-u-c text-xs">Tipo</span>
			        <select id="tipo" name="typeOfItem" tabindex="1" data-placeholder="Seleccione un tipo" class="form-control no-bg no-border b-b b-light" autocomplete="off">
			        	<option value="" selected>Seleccionar</option>
			        	<option value="0">Servicio o Producto (Sin Inventario)</option>
			        	<option value="1">Producto (Con Inventario)</option>
			        	<option value="2">Activo Fijo/Compuesto (Con Inventario)</option>
			        	<option value="3">Activo Fijo/Compuesto (Sin Inventario)</option>
			        	<option value="dynamic">Dinámico</option>
			        	<option value="resetstock">Cerar Stock</option>
			        </select>
				</div>	

			    <div class="col-sm-6 m-b">
		        	<span class="font-bold text-u-c text-xs">Sucursal</span>
		        	<?=selectInputOutlet(false,false,'no-bg no-border b-b searchSimple b-light','outlet',true,true);?>
		        </div>

			    <div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Descuento %</span>
			    	<input type="text" class="form-control no-padder no-border maskPercentInt no-bg b-b" placeholder="0" name="discount" value="" autocomplete="off">
			    </div>
			    <div class="col-sm-6 m-b" style="height:68px;">
			    </div>

			    <div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Unidad de Medida</span>
			    	<input type="text" class="form-control no-padder no-border no-bg b-b" placeholder="" name="uom" value="" autocomplete="off">
			    </div>
			    <div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Merma %</span>
			    	<input type="text" class="form-control no-padder no-border maskPercentInt no-bg b-b" placeholder="0" name="waste" value="" autocomplete="off">
			    </div>

			    <div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Comisión</span>
			    	<div class="input-group">
			    		<input type="text" class="form-control no-border maskInteger no-bg b-b text-left" placeholder="0" name="comission" id="itemComission" value="" autocomplete="off">

			    		<div class="input-group-btn">
			    			<button class="btn btn-default no-border dropdown-toggle" data-toggle="dropdown"> 
			    				<span class="dropdown-label" id="comissionType"><b>-</b></span> 
			    				<span class="caret"></span> 
			    				<input class="comissionType" type="hidden" value="-" name="comissionType">
			    			</button> 
			    			<ul class="dropdown-menu dropdown-select pull-right">
			    				<li class="active comissionTypeBtn" data-symbol="-"> 
			    					<a href="#"><b>-</b></a> 
			    				</li> 
			    				<li class="comissionTypeBtn" data-symbol="%"> 
			    					<a href="#"><b>%</b></a> 
			    				</li> 
			    				<li class="comissionTypeBtn" data-symbol="<?=CURRENCY?>"> 
			    					<a href="#"><b><?=CURRENCY?></b></a> 
			    				</li> 
			    			</ul> 
			    		</div>

			    	</div>
			    </div>

			    

			    <div class="col-sm-6 m-b" style="height:68px;">
			    </div>

			    <?php if(SCHEDULE){?>

			    <div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Sesiones</span>
			    	<input type="text" class="form-control no-padder no-border maskPercentInt no-bg b-b" placeholder="" name="sessions" value="" autocomplete="off">
			    </div>

			    <div class="col-sm-6 m-b">
				    <span class="font-bold text-u-c text-xs">Duración min.</span>
			    	<input type="text" class="form-control no-padder no-border maskPercentInt no-bg b-b" placeholder="" name="duration" value="" autocomplete="off">
			    </div>
			    
			    <?php
				}
			    ?>

			    <div class="col-sm-6 m-b">
					<div class="font-bold text-u-c text-xs">Online</div>
					<select id="ecom" name="ecom" tabindex="1" data-placeholder="Seleccionar" class="form-control no-bg no-border b-b b-light" autocomplete="off">
			        	<option value="" selected>Seleccionar</option>
			        	<option value="1">Si</option>
			        	<option value="0">No</option>
			        </select>
                </div>

		    </div>
		    <div class="col-xs-12 wrapper m-t bg-light lter">
			    <input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="Modificar">
			    <a href="#" class="cancelItemView m-r-lg m-t  pull-right">Cancelar</a>
			    <input type="hidden" name="ids" value="" id="bulkUpdateIds">
		    </div>
		</form>

	</div>
	<?php

	dai();
}

if(validateHttp('action') == 'editform' && validateHttp('id')){
	theErrorHandler('json');
	
	$result 			= ncmExecute('SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [dec(validateHttp('id')),COMPANY_ID]);
	$itemId 			= enc($result['itemId']);
	$realType			= iftn($result['itemType'],'product');
	$type 				= $realType;
	$compSetCostprice 	= '0';
	$itemCanSale 		= $result['itemCanSale'];
	$itemDiscount 		= $result['itemDiscount'];
	$itemStatus 		= $result['itemStatus'];
	$itemOnline 		= $result['itemOnline'];
	$itemSessions 		= $result['itemSessions'];
	$itemDuration 		= $result['itemDuration'];
	$isParent 			= $result['itemIsParent'];
	$itemProcedure 		= $result['itemProcedure'];
	$itemTrackInventory = $result['itemTrackInventory'];
	$itemComission		= $result['itemComissionPercent'];
	$itemPricePercent 	= $result['itemPricePercent'];
	$upsellDescription 	= $result['itemUpsellDescription'];
	$productionTools 	= false;
	$inventoryTools 	= false;
	$comboTools 		= false;

	//TYPE
	if($realType == 'product'){
      if($result['itemProduction'] > 0){
        $type 				= 'production';
        $typeName 			= 'Producción Previa';
        $inventoryTools 	= true;
      }else if($result['itemType'] == 'product' && $result['itemTrackInventory'] < 1 && validity(getCompoundsArray($result['itemId'])) ){
        $type 				= 'direct_production';
        $typeName 			= 'Producción Directa';
        $productionTools 	= true;
      }else if($result['itemCanSale']<1){
		$type 				= 'compound';
		$typeName 			= 'Activo/Compuesto';
		$inventoryTools 	= true;
      }else{
      	$typeName 			= 'Producto';
      	$productionTools 	= true;
      	$inventoryTools 	= true;
      }
    }else if($realType == 'precombo'){
    	$typeName 			= 'Combo Predefinido';
    	$comboTools 		= true;
    }else if($realType == 'combo'){
    	$typeName 			= 'Combo Dinámico';
    	$comboTools 		= true;
    }else if($realType == 'comboAddons'){
    	$typeName 			= 'Combo Add-on';
    	$comboTools 		= true;
    	$productionTools 	= false;
    }else if($realType == 'production'){
    	$typeName 			= 'Producción Previa';
    	$productionTools 	= true;
    }else if($realType == 'direct_production'){
    	$typeName 			= 'Producción Directa';
    	$productionTools 	= true;
    }else if($realType == 'dynamic'){
    	$typeName 			= 'Dinámico';
      	$productionTools 	= false;
      	$inventoryTools 	= false;
    }

    if(!$_modules['production']){
    	$productionTools 	= false;
    }

    $inventoryTools 		= true;

    $itemPrice 				= $result['itemPrice'];

    if($result['itemTrackInventory']){
	    $itemStock 				= getItemStock($result['itemId']);

	    if($result['itemPriceType']){//si el precio es porcentual al costo
	    	$cogs = $itemStock['stockCOGS'];
	    	if($result['itemPricePercent'] < 1){
	    		$itemPrice = $cogs;
	    	}else{
	    		$addPrice = ($cogs * $result['itemPricePercent']) / 100;
	    		$itemPrice = $cogs + $addPrice;
	    	}
	    }
	}



	//createInventory($result['itemId']);  
	$letters 			= '';
	$bg 				= 'opacity';
	$img 				= 'https://assets.encom.app/250-250/0/' . enc(COMPANY_ID) . '_' . $itemId . '.jpg?' . date('i');

	if($result['itemCanSale'] > 0 && $result['itemTrackInventory'] < 1){
		// servicio
		$itemTypeOne = 'selected';
	}else if($result['itemCanSale'] > 0 && $result['itemTrackInventory'] > 0){
		//a la venta y con inventario
		$itemTypeTwo = 'selected';
	}else if($result['itemCanSale'] < 1 && $result['itemTrackInventory'] > 0){
		//a la venta y sin inventario
		$itemTypeThree = 'selected';
	}else if($result['itemCanSale'] < 1 && $result['itemTrackInventory'] < 1){
		//no se vende y si inventario
		$itemTypeFour = 'selected';
	}

	if($result['itemType'] == 'dynamic'){
		$itemTypeFive = 'selected';
	}

	if(validateHttp('outcall')){
		$itemTypeTwo = 'selected';
	}

	if($result['itemTrackInventory'] < 1){
		$hideInventoryOps = 'hidden';
	}else{
		$hideInventoryOps = '';
	}

	$hideInventoryOps .= ' animated fadeIn';

	$archiveSelected = ($result['itemStatus'] > 0) ? true : false;
	$reorderSelected = $result['autoReOrder'];
	
	?>

	<div class="modal-body no-padder bg-white">
		<form action="<?=$baseUrl;?>?action=update" method="post" id="editItem" name="editItem">

			<?php
			if($type == 'giftcard'){//giftcards
				$expCount 	= explodes(' ',$result['itemDescription'],true,0);
				$expTime 	= explodes(' ',$result['itemDescription'],true,1);
			?>
				<style type="text/css">.btn-colorselector{border:2px solid #fff;}</style>
				<div class="col-sm-12 wrapper <?=iftn($result['itemSKU'],'gradBgOrange','');?> clear" id="giftBg" style="<?=iftn($result['itemSKU'],'','background:#'.$result['itemSKU']);?>">
		            <div class="col-xs-12 no-padder text-center m-b-md">
		              <div>
		                <i class="material-icons text-white" style="font-size:120px!important;">card_giftcard</i>
		              </div>
		            </div>

		            <div class="col-xs-12 no-padder text-center text-white">
		              Añada un nombre y un monto mínimo inicial
		            </div>

		            <div class="col-sm-8 wrapper-xs m-b">
		              <input type="text" id="insertItemName" class="form-control maskRequiredText no-border b-b b-white no-bg font-bold text-white" style="font-size:25px; height:55px;" name="name" placeholder="Nombre" value="<?=toUTF8($result['itemName']);?>" autocomplete="off">
		            </div>

		            <div class="col-sm-4 wrapper-xs m-b">
		              <input type="text" class="sellingPriceEdit maskCurrency prices text-right form-control no-border b-b b-white no-bg font-bold text-white" name="sellingPrice" value="<?=formatCurrentNumber($itemPrice);?>" autocomplete="off" style="font-size:25px; height:55px;"/>
		            </div>

		            <div class="col-sm-3 col-xs-6 m-b-xs">
			            <div class="form-group m-t-sm">
					        <select id="colorselector_1" name="uid" class="b b-white b-2x">
					          <option value="e57373" data-color="#e57373">e57373</option>
					          <option value="F06292" data-color="#F06292">F06292</option>
					          <option value="BA68C8" data-color="#BA68C8">BA68C8</option>
					          <option value="9575CD" data-color="#9575CD">9575CD</option>
					          <option value="7986CB" data-color="#7986CB">7986CB</option>
					          <option value="64B5F6" data-color="#64B5F6">64B5F6</option>
					          <option value="4FC3F7" data-color="#4FC3F7">4FC3F7</option>
					          <option value="4DD0E1" data-color="#4DD0E1">4DD0E1</option>
					          <option value="4DB6AC" data-color="#4DB6AC">4DB6AC</option>
					          <option value="81C784" data-color="#81C784">81C784</option>
					          <option value="AED581" data-color="#AED581">AED581</option>
					          <option value="DCE775" data-color="#DCE775">DCE775</option>
					          <option value="FFF176" data-color="#FFF176">FFF176</option>
					          <option value="FFD54F" data-color="#FFD54F">FFD54F</option>
					          <option value="FFB74D" data-color="#FFB74D">FFB74D</option>
					          <option value="FF8A65" data-color="#FF8A65">FF8A65</option>
					          <option value="A1887F" data-color="#A1887F">A1887F</option>
					          <option value="" data-color="#E0E0E0" selected="selected">E0E0E0</option>
					          <option value="90A4AE" data-color="#90A4AE">90A4AE</option>
					          <option value="ef5350" data-color="#ef5350">ef5350</option>
					        </select>
					    </div>
					</div>

					<div class="col-sm-3 col-xs-6 m-b-xs">
                      <?php 
			            echo selectInputTax($result['taxId'],false,'no-bg no-border b-b b-light m-b font-bold text-white');
			          ?>
                    </div>

		            <div class="col-sm-3 col-xs-6 m-b-xs" data-toggle="tooltip" title="Duración de esta Gift Card">
		              <input type="text" class="form-control text-right maskInteger no-border b-b b-white no-bg font-bold text-white" name="giftExpCount" value="<?=iftn($expCount,'1')?>" autocomplete="off">
		              <input type="hidden" name="typeOfItem" value="giftcard">
		            </div>

		            <div class="col-sm-3 col-xs-6 m-b-xs">
		              <select name="giftExpTime" class="form-control no-border b-b b-light no-bg font-bold no-bg text-white" autocomplete="off">
		                <option value="year" <?=($expTime=='year')?'selected':''?>>Año/s</option>
		                <option value="month" <?=($expTime=='month')?'selected':''?>>Mes/es</option>
		                <option value="day" <?=($expTime=='day')?'selected':''?>>Día/s</option>
		              </select>
		            </div>

		            <div class="col-xs-12">
		            	<div class="col-sm-3 col-xs-6 no-padder m-b">
							<div class="font-bold text-u-c text-xs">Online</div>
					    	<?=switchIn('ecom',$result['itemEcom'])?>

					    	<div class="font-bold text-u-c text-xs m-t">Destacado</div>
					    	<?=switchIn('featured',$result['itemFeatured'])?>
	                    </div>

	                    <div class="col-sm-4 col-xs-12 no-padder m-b">
			            	<a href="/@#report_inventory?ii=<?=$itemId?>" target="_blank" class="btn text-white text-u-l" data-toggle="tooltip" title="Lleve un control físico de las tarjetas vendidas">
			            		Historial de tarjetas
			            	</a>
			            	<a href="/@#report_products?ii=<?=$itemId?>" target="_blank" class="btn text-white text-u-l" data-toggle="tooltip" title="Reporte detallado de ventas">
			            		Historial de ventas
			            	</a>
			            </div>

			            <div class="col-sm-5 col-xs-12 no-padder m-b">
							<span class="font-bold text-u-c text-xs">Categoría</span>
		                    <?php 
		                      	echo selectInputCategory(enc($result['categoryId']),false,'category no-bg no-border b-b b-light m-b searchSimple');
					        ?>
						    
					    	<div class="m-t-xs">
								<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear"  data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
								<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Renombrar"  data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
				    			<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Remover"  data-toggle="tooltip" data-placement="top"><i class="material-icons text-danger">close</i></a>
				    			<a href="#" class="toggleItemPart btn btn-sm bg-light lter hidden" data-table="category" data-select="category" title="Ver/Ocultar categoría en la caja registradora"  data-toggle="tooltip" data-placement="top"><i class="material-icons">remove_red_eye</i></a>
				    		</div>
			    		</div>
		            </div>

		            <div class="col-xs-12 no-padder m-t-md">
		            	<?php
		            	if(!$archiveSelected){//si es para eliminar
		            	?>
							<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?=$_GET['id']?>" data-type="deleteItem" data-load="<?=$baseUrl;?>?action=delete&id=<?=$_GET['id']?>" data-toggle="tooltip" data-placement="right" title="Eliminar Artículo"><span class="text-white font-bold">Eliminar</span></a>
						<?php
						}else{
						?>
							<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?=$_GET['id']?>" data-type="archiveItem" data-load="<?=$baseUrl;?>?action=archive&id=<?=$_GET['id']?>" data-toggle="tooltip" data-placement="right" title="Archivar Artículo"><span class="text-white font-bold">Archivar</span></a>
						<?php
						}
						?>

					    <input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="Guardar">
					    <a href="#" class="cancelItemView m-r-lg m-t  pull-right">Cancelar</a>
					    <input type="hidden" value="<?=$_GET['id']?>" name="id">
					    <input type="hidden" value="<?=$type?>" name="itemType">
				    </div>

		        </div>

		    <?php
			}else if($type == 'discount'){//descuentos
			?>
				<div class="col-sm-12 wrapper gradBgYellow animateBg clear">
		            <div class="col-xs-12 no-padder text-center">
		              <div>
		                <span class="text-white font-bold" style="font-size:7em!important;">%</span>
		              </div>
		            </div>

		            <div class="col-xs-12 no-padder text-center text-white">
		              Añada un nombre y porcentaje de descuento
		            </div>

		            <div class="col-sm-8 no-padder m-b">
		              <input type="text" id="insertItemName" class="form-control maskRequiredText no-border b-b b-white no-bg font-bold text-white" style="font-size:25px; height:55px;" name="name" placeholder="Nombre" value="<?=toUTF8($result['itemName']);?>" autocomplete="off">
		            </div>

		            <div class="col-sm-4 no-padder m-b">
		              <input type="text" class="sellingPriceEdit maskInteger prices text-right form-control no-border b-b b-white no-bg font-bold text-white" name="sellingPrice" value="<?=formatCurrentNumber($result['itemPrice'],'no');?>" autocomplete="off" style="font-size:25px; height:55px;"/>
		            </div>

		            <div class="col-xs-12 m-b-sm text-left">
		            	<label>Descripción</label>
		            	 <input type="text" class="form-control no-border b-b b-white no-bg font-bold text-white" name="description" value="<?=$result['itemDescription'];?>" autocomplete="off">
		            </div>

		            <div class="col-xs-12 no-padder m-t-md">
		            	
						<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?=$_GET['id']?>" data-type="archiveItem" data-load="<?=$baseUrl;?>?action=archive&id=<?=$_GET['id']?>" data-toggle="tooltip" data-placement="right" title="Archivar Artículo"><span class="text-danger font-bold">Archivar</span></a>

					    <input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="Guardar">
					    <a href="#" class="cancelItemView m-r-lg m-t  pull-right">Cancelar</a>
					    <input type="hidden" value="<?=$_GET['id']?>" name="id">
					    <input type="hidden" value="<?=$type?>" name="itemType">
				    </div>

		        </div>

		    <?php
			}else if($type == 'group' || validity($result['itemIsParent'])){//groups
				$type = 'group';//por si type no es group pero si is parent
			?>
	            <div class="col-xs-12 wrapper text-center bg-info gradBgBlue animateBg">
	              <input type="text" id="insertItemName" class="form-control maskRequiredText text-center no-border b-b b-white no-bg font-bold text-white m-t m-b" style="font-size:25px; height:55px;" name="name" placeholder="Nombre" value="<?=toUTF8($result['itemName']);?>" autocomplete="off">
	            </div>

	            <div class="col-xs-12 bg-white wrapper">

					<div class="list-group alt">
			            <?php 
			            $modifier = ncmExecute('SELECT itemName, itemId, itemPrice, itemType FROM item WHERE itemStatus = 1 AND itemParentId = ' . $result['itemId'] . ' LIMIT 50',[],false,true);

						if($modifier){
							while (!$modifier->EOF) {
								$fields = $modifier->fields;
						?>
						<a class="clickrow pointer row<?=enc($fields['itemId']);?> list-group-item wrapper-md <?=(in_array($fields['itemType'], ['giftcard','group'])) ? 'modal-narrow' : ''?>" id="<?=enc($fields['itemId']);?>">
							<span class="font-bold"><?=toUTF8($fields['itemName'])?></span>

							<span class="pull-right ungroup pointer" data-id="<?=enc($fields['itemId']);?>">
								<i class="material-icons text-danger">close</i>
							</span>

							<div class="pull-right m-r"><?=iftn($fields['itemPrice'],'',formatCurrentNumber($fields['itemPrice']));?></div>
						</a>
						<?php 
								$modifier->MoveNext();
							}
							$modifier->Close();
						}
						?>
					</div>

					
					<div class="col-sm-6">
						<span class="font-bold text-u-c text-xs">Categoría del Grupo</span>
	                    <?php 
	                      	echo selectInputCategory(enc($result['categoryId']),false,'category no-bg no-border b-b b-light m-b searchSimple');
				        ?>
					    
				    	<div class="m-t-xs">
							<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear"  data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
							<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Renombrar"  data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
			    			<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Remover"  data-toggle="tooltip" data-placement="top"><i class="material-icons text-danger">close</i></a>
			    			<a href="#" class="toggleItemPart btn btn-sm bg-light lter hidden" data-table="category" data-select="category" title="Ver/Ocultar categoría en la caja registradora"  data-toggle="tooltip" data-placement="top"><i class="material-icons">remove_red_eye</i></a>
			    		</div>
		    		</div>
                  	
                  	<div class="col-sm-3 col-xs-6 m-b">
						<div class="font-bold text-u-c text-xs">Online</div>
				    	<?=switchIn('ecom',$result['itemEcom'])?>
				    	<div class="font-bold text-u-c text-xs m-t">Destacado</div>
				    	<?=switchIn('featured',$result['itemFeatured'])?>
                    </div>

                    <div class="col-sm-3 col-xs-6 m-b">
						
                    </div>


					<div class="col-xs-12 no-padder m-t-md">
						<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?=$_GET['id']?>" data-type="archiveItem" data-load="<?=$baseUrl;?>?action=delete&id=<?=$_GET['id']?>" data-toggle="tooltip" data-placement="right" title="Archivar Artículo"><span class="text-danger">Eliminar</span></a>

					    <input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="Guardar">
					    <a href="#" class="cancelItemView m-r-lg m-t  pull-right">Cancelar</a>
					    <input type="hidden" value="<?=$_GET['id']?>" name="id">
					    <input type="hidden" value="<?=$type?>" name="itemType">
				    </div>
				</div>
			<?php
			}else{ //PRODUCTO COMBO PRODUCCION
				//precio
				if($result['itemDiscount']>0){
					$displaydiscount	= ($result['itemDiscount'] * $result['itemPrice'])/100;
					$finalPrice 		= $itemPrice - $displaydiscount;
				}else{
					$finalPrice 		= $itemPrice;
				}
			?>

			<div class="col-sm-4 wrapper text-white text-center clear hidden-xs bg-info gradBgBlue animateBg matchCols">
				<a href="#" class="col-xs-12 no-padder m-b-lg itemImageBtn" data-toggle="tooltip" data-placement="top" style="margin-top:40%" title="Subir Imagen">
					<img src="<?=$img?>" width="225" height="225" class="itemImg all-shadows rounded">
				</a>
				<input type="hidden" class="itemImgFlag" name="itemImgFlag" value="<?=file_exists(SYSIMGS_FOLDER.'/'.enc(COMPANY_ID).'_'.$itemId.'.jpg')?1:0;?>">

				<div class="text-right col-xs-12 no-padder">
					<div class="font-bold">
					  <?=toUTF8($result['itemName']);?>
					</div>

					<?php
					if($result['itemCanSale']>0){
					?>
					<div class="font-bold h1">
						<?php
						if($result['itemStatus'] > 0){
							echo CURRENCY.formatCurrentNumber($finalPrice);
						}else{
							echo '<span class="text-u-c text-warning">Archivado</span>';
						}
					  	?>
					</div>
					<?php
					}
					?>
					<em class="">
					 SKU <?=($result['itemSKU']) ? toUTF8($result['itemSKU']) : $itemId;?>
					</em>
					<div class="text-sm">
						<?=toUTF8($result['itemDescription']);?>
					</div>
					<div class="text-sm">
						<span class="badge bg-white text-info"><?=$typeName;?></span>
					</div>

					<?php
					if($result['itemParentId']){
						$parentName = getItemName($result['itemParentId']);
					?>
					<div class="text-sm">
					  Grupo <?=$parentName;?>
					</div>
					<?php
					}
					
					if($result['categoryId']){
					?>
					<div class="text-sm font-bold">
						<?=getTaxonomyName($result['categoryId']);?>
					</div>
					<?php
					}
					
					if($result['brandId']){
					?>
					<div class="text-sm font-bold">
						<?=getTaxonomyName($result['brandId']);?>
					</div>
					<?php
					}
					?>
				</div>
			</div>

			<div class="col-sm-8 no-padder bg-white matchCols">
	            <div class="col-xs-12 no-padder bg-light dk block">
	              <header class="header bg-light lt">
	                <ul class="nav nav-tabs nav-white">
	                  <li class="active">
	                    <a href="#dataTab" class="tabs" title="Información">
		                    <i class="material-icons">list_alt</i>
		                </a>
	                  </li>

	                  <li class="<?=validateHttp('outcall')?'hidden':''?>">
	                    <a href="#settingsTab" class="tabs" title="Ajustes">
		                    <i class="material-icons">settings</i>
		                </a>
		              </li>

		              <?php
						if($productionTools && !validateHttp('outcall')){
					  ?>
	                  <li>
	                    <a href="#productionTab" class="tabs" title="Producción">
		                    <i class="material-icons">build</i>
		                </a>
		              </li>
		              	<?php
						}
						?>

		              	<?php
						if($inventoryTools){
						?>
						<li class="inventoryTools <?=$hideInventoryOps?>">
			            	<a href="#inventoryTab" class="tabs" title="Inventario">
			            		<i class="material-icons">storage</i>
			            	</a>
			            </li>
			            <?php
						}
						?>
						<?php
						if($comboTools){
						?>
						<li>
							<a href="#kitTab" class="tabs" title="Combo">
								<i class="material-icons">layers</i>
							</a>
						</li>

						<li>
							<a href="#upsellTab" class="tabs" title="Upsell">
								<i class="material-icons">loupe</i>
							</a>
						</li>
						<?php
						}
						?>
						<?php
						if($result['itemCanSale'] > 0){					
						?>
						<li data-toggle="tooltip" title="Ver historial de ventas" class="<?=validateHttp('outcall')?'hidden':''?>">
							<a href="/@#report_products?ii=<?=enc($result['itemId'])?>" target="_blank">
								<i class="material-icons">history</i>
							</a>
						</li>

						<li>
							<a href="#dateHoursTab" class="tabs hidden" title="Días y Horas">
								<i class="material-icons">date_range</i>
							</a>
						</li>
						<?php
						}
						?>

						<?php
						if($_modules['dropbox']){					
						?>
						<li>
							<a href="#ncmDBItemFilesTab" class="tabs" title="Archivos">
								<img src="https://aem.dropbox.com/cms/content/dam/dropbox/www/en-us/branding/app-dropbox-android@2x.png" height="19">
							</a>
						</li>
						<?php
						}
						?>

	                </ul>
	              </header>
	            </div>

	            <div class="tab-content">

		            <div class="tab-pane active bg-white col-xs-12 no-padder" id="dataTab" style="min-height: 400px;">
		                <div class="col-xs-12 wrapper-md">
		                	<input type="file" name="image" id="itemImageInput" accept="image/*" style="position:absolute;margin-left:-3000px;" />
							<div class="col-xs-12 no-padder text-center visible-xs">
								<a href="#" class="col-xs-12 no-padder m-b itemImageBtn needsclick" title="Subir Imagen">
									<img src="<?=$img?>" width="225" height="225" class="itemImg rounded">
								</a>
								<div class="m-b">Doble tap para subir una imagen</div>
							</div>

							<input type="text" id="insertItemName" class="form-control maskRequiredText no-padder no-border no-bg font-bold text-dark " style="font-size:30px; height:55px;" name="name" placeholder="Nombre del Artículo" value="<?=toUTF8($result['itemName']);?>" autocomplete="off">

							<div class="col-sm-6">
								<input type="text" class="form-control no-padder no-border no-bg text-muted font-bold text-u-c " name="uid" placeholder="SKU o Código de Barras" value="<?=($result['itemSKU'])?$result['itemSKU']:$result['itemAutoSKU'];?>" autocomplete="off">
							</div>
							<?php
							if($result['itemCanSale']>0){					
							?>
							<div class="col-sm-6">
								<?php
								  	if($result['itemDiscount']>0){
									  echo '<div class="text-danger pull-right badge bg-light lter text-l-t m-r">'.formatCurrentNumber($result['itemPrice']).'</div>';
									}

									$inventoryArray 	= [];
									$COGS 				= 0;
									$margin 			= 0;


									if(validity($finalPrice)){
										if(validity($COGS)){
											$dif 	= $finalPrice - $COGS;
											$margin = ($dif / $finalPrice) * 100;
										}else{
											$margin = 100;
										}
									}

								?>
								<input type="text" class="sellingPriceEdit form-control prices input-lg font-bold text-right maskCurrency no-bg no-border b-b b-3x m-b-xs text-info <?=($result['itemPriceType'])?'disabled':''?>" name="sellingPrice" style="font-size:25px;" value="<?=formatCurrentNumber($finalPrice);?>" placeholder="Precio" autocomplete="off" <?=($result['itemPriceType'])?'disabled':''?>>
							</div>

							<?php
								
									$markup 	= 0;
									$margin 	= 0;

									$COGS 		= $itemStock['stockOnHandCOGS'];
									$grossP 	= $finalPrice - $COGS;

									if($grossP > 0){
										$markup 	= divider($grossP , $COGS,true) * 100;
										$margin 	= divider($grossP , $finalPrice,true) * 100;
									}
								
							?>
								<div class="col-xs-12 no-padder text-sm text-u-c text-muted text-right font-bold">
									Costo: <span class="text-default m-r-sm"><?=CURRENCY . formatCurrentNumber($COGS);?></span>
									Markup: <span class="text-default m-r-sm"><?=formatCurrentNumber($markup,'no');?>%</span>
									Margen: <span class="text-default m-r-sm"><?=formatCurrentNumber($margin,'no');?>%</span>
									Ganancia: <span class="text-default m-r-sm"><?=CURRENCY . formatCurrentNumber($grossP);?></span>
								</div>
							<?php
								
							}
							?>

							<div class="col-xs-12 no-padder m-t-md m-b-lg">
								<label class="font-bold text-u-c text-xs">Descripción</label>
								<textarea class="form-control no-border b-b b-light no-bg text-muted" placeholder="Descripción general" name="description" autocomplete="off"><?=$result['itemDescription'];?></textarea>
							</div>

							<div class="col-xs-12 no-padder m-t">
			                    <div class="col-sm-6 m-b">
			                      <span class="font-bold text-u-c text-xs">Impuesto</span>
			                      <?php 
						            echo selectInputTax($result['taxId'],false,'no-bg no-border searchSimple b-b b-light m-b');
						          ?>
			                    </div>

			                    <div class="col-sm-6 m-b">
			                      <span class="font-bold text-u-c text-xs">Categoría</span>
			                      	<?php
			                      	echo selectInputCategory(enc($result['categoryId']),false,'category no-bg no-border b-b b-light m-b searchSimple','category',true);
				        			?>
							    
							    	<div class="m-t-xs <?=validateHttp('outcall')?'hidden':''?>">
										<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear"  data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
										<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Renombrar"  data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
						    			<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Remover"  data-toggle="tooltip" data-placement="top"><i class="material-icons text-danger">close</i></a>
						    			<a href="#" class="toggleItemPart btn btn-sm bg-light lter hidden" data-table="category" data-select="category" title="Ver/Ocultar categoría en la caja registradora"  data-toggle="tooltip" data-placement="top"><i class="material-icons">remove_red_eye</i></a>
						    		</div>
			                  	</div>
			                </div>
						</div>
					</div>

					<div class="tab-pane bg-white col-xs-12 wrapper" id="settingsTab" style="min-height: 400px;">
						<div class="col-xs-12 no-padder">
							
	                    	<?php
							if(in_array($type, ['product','compound','dynamic'])){
							?>
							<div class="col-sm-6 m-b">
					    		<span class="font-bold text-u-c text-xs">Tipo</span>
						        <select id="tipo" name="typeOfItem" tabindex="1" data-itemid="<?=$_GET['id']?>" data-placeholder="Seleccione un tipo" class="form-control no-bg no-border b-b b-light" autocomplete="off">
						        	<option value="0" <?=$itemTypeOne?>>Servicio o Producto (Sin Inventario)</option>
						        	<option value="1" <?=$itemTypeTwo?>>Producto (Con Inventario)</option>
						        	<option value="2" <?=$itemTypeThree?>>Activo Fijo/Compuesto (Con Inventario)</option>
						        	<option value="3" <?=$itemTypeFour?>>Activo Fijo/Compuesto (Sin Inventario)</option>
						        	<option value="dynamic" <?=$itemTypeFive?>>Dinámico</option>
						        </select>
							</div>	
							<?php
							}
							?>

		                    <div class="col-sm-6 m-b">
		                      <span class="font-bold text-u-c text-xs">Marca</span>
		                      
		                      	<?php 
						        	$brand = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "brand" AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC LIMIT '.$plansValues[PLAN]['max_brands'],[],false,true);
						        ?>
								<select id="brandEdit" name="brand" tabindex="1" data-placeholder="Seleccione una Marca" class="form-control brand no-bg no-border b-b b-light m-b searchSimple needsclick" autocomplete="off">
									<option value="">Seleccionar</option>
									<?php 
									if($brand){
										while (!$brand->EOF) {
											$brandId = enc($brand->fields['taxonomyId']);
									?>
											<option value="<?=$brandId;?>" <?=($brand->fields['taxonomyId'] == $result['brandId'])?'selected':'';?>>
												<?=$brand->fields['taxonomyName'];?>
											</option>
									<?php 
											$brand->MoveNext(); 
										}
										$brand->Close();
									}
									?>
								</select>
						        <div class="m-t-xs <?=validateHttp('outcall')?'hidden':''?>">
							        <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="brand" title="Crear" data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
						    		<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="brand" data-select="brandEdit" title="Renombrar" data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
					    			<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="brand" data-select="brandEdit" data-toggle="tooltip" data-placement="top" title="Remover"><i class="material-icons text-danger">close</i></a>
					    		</div>
		                    </div>

		                    <div class="col-sm-6 m-b hidden">
		                  		<?php
		                  		//Aún no se como implementar
		                  		/*$tags = getAllTags();
		                  		?>
		                  		<span class="font-bold text-u-c text-xs">Etiquetas</span>
			                  	<select multiple class="chosen-select form-control" id="itemTags" data-placeholder="Seleccione Etiquetas">
			                  		<?php
			                  		foreach ($tags as $key => $value) {
			                  		?>
			                  			<option value="<?=enc($key)?>"><?=$value['name']?></option>
			                  		<?php
			                  		}
			                  		?>	
			                  	</select>
			                  	<?php */?>
			                </div>

			                <div class="col-sm-6 m-b">
			                	<span class="font-bold text-u-c text-xs">Sucursal</span>
			                	<?=selectInputOutlet($result['outletId'],false,'no-bg no-border searchSimple b-b b-light','outlet',true);?>
			                </div>

			                <?php
		                    if(in_array($type,['product','production','direct_production'])){
		                    ?>

		                    <div class="col-sm-6 m-b">
								<span class="font-bold text-u-c text-xs">Descuento %</span>
						    	<input type="text" class="form-control no-padder no-border maskPercent no-bg b-b" placeholder="0" name="itemDiscount" value="<?=round($itemDiscount,3)?>" autocomplete="off" <?=($comboTools)?'readonly':''?>>
		                    </div>

		                    <?php
							}
							?>

	                    	<?php
							if(in_array($type,['product','production','compound'])){
							?>
							<div class="col-sm-6 m-b">
								<span class="font-bold text-u-c text-xs">Unidad de medida</span>
					    		<input type="text" class="form-control no-padder no-border no-bg b-b" name="uom" placeholder="Ej. ml" value="<?=$result['itemUOM']?>" autocomplete="off">
							</div>	
							<div class="col-sm-6 m-b">
								<span class="font-bold text-u-c text-xs">Merma %</span>
					    		<input type="text" class="form-control no-padder no-border maskInteger no-bg b-b" name="waste" placeholder="0" value="<?=$result['itemWaste']?>" autocomplete="off">
							</div>	
							<?php
							}
							?>
	                    
		                    <?php
		                    if(in_array($type,['product','production','direct_production'])){
		                    ?>

		                    <div class="col-sm-6 m-b">
								<span class="font-bold text-u-c text-xs">Comisión</span>
								<div class="input-group">
						    		<input type="text" class="form-control no-border maskInteger no-bg b-b text-left" placeholder="0" name="itemComission" value="<?=($result['itemComissionType']) ? formatCurrentNumber($itemComission) : (int)$itemComission;?>" autocomplete="off" id="itemComission">

						    		<div class="input-group-btn"> 
						    			<button class="btn btn-default no-border dropdown-toggle" data-toggle="dropdown"> 
						    				<span class="dropdown-label" id="comissionType"><b><?=($result['itemComissionType'])?CURRENCY:'%'?></b></span> 
						    				<span class="caret"></span> 
						    				<input class="comissionType" type="hidden" value="<?=($result['itemComissionType'])?CURRENCY:'%'?>" name="comissionType">
						    			</button> 
						    			<ul class="dropdown-menu dropdown-select pull-right">
						    				<li class="<?=($result['itemComissionType']) ? '' : 'active'?> comissionTypeBtn" data-symbol="%"> 
						    					<a href="#"><b>%</b></a> 
						    				</li> 
						    				<li class="<?=($result['itemComissionType']) ? 'active' : ''?> comissionTypeBtn" data-symbol="<?=CURRENCY?>"> 
						    					<a href="#"><b><?=CURRENCY?></b></a> 
						    				</li>
						    			</ul>
						    		</div>

						    	</div>
		                    </div>

		                    <div class="col-sm-6 m-b">
								<span class="font-bold text-u-c text-xs">Tipo de Precio</span>
						    	<div class="input-group">
						    		<input type="text" class="form-control no-border maskPercentInt no-bg b-b text-right <?=($result['itemPriceType'])?'':'disabled'?>" placeholder="0" name="itemPricePercent" id="itemPricePercent" value="<?=($result['itemPriceType']) ? $itemPricePercent : '0'?>" autocomplete="off" <?=($result['itemPriceType'])?'':'disabled'?>>

						    		<div class="input-group-btn">
						    			<button class="btn btn-default no-border dropdown-toggle" data-toggle="dropdown"> 
						    				<span class="dropdown-label" id="priceType"><b><?=($result['itemPriceType'])?'%':CURRENCY?></b></span> 
						    				<span class="caret"></span> 
						    				<input class="priceType" type="hidden" value="<?=($result['itemPriceType'])?'%':CURRENCY?>" name="priceType">
						    			</button> 
						    			<ul class="dropdown-menu dropdown-select pull-right">
						    				<li class="<?=($result['itemPriceType']) ? 'active' : ''?> priceTypeBtn" data-symbol="%"> 
						    					<a href="#"><b>%</b></a> 
						    				</li> 
						    				<li class="<?=($result['itemPriceType']) ? '' : 'active'?> priceTypeBtn" data-symbol="<?=CURRENCY?>"> 
						    					<a href="#"><b><?=CURRENCY?></b></a> 
						    				</li> 
						    			</ul> 
						    		</div>

						    	</div>

						    	<div class="font-bold text-u-c text-xs m-t"><?=TAX_NAME?> Incluído</div>
				    			<?=switchIn('taxIncluded',$result['itemTaxExcluded'] ? false : true)?>
						    </div>

		                    <?php
							}
							?>

							<?php if(SCHEDULE){?>
			                    <div class="col-sm-6 m-b">
									<span class="font-bold text-u-c text-xs">Sesiones</span>
							    	<input type="text" class="form-control no-padder no-border maskInteger no-bg b-b" placeholder="" name="itemSessions" value="<?=$itemSessions?>" autocomplete="off">
			                    </div>
			                    <div class="col-sm-6 m-b">
									<span class="font-bold text-u-c text-xs">Duración min.</span>
							    	<input type="text" class="form-control no-padder no-border maskInteger no-bg b-b" placeholder="" name="itemDuration" value="<?=$itemDuration?>" autocomplete="off">
			                    </div>
		                	<?php }?>

		                	<?php if($type != 'dynamic'){?>
		                	<div class="col-sm-6 m-b">
								<div class="font-bold text-u-c text-xs">Online</div>
						    	<?=switchIn('ecom',$result['itemEcom'])?>
		                    </div>

		                    <div class="col-sm-6 m-b">
								<div class="font-bold text-u-c text-xs">Destacado</div>
						    	<?=switchIn('featured',$result['itemFeatured'])?>
		                    </div>
		                    <?php }?>

						</div>
					</div>

					<?php
					if($type == 'combo' || $type == 'comboAddons'){
						$cmpndCts = ncmExecute('SELECT taxonomyId as id, taxonomyName as name FROM taxonomy WHERE taxonomyType = "category" AND '.$SQLcompanyId.' LIMIT '.$plansValues[PLAN]['max_categories'],[],false,true);
						$inpuTComp = json_decode(selectInputCompound($cmpndCts,false,[],'search bg-white'));
					}

					$mask = 'maskFloat3';
					if(in_array($type, ['combo','precombo','comboAddons'])){
						$mask = 'maskFloat';
					}

					//aquí se genera el box para añadir compounds, categorías o lo que se necesite
					$compoundBoxForJs = 	'<div class="TextBoxDiv">' .
											'	<div class="col-sm-6 wrapper-xs">';
											
											if($type == 'combo' || $type == 'comboAddons'){
					$compoundBoxForJs .=			$inpuTComp;											
											}else{
					$compoundBoxForJs .= 	' 		<select name="compid[]" class="form-control bg-white no-border b-b compoundSelect searchAjax" autocomplete="off"></select>';
											}
											
					$compoundBoxForJs .=	'	</div>' .
											'	<div class="col-sm-4 wrapper-xs">' .
											'		<input type="text" class="form-control '.$mask.' text-right no-border b-b no-bg" name="compunits[]" placeholder="Cantidad" data-placement="bottom" value="">' .
											'	</div>' .
											'	<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm">' .
											'   	<span class="badge"></span>' .
											'	</div>' .
											'</div>';
					?>

					<?php
					if($productionTools){
					?>
						<div class="tab-pane bg-white col-xs-12 wrapper" id="productionTab"  style="min-height: 400px;">
							
							<div class="col-xs-12 wrapper">
			                  <div class="col-xs-12 no-padder h4 font-bold text-u-c m-b">
			                  	Tipos de producción 
			                  	<?php
			                  	$productionConf = '';
			                  	if($type == 'production'){
			                  		
			                  	?>
			                  		<a href="/@#bulk_production" target="_blank" class="pull-right btn btn-default btn-rounded font-bold">Producir</a>
			                  	<?php
								}else if($type == 'product'){
									$productionConf = 'hidden';
								}

			                  	?>
			                  </div>

			                  <div class="col-xs-12 no-padder">
			                  	<div class="col-sm-3 no-padder"></div>
			                  	<div class="col-sm-6 no-padder">
			                  		<select class="form-control rounded" id="productionType" name="productionType">
			                  			<option value="3" <?=($type == 'product')?'selected':''?>>Ningún tipo</option>
			                  			<option value="2" <?=($type == 'direct_production')?'selected':''?>>Directa</option>
			                  			<option value="1" <?=($type == 'production')?'selected':''?>>Previa</option>
			                  		</select>
				                </div>
				                <div class="col-sm-3 no-padder"></div>
			                  </div>

			                </div>

			                <div class="col-xs-12 wrapper h4 m-t font-bold text-u-c <?=$productionConf?>">Insumos</div>

							<div class="col-xs-12 <?=$productionConf?>">
								<div class="col-xs-12 wrapper bg-light lter r-3x" id="compoundHolder">
									<?php 
									$obj 		= getCompoundsArray($result['itemId']);

									if($obj){
										foreach ($obj as $resulta){
										    $id 		= $resulta['compoundId'];
										    $units 		= $resulta['toCompoundQty'];

										    $compData 	= ncmExecute('SELECT * FROM item WHERE itemId = ? LIMIT 1',[$id]);
										    $uom 		= $compData['itemUOM'];
										    $name 		= toUTF8($compData['itemName']);
										?>
											<div class="TextBoxDiv">
												<div class="col-sm-6 wrapper-xs">
													<select name="compid[]" class="form-control bg-white no-border b-b compoundSelect searchAjax" autocomplete="off">
														<?php
														if($id){
															?>
															<option value="<?=enc($id);?>"><?=$name;?></option>
															<?php
														}
														?>
													</select>
												</div>
												<div class="col-sm-4 wrapper-xs"> 
													<input type="text" class="form-control text-right no-border b-b no-bg maskFloat3" name="compunits[]" data-toggle="tooltip" data-placement="left" placeholder="Cantidad" value="<?=$units?>">
												</div>
												<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm"><span class="badge"><?=$uom?></span></div>
											</div>
										<?php 

										}
									}
									?>

									<div class="TextBoxDiv">
										<div class="col-sm-6 wrapper-xs"> 
											<select name="compid[]" class="form-control bg-white no-border b-b compoundSelect searchAjax" autocomplete="off"></select>
										</div>
										<div class="col-sm-4 wrapper-xs"> 
											<input type="text" class="form-control text-right no-border b-b no-bg maskFloat3" name="compunits[]"  placeholder="Cantidad" value=""> 
										</div>
										<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm"><span class="badge"></span></div>
									</div>
								</div>
								<div class="col-xs-12 text-center m-t m-b">
									<a href="#" id="addCompound" class="m-r-lg"><span class="text-info font-bold text-u-c">Agregar</span></a>
									<a href="#" id="rmCompound"><span class="font-bold text-u-c">Eliminar</span></a>
								</div>
							</div>

							<div class="col-xs-12 wrapper <?=$productionConf?>">
								<div class="col-xs-12 wrapper bg-light lter r-2x">
									<?php
									$pCOGS 				= getProductionCOGS(dec($itemId));
									$costTitle 			= 'Costo';
									if($_GET['test']){
										echo getProductionCOGS(dec($itemId));
										echo '<br>';
										echo dec($itemId);
										echo '<br>' . '<br>';
									}
									?>
									<div class="col-sm-8 text-u-c font-bold"> 
										<?=$costTitle?>
									</div>
									<div class="col-sm-4 font-bold text-right"> 
										<?=CURRENCY . formatCurrentNumber($pCOGS)?>
									</div>
								</div>
								<div class="col-sm-12 text-sm"> 
									*Para re calcular el costo total, debe guardar los cambios realizados
								</div>
							</div>

							<div class=" <?=$productionConf?>">
				                <div class="col-xs-12 h4 font-bold text-u-c">Procedimiento</div>
				                <div class="col-xs-12 wrapper">
				                  <textarea class="form-control b-light" placeholder="Procedimiento para la elaboración (opcional)" style="height:100px" name="procedure" autocomplete="off"><?=$itemProcedure?></textarea>
				                </div>
				            </div>

			                
						</div>
					<?php
					}else if($comboTools){
					?>
						<div class="tab-pane bg-white col-xs-12 wrapper" id="kitTab"  style="min-height: 400px;">
							
							<div class="col-xs-12 wrapper">
			                  <div class="col-xs-12 no-padder h4 font-bold text-u-c m-b">Tipo de combo</div>

			                  <div class="col-xs-12 no-padder">
			                  	<div class="col-sm-3 no-padder"></div>
			                  	<div class="col-sm-6 no-padder">
			                  		<select class="form-control rounded" name="comboType" id="comboSelector" data-type="<?=$type?>">
			                  			<option value="1" <?=($type == 'precombo')?'selected':''?>>Predefinido</option>
			                  			<option value="2" <?=($type == 'combo')?'selected':''?>>Dinámico</option>
			                  			<option value="3" <?=($type == 'comboAddons')?'selected':''?>>Add-on</option>
			                  		</select>
				                </div>
				                <div class="col-sm-3 no-padder"></div>
			                  </div>

			                </div>

			                <div class="col-xs-12 h4 m-t-md font-bold wrapper text-u-c">Compuestos</div>

							<div class="col-xs-12 no-padder">
								<div class="col-xs-12 wrapper bg-light lter r-3x" id="compoundHolder">
								<?php
								if($type == 'precombo'){
								
									$obj 		= getCompoundsArray($result['itemId']);

									if($obj){//si ya tiene cargados
										
										$comboCOGS 	= getComboCOGS($result['itemId']);

										$allCats 	= getAllItemCategories();
											
										foreach ($obj as $resulta){
										    $id 		= $resulta['compoundId'];
										    $units 		= number_format($resulta['toCompoundQty'],2);//dejo en 2 ceros

											$compData 	= ncmExecute('SELECT * FROM item WHERE itemId = ? LIMIT 1',[$id]);
											$uom 		= $compData['itemUOM'];
										    $name 		= toUTF8($compData['itemName']);
										    $price 		= $compData['itemPrice'];
																								
								?>
											<div class="TextBoxDiv">
												<div class="col-sm-6 wrapper-xs">
													<select name="compid[]" class="form-control bg-white no-border b-b compoundSelect searchAjax" autocomplete="off">
														<?php
														if($id){
														?>
															<option value="<?=enc($id);?>"><?=$name;?></option>
														<?php
														}
														?>
													</select>
												</div>
												<div class="col-sm-4 wrapper-xs"> 
													<input type="text" class="form-control text-right no-border b-b no-bg maskFloat" name="compunits[]" data-units="<?=$units?>"  value="<?=$units?>"> 
												</div>
												<div class="col-sm-2 wrapper-xs uom m-t-sm">
													<span class="badge"><?=$uom?></span>
												</div>
											</div>
								<?php 
										}
										
									}
								?>

									<div class="TextBoxDiv">
										<div class="col-sm-6 wrapper-xs">
											<select name="compid[]" class="form-control bg-white no-border b-b compoundSelect searchAjax" autocomplete="off"></select>
										</div>
										<div class="col-sm-4 wrapper-xs"> 
											<input type="text" class="form-control text-right no-border b-b no-bg maskFloat" name="compunits[]"  placeholder="Cantidad" value=""> 
										</div>
										<div class="col-sm-2 wrapper-xs uom font-bold uom m-t-sm"><span class="badge"></span></div>
									</div>
								
								<?php
								}else{
									$obj 		= getCompoundsArray($result['itemId']);

									if($obj){
										
										$comboCOGS 			= getComboCOGS($result['itemId']);
										$compidPreselected 	= '';
											
										foreach ($obj as $resulta){
											if($resulta['toCompoundQty'] < 0){
												//detecto el preselected que tiene como cantidad -1
												$compidPreselected = $resulta['compoundId'];
												continue;
											}

										    $id 		= $resulta['compoundId'];
										    $units 		= number_format($resulta['toCompoundQty'],2);//dejo en 2 ceros
											$options 	= json_decode(selectInputCompound($cmpndCts,$id,[],'search bg-white')); //vuelvo a generar para match los seleccionados
											$name 		= $allCats[$id]['name'];
											$uom 		= '';
											
											?>
											<div class="TextBoxDiv">
												<div class="col-sm-8 wrapper-xs">
													<?php
													// si es combo dinamico muestro input de categorias
													echo $options;
													?>
												</div>
												<div class="col-sm-4 wrapper-xs"> 
													<input type="text" class="form-control text-right no-border b-b no-bg maskFloat" name="compunits[]" data-units="<?=$units?>"  value="<?=$units?>"> 
												</div>
											</div>
											<?php 
										}											
									}
									?>
									<div class="TextBoxDiv">
										<div class="col-sm-8 wrapper-xs">
											<?php
											// si es combo dinamico muestro input de categorias
											echo $inpuTComp;
											?>
										</div>
										<div class="col-sm-4 wrapper-xs"> 
											<input type="text" class="form-control text-right no-border b-b no-bg maskFloat" name="compunits[]"  placeholder="Cantidad" value=""> 
										</div>
									</div>
								<?php
								}
								?>

								</div>
								<div class="col-xs-12 text-center m-t m-b">
									<a href="#" id="addCompound" class="m-r-lg"><span class="text-info font-bold text-u-c">Agregar</span></a>
									<a href="#" id="rmCompound"><span class="font-bold text-u-c">Eliminar</span></a>
								</div>

							</div>

							<?php
							if($type == 'precombo'){
							?>
							<div class="col-xs-12 wrapper">
								<div class="col-xs-12 wrapper bg-light lter r-3x">
									<div class="col-sm-8 font-bold"> 
										COSTO TOTAL
									</div>
									<div class="col-sm-4 font-bold text-right"> 
										<?=CURRENCY.formatCurrentNumber($comboCOGS)?>
									</div>
								</div>
								<div class="col-sm-12 text-sm"> 
									*Para re calcular el costo total, debe guardar los cambios realizados
								</div>
							</div>
							<?php
							}else{
							?>
							<div class="col-xs-12">
								<div class="col-sm-4 wrapper-xs">
									<span class="font-bold text-sm text-u-c">Pre seleccionado</span>
								</div>
								<div class="col-sm-8 wrapper-xs"> 
									<select name="compidPreselected" class="form-control bg-white no-border b-b searchAjax" autocomplete="off">
										<?php
										if($compidPreselected){
											$name = getItemName($compidPreselected);
											echo '<option value="' . enc($compidPreselected) . '">' . $name . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<?php
							}
							?>

						</div>

						<div class="tab-pane bg-white col-xs-12 wrapper" id="upsellTab"  style="min-height: 400px;">
							<div class="col-xs-12 wrapper h4 font-bold text-u-c">Upselling</div>
							<div class="col-xs-12 m-b-sm">Seleccione los artículos que recomendarán este combo</div>
							<div class="col-xs-12 m-b">
								<select name="upsell[]" id="upsell[]" class="form-control m-b input-lg searchAjax no-border b-b" multiple="" tabindex="-1">
					            <?php 
					            $upsells    	= ncmExecute('SELECT GROUP_CONCAT(upsellChildId) as names FROM upsell WHERE upsellParentId = ?',[dec($itemId)]);
					            
					            if($upsells){
					                $upsellOps 	= ncmExecute('SELECT itemName, itemId FROM item WHERE itemId IN(' . $upsells['names'] . ')',[],false,true);
					                if($upsellOps){
						                while (!$upsellOps->EOF) {
						                	echo '<option value="' . enc($upsellOps->fields['itemId']) . '" selected>' . toUTF8($upsellOps->fields['itemName']) . '</option>';
						                	$upsellOps->MoveNext();
						                }
						            }
					            }
					            ?>
					            </select>
					        </div>

					        <div class="col-xs-12 m-b m-t">
					        	<div class="col-xs-12 h4 wrapper m-b-sm">Añada una descripción a la recomendación de este combo</div>
					        	<textarea class="form-control" name="upsellDescription"><?=$upsellDescription;?></textarea>
					        </div>
						</div>
						
					<?php
					}
					?>
					<?php
					if($inventoryTools){
						if(OUTLET_ID < 1){
							$outlet 		= ncmExecute("SELECT outletId, outletName FROM outlet WHERE outletStatus = 1 AND companyId = ?",[COMPANY_ID],false,true);
						}else{
							$outlet 		= ncmExecute("SELECT outletId, outletName FROM outlet WHERE outletStatus = 1 AND companyId = ? AND outletId = ?",[COMPANY_ID,OUTLET_ID],false,true);
						}
						
						$invList 		= '';
						$totalUnits 	= 0;
						$stockTriggerTotal = 0;

						if($outlet){
					        while (!$outlet->EOF) {
					        	$fields 	= $outlet->fields;
					        	$bg 		= 'bg-light ';
					        	$count 		= 0;

					        	$inventory 	= getItemStock(dec($_GET['id']),$fields['outletId']);
					        	$count 		= formatQty($inventory['stockOnHand']);
					        	
					        	if($count <= 0){
					        		$bg 	= 'bg-danger ';
					        	}

					        	$invList .= '<tr>' .
											'	<td class="font-bold">' . $fields['outletName'] . '</td>' .
											' 	<td>Stock Mínimo</td>' .
											'	<td class="text-right"><span class="label ' . $bg . ' lter">' . $count . '</span></td>' .
											'</tr>';

								$depo = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'location' AND outletId = ? ORDER BY taxonomyName ASC",[$fields['outletId']],false,true);
								if($depo){
									$dTotal = 0;
									while (!$depo->EOF) {
										$dCount = 0;
										$depCount = ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',[$depo->fields['taxonomyId'],dec($itemId)]);

										if($depCount){
											$dCount = $depCount['toLocationCount'];
										}

										$dTotal += $dCount;

										$sTrigger = ncmExecute('SELECT * FROM stockTrigger WHERE outletId = ? AND itemId = ? LIMIT 1',[$depo->fields['taxonomyId'],dec($_GET['id'])]);

										$invList .= 	'<tr>' .
														'	<td><spa class="m-l-md">' . $depo->fields['taxonomyName'] . '</span></td>' .
														'	<td>' .
														'		<input type="text" class="form-control maskFloat text-right input-md no-border no-bg b-b" placeholder="0" name="stocktrigger[]" value="' . formatCurrentNumber($sTrigger['stockTriggerCount'],'si',false,'2') . '" autocomplete="off" placeholder="Cantidad" data-placement="top"/>' . 
														'		<input type="hidden" class="hidden" name="stocktriggerLocation[]" value="' . enc($depo->fields['taxonomyId']) . '">' .
														'	</td>' .
														'	<td class="text-right">' .
														'		<span class="label bg-light lter">' . 
																	formatQty($dCount) . 
														'		</span>' .
														'	</td>'.
														'</tr>';

										$count 	= $count - $dTotal;

										$depo->MoveNext();
									}
								}

								$sTrigger = ncmExecute('SELECT * FROM stockTrigger WHERE outletId = ? AND itemId = ? LIMIT 1',[$fields['outletId'],dec($_GET['id'])]);

								$invList .= 	'<tr>' .
												'	<td><span class="m-l-md">Principal</span></td>' .
												'	<td>' .
												'		<input type="text" class="form-control maskFloat text-right input-md no-border no-bg b-b" placeholder="0" name="stocktrigger[]" value="' . formatCurrentNumber($sTrigger['stockTriggerCount'],'si',false,'2') . '" autocomplete="off" placeholder="Cantidad" data-placement="top"/>' . 
												'		<input type="hidden" class="hidden" name="stocktriggerLocation[]" value="' . enc($fields['outletId']) . '">' .
												'	</td>' .
												'	<td class="text-right">' .
												'		<span class="label bg-light lter">' . 
															formatQty($count) . 
												'		</span>' .
												'	</td>'.
												'</tr>';

								$totalUnits 		+= $inventory['stockOnHand'];
								$stockTriggerTotal 	+= $sTrigger['stockTriggerCount'];

					        	$outlet->MoveNext();
					        }
					        $outlet->Close();
					    }
					?>
						<div class="tab-pane bg-white col-xs-12 wrapper" id="inventoryTab"  style="min-height: 400px;" data-out="<?=OUTLET_ID?>">
							
							<?php
							if(OUTLET_ID > 0){
							?>
							<div class="col-xs-12 text-center m-b-lg r-24x bg-light lter">
							    <div class="col-sm-4 b-r b-light m-b m-t">
							        <div class="text-sm">Precio de Compra</div>
							        <div class="h3 font-bold"><?=CURRENCY . formatCurrentNumber($itemStock['stockCOGS']);?></div>
							    </div>
							    <div class="col-sm-4 b-r b-light m-b m-t">
							    	<div class="text-sm">Costo Promedio</div>
							        <div class="h3 font-bold"><?=CURRENCY . formatCurrentNumber($itemStock['stockOnHandCOGS']);?></div>
							    </div>
							    <div class="col-sm-4 m-b m-t">
							    	<div class="text-sm">Valor total del stock</div>
							        <div class="h3 font-bold"><?=CURRENCY . formatCurrentNumber($itemStock['stockOnHandCOGS'] * $itemStock['stockOnHand']);?></div>
							    </div>
							</div>
							<?php
							}
							?>

							<div class="col-xs-12 wrapper gradBgGreen hidden r-24x m-b addRemoveStockBlocks animated fadeIn" id="addStock">
								<div class="col-sm-4">
									<span class="text-xs text-u-c font-bold">
										Cantidad
									</span>
									<input type="text" name="addcount" class="form-control no-border no-bg b-b text-right text-white maskFloat3" placeholder="" value=""  id="addStockCount">
								</div>
								<div class="col-sm-4">
									<span class="text-xs text-u-c font-bold">
										Costo Unitario
									</span>
									<input type="text" name="addcost" class="form-control no-border no-bg b-b text-right text-white maskCurrency" placeholder="" value="" id="addCogsCount">
								</div>
								<div class="col-sm-4">
									<a href="<?=$baseUrl;?>?action=stockAdd&id=<?=$itemId?>" class="btn btn-default btn-block m-t btn-rounded font-bold text-u-c" id="btnAddStockSubmit">Añadir</a>
								</div>
							</div>

							<div class="col-xs-12 wrapper gradBgOrange hidden r-24x m-b addRemoveStockBlocks animated fadeIn" id="removeStock">
								<div class="col-sm-4">
									<span class="text-xs text-u-c font-bold">
										Cantidad
									</span>
									<input type="text" class="form-control no-border no-bg b-b text-right text-white maskFloat3" placeholder="" value="" id="removeStockCount">
								</div>
								<div class="col-sm-4">
								</div>
								<div class="col-sm-4">
									<a href="<?=$baseUrl;?>?action=stockRemove&id=<?=$itemId?>" class="btn btn-default btn-block m-t btn-rounded font-bold text-u-c" id="btnRemoveStockSubmit">Remover</a>
								</div>
							</div>

							<div class="col-xs-12 wrapper-lg text-center hidden r-24x m-b addRemoveStockBlocks animated fadeIn" id="successStock">
								
								<i class="material-icons text-info" style="font-size: 3em !important;">check</i>
							</div>

							<div class="col-xs-12 no-padder m-b-lg" style="min-height:158px;">
								<div class="col-sm-5 col-xs-12 no-padder text-center hidden-xs">
								  <canvas id="chart-inventory" class="" height="200" style="max-height:200px;"></canvas>
								  <div class="donut-inner" style=" margin-top: -120px;">
								    <div class="h1 m-t total font-bold"><?=$totalUnits?></div>
								    <span>Total</span>
								  </div>
								</div>
								<script type="text/javascript">
									var totalU = <?=$totalUnits?>;
									$(document).ready(function(){
										Chart.defaults.global.responsive 			= true;
										Chart.defaults.global.maintainAspectRatio 	= true;
										Chart.defaults.global.legend.display 		= false;

										totalU = (totalU > 0) ? totalU : 0;
										var methods = new Chart($('#chart-inventory'), {
											type: 'doughnut',
											data: {
											  labels: ['Mínimo', 'Stock'],
											  datasets: [{
											    label: "Inventario",
											    data: [<?=$stockTriggerTotal;?>, totalU],
											    backgroundColor: ['#e0eaec','#61D5AF']
											  }]
											},
											animation: true,
											options: {
											  cutoutPercentage: 85
											}
										});
									});
								</script>

								<div class="col-sm-7 col-xs-12 no-padder">
									
									<div class="col-xs-12 wrapper b-b">
										<div class="font-bold text-u-c text-xs">Ajustes e historial</div>
										<div class="btn-group"> 
											<a href="#" class="btn btn-default font-bold btn-rounded <?=(OUTLET_ID < 1)?'disabled':''?>" id="btnAddStock" data-toggle="tooltip" title="Añadir Stock"  <?=(OUTLET_ID < 1)?'disabled':''?>>
												<i class="material-icons text-success">add</i>
											</a>
											<a href="#" class="btn btn-default font-bold btn-rounded <?=(OUTLET_ID < 1)?'disabled':''?>" id="btnRemoveStock" data-toggle="tooltip" title="Remover Stock"  <?=(OUTLET_ID < 1)?'disabled':''?>>
												<i class="material-icons text-danger">remove</i>
											</a>
											<a href="/@#report_inventory?ii=<?=$itemId?>" target="_blank" class="btn btn-default btn-rounded text-u-c font-bold">
												Historial
											</a>
										</div>
									</div>

									<div class="col-xs-12 wrapper">
										<?php
										if(OUTLET_ID > 0 && 1 == 2){
										?>
										<span class="font-bold text-u-c text-xs">Depósito Asignado</span>
										<select name="defaultLocation" class="form-control no-border b-b no-bg">
											<option value="<?=enc(OUTLET_ID)?>">Principal</option>
											<?php
											$dLocation = ncmExecute('SELECT * FROM taxonomy WHERE taxonomyType = "location" AND outletId = ?',[OUTLET_ID],false,true);
											if($dLocation){
												while (!$dLocation->EOF) {

													?>
													<option value="<?=enc($dLocation->fields['outletId'])?>"><?=$dLocation->fields['taxonomyName']?></option>
													<?php
													$dLocation->MoveNext();
												}
											}
											?>
										</select>
										<?php
										}
										?>
									</div>
														  	

								  <div class="col-sm-6 col-xs-12 no-padder m-b hidden">
								    <div class="m-t-sm">
								      Auto ordenar
								    </div>
								  </div>
								  <div class="col-sm-6 col-xs-12 no-padder m-b hidden">
								    <span class="pull-right">
										<?=switchIn('autoorder',$reorderSelected)?>
							        </span>
								  </div>

								  <div class="col-sm-6 col-xs-12 no-padder m-b hidden">
								    <div class="m-t-sm">
								      Unidades a reordenar <i class="icon-question text-muted text-xs" data-toggle="tooltip" title="" data-original-title="Ingrese la cantidad de unidades que desea que el sistema ordene automáticamente"></i>
								    </div>
								  </div>

								  <div class="col-sm-6 col-xs-12 no-padder m-b hidden">
								    <input type="text" class="form-control maskInteger text-right input-md no-border no-bg b-b" placeholder="0" name="autoordercount" value="<?=$result['autoReOrderLevel']?>" autocomplete="off" placeholder="Cantidad" data-placement="top"/>
								  </div>
								</div>
							</div>

							<div class="col-xs-12 wrapper h5 font-bold text-u-c">Stock por sucursal</div>
							<div class="col-xs-12 b r-24x panel">
								<table class="table">
									<tbody>
										<?=$invList;?>
									</tbody>
								</table>
							</div>

							</div>
						</div>
					<?php
					}
					?>

					<div class="tab-pane bg-white col-xs-12 wrapper" id="ncmDBItemFilesTab"  style="min-height: 400px; display: none;"></div>

					<div class="tab-pane bg-white col-xs-12 wrapper" id="dateHoursTab"  style="min-height: 400px; display: none;">
						<div class="col-xs-12 wrapper h4 text-u-c font-bold">Días y horarios disponibles</div>
						<div class="col-xs-12 m-b-sm">Seleccione los días y rango de horas disponibles para la venta de este producto</div>
						<div class="hidden businessHoursConfig">
							<?php
							  if($result['itemDateHour']){
							    echo stripslashes( $result['itemDateHour'] );
							  }else{
							    ?>
							    [{"isActive":true,"timeFrom":"01:00","timeTill":"23:59"},{"isActive":true,"timeFrom":"01:00","timeTill":"23:59"},{"isActive":true,"timeFrom":"01:00","timeTill":"23:59"},{"isActive":true,"timeFrom":"01:00","timeTill":"23:59"},{"isActive":true,"timeFrom":"01:00","timeTill":"23:59"},{"isActive":true,"timeFrom":"01:00","timeTill":"23:59"},{"isActive":true,"timeFrom":"01:00","timeTill":"23:59"}]
							    <?php
							  }
							?>
						</div>
						<input type="hidden" class="hidden businessHours" name="businessHours">
						<div class="businessHours"></div>
					</div>

					<div class="col-xs-12 wrapper bg-light lter">
						<?php
		            	if($result['itemStatus'] < 1){//si es para eliminar
		            	?>
							<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?=$_GET['id']?>" data-type="deleteItem" data-load="<?=$baseUrl;?>?action=delete&id=<?=$_GET['id']?>" data-toggle="tooltip" data-placement="right" title="Eliminar Artículo">
								<span class="text-danger">Eliminar</span>
							</a>
						<?php
						}else{
						?>
							<a href="#" class="pull-left m-t m-l itemsAction <?=validateHttp('outcall')?'hidden':''?>" data-id="<?=$_GET['id']?>" data-type="archiveItem" data-load="<?=$baseUrl;?>?action=archive&id=<?=$_GET['id']?>" data-toggle="tooltip" data-placement="right" title="Archivar Artículo">
								<span class="text-danger">Archivar</span>
							</a>
						<?php
						}
						?>

						<input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="<?=($result['itemStatus'] < 1) ? 'Re Activar' : 'Guardar';?>">

					    <a href="#" class="cancelItemView m-r-lg m-t  pull-right">Cancelar</a>
					    <input type="hidden" value="<?=$_GET['id']?>" name="id">
					    <input type="hidden" value="<?=$type?>" name="itemType">
				    </div>

				</div>
	        </div>

	        <?php
	    	}
	        ?>			
		</form>

	</div>
	
	<script>
		$(document).ready(function(){
			

			<?php
			if($type == 'giftcard'){
				if($result['itemSKU']){
			?>
				$('#giftBg').removeClass('gradBgOrange').css({backgroundColor:'#<?=$result['itemSKU']?>'});
			<?php
				}
			?>
			$('#colorselector_1').colorselector("setColor","#<?=$result['itemSKU'];?>");
			$('.dropdow-colorselector .dropdown-toggle').addClass('b b-white b-2x');
			$('#colorselector_1').on('change',function(){
				var color = $(this).val();
				$('#giftBg').removeClass('gradBgOrange').css({backgroundColor:'#' + color});
			});
			<?php
			}
			?>
			
			//if(compSetCostprice > 0 && $('.costPriceEdit').data('value') <= 0){
				//$('.costPriceEdit').val(compSetCostprice);
			//}

			window.compBoxesList = '<?=$compoundBoxForJs;?>';

			
		});
	</script>

	<?php
	include_once('includes/compression_end.php');
	dai();           
}

if(validateHttp('action') == 'formCSV'){
	?>
	<div class="modal-body modal-body no-padder clear gradBgBlue animateBg">
		<form action="<?=$baseUrl;?>?action=importCSV" method="POST" id="csvForm" name="csvForm" enctype="multipart/form-data">
			<div class="col-xs-12 wrapper">
				<h2 class="font-bold">Carga masiva de artículos</h2>
				<div class="text-md">
					1. Descargue el modelo desde <a href="<?=$baseUrl;?>?action=csvModel" class="text-info" target="_blank"><span class="text-warning font-bold">aquí</span></a>
					<br>
					2. Complete los campos del archivo descargado
					<br>
					3. Suba el archivo en el botón de abajo
				</div>
				<input name="csv" type="file" class="form-control btn btn-default btn-rounded m-t" />
				<input type="hidden" name="MAX_FILE_SIZE" value="8M">
			</div>
			<div class="col-xs-12 wrapper bg-light lter text-right">
				<input type="checkbox" name="isUpdate" value="edit">
				<a href="#" class="m-t m-r" data-dismiss="modal" aria-hidden="true">Cancelar</a>
				<input class="btn btn-lg btn-info btn-rounded text-u-c font-bold" type="submit" value="Subir">
			</div>
		</form>
	</div>
			
		
	<?php
	dai();
}

//---------------UI-------------------//


//---------------PROCESS-------------------
//Insertar Producto
if(validateHttp('action') == 'insertBtn'){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	if(checkPlanMaxReached('item',$plansValues[PLAN]['max_items'])){
	    dai('max');
	}
	
	$name = 'Nuevo Artículo';
	
	$record = [];

	if(validateHttp('discount')){
		$name = 'Nuevo Descuento';
		$record['itemType'] = 'discount';
	}else if(validateHttp('combo')){
		$name = 'Nuevo Combo';
		$record['itemType'] = 'combo';
	}else if(validateHttp('giftcard')){
		$name 							= 'Gift Card';
		$record['itemType'] 			= 'giftcard';
		$record['itemTrackInventory'] 	= 1;
		$record['itemDescription'] 		= '1 year';
	}

	$record['itemName'] 		= $name;
	$record['itemTaxExcluded']	= 0;
	$record['itemDate'] 		= TODAY;
	$record['companyId'] 		= COMPANY_ID;
	$record['updated_at']		= TODAY;
	
	$insert = $db->AutoExecute('item', $record, 'INSERT'); 
	if($insert === false){
		echo 'false';
	}else{
		
		$itemId = $db->Insert_ID();

		//insertBlankInventoryinAllOutlets($itemId,$SQLcompanyId);//importante si no hago esto y alguien añade un item sin inventario se arma un quilombo en la app porque el index de los articulos son relativos al index del inventario (esto tiene que cambiar con el nuevo inventario debido a los multiples inventarios o lotes)
		
		echo 'true|0|'.enc($itemId);
		updateLastTimeEdit(false,'item');
	}
	dai();
}

//Editar Producto
if(validateHttp('action') == 'update' && validateHttp('id','post')){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	if(!validateHttp('name','post')){
		dai('El nombre es obligatorio');
	}

	$record 		= [];

	$sku 			= validateHttp('uid','post');
	$name 			= validateHttp('name','post');
	$price 			= formatNumberToInsertDB($_POST['sellingPrice']);

	$itemComission 		= validateHttp('itemComission','post');
	if(validateHttp('comissionType','post') == '%'){
		$itemComission 	= ($itemComission > 100) ? 100 : $itemComission;
		$itemComission 	= ($itemComission < 0) ? 0 : $itemComission;

		$comissionType 	= '0';
	}else{
		$comissionType 	= '1';
	}

	$itemComission 		= formatNumberToInsertDB($itemComission);

	$itemPricePercent 	= validateHttp('itemPricePercent','post');
	if(validateHttp('priceType','post') == '%'){
		$itemPricePercent 	= ($itemPricePercent > 100) ? 100 : $itemPricePercent;
		$itemPricePercent 	= ($itemPricePercent < 0) ? 0 : $itemPricePercent;

		$itemPriceType 		= 1;
	}else{
		$itemPriceType 		= NULL;
		$itemPricePercent 	= 0;
	}	

	$cogs 			= 0;
	$description 	= validateHttp('description','post');
	$brand 			= iftn($_POST['brand'],NULL,dec($_POST['brand']));
	$category 		= iftn($_POST['category'],NULL,dec($_POST['category']));
	$itemTags 		= json_encode(explodes(',',$_POST['itemtags']));
	$itemSessions 	= iftn($_POST['itemSessions'],NULL);
	$itemDuration 	= iftn($_POST['itemDuration'],NULL);
	
	$id 			= dec(validateHttp('id','post'));	

	$comboType 		= validateHttp('comboType','post');
	$isCombo		= $comboType ? true : false;

	$productionType = validateHttp('productionType','post');
	$isProduction	= $productionType ? true : false;
	
	$comboCOGS 		= (validateHttp('comboCOGS','post'))?formatNumberToInsertDB($_POST['comboCOGS']):0;
	$deleteCompounds = false;

	$archive 		= ($_POST['archive'] == '1')?'0':'1';//es asi porque para archivar tiene que estar en 0 y el check no anda si envio 
	$ecom 			= validateHttp('ecom','post') ? 1 : 0;
	$featured 		= validateHttp('featured','post') ? 1 : 0;
	$autoorder 		= iftn($_POST['autoorder'],0);
	$autoordercount = $_POST['autoordercount'];
	$inventorycountmethod = $_POST['inventorycountmethod'];
	$tax 			= dec($_POST['tax']);
	$taxIncluded 	= validateHttp('taxIncluded','post') ? 0 : 1;
	$outlet			= ($_POST['outlet']=='all') ? NULL : dec(validateHttp('outlet','post'));
	$uom			= validateHttp('uom','post');
	$waste			= validateHttp('waste','post');
	$ptype 			= validateHttp('itemType','post');
	$businessHours  = json_decode(json_encode(validateHttp('businessHours','post')));
	$businessHours 	= ($businessHours) ? $businessHours : null;
	
	$itemDiscount 	= iftn(formatNumberToInsertDB($_POST['itemDiscount'],true,3),NULL);
	$procedure 		= validateHttp('procedure','post');//procedimiento

	//Defino si esta a la venta y si hay que track el inventario
	if($_POST['typeOfItem'] == 0){
		// servicio
		$canSell 		= 1;
		$trackInventory = 0;
		$type 			= 'product';
	}else if(validateHttp('typeOfItem','post') == 1){
		//a la venta y con inventario
		$canSell 		= 1;
		$trackInventory = 1;
		$type 			= 'product';
	}else if(validateHttp('typeOfItem','post') == 2){
		//no se vende y si inventario
		$canSell 		= 0;
		$trackInventory = 1;
		$type 			= 'compound';
	}else if(validateHttp('typeOfItem','post') == 3){
		//no se vende y no inventario
		$canSell 		= 0;
		$trackInventory = 0;
		$type 			= 'compound';
	}else if(validateHttp('typeOfItem','post') == 'dynamic'){
		// servicio dinamico
		$canSell 		= 1;
		$trackInventory = 0;
		$isProduction 	= false;
		$isCombo 		= false;
		$isDynamic 		= true;
		$type 			= 'dynamic';
	}else{
		$canSell 		= 1;
		$trackInventory = 0;
	}

	if($isProduction && $productionType == 3){//elimino produccion o no posee permisos en el plan
		$isProduction 		= false;
		$procedure  		= NULL;
		$productionType 	= NULL;
		$type 				= 'product';
		$deleteCompounds 	= true;
	}else if($isProduction && $productionType == 1){//prod previa
		$productionType 	= 1;
		$isProduction 		= true;
		$deleteCompounds	= false;
		$type 				= 'production';
		$trackInventory 	= 1;
		$canSell 			= 1;
	}else if($isProduction && $productionType == 2){//produccion directa
		$productionType 	= 0;
		$isProduction 		= true;
		$deleteCompounds 	= false;
		$type 				= 'direct_production';
		$trackInventory 	= 0;
		$canSell 			= 1;
	}

	if($isCombo){
		if($comboType == 1){
			$type = 'precombo';
		}else if($comboType == 2){
			$type = 'combo';
		}else{
			$type = 'comboAddons';
		}

		$trackInventory = 0;
	}

	if($trackInventory < 1){
		$stocktrigger 	= 0;
		$autoorder 		= 0;
		$autoordercount = 0;

		//elimino el inventario y stock trigger en caso de que haya tenido para que no quede al pedo en la DB
		$db->Execute('DELETE FROM stock WHERE itemId = ? AND companyId = ?', [$id,COMPANY_ID]);
		$db->Execute('DELETE FROM stockTrigger WHERE itemId = ?', [$id]);
	}else{
		if(validateHttp('stocktrigger','post')){
			$location = validateHttp('stocktriggerLocation','post');
			
			foreach(validateHttp('stocktrigger','post') as $key => $value) {
				stockTriggerManager($id,$value,dec($location[$key]));
			}
			//dai();
		}
	}
	////

	if(validateHttp('resetCombo','post')){
		$deleteCompounds = true;
	}

	//compuestos
	if(validateHttp('compid','post') && !$deleteCompounds){

		$json 			= [];
		$cogsC 			= 0;
		$priceC 		= 0;
		$itemDiscount 	= 0;
		$cleared 		= false;
		$compIDA 		= validateHttp('compid','post');
		$compQTYA 		= validateHttp('compunits','post');

		if(validateHttp('compidPreselected','post')){
			array_push($compIDA, validateHttp('compidPreselected','post'));
			array_push($compQTYA, -1);
			//print_r($compIDA);
			//print_r($compQTYA);
			//dai();
		}

		foreach($compIDA as $key => $n){
			$compid 	= $compIDA;
			$compqty 	= $compQTYA;

			$compid 	= $compid[$key];
			$compqty 	= $compqty[$key];

			if($isCombo){
				$compu 		= formatNumberToInsertDB($compqty,true,2);
			}else if($isProduction){
				$compu 		= formatNumberToInsertDB($compqty,true,3);
			}

			if($compqty === -1){
				$compu = $compqty;
			}
			
			if(validity($compid)){
				if(!$cleared){
					ncmExecute('DELETE FROM toCompound WHERE itemId = ?',[$id]);
					$cleared = true;
				}

				$compIns 					= [];
				$compIns['itemId'] 			= $id;
				$compIns['compoundId'] 		= dec($compid);
				$compIns['toCompoundQty'] 	= $compu;
				$compIns['toCompoundOrder'] = $key;
				$inserted = $db->AutoExecute('toCompound', $compIns, 'INSERT');

				if(($isCombo && $type == 'precombo') || $isProduction){
					$priceC += (getItemPrice( $compid ) * $compu);
				}
			}
		}

		$record['itemPrice'] 	= $price;

	}else{
		ncmExecute('DELETE FROM toCompound WHERE itemId = ?',[$id]);
		$record['itemPrice'] 		= ($cogs > $price) ? $cogs : $price;
	}
	
	//compuestos//

	if($businessHours){
		$record['itemDateHour']	= $businessHours;
	}

	if($ptype == 'discount'){
		$type		= $ptype;
		$price 		= formatNumberToInsertDB(validateHttp('sellingPrice','post'),true,3);
	}

	if($ptype == 'giftcard'){
		$type			= $ptype;
		$description 	= validateHttp('giftExpCount','post') . ' ' . validateHttp('giftExpTime','post');
		$trackInventory = 1;
	}

	if($ptype == 'dynamic'){
		$type			= $ptype;
		//$trackInventory = 0;

	}

	if(validateHttp('upsell','post')){
		//print_R(validateHttp('upsell','post'));
		$db->Execute('DELETE FROM upsell WHERE upsellParentId = ?',[$id]);

		foreach (validateHttp('upsell','post') as $key => $value) {
			$uRecord 					= [];
			$uRecord['upsellParentId'] 	= $id;
			$uRecord['upsellChildId'] 	= dec($value);
			$uRecord['companyId'] 		= COMPANY_ID;
			$db->AutoExecute('upsell', $uRecord, 'INSERT');
		}

		if(validateHttp('upsellDescription','post')){
			$record['itemUpsellDescription'] 	= validateHttp('upsellDescription','post');
		}
	}

	//location
	if(validateHttp('defaultLocation','post')){
		$lRecord = [];
		list($ouNotUse,$location) = outletOrLocation(dec(validateHttp('defaultLocation','post')));

		$db->Execute('DELETE FROM toItemLocation WHERE itemId = ? AND outletId = ?',[$id,$location]);
		$lRecord['itemId'] 		= $id;
		$lRecord['outletId'] 	= $location;
		$db->AutoExecute('toItemLocation', $lRecord, 'INSERT');
	}

	$record['itemType']			= $type;
	$record['itemSKU'] 			= $sku;
	$record['itemName'] 		= $name;
	$record['itemDescription'] 	= $description;
	$record['itemSessions'] 	= $itemSessions;
	$record['itemDuration'] 	= $itemDuration;
	$record['itemTags'] 		= $itemTags;
	$record['itemCanSale'] 		= $canSell;
	$record['itemTrackInventory'] = $trackInventory;
	$record['itemStatus'] 		= $archive;
	$record['autoReOrder'] 		= $autoorder;
	$record['autoReOrderLevel'] = $autoordercount;
	$record['inventoryMethod'] 	= $inventorycountmethod;
	$record['itemImage'] 		= iftn(validateHttp('itemImgFlag','post'),'','true');
	$record['itemDiscount']		= $itemDiscount;
	$record['itemProcedure']	= $procedure;
	$record['itemProduction']	= $productionType;
	$record['itemComissionPercent']	= $itemComission;
	$record['itemComissionType']= $comissionType;
	$record['itemPricePercent'] = formatNumberToInsertDB($itemPricePercent);
	$record['itemPriceType']	= $itemPriceType;
	$record['itemEcom']			= $ecom;
	$record['itemFeatured']		= $featured;
	$record['itemTaxExcluded']	= $taxIncluded;
	
	$record['itemUOM']			= $uom;
	$record['itemWaste']		= $waste;
	
	$record['brandId'] 			= $brand;
	$record['categoryId'] 		= $category;
	$record['taxId']			= $tax;
	$record['locationId']		= $location;
	$record['outletId']			= $outlet;
	$record['updated_at']		= TODAY;


	$update = $db->AutoExecute('item', $record, 'UPDATE', 'itemId = ' . db_prepare($id) . ' AND ' . $SQLcompanyId); 
	if($update === false){
		echo 'false';
	}else{
		echo 'true|'.$_GET['index'].'|'.$_POST['id'];
		updateLastTimeEdit(false,'item');
		require_once('../panel/libraries/php-thumb/ThumbLib.inc.php');
		uploadImage($_FILES['image'], SYSIMGS_FOLDER.'/'.enc(COMPANY_ID).'_'.enc($id).'.jpg', 500000);
	}

	dai();
}

//Edit Bulk
//Editar Producto
if(validateHttp('action') == 'bulkUpdate' && validateHttp('ids','post')){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	//	session_write_close();

	$ids 		= explodes('|',validateHttp('ids','post'));
	$record 	= [];
	$recordP 	= [];//para grupos
	$inventoryActions = false;

	if(validateHttp('sellingPrice','post')){
		$record['itemPrice'] = formatNumberToInsertDB($_POST['sellingPrice']);
	}

	if(validateHttp('brand','post')){
		$record['brandId'] = dec($_POST['brand']);
	}
	if(validateHttp('category','post')){
		$record['categoryId'] = dec($_POST['category']);
	}

	if(validateHttp('tax','post')){
		$record['taxId'] = dec($_POST['tax']);
	}
	if(validateHttp('outlet','post')){
		$record['outletId'] = ($_POST['outlet']=='all') ? NULL : dec($_POST['outlet']);
	}

	if(validateHttp('sessions','post')){
		$record['itemSessions'] = formatNumberToInsertDB($_POST['sessions']);
	}
	if(validateHttp('duration','post')){
		$record['itemDuration'] = formatNumberToInsertDB($_POST['duration']);
	}
	if( isset($_POST['discount']) && is_numeric($_POST['discount']) ){
		if($_POST['discount'] > 0){
			$record['itemDiscount'] = formatNumberToInsertDB($_POST['discount'],true,3);
		}else{
			$record['itemDiscount'] = NULL;
		}
	}

	if(validateHttp('uom','post')){
		$record['itemUOM'] = validateHttp('uom','post');
	}
	if(validateHttp('waste','post')){
		$record['itemWaste'] = validateHttp('waste','post');
	}

	if(isset($_POST['ecom']) && $_POST['ecom'] != NULL){
		if(validateHttp('ecom','post') == 1){
			$record['itemEcom'] = 1;
			$recordP['itemEcom'] = $record['itemEcom'];//para grupos
		}else if(validateHttp('ecom','post') == 0){
			$record['itemEcom'] = 0;
			$recordP['itemEcom'] = $record['itemEcom'];//para grupos
		}
	}

	if(validateHttp('featured','post')){
		$record['itemFeatured'] = validateHttp('featured','post');
		$recordP['itemFeatured'] = $record['itemFeatured'];//para grupos
	}

	if(isset($_POST['typeOfItem']) && $_POST['typeOfItem'] != NULL){
		$canSell 			= -1;
		$trackInventory 	= -1;
		$type 				= 'product';
		$inventoryActions 	= true;
		$resetStock			= false;
		$do 				= false;

		if($_POST['typeOfItem'] == 0){
			// servicio
			$canSell 		= 1;
			$trackInventory = 0;
			$do 			= true;
		}else if(validateHttp('typeOfItem','post') == 1){
			//a la venta y con inventario
			$canSell 		= 1;
			$trackInventory = 1;
			$type 			= 'product';
			$do 			= true;
		}else if(validateHttp('typeOfItem','post') == 2){
			//no se vende y si inventario
			$canSell 		= 0;
			$trackInventory = 1;
			$type 			= 'compound';
			$do 			= true;
		}else if(validateHttp('typeOfItem','post') == 3){
			//no se vende y no inventario
			$canSell 		= 0;
			$trackInventory = 0;
			$do 			= true;
		}else if(validateHttp('typeOfItem','post') == 'dynamic'){
			// servicio dinamico
			$canSell 		= 1;
			$trackInventory = 0;
			$isProduction 	= false;
			$isCombo 		= false;
			$isDynamic 		= true;
			$type 			= 'dynamic';
			$do 			= true;
		}else if(validateHttp('typeOfItem','post') == 'resetstock'){
			//a la venta y con inventario
			$resetStock		= true;
			$do 			= false;//no cambio su estado como inventariable pero elimino su stock
		}

		if($do){

			if($canSell > -1 && $trackInventory > -1){
				$record['itemCanSale'] 			= $canSell;
				$record['itemTrackInventory'] 	= $trackInventory;	
			}

			$record['itemType'] = $type;
		}
	}

	if(validateHttp('comissionType','post') != '-'){
		if(validateHttp('comission','post')){
			$itemComission 	= validateHttp('comission','post');
			if(validateHttp('comissionType','post') == '%'){
				$itemComission 	= ($itemComission > 100) ? 100 : $itemComission;
				$itemComission 	= ($itemComission < 0) ? 0 : $itemComission;

				$comissionType 	= '0';
			}else{
				$comissionType 	= '1';
			}
			$record['itemComissionPercent'] = formatNumberToInsertDB($itemComission);
			$record['itemComissionType']	= $comissionType;
		}
	}

	if(validateHttp('priceType','post') != '-'){
		$itemPricePercent 	= validateHttp('itemPricePercent','post');
		if(validateHttp('priceType','post') == '%'){
			$itemPricePercent 	= ($itemPricePercent > 100) ? 100 : $itemPricePercent;
			$itemPricePercent 	= ($itemPricePercent < 0) ? 0 : $itemPricePercent;

			$itemPriceType 		= 1;
		}else{
			$itemPriceType 		= NULL;
			$itemPricePercent 	= 0;
		}
		$record['itemPricePercent'] = formatNumberToInsertDB($itemPricePercent);
		$record['itemPriceType']	= $itemPriceType;
	}

	$record['updated_at']		= TODAY;

	foreach($ids as $id){
		$itemId 	= dec($id);
		$itemData 	= ncmExecute('SELECT itemId,itemIsParent,itemPrice FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[$itemId,COMPANY_ID],true);

		if($itemData['itemIsParent']){
			$children 	= ncmExecute('SELECT itemId,itemPrice FROM item WHERE itemParentId = ? AND companyId = ? LIMIT 200',[$itemData['itemId'],COMPANY_ID],false,true);

			if($recordP){
				ncmUpdate(['records' => $recordP, 'table' => 'item', 'where' => 'itemId = ' . $itemData['itemId']]);
			}

			if($children){
				while (!$children->EOF) {
					$itemDataC 	= $children->fields;
					$itemId 	= $itemDataC['itemId'];

					if(isset($_POST['percentPrice']) && $_POST['percentPrice'] != ''){
						$actualPrice 			= $itemDataC['itemPrice'];
						$record['itemPrice'] 	= percenter($actualPrice,(int)$_POST['percentPrice']);
					}

					if($inventoryActions){
						if($trackInventory === 0 || $resetStock){
							//elimino el inventario y stock trigger en caso de que haya tenido para que no quede al pedo en la DB
							ncmExecute('DELETE FROM stock WHERE itemId = ? AND companyId = ? LIMIT 100000', [$itemId,COMPANY_ID]);
							ncmExecute('DELETE FROM stockTrigger WHERE itemId = ? LIMIT 1000', [$itemId]);
						}else{
							//stockTriggerManager($itemId,1);
						}
					}
					
					$update = $db->AutoExecute('item', $record, 'UPDATE', 'itemId = ' . db_prepare($itemId) . ' AND ' . $SQLcompanyId); 
					if($update === false){
					}else{
						updateLastTimeEdit(false,'item');
					}
					$children->MoveNext();
				}
			}

		}else{

			if(isset($_POST['percentPrice']) && $_POST['percentPrice'] != ''){
				$actualPrice 			= $itemData['itemPrice'];
				$record['itemPrice'] 	= percenter($actualPrice,(int)$_POST['percentPrice']);
			}

			if($inventoryActions){
				if($trackInventory === 0 || $resetStock){
					//elimino el inventario y stock trigger en caso de que haya tenido para que no quede al pedo en la DB
					ncmExecute('DELETE FROM stock WHERE itemId = ? AND companyId = ? LIMIT 100000', [$itemId,COMPANY_ID]);
					ncmExecute('DELETE FROM stockTrigger WHERE itemId = ? LIMIT 1000', [$itemId]);
				}
			}

			$update = ncmUpdate(['records' => $record, 'table' => 'item', 'where' => 'itemId = ' . db_prepare($itemId) . ' AND ' . $SQLcompanyId]);
			
			//$update = $db->AutoExecute('item', $record, 'UPDATE', 'itemId = ' . db_prepare($itemId) . ' AND ' . $SQLcompanyId); 
			if(!$update['error']){
				updateLastTimeEdit(false,'item');
			}

		}

	}
	echo 'true|0|'. str_replace('|', ',', validateHttp('ids','post'));
	dai();
}

//Eliminar Producto
if(validateHttp('action') == 'delete' && validateHttp('id')){
	if(!allowUser('items','delete',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	if(validateHttp('multi')){
		$ids 	= explodes('|',$_GET['id']);
		$ins 	= [];
		for($i = 0; $i < counts($ids); $i++){
			$id 	= dec($ids[$i]);
			//$delete = deleteItem($id);

			$ins[] 	= $id;

			if($delete){
				@file_get_contents('/upload.php?action=delete&id=' . $ids[$i]);
			}
		}

		$deleted = deleteItemBulk(implode(',', $ins));
		if($deleted){
			echo 'true';
		}else{
			echo 'false';
		}
		
		updateLastTimeEdit(false,'item');
	}else{
		$delete = deleteItem(dec($_GET['id']));
		if($delete){
			@file_get_contents('/upload.php?action=delete&id=' . $_GET['id']);
			updateLastTimeEdit(false,'item');
			echo 'true';
		}else{
			echo 'false';
		}
	}
	dai();
}

//Eliminar TODO el inventario de un producto
if(validateHttp('action') == 'clearSingleInventory' && validateHttp('id')){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	if(validateHttp('multi')){
		
	}else{
		$id 		= dec(db_prepare($_GET['id']));
		$isGroup 	= getValue('item','itemIsParent','WHERE itemId = '.$id.' AND '.$SQLcompanyId);

		$delete 	= $db->Execute('DELETE FROM inventory WHERE itemId = ? AND inventoryType = 0 AND '.$SQLcompanyId, array($id));

		if(validity($isGroup)){
			$record 				= array();
			$record['itemParentId'] = NULL;
			$db->AutoExecute('item', $record, 'UPDATE', 'itemParentId = '.$id.' AND '.$SQLcompanyId); 
		}

		if($delete === false){
			echo 'false';
		}else{
			echo 'true';
			updateRowLastUpdate('item','item_id = '.$id);
			updateLastTimeEdit(false,'item');
		}
	}
	dai();
}

//crear grupo
if(validateHttp('action') == 'group' && validateHttp('id')){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	if(validateHttp('multi') && validateHttp('name')){
		$ids 	= explodes('|',$_GET['id']);
		$name 	= validateHttp('name');

		$getCat = getItemData(dec($ids[0]));

		//creo un prod en base al nombre
		$record['itemName'] 		= $name;
		$record['itemIsParent']		= 1;
		$record['itemType']			= 'group';
		$record['itemDate'] 		= TODAY;
		$record['categoryId'] 		= $getCat['categoryId'];
		$record['companyId'] 		= COMPANY_ID;
		
		$insert 	= $db->AutoExecute('item', $record, 'INSERT'); 
		$parentId 	= $db->Insert_ID();

		if($insert !== false){
			foreach ($ids as $key) {
				$record 				= [];
				$id 					= dec($key);
				$record['itemParentId'] = $parentId;
				$record['updated_at']	= TODAY;
				$group 					= $db->AutoExecute('item', $record, 'UPDATE', 'itemId = '.db_prepare($id)); 
			}

			echo enc($parentId);
			updateLastTimeEdit(false,'item');
		}else{
			echo 'false';
		}
	}else{
		echo 'false';
	}

	dai();
}

//añadir a grupo
if(validateHttp('action') == 'groupEdit' && validateHttp('id')){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	if(validateHttp('multi') && validateHttp('group')){
		$ids 		= explodes('|',validateHttp('id'));
		$parentId 	= dec(validateHttp('group'));

		foreach ($ids as $key) {
			$record 				= array();
			$id 					= dec($key);

			if($parentId != $id){
				$record['itemParentId'] = $parentId;
				$record['updated_at']	= TODAY;
				$group 					= $db->AutoExecute('item', $record, 'UPDATE', 'itemId = '.db_prepare($id)); 
			}
		}

		updateLastTimeEdit(false,'item');
		echo 'true';
		
	}else{
		echo 'false';
	}

	dai();
}

if(validateHttp('action') == 'ungroup' && validateHttp('id')){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$record 				= array();
	$id 					= dec(validateHttp('id'));
	$record['itemParentId'] = NULL;
	$record['updated_at']	= TODAY;

	$group 					= $db->AutoExecute('item', $record, 'UPDATE', 'itemId = '.db_prepare($id)); 
	
	if($group === true){
		updateLastTimeEdit(false,'item');
		echo 'true';
		
	}else{
		echo 'false';
	}

	dai();
}

if(validateHttp('action') == 'archive' && validateHttp('id')){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$arch['itemStatus'] 	= 0;
	$arch['itemParentId'] 	= NULL;
	$arch['updated_at'] 	= TODAY;

	if(validateHttp('multi')){
		$ids = explodes('|',validateHttp('id'));

		foreach ($ids as $value) {
			$id 		= db_prepare(dec($value));
			$archive 	= $db->AutoExecute('item', $arch, 'UPDATE', 'itemId = ' . $id); 
		}
	}else{
		$archive = $db->AutoExecute('item', $arch, 'UPDATE', 'itemId = ' . db_prepare( dec($_GET['id']) )); 
	}

	updateLastTimeEdit(COMPANY_ID,'item');

	dai('true');
}

if(validateHttp('action') == 'unarchive' && validateHttp('id')){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$arch['itemStatus'] = 1;
	$arch['updated_at'] = TODAY;
	if(validateHttp('multi')){
		$ids = explodes('|',validateHttp('id'));

		foreach ($ids as $value) {
			$id 		= db_prepare(dec($value));
			$archive 	= $db->AutoExecute('item', $arch, 'UPDATE', 'itemId = ' . $id); 
		}
	}else{
		$archive = $db->AutoExecute('item', $arch, 'UPDATE', 'itemId = '.db_prepare($_GET['id'])); 
	}

	updateLastTimeEdit(COMPANY_ID,'item');

	dai('true');
}

if(validateHttp('tableExtra')){
	adm( validateHttp('valExtra'),validateHttp('tableExtra'),dec(validateHttp('idExtra')),validateHttp('actionExtra') );
}

if(validateHttp('getitemsarray')){
	$limit = ' LIMIT '.$plansValues[PLAN]['max_items'];
	$itemsListforJS = '[';
	
	$result = ncmExecute('SELECT itemId, itemName FROM item WHERE '.$SQLcompanyId.' ORDER BY itemName DESC'.$limit,[],false,true);

	if($result){
		while (!$result->EOF) {

			$itemsListforJS .= '{"'.$result->fields['itemId'].'","'.$result->fields['itemName'].'"},';

			$result->MoveNext(); 
		}
		$itemsListforJS = rtrim($itemsListforJS,",");
		$result->Close();
	}
	die($itemsListforJS.']');
}

if(validateHttp('getItem')){
	$limit 			= ' LIMIT 1';
	$itemsListforJS = '[';
	
	$result 		= ncmExecute('SELECT itemId, itemName FROM item WHERE itemId = ? ' . $SQLcompanyId . ' ' . $limit,[$_POST['id']]);

	if($result['itemId']){
		echo $result['itemId'].'|'.$result['itemName'];
	}

	dai();
}

if(validateHttp('action') == 'exportCSV' && validateHttp('ids')){
	include_once("libraries/parsecsv.lib.php");
	$ids 		= explodes('|',$_GET['ids']);
	$array 		= [];
	$fields 	= ['TITULO','SKU','MARCA','CATEGORIA','DESCRIPCION','PRECIO DE COSTO','PRECIO DE VENTA','TIPO',TAX_NAME,'SUCURSAL','% DESCUENTO','UN. DE MEDIDA','% MERMA','% COMISION','SESIONES','DURACION EN MIN.','STOCK MINIMO','STOCK INICIAL (Sucursal:Cantidad;Sucursal2:Cantidad)'];
	$var 		= [];

	for($i=0;$i<count($ids);$i++){
		$id 	= dec($ids[$i]);

		$result = $db->Execute('SELECT * FROM item WHERE itemId = '.$id.' AND '.$SQLcompanyId);

		if($result->fields['itemIsParent'] == '1'){
			$child = $db->Execute('SELECT * FROM item WHERE itemParentId = '.$id.' AND '.$SQLcompanyId);

			while (!$child->EOF) {
				$var['titulo'] 				= $child->fields['itemName'];
				$var['sku'] 				= $child->fields['itemSKU'];
				$var['marca'] 				= getTaxonomyName($child->fields['brandId']);
				$var['categoria'] 			= getTaxonomyName($child->fields['categoryId']);

				$var['precio de costo'] 	= $child->fields['itemCOGS'];
				$var['precio de venta'] 	= $child->fields['itemPrice'];

				$inventoryData 				= '';
				$inventory 					= $db->Execute('SELECT inventoryCount FROM inventory WHERE itemId = ? AND outletId = ?', array($child->fields['itemId'],OUTLET_ID));

				$inventoryData 				.= $inventory->fields['inventoryCount'];
				$var['inventario en la sucursal '.getCurrentOutletName()] = $inventoryData;

				$inventory->Close();

				$child->MoveNext();
				array_push($array, $var);
				$var = array();
			}

			$child->Close();
		}else{
			$var['titulo'] 			= $result->fields['itemName'];
			$var['sku'] 			= $result->fields['itemSKU'];
			$var['marca'] 			= getTaxonomyName($result->fields['brandId']);
			$var['categoria'] 		= getTaxonomyName($result->fields['categoryId']);

			$var['precio de costo'] = $result->fields['itemCOGS'];
			$var['precio de venta'] = $result->fields['itemPrice'];

			$inventoryData 			= '';
			$inventory 				= $db->Execute('SELECT inventoryCount FROM inventory WHERE itemId = ? AND outletId = ?', array($result->fields['itemId'],OUTLET_ID));

			$inventoryData 			.= $inventory->fields['inventoryCount'];
			$var['inventario en la sucursal '.getCurrentOutletName()] = $inventoryData;

			$inventory->Close();

			array_push($array, $var);
			$var = array();
		}

		$result->Close();		
	}

	/*echo '<pre>';
	print_r($array);
	echo '</pre>';*/

	$csv = new parseCSV();
	$csv->output("items_export_".date("d-m-Y").".csv", $array, $fields);
	
	dai();
}

if(validateHttp('action') == 'importCSV'){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$mimes 	= ['application/vnd.ms-excel','text/plain','text/csv','text/tsv','application/octet-stream'];
	$msg 	= '';
	if(!empty($_FILES['csv']['tmp_name'])){
		if(in_array($_FILES['csv']['type'],$mimes)){
		
	  		$fileData = file_get_contents($_FILES['csv']['tmp_name']);
			
			$cols 	= 8;
			$record = [];
			$msg 	= '';
			$maxRows = 2000;
			$noLines = 0;

			$lines 	= explodes(PHP_EOL, $fileData);
			$data 	= [];
			foreach ($lines as $line) {
				if($noLines>0){
				    $data[] = str_getcsv($line);
				}
			    $noLines++;
			}

			if($noLines > $maxRows){
				$msg = 'ERROR, máximo ' . $maxRows . ' líneas por archivo, ' . $noLines . ' líneas enviadas';
			}else{
				//outlets Ids by name
				$result 		= ncmExecute("SELECT outletName, outletId FROM outlet WHERE companyId = ?",[COMPANY_ID],false,true);
				$outlets 		= [];
				if($result){
					while (!$result->EOF) {
						$fields = $result->fields;
						$oname 	= strtolower( toUTF8($fields['outletName']) );
					    $outlets[$oname] = $fields['outletId'];
					    $result->MoveNext(); 
					}
				}

				$isUpdate = validateHttp('isUpdate','post');

				//$skus 			= ncmExecute("SELECT GROUP_CONCAT(itemSKU) FROM item WHERE itemSKU IS NOT NULL AND companyId = ?",[COMPANY_ID]);

				foreach($data as $val){
					$sI = 0;

					//['TÍTULO','SKU','MARCA','CATEGORÍA','DESCRIPCIÓN','PRECIO DE VENTA','TIPO',TAX_NAME,'SUCURSAL','% DESCUENTO','UN. DE MEDIDA','% MERMA','% COMISIÓN','SESIONES','DURACIÓN EN MIN.','STOCK MÍNIMO']

					if($isUpdate && validity($val[$sI])){
						$id = $db->Prepare( dec($val[$sI]) );
						$sI++;
					}

					$name 			= toUTF8($val[$sI]); $sI++;
					$sku 			= toUTF8($val[$sI]); $sI++;
					$brand 			= toUTF8($val[$sI]); $sI++;
					$category 		= toUTF8($val[$sI]); $sI++;
					$note 			= toUTF8($val[$sI]); $sI++;
					$cogs 			= formatNumberToInsertDB($val[$sI]); $sI++;
					$price 			= formatNumberToInsertDB($val[$sI]); $sI++;
					$type 			= strtolower( preg_replace('/[^A-Za-z]*$/', '', $val[$sI]) ); $sI++;
					$tax 			= $val[$sI]; $sI++;
					$outlet			= strtolower($val[$sI]); $sI++;
					$discount		= $val[$sI]; $sI++;
					$uom 			= $val[$sI]; $sI++;
					$waste 			= $val[$sI]; $sI++;
					$comission		= $val[$sI]; $sI++;
					$sessions		= $val[$sI]; $sI++;
					$minutes		= $val[$sI]; $sI++;
					$minstock		= $val[$sI]; $sI++;
					$inistock		= $val[$sI];
					
					if($name){
						if($comission>100){
							$comission = 100;
						}else if($comission<0){
							$comission = 0;
						}

						if($type == 'producto'){
							// servicio
							$canSell 		= 1;
							$trackInventory = 1;
						}else if($type == 'compuesto'){
							//no se vende y si inventario
							$canSell 		= 0;
							$trackInventory = 1;
						}else{
							$canSell 		= 1;
							$trackInventory = 0;
						}

						$record['itemName'] 		= $name;
						$record['itemDate'] 		= TODAY;
						$record['itemSKU'] 			= $sku;
						$record['itemStatus'] 		= 1;
						$record['itemImage'] 		= 'false';
						$record['itemType']			= 'product';

						$record['itemComissionPercent']	= $comission;
						$record['itemUOM']			= $uom;
						$record['itemWaste']		= $waste;
						$record['itemSessions'] 	= $sessions;
						$record['itemDuration'] 	= $minutes;
						$record['autoReOrder'] 		= ($minstock > 0) ? true : false;
						$record['autoReOrderLevel'] = $minstock;
						$record['itemDiscount']		= $discount;
						$record['itemCanSale'] 		= $canSell;
						$record['itemTrackInventory'] = $trackInventory;

						$record['itemPrice'] 		= $price;
						$record['itemDescription'] 	= $note;

						$record['brandId'] 			= getTaxonomyIdOrInsert($brand, 'brand');
						$record['categoryId'] 		= getTaxonomyIdOrInsert($category, 'category');
						$record['taxId'] 			= getTaxonomyIdOrInsert($tax, 'tax');

						$record['companyId'] 		= COMPANY_ID;
						$record['outletId']			= ($outlet == 'todas' || !validity($outlet)) ? NULL : $outlets[$outlet];

						$record['updated_at'] 		= TODAY;

						if($isUpdate){
							$insert 					= ncmUpdate(['records'=>$record,'table'=>'item','where'=>'itemId = ' . $id . ' AND companyId = ' . COMPANY_ID]);//$db->AutoExecute('item', $record, 'INSERT');
							$insert 					= $insert['error'] ? false : true;
							$lastInserted 				= $insert['id'];
						}else{
							$insert 					= ncmInsert(['records'=>$record,'table'=>'item']);
							$lastInserted 				= $insert;
						}

						if($insert !== false){
							if($inistock){
								$stockBlock = explodes(';', $inistock);
								foreach ($stockBlock as $block) {
									$unblock 	= explodes(':', $block);
									$outName 	= $unblock[0];
									$outIndx 	= searchInArray($allOutletsArray,'name',$outName);//getValue('outlet', 'outletId', 'WHERE outletName = "' . $outName . '" AND companyId = ' . COMPANY_ID);

									if($outIndx !== -1){
										$outId 		= $allOutletsArray[$outIndx]['id'];
										$count 		= $unblock[1];

										$ops['itemId']    = $lastInserted;
										$ops['outletId']  = $outId;
										$ops['cogs']      = $cogs;
										$ops['count']     = $count;

										manageStock($ops);
									}

								}
							}
						}
					}
				}

				$msg = 'Archivo subido con éxito';
				updateLastTimeEdit(false,'item');
			}
		}else{
			$msg = 'ERROR, solo se pueden importar archivos CSV. (' . $_FILES['csv']['type'] . ')';
		}
	}else{
		$msg = 'ERROR, el archivo no fue subido, vuelva a intentar o contáctenos';
	}
	header('location: /@#items');
}

if(validateHttp('action') == 'csvModel'){
	include_once("libraries/parsecsv.lib.php");
	$array 		= [];
	$fields 	= ['TITULO','SKU','MARCA','CATEGORIA','DESCRIPCION','PRECIO DE COSTO','PRECIO DE VENTA','TIPO',TAX_NAME,'SUCURSAL','% DESCUENTO','UN. DE MEDIDA','% MERMA','% COMISION','SESIONES','DURACION EN MIN.','STOCK MINIMO','STOCK INICIAL (Sucursal:Cantidad;Sucursal2:Cantidad)'];
	$var 		= ['Guantes','B12345','Gadgets','Prendas','Ultra grip de cuero','15000','20000','Producto','15','Central','','Uni.','','','','','5','Central:5;Sucursal:23'];
	$var2 		= ['Tratamiento','T54321','','Servicios','Tratamiento completo','20000','34000','Servicio','15','Todas','10','','','5','3','40',''];
	
	array_push($array, $var);
	array_push($array, $var2);

	$csv = new parseCSV();
	$csv->output("items_import_example.csv", $array, $fields);
	dai();
}

if(validateHttp('action') == 'stockAdd'){
	if(!allowUser('items','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$ops['itemId']    = db_prepare(dec($_GET['id']));
	$ops['outletId']  = OUTLET_ID;
	$ops['date']      = TODAY;
	$ops['cogs']      = formatNumberToInsertDB(db_prepare($_GET['price']));
	$ops['count']     = formatNumberToInsertDB(db_prepare($_GET['count']),true,3);

	$manage 		  = manageStock($ops);

	if($manage){
		echo 'true';
	}else{
		echo 'false';
	}

	dai();
}

if(validateHttp('action') == 'stockRemove'){
	if(!allowUser('items','delete',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$ops['itemId']    = db_prepare(dec($_GET['id']));
	$ops['outletId']  = OUTLET_ID;
	$ops['count']     = formatNumberToInsertDB(db_prepare($_GET['count']),true,3);
    $ops['type']      = '-';

	$manage 		  = manageStock($ops);

	if($manage){
		echo 'true';
	}else{
		echo 'false';
	}

	dai();
}
//---------------PROCESS-------------------//

if(validateHttp('action') == 'showTable'){

	$cOutletName 	= getCurrentOutletName();
	$outletNow 		= ($cOutletName == 'None') ? '' : $cOutletName;
	$archived 		= ' AND itemStatus = 1';
	$unGrouped 		= ' AND (itemIsParent > 0 OR (itemParentId IS NULL OR itemParentId = 0))';
	$class 			= 'bg-light lter';
	$outletSearch 	= '';

	if($_GET['debug']){
		$db->debug = true;
	}

	//$limit 			= ' LIMIT ' . $plansValues[PLAN]['max_items'];
	$limits 		= getTableLimits($limitDetail,$offsetDetail);

	if(validateHttp('archived')){
		$archived 	= ' AND itemStatus = 0 AND (itemIsParent < 1 OR itemIsParent IS NULL)';
		$unGrouped 	= '';
	}

	if(validateHttp('ungroup')){
		$unGrouped = ' AND (itemIsParent < 1 OR itemIsParent IS NULL)';
	}

	if(validateHttp('singleRow')){
		$singleRow = ' AND itemId = ' . dec(validateHttp('singleRow'));
	}

	//outlet search logic
	if(OUTLETS_COUNT > 1 && OUTLET_ID > 1){
		$outletSearch = ' AND (outletId = ' . OUTLET_ID . ' OR outletId IS NULL or outletId = 0)';
	}

	if(validateHttp('src') || validateHttp('srccat')){

		if(validateHttp('src')){
			$word 	= db_prepare(validateHttp('src'));
			//primero obtengo posible fuente
			$sData = ncmExecute("SELECT GROUP_CONCAT(itemId) as ids FROM item WHERE (itemName LIKE '%" . $word . "%' OR itemSKU LIKE '%" . $word . "%') AND companyId = ? LIMIT 100",[COMPANY_ID],true);
			
			$search = ' AND itemId IN(' . $sData['ids'] . ')';

			$sql = 'SELECT *
				FROM item
				WHERE (itemParentId < 1 OR itemParentId IS NULL)' . 
				$outletSearch . $singleRow . $search . ' AND companyId = ?' . $archived . '
				ORDER BY itemId DESC' . $limits;
		}else if(validateHttp('srccat')){
			if(validateHttp('srccat') == 'none'){
				$search = ' AND categoryId IS NULL';
			}else{
				$word 	= db_prepare(dec(validateHttp('srccat')));
				$search = ' AND categoryId = ' . $word;
			}

			$sql = 'SELECT *
					FROM item
					WHERE (itemParentId < 1 OR itemParentId IS NULL)' . 
					$outletSearch . $singleRow . $search . ' AND companyId = ?' . $archived . '
					ORDER BY itemId DESC' . $limits;
		}

	}else{
		$sql = 'SELECT *
				FROM item
				WHERE itemId > 0' . 
				$outletSearch . $singleRow . $archived . $unGrouped . 
				' AND companyId = ? ORDER BY itemId DESC ' . $limits;
	}

	$result 		= ncmExecute($sql,[COMPANY_ID],false,true);

	$head		= 	'	<thead class="text-u-c">'.
					'		<tr>'.
					'			<th style="max-width:60px;min-width:60px;" class="hidden-print">'.
					'				<span class="check b b-light rounded" id="checkAll"></span>'.
					'			</th>'.
					'			<th style="max-width:60px;min-width:60px;width:60px;"></th>'.
					'			<th>Nombre</th>'.
					'			<th>Tipo</th>'.
					'			<th>Creado</th>'.
					'			<th>Ud. Medida</th>'.
					'			<th>Código</th>'.
					'			<th>marca</th>'.
					'			<th>categoría</th>'.
					'			<th>Sucursal</th>'.
					'			<th>Sesiones</th>'.
					'			<th>Duración</th>'.
					'			<th>Merma</th>'.
					'			<th>Comisión</th>'.
					'			<th>Descuento</th>'.
					'			<th>Costo</th>'.
					'			<th>Precio</th>'.
					'			<th>Valor</th>'.
					'			<th>stock</th>'.
					'			<th>Online</th>'.
					'		</tr>'.
					'	</thead>'.
					'	<tbody>';

	$table = '';

	if($result){

		$idsList 			= getAllByIDBuild($result, 'itemId');

		$childrenIds 		= getAllComapnyItemsChildren();
		$outletsArray 		= getAllOutlets();
		$allCategoriesArray = getAllItemCategories();
		$allBrandsArray 	= getAllItemBrands();
		$allWaste      		= getAllWasteValue(false,true);
		$allCompounds 		= getAllCompoundsArray();
		$singleRow 			= validateHttp('singleRow');
		$singleRowD			= dec($singleRow);

		if(!$singleRow){
			if(OUTLET_ID < 1){
				$stockArray 		= getAllItemStock(false, true, $idsList);
			}else{
				$stockArray 		= getAllItemStock(OUTLET_ID);
			}
		}else{
			$singleStock 			=  getItemStock($singleRowD);

			$stockArray[$singleRowD] = ['onHand' => $singleStock['stockOnHand'], 'cogs' => $singleStock['stockOnHandCOGS'], 'cogss' => $singleStock['stockCOGS']];
		}

		while (!$result->EOF) {
			$fields 		= $result->fields;
			$brand 			= toUTF8(iftn($allBrandsArray[$fields['brandId']]['name'],'-'));
			$category 		= toUTF8(iftn($allCategoriesArray[$fields['categoryId']]['name'],'-'));
			$textIcon 		= 'Servicio';
			$itemId 		= enc($fields['itemId']);
			$fechUgly 		= $fields['itemDate'];
			$fecha 			= niceDate($fechUgly);
			$itemType 		= $fields['itemType'];
			$modalNarrow 	= '';
			$outletName 	= getCurrentOutletName($fields['outletId']);
			$outletName 	= iftn($outletName,'Todas');
			$discountPerc 	= iftn($fields['itemDiscount'],'-','~' . formatCurrentNumber($fields['itemDiscount'],'no').'%');
			$rowIsGroup 	= '';
			$rawPrice 		= $fields['itemPrice'];
			$commission 	= iftn($fields['itemComissionPercent'],'-');
			$sessions 		= iftn($fields['itemSessions'],'-');
			$duration 		= iftn($fields['itemDuration'],'-');
			$waste 			= ($fields['itemWaste'] > 0) ? $fields['itemWaste'] . '%' : '-';
			$ecom 			= '';
			$sortOnline 	= 0;
			$filterOnline 	= '';

			if($commission != '-'){
				if($fields['itemComissionType']){
					$commission = formatCurrentNumber($commission);
				}else{
					$commission = formatCurrentNumber($commission,'no') . '%';
				}
			}

			//pricing
			if($fields['itemPriceType']){//si el precio es porcentual al costo
		    	$cogs = (float)iftn($stockArray[$fields['itemId']]['cogs'],$stockArray[$fields['itemId']]['cogss']);
		    	if($fields['itemPricePercent'] < 1){
		    		$rawPrice = $cogs;
		    	}else{
		    		$addPrice = divider( ($cogs * $fields['itemPricePercent']), 100, true);
		    		$rawPrice = $cogs + $addPrice;
		    	}
		    }

		    $formatPrice = formatCurrentNumber($rawPrice);
		    //pricing end

		    if($fields['itemEcom']){
		    	$ecom 			= '<i class="material-icons text-success">check</i>';
		    	$sortOnline 	= 1;
		    	$filterOnline 	= 'online';
		    }

			//Comienza conteo de Stock
			if($fields['itemTrackInventory'] > 0){
				
				//$stockTrigger = ncmExecute('SELECT * FROM stockTrigger WHERE itemId = ? AND outletId = ? LIMIT 1',[$fields['itemId'],OUTLET_ID],true);

				$textIcon 	= 'Producto';
				$reorder 	= 0;//($stockTrigger) ? $stockTrigger['stockTriggerCount'] : 0;
				$stock 		= $stockArray[$fields['itemId']]['onHand'];
				
				$child 		= '';
				$oNow 		= '';

				if($stock <= 0){
					$class = 'bg-danger lter';
				}else if($stock > $reorder){
					$class 	= 'bg-light lter';
					$oNow 	= OUTLET_ID;
				}else if($stock < $reorder && $stock > 0){
					$class 	= 'bg-warning lter';
				}

				$avCOGS 	= iftn($stockArray[$fields['itemId']]['cogs'],$stockArray[$fields['itemId']]['cogss']);
				
				$avCOGS 	= ($avCOGS > 0) ? $avCOGS : 0;

				$prodCapTxt = '';
				if($fields['itemProduction'] > 0){
					$capacityIs = getProductionCapacity($allCompounds[$fields['itemId']],$stockArray,$allWaste);
					
					$textIcon 	= 'Producción';
					$prodCapTxt = 'data-toggle="tooltip" data-placement="left" title="Capacidad máxima de producción ' . $capacityIs . ' unidades"';
				}

				$amount 		= formatQty($stock,3);
				$stockField 	= '<span class="' . $class . ' font-bold label" ' . $prodCapTxt . '>' . $amount . '</span>';

				if($fields['itemIsParent']){
					$stock 		= 0;
					$child 		= $childrenIds[$itemId];
					$childarr 	= explodes(',',$childrenIds[$itemId],true);
					foreach($childarr as $childy){
						$stock 		+= sumInventoryInOutlet($inventoryArray[$childy]);
					}
					$amount 	= formatQty($stock,3);
					//$amount = ($stock < 1 && $stock > -1)?formatCurrentNumber($stock,'si',false,true):formatCurrentNumber($stock,'no');
					$stockField = '<span class="bg-light lter font-bold label">' . $amount . '</span>';
				}

				$stockRaw 		= $stock;
			}else{
				$avCOGS 	= 0;
				$stockField = '-';
				$allWaste 	= ($itemType == 'precombo') ? false : $allWaste;
				
				$capacityIs = getProductionCapacity($allCompounds[$fields['itemId']],$stockArray,$allWaste);
				
				if($fields['itemIsParent'] || $itemType == 'group'){
					$stock 		= 0;
					$commission = '-';
					$child 		= $childrenIds[$itemId];
					$childarr 	= explodes(',',$childrenIds[$itemId]);
					foreach($childarr as $childy){
						$stock 		+= sumInventoryInOutlet($inventoryArray[$childy]);
					}
					$amount 		= formatQty($stock,3);
					$stockField 	= '<span class="bg-light lter font-bold label">' . $amount . '</span>';
					$textIcon 		= 'Grupo';
					$modalNarrow 	= 'modal-narrow';
					$rowIsGroup 	= 'group';
					$formatPrice	= '-';
					$stockRaw 		= $stock;
				}else if($itemType == 'precombo'){
					$textIcon 		= 'Combo Predefinido';
					$stockField 	= '<span class="no-bg b b-light text-muted font-bold label" data-toggle="tooltip" data-placement="left" title="Disponibilidad">' . $capacityIs .'</span>';
					$stockRaw 		= $capacityIs;
				}else if($itemType == 'combo'){
					$textIcon 		= 'Combo Dinámico';
					$stockField 	= '-';
					$stockRaw 		= 0;
				}else if($itemType == 'comboAddons'){
					$textIcon 		= 'Combo Add-on';
					$stockField 	= '-';
					$stockRaw 		= 0;
				}else if($itemType == 'direct_production'){
					$avCOGS 		= getProductionCOGS(dec($itemId));
					$textIcon 		= 'Producción Directa';
					$prodCapTxt 	= 'data-toggle="tooltip" data-placement="left" title="Capacidad actual"';
					$stock 			= $capacityIs;
					$amount 		= formatQty($stock,3);
					$stockField 	= '<span class="bg-light lter font-bold label" ' . $prodCapTxt . '>' . $amount . '</span>';
					$stockRaw 		= $stock;
				}
			}

			//$stockRaw 		= formatNumberToInsertDB($stockRaw);//formateo numero a raw

			//Activo
			if($fields['itemCanSale'] < 1 || $itemType == 'compound'){
				$textIcon = 'Activo/Compuesto';
			}

			if($itemType == 'giftcard'){
				$textIcon 		= 'Gift Card';
				$modalNarrow 	= 'modal-narrow';
			}else if($itemType == 'discount'){
				$textIcon 		= '.Descuento';
				$modalNarrow 	= 'modal-narrow';
				$discount 		= '';
			}

			$imgBlock 			= '';

			if($fields['itemImage'] == 'true'){
				$imgBlock 			= 'class="lazy" data-src="https://assets.encom.app/60-60/0/' . enc(COMPANY_ID) . '_' . $itemId . '.jpg"';				
			}

			$table .= 	'<tr id="' . $itemId . '" class="clickrow ' . $modalNarrow . '" data-to-filter="' . enc($oNow) . '" >'.
						'	<td class="hidden-print">' .
						'		<span class="hidden">' . iftn($child,implodes(',', [$itemId,$child]),$itemId) . '</span>'.
						'		<span class="check b b-light rounded"> <input type="hidden" class="'.$rowIsGroup.'" value="'.$itemId.'"></span>'.
						'	</td>'.
						' 	<td ' . $imgBlock . '></td>' .
			 			'	<td class="font-bold">' . toUTF8($fields['itemName']) . '</td>' .
			 			'	<td>' . $textIcon . '</td>' .
						' 	<td data-sort="'.$fechUgly.'">'.$fecha.'</td>' .
						' 	<td>' . iftn($fields['itemUOM'],'-','<span class="badge">'.toUTF8($fields['itemUOM']).'</span>') . '</td>' .
						'	<td>' . iftn($fields['itemSKU'],'-') . '</td>' .
						'	<td>'.$brand.'</td>' .
						'	<td>'.$category.'</td>' .
						'	<td>'.$outletName.'</td>' .
						'	<td class="text-right bg-light lter" data-sort="' . $fields['itemSessions'].'">' . $sessions . '</td>' .
						'	<td class="text-right bg-light lter" data-sort="' . $fields['itemDuration'].'">' . $duration . '</td>' .
						'	<td class="text-right bg-light lter" data-sort="' . $fields['itemWaste'] . '">' . $waste . '</td>' .
						'	<td class="text-right bg-light lter" data-sort="' . $fields['itemComissionPercent'].'">'.$commission.'</td>' .
						'	<td class="text-right bg-light lter" data-sort="' . $fields['itemDiscount'].'">'.$discountPerc.'</td>';

			if($itemType == 'product' || $itemType == 'precombo' || $itemType == 'giftcard' ){
				$compoundsPrice = getComboCOGS($fields['itemId']);
				if($fields['itemDiscount'] > 0){
					$discount		= round_precision(abs($rawPrice * ($fields['itemDiscount'] / 100)),2);
					$discount		= $rawPrice - $discount;
					$formatPrice 	= '<strike class="text-xs text-danger block">' . formatCurrentNumber($rawPrice) . '</strike>' . formatCurrentNumber($discount);
				}

				if($compoundsPrice > $rawPrice){
					$formatPrice 	= '<strike class="text-xs text-danger block">' . formatCurrentNumber($compoundsPrice) . '</strike>' . formatCurrentNumber($rawPrice);
				}
			}

			$table .= 	'	<td class="text-right bg-light lter" data-sort="' . iftn($avCOGS,'0',$avCOGS) . '" data-format="money" width="12%">' .
								iftn($avCOGS,'-', formatCurrentNumber($avCOGS)) . 
						'	</td>' .
						'	<td class="text-right bg-light lter" data-sort="'.$fields['itemPrice'].'" data-format="money" width="12%">' . 
								$formatPrice . 
						'	</td>' .
						'	<td class="text-right bg-light lter" data-sort="' . iftn($avCOGS,'0',($avCOGS * $stockRaw)) . '" data-format="money" width="10%">' . 
								( ($avCOGS <= 0) ? '0' : formatCurrentNumber($avCOGS * $stockRaw) ) . 
						'	</td>' .
						'	<td class="text-right" width="5%" data-sort="' . $stockRaw . '">' . 
								$stockField . 
						'	</td>' .
						' 	<td data-sort="' . $sortOnline . '" data-filter="' . $filterOnline . '">' . $ecom . '</td>' .
						'</tr>';

			$itemsListforJS .= '["'.$itemId.'","'.toUTF8($fields['itemName']).'"],"';

			if(validateHttp('part') && !$singleRow){
	        	$table .= '[@]';
	        }

			$result->MoveNext(); 
		}

		$result->Close();
	}

	$foot .= 	'</tbody>' .
				'<tfoot>' .
				'	<tr class="text-right strong">' .
				'		<th class="hidden-print"></th>' .
				'		<th colspan="9">TOTALES:</th>'.
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>'.
				'		<th></th>' .
				'	</tr>' .
				'</tfoot>';


	$catsBtn = 	'<span class="btn-group m-r-xs"><a href="#" class="btn btn-default dropdown-toggle" data-toggle="dropdown" id="typeActivator">Categorías <span class="caret"></span></a>' .
				'	<ul class="dropdown-menu animated fadeIn" style="max-height:400px; overflow:auto;">';
						$result = ncmExecute('SELECT taxonomyId, taxonomyName, CAST(taxonomyExtra as UNSIGNED) as sort FROM taxonomy WHERE taxonomyType = "category" AND ' . $SQLcompanyId . ' ORDER BY sort ASC LIMIT 500',[],false,true);
						if($result){
							$catsBtn .=	'<li>' .
				            			'	<a href="#" data-id="all" class="text-default filterByCategory">Todas</a>' . 
				            			'	<a href="#" data-id="none" class="text-default filterByCategory">Sin categoría</a>' . 
										'</li>';
							while (!$result->EOF) {
								$fields 	= $result->fields;
								$id 		= enc($fields['taxonomyId']);
					            
					            $catsBtn .=	'<li>' .
					            			'	<a href="#" data-id="' . $id . '" class="text-default filterByCategory">' .
													toUTF8($fields['taxonomyName']) .
											'	</a>' . 
											'</li>';
					            $result->MoveNext();
					        }
					    }
	$catsBtn .=	'	</ul>' .
				'</span>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable 						= $head . $table . $foot;
		$jsonResult['table'] 			= $fullTable;
		$jsonResult['categoriesSelect'] = $catsBtn;

		header('Content-Type: application/json'); 
		dai(json_encodes($jsonResult,true));
	}
}
?>

<div class="hidden-print col-xs-12">
	<div class="col-xs-9 text-left m-t-sm no-padder">

		<a href="#contacts?rol=supplier" class="hidden-xs m-t m-r" data-toggle="tooltip" data-placement="bottom" title="Administrar Proveedores">Proveedores</a>
		
		<?php
		if(validateHttp('archived')){
			?>
			<a href="/@#items" class="hidden-xs m-t m-r viewArchived" data-typea="active" data-toggle="tooltip" data-placement="bottom" title="Ver artículos Activos">Activos</a>
			<?php
		}else{
			?>
			<a href="#items?archived=true" class="hidden-xs m-t m-r viewArchived" data-typea="archive" data-toggle="tooltip" data-placement="bottom" title="Ver artículos Archivados">Archivados</a>

			<a href="#items?ungroup=true" class="hidden-xs m-t m-r" data-toggle="tooltip" data-placement="bottom" title="Ver listado desagrupado">Desagrupado</a>
			<?php
		}
		?>

		
		
		<span class="hidden-xs text-muted m-r">|</span>
		<span class="hidden-xs text-muted m-r">Herramientas: </span>

		<a href="#bulk_adjustment" class="hidden-xs m-r" data-toggle="tooltip" data-placement="bottom" title="Ajustar stock manualmente">Ajustar</a>
		<a href="#purchase" class="hidden-xs m-r" data-toggle="tooltip" data-placement="bottom" title="Realizar compras de mercaderías">Comprar</a>
		<?=($_modules['production']) ? '<a href="#bulk_production" class="hidden-xs m-r" data-toggle="tooltip" data-placement="bottom" title="Producir uno o más productos">':'<a href="javascript:;" class="hidden-xs m-r" data-placement="bottom" data-toggle="tooltip" data-html="true" title="Habilite el módulo de producción">'?>Producir</a>
		<a href="#bulk_transfer" class="hidden-xs m-r" data-toggle="tooltip" data-placement="bottom" title="Transferir stock entre sucursales y depósitos">Transferir</a>
		<a href="#inventory_count" class="hidden-xs m-r" data-toggle="tooltip" data-placement="bottom" title="Realizar un conteo parcial o completo de inventario">Contabilizar</a>

	</div>

	<div class="col-xs-3 text-right no-padder">
		<div class="btn-group pull-right"> 
			<button class="btn btn-info bg-info dk btn-rounded dropdown-toggle" data-toggle="dropdown"><span class="m-r-sm font-bold text-u-c">Crear</span><span class="caret"></span></button> 
			<ul class="dropdown-menu"> 
				<li class="create" data-type="<?=enc(0);?>"><a href="#" class="createItemBtn">Artículo</a></li> 
				<li class="create" data-type="<?=enc(1);?>"><a href="#" class="combo createItemBtn">Kit o Combo</a></li>
				<li class="create" data-type="<?=enc(2);?>"><a href="#" class="discount createItemBtn modal-narrow">Descuento</a></li>
				<li class="create" data-type="<?=enc(3);?>"><a href="#" class="giftcard createItemBtn modal-narrow">Gift Card</a></li>
				<li class=""><a href="<?=$baseUrl;?>?action=formCSV" id="bulkUpload">Múltiples Artículos</a></li>
			</ul>  
		</div>
	</div>
</div>

<div class="wrapper col-xs-12">
	<?=headerPrint();?>

	<div class="col-sm-6 no-padder hidden-xs">
		<div class="btn-group pull-left hidden-print">
			<button class="btn btn-default dropdown-toggle rounded font-bold text-u-c" id="groupActions" data-toggle="dropdown">Seleccionados <span class="caret"></span></button> 
			<ul class="dropdown-menu">
				<?php
				if(!validateHttp('archived')){
				?>
				<li><a href="#" id="bulkEditBtn" data-type="bulkEdit" class="multi">Edición masiva</a></li>
				<li><a href="#" id="" data-type="group" class="multi group">Agrupar</a></li>
				<li><a href="#" id="" data-type="barcode" class="multi">Códigos de Barra</a></li>
				<?php
				}
				?>
				<li>
					<?php
					if(validateHttp('archived')){
					?>
					<a href="#" data-type="unarchive" class="multi">Re Activar</a>
					<a href="#" data-type="delete" class="multi"><span class="text-danger">Eliminar</span></a>
					<?php
					}else{
					?>
					<a href="#" data-type="archive" class="multi" data-toggle="tooltip" data-placement="top" title="Desactivar los artículos seleccionados para que no se muestren en la caja registradora">
						<span class="text-danger">Archivar</span>
					</a>
					<?php
					}
					?>
				</li>
				<!--<li><a href="#" id="" data-type="delete" class="multi remove"><span class="text-danger">Eliminar</span></a></li>-->
			</ul>
		</div>
	</div>

	<div class="col-sm-6 no-padder text-right">
		<span class="font-bold h1">
			<a href="https://docs.encom.app/panel-de-control/articulos" class="m-r-sm hidden-print" target="_blank" data-toggle="tooltip" data-placement="left" title="" data-original-title="Visitar el centro de ayuda">
				<i class="material-icons text-info m-b-xs">help_outline</i>
			</a>
	    	<span id="pageTitle">Productos y Servicios</span>
	    </span>
	</div>
</div>

<div class="wrapper col-xs-12 panel r-24x push-chat-down table-responsive tableContainer">
	<table class="table hover no-padder" data-column-defs='[{"sortable": false, "targets": [0,1]}]' data-order='[[ 4, "desc" ]]' id="tableItems">
		<?=placeHolderLoader('table')?>
	</table>
</div>

<script type="text/javascript">
	$('.matchCols').matchHeight();
	var archived 			= "<?php echo validateHttp('archived') ? '&archived=true' : ''?><?php echo validateHttp('ungroup') ? '&ungroup=true' : ''?>";
	window.limit 	 		= <?=$limitDetail?>;
	window.offset 	 		= <?=$offsetDetail?>;
	window.baseUrl  		= '<?=$baseUrl;?>';
	var ncmDBActive 		= '<?=$_modules['dropboxToken']?>';
	var thousandSeparator 	= '<?=THOUSAND_SEPARATOR?>';
	var decimal 			= '<?=DECIMAL?>';
	var currency 			= '<?=CURRENCY?>';
	var companyId 			= '<?=enc(COMPANY_ID)?>';
	
</script>
<script type="text/javascript" src="scripts/a_items.js?<?=rand();?>"></script>

<?php
include_once('includes/compression_end.php');
dai();
?>