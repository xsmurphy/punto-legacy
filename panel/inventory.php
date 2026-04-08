<?php
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
theErrorHandler();//error handler

$o = (OUTLET_ID > 1)?' AND outletId = '.OUTLET_ID:'';

if($_GET['penor']){
	die();
	$result 			= $db->Execute('SELECT itemId, taxId, inventoryReorder FROM inventory');

	while (!$result->EOF) {
		$record = array();
		if($result->fields['taxId'] && $result->fields['taxId']>0){
			$record['taxId'] 			= $result->fields['taxId'];
		}
		if($result->fields['inventoryReorder'] && $result->fields['inventoryReorder']>0){
			$record['inventoryTrigger'] = (int)$result->fields['inventoryReorder'];
		}
		
		$update = $db->AutoExecute('item', $record, 'UPDATE', 'itemId = '.$result->fields['itemId']);
		if($update){
			//echo '<br>Updated<br>';
		}else{
			//echo '<br>Not Updated<br>';
		}

		$result->MoveNext(); 
	}
	$result->Close();
	
	
	dai('End');
}

if($_GET['rofl']){
	echo '<pre>';
	print_r( getAllIndividualInventory() );
	echo '</pre>';
	dai();
}

//---------------UI-------------------

if(validateBool('action') == 'editform' && validateBool('id')){
	
	$result 			= $db->Execute('SELECT * FROM item WHERE itemId = ? AND '.$SQLcompanyId, array(dec($_GET['id'])));
	$itemId 			= enc($result->fields['itemId']);
	$realType			= $result->fields['itemType'];
	$type 				= ($realType == 'precombo')?'combo':$realType;
	$compSetCostprice 	= '0';
	$itemCanSale 		= $result->fields['itemCanSale'];
	$itemDiscount 		= $result->fields['itemDiscount'];
	$itemStatus 		= $result->fields['itemStatus'];
	$itemOnline 		= $result->fields['itemOnline'];
	$isParent 			= $result->fields['itemIsParent'];
	$itemProcedure 		= $result->fields['itemProcedure'];
	$itemTrackInventory = $result->fields['itemTrackInventory'];

	$img 				= SYSIMGS_FOLDER.'/'.enc(COMPANY_ID).'_'.$itemId.'.jpg';
	$letters 			= '';
	$bg 				= 'opacity';
	$img 				= ASSETS_URL.'/src.php?src='.$img.'&w=150&h=150&'.rand();

	//Esta query es para el listado de items para el Compound por que debo de usar multiple times
	$cmpndTms = $db->Execute('SELECT itemId as id, itemName as name, itemPrice as price FROM item WHERE itemIsParent = 0 AND itemStatus = 1 AND itemType = \'product\' AND '.$SQLcompanyId.' ORDER BY itemName ASC LIMIT '.$plansValues[PLAN]['max_items']);
	$cmpndCts = $db->Execute('SELECT taxonomyId as id, taxonomyName as name FROM taxonomy WHERE taxonomyType = \'category\' AND '.$SQLcompanyId.' LIMIT '.$plansValues[PLAN]['max_categories']);

	if($itemCanSale > 0 && $itemTrackInventory < 1){
		// servicio
		$itemTypeOne = 'selected';
		$hideInventoryOps = 'hidden';
	}else if($itemCanSale > 0 && $itemTrackInventory > 0){
		//a la venta y con inventario
		$itemTypeTwo = 'selected';
		$hideInventoryOps = '';
	}else if($itemCanSale < 1 && $itemTrackInventory > 0){
		//a la venta y sin inventario
		$itemTypeThree = 'selected';
		$hideInventoryOps = 'hidden';
	}else if($itemCanSale < 1 && $itemTrackInventory < 1){
		//no se vende y si inventario
		$itemTypeFour = 'selected';
		$hideInventoryOps = '';
	}

	$hideInventoryOps .= ' animated fadeIn';

	$archiveSelected = ($result->fields['itemStatus']>0)?true:false;
	$reorderSelected = $result->fields['autoReOrder'];
	
	?>
	<style>
	.popover{
		max-width: 280px!important;
		width:280px!important;
	}
	</style>

	<div class="hidden" id="inputCompundList">
		<?php
		$inpuTComp = json_decode(selectInputCompound((($result->fields['itemType']=='combo')?$cmpndCts:$cmpndTms)));
		?>
		<div class="TextBoxDiv">
			<div class="col-sm-8 wrapper-xs"><?=$inpuTComp;?></div>
			<div class="col-sm-4 wrapper-xs">
				<input type="text" class="form-control maskInteger text-right no-border b-b no-bg" name="compunits[]" data-toggle="tooltip" title="Utilice unidades enteras" placeholder="Unidades" data-placement="bottom" value="">
			</div>
		</div>
	</div>

	<div class="modal-body no-padder clear">
		<form action="?action=update" method="post" id="editItem" name="editItem">
			<div class="col-sm-6 wrapper bg-light lter clear">
				<div class="col-xs-12 no-padder">
					<div class="text-center">
						<?php
						if($type == 'product' || $type == 'combo'){
						?>
							<a href="#" class="thumb-lg" tabindex="0" class="btn btn-lg btn-primary" role="button" data-html="true" data-toggle="popover" data-trigger="focus" title="" data-content="<div><a href='#' id='deleteImgBtn' data-id='<?=$itemId?>' class='btn btn-default btn-block'>Eliminar Imagen</a><label for='image' class='btn btn-default btn-block'>Subir Imagen</label></div>">
								<img src="<?=$img?>" width="120" class="img-circle itemImg">
							</a>
							<input type="hidden" id="itemImgFlag" name="itemImgFlag" value="<?=$result->fields['itemImage'];?>">
							
						
						<?php
						}else if($type == 'discount'){
						?>

							<span style="font-size:3.5em; width:120px; height:120px; display:block; margin:0 auto;" class="wrapper-lg bg-warning lt rounded"><i class="icon-tag"></i></span>

						<?php
						}
						?>
					</div>
				</div>

				<div class="col-sm-12">
					<div class="form-group">
						<input type="text" id="insertItemName" class="form-control text-center maskRequiredText no-border b-b b-light no-bg font-thin" style="font-size:25px; height:55px;" name="name" placeholder="Nombre del Artículo" value="<?=$result->fields['itemName'];?>" autocomplete="off"/>
					</div>
					<?php
					if($type == 'product' || $type == 'combo'){
					?>
					<div class="form-group col-xs-9 no-padder">
						<input type="text" class="form-control no-border b-b b-light no-bg" name="uid" placeholder="SKU o Código de Barras" value="<?=($result->fields['itemSKU'])?$result->fields['itemSKU']:$result->fields['itemAutoSKU'];?>" autocomplete="off"/> 
					</div>
					<div class="col-xs-3 no-padder h4 m-t-sm text-center"><?=enc($result->fields['itemId']);?></div>
					<?php
					}
					?>
				</div>

				<div class="form-group col-xs-12 no-padder m-t">
					<textarea class="form-control no-border b-b b-light no-bg" placeholder="Descripción general" style="height:100px" name="description" autocomplete="off"><?=$result->fields['itemDescription'];?></textarea>
				</div>

				<div class="m-t col-xs-12 no-padder">
					<?php
					if($type == 'discount'){
					?>

						<div class="col-sm-2"></div>
						<div class="col-sm-8 m-t">
					        <div class="form-group">
					          <label>Porcentaje de descueno:</label>
					          <input type="text" class="sellingPriceEdit form-control prices input-lg text-right maskPercent no-bg no-border b-b b-light" name="sellingPrice" value="<?=formatCurrentNumber($result->fields['itemPrice']);?>" autocomplete="off"/>
					        </div>
					    </div>
					    <div class="col-sm-2"></div>

					<?php
					}else if($type == 'combo' && $realType != 'precombo'){
					?>
				    <div class="text-info text-center">El precio final del combo depende enteramente de los artículos que lo conforman y se seleccionan en el momento de la venta.</div>
			      	<?php
					}else if($type == 'product' || $realType == 'precombo'){
					?>
					<div class="col-sm-8 no-padder">
				        <div class="form-group">
				          <label>Precio de Venta Principal <i class="icon-question text-xs" data-toggle="tooltip" data-placement="top" title="" data-original-title="Los precios de venta deben tener el <?=TAX_NAME?> incluído"></i></label>
				          <input type="text" class="sellingPriceEdit form-control prices input-lg font-bold text-right maskCurrency no-bg no-border b-b b-light" name="sellingPrice" value="<?=formatCurrentNumber($result->fields['itemPrice']);?>" autocomplete="off"/>
				          <?php
						  	if($result->fields['itemDiscount']>0){
							  $displaydiscount		= round_precision(abs($result->fields['itemPrice'] * ($result->fields['itemDiscount'] / 100)),2);
							  echo '<div class="text-xs text-danger text-right">Con el descuento: '.formatCurrentNumber($result->fields['itemPrice']-$displaydiscount).'</div>';
							}
						  ?>
				        </div>
				    </div>

					<div class="col-sm-4 no-padder">
						<div class="form-group">
					  <label>Descuento <i class="icon-question text-xs" data-toggle="tooltip" data-placement="top" title="" data-original-title="Asigne un descuento porcentual permanente a este Artículo"></i></label>
					  <input type="text" class="form-control input-lg text-right maskPercent bg no-border b-b b-light" placeholder="0%' name="itemDiscount" value="<?=($itemDiscount)?>" autocomplete="off">
					</div>
					</div>

					<div class="col-sm-12 no-padder">
						<div class="col-sm-8 no-padder">
							<a href="#" class="block bg h4 text-center wrapper-sm m-r disabled" data-html="true" data-toggle="popoverNOT" data-placement="top" data-content='<div class="text-center h4 font-thin m-b-sm col-xs-12 no-padder">Ingrese los precios secundarios</div> <label>Precio 2:</label> <input type="text" class="form-control font-bold text-right maskCurrency no-bg no-border b-b b-light m-b-sm" name="sellingPrice1" value="<?=formatCurrentNumber($result->fields["itemPrice1"]);?>" autocomplete="off"/>
								<label>Precio 3:</label> <input type="text" class="form-control font-bold text-right maskCurrency no-bg no-border b-b b-light m-b-sm" name="sellingPrice2" value="<?=formatCurrentNumber($result->fields["itemPrice2"]);?>" autocomplete="off"/>
								<label>Precio 4:</label> <input type="text" class="form-control font-bold text-right maskCurrency no-bg no-border b-b b-light m-b-sm" name="sellingPrice3" value="<?=formatCurrentNumber($result->fields["itemPrice3"]);?>" autocomplete="off"/>
								<label>Precio 5:</label> <input type="text" class="form-control font-bold text-right maskCurrency no-bg no-border b-b b-light m-b-sm" name="sellingPrice4" value="<?=formatCurrentNumber($result->fields["itemPrice4"]);?>" autocomplete="off"/>'>
								<span class="text-info">
									<strike>Lista de Precios</strike>
								</span>
							</a>
						</div>
						<div class="col-sm-4 no-padder">
						  <?php 
				            selectInputTaxonomy('tax',$result->fields['taxId']);
				          ?>
						</div>
					</div>
					<?php
					}
					?>

					<div class="col-sm-12 wrapper-md m-b-lg"></div>
					<div class="col-sm-12 no-padder hidden">
					  <div class="col-sm-6 no-padder">
					  	<div class="m-t-sm">
					  		<span class="">Estado</span> <i class="icon-question text-xs text-muted" data-toggle="tooltip" title="Desactiva esta opción para evitar que este artículo se muestre en la caja registradora y en el listado de artículos del panel, (será movido al listado de artículos Archivados)" data-placement="right"></i>
					  	</div>
					  </div>
					  <div class="col-sm-6 no-padder">
					    <span class="pull-right">
					    	<? //switchIn('archive',$archiveSelected)?>
					     </span>
					   </div>
					</div>
				</div>
			</div>

			<div class="col-sm-6 no-padder bg-white">

				<div class="col-xs-12 no-padder bg-light dk block">
					<header class="header bg-light lt">
				        <ul class="nav nav-tabs nav-white">
				            <li class="active"><a href="#data" data-toggle="tab"><i class="icon-settings"></i></a></li>
				            <?php
							if($type == 'product'){
								if($plansValues[PLAN]['inventory']){
							?>
					            <li><a href="#options" data-toggle="tab">
					            	Opciones
					            	<span class="badge badge-sm up bg-warning dker count hidden" id="opsBadge" style="display: inline-block;"></span>
					            </a></li>
				            <?php
								}
							?>

				            <li><a href="#modifiers" data-toggle="tab">Producción</a></li>
				            <?php
							}
							?>
							<?php
							if($type == 'product' || $type == 'combo'){
							?>
							<li class="inventoryTools <?=$hideInventoryOps?>">
				            	<a href="?action=inventoryForm&id=<?=$itemId?>" data-position="<?=$itemId?>" class="inventoryBtn <?=$disabledClass?>" id="inventoryCount<?=$itemId?>" <?=$disabled?>>
				            		Inventario
				            	</a>
				            </li>
				            <?php
							}
							?>
							<?php
							if($type == 'combo'){
							?>
							<li><a href="#combosTab" data-toggle="tab">Combo</a></li>
							<?php
							}
							?>
				        </ul>
				    </header>
				</div>

				<div class="tab-content">
	          		<div class="tab-pane active bg-white col-xs-12 no-padder" id="data">
	          			<?php
						if($type == 'product' || $type == 'combo'){
						?>
	          			<div class="col-xs-12 no-border b-dashed b-b wrapper-sm">
				    		<span>Marca:</span>
					        <?php 
					        	$brand = $db->Execute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = \'brand\' AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC LIMIT '.$plansValues[PLAN]['max_brands']);
					        ?>
					        <select id="brandEdit" name="brand" tabindex="1" data-placeholder="Seleccione una Marca" class="form-control m-b brand" autocomplete="off">
					        	<option value="">Seleccionar</option>
					          <?php 
					          while (!$brand->EOF) {
					          	$brandId = enc($brand->fields['taxonomyId']);
					          	?>
					          <option value="<?=$brandId;?>" <?=($brand->fields['taxonomyId'] == $result->fields['brandId'])?'selected':'';?>>
					          <?=$brand->fields['taxonomyName'];?>
					          </option>
					         	<?php 
									$brand->MoveNext(); 
									}
									$brand->Close();
								?>
					        </select>
					        <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="brand" title="Crear" data-toggle="tooltip" data-placement="top"><i class="icon-plus"></i></a>
				    		<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="brand" data-select="brandEdit" title="Renombrar" data-toggle="tooltip" data-placement="top"><span class="icon-note"></span></a>
			    			<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="brand" data-select="brandEdit" data-toggle="tooltip" data-placement="top" title="Remover"><span class="icon-trash text-danger"></span></a>
						</div>
						<div class="col-xs-12 no-border b-dashed b-b wrapper-sm">
				    		<span>Categoría:</span>
					        <?php 
					        	$category = $db->Execute('SELECT taxonomyId, taxonomyName, taxonomyExtra FROM taxonomy WHERE taxonomyType = \'category\' AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC LIMIT '.$plansValues[PLAN]['max_categories']);
					        ?>
					        <select id="categoryEdit" name="category" tabindex="1" data-placeholder="Seleccione una Categoría" class="form-control category m-b" autocomplete="off">
					        	<option value="" selected>Seleccionar</option>
					          <?php 
					          	while (!$category->EOF) {
					          	$categoryId = enc($category->fields['taxonomyId']);
					          	?>
					          <option value="<?=$categoryId;?>" data-toggle="<?=$category->fields['taxonomyExtra'];?>" <?=($category->fields['taxonomyId'] == $result->fields['categoryId'])?'selected':'';?>>
					          	<?=(($category->fields['taxonomyExtra'] == 1)?'× ':'').$category->fields['taxonomyName'];?>
					          </option>
					          <?php 
			                    $category->MoveNext(); 
			                    }
			                    $category->Close();
			                  ?>
					        </select>
					    
							<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear"  data-toggle="tooltip" data-placement="top"><i class="icon-plus"></i></a>
							<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="categoryEdit" title="Renombrar"  data-toggle="tooltip" data-placement="top"><span class="icon-note"></span></a>
			    			<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="categoryEdit" title="Remover"  data-toggle="tooltip" data-placement="top"><span class="icon-trash text-danger"></span></a>
			    			<a href="#" class="toggleItemPart btn btn-sm bg-light lter" data-table="category" data-select="categoryEdit" title="Ver/Ocultar categoría en la caja registradora"  data-toggle="tooltip" data-placement="top"><span class="icon-eye"></span></a>
						</div>
						<div class="col-xs-12 no-border b-dashed b-b wrapper-sm">
				    		<span>Tipo: <i class="icon-question text-xs text-muted" data-toggle="tooltip" title="Seleccione que tipo de artículo es, si es necesario llevar un control de inventario o no. Los Activos no pueden venderse, son solo para uso interno de la empresa" data-placement="right"></i></span>
					        <select id="tipo" name="typeOfItem" tabindex="1" data-itemid="<?=$_GET['id']?>" data-placeholder="Seleccione un tipo" class="form-control m-b" autocomplete="off">
					        	<option value="0" <?=$itemTypeOne?>>Servicio o Producto (Sin Inventario)</option>
					        	<option value="1" <?=$itemTypeTwo?>>Producto (Con Inventario)</option>
					        	<option value="2" <?=$itemTypeThree?>>Activo Fijo (Con Inventario)</option>
					        	<option value="3" <?=$itemTypeFour?>>Activo Fijo (Sin Inventario)</option>
					        </select>
						</div>
						<div class="col-xs-12 no-border b-dashed b-b wrapper-sm inventoryTools <?=$hideInventoryOps?>">
							<div class="col-sm-6 no-padder">
								<div class="m-t-sm">
									Nivel de Re-stock <i class="icon-question text-muted text-xs" data-toggle="tooltip" title="" data-original-title="Una vez que su inventario llegue a la cantidad que indique en este campo, le enviaremos alertas de inventario bajo y se generarán órdenes de compra en caso de que se hayan activado. Para desactivar esta herramienta deje el campo en 0"></i>
								</div>
							</div>
							<div class="col-sm-6 no-padder">
								<input type="text" class="form-control maskInteger text-right input-md no-border no-bg b-b" placeholder="0" name="stocktrigger" value="<?=$result->fields['inventoryTrigger']?>" autocomplete="off" data-toggle="tooltip" title="Utilice unidades enteras, ej: 10 unidades, 1kg = 1000g y 1lt = 1000ml. No es recomendable utilizar decimales" placeholder="Unidades" data-placement="top"/>
							</div>
						</div>
						<div class="col-xs-12 no-border b-dashed b-b wrapper-sm inventoryTools <?=$hideInventoryOps?>" style="display:none">
							<div class="col-sm-6 no-padder">
								<div class="m-t-sm">
									Auto ordenar <i class="icon-question text-muted text-xs" data-toggle="tooltip" title="Habilite esta opción para que el sistema genere órdenes de compra de este artículo automáticamente cuando el inventario haya alcanzado el Nivel de Re-stock"></i>
								</div>
							</div>
							<div class="col-sm-6 no-padder">
								<span class="pull-right">
									<?=switchIn('autoorder',$reorderSelected)?>
						         </span>
							</div>
						</div>
						<div class="col-xs-12 no-border b-dashed b-b wrapper-sm inventoryTools <?=$hideInventoryOps?>" style="display:none">
							<div class="col-sm-6 no-padder">
								<div class="m-t-sm">
									Unidades a reordenar <i class="icon-question text-muted text-xs" data-toggle="tooltip" title="Ingrese la cantidad de unidades que desea que el sistema ordene automáticamente"></i>
								</div>
							</div>
							<div class="col-sm-6 no-padder">
								<input type="text" class="form-control maskInteger text-right input-md no-border no-bg b-b" placeholder="0" name="autoordercount" value="<?=$result->fields['autoReOrderLevel']?>" autocomplete="off" data-toggle="tooltip" title="Utilice unidades enteras, ej: 10 unidades, 1kg = 1000gr y 1lt = 1000ml" placeholder="Unidades" data-placement="top"/>
							</div>
						</div>

						<div class="col-xs-12 no-border b-dashed b-b wrapper-sm inventoryTools <?=$hideInventoryOps?>">
							<div class="col-sm-6 no-padder">
								<div class="m-t-sm">
									Método de inventario <i class="icon-question text-muted text-xs" data-toggle="tooltip" title="Seleccione el método de contabilidad de inventario que se ajuste mejor al tipo de producto"></i>
									<br>
									<a href="https://es.wikipedia.org/wiki/FIFO_y_LIFO_(contabilidad)" target="_blank"><span class="text-info">Más información</span></a>
								</div>
							</div>
							<div class="col-sm-6 no-padder">
								<select id="inventorycountmethod" name="inventorycountmethod" tabindex="1" data-placeholder="Seleccione un tipo" class="form-control m-b" autocomplete="off">
								<?php
								foreach($inventoryControlType as $option => $val){
									$inM = (!$result->fields['inventoryMethod'])?0:$result->fields['inventoryMethod'];
									$selected = ($inM==$val)?'selected':'';
									echo '<option value="'.$val.'" '.$selected.'>'.$option.'</option>';
								}
								?>
						        </select>
							</div>
						</div>

						<div class="col-xs-12 wrapper">
							

							<!--<div class="col-xs-12 wrapper-xs m-b b-b hidden">
								<div class="col-xs-4 no-padder">
									<span class="m-t block text-md">En venta <i class="icon-question text-muted text-xs" data-toggle="tooltip" title="Desactivar si no es mercadería o servicio para la venta, pero forma parte del inventario general de la empresa"></i></span>
								</div>
								<div class="col-xs-8 text-right no-padder">
									<?=switchIn('canSell',$selleableSelected)?>
		                        </div>
							</div>-->

							

							<!--<div class="col-xs-12 wrapper-xs m-b b-b">
								<div class="col-xs-4 no-padder">
									<span class="m-t block h4">Online <i class="icon-question text-muted text-xs" data-toggle="tooltip" title="Activar si este artículo puede ser vendido online"></i></span>
								</div>
								<div class="col-xs-8 text-right no-padder">
									<?=switchIn('online',$onlineSelected)?>
		                        </div>
							</div>-->

						</div>

						<?php
						}else if($type == 'discount'){
						?>
							<div class="col-xs-12 text-center" style="margin-top:25%;">
								<span style="font-size:3em;" class=""><i class="icon-tag text-muted"></i></span>
								<h2 class="font-thin m-t">Descuentos personalizados</h2>
								<p class="text-muted">
									Son especialemente útiles para crear descuentos específicos y poder tener un control de los mismos. <br><em>"Así como si se tratase de un artículo o servicio, puede asignarle un inventario para controlar su uso y ver reportes detallados."</em>
								</p>
								
							</div>
						<?php
						}
						?>
					</div>
					<div class="tab-pane bg-white col-xs-12 no-padder" id="options" style="">
						<div class="wrapper-sm clear" id="modifiersHolder">
							
							<?php $modifier = $db->Execute('SELECT * FROM item WHERE itemParentId = '.$result->fields['itemId']);
							$childCount = $modifier->RecordCount();
							if($childCount > 0){
								?>
								<script type="text/javascript">
									$('#opsBadge').removeClass('hidden').text('<?=$childCount?>');
								</script>
								<?php
							}
							?>
							<?php 
							while (!$modifier->EOF) {
								$name = explode(' / ',$modifier->fields['itemName']);
								$name = $name[1];
							?>
						
							<div class="TextBoxDiv col-xs-12 no-padder">
							<div class="line line-dashed b-b line-lg pull-in"></div>
								<div class="col-sm-7 wrapper-xs"> 
									<input type="text" class="form-control input-md name no-border no-bg b-b" name="modname[]" placeholder="Nombre" value="<?=$name?>"> 
								</div>
								<div class="col-sm-5 wrapper-xs"> 
									<input type="text" class="form-control input-md text-right maskCurrency no-border no-bg b-b" name="modprice[]" placeholder="Precio" value="<?=formatCurrentNumber($modifier->fields['itemPrice']);?>"> 
								</div>
								<div class="col-sm-4 wrapper-xs"> 
									<input type="text" class="form-control input-md no-border no-bg b-b" name="modsku[]" placeholder="SKU" value="<?=$modifier->fields['itemSKU'];?>"> 
								</div>
								<div class="col-sm-4 wrapper-xs text-center">
									<a href="items?action=inventoryForm&id=<?=enc($modifier->fields['itemId']);?>" data-position="<?=enc($modifier->fields['itemId']);?>" class="m-t-sm block inventoryBtn" id="inventoryCount<?=enc($modifier->fields['itemId']);?>"><span class="text-info text-sm">Inventario</span></a> 
								</div>
								<div class="col-sm-4 wrapper-xs text-center">
									<a href="#" data-id="<?=enc($modifier->fields['itemId']);?>" class="m-t-sm block singleBarcode"><span class="text-info text-sm">Código</span></a> 
								</div>
								<input type="hidden" class="id" name="modId[]" value="<?=enc($modifier->fields['itemId']);?>">
							</div>
							<?php 
								$modifier->MoveNext();
							}
							$modifier->Close();
							?>

							<div class="TextBoxDiv optionsBox col-xs-12 no-padder">
								<div class="line line-dashed b-b line-lg pull-in"></div>
								<div class="col-sm-7 wrapper-xs"> 
									<input type="text" class="form-control input-md name no-border no-bg b-b" name="modname[]" placeholder="Opción" value=""> 
								</div>
								<div class="col-sm-5 wrapper-xs"> 
									<input type="text" class="form-control input-md text-right maskCurrency no-border no-bg b-b" name="modprice[]" placeholder="Precio" value=""> 
								</div>
								<div class="col-sm-4 wrapper-xs"> 
									<input type="text" class="form-control input-md no-border no-bg b-b" name="modsku[]" placeholder="SKU" value=""> 
								</div>
								<div class="col-sm-8 wrapper-xs"></div>
							</div>
						</div>
						<div class="clear text-center m-b">
							<a href="#" id="addModifier" class="m-r-lg text-success">Agregar</a> 
							<a href="#" id="rmModifier" class="text-danger">Eliminar</a> 
						</div>

						<div class="wrapper m-t">
							<div class="wrapper bg-light lter text-muted text-sm r-2x">
								*Las <strong>Opciones</strong> le permite crear variantes del producto principal que pueden tener su propio precio final pero poseen el mismo costo de compra o producción que el principal, también pueden tener un inventario independiente.
								<br>
								Podrían ser distintas medidas o colores de un mismo producto. Ej:
								<br><br>
								<strong>Producto principal:</strong>
								<br>
								Camisa elegante
								<br>
								<strong>Opciones:</strong>
								<br>
								Pequeño
								<br>
								Mediano
								<br>
								Grande
							</div>
						</div>
					</div>
					<?php
					if($type == 'product'){
					?>
					<div class="tab-pane wrapper bg-white col-xs-12 no-padder" id="modifiers">
						<div class="col-xs-12 no-padder">
							<div class="col-xs-12 wrapper-xs text-center text-md m-b">Tipos de producción</div>
							<div class="col-sm-4 m-t-xs">Directa <i class="icon-question text-muted text-xs" data-toggle="tooltip" data-placement="bottom" title="Utilizado para artículos que se venden directamente al producirse (sustrae el inventario de sus compuestos luego de que se realiza la venta), ej. platos de comida en un restaurant, un café o un servicio que requiere del uso de ciertos productos"></i></div>
							<div class="col-sm-4 text-center"><?=switchIn('productionType',(($result->fields['itemProduction']>0)?true:false))?></div>
							<div class="col-sm-4 text-right m-t-xs"><i class="icon-question text-muted text-xs" data-toggle="tooltip" data-placement="bottom" title="Utilizado para artículos que se almacenan luego de su producción y al mismo tiempo crea un stock del resultado de esta producción. Ej: Prendas de vestir, alimentos pre fabricados, etc. (sustrae el inventario de sus compuestos en el momento de la producción)"></i> 
							<?=(!$plansValues[PLAN]['production'])?'<strike>Previa</strike><div class="text-info text-xs">Actualice su plan para habilitar</div>':'Previa'?></div>
						</div>
						<?php
						if(($result->fields['itemProduction']>0 && $result->fields['compoundId'])){ //verifico si es produccion previa y si hay productos cargados
						?>
						<div class="col-xs-12 wrapper">
							<div class="col-xs-5 wrapper-xs">
								<input type="text" class="form-control no-border no-bg b-b text-right datepicker" placeholder="Vencimiento" value="" id="productionExpirationDate">
							</div>
							<div class="col-xs-4 wrapper-xs">
								<input type="number" class="form-control no-border no-bg b-b text-right" placeholder="Unidades" value="" id="productionUnits" data-toggle="tooltip" data-placement="bottom" title="Indique la cantidad de unidades de <?=$result->fields['itemName'];?> a producir. El stock de sus compuestos será reducido inmediatamente">
							</div>
							<div class="col-xs-3 wrapper-xs">
								<a href="#" class="btn btn-dark btn-block text-center" data-name="<?=$result->fields['itemName'];?>" data-id="<?=$itemId;?>" data-outletname="<?=getCurrentOutletName()?>" id="productionBtn" data-toggle="tooltip" data-placement="bottom" title="Asegurese de guardar cualquier cambio realizado antes de producir este artículo">Producir</a>
							</div>
							
							<?php
							//obtengo el costo de producción de los compuestos
							$pCogs = 0;
							while (!$cmpndTms->EOF) {
						  		$pCogs += $cmpndTms->fields['price'];
								$cmpndTms->MoveNext(); 
							}
							$cmpndTms->MoveFirst();
							?>
							<input type="hidden" value="<?=$pCogs?>" id="productionCogs">
						</div>
						<?php
						}
						?>
						<div class="col-xs-12 wrapper-xs text-center text-md">Compuestos</div>
						<div class="wrapper clear" id="compoundHolder">
							<?php 
							if($result->fields['compoundId']){
								$obj = json_decode($result->fields['compoundId']);
								
								foreach ($obj as $result){
								    $id 	= $result->id;
								    $units 	= $result->units;

								    if($result->fields['itemCOGS'] < 1){
								    	//echo $compSetCostprice;
									    $compSetCostprice += (getItemPrice(dec($id),true)*$units);
									    $compSetCostprice = forceDobleDecimals($compSetCostprice);
									}
							?>
							<div class="TextBoxDiv">
								<div class="col-sm-8 wrapper-xs"> 
									<?=json_decode(selectInputCompound($cmpndTms,$id));?>
								</div>
								<div class="col-sm-4 wrapper-xs"> 
									<input type="text" class="form-control text-right no-border b-b no-bg maskInteger" name="compunits[]" data-toggle="tooltip" data-placement="left" title="Utilice unidades enteras" placeholder="Unidades" data-placement="bottom" value="<?=$units?>"> 
								</div>
							</div>
							<?php 

								}
							}
							?>
							
							<div class="TextBoxDiv">
								<div class="col-sm-8 wrapper-xs"> 
									<?=$inpuTComp;?>
								</div>
								<div class="col-sm-4 wrapper-xs"> 
									<input type="text" class="form-control text-right no-border b-b no-bg maskInteger" name="compunits[]" data-toggle="tooltip" data-placement="left" title="Utilice unidades enteras" placeholder="Unidades" data-placement="bottom" value=""> 
								</div>
							</div>
							
						</div>

						<div class="col-xs-12 text-center m-b">
							<a href="#" id="addCompound" class="m-r-lg text-success">Agregar</a> 
							<a href="#" id="rmCompound" class="text-danger">Eliminar</a> 
						</div>

						<hr class="bg-light">

						<div class="col-xs-12 text-center text-md">Procedimiento</div>
						<div class="col-xs-12 wrapper">
							<textarea class="form-control b-light" placeholder="Procedimiento para la elaboración (opcional)" style="height:100px" name="procedure" autocomplete="off"><?=$itemProcedure?></textarea>
						</div>
					</div>
					<?php
					}else if($type == 'combo'){
					?>
					<div class="tab-pane wrapper bg-white col-xs-12 no-padder" id="combosTab">
						<div class="col-xs-12 no-padder">
							<div class="col-xs-12 wrapper-xs text-center text-md m-b">Tipo de combo</div>
							<div class="col-sm-4 m-t-xs">Predefinido <i class="icon-question text-muted text-xs" data-toggle="tooltip" data-placement="bottom" title="Esta opción es para combos cuyos artículos no varían al momento de la venta, es decir, los artículos que conforman este combo son pre seleccionados al momento de la creación del combo"></i></div>
							<div class="col-sm-4 text-center"><?=switchIn('comboType',(($result->fields['itemType']=='combo')?true:false))?></div>
							<div class="col-sm-4 text-right m-t-xs"><i class="icon-question text-muted text-xs" data-toggle="tooltip" data-placement="bottom" title="Este tipo de combo requiere que se seleccionen los artículos que lo conforman en el momento de realizar la venta. Deberá de seleccionar previamente las categorías que conformorán el combo al momento de crearlo"></i> Dinámico</div>
						</div>
						
						<div class="wrapper clear" id="compoundHolder">
							<?php 
							if($result->fields['compoundId']){
								$obj = json_decode($result->fields['compoundId']);
								
								foreach ($obj as $result){
								    $id 	= $result->id;
								    $units 	= $result->units;

								    if($result->fields['itemCOGS'] < 0 || $result->fields['itemCOGS'] == 0){
								    	//echo $compSetCostprice;
									    $compSetCostprice += (getItemPrice(dec($id),true)*$units);
									    $compSetCostprice = forceDobleDecimals($compSetCostprice);
									}
									?>
									<div class="TextBoxDiv">
										<div class="col-sm-8 wrapper-xs"> 

											<?php
											if($realType=='precombo'){
												echo json_decode(selectInputCompound($cmpndTms,$id));
											}else{
												echo json_decode(selectInputCompound($cmpndCts,$id));
											}
											?>
										</div>
										<div class="col-sm-4 wrapper-xs"> 
											<input type="text" class="form-control text-right no-border b-b no-bg maskInteger" name="compunits[]" data-toggle="tooltip" data-placement="left" title="Utilice unidades enteras" placeholder="Unidades" data-placement="bottom" value="<?=$units?>"> 
										</div>
									</div>
									<?php 

								}
							}

							?>
							
							<div class="TextBoxDiv">
								<div class="col-sm-8 wrapper-xs"> 
									<?=$inpuTComp;?>
								</div>
								
								<div class="col-sm-4 wrapper-xs"> 
									<input type="text" class="form-control text-right no-border b-b no-bg maskInteger" name="compunits[]" data-toggle="tooltip" data-placement="left" title="Utilice unidades enteras" placeholder="Unidades" data-placement="bottom" value=""> 
								</div>
							</div>
							
						</div>

						<div class="col-xs-12 text-center m-b">
							<a href="#" id="addCompound" class="m-r-lg text-success">Agregar</a> 
							<a href="#" id="rmCompound" class="text-danger">Eliminar</a> 
						</div>

						<div class="wrapper m-t">
							<div class="wrapper bg-light lter text-muted text-sm r-2x">
								La suma de los precios de los artículos seleccionados en esta sección crearán automáticamente el precio de costo y venta del producto final.
							</div>
						</div>
					</div>
					<?php
					}
					?>
					<div class="tab-pane wrapper bg-white col-xs-12 no-padder" id="settings">
					</div>
				</div>

			</div>

			<div class="col-xs-12 wrapper bg-light lter">
				<a href="#" class="btn btn-default pull-left itemsAction" data-id="<?=$_GET['id']?>" data-type="deleteItem" data-load="items?action=delete&id=<?=$_GET['id']?>" data-toggle="tooltip" data-placement="right" title="Eliminar Artículo"><i class="icon-trash text-danger"></i></a>

			    <input class="btn btn-info pull-right" type="submit" value="Guardar">
			    <button class="btn btn-default cancelItemView m-r  pull-right">Cancelar</button>
			    <input type="hidden" value="<?=$_GET['id']?>" name="id">
			    <input type="hidden" value="<?=$type?>" name="itemType">
		    </div>
		</form>
	</div>
	
	<form method="post" enctype="multipart/form-data" action="upload.php?id=<?=enc(COMPANY_ID).'_'.$itemId?>">
		<input type="file" name="image" id="image" data-url="upload.php?id=<?=enc(COMPANY_ID).'_'.$itemId?>" style="display:none" />
	</form>

	<script>
		$(document).ready(function(){
			var compSetCostprice = checkIfallDecimals(<?=$compSetCostprice?>);

			$('.datepicker').datetimepicker({
			  format: 'YYYY-MM-DD HH:mm:ss'
			});
			
			if(compSetCostprice > 0 && $('.costPriceEdit').data('value') <= 0){
				$('.costPriceEdit').val(compSetCostprice);
			}
			var thousandSeparator 	= '<?=THOUSAND_SEPARATOR?>';
			var decimal 			= '<?=DECIMAL?>';
			var currency 			= '<?=CURRENCY?>';

			$('[data-toggle="tooltip"]').tooltip();
			$("[data-toggle=popover]").popover().on('show.bs.popover', function(){
				maskCurrency($('.maskCurrency'),thousandSeparator,decimal);
			});
			maskCurrency($('.maskInteger'),thousandSeparator,'no');
			$('.maskPercent').mask('##0,000%', {reverse: true});
			maskCurrency($('.maskCurrency'),thousandSeparator,decimal);
			//$(".compoundSelect").select2(options);

			var compBoxesList = $('#inputCompundList').html();

			<?php 
			 if($type == 'combo'){
			 	echo "addRemoveTextBox('#addCompound','#rmCompound','#compoundHolder',(compBoxesList)?compBoxesList:'');";
			 }else if($type == 'product'){
			 	echo "addRemoveTextBox('#addModifier','#rmModifier','#modifiersHolder',$('.optionsBox').html());";
			 	echo "addRemoveTextBox('#addCompound','#rmCompound','#compoundHolder',(compBoxesList)?compBoxesList:'');";
			 }
			 ?>		
		});
	</script>

	<?php

	dai();           
}

if(validateBool('action') == 'inventoryForm' && validateBool('id')){
	?>
		<div class="modal-body no-padder">		
			
		    <ul class="nav nav-tabs m-t-sm m-l-sm m-r-sm"> 
		      <li class="active">
		      	<a href="#inventoryForm" class="refreshUsers" data-toggle="tab" aria-expanded="false">Inventario</a>
		      </li>

		      <li class="hidden">
		      	<a href="#moveForm" data-toggle="tab" aria-expanded="false">Mover Inventario</a>
		      </li>
		    </ul>
		    
			<div class="tab-content bg-white">
				<div class="tab-pane active" id="inventoryFormTab">
					<form action="?action=inventoryUpdate" method="POST" id="inventoryForm" name="inventoryForm" enctype="multipart/form-data">
					  <div class="wrapper">
					    <table class="table">
					      <tbody>
					        <?php 
							$outlet = $db->Execute("SELECT outletId, outletName FROM outlet WHERE outletStatus = 1 AND ".$SQLcompanyId.$o);
							$totalRows = 0;
							?>
					        <?php while (!$outlet->EOF) {?>
					        <tr>
					        	<td colspan="6" class="text-center wrapper-xs text-u-c font-bold text-xs bg-light lt">
					            	<?=$outlet->fields['outletName'];?>
					            	<input type="hidden" name="outletId[]" value="<?=enc($outlet->fields['outletId']);?>"/>
					            </td>
					        </tr>
					        <tr>
					          <th>Stock</th>
					          <th class="text-center">Añadido el</th>
					          <th class="text-center">Vencimiento</th>
					          <th class="text-center">Costo</th>
					          <th>Proveedor</th>
					          <th>Eliminar</th>
					        </tr>
					        
					          <?php $inventory = $db->Execute('SELECT * 
					          									FROM inventory 
					          									WHERE outletId = ? 
					          									AND itemId = ? 
					          									AND inventoryCount > 0 
					          									AND inventoryType < 1
					          									ORDER BY inventoryExpirationDate DESC
					          									',
					          									array($outlet->fields['outletId'],dec($_GET['id'])));
					          	?>

					          	<?php while (!$inventory->EOF) {?>
					         <tr>
					         <td width="20%">
					         	<input type="hidden" name="inventoryId[]" value="<?=enc($inventory->fields['inventoryId']);?>"/>
					          	<input type="text" class="form-control maskInteger no-border no-bg b-b text-right" placeholder="0" name="count[]" value="<?=($inventory->fields['inventoryCount'] < 0)?0:(int)$inventory->fields['inventoryCount'];?>" autocomplete="off" placeholder="Unidades"/>
					          </td>
					          <td class="text-right">
					            <?=niceDate($inventory->fields['inventoryDate']); ?>
					          </td>
					          <td class="text-right">
					          	<input type="text" class="form-control no-border no-bg b-b text-right datepicker" placeholder="Vencimiento" value="<?=$inventory->fields['inventoryExpirationDate']?>" name="expires[]">
					          </td>
					          <td class="text-right">
					          	<input type="text" class="form-control maskCurrency text-right" name="cogs[]" value="<?=formatCurrentNumber($inventory->fields['inventoryCOGS']);?>" autocomplete="off" placeholder="Costo"/>
					          </td>
					          <td>
					          <?php 
					          if($inventory->fields['supplierId'] == dec(COMPANY_ID)){
					          	echo '<i>Producido</i>';
					          }else{
						          $supplier = $db->Execute('SELECT contactId, contactName FROM contact WHERE '.$SQLcompanyId.' AND type = 2 ORDER BY contactDate ASC LIMIT '.$plansValues[PLAN]['max_suppliers']);?>
							        <select id="" name="supplier[]" tabindex="1" data-placeholder="Seleccione un Proveedor" class="form-control" autocomplete="off">
							        	<option value="" selected>Seleccionar</option>
							          <?php while (!$supplier->EOF) {?>
							          <option value="<?=enc($supplier->fields['contactId']);?>" <?=($supplier->fields['contactId'] == $inventory->fields['supplierId'])?'selected':'';?>>
							          <?=$supplier->fields['contactName'];?>
							          </option>
							         	<?php 
											$supplier->MoveNext(); 
											}
											$supplier->Close();
										?>
							        </select>
							  <?php
					      		}
					          ?>
						      </td>
					          <td>
					          	<?=switchIn('delete[]',false,'bg-danger',enc($inventory->fields['inventoryId']))?>
					          </td>

					          
					          
					        </tr>
					        <?php
					        		$inventory->MoveNext();  
					        		$totalRows++;
					        	}
					            $inventory->Close();
					            $outlet->MoveNext(); 
					        }
					        $outlet->Close();
					        ?>
					      </tbody>
					    </table>
					  </div>
					  <div class="wrapper bg-light dk">
					    <input class="btn btn-info m-r" type="submit" value="Modificar">
					    <button class="btn btn-default" data-dismiss="modal">Cancelar</button>
					    <input type="hidden" name="id" value="<?=$_GET['id'];?>"/>
					    <input type="hidden" name="totalRows" value="<?=$totalRows?>"/>
					  </div>
					</form>
				</div>

				<div class="tab-pane" id="moveForm">
					<form action="items.php?action=inventoryMove" method="POST" id="inventoryMove" name="inventoryMove" enctype="multipart/form-data">
					    <div class="wrapper m-b clear">
					    	<div class="text-center h2"><?=getItemName(dec($_GET['id']));?></div>
					    	<div class="col-sm-4">
					    		<h4>Mover</h4>
					    		<input type="text" class="form-control maskInteger text-right" placeholder="0" name="moveUnits" value="1" autocomplete="off"/>
					    	</div>
					    	<div class="col-sm-4">
					    		<h4>Desde</h4>
					    		<?=selectInputOutlet(OUTLET_ID,'','');?>
					    	</div>
					    	<div class="col-sm-4">
					    		<h4>A</h4>
					    		<?=selectInputOutlet('','','','outletto');?>
					    	</div>
					    </div>
					    <div class="wrapper bg-light dk">
						    <input class="btn btn-info m-r" type="submit" value="Mover">
						    <button class="btn btn-default" data-dismiss="modal">Cancelar</button>
						    <input type="hidden" name="id" value="<?=$_GET['id'];?>"/>
						</div>
					</form>
				</div>
			</div>

		</div>
		<script type="text/javascript">
			$('.datepicker').datetimepicker({
			  format: 'YYYY-MM-DD HH:mm:ss'
			});
			var thousandSeparator 	= '<?=THOUSAND_SEPARATOR?>';
			var decimal 			= '<?=DECIMAL?>';
			var currency 			= '<?=CURRENCY?>';
			maskCurrency($('.maskInteger'),thousandSeparator,'no');
			maskCurrency($('.maskCurrency'),thousandSeparator,decimal);
		</script>
	<?php
	dai();
}

if(validateBool('showTable')){
	$inventoryArray 	= getAllIndividualInventory();
	$outletsArray 		= getAllOutlets();

	$table = '<thead class="text-u-c">
					<tr class="">
						<th></th>
						<th>Nombre</th>
						<th></th>
						<th class="hidden">a1</th>
					</tr>
				</thead>
				<tbody>';

	foreach($inventoryArray as $itemId => $outlets){
		$table .= '<tr data-type="loadItem" data-id="'.$itemId.'" data-element="#formItemSlot" data-load="?action=editform&id='.$itemId.'" class="itemsAction" >';
			
			$table .= '<td width="2%">./</td>';
			$table .= '<td width="96%"> <strong>'.$itemId.'</strong> </td>';
			$table .= '<td width="2%"><a href="#" class="toggleInventory btn btn-default" data-inv=".childRow'.$itemId.'">+</a></td>';

		//if($inventoryArray[$itemId]){
			$table .= '<td style="display:none;">';
			foreach($outlets as $outlet => $inventory){
				$outletTotalUnits 	= 0;
				$outletTotalCogs 	= 0;
				$cogsAverager 		= 0;

				if(OUTLET_ID<1){
					$table .= '<div class="h4 font-thin text-center m-b">'.$outletsArray[dec($outlet)]['name'].'</div>';
				}
				$table .= '<table class="table no-padder no-margin"><thead><tr class="text-u-c"><th>Fecha</th><th>Proveedor</th><th>Vencimiento</th><th class="text-center">Cantidad</th><th class="text-center">Costo</th><th>Eliminar</th></tr></thead>';
						$table .= '<tbody>';
					
					foreach($inventory as $rows){
							$table .= '<tr>';
								$table .= '<td class=""> '.niceDate($rows['date']).' </td>';
								$table .= '<td class=""> '.$rows['supplier'].' </td>';
								$table .= '<td class=""> <input type="text" class="form-control text-right no-border b-b no-bg datepicker" name="expires[]" value="'.$rows['expires'].'" > </td>';
								$table .= '<td class="text-right"> <input type="text" class="form-control text-right no-border b-b no-bg" name="count[]" value="'.$rows['count'].'" > </td>';
								$table .= '<td class="text-right"> <input type="text" class="form-control text-right no-border b-b no-bg" name="cogs[]" value="'.formatCurrentNumber($rows['cogs']).'" > </td>';
								$table .= '<td class="text-right"> '.switchIn('delete[]',false,'bg-danger',enc($inventory->fields['inventoryId'])).' </td>';
								
							$table .= '</tr>';

							$outletTotalUnits += $rows['count'];
							$outletTotalCogs += $rows['cogs'];
							$cogsAverager += ($rows['cogs']>0)?1:0;
					}

					$table .= '<tr class="font-bold"><td></td><td></td><td></td><td class="text-right">'.$outletTotalUnits.'</td><td class="text-right">'.formatCurrentNumber(divider($cogsAverager,$outletTotalCogs)).'</td><td></td></tr>';

					$table .= '</tbody>';
				$table .= '</table>';

			}
			$table .= '</td>';
		/*}else{
			$table .= '<div class="text-center h4 no-padder font-thin">No posee inventario</div>';
		}*/
	}
	
	$table .= '</tbody>';

	$table .= '<tfoot>';
		$table .= '<tr class="text-right strong">';
			$table .= '<th></th>';
			$table .= '<th></th>';
			$table .= '<th></th>';
			$table .= '<th class="hidden">a1</th>';
		$table .= '</tr>';

	$table .= '</tfoot>';
	
	$result->Close();
	
	dai($table);
}



//---------------UI-------------------//


//---------------PROCESS-------------------

if(validateBool('action') == 'inventoryUpdate' && validateBool('id',true,'post')){
	
	$totalRows 	= (int)$_POST['totalRows'];

	$outlet 	= $_POST['outletId'];
	$count 		= $_POST['count'];
	$cogs 		= $_POST['cogs'];
	$supplier 	= $_POST['supplier'];
	$id 		= $_POST['id'];
	$expires 	= $_POST['expires'];
	$invId 		= $_POST['inventoryId'];
	$delete 	= $_POST['delete'];
	$avrCOGS 	= 0;
	
	for($x=0;$x<$totalRows;$x++){

		if($delete[$x]){
			$db->Execute('DELETE FROM inventory WHERE inventoryId = ?', array( dec( $delete[$x] ) ) );
		}else{

			$countit 		= formatNumberToInsertDB($count[$x]);
			$cogsit 		= formatNumberToInsertDB($cogs[$x]);
			$outletit 		= dec($outlet[$x]);

			$inventory 								= array();	
			$inventory['inventoryCount'] 			= iftn($countit,0);
			$inventory['inventoryCOGS'] 			= iftn($cogsit,0);
			$inventory['supplierId'] 				= dec($supplier[$x]);
			$inventory['inventoryExpirationDate'] 	= iftn($expires[$x],NULL);

			$update = $db->AutoExecute('inventory', $inventory, 'UPDATE', 'inventoryId = '.$db->Prepare(dec($invId[$x]))); 

			addToHistory(($countit)?$countit:0, ($reorderit)?$reorderit:0, 'manual', false, $db->Prepare($outletit), $db->Prepare(dec($id)));
			$avrCOGS += $cogsit;
		}
	}

	updateItemAverageCOGS(dec($id),$avrCOGS,$totalRows);
	updateLastTimeEdit();
	dai('true|0|'.$id);
}






//---------------PROCESS-------------------//
?>

<!DOCTYPE html>
<html class="no-js">
<head>
<!-- meta -->
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
<title><?=COMPANY_NAME?> Artículos</title>

<?=coreFiles('top')?>
<link rel="stylesheet" href="/assets/vendor/css/select2-4.0.6.min.css" type="text/css" />
<link rel="stylesheet" href="css/select2-bootstrap.css" type="text/css" />

<?=jsGlobals();?>

</head>
<body class="bg-white">
<?php //maintaining()?>

	<?=head(true);?>

	<div class="bg-light hidden-print col-xs-12 wrapper-sm">
		<div class="col-sm-6 col-xs-3 text-left m-t-xs">
			<?=displayBulkBtns('')?>
		</div>

		<div class="col-sm-6 col-xs-9 text-right">
			<a href="/produce" class="btn btn-default hidden-xs m-r-xs" id="">Producir</a>
			<a href="/purchase" class="btn btn-default hidden-xs" id="">Comprar</a>
			<a href="/transfer" class="btn btn-default hidden-xs" id="">Transferir</a>
		</div>
	</div>

	<div class="wrapper col-xs-12">
		<div class="col-xs-9 hidden-xs">
			
		</div>

		<div class="col-md-3 text-right col-sm-6">
			<span class="font-thin h2">
		      Inventario
		    </span>
		</div>
	</div>

	<div class="wrapper col-xs-12 panel push-chat-down">
		<div class="tableContainer">
			<div class="table-responsive no-border">
				<table class="table hover no-padder">
					
				</table>
			</div>
		</div>
		<?php
		if(PLAN == '0'){
		?>
		<div class="col-xs-12 text-center">
			<div class="col-sm-3"></div>
			<div class="col-sm-6">
				<h2 class="text-warning">Solo 20 arttículos son mostrados</h2>
				
				<p class="text-muted">
					El plan FREE posee un limite de 20 artículos (contando las opciones de cada uno). Si anteriormente tenías más artículos, no te preocupes que no se eliminaron. <strong>Por tan solo $5 (dólares americanos), puedes actualizar al plan MICRO y almacenar hasta 100 artículos!. </strong> Si deseas aún más, tenemos planes que se adaptan a tus necesidades y las de tu empresa.
				</p>
			</div>
			<div class="col-sm-3"></div>
		</div>
		<?php
		}
		?>
	</div>

	<div class="modal fade" tabindex="-1" id="modalLoad" role="dialog">
	  <div class="modal-dialog modal-lg">
	    <div class="modal-content">
	      <div class="modal-body">
	        
	      </div>
	    </div>
	  </div>
	</div>

	<div class="modal fade" tabindex="-1" id="modalItem" role="dialog">
	  <div class="modal-dialog modal-lg">
	    <div class="modal-content">
	      
	    </div>
	  </div>
	</div>

	<?php
	footerInjector();
	?>

	<script type="text/javascript">
		var companyId 			= '<?=enc(COMPANY_ID);?>';
		var thousandSeparator 	= '<?=THOUSAND_SEPARATOR?>';
		var decimal 			= '<?=DECIMAL?>';
		var currency 			= '<?=CURRENCY?>';		
	</script>
	<?=coreFiles()?>
	<script type="text/javascript" src="/assets/vendor/js/select2-4.1.0.min.js"></script>
	<script type="text/javascript">
		$(document).ready(function(){
			FastClick.attach(document.body);
			window.info1 = {
						"container" 	: ".tableContainer",
						"url" 			: "?showTable=true",
						"table" 		: ".table",
						"sort" 			: 2,
						"currency" 		: "",
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"allowChild"	: true,
						"allowChildHide" : false,
						"allowChildBg" : 'bg-light lter',
						"showPagination" : false
		 	};

		 	manageTable(info1,function(){
		 		$('.datepicker').datetimepicker({
				  format: 'YYYY-MM-DD HH:mm:ss'
				});
		 	});

		 	switchit();

		 	onClickWrap('.toggleInventory',function(event,tis){
				var classis = tis.data('inv');
				$(classis).toggleClass('hidden');
			});

			
		});
	</script>

</body>
</html>
<?php
dai();
?>