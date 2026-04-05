<?php

include_once('includes/top_includes.php');
topHook();
allowUser('items', 'view');

$timeSE 				= startPageLoadTimeCalculator();

$baseUrl 			= '/' . basename(__FILE__, '.php');

$roc 					= (OUTLETS_COUNT > 1) ? getROC(1) : getROC(1, 1);
$limitDetail	= 100;
$offsetDetail	= 0;
//---------------UI-------------------


if (validateHttp('action') == 'searchItemInputJson') {
	$query  	= db_prepare(validateHttp('q'));
	$queryl  	= strtolower($query);
	$sql    = 'SELECT itemId, itemName, itemSKU, itemUOM, taxId FROM item WHERE (itemName LIKE "%' . $queryl . '%" OR itemSKU LIKE "%' . $queryl . '%") AND ' . $SQLcompanyId . ' AND itemStatus = 1 LIMIT 200';

	$result = ncmExecute($sql, [], false, true);
	$json   = [];

	if ($result) {
		while (!$result->EOF) {
			$stock 	= getItemStock($result->fields['itemId']);
			$json[] = [
				'name' 	=> toUTF8($result->fields['itemName']),
				'sname' => mb_strtolower(toUTF8($result->fields['itemName']), 'UTF-8'),
				'ssku' 	=> strtolower($result->fields['itemSKU']),
				'id' 	=> enc($result->fields['itemId']),
				'uom' 	=> $result->fields['itemUOM'],
				'cost'	=> formatCurrentNumber($stock['stockOnHandCOGS'] ?? 0),
				'tax' 	=> enc($result->fields['taxId'])
			];
			$result->MoveNext();
		}
		$result->Close();
	}

	dai(json_encode($json));
}

if (validateHttp('action') == 'searchItemStockableInputJson') {
	$query  = db_prepare(validateHttp('q'));

	$roc 		= (OUTLETS_COUNT > 1) ? ' AND (outletId = ' . OUTLET_ID . ' OR outletId IS NULL)' : '';

	$sql    = 'SELECT itemId, itemName, itemSKU, itemUOM, taxId FROM item WHERE (itemName LIKE "%' . $query . '%" OR itemSKU LIKE "%' . $query . '%") AND itemType IN("product","compound","production") AND itemTrackInventory > 0 AND itemStatus = 1 AND ' . $SQLcompanyId . $roc . ' LIMIT 300';



	$result = ncmExecute($sql, [], false, true);
	$json   = [];

	if ($result) {
		while (!$result->EOF) {
			$fields = $result->fields;
			$stock 	= getItemStock($result->fields['itemId']);
			$taxName = getTaxValue($fields['taxId']);
			if (empty($taxName)) {
				$taxName = "0";
			}
			$json[] = [
				'name' 	=> toUTF8($fields['itemName']),
				'sname' => mb_strtolower(toUTF8($result->fields['itemName']), 'UTF-8'),
				'ssku' 	=> toUTF8(strtolower($fields['itemSKU'])),
				'id' 		=> enc($fields['itemId']),
				'uom' 	=> toUTF8($fields['itemUOM']),
				'cost'	=> formatCurrentNumber($stock['stockCOGS'] ?? 0),
				'tax' 	=> $taxName,
			];
			$result->MoveNext();
		}
		$result->Close();
	}

	dai(json_encode($json));
}

if (validateHttp('action') == 'bulkEditForm') {
?>

	<div class="col-xs-12 no-padder bg-white r-24x clear">

		<form action="<?= $baseUrl; ?>?action=bulkUpdate" method="post" id="editItemBulk" name="editItemBulk">
			<div class="col-xs-12 wrapper">
				<div class="h2 text-u-c font-bold wrapper m-b">
					Edición Masiva
					<div class="text-xs text-muted font-normal">
						Todo lo que modifique en esta sección se aplicará automáticamente a todos los artículos y grupos seleccionados
					</div>
				</div>


				<div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Precio en <?= CURRENCY ?></span>
					<input type="text" class="form-control no-padder no-border maskCurrency no-bg b-b" name="sellingPrice" value="" autocomplete="off" />
				</div>
				<div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs text-u-l pointer" data-toggle="tooltip" title="Ajustará el precio actual basado en el porcentaje asignado, ej. si coloca 5, aumentará el precio un 5%, si coloca -10, disminuirá el precio un 10%">Ajuste de Precio en %</span>
					<input type="text" class="form-control no-padder no-border no-bg b-b text-right" placeholder="0" name="percentPrice" value="" autocomplete="off">
				</div>

				<div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Impuesto</span>
					<?php
					echo selectInputTax(false, false, 'no-bg no-border searchSimple b-b b-light m-b');
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
								<li class="priceTypeBtn" data-symbol="<?= CURRENCY ?>">
									<a href="#"><b><?= CURRENCY ?></b></a>
								</li>
							</ul>
						</div>

					</div>
				</div>

				<div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Categoría</span>
					<?php
					echo selectInputCategory(enc($result->fields['categoryId']), false, 'category no-bg no-border b-b b-light m-b searchSimple needsclick', 'category', true);
					?>
					<div class="m-t-xs">
						<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear" data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
						<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Renombrar" data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
						<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Remover" data-toggle="tooltip" data-placement="top"><i class="material-icons text-danger">close</i></a>
					</div>
				</div>
				<div class="col-sm-6 m-b">
					<span class="font-bold text-u-c text-xs">Marca</span>
					<?php
					$brand = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "brand" AND ' . $SQLcompanyId . ' ORDER BY taxonomyName ASC LIMIT ' . $plansValues[PLAN]['max_brands'], [], false, true);
					?>
					<select id="brandEdit" name="brand" tabindex="1" data-placeholder="Seleccione una Marca" class="form-control brand no-bg no-border b-b b-light m-b searchSimple needsclick" autocomplete="off">
						<option value="">Seleccionar</option>
						<?php
						if ($brand) {
							while (!$brand->EOF) {
								$brandId = enc($brand->fields['taxonomyId']);
						?>
								<option value="<?= $brandId; ?>">
									<?= $brand->fields['taxonomyName']; ?>
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
					<?= selectInputOutlet(false, false, 'no-bg no-border b-b searchSimple b-light', 'outlet', true, true); ?>
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
								<li class="comissionTypeBtn" data-symbol="<?= CURRENCY ?>">
									<a href="#"><b><?= CURRENCY ?></b></a>
								</li>
							</ul>
						</div>

					</div>
				</div>



				<div class="col-sm-6 m-b" style="height:68px;">
				</div>

				<?php if (SCHEDULE) { ?>

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

if (validateHttp('action') == 'editform' && validateHttp('id')) {
	theErrorHandler('json');

	$result 						= ncmExecute('SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [dec(validateHttp('id')), COMPANY_ID]);
	$jResult 						= json_decode($result['data'] ?? "", true);
	$itemId 						= enc($result['itemId']);
	$realType						= iftn($result['itemType'], 'product');
	$type 							= $realType;
	$compSetCostprice 	= '0';
	$itemCanSale 				= $result['itemCanSale'];
	$itemDiscount 			= $result['itemDiscount'];
	$itemStatus 				= $result['itemStatus'];
	$itemOnline 				= array_key_exists('itemOnline', $result) && $result['itemOnline'];
	$itemSessions 			= $result['itemSessions'];
	$itemDuration 			= $result['itemDuration'];
	$isParent 					= $result['itemIsParent'];
	$itemProcedure 			= $result['itemProcedure'];
	$itemTrackInventory = $result['itemTrackInventory'];
	$itemComission		= $result['itemComissionPercent'];
	$itemPricePercent 	= $result['itemPricePercent'];
	$upsellDescription 	= $result['itemUpsellDescription'];
	$productionTools 	= false;
	$inventoryTools 	= false;
	$comboTools 		= false;

	//TYPE
	if ($realType == 'product') {

		if ($result['itemProduction'] > 0) {
			$type 						= 'production';
			$typeName 				= 'Producción Previa';
			$inventoryTools 	= true;
		} else if ($result['itemType'] == 'product' && $result['itemTrackInventory'] < 1 && validity(getCompoundsArray($result['itemId']))) {
			$type 						= 'direct_production';
			$typeName 				= 'Producción Directa';
			$productionTools 	= true;
		} else if ($result['itemCanSale'] < 1) {
			$type 						= 'compound';
			$typeName 				= 'Activo/Compuesto';
			$inventoryTools 	= true;
		} else {
			$typeName 				= 'Producto';
			$productionTools 	= true;
			$inventoryTools 	= true;
		}
	} else if ($realType == 'precombo') {
		$typeName 				= 'Combo Predefinido';
		$comboTools 			= true;
	} else if ($realType == 'combo') {
		$typeName 				= 'Combo Dinámico';
		$comboTools 			= true;
	} else if ($realType == 'comboAddons') {
		$typeName 				= 'Combo Add-on';
		$comboTools 			= true;
		$productionTools 	= false;
	} else if ($realType == 'production') {
		$typeName 				= 'Producción Previa';
		$productionTools 	= true;
	} else if ($realType == 'direct_production') {
		$typeName 				= 'Producción Directa';
		$productionTools 	= true;
	} else if ($realType == 'dynamic') {
		$typeName 				= 'Dinámico';
		$productionTools 	= false;
		$inventoryTools 	= false;
	}

	if (!$_modules['production']) {
		$productionTools 	= false;
	}

	$inventoryTools 		= true;

	$itemPrice 					= $result['itemPrice'];

	if ($result['itemTrackInventory']) {
		$itemStock 				= getItemStock($result['itemId']);

		if ($result['itemPriceType']) { //si el precio es porcentual al costo
			$cogs = $itemStock['stockCOGS'];
			if ($result['itemPricePercent'] < 1) {
				$itemPrice = $cogs;
			} else {
				$addPrice = ($cogs * $result['itemPricePercent']) / 100;
				$itemPrice = $cogs + $addPrice;
			}
		}
	}

	//createInventory($result['itemId']);  
	$letters 			= '';
	$bg 					= 'opacity';
	$img 					= 'https://assets.encom.app/250-250/0/' . enc(COMPANY_ID) . '_' . $itemId . '.jpg?' . $result['updated_at'];

	if ($result['itemCanSale'] > 0 && $result['itemTrackInventory'] < 1) {
		// servicio
		$itemTypeOne = 'selected';
	} else if ($result['itemCanSale'] > 0 && $result['itemTrackInventory'] > 0) {
		//a la venta y con inventario
		$itemTypeTwo = 'selected';
	} else if ($result['itemCanSale'] < 1 && $result['itemTrackInventory'] > 0) {
		//a la venta y sin inventario
		$itemTypeThree = 'selected';
	} else if ($result['itemCanSale'] < 1 && $result['itemTrackInventory'] < 1) {
		//no se vende y si inventario
		$itemTypeFour = 'selected';
	}

	if ($result['itemType'] == 'dynamic') {
		$itemTypeFive = 'selected';
	}

	if (validateHttp('outcall')) {
		$itemTypeTwo = 'selected';
	}

	if ($result['itemTrackInventory'] < 1) {
		$hideInventoryOps = 'hidden';
	} else {
		$hideInventoryOps = '';
	}

	$hideInventoryOps .= ' animated fadeIn';

	$archiveSelected = ($result['itemStatus'] > 0) ? true : false;
	$reorderSelected = $result['autoReOrder'];

?>

	<div class="modal-body no-padder bg-white">
		<form action="<?= $baseUrl; ?>?action=update" method="post" id="editItem" name="editItem">

			<?php
			if ($type == 'giftcard') { //giftcards
				$expCount 	= explodes(' ', $result['itemDescription'], true, 0);
				$expTime 	= explodes(' ', $result['itemDescription'], true, 1);
			?>
				<style type="text/css">
					.btn-colorselector {
						border: 2px solid #fff;
					}
				</style>
				<div class="col-sm-12 wrapper <?= iftn($result['itemSKU'], 'gradBgOrange', ''); ?> clear" id="giftBg" style="<?= iftn($result['itemSKU'], '', 'background:#' . $result['itemSKU']); ?>">
					<div class="col-xs-12 no-padder text-center m-b-md">
						<div>
							<i class="material-icons text-white" style="font-size:120px!important;">card_giftcard</i>
						</div>
					</div>

					<div class="col-xs-12 no-padder text-center text-white">
						Añada un nombre y un monto mínimo inicial
					</div>

					<div class="col-sm-8 wrapper-xs m-b">
						<input type="text" id="insertItemName" class="form-control maskRequiredText no-border b-b b-white no-bg font-bold text-white" style="font-size:25px; height:55px;" name="name" placeholder="Nombre" value="<?= toUTF8($result['itemName']); ?>" autocomplete="off">
					</div>

					<div class="col-sm-4 wrapper-xs m-b">
						<input type="text" class="sellingPriceEdit maskCurrency prices text-right form-control no-border b-b b-white no-bg font-bold text-white" name="sellingPrice" value="<?= formatCurrentNumber($itemPrice); ?>" autocomplete="off" style="font-size:25px; height:55px;" />
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
						echo selectInputTax($result['taxId'], false, 'no-bg no-border b-b b-light m-b font-bold text-white', true);
						?>
					</div>

					<div class="col-sm-3 col-xs-6 m-b-xs" data-toggle="tooltip" title="Duración de esta Gift Card">
						<input type="text" class="form-control text-right maskInteger no-border b-b b-white no-bg font-bold text-white" name="giftExpCount" value="<?= iftn($expCount, '1') ?>" autocomplete="off">
						<input type="hidden" name="typeOfItem" value="giftcard">
					</div>

					<div class="col-sm-3 col-xs-6 m-b-xs">
						<select name="giftExpTime" class="form-control no-border b-b b-light no-bg font-bold no-bg text-white" autocomplete="off">
							<option value="year" <?= ($expTime == 'year') ? 'selected' : '' ?>>Año/s</option>
							<option value="month" <?= ($expTime == 'month') ? 'selected' : '' ?>>Mes/es</option>
							<option value="day" <?= ($expTime == 'day') ? 'selected' : '' ?>>Día/s</option>
						</select>
					</div>

					<div class="col-xs-12">
						<div class="col-sm-3 col-xs-6 no-padder m-b">
							<div class="font-bold text-u-c text-xs">Online</div>
							<?= switchIn('ecom', $result['itemEcom']) ?>

							<div class="font-bold text-u-c text-xs m-t">Destacado</div>
							<?= switchIn('featured', $result['itemFeatured']) ?>
						</div>

						<div class="col-sm-4 col-xs-12 no-padder m-b">
							<a href="/@#report_inventory?ii=<?= $itemId ?>" target="_blank" class="btn text-white text-u-l" data-toggle="tooltip" title="Lleve un control físico de las tarjetas vendidas">
								Historial de tarjetas
							</a>
							<a href="/@#report_products?ii=<?= $itemId ?>" target="_blank" class="btn text-white text-u-l" data-toggle="tooltip" title="Reporte detallado de ventas">
								Historial de ventas
							</a>
						</div>

						<div class="col-sm-5 col-xs-12 no-padder m-b">
							<span class="font-bold text-u-c text-xs">Categoría</span>
							<?php
							echo selectInputCategory(enc($result['categoryId']), false, 'category no-bg no-border b-b b-light m-b searchSimple');
							?>

							<div class="m-t-xs">
								<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear" data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
								<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Renombrar" data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
								<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Remover" data-toggle="tooltip" data-placement="top"><i class="material-icons text-danger">close</i></a>
								<a href="#" class="toggleItemPart btn btn-sm bg-light lter hidden" data-table="category" data-select="category" title="Ver/Ocultar categoría en la caja registradora" data-toggle="tooltip" data-placement="top"><i class="material-icons">remove_red_eye</i></a>
							</div>
						</div>
					</div>

					<div class="col-xs-12 no-padder m-t-md">
						<?php
						if (!$archiveSelected) { //si es para eliminar
						?>
							<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?= $_GET['id'] ?>" data-type="deleteItem" data-load="<?= $baseUrl; ?>?action=delete&id=<?= $_GET['id'] ?>" data-toggle="tooltip" data-placement="right" title="Eliminar Artículo"><span class="text-white font-bold">Eliminar</span></a>
						<?php
						} else {
						?>
							<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?= $_GET['id'] ?>" data-type="archiveItem" data-load="<?= $baseUrl; ?>?action=archive&id=<?= $_GET['id'] ?>" data-toggle="tooltip" data-placement="right" title="Archivar Artículo"><span class="text-white font-bold">Archivar</span></a>
						<?php
						}
						?>

						<input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="Guardar">
						<a href="#" class="cancelItemView m-r-lg m-t  pull-right">Cancelar</a>
						<input type="hidden" value="<?= $_GET['id'] ?>" name="id">
						<input type="hidden" value="<?= $type ?>" name="itemType">
					</div>

				</div>

			<?php
			} else if ($type == 'discount') { //descuentos
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
						<input type="text" id="insertItemName" class="form-control maskRequiredText no-border b-b b-white no-bg font-bold text-white" style="font-size:25px; height:55px;" name="name" placeholder="Nombre" value="<?= toUTF8($result['itemName']); ?>" autocomplete="off">
					</div>

					<div class="col-sm-4 no-padder m-b">
						<input type="text" class="sellingPriceEdit maskInteger prices text-right form-control no-border b-b b-white no-bg font-bold text-white" name="sellingPrice" value="<?= formatCurrentNumber($result['itemPrice'], 'no'); ?>" autocomplete="off" style="font-size:25px; height:55px;" />
					</div>

					<div class="col-xs-12 m-b-sm text-left">
						<label>Descripción</label>
						<input type="text" class="form-control no-border b-b b-white no-bg font-bold text-white" name="description" value="<?= $result['itemDescription']; ?>" autocomplete="off">
					</div>

					<div class="col-xs-12 no-padder m-t-md">

						<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?= $_GET['id'] ?>" data-type="archiveItem" data-load="<?= $baseUrl; ?>?action=archive&id=<?= $_GET['id'] ?>" data-toggle="tooltip" data-placement="right" title="Archivar Artículo"><span class="text-danger font-bold">Archivar</span></a>

						<input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="Guardar">
						<a href="#" class="cancelItemView m-r-lg m-t  pull-right">Cancelar</a>
						<input type="hidden" value="<?= $_GET['id'] ?>" name="id">
						<input type="hidden" value="<?= $type ?>" name="itemType">
					</div>

				</div>

			<?php
			} else if ($type == 'group' || validity($result['itemIsParent'])) { //groups
				$type = 'group'; //por si type no es group pero si is parent
			?>
				<div class="col-xs-12 wrapper text-center bg-info gradBgBlue animateBg">
					<input type="text" id="insertItemName" class="form-control maskRequiredText text-center no-border b-b b-white no-bg font-bold text-white m-t m-b" style="font-size:25px; height:55px;" name="name" placeholder="Nombre" value="<?= toUTF8($result['itemName']); ?>" autocomplete="off">
				</div>

				<div class="col-xs-12 bg-white wrapper">

					<div class="list-group alt">
						<?php
						$modifier = ncmExecute('SELECT itemName, itemId, itemPrice, itemType FROM item WHERE itemStatus = 1 AND itemParentId = ' . $result['itemId'] . ' LIMIT 50', [], false, true);

						if ($modifier) {
							while (!$modifier->EOF) {
								$fields = $modifier->fields;
						?>
								<a class="clickrow pointer row<?= enc($fields['itemId']); ?> list-group-item wrapper-md <?= (in_array($fields['itemType'], ['giftcard', 'group'])) ? 'modal-narrow' : '' ?>" id="<?= enc($fields['itemId']); ?>">
									<span class="font-bold"><?= toUTF8($fields['itemName']) ?></span>

									<span class="pull-right ungroup pointer" data-id="<?= enc($fields['itemId']); ?>">
										<i class="material-icons text-danger">close</i>
									</span>

									<div class="pull-right m-r"><?= iftn($fields['itemPrice'], '', formatCurrentNumber($fields['itemPrice'])); ?></div>
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
						echo selectInputCategory(enc($result['categoryId']), false, 'category no-bg no-border b-b b-light m-b searchSimple');
						?>

						<div class="m-t-xs">
							<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear" data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
							<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Renombrar" data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
							<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Remover" data-toggle="tooltip" data-placement="top"><i class="material-icons text-danger">close</i></a>
							<a href="#" class="toggleItemPart btn btn-sm bg-light lter hidden" data-table="category" data-select="category" title="Ver/Ocultar categoría en la caja registradora" data-toggle="tooltip" data-placement="top"><i class="material-icons">remove_red_eye</i></a>
						</div>
						<div class="col-xs-12 m-t no-padder">
							<span class="font-bold text-u-c text-xs">Sucursal</span>
							<?= selectInputOutlet($result['outletId'], false, 'no-bg no-border searchSimple b-b b-light', 'outlet', true); ?>
						</div>
					</div>

					<div class="col-sm-3 col-xs-6 m-b">
						<div class="font-bold text-u-c text-xs">Online</div>
						<?= switchIn('ecom', $result['itemEcom']) ?>
						<div class="font-bold text-u-c text-xs m-t">Destacado</div>
						<?= switchIn('featured', $result['itemFeatured']) ?>
					</div>

					<div class="col-sm-3 col-xs-6 m-b">

					</div>


					<div class="col-xs-12 no-padder m-t-md">
						<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?= $_GET['id'] ?>" data-type="archiveItem" data-load="<?= $baseUrl; ?>?action=delete&id=<?= $_GET['id'] ?>" data-toggle="tooltip" data-placement="right" title="Archivar Artículo"><span class="text-danger">Eliminar</span></a>

						<input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="Guardar">
						<a href="#" class="cancelItemView m-r-lg m-t  pull-right">Cancelar</a>
						<input type="hidden" value="<?= $_GET['id'] ?>" name="id">
						<input type="hidden" value="<?= $type ?>" name="itemType">
					</div>
				</div>
			<?php
			} else { //PRODUCTO COMBO PRODUCCION
				//precio
				if ($result['itemDiscount'] > 0) {
					$displaydiscount	= ($result['itemDiscount'] * $result['itemPrice']) / 100;
					$finalPrice 			= $itemPrice - $displaydiscount;
				} else {
					$finalPrice 			= $itemPrice;
				}
			?>

				<div class="col-sm-4 wrapper text-white text-center clear hidden-xs bg-info gradBgBlue animateBg matchCols">
					<a href="#" class="col-xs-12 no-padder m-b-lg itemImageBtn" data-toggle="tooltip" data-placement="top" style="margin-top:40%" title="Subir Imagen">
						<img src="<?= $img ?>" width="225" height="225" class="itemImg all-shadows rounded">
					</a>
					<input type="hidden" class="itemImgFlag" name="itemImgFlag" value="<?= file_exists(SYSIMGS_FOLDER . '/' . enc(COMPANY_ID) . '_' . $itemId . '.jpg') ? 1 : 0; ?>">

					<div class="text-right col-xs-12 no-padder">
						<div class="font-bold">
							<?= toUTF8($result['itemName']); ?>
						</div>

						<?php
						if ($result['itemCanSale'] > 0) {
						?>
							<div class="font-bold h1">
								<?php
								if ($result['itemStatus'] > 0) {
									echo CURRENCY . formatCurrentNumber($finalPrice);
								} else {
									echo '<span class="text-u-c text-warning">Archivado</span>';
								}
								?>
							</div>
						<?php
						}
						?>
						<em class="">
							SKU <?= ($result['itemSKU']) ? toUTF8($result['itemSKU']) : $itemId; ?>
						</em>
						<div class="text-sm">
							<?= toUTF8($result['itemDescription']); ?>
						</div>
						<div class="text-sm">
							<span class="badge bg-white text-info"><?= $typeName ?? ''; ?></span>
						</div>

						<?php
						if ($result['itemParentId']) {
							$parentName = getItemName($result['itemParentId']);
						?>
							<div class="text-sm">
								Grupo <?= $parentName; ?>
							</div>
						<?php
						}

						if ($result['categoryId']) {
						?>
							<div class="text-sm font-bold">
								<?= getTaxonomyName($result['categoryId']); ?>
							</div>
						<?php
						}

						if ($result['brandId']) {
						?>
							<div class="text-sm font-bold">
								<?= getTaxonomyName($result['brandId']); ?>
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

								<li class="<?= validateHttp('outcall') ? 'hidden' : '' ?>">
									<a href="#settingsTab" class="tabs" title="Ajustes">
										<i class="material-icons">settings</i>
									</a>
								</li>

								<?php
								if ($productionTools && !validateHttp('outcall')) {
								?>
									<li>
										<a href="#productionTab" class="tabs" title="Producción">
											<i class="material-icons">precision_manufacturing</i>
										</a>
									</li>
								<?php
								}
								?>

								<?php
								if ($inventoryTools) {
								?>
									<li class="inventoryTools <?= $hideInventoryOps ?>">
										<a href="#inventoryTab" class="tabs" title="Inventario">
											<i class="material-icons">inventory</i>
										</a>
									</li>
								<?php
								}
								?>
								<?php
								if ($comboTools) {
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
								if ($result['itemCanSale'] > 0) {
								?>
									<li data-toggle="tooltip" title="Ver historial de ventas" class="<?= validateHttp('outcall') ? 'hidden' : '' ?>">
										<a href="/@#report_products?ii=<?= enc($result['itemId']) ?>" target="_blank">
											<i class="material-icons">history</i>
										</a>
									</li>

									<li>
										<a href="#dateHoursTab" class="tabs" title="Días y Horas">
											<i class="material-icons">date_range</i>
										</a>
									</li>

									<li>
										<a href="#dateHoursTab" class="tabs setCurrenciesBtn" data-id="<?= $_GET['id'] ?>" title="Configurar Monedas">
											<i class="material-icons">toll</i>
										</a>
									</li>


								<?php
								}
								?>

								<?php
								if ($_modules['dropbox']) {
								?>
									<li>
										<a href="#ncmDBItemFilesTab" class="tabs" title="Archivos">
											<img src="https://aem.dropbox.com/cms/content/dam/dropbox/www/en-us/branding/app-dropbox-android@2x.png" height="19">
										</a>
									</li>
								<?php
								}
								?>



								<li onclick="updateDate()">
									<a href="#lotesTab" class="tabs lotesBtn" data-id="a<?= $_GET['id'] ?>" title="Vencimiento de lotes">
										<i class="material-icons">grid_view</i>
									</a>
								</li>


							</ul>
						</header>
					</div>

					<div class="tab-content">

						<div class="tab-pane active bg-white col-xs-12 no-padder animated fadeIn speed-4x" id="dataTab" style="min-height: 400px;">
							<div class="col-xs-12 wrapper-md">
								<input type="file" name="image" id="itemImageInput" accept="image/*" style="position:absolute;margin-left:-3000px;" />
								<div class="col-xs-12 no-padder text-center visible-xs">
									<a href="#" class="col-xs-12 no-padder m-b itemImageBtn needsclick" title="Subir Imagen">
										<img src="<?= $img ?>" width="225" height="225" class="itemImg rounded">
									</a>
									<div class="m-b">Doble tap para subir una imagen</div>
								</div>

								<input type="text" id="insertItemName" class="form-control maskRequiredText no-padder no-border no-bg font-bold text-dark " style="font-size:30px; height:55px;" name="name" placeholder="Nombre del Artículo" value="<?= toUTF8($result['itemName']); ?>" autocomplete="off">

								<div class="col-sm-6">
									<input type="text" class="form-control no-padder no-border no-bg text-muted font-bold text-u-c " name="uid" placeholder="SKU o Código de Barras" value="<?= ($result['itemSKU']) ? $result['itemSKU'] : (array_key_exists("itemAutoSKU", $result) && $result['itemAutoSKU']); ?>" autocomplete="off">
								</div>
								<?php
								if ($result['itemCanSale'] > 0) {
								?>
									<div class="col-sm-6">
										<?php

										$inventoryArray 	= [];
										$COGS 						= 0;
										$margin 					= 0;

										if (validity($finalPrice)) {
											if (validity($COGS)) {
												$dif 		= $finalPrice - $COGS;
												$margin = ($dif / $finalPrice) * 100;
											} else {
												$margin = 100;
											}
										}

										?>

										<?php
										if ($itemDiscount > 0) {
										?>
											<div class="text-danger pull-right badge bg-light lter text-l-t m-r"> <?= formatCurrentNumber($result['itemPrice']) ?> </div>
											<div class="col-xs-12 b-b b-3x m-b-xs no-padder text-right font-bold text-info" data-toggle="tooltip" data-placement="top" title="Para modificar elimine el descuento fijo" style="font-size:25px;">
												<?= formatCurrentNumber($finalPrice); ?>
											</div>
											<input type="hidden" name="sellingPrice" value="<?= formatCurrentNumber($result['itemPrice']); ?>">
										<?php
										} else {
										?>
											<input type="text" class="sellingPriceEdit form-control prices input-lg font-bold text-right maskCurrency no-bg no-border b-b b-3x m-b-xs text-info <?= ($result['itemPriceType']) ? 'disabled' : '' ?>" name="sellingPrice" style="font-size:25px;" value="<?= formatCurrentNumber($finalPrice); ?>" placeholder="Precio" autocomplete="off" <?= ($result['itemPriceType']) ? 'disabled' : '' ?>>
										<?php
										}
										?>
									</div>

									<?php

									$markup 	= 0;
									$margin 	= 0;

									$COGS 		= $itemStock['stockOnHandCOGS'] ?? 0;
									$grossP 	= $finalPrice - $COGS;

									if ($grossP > 0) {
										$markup 	= divider($grossP, $COGS, true) * 100;
										$margin 	= divider($grossP, $finalPrice, true) * 100;
									}

									?>
									<div class="col-xs-12 no-padder text-sm text-u-c text-muted text-right font-bold">
										Costo: <span class="text-default m-r-sm"><?= CURRENCY . formatCurrentNumber($COGS); ?></span>
										Markup: <span class="text-default m-r-sm"><?= formatCurrentNumber($markup, 'no'); ?>%</span>
										Margen: <span class="text-default m-r-sm"><?= formatCurrentNumber($margin, 'no'); ?>%</span>
										Ganancia: <span class="text-default m-r-sm"><?= CURRENCY . formatCurrentNumber($grossP); ?></span>
									</div>
								<?php

								}
								?>

								<div class="col-xs-12 no-padder m-t-md m-b-lg">
									<label class="font-bold text-u-c text-xs">Descripción</label>
									<textarea class="form-control no-border b-b b-light no-bg text-muted" placeholder="Descripción general" name="description" autocomplete="off"><?= $result['itemDescription']; ?></textarea>
								</div>

								<div class="col-xs-12 no-padder m-t">
									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Impuesto</span>
										<?php
										echo selectInputTax($result['taxId'], false, 'no-bg no-border searchSimple b-b b-light m-b');
										?>
									</div>

									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Categoría</span>
										<?php
										echo selectInputCategory(enc($result['categoryId']), false, 'category no-bg no-border b-b b-light m-b searchSimple', 'category', true);
										?>

										<div class="m-t-xs <?= validateHttp('outcall') ? 'hidden' : '' ?>">
											<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear" data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
											<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Renombrar" data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
											<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Remover" data-toggle="tooltip" data-placement="top"><i class="material-icons text-danger">close</i></a>
											<a href="#" class="toggleItemPart btn btn-sm bg-light lter hidden" data-table="category" data-select="category" title="Ver/Ocultar categoría en la caja registradora" data-toggle="tooltip" data-placement="top"><i class="material-icons">remove_red_eye</i></a>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div class="tab-pane bg-white col-xs-12 wrapper animated fadeIn speed-4x" id="settingsTab" style="min-height: 400px;">
							<div class="col-xs-12 no-padder">

								<?php
								if (in_array($type, ['product', 'compound', 'dynamic'])) {
								?>
									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Tipo</span>
										<select id="tipo" name="typeOfItem" tabindex="1" data-itemid="<?= $_GET['id'] ?>" data-placeholder="Seleccione un tipo" class="form-control no-bg no-border b-b b-light" autocomplete="off">
											<option value="0" <?= $itemTypeOne ?? "" ?>>Servicio o Producto (Sin Inventario)</option>
											<option value="1" <?= $itemTypeTwo ?? "" ?>>Producto (Con Inventario)</option>
											<option value="2" <?= $itemTypeThree ?? "" ?>>Activo Fijo/Compuesto (Con Inventario)</option>
											<option value="3" <?= $itemTypeFour ?? "" ?>>Activo Fijo/Compuesto (Sin Inventario)</option>
											<option value="dynamic" <?= $itemTypeFive ?? "" ?>>Dinámico</option>
										</select>
									</div>
								<?php
								}
								?>

								<div class="col-sm-6 m-b">
									<span class="font-bold text-u-c text-xs">Marca</span>

									<?php
									$brand = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "brand" AND ' . $SQLcompanyId . ' ORDER BY taxonomyName ASC LIMIT ' . $plansValues[PLAN]['max_brands'], [], false, true);
									?>
									<select id="brandEdit" name="brand" tabindex="1" data-placeholder="Seleccione una Marca" class="form-control brand no-bg no-border b-b b-light m-b searchSimple needsclick" autocomplete="off">
										<option value="">Seleccionar</option>
										<?php
										if ($brand) {
											while (!$brand->EOF) {
												$brandId = enc($brand->fields['taxonomyId']);
										?>
												<option value="<?= $brandId; ?>" <?= ($brand->fields['taxonomyId'] == $result['brandId']) ? 'selected' : ''; ?>>
													<?= $brand->fields['taxonomyName']; ?>
												</option>
										<?php
												$brand->MoveNext();
											}
											$brand->Close();
										}
										?>
									</select>
									<div class="m-t-xs <?= validateHttp('outcall') ? 'hidden' : '' ?>">
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
			                  	<?php */ ?>
								</div>

								<div class="col-sm-6 m-b">
									<span class="font-bold text-u-c text-xs">Sucursal</span>
									<?= selectInputOutlet($result['outletId'], false, 'no-bg no-border searchSimple b-b b-light', 'outlet', true); ?>
								</div>

								<?php
								if (in_array($type, ['product', 'production', 'direct_production'])) {
								?>

									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Descuento %</span>
										<input type="text" class="form-control no-padder no-border maskPercent no-bg b-b" placeholder="0" name="itemDiscount" value="<?= number_format($itemDiscount, 3, '.', '') ?>" autocomplete="off" <?= ($comboTools) ? 'readonly' : '' ?>>
									</div>

								<?php
								}
								?>

								<?php
								if (in_array($type, ['product', 'production', 'compound'])) {
								?>
									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Unidad de medida</span>
										<input type="text" class="form-control no-padder no-border no-bg b-b" name="uom" placeholder="Ej. ml" value="<?= $result['itemUOM'] ?>" autocomplete="off">
									</div>
									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Merma %</span>
										<input type="text" class="form-control no-padder no-border maskInteger no-bg b-b" name="waste" placeholder="0" value="<?= $result['itemWaste'] ?>" autocomplete="off">
									</div>
									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Prioridad de Ordenamiento</span>
										<input type="text" class="form-control no-padder no-border maskInteger no-bg b-b" name="sort" placeholder="0" value="<?= $result['itemSort'] ?? '' ?>" autocomplete="off">
									</div>
								<?php
								}
								?>

								<?php
								if (in_array($type, ['product', 'production', 'direct_production'])) {
								?>

									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Comisión</span>
										<div class="input-group">
											<input type="text" class="form-control no-border maskInteger no-bg b-b text-left" placeholder="0" name="itemComission" value="<?= ($result['itemComissionType']) ? formatCurrentNumber($itemComission) : (int)$itemComission; ?>" autocomplete="off" id="itemComission">

											<div class="input-group-btn">
												<button class="btn btn-default no-border dropdown-toggle" data-toggle="dropdown">
													<span class="dropdown-label" id="comissionType"><b><?= ($result['itemComissionType']) ? CURRENCY : '%' ?></b></span>
													<span class="caret"></span>
													<input class="comissionType" type="hidden" value="<?= ($result['itemComissionType']) ? CURRENCY : '%' ?>" name="comissionType">
												</button>
												<ul class="dropdown-menu dropdown-select pull-right">
													<li class="<?= ($result['itemComissionType']) ? '' : 'active' ?> comissionTypeBtn" data-symbol="%">
														<a href="#"><b>%</b></a>
													</li>
													<li class="<?= ($result['itemComissionType']) ? 'active' : '' ?> comissionTypeBtn" data-symbol="<?= CURRENCY ?>">
														<a href="#"><b><?= CURRENCY ?></b></a>
													</li>
												</ul>
											</div>

										</div>
									</div>

									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Tipo de Precio</span>
										<div class="input-group">
											<input type="text" class="form-control no-border maskPercentInt no-bg b-b text-right <?= ($result['itemPriceType']) ? '' : 'disabled' ?>" placeholder="0" name="itemPricePercent" id="itemPricePercent" value="<?= ($result['itemPriceType']) ? $itemPricePercent : '0' ?>" autocomplete="off" <?= ($result['itemPriceType']) ? '' : 'disabled' ?>>

											<div class="input-group-btn">
												<button class="btn btn-default no-border dropdown-toggle" data-toggle="dropdown">
													<span class="dropdown-label" id="priceType"><b><?= ($result['itemPriceType']) ? '%' : CURRENCY ?></b></span>
													<span class="caret"></span>
													<input class="priceType" type="hidden" value="<?= ($result['itemPriceType']) ? '%' : CURRENCY ?>" name="priceType">
												</button>
												<ul class="dropdown-menu dropdown-select pull-right">
													<li class="<?= ($result['itemPriceType']) ? 'active' : '' ?> priceTypeBtn" data-symbol="%">
														<a href="#"><b>%</b></a>
													</li>
													<li class="<?= ($result['itemPriceType']) ? '' : 'active' ?> priceTypeBtn" data-symbol="<?= CURRENCY ?>">
														<a href="#"><b><?= CURRENCY ?></b></a>
													</li>
												</ul>
											</div>

										</div>

										<div class="font-bold text-u-c text-xs m-t"><?= TAX_NAME ?> Incluído</div>
										<?= switchIn('taxIncluded', $jResult['itemTaxIncluded'] ?? false) ?>
									</div>

								<?php
								}
								?>

								<?php if (SCHEDULE) { ?>
									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Sesiones</span>
										<input type="text" class="form-control no-padder no-border maskInteger no-bg b-b" placeholder="" name="itemSessions" value="<?= $itemSessions ?>" autocomplete="off">
									</div>
									<div class="col-sm-6 m-b">
										<span class="font-bold text-u-c text-xs">Duración min.</span>
										<input type="text" class="form-control no-padder no-border maskInteger no-bg b-b" placeholder="" name="itemDuration" value="<?= $itemDuration ?>" autocomplete="off">
									</div>
								<?php } ?>

								<?php if ($type != 'dynamic') { ?>
									<div class="col-sm-6 m-b">
										<div class="font-bold text-u-c text-xs">Online</div>
										<?= switchIn('ecom', $jResult['itemEcom'] ?? false) ?>
									</div>

									<div class="col-sm-6 m-b">
										<div class="font-bold text-u-c text-xs">Destacado</div>
										<?= switchIn('featured', $jResult['itemFeatured'] ?? false) ?>
									</div>
								<?php } ?>

							</div>
						</div>

						<?php
						if ($type == 'combo' || $type == 'comboAddons') {
							$cmpndCts = ncmExecute('SELECT taxonomyId as id, taxonomyName as name FROM taxonomy WHERE taxonomyType = "category" AND ' . $SQLcompanyId . ' LIMIT ' . $plansValues[PLAN]['max_categories'], [], false, true);
							$inpuTComp = json_decode(selectInputCompound($cmpndCts, false, [], 'search bg-white'));
						}

						$mask = 'maskFloat3';
						if (in_array($type, ['combo', 'precombo', 'comboAddons'])) {
							$mask = 'maskFloat';
						}

						//aquí se genera el box para añadir compounds, categorías o lo que se necesite
						$compoundBoxForJs = 	'<div class="TextBoxDiv">' .
							'	<div class="col-sm-6 wrapper-xs">';

						if ($type == 'combo' || $type == 'comboAddons') {
							$compoundBoxForJs .=			$inpuTComp;
						} else {
							$compoundBoxForJs .= 	' 		<select name="compid[]" class="form-control bg-white no-border b-b compoundSelect searchAjax" autocomplete="off"></select>';
						}

						$compoundBoxForJs .=	'	</div>' .
							'	<div class="col-sm-4 wrapper-xs">' .
							'		<input type="text" class="form-control ' . $mask . ' text-right no-border b-b no-bg" name="compunits[]" placeholder="Cantidad" data-placement="bottom" value="">' .
							'	</div>' .
							'	<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm">' .
							'   	<span class="badge"></span>' .
							'	</div>' .
							'</div>';
						?>

						<?php
						if ($productionTools) {
						?>
							<div class="tab-pane bg-white col-xs-12 wrapper animated fadeIn speed-4x" id="productionTab" style="min-height: 400px;">

								<div class="col-xs-12 wrapper">
									<div class="col-xs-12 no-padder h4 font-bold text-u-c m-b">
										Tipos de producción
										<?php
										$productionConf = '';
										if ($type == 'production') {

										?>
											<a href="/@#bulk_production" target="_blank" class="pull-right btn btn-default btn-rounded font-bold">Producir</a>
										<?php
										} else if ($type == 'product') {
											$productionConf = 'hidden';
										}

										?>
									</div>

									<div class="col-xs-12 no-padder">
										<div class="col-sm-3 no-padder"></div>
										<div class="col-sm-6 no-padder">
											<select class="form-control rounded" id="productionType" name="productionType">
												<option value="3" <?= ($type == 'product') ? 'selected' : '' ?>>Ningún tipo</option>
												<option value="2" <?= ($type == 'direct_production') ? 'selected' : '' ?>>Directa</option>
												<option value="1" <?= ($type == 'production') ? 'selected' : '' ?>>Previa</option>
											</select>
										</div>
										<div class="col-sm-3 no-padder"></div>
									</div>

								</div>

								<div class="col-xs-12 wrapper h4 m-t font-bold text-u-c <?= $productionConf ?>">Insumos</div>

								<div class="col-xs-12 no-padder <?= $productionConf ?>">
									<div class="col-xs-12 wrapper bg-light lter r-3x" id="compoundHolder">
										<?php
										$obj 				= getCompoundsArray($result['itemId']);
										$totalCOGS 	= 0;
										$ix					= 0;

										if ($obj) {
											foreach ($obj as $resulta) {
												$id 			= $resulta['compoundId'];
												$units 		= $resulta['toCompoundQty'];

												$compData = ncmExecute('SELECT * FROM item WHERE itemId = ? LIMIT 1', [$id]);
												$uom 			= $compData['itemUOM'];
												$name 		= toUTF8($compData['itemName']);
												$cogs 		= getItemCOGSWithWaste($compData['itemId']) * $units;
												//$cogs 		= $cogs['stockOnHandCOGS'];
												$totalCOGS += $cogs;
										?>
												<div class="TextBoxDiv row" data-index="<?= $ix ?>">
													<div class="col-sm-6 wrapper-xs">
														<select name="compid[]" class="form-control bg-white no-border b-b compoundSelect searchAjax" autocomplete="off">
															<?php
															if ($id) {
															?>
																<option value="<?= enc($id); ?>"><?= $name; ?></option>
															<?php
															}
															?>
														</select>
													</div>
													<div class="col-sm-2 wrapper-xs">
														<input type="text" class="form-control text-right no-border b-b no-bg maskFloat3" name="compunits[]" data-toggle="tooltip" data-placement="left" placeholder="Cantidad" value="<?= $units ?>">
													</div>
													<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm text-right"><?= CURRENCY . formatCurrentNumber($cogs) ?></div>
													<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm text-right">
														<span class="badge m-r"><?= $uom ?></span>
														<a href="#" class="rmCompound" data-index="<?= $ix ?>">
															<span class="text-danger m-r-xs material-icons">close</span>
														</a>
													</div>
												</div>
										<?php

												$ix++;
											}
										}
										?>

										<div class="TextBoxDiv">
											<div class="col-sm-6 wrapper-xs">
												<select name="compid[]" class="form-control bg-white no-border b-b compoundSelect searchAjax" autocomplete="off"></select>
											</div>
											<div class="col-sm-2 wrapper-xs">
												<input type="text" class="form-control text-right no-border b-b no-bg maskFloat3" name="compunits[]" placeholder="Cantidad" value="">
											</div>
											<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm"><span class="badge"></span></div>
											<div class="col-sm-2"></div>
										</div>
									</div>
									<div class="col-xs-12 text-right m-t m-b">
										<a href="#" id="addCompound" class="m-r-lg"><span class="text-info font-bold text-u-c">Agregar</span></a>
									</div>
								</div>

								<div class="col-xs-12 wrapper <?= $productionConf ?>">
									<div class="col-xs-12 wrapper bg-light lter r-2x">
										<?php
										$pCOGS 					= getProductionCOGS(dec($itemId));
										$costTitle 			= 'Costo';
										?>
										<div class="col-sm-8 text-u-c font-bold">
											<?= $costTitle ?>
										</div>
										<div class="col-sm-4 font-bold text-right">
											<?= CURRENCY . formatCurrentNumber($totalCOGS) ?>
										</div>
									</div>
									<div class="col-sm-12 text-sm">
										*Para re calcular el costo total, debe guardar los cambios realizados
									</div>
								</div>

								<div class=" <?= $productionConf ?>">
									<div class="col-xs-12 h4 font-bold text-u-c">Procedimiento</div>
									<div class="col-xs-12 wrapper">
										<textarea class="form-control b-light" placeholder="Procedimiento para la elaboración (opcional)" style="height:100px" name="procedure" autocomplete="off"><?= $itemProcedure ?></textarea>
									</div>
								</div>


							</div>
						<?php
						} else if ($comboTools) {
						?>
							<div class="tab-pane bg-white col-xs-12 wrapper animated fadeIn speed-4x" id="kitTab" style="min-height: 400px;">

								<div class="col-xs-12 wrapper">
									<div class="col-xs-12 no-padder h4 font-bold text-u-c m-b">Tipo de combo</div>

									<div class="col-xs-12 no-padder">
										<div class="col-sm-3 no-padder"></div>
										<div class="col-sm-6 no-padder">
											<select class="form-control rounded" name="comboType" id="comboSelector" data-type="<?= $type ?>">
												<option value="1" <?= ($type == 'precombo') ? 'selected' : '' ?>>Predefinido</option>
												<option value="2" <?= ($type == 'combo') ? 'selected' : '' ?>>Dinámico</option>
												<option value="3" <?= ($type == 'comboAddons') ? 'selected' : '' ?>>Add-on</option>
											</select>
										</div>
										<div class="col-sm-3 no-padder"></div>
									</div>

								</div>

								<div class="col-xs-12 h4 m-t-md font-bold wrapper text-u-c">Compuestos</div>

								<div class="col-xs-12 no-padder">
									<div class="col-xs-12 wrapper bg-light lter r-3x" id="compoundHolder">
										<?php
										if ($type == 'precombo') {

											$obj 		= getCompoundsArray($result['itemId']);

											if ($obj) { //si ya tiene cargados

												$comboCOGS 	= getComboCOGS($result['itemId']);

												$allCats 	= getAllItemCategories();

												foreach ($obj as $resulta) {
													$id 			= $resulta['compoundId'];
													$units 		= number_format($resulta['toCompoundQty'], 2); //dejo en 2 ceros

													$compData = ncmExecute('SELECT * FROM item WHERE itemId = ? LIMIT 1', [$id]);
													$uom 			= $compData['itemUOM'];
													$name 		= toUTF8($compData['itemName']);
													$price 		= $compData['itemPrice'];

										?>
													<div class="TextBoxDiv">
														<div class="col-sm-6 wrapper-xs">
															<select name="compid[]" class="form-control bg-white no-border b-b compoundSelect searchAjax" autocomplete="off">
																<?php
																if ($id) {
																?>
																	<option value="<?= enc($id); ?>"><?= $name; ?></option>
																<?php
																}
																?>
															</select>
														</div>
														<div class="col-sm-4 wrapper-xs">
															<input type="text" class="form-control text-right no-border b-b no-bg maskFloat" name="compunits[]" data-units="<?= $units ?>" value="<?= $units ?>">
														</div>
														<div class="col-sm-2 wrapper-xs uom m-t-sm">
															<span class="badge"><?= $uom ?></span>
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
													<input type="text" class="form-control text-right no-border b-b no-bg maskFloat" name="compunits[]" placeholder="Cantidad" value="">
												</div>
												<div class="col-sm-2 wrapper-xs uom font-bold uom m-t-sm"><span class="badge"></span></div>
											</div>

											<?php
										} else {
											$obj 									= getCompoundsArray($result['itemId']);

											if ($obj) {

												$comboCOGS 					= getComboCOGS($result['itemId']);
												$compidPreselected 	= '';

												foreach ($obj as $resulta) {
													if ($resulta['toCompoundQty'] < 0) {
														//detecto el preselected que tiene como cantidad -1
														$compidPreselected = $resulta['compoundId'];
														continue;
													}

													$id 			= $resulta['compoundId'];
													$units 		= number_format($resulta['toCompoundQty'], 2); //dejo en 2 ceros
													$options 	= json_decode(selectInputCompound($cmpndCts, $id, [], 'search bg-white')); //vuelvo a generar para match los seleccionados
													$name 		= $allCats[$id]['name'] ?? "";
													$uom 			= '';

											?>
													<div class="TextBoxDiv">
														<div class="col-sm-6 col-xs-12 wrapper-xs">
															<?php
															// si es combo dinamico muestro input de categorias
															echo $options;
															?>
														</div>
														<div class="col-sm-4 col-xs-8 wrapper-xs">
															<input type="text" class="form-control text-right no-border b-b no-bg maskFloat" name="compunits[]" data-units="<?= $units ?>" value="<?= $units ?>">
														</div>
														<div class="col-sm-2 col-xs-4">
															<input type="checkbox" name="comppreselect[]" value="1" <?= ($resulta['toCompoundPreselected'] ? 'checked' : '') ?>>
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
													<input type="text" class="form-control text-right no-border b-b no-bg maskFloat" name="compunits[]" placeholder="Cantidad" value="">
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
								if ($type == 'precombo') {
								?>
									<div class="col-xs-12 wrapper">
										<div class="col-xs-12 wrapper bg-light lter r-3x">
											<div class="col-sm-8 font-bold">
												COSTO TOTAL
											</div>
											<div class="col-sm-4 font-bold text-right">
												<?= CURRENCY . formatCurrentNumber($comboCOGS ?? 0) ?>
											</div>
										</div>
										<div class="col-sm-12 text-sm">
											*Para re calcular el costo total, debe guardar los cambios realizados
										</div>
									</div>
								<?php
								} else {
								?>
									<!-- <div class="col-xs-12">
										<div class="col-sm-4 wrapper-xs">
											<span class="font-bold text-sm text-u-c">Pre seleccionado</span>
										</div>
										<div class="col-sm-8 wrapper-xs">
											<select name="compidPreselected" class="form-control bg-white no-border b-b searchAjax" autocomplete="off">
												<?php
												// $compidPreselected 	= '';
												// if ($compidPreselected) {
												// 	$name = getItemName($compidPreselected);
												// 	echo '<option value="' . enc($compidPreselected) . '">' . $name . '</option>';
												// }
												?>
											</select>
										</div>
									</div> -->

									<div class="col-xs-12">
										<div class="col-sm-4 wrapper-xs">
											<span class="font-bold text-sm text-u-c">Regla de Precio</span>
										</div>
										<div class="col-sm-8 wrapper-xs">
											<select name="priceRule" class="form-control bg-white no-border b-b" autocomplete="off">
												<option value="">Ninguno</option>
												<option value="topPrice" <?= (isset($jResult['priceRule']) && $jResult['priceRule'] == 'topPrice') ? 'selected' : '' ?>>Mayor</option>
												<option value="lowPrice" <?= (isset($jResult['priceRule']) && $jResult['priceRule'] == 'lowPrice') ? 'selected' : '' ?>>Menor</option>
												<option value="average" <?= (isset($jResult['priceRule']) && $jResult['priceRule'] == 'average') ? 'selected' : '' ?>>Promedio</option>
											</select>
										</div>
									</div>
								<?php
								}
								?>

							</div>

							<div class="tab-pane bg-white col-xs-12 wrapper animated fadeIn speed-4x" id="upsellTab" style="min-height: 400px;">
								<div class="col-xs-12 wrapper h4 font-bold text-u-c">Upselling</div>
								<div class="col-xs-12 m-b-sm">Seleccione los artículos que recomendarán este combo</div>
								<div class="col-xs-12 m-b">
									<select name="upsell[]" id="upsell[]" class="form-control m-b input-lg searchAjax no-border b-b" multiple="" tabindex="-1">
										<?php
										$upsells    	= ncmExecute('SELECT GROUP_CONCAT(upsellChildId) as names FROM upsell WHERE upsellParentId = ?', [dec($itemId)]);
										$itemDecode = dec($itemId);
										if (!empty($upsells["names"])) {
											$upsellOps 	= ncmExecute('SELECT itemName, itemId FROM item WHERE itemId IN(' . $upsells['names'] . ')', [], false, true);
											if ($upsellOps) {
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
									<textarea class="form-control" name="upsellDescription"><?= $upsellDescription; ?></textarea>
								</div>
							</div>

						<?php
						}
						?>
						<?php
						if ($inventoryTools) {
							if (OUTLET_ID < 1) {
								$outlet 		= ncmExecute("SELECT outletId, outletName FROM outlet WHERE outletStatus = 1 AND companyId = ?", [COMPANY_ID], false, true);
							} else {
								$outlet 		= ncmExecute("SELECT outletId, outletName FROM outlet WHERE outletStatus = 1 AND companyId = ? AND outletId = ?", [COMPANY_ID, OUTLET_ID], false, true);
							}

							$invList 		= '';
							$totalUnits 	= 0;
							$stockTriggerTotal = 0;

							if ($outlet) {
								while (!$outlet->EOF) {
									$fields 		= $outlet->fields;
									$bg 				= 'bg-light ';
									$count 			= 0;

									$inventory 	= getItemStock(dec($_GET['id']), $fields['outletId']);
									$count 			= formatQty($inventory['stockOnHand'] ?? 0);

									if ($count <= 0) {
										$bg 			= 'bg-danger ';
									}

									$invList .= '<tr>' .
										'	<td class="font-bold">' . $fields['outletName'] . '</td>' .
										' 	<td>Stock Mínimo</td>' .
										'	<td class="text-right"><span class="label ' . $bg . ' lter">' . $count . '</span></td>' .
										'</tr>';

									$depo = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'location' AND outletId = ? ORDER BY taxonomyName ASC", [$fields['outletId']], false, true);
									if ($depo) {
										$dTotal = 0;
										while (!$depo->EOF) {
											$dCount = 0;
											$depCount = ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1', [$depo->fields['taxonomyId'], dec($itemId)]);

											if ($depCount) {
												$dCount = $depCount['toLocationCount'];
											}

											$dTotal += $dCount;

											$sTrigger = ncmExecute('SELECT * FROM stockTrigger WHERE outletId = ? AND itemId = ? LIMIT 1', [$depo->fields['taxonomyId'], dec($_GET['id'])]);

											$invList .= 	'<tr>' .
												'	<td><spa class="m-l-md">' . $depo->fields['taxonomyName'] . '</span></td>' .
												'	<td>' .
												'		<input type="text" class="form-control maskFloat text-right input-md no-border no-bg b-b" placeholder="0" name="stocktrigger[]" value="' . formatCurrentNumber($sTrigger['stockTriggerCount'] ?? 0, 'si', false, '2') . '" autocomplete="off" placeholder="Cantidad" data-placement="top"/>' .
												'		<input type="hidden" class="hidden" name="stocktriggerLocation[]" value="' . enc($depo->fields['taxonomyId']) . '">' .
												'	</td>' .
												'	<td class="text-right">' .
												'		<span class="label bg-light lter">' .
												formatQty($dCount) .
												'		</span>' .
												'	</td>' .
												'</tr>';

											// Verificar si $count y $dTotal son numéricos antes de la resta
											if (is_numeric($count) && is_numeric($dTotal)) {
												$count = $count - $dTotal;
											} else {
												// Uno o ambos valores no son numéricos, intenta convertirlos a números
												$count = floatval($count);
												$dTotal = floatval($dTotal);

												// Verifica nuevamente si ambos valores son numéricos
												if (is_numeric($count) && is_numeric($dTotal)) {
													// Ahora que ambos son numéricos, realiza la resta
													$count = $count - $dTotal;
												}
											}

											$depo->MoveNext();
										}
									}

									$sTrigger = ncmExecute('SELECT * FROM stockTrigger WHERE outletId = ? AND itemId = ? LIMIT 1', [$fields['outletId'], dec($_GET['id'])]);

									$invList .= 	'<tr>' .
										'	<td><span class="m-l-md">Principal</span></td>' .
										'	<td>' .
										'		<input type="text" class="form-control maskFloat text-right input-md no-border no-bg b-b" placeholder="0" name="stocktrigger[]" value="' . formatCurrentNumber($sTrigger['stockTriggerCount'] ?? 0, 'si', false, '2') . '" autocomplete="off" placeholder="Cantidad" data-placement="top"/>' .
										'		<input type="hidden" class="hidden" name="stocktriggerLocation[]" value="' . enc($fields['outletId']) . '">' .
										'	</td>' .
										'	<td class="text-right">' .
										'		<span class="label bg-light lter">' .
										formatQty($count) .
										'		</span>' .
										'	</td>' .
										'</tr>';

									$totalUnits 				+= $inventory['stockOnHand'] ?? 0;
									$stockTriggerTotal 	+= $sTrigger['stockTriggerCount'] ?? 0;

									$outlet->MoveNext();
								}
								$outlet->Close();
							}
						?>
							<div class="tab-pane bg-white col-xs-12 wrapper animated fadeIn speed-4x" id="inventoryTab" style="min-height: 400px;" data-out="<?= OUTLET_ID ?>">

								<?php
								if (OUTLET_ID > 0) {
								?>
									<div class="col-xs-12 text-center m-b-lg r-24x bg-light lter">
										<div class="col-sm-4 b-r b-light m-b m-t">
											<div class="text-sm">Precio de Compra</div>
											<div class="h3 font-bold"><?= CURRENCY . formatCurrentNumber($itemStock['stockCOGS'] ?? 0); ?></div>
										</div>
										<div class="col-sm-4 b-r b-light m-b m-t">
											<div class="text-sm">Costo Promedio</div>
											<div class="h3 font-bold"><?= CURRENCY . formatCurrentNumber($itemStock['stockOnHandCOGS'] ?? 0); ?></div>
										</div>
										<div class="col-sm-4 m-b m-t">
											<div class="text-sm">Valor total del stock</div>
											<div class="h3 font-bold"><?= CURRENCY . formatCurrentNumber(($itemStock['stockOnHandCOGS'] ?? 0) * ($itemStock['stockOnHand'] ?? 0)); ?></div>
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
										<input type="text" name="addcount" class="form-control no-border no-bg b-b text-right text-white maskFloat3" placeholder="" value="" id="addStockCount">
									</div>
									<div class="col-sm-4">
										<span class="text-xs text-u-c font-bold">
											Costo Unitario
										</span>
										<input type="text" name="addcost" class="form-control no-border no-bg b-b text-right text-white maskCurrency" placeholder="" value="" id="addCogsCount">
									</div>
									<div class="col-sm-4">
										<a href="<?= $baseUrl; ?>?action=stockAdd&id=<?= $itemId ?>" class="btn btn-default btn-block m-t btn-rounded font-bold text-u-c" id="btnAddStockSubmit">Añadir</a>
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
										<a href="<?= $baseUrl; ?>?action=stockRemove&id=<?= $itemId ?>" class="btn btn-default btn-block m-t btn-rounded font-bold text-u-c" id="btnRemoveStockSubmit">Remover</a>
									</div>
								</div>

								<div class="col-xs-12 wrapper-lg text-center hidden r-24x m-b addRemoveStockBlocks animated fadeIn" id="successStock">

									<i class="material-icons text-info" style="font-size: 3em !important;">check</i>
								</div>

								<div class="col-xs-12 no-padder m-b-lg" style="min-height:158px;">
									<div class="col-sm-5 col-xs-12 no-padder text-center hidden-xs">
										<canvas id="chart-inventory" class="" height="200" style="max-height:200px;"></canvas>
										<div class="donut-inner" style=" margin-top: -120px;">
											<div class="h1 m-t total font-bold"><?= formatQty($totalUnits ?? 0, 3) ?></div>
											<span>Total</span>
										</div>
									</div>
									<script type="text/javascript">
										var totalU = <?= $totalUnits ?>;
										$(document).ready(function() {
											Chart.defaults.global.responsive = true;
											Chart.defaults.global.maintainAspectRatio = true;
											Chart.defaults.global.legend.display = false;

											totalU = (totalU > 0) ? totalU : 0;
											var methods = new Chart($('#chart-inventory'), {
												type: 'doughnut',
												data: {
													labels: ['Mínimo', 'Stock'],
													datasets: [{
														label: "Inventario",
														data: [<?= $stockTriggerTotal; ?>, totalU],
														backgroundColor: ['#e0eaec', '#61D5AF']
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
												<a href="#" class="btn btn-default font-bold btn-rounded <?= (OUTLET_ID < 1) ? 'disabled' : '' ?>" id="btnAddStock" data-toggle="tooltip" title="Añadir Stock" <?= (OUTLET_ID < 1) ? 'disabled' : '' ?>>
													<i class="material-icons text-success">add</i>
												</a>
												<a href="#" class="btn btn-default font-bold btn-rounded <?= (OUTLET_ID < 1) ? 'disabled' : '' ?>" id="btnRemoveStock" data-toggle="tooltip" title="Remover Stock" <?= (OUTLET_ID < 1) ? 'disabled' : '' ?>>
													<i class="material-icons text-danger">remove</i>
												</a>
												<a href="/@#report_inventory?ii=<?= $itemId ?>" target="_blank" class="btn btn-default btn-rounded text-u-c font-bold">
													Historial
												</a>
											</div>
										</div>

										<div class="col-xs-12 wrapper">
											<?php
											if (OUTLET_ID > 0 && 1 == 2) {
											?>
												<span class="font-bold text-u-c text-xs">Depósito Asignado</span>
												<select name="defaultLocation" class="form-control no-border b-b no-bg">
													<option value="<?= enc(OUTLET_ID) ?>">Principal</option>
													<?php
													$dLocation = ncmExecute('SELECT * FROM taxonomy WHERE taxonomyType = "location" AND outletId = ?', [OUTLET_ID], false, true);
													if ($dLocation) {
														while (!$dLocation->EOF) {

													?>
															<option value="<?= enc($dLocation->fields['outletId']) ?>"><?= $dLocation->fields['taxonomyName'] ?></option>
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
												<?= switchIn('autoorder', $reorderSelected) ?>
											</span>
										</div>

										<div class="col-sm-6 col-xs-12 no-padder m-b hidden">
											<div class="m-t-sm">
												Unidades a reordenar <i class="icon-question text-muted text-xs" data-toggle="tooltip" title="" data-original-title="Ingrese la cantidad de unidades que desea que el sistema ordene automáticamente"></i>
											</div>
										</div>

										<div class="col-sm-6 col-xs-12 no-padder m-b hidden">
											<input type="text" class="form-control maskInteger text-right input-md no-border no-bg b-b" placeholder="0" name="autoordercount" value="<?= $result['autoReOrderLevel'] ?>" autocomplete="off" placeholder="Cantidad" data-placement="top" />
										</div>
									</div>
								</div>

								<div class="col-xs-12 wrapper h5 font-bold text-u-c">Stock por sucursal</div>
								<div class="col-xs-12 b r-24x panel">
									<table class="table">
										<tbody>
											<?= $invList; ?>
										</tbody>
									</table>
								</div>

							</div>
					</div>
				<?php
						}
				?>

				<div class="tab-pane bg-white col-xs-12 wrapper animated fadeIn speed-4x" id="ncmDBItemFilesTab" style="min-height: 400px; display: none;"></div>
				<div class="tab-pane bg-white col-xs-12 wrapper animated fadeIn speed-4x" id="lotesTab" style="min-height: 400px; display: none;">
					<input type="hidden" id="itemId" value="<?= $_GET['id'] ?>">
					<div class="col-xs-12 wrapper h4 m-t font-bold text-u-c">Vencimientos</div>

					<div class="col-xs-12 no-padder">
						<div class="col-xs-12 wrapper bg-light lter r-3x" id="lineLote">
							<?php
							$data = array(
								'api_key' => API_KEY,
								'company_id' =>  enc(COMPANY_ID),
								'resourceId' => $itemId
							);
							$tasks = json_decode(curlContents(API_URL . "/get_tasks", 'POST', $data), true);
							$countfor = 0;
							$cantidadDeTareas = 0;

							if (empty($tasks["error"])) {
								$tasks = array_filter($tasks, function ($item) {
									return $item['status'] !== 'finished';
								});

								// Obtener la cantidad de tareas que quedan
								$cantidadDeTareas = count($tasks);
							}

							if ($cantidadDeTareas > 0) {

								$tasks = array_filter($tasks, function ($item) {
									return $item['status'] !== 'finished';
								});

								if (count($tasks ?? [])) {
									foreach ($tasks as $index => $item) {
										$vencimiento = $item['date'];
										$lote = $item['data']['lote'];

										echo '<div class="TextBox row">';
										echo '<div class="col-sm-12 col-lg-6 wrapper-xs">';
										echo '<div class="input-group">';
										echo '<input type="date" class="form-control no-border no-bg b-b text-left" style="background-color: transparent !important;" placeholder="0" name="data[' . $index . '][vencimiento]" id="itemVencimiento" value="' . $vencimiento . '" autocomplete="off" readonly>';
										echo '</div>';
										echo '</div>';
										echo '<div class="col-sm-12 col-lg-4 wrapper-xs">';
										echo '<div class="input-group">';
										echo '<input type="text" class="form-control no-border no-bg b-b text-left" style="background-color: transparent !important;" placeholder="Descripcion" name="data[' . $index . '][lote]" id="itemLote" value="' . $lote . '" autocomplete="off" readonly>';
										echo '</div>';
										echo '</div>';
										echo '<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm text-right">';
										echo '<span class="badge m-r"></span>';
										echo '<a href="#" class="rmCompound"  onclick="removeTextBoxDiv(this, \'' . $item['ID'] . '\')" data-index="' . $index . '">';
										echo '<span class="text-danger m-r-xs material-icons">close</span>';
										echo '</a>';
										echo '</div>';
										echo '</div>';
										$countfor = $countfor + 1;
									}
								}
								echo '<input type="hidden" id="countFor" value="' . $countfor . '">';
							} else {

								if ($countfor == 0) {

							?>
									<div class="TextBox row">
										<div class="col-sm-12 col-lg-6 wrapper-xs">

											<div class="input-group">
												<input type="date" class="form-control no-border  no-bg b-b text-left" placeholder="0" name="data[0][vencimiento]" id="itemVencimiento" value="" autocomplete="off">
											</div>
										</div>
										<div class="col-sm-12 col-lg-4 wrapper-xs">

											<div class="input-group">
												<input type="text" class="form-control no-border no-bg b-b text-left" placeholder="Descripcion" name="data[0][lote]" id="itemLote" value="" autocomplete="off">
											</div>
										</div>

										<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm text-right">
											<span class="badge m-r"></span>
											<a href="#" class="rmCompound" onclick="removeTextBoxDiv(this)" data-index="<?= $ix ?? 0 ?>">
												<span class="text-danger m-r-xs material-icons">close</span>
											</a>
										</div>
									</div>
							<?php
									$countfor = $countfor + 1;
									echo '<input type="hidden" id="countFor" value="' . $countfor . '">';
								}
							}

							?>




						</div>
						<div class="col-xs-12 text-right m-t m-b">
							<a href="#" id="addLineLote" class="m-r-lg"><span class="text-info font-bold text-u-c">Agregar</span></a>
						</div>
					</div>
					<!-- <div class="row">
					<div class="col-sm-4 m-b">
						<span class="font-bold text-u-c text-xs">Vencimiento</span>
						<div class="input-group">
							<input type="date" class="form-control no-border  no-bg b-b text-left" placeholder="0" name="vencimiento" id="itemVencimiento" value="" autocomplete="off">
						</div>
					</div>
					<div class="col-sm-6 m-b">
						<span class="font-bold text-u-c text-xs">Lote</span>
						<div class="input-group">
							<input type="text" class="form-control no-border no-bg b-b text-left" placeholder="0" name="lote" id="itemLote" value="" autocomplete="off">
						</div>
					</div>
					<div class="col-md-2 text-center">
							<button class="btn btn-info btn-rounded text-u-c font-bold pull-right" onclick="addLoteDate()" ><span class="btn btn-info btn-rounded text-u-c font-bold pull-right">Agregar</span></button>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12 text-center">
						
						</div>
					</div> -->


				</div>

				<div class="tab-pane bg-white col-xs-12 wrapper animated fadeIn speed-4x" id="dateHoursTab" style="min-height: 400px; display: none;">
					<div class="col-xs-12 wrapper h4 text-u-c font-bold">Días y horarios disponibles</div>
					<div class="col-xs-12 m-b-sm">Seleccione los días y rango de horas disponibles para la venta de este producto</div>
					<div class="hidden businessHoursConfig">
						<?php
						if ($result['itemDateHour']) {
							echo stripslashes($result['itemDateHour']);
						} else {
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
					if ($result['itemStatus'] < 1) { //si es para eliminar
					?>
						<a href="#" class="pull-left m-t m-l itemsAction" data-id="<?= $_GET['id'] ?>" data-type="deleteItem" data-load="<?= $baseUrl; ?>?action=delete&id=<?= $_GET['id'] ?>" data-toggle="tooltip" data-placement="right" title="Eliminar Artículo">
							<span class="text-danger">Eliminar</span>
						</a>
					<?php
					} else {
					?>
						<a href="#" class="pull-left m-t m-l itemsAction <?= validateHttp('outcall') ? 'hidden' : '' ?>" data-id="<?= $_GET['id'] ?>" data-type="archiveItem" data-load="<?= $baseUrl; ?>?action=archive&id=<?= $_GET['id'] ?>" data-toggle="tooltip" data-placement="right" title="Archivar Artículo">
							<span class="text-danger">Archivar</span>
						</a>
					<?php
					}
					?>

					<input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="<?= ($result['itemStatus'] < 1) ? 'Re Activar' : 'Guardar'; ?>">

					<a href="#" class="cancelItemView m-r-lg m-t  pull-right">Cancelar</a>
					<input type="hidden" value="<?= $_GET['id'] ?>" name="id">
					<input type="hidden" value="<?= $type ?>" name="itemType">
				</div>

				</div>
	</div>

<?php
			}
?>
</form>

</div>

<script>
	$(document).ready(function() {
		$('#addLineLote').click(function(event) {
			event.preventDefault();
			addLineLote();
		});

		<?php
		if ($type == 'giftcard') {
			if ($result['itemSKU']) {
		?>
				$('#giftBg').removeClass('gradBgOrange').css({
					backgroundColor: '#<?= $result['itemSKU'] ?>'
				});
			<?php
			}
			?>
			$('#colorselector_1').colorselector("setColor", "#<?= $result['itemSKU']; ?>");
			$('.dropdow-colorselector .dropdown-toggle').addClass('b b-white b-2x');
			$('#colorselector_1').on('change', function() {
				var color = $(this).val();
				$('#giftBg').removeClass('gradBgOrange').css({
					backgroundColor: '#' + color
				});
			});
		<?php
		}
		?>

		//if(compSetCostprice > 0 && $('.costPriceEdit').data('value') <= 0){
		//$('.costPriceEdit').val(compSetCostprice);
		//}

		window.compBoxesList = '<?= $compoundBoxForJs ?? ""; ?>';


	});
</script>

<?php
	include_once('includes/compression_end.php');
	dai();
}

if (validateHttp('action') == 'formCSV') {
?>
	<div class="modal-body modal-body no-padder clear gradBgBlue animateBg">
		<form action="<?= $baseUrl; ?>?action=importCSV" method="POST" id="csvForm" name="csvForm" enctype="multipart/form-data">
			<div class="col-xs-12 wrapper">
				<h2 class="font-bold">Carga masiva de artículos</h2>
				<div class="text-md">
					1. Descargue el modelo desde <a href="<?= $baseUrl; ?>?action=csvModel" class="text-info" target="_blank"><span class="text-warning font-bold">aquí</span></a>
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

if (validateHttp('action') == 'setCurrencies') {
	if (!validateHttp('id')) {
		jsonDieResult(['error' => 'No ID']);
	}

	$itID 		= dec(validateHttp('id'));

	if (validateHttp('update')) {
		if (!allowUser('items', 'edit', true)) {
			jsonDieResult(['error' => 'No permissions']);
		}

		$data = json_decode(base64_decode(validateHttp('update')), true);
		$updt = [];

		foreach ($data as $key => $val) {
			$amount   = floatval($val['value']);
			$currency = preg_replace('/[^a-z]/i', '', $val['code']);

			if (is_numeric($currency) || strlen($currency) > 3) {
				continue;
			}

			if ($amount > 0) {
				$updt[] = [$currency => $amount];
			}
		}

		$settUpdt = json_encode($updt);
		ncmUpdate(['records' => ['itemCurrencies' => $settUpdt], 'table' => 'item', 'where' => 'itemId = ' . $itID . ' AND companyId = ' . COMPANY_ID]);

		dai();
	}

	$result 	= ncmExecute('SELECT itemCurrencies FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$itID, COMPANY_ID]);

	$currens 	= json_decode($result['itemCurrencies'], true);

	$out = [];
	foreach ($_COUNTRIES_H as $ccode => $value) {
		$currency = $value['currency']['code'];
		$curcur   = 0;

		if (validity($currens)) {
			foreach ($currens as $k => $v) {
				if ($v[$currency] > 0) {
					$curcur = floatval($v[$currency]);
				}
			}
		}

		if ($currency != null && $currency != COUNTRY) {
			$out[]  = ['ccode' => $ccode, 'code' => $currency, 'value' => $curcur];
		}
	}


	header('Content-Type: application/json');
	dai(json_encode($out));
}

//---------------UI-------------------//


//---------------PROCESS-------------------
//Insertar Producto
if (validateHttp('action') == 'insertBtn') {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	if (checkPlanMaxReached('item', $plansValues[PLAN]['max_items'])) {
		dai('max');
	}

	$name 	= 'Nuevo Artículo';

	$record = [];

	if (validateHttp('discount')) {
		$name = 'Nuevo Descuento';
		$record['itemType'] = 'discount';
	} else if (validateHttp('combo')) {
		$name = 'Nuevo Combo';
		$record['itemType'] = 'combo';
	} else if (validateHttp('giftcard')) {
		$name 													= 'Gift Card';
		$record['itemType'] 						= 'giftcard';
		$record['itemTrackInventory'] 	= 1;
		$record['itemDescription'] 			= '1 year';
	}

	$record['itemName'] 				= $name;
	$record['itemTaxIncluded']	= 1;
	$record['itemDate'] 				= TODAY;
	$record['companyId'] 				= COMPANY_ID;
	$record['updated_at']				= TODAY;
	$record['data']							= json_encode($record);

	$insert = $db->AutoExecute('item', $record, 'INSERT');
	if ($insert === false) {
		echo 'false';
	} else {

		$itemId = $db->Insert_ID();

		//insertBlankInventoryinAllOutlets($itemId,$SQLcompanyId);//importante si no hago esto y alguien añade un item sin inventario se arma un quilombo en la app porque el index de los articulos son relativos al index del inventario (esto tiene que cambiar con el nuevo inventario debido a los multiples inventarios o lotes)

		echo 'true|0|' . enc($itemId);
		updateLastTimeEdit(false, 'item');

		// try {
		// 	$userName = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);
		// 	$registerName = getValue('register', 'registerName', 'WHERE registerId = ' . REGISTER_ID);
		// 	$companyName = getValue('setting', 'settingName', 'WHERE companyId = ' . COMPANY_ID);
		// 	$outletName = getCurrentOutletName(OUTLET_ID);
		// 	$itemDate = $db->Insert_ItemDate();

		// 	$auditoriaData = [
		// 		'date'        => TODAY,
		// 		'user'      => $userName,
		// 		'module'       => 'ARTICULOS',
		// 		'origin'       => 'PANEL',
		// 		'company_id'       => COMPANY_ID,
		// 		'data'       => [
		// 			'action' => "El usuario $userName agregó un artículo desde el panel en la sucursal " . $outletName,
		// 			'userId' => USER_ID,
		// 			'userName' => $userName,
		// 			'operationData' => $insert,
		// 			'registerId' => REGISTER_ID,
		// 			'registerName' => $registerName,
		// 			'companyID' => COMPANY_ID,
		// 			'companyName' => $companyName,
		// 			'outletId' => OUTLET_ID,
		// 			'outletName' => $outletName,
		// 			'timestamp' => strtotime($itemDate)
		// 		]
		// 	];
		// 	//sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
		// 	error_log("auditoriaData: \n", 3, './error_log');
		// 	error_log(print_r($auditoriaData, true), 3, './error_log');
		// } catch (\Throwable $th) {
		// 	//throw $th;
		// 	error_log("Error al enviar registro de auditoría de anulación de factura: \n", 3, './error_log');
		// 	error_log(print_r($th, true), 3, './error_log');
		// 	// error_log("transaction: \n", 3, './error_log');
		// 	// error_log(print_r($transaction, true), 3, './error_log');
		// }
	}
	dai();
}

//Editar Producto
if (validateHttp('action') == 'update' && validateHttp('id', 'post')) {
	$dataArray = validateHttp('data', 'post');
	//error_log(print_r($_POST, true));

	if (!empty($dataArray)) {
		$arr = array(

			'api_key' => API_KEY,
			'company_id' => enc(COMPANY_ID)
		);

		foreach ($dataArray as $item) {
			$task = array(
				'date' => $item['vencimiento'],
				'type' => 0,
				'resourceId' => $_POST['id'],
				'lote' => $item['lote'],
				'data' => json_encode(array(
					'descripcion' => $_POST['name'],
					'lote' => $item['lote']
				))
			);

			$arr['tasks'][] = $task;
		}

		$jsonData = json_encode($arr);
		//error_log($jsonData);

		// Configurar la URL del endpoint
		$url = API_URL . "/add_task";

		// Configurar las opciones de cURL
		$options = array(
			CURLOPT_URL => $url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $jsonData,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($jsonData)
			)
		);

		// Inicializar cURL y configurar las opciones
		$curl = curl_init();
		curl_setopt_array($curl, $options);

		// Ejecutar la solicitud cURL
		$response = curl_exec($curl);

		// Obtener el código de respuesta HTTP
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		// Cerrar la conexión cURL
		curl_close($curl);

		// Verificar si la solicitud fue exitosa
		if ($httpCode == 200) {
			error_log('La solicitud se envió correctamente.');
			error_log('Respuesta: ' . $response);
		} else {
			error_log('Error al enviar la solicitud. Código de respuesta HTTP: ' . $httpCode . ' response: ' . $response);
		}
	}


	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	if (!validateHttp('name', 'post')) {
		dai('El nombre es obligatorio');
	}

	$record 		= [];

	$sku 				= validateHttp('uid', 'post');
	$name 			= validateHttp('name', 'post');
	$price 			= formatNumberToInsertDB($_POST['sellingPrice'] ?? 0);

	$itemComission 		= validateHttp('itemComission', 'post');
	if (validateHttp('comissionType', 'post') == '%') {
		$itemComission 	= ($itemComission > 100) ? 100 : $itemComission;
		$itemComission 	= ($itemComission < 0) ? 0 : $itemComission;

		$comissionType 	= '0';
	} else {
		$comissionType 	= '1';
	}

	$itemComission 				= formatNumberToInsertDB($itemComission);

	$itemPricePercent 		= validateHttp('itemPricePercent', 'post');
	if (validateHttp('priceType', 'post') == '%') {
		$itemPricePercent 	= ($itemPricePercent > 100) ? 100 : $itemPricePercent;
		$itemPricePercent 	= ($itemPricePercent < 0) ? 0 : $itemPricePercent;

		$itemPriceType 			= 1;
	} else {
		$itemPriceType 			= NULL;
		$itemPricePercent 	= 0;
	}

	$cogs 					= 0;
	$description 		= validateHttp('description', 'post');
	$brand 					= iftn($_POST['brand'] ?? NULL, NULL, dec($_POST['brand'] ?? NULL));
	$category 			= iftn($_POST['category'], NULL, dec($_POST['category']));
	$itemTags 			= json_encode(explodes(',', ($_POST['itemtags'] ?? "")));
	$itemSessions 	= iftn(($_POST['itemSessions'] ?? NULL), NULL);
	$itemDuration 	= iftn(($_POST['itemDuration'] ?? NULL), NULL);

	$id 						= dec(validateHttp('id', 'post'));

	$comboType 			= validateHttp('comboType', 'post');
	$isCombo				= $comboType ? true : false;

	$productionType = validateHttp('productionType', 'post');
	$isProduction		= $productionType ? true : false;

	$comboCOGS 			= (validateHttp('comboCOGS', 'post')) ? formatNumberToInsertDB($_POST['comboCOGS']) : 0;
	$deleteCompounds = false;

	$archive 				= ((!empty($_POST['archive'])) && ($_POST['archive'] == '1')) ? '0' : '1'; //es asi porque para archivar tiene que estar en 0 y el check no anda si envio 
	$ecom 					= validateHttp('ecom', 'post') ? 1 : 0;
	$featured 			= validateHttp('featured', 'post') ? 1 : 0;
	$autoorder 			= iftn($_POST['autoorder'] ?? 0, 0);
	$autoordercount = $_POST['autoordercount'] ?? 0;
	$inventorycountmethod = $_POST['inventorycountmethod'] ?? "";
	$tax 						= dec($_POST['tax']?? 0);
	$taxIncluded 		= validateHttp('taxIncluded', 'post') ? 1 : 0;
	$outlet					= (isset($_POST['outlet']) && $_POST['outlet'] == 'all') ? NULL : dec(validateHttp('outlet', 'post'));
	$uom						= validateHttp('uom', 'post');
	$waste					= validateHttp('waste', 'post');
	$ptype 					= validateHttp('itemType', 'post');
	$businessHours  = json_decode(json_encode(validateHttp('businessHours', 'post')));
	$businessHours 	= ($businessHours) ? $businessHours : null;

	$priceRule			= validateHttp('priceRule', 'post');

	$itemDiscount 	= iftn(formatNumberToInsertDB($_POST['itemDiscount'] ?? 0, true, 3), NULL);
	$procedure 			= validateHttp('procedure', 'post'); //procedimiento

	//Defino si esta a la venta y si hay que track el inventario
	if (($_POST['typeOfItem'] ?? 0) == 0) {
		// servicio
		$canSell 		= 1;
		$trackInventory = 0;
		$type 			= 'product';
	} else if (validateHttp('typeOfItem', 'post') == 1) {
		//a la venta y con inventario
		$canSell 		= 1;
		$trackInventory = 1;
		$type 			= 'product';
	} else if (validateHttp('typeOfItem', 'post') == 2) {
		//no se vende y si inventario
		$canSell 		= 0;
		$trackInventory = 1;
		$type 			= 'compound';
	} else if (validateHttp('typeOfItem', 'post') == 3) {
		//no se vende y no inventario
		$canSell 		= 0;
		$trackInventory = 0;
		$type 			= 'compound';
	} else if (validateHttp('typeOfItem', 'post') == 'dynamic') {
		// servicio dinamico
		$canSell 		= 1;
		$trackInventory = 0;
		$isProduction 	= false;
		$isCombo 		= false;
		$isDynamic 		= true;
		$type 			= 'dynamic';
	} else {
		$canSell 		= 1;
		$trackInventory = 0;
	}

	if ($isProduction && $productionType == 3) { //elimino produccion o no posee permisos en el plan
		$isProduction 		= false;
		$procedure  		= NULL;
		$productionType 	= NULL;
		$type 				= 'product';
		$deleteCompounds 	= true;
	} else if ($isProduction && $productionType == 1) { //prod previa
		$productionType 	= 1;
		$isProduction 		= true;
		$deleteCompounds	= false;
		$type 				= 'production';
		$trackInventory 	= 1;
		$canSell 			= 1;
	} else if ($isProduction && $productionType == 2) { //produccion directa
		$productionType 	= 0;
		$isProduction 		= true;
		$deleteCompounds 	= false;
		$type 				= 'direct_production';
		$trackInventory 	= 0;
		$canSell 			= 1;
	}

	if ($isCombo) {
		if ($comboType == 1) {
			$type = 'precombo';
		} else if ($comboType == 2) {
			$type = 'combo';
		} else {
			$type = 'comboAddons';
		}

		$trackInventory = 0;
	}

	if ($trackInventory < 1) {
		$stocktrigger 	= 0;
		$autoorder 		= 0;
		$autoordercount = 0;

		//elimino el inventario y stock trigger en caso de que haya tenido para que no quede al pedo en la DB
		$db->Execute('DELETE FROM stock WHERE itemId = ? AND companyId = ?', [$id, COMPANY_ID]);
		$db->Execute('DELETE FROM stockTrigger WHERE itemId = ?', [$id]);
	} else {
		if (validateHttp('stocktrigger', 'post')) {
			$location = validateHttp('stocktriggerLocation', 'post');

			foreach (validateHttp('stocktrigger', 'post') as $key => $value) {
				stockTriggerManager($id, $value, dec($location[$key]));
			}
			//dai();
		}
	}
	////

	if (validateHttp('resetCombo', 'post')) {
		$deleteCompounds = true;
	}

	//compuestos
	if (validateHttp('compid', 'post') && !$deleteCompounds) {

		$json 					= [];
		$cogsC 					= 0;
		$priceC 				= 0;
		$itemDiscount 	= 0;
		$cleared 			= false;
		$compIDA 			= validateHttp('compid', 'post');
		$compQTYA 		= validateHttp('compunits', 'post');
		$compPRESA 		= validateHttp('comppreselect', 'post');


		if (validateHttp('compidPreselected', 'post')) {
			array_push($compIDA, validateHttp('compidPreselected', 'post'));
			array_push($compQTYA, -1);
			//print_r($compIDA);
			//print_r($compQTYA);
			//dai();
		}

		foreach ($compIDA as $key => $n) {
			$compid 	= $compIDA;
			$compqty 	= $compQTYA;
			$comppre 	= $compPRESA;

			$compid 	= $compid[$key];
			$compqty 	= $compqty[$key];
			$comppre 	= is_array($comppre) ? ($comppre[$key] ?? 0) : 0;

			if ($isCombo) {
				$compu 		= formatNumberToInsertDB($compqty, true, 2);
			} else if ($isProduction) {
				$compu 		= formatNumberToInsertDB($compqty, true, 3);
			}

			if ($compqty === -1) {
				$compu = $compqty;
			}

			if (validity($compid)) {
				if (!$cleared) {
					ncmExecute('DELETE FROM toCompound WHERE itemId = ?', [$id]);
					$cleared = true;
				}

				$compIns 										= [];
				$compIns['itemId'] 					= $id;
				$compIns['compoundId'] 			= dec($compid);
				$compIns['toCompoundQty'] 	= $compu;
				$compIns['toCompoundOrder'] = $key;
				$compIns['toCompoundPreselected'] = $comppre;
				$inserted = $db->AutoExecute('toCompound', $compIns, 'INSERT');

				if (($isCombo && $type == 'precombo') || $isProduction) {
					$priceC += (getItemPrice($compid) * $compu);
				}
			}
		}

		$record['itemPrice'] 	= $price;
	} else {
		ncmExecute('DELETE FROM toCompound WHERE itemId = ?', [$id]);
		$record['itemPrice'] 		= ($cogs > $price) ? $cogs : $price;
	}

	//compuestos//

	if ($businessHours) {
		$record['itemDateHour']	= $businessHours;
	}

	if ($ptype == 'discount') {
		$type		= $ptype;
		$price 		= formatNumberToInsertDB(validateHttp('sellingPrice', 'post'), true, 3);
	}

	if ($ptype == 'giftcard') {
		$type			= $ptype;
		$description 	= validateHttp('giftExpCount', 'post') . ' ' . validateHttp('giftExpTime', 'post');
		$trackInventory = 1;
	}

	if ($ptype == 'dynamic') {
		$type			= $ptype;
		//$trackInventory = 0;

	}

	if (validateHttp('upsell', 'post')) {
		//print_R(validateHttp('upsell','post'));
		$db->Execute('DELETE FROM upsell WHERE upsellParentId = ?', [$id]);

		foreach (validateHttp('upsell', 'post') as $key => $value) {
			$uRecord 					= [];
			$uRecord['upsellParentId'] 	= $id;
			$uRecord['upsellChildId'] 	= dec($value);
			$uRecord['companyId'] 		= COMPANY_ID;
			$db->AutoExecute('upsell', $uRecord, 'INSERT');
		}

		if (validateHttp('upsellDescription', 'post')) {
			$record['itemUpsellDescription'] 	= validateHttp('upsellDescription', 'post');
		}
	}

	//location
	$location = NULL;
	if (validateHttp('defaultLocation', 'post')) {
		$lRecord = [];
		list($ouNotUse, $location) = outletOrLocation(dec(validateHttp('defaultLocation', 'post')));

		$db->Execute('DELETE FROM toItemLocation WHERE itemId = ? AND outletId = ?', [$id, $location]);
		$lRecord['itemId'] 		= $id;
		$lRecord['outletId'] 	= $location;
		$db->AutoExecute('toItemLocation', $lRecord, 'INSERT');
	}

	if (validateHttp('sort', 'post')) {
		$record['itemSort'] = validateHttp('sort', 'post');
	}

	$record['itemType']			= $type;
	$record['itemSKU'] 			= $sku;
	$record['itemName'] 		= $name;
	$record['itemDescription'] 			= $description;
	$record['itemSessions'] 				= $itemSessions;
	$record['itemDuration'] 				= $itemDuration;
	$record['itemTags'] 						= $itemTags;
	$record['itemCanSale'] 					= $canSell;
	$record['itemTrackInventory'] 	= $trackInventory;
	$record['itemStatus'] 					= $archive;
	$record['autoReOrder'] 					= $autoorder;
	$record['autoReOrderLevel'] 		= $autoordercount;
	$record['inventoryMethod'] 			= $inventorycountmethod;
	$record['itemImage'] 						= iftn(validateHttp('itemImgFlag', 'post'), '', 'true');
	$record['itemDiscount']					= $itemDiscount;
	$record['itemProcedure']				= $procedure;
	$record['itemProduction']				= $productionType;
	$record['itemComissionPercent']	= $itemComission;
	$record['itemComissionType']		= $comissionType;
	$record['itemPricePercent'] 		= formatNumberToInsertDB($itemPricePercent);
	$record['itemPriceType']				= $itemPriceType;
	$record['itemEcom']							= $ecom;
	$record['itemFeatured']					= $featured;
	$record['itemTaxIncluded']			= $taxIncluded;

	$record['itemUOM']							= $uom;
	$record['itemWaste']						= $waste;

	$record['brandId'] 							= $brand;
	$record['categoryId'] 					= $category;
	$record['taxId']								= $tax;
	$record['locationId']						= $location;
	$record['outletId']							= $outlet;
	$record['updated_at']						= TODAY;

	$record['priceRule'] 						= $priceRule;

	$record['data']									= json_encode($record);


	$update = $db->AutoExecute('item', $record, 'UPDATE', 'itemId = ' . db_prepare($id) . ' AND ' . $SQLcompanyId);
	if ($update === false) {
		echo 'false';
	} else {
		updateLastTimeEdit(false, 'item');
		require_once('libraries/php-thumb/ThumbLib.inc.php');
		$imgUp = !empty($_FILES['image']) && uploadImage($_FILES['image'], SYSIMGS_FOLDER . '/' . enc(COMPANY_ID) . '_' . enc($id) . '.jpg', 500000);
		echo 'true|' . ($_GET['index'] ?? "") . '|' . $_POST['id'] . '|' . $imgUp;
	}

	dai();
}

//Edit Bulk
//Editar Producto
if (validateHttp('action') == 'bulkUpdate' && validateHttp('ids', 'post')) {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	//	session_write_close();

	$ids 		= explodes('|', validateHttp('ids', 'post'));
	$record 	= [];
	$recordP 	= []; //para grupos
	$inventoryActions = false;

	if (validateHttp('sellingPrice', 'post')) {
		$record['itemPrice'] = formatNumberToInsertDB($_POST['sellingPrice']);
	}

	if (validateHttp('brand', 'post')) {
		$record['brandId'] = dec($_POST['brand']);
	}
	if (validateHttp('category', 'post')) {
		$record['categoryId'] = dec($_POST['category']);
	}

	if (validateHttp('tax', 'post')) {
		$record['taxId'] = dec($_POST['tax']);
	}
	if (validateHttp('outlet', 'post')) {
		$record['outletId'] = ($_POST['outlet'] == 'all') ? NULL : dec($_POST['outlet']);
	}

	if (validateHttp('sessions', 'post')) {
		$record['itemSessions'] = formatNumberToInsertDB($_POST['sessions']);
	}
	if (validateHttp('duration', 'post')) {
		$record['itemDuration'] = formatNumberToInsertDB($_POST['duration']);
	}
	if (isset($_POST['discount']) && is_numeric($_POST['discount'])) {
		if ($_POST['discount'] > 0) {
			$record['itemDiscount'] = formatNumberToInsertDB($_POST['discount'], true, 3);
		} else {
			$record['itemDiscount'] = NULL;
		}
	}

	if (validateHttp('uom', 'post')) {
		$record['itemUOM'] = validateHttp('uom', 'post');
	}
	if (validateHttp('waste', 'post')) {
		$record['itemWaste'] = validateHttp('waste', 'post');
	}

	if (isset($_POST['ecom']) && $_POST['ecom'] != NULL) {
		if (validateHttp('ecom', 'post') == 1) {
			$record['itemEcom'] = 1;
			$recordP['itemEcom'] = $record['itemEcom']; // para grupos
		} else if (validateHttp('ecom', 'post') == 0) {
			$record['itemEcom'] = 0;
			$recordP['itemEcom'] = $record['itemEcom']; // para grupos
		}
	}
	

	if (validateHttp('featured', 'post')) {
		$record['itemFeatured'] = validateHttp('featured', 'post');
		$recordP['itemFeatured'] = $record['itemFeatured']; //para grupos
	}

	if (isset($_POST['typeOfItem']) && $_POST['typeOfItem'] != NULL) {
		$canSell 			= -1;
		$trackInventory 	= -1;
		$type 				= 'product';
		$inventoryActions 	= true;
		$resetStock			= false;
		$do 				= false;

		if ($_POST['typeOfItem'] == 0) {
			// servicio
			$canSell 		= 1;
			$trackInventory = 0;
			$do 			= true;
		} else if (validateHttp('typeOfItem', 'post') == 1) {
			//a la venta y con inventario
			$canSell 		= 1;
			$trackInventory = 1;
			$type 			= 'product';
			$do 			= true;
		} else if (validateHttp('typeOfItem', 'post') == 2) {
			//no se vende y si inventario
			$canSell 		= 0;
			$trackInventory = 1;
			$type 			= 'compound';
			$do 			= true;
		} else if (validateHttp('typeOfItem', 'post') == 3) {
			//no se vende y no inventario
			$canSell 		= 0;
			$trackInventory = 0;
			$do 			= true;
		} else if (validateHttp('typeOfItem', 'post') == 'dynamic') {
			// servicio dinamico
			$canSell 		= 1;
			$trackInventory = 0;
			$isProduction 	= false;
			$isCombo 		= false;
			$isDynamic 		= true;
			$type 			= 'dynamic';
			$do 			= true;
		} else if (validateHttp('typeOfItem', 'post') == 'resetstock') {
			//a la venta y con inventario
			$resetStock		= true;
			$do 			= false; //no cambio su estado como inventariable pero elimino su stock
		}

		if ($do) {

			if ($canSell > -1 && $trackInventory > -1) {
				$record['itemCanSale'] 			= $canSell;
				$record['itemTrackInventory'] 	= $trackInventory;
			}

			$record['itemType'] = $type;
		}
	}

	if (validateHttp('comissionType', 'post') != '-') {
		if (validateHttp('comission', 'post')) {
			$itemComission 	= validateHttp('comission', 'post');
			if (validateHttp('comissionType', 'post') == '%') {
				$itemComission 	= ($itemComission > 100) ? 100 : $itemComission;
				$itemComission 	= ($itemComission < 0) ? 0 : $itemComission;

				$comissionType 	= '0';
			} else {
				$comissionType 	= '1';
			}
			$record['itemComissionPercent'] = formatNumberToInsertDB($itemComission);
			$record['itemComissionType']	= $comissionType;
		}
	}

	if (validateHttp('priceType', 'post') != '-') {
		$itemPricePercent 	= validateHttp('itemPricePercent', 'post');
		if (validateHttp('priceType', 'post') == '%') {
			$itemPricePercent 	= ($itemPricePercent > 100) ? 100 : $itemPricePercent;
			$itemPricePercent 	= ($itemPricePercent < 0) ? 0 : $itemPricePercent;

			$itemPriceType 		= 1;
		} else {
			$itemPriceType 		= NULL;
			$itemPricePercent 	= 0;
		}
		$record['itemPricePercent'] = formatNumberToInsertDB($itemPricePercent);
		$record['itemPriceType']	= $itemPriceType;
	}

	$record['updated_at']		= TODAY;

	foreach ($ids as $id) {
	    if (isset($recordP['itemEcom']) && $recordP['itemEcom'] !== null) { 
			$itemId = dec($id);
			$itemData = ncmExecute('SELECT itemId,itemIsParent,itemPrice,data FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$itemId, COMPANY_ID], true); 	
			$datavalue = json_decode($itemData['data'], true);
			// error_log('datavalue: ' . json_encode($datavalue['itemEcom']), 3, '/var/www/panel/error_log.log');
			// error_log('recordPitemEcom: ' . $recordP['itemEcom'], 3, '/var/www/panel/error_log.log');
	
			$datavalue['itemEcom'] = $recordP['itemEcom'];
			$recordP['data'] = json_encode($datavalue);
			ncmUpdate(['records' => $recordP, 'table' => 'item', 'where' => 'itemId = ' . db_prepare($itemId) . ' AND ' . $SQLcompanyId]);
	
			} 
		$itemId 	= dec($id);
		$itemData = ncmExecute('SELECT itemId,itemIsParent,itemPrice, data FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$itemId, COMPANY_ID], true);
		if ($itemData['itemIsParent']) {
			$children 	= ncmExecute('SELECT itemId,itemPrice FROM item WHERE itemParentId = ? AND companyId = ? LIMIT 200', [$itemData['itemId'], COMPANY_ID], false, true);

			if ($recordP) {
				ncmUpdate(['records' => $recordP, 'table' => 'item', 'where' => 'itemId = ' . $itemData['itemId']]);
			}	

			if ($children) {
				while (!$children->EOF) {
					$itemDataC 	= $children->fields;
					$itemId 	= $itemDataC['itemId'];

					if (isset($_POST['percentPrice']) && $_POST['percentPrice'] != '') {
						$actualPrice 			= $itemDataC['itemPrice'];
						$record['itemPrice'] 	= percenter($actualPrice, (int)$_POST['percentPrice']);
					}

					if ($inventoryActions) {
						if ($trackInventory === 0 || $resetStock) {
							//elimino el inventario y stock trigger en caso de que haya tenido para que no quede al pedo en la DB
							ncmExecute('DELETE FROM stock WHERE itemId = ? AND companyId = ? LIMIT 100000', [$itemId, COMPANY_ID]);
							ncmExecute('DELETE FROM stockTrigger WHERE itemId = ? LIMIT 1000', [$itemId]);
						} else {
							//stockTriggerManager($itemId,1);
						}
					}

					$update = $db->AutoExecute('item', $record, 'UPDATE', 'itemId = ' . db_prepare($itemId) . ' AND ' . $SQLcompanyId);
					if ($update === false) {
					} else {
						updateLastTimeEdit(false, 'item');
					}
					$children->MoveNext();
				}
			}
		} else {

			if (isset($_POST['percentPrice']) && $_POST['percentPrice'] != '') {
				$actualPrice 			= $itemData['itemPrice'];
				$record['itemPrice'] 	= percenter($actualPrice, (int)$_POST['percentPrice']);
			}

			if ($inventoryActions) {
				if ($trackInventory === 0 || $resetStock) {
					//elimino el inventario y stock trigger en caso de que haya tenido para que no quede al pedo en la DB
					ncmExecute('DELETE FROM stock WHERE itemId = ? AND companyId = ? LIMIT 100000', [$itemId, COMPANY_ID]);
					ncmExecute('DELETE FROM stockTrigger WHERE itemId = ? LIMIT 1000', [$itemId]);
				}
			}

			$update = ncmUpdate(['records' => $record, 'table' => 'item', 'where' => 'itemId = ' . db_prepare($itemId) . ' AND ' . $SQLcompanyId]);

			//$update = $db->AutoExecute('item', $record, 'UPDATE', 'itemId = ' . db_prepare($itemId) . ' AND ' . $SQLcompanyId); 
			if (!$update['error']) {
				updateLastTimeEdit(false, 'item');
			}
		}
}
	echo 'true|0|' . str_replace('|', ',', validateHttp('ids', 'post'));
	dai();
}

//Eliminar Producto
if (validateHttp('action') == 'delete' && validateHttp('id')) {
	if (!allowUser('items', 'delete', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	if (validateHttp('multi')) {
		$ids 	= explodes('|', $_GET['id']);
		$ins 	= [];
		for ($i = 0; $i < counts($ids); $i++) {
			$id 	= dec($ids[$i]);
			//$delete = deleteItem($id);

			$ins[] 	= $id;

			if ($delete) {
				@file_get_contents('/upload.php?action=delete&id=' . $ids[$i]);
			}
		}

		$deleted = deleteItemBulk(implode(',', $ins));
		if ($deleted) {
			echo 'true';
		} else {
			echo 'false';
		}

		updateLastTimeEdit(false, 'item');
	} else {
		$delete = deleteItem(dec($_GET['id']));
		if ($delete) {
			@file_get_contents('/upload.php?action=delete&id=' . $_GET['id']);
			updateLastTimeEdit(false, 'item');
			echo 'true';
		} else {
			echo 'false';
		}
	}
	dai();
}

//Eliminar TODO el inventario de un producto
if (validateHttp('action') == 'clearSingleInventory' && validateHttp('id')) {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	if (validateHttp('multi')) {
	} else {
		$id 		= dec(db_prepare($_GET['id']));
		$isGroup 	= getValue('item', 'itemIsParent', 'WHERE itemId = ' . $id . ' AND ' . $SQLcompanyId);

		$delete 	= $db->Execute('DELETE FROM inventory WHERE itemId = ? AND inventoryType = 0 AND ' . $SQLcompanyId, array($id));

		if (validity($isGroup)) {
			$record 				= array();
			$record['itemParentId'] = NULL;
			$db->AutoExecute('item', $record, 'UPDATE', 'itemParentId = ' . $id . ' AND ' . $SQLcompanyId);
		}

		if ($delete === false) {
			echo 'false';
		} else {
			echo 'true';
			updateRowLastUpdate('item', 'item_id = ' . $id);
			updateLastTimeEdit(false, 'item');
		}
	}
	dai();
}

//crear grupo
if (validateHttp('action') == 'group' && validateHttp('id')) {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	if (validateHttp('multi') && validateHttp('name')) {
		$ids 	= explodes('|', $_GET['id']);
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

		if ($insert !== false) {
			foreach ($ids as $key) {
				$record 				= [];
				$id 					= dec($key);
				$record['itemParentId'] = $parentId;
				$record['updated_at']	= TODAY;
				$group 					= $db->AutoExecute('item', $record, 'UPDATE', 'itemId = ' . db_prepare($id));
			}

			echo enc($parentId);
			updateLastTimeEdit(false, 'item');
		} else {
			echo 'false';
		}
	} else {
		echo 'false';
	}

	dai();
}

//añadir a grupo
if (validateHttp('action') == 'groupEdit' && validateHttp('id')) {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	if (validateHttp('multi') && validateHttp('group')) {
		$ids 		= explodes('|', validateHttp('id'));
		$parentId 	= dec(validateHttp('group'));

		foreach ($ids as $key) {
			$record 				= array();
			$id 					= dec($key);

			if ($parentId != $id) {
				$record['itemParentId'] = $parentId;
				$record['updated_at']	= TODAY;
				$group 					= $db->AutoExecute('item', $record, 'UPDATE', 'itemId = ' . db_prepare($id));
			}
		}

		updateLastTimeEdit(false, 'item');
		echo 'true';
	} else {
		echo 'false';
	}

	dai();
}

if (validateHttp('action') == 'ungroup' && validateHttp('id')) {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$record 				= array();
	$id 					= dec(validateHttp('id'));
	$record['itemParentId'] = NULL;
	$record['updated_at']	= TODAY;

	$group 					= $db->AutoExecute('item', $record, 'UPDATE', 'itemId = ' . db_prepare($id));

	if ($group === true) {
		updateLastTimeEdit(false, 'item');
		echo 'true';
	} else {
		echo 'false';
	}

	dai();
}

if (validateHttp('action') == 'archive' && validateHttp('id')) {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$arch['itemStatus'] 	= 0;
	$arch['itemParentId'] 	= NULL;
	$arch['updated_at'] 	= TODAY;

	if (validateHttp('multi')) {
		$ids = explodes('|', validateHttp('id'));

		foreach ($ids as $value) {
			$id 		= db_prepare(dec($value));
			$archive 	= $db->AutoExecute('item', $arch, 'UPDATE', 'itemId = ' . $id);
		}
	} else {
		$archive = $db->AutoExecute('item', $arch, 'UPDATE', 'itemId = ' . db_prepare(dec($_GET['id'])));
	}

	updateLastTimeEdit(COMPANY_ID, 'item');

	dai('true');
}

if (validateHttp('action') == 'unarchive' && validateHttp('id')) {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$arch['itemStatus'] = 1;
	$arch['updated_at'] = TODAY;
	if (validateHttp('multi')) {
		$ids = explodes('|', validateHttp('id'));

		foreach ($ids as $value) {
			$id 		= db_prepare(dec($value));
			$archive 	= $db->AutoExecute('item', $arch, 'UPDATE', 'itemId = ' . $id);
		}
	} else {
		$archive = $db->AutoExecute('item', $arch, 'UPDATE', 'itemId = ' . db_prepare($_GET['id']));
	}

	updateLastTimeEdit(COMPANY_ID, 'item');

	dai('true');
}

if (validateHttp('tableExtra')) {
	adm(validateHttp('valExtra'), validateHttp('tableExtra'), dec(validateHttp('idExtra')), validateHttp('actionExtra'));
}

if (validateHttp('getitemsarray')) {
	$limit = ' LIMIT ' . $plansValues[PLAN]['max_items'];
	$itemsListforJS = '[';

	$result = ncmExecute('SELECT itemId, itemName FROM item WHERE ' . $SQLcompanyId . ' ORDER BY itemName DESC' . $limit, [], false, true);

	if ($result) {
		while (!$result->EOF) {

			$itemsListforJS .= '{"' . $result->fields['itemId'] . '","' . $result->fields['itemName'] . '"},';

			$result->MoveNext();
		}
		$itemsListforJS = rtrim($itemsListforJS, ",");
		$result->Close();
	}
	die($itemsListforJS . ']');
}

if (validateHttp('getItem')) {
	$limit 			= ' LIMIT 1';
	$itemsListforJS = '[';

	$result 		= ncmExecute('SELECT itemId, itemName FROM item WHERE itemId = ? ' . $SQLcompanyId . ' ' . $limit, [$_POST['id']]);

	if ($result['itemId']) {
		echo $result['itemId'] . '|' . $result['itemName'];
	}

	dai();
}

if (validateHttp('action') == 'exportCSV' && validateHttp('ids')) {
	include_once("libraries/parsecsv.lib.php");
	$ids 		= explodes('|', $_GET['ids']);
	$array 		= [];
	$fields 	= ['TITULO', 'SKU', 'MARCA', 'CATEGORIA', 'DESCRIPCION', 'PRECIO DE COSTO', 'PRECIO DE VENTA', 'TIPO', TAX_NAME, 'SUCURSAL', '% DESCUENTO', 'UN. DE MEDIDA', '% MERMA', '% COMISION', 'SESIONES', 'DURACION EN MIN.', 'STOCK MINIMO', 'STOCK INICIAL (Sucursal:Cantidad;Sucursal2:Cantidad)'];
	$var 		= [];

	for ($i = 0; $i < count($ids); $i++) {
		$id 	= dec($ids[$i]);

		$result = $db->Execute('SELECT * FROM item WHERE itemId = ' . $id . ' AND ' . $SQLcompanyId);

		if ($result->fields['itemIsParent'] == '1') {
			$child = $db->Execute('SELECT * FROM item WHERE itemParentId = ' . $id . ' AND ' . $SQLcompanyId);

			while (!$child->EOF) {
				$var['titulo'] 				= $child->fields['itemName'];
				$var['sku'] 				= $child->fields['itemSKU'];
				$var['marca'] 				= getTaxonomyName($child->fields['brandId']);
				$var['categoria'] 			= getTaxonomyName($child->fields['categoryId']);

				$var['precio de costo'] 	= $child->fields['itemCOGS'];
				$var['precio de venta'] 	= $child->fields['itemPrice'];

				$inventoryData 				= '';
				$inventory 					= $db->Execute('SELECT inventoryCount FROM inventory WHERE itemId = ? AND outletId = ?', array($child->fields['itemId'], OUTLET_ID));

				$inventoryData 				.= $inventory->fields['inventoryCount'];
				$var['inventario en la sucursal ' . getCurrentOutletName()] = $inventoryData;

				$inventory->Close();

				$child->MoveNext();
				array_push($array, $var);
				$var = array();
			}

			$child->Close();
		} else {
			$var['titulo'] 			= $result->fields['itemName'];
			$var['sku'] 			= $result->fields['itemSKU'];
			$var['marca'] 			= getTaxonomyName($result->fields['brandId']);
			$var['categoria'] 		= getTaxonomyName($result->fields['categoryId']);

			$var['precio de costo'] = $result->fields['itemCOGS'];
			$var['precio de venta'] = $result->fields['itemPrice'];

			$inventoryData 			= '';
			$inventory 				= $db->Execute('SELECT inventoryCount FROM inventory WHERE itemId = ? AND outletId = ?', array($result->fields['itemId'], OUTLET_ID));

			$inventoryData 			.= $inventory->fields['inventoryCount'];
			$var['inventario en la sucursal ' . getCurrentOutletName()] = $inventoryData;

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
	$csv->output("items_export_" . date("d-m-Y") . ".csv", $array, $fields);

	dai();
}

if (validateHttp('action') == 'importCSV') {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$mimes 	= ['application/vnd.ms-excel', 'text/plain', 'text/csv', 'text/tsv', 'application/octet-stream'];
	$msg 	= '';
	if (!empty($_FILES['csv']['tmp_name'])) {
		if (in_array($_FILES['csv']['type'], $mimes)) {

			$fileData = file_get_contents($_FILES['csv']['tmp_name']);

			$cols 		= 8;
			$record 	= [];
			$msg 			= '';
			$maxRows 	= 2000;
			$noLines 	= 0;
			$delimitter = ',';

			$comas 		= substr_count($fileData, ',');
			$colons		= substr_count($fileData, ';');

			if ($colons > $comas) {
				$delimitter = ';';
			}

			$lines 		= explodes(PHP_EOL, $fileData);
			$data 		= [];

			foreach ($lines as $line) {
				if ($noLines > 0) {
					$data[] = str_getcsv($line, $delimitter);
				}

				$noLines++;
			}

			if ($noLines > $maxRows) {
				$msg = 'ERROR, máximo ' . $maxRows . ' líneas por archivo, ' . $noLines . ' líneas enviadas';
			} else {
				//outlets Ids by name
				$result 		= ncmExecute("SELECT outletName, outletId FROM outlet WHERE companyId = ?", [COMPANY_ID], false, true);
				$outlets 		= [];

				if ($result) {
					while (!$result->EOF) {
						$fields = $result->fields;
						$oname 	= strtolower(toUTF8($fields['outletName']));
						$outlets[$oname] = $fields['outletId'];
						$result->MoveNext();
					}
				}

				$isUpdate = validateHttp('isUpdate', 'post');

				//$skus 			= ncmExecute("SELECT GROUP_CONCAT(itemSKU) FROM item WHERE itemSKU IS NOT NULL AND companyId = ?",[COMPANY_ID]);
				$allOutletsArrayLowerCase = array_map(function($item) {
					$item['name'] = strtolower($item['name']);
					return $item;
				}, $allOutletsArray);

				foreach ($data as $val) {
					$sI = 0;

					//['TÍTULO','SKU','MARCA','CATEGORÍA','DESCRIPCIÓN','PRECIO DE VENTA','TIPO',TAX_NAME,'SUCURSAL','% DESCUENTO','UN. DE MEDIDA','% MERMA','% COMISIÓN','SESIONES','DURACIÓN EN MIN.','STOCK MÍNIMO']

					if ($isUpdate && validity($val[$sI])) {
						$id = $db->Prepare(dec($val[$sI]));
						$sI++;
					}

					$name 			= toUTF8($val[$sI]);
					$sI++;
					$sku 			= toUTF8($val[$sI]);
					$sI++;
					$brand 			= toUTF8($val[$sI]);
					$sI++;
					$category 		= toUTF8($val[$sI]);
					$sI++;
					$note 			= toUTF8($val[$sI]);
					$sI++;
					$cogs 			= formatNumberToInsertDB($val[$sI]);
					$sI++;
					$price 			= formatNumberToInsertDB($val[$sI]);
					$sI++;
					$type 			= strtolower(preg_replace('/[^A-Za-z]*$/', '', $val[$sI]));
					$sI++;
					$tax 			= $val[$sI];
					$sI++;
					$outlet			= strtolower($val[$sI]);
					$sI++;
					$discount		= $val[$sI];
					$sI++;
					$uom 			= $val[$sI];
					$sI++;
					$waste 			= $val[$sI];
					$sI++;
					$comission		= $val[$sI];
					$sI++;
					$sessions		= $val[$sI];
					$sI++;
					$minutes		= $val[$sI];
					$sI++;
					$minstock		= $val[$sI];
					$sI++;
					$inistock		= $val[$sI];

					if ($name) {
						if ($comission > 100) {
							$comission = 100;
						} else if ($comission < 0) {
							$comission = 0;
						}

						if ($type == 'producto') {
							// servicio
							$canSell 		= 1;
							$trackInventory = 1;
						} else if ($type == 'compuesto') {
							//no se vende y si inventario
							$canSell 		= 0;
							$trackInventory = 1;
						} else {
							$canSell 		= 1;
							$trackInventory = 0;
						}

						$record['itemName'] 		= $name;
						$record['itemDate'] 		= TODAY;
						$record['itemSKU'] 			= $sku;
						$record['itemStatus'] 	= 1;
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

						if ($isUpdate) {
							$insert 					= ncmUpdate(['records' => $record, 'table' => 'item', 'where' => 'itemId = ' . $id . ' AND companyId = ' . COMPANY_ID]); //$db->AutoExecute('item', $record, 'INSERT');
							$insert 					= $insert['error'] ? false : true;
							$lastInserted 				= $insert['id'];
						} else {
							$insert 					= ncmInsert(['records' => $record, 'table' => 'item']);
							$lastInserted 				= $insert;
						}

						if ($insert !== false) {
							if ($inistock) {
								$stockBlock = explodes(';', $inistock);
								foreach ($stockBlock as $block) {
									$unblock 	= explodes(':', $block);
									$outName 	= strtolower($unblock[0]);
									$outIndx 	= searchInArray($allOutletsArrayLowerCase, 'name', $outName); //getValue('outlet', 'outletId', 'WHERE outletName = "' . $outName . '" AND companyId = ' . COMPANY_ID);
									if ($outIndx !== -1) {
										$outId 		= $allOutletsArrayLowerCase[$outIndx]['id'];
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
				updateLastTimeEdit(false, 'item');
			}
		} else {
			$msg = 'ERROR, solo se pueden importar archivos CSV. (' . $_FILES['csv']['type'] . ')';
		}
	} else {
		$msg = 'ERROR, el archivo no fue subido, vuelva a intentar o contáctenos';
	}
	header('location: /@#items');
}

if (validateHttp('action') == 'csvModel') {
	include_once("libraries/parsecsv.lib.php");
	$array 		= [];
	$fields 	= ['TITULO', 'SKU', 'MARCA', 'CATEGORIA', 'DESCRIPCION', 'PRECIO DE COSTO', 'PRECIO DE VENTA', 'TIPO', TAX_NAME, 'SUCURSAL', '% DESCUENTO', 'UN. DE MEDIDA', '% MERMA', '% COMISION', 'SESIONES', 'DURACION EN MIN.', 'STOCK MINIMO', 'STOCK INICIAL (Sucursal:Cantidad;Sucursal2:Cantidad)'];
	$var 		= ['Guantes', 'B12345', 'Gadgets', 'Prendas', 'Ultra grip de cuero', '15000', '20000', 'Producto', '15', 'Central', '', 'Uni.', '', '', '', '', '5', 'Central:5;Sucursal:23'];
	$var2 		= ['Tratamiento', 'T54321', '', 'Servicios', 'Tratamiento completo', '20000', '34000', 'Servicio', '15', 'Todas', '10', '', '', '5', '3', '40', ''];

	array_push($array, $var);
	array_push($array, $var2);

	$csv = new parseCSV();
	$csv->output("items_import_example.csv", $array, $fields);
	dai();
}

if (validateHttp('action') == 'stockAdd') {
	if (!allowUser('items', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$ops['itemId']    = db_prepare(dec($_GET['id']));
	$ops['outletId']  = OUTLET_ID;
	$ops['date']      = TODAY;
	$ops['cogs']      = formatNumberToInsertDB(db_prepare($_GET['price']));
	$ops['count']     = formatNumberToInsertDB(db_prepare($_GET['count']), true, 3);

	$manage 		  = manageStock($ops);

	if ($manage) {
		echo 'true';
	} else {
		echo 'false';
	}

	dai();
}

if (validateHttp('action') == 'stockRemove') {
	if (!allowUser('items', 'delete', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$ops['itemId']    = db_prepare(dec($_GET['id']));
	$ops['outletId']  = OUTLET_ID;
	$ops['count']     = formatNumberToInsertDB(db_prepare($_GET['count']), true, 3);
	$ops['type']      = '-';

	$manage 		  = manageStock($ops);

	if ($manage) {
		echo 'true';
	} else {
		echo 'false';
	}

	dai();
}
//---------------PROCESS-------------------//

if (validateHttp('action') == 'showTable') {

	endPageLoadTimeCalculator($timeSE, 'showTable', true);

	ini_set('memory_limit', '256M');

	theErrorHandler('json');

	$cOutletName 		= getCurrentOutletName();
	$outletNow 			= ($cOutletName == 'None') ? '' : $cOutletName;
	$archived 			= ' AND itemStatus = 1';
	$unGrouped 			= ' AND (itemIsParent > 0 OR (itemParentId IS NULL OR itemParentId = 0))';
	$class 					= 'bg-light lter';
	$outletSearch 	= '';
	$singleRow 			= '';

	$debug = false;

	if (!empty($_GET['debug'])) {
		$db->debug = true;
		$debug = true;
	}

	//$limit 			= ' LIMIT ' . $plansValues[PLAN]['max_items'];
	$limits 		= getTableLimits($limitDetail, $offsetDetail);

	if (validateHttp('archived')) {
		$archived 	= ' AND itemStatus = 0 AND (itemIsParent < 1 OR itemIsParent IS NULL)';
		$unGrouped 	= '';
	}

	if (validateHttp('ungroup')) {
		$unGrouped = ' AND (itemIsParent < 1 OR itemIsParent IS NULL)';
	}

	if (validateHttp('singleRow')) {
		$singleRow = ' AND itemId = ' . dec(validateHttp('singleRow'));
	}

	//outlet search logic
	if (OUTLETS_COUNT > 1 && OUTLET_ID > 1) {
		$outletSearch = ' AND (outletId = ' . OUTLET_ID . ' OR outletId IS NULL or outletId = 0)';
	}

	if (validateHttp('src') || validateHttp('srccat')) {

		if (validateHttp('src')) {
			$word 	= db_prepare(validateHttp('src'));
			//primero obtengo posible fuente
			$sData = ncmExecute("SELECT GROUP_CONCAT(itemId) as ids FROM item WHERE (itemName LIKE '%" . $word . "%' OR itemSKU LIKE '%" . $word . "%') AND companyId = ? LIMIT 100", [COMPANY_ID], true);

			$search = ' AND itemId IN(' . $sData['ids'] . ')';

			$sql = 'SELECT *
				FROM item
				WHERE (itemParentId < 1 OR itemParentId IS NULL)' .
				$outletSearch . $singleRow . $search . ' AND companyId = ?' . $archived . '
				ORDER BY itemId DESC' . $limits;
		} else if (validateHttp('srccat')) {
			if (validateHttp('srccat') == 'none') {
				$search = ' AND categoryId IS NULL';
			} else {
				$word 	= db_prepare(dec(validateHttp('srccat')));
				$search = ' AND categoryId = ' . $word;
			}

			$sql = 'SELECT *
					FROM item
					WHERE (itemParentId < 1 OR itemParentId IS NULL)' .
				$outletSearch . $singleRow . $search . ' AND companyId = ?' . $archived . '
					ORDER BY itemId DESC' . $limits;
		}
	} else {
		$sql = 'SELECT *
						FROM item
						WHERE companyId = ? AND itemId > 0' .
			$outletSearch . $singleRow . $archived . $unGrouped .
			' ORDER BY itemId DESC ' . $limits;
	}

	endPageLoadTimeCalculator($timeSE, 'before exec query', true);

	$result 		= ncmExecute($sql, [COMPANY_ID], false, true);

	$head		= 	'	<thead class="text-u-c">' .
		'		<tr>' .
		'			<th style="max-width:60px;width:60px;"></th>' .
		'			<th>Nombre</th>' .
		'			<th>Tipo</th>' .
		'			<th>Creado</th>' .
		'			<th>Ud. Medida</th>' .
		'			<th>Código</th>' .
		'			<th>marca</th>' .
		'			<th>categoría</th>' .
		'			<th>Sucursal</th>' .
		'			<th>Sesiones</th>' .
		'			<th>Duración</th>' .
		'			<th>Merma</th>' .
		'			<th>Comisión</th>' .
		'			<th>Descuento</th>' .
		'			<th>Costo</th>' .
		'			<th>Precio</th>' .
		'			<th>Precio Sin Descuento</th>' .
		'			<th>Valor</th>' .
		'			<th>' . TAX_NAME . '</th>' .
		'			<th>stock</th>' .
		'			<th>Online</th>' .
		'		</tr>' .
		'	</thead>' .
		'	<tbody>';

	$table = '';

	if ($result) {

		endPageLoadTimeCalculator($timeSE, 'before start', true);

		$idsList 					= getAllByIDBuild($result, 'itemId');

		$childrenIds 			= getAllComapnyItemsChildren();

		$outletsArray 		= getAllOutlets();
		$allCategoriesArray = getAllItemCategories();
		$allBrandsArray 	= getAllItemBrands();
		$allWaste      		= getAllWasteValue(false, true);
		$allCompounds 		= getAllCompoundsArray(true);

		$getAllTaxNames 	= getAllTax();
		$singleRow 				= validateHttp('singleRow');
		$singleRowD				= dec($singleRow);

		if (!$singleRow) {
			if (OUTLET_ID < 1) {
				$stockArray 		= getAllItemStock(false, true, $idsList, false);
			} else {
				$stockArray 		= getAllItemStock(OUTLET_ID, false, $idsList, false);
			}
		} else {
			$singleStock 			=  getItemStock($singleRowD);

			$stockArray[$singleRowD] = ['onHand' => $singleStock['stockOnHand'], 'cogs' => $singleStock['stockOnHandCOGS'], 'cogss' => $singleStock['stockCOGS']];
		}

		endPageLoadTimeCalculator($timeSE, 'before while', true);

		while (!$result->EOF) {

			$fields 			= $result->fields;
			$brand 				= toUTF8(iftn($allBrandsArray[$fields['brandId']]['name'] ?? false, '-'));
			$category 		= toUTF8(iftn($allCategoriesArray[$fields['categoryId']]['name'] ?? false, '-'));
			$textIcon 		= 'Servicio';
			$itemId 			= enc($fields['itemId']);
			$fechUgly 		= $fields['itemDate'];
			$fecha 				= niceDate($fechUgly);
			$itemType 		= $fields['itemType'];
			$modalNarrow 	= '';
			$outletName 	= getCurrentOutletName($fields['outletId']);
			$outletName 	= iftn($outletName, 'Todas');
			$discountPerc = iftn($fields['itemDiscount'], '-', '~' . formatCurrentNumber($fields['itemDiscount'], 'no') . '%');
			$rowIsGroup 	= '';
			$rawPrice 		= $fields['itemPrice'];
			$commission 	= iftn($fields['itemComissionPercent'], '-');
			$sessions 		= iftn($fields['itemSessions'], '-');
			$duration 		= iftn($fields['itemDuration'], '-');
			$waste 				= ($fields['itemWaste'] > 0) ? $fields['itemWaste'] . '%' : '-';
			$ecom 				= '';
			$sortOnline 	= 0;
			$filterOnline = '';
			$taxName  = "0";
			if (array_key_exists(intval($fields['taxId']), $getAllTaxNames) && !empty($getAllTaxNames[intval($fields['taxId'])]['name'])) {
				$taxName = $getAllTaxNames[intval($fields['taxId'])]['name'];
			}
			// $taxName 			= array_key_exists(intval($fields['taxId']),$getAllTaxNames) && ($getAllTaxNames[intval($fields['taxId'])]['name'] ?? '0');

			if ($commission != '-') {
				if ($fields['itemComissionType']) {
					$commission = formatCurrentNumber($commission);
				} else {
					$commission = formatCurrentNumber($commission, 'no') . '%';
				}
			}

			//pricing
			if ($fields['itemPriceType']) { //si el precio es porcentual al costo
				$cogs = (float)iftn($stockArray[$fields['itemId']]['cogs'], $stockArray[$fields['itemId']]['cogss']);
				if ($fields['itemPricePercent'] < 1) {
					$rawPrice = $cogs;
				} else {
					$addPrice = divider(($cogs * $fields['itemPricePercent']), 100, true);
					$rawPrice = $cogs + $addPrice;
				}
			}

			$formatPrice = formatCurrentNumber($rawPrice);
			$formatPrice2 = formatCurrentNumber($rawPrice);
			//pricing end

			if ($fields['itemEcom']) {
				$ecom 			= '<i class="material-icons text-success">check</i>';
				$sortOnline 	= 1;
				$filterOnline 	= 'online';
			}

			$oNow 		= '';
			$child 		= '';
			$stockRaw = 0;
			//Comienza conteo de Stock
			if ($fields['itemTrackInventory'] > 0) {

				//$stockTrigger = ncmExecute('SELECT * FROM stockTrigger WHERE itemId = ? AND outletId = ? LIMIT 1',[$fields['itemId'],OUTLET_ID],true);

				$textIcon 	= 'Producto';
				$reorder 	= 0; //($stockTrigger) ? $stockTrigger['stockTriggerCount'] : 0;
				$stock 		= $stockArray[$fields['itemId']]['onHand'] ?? 0;


				if ($stock <= 0) {
					$class = 'bg-danger lter';
				} else if ($stock > $reorder) {
					$class 	= 'bg-light lter';
					$oNow 	= OUTLET_ID;
				} else if ($stock < $reorder && $stock > 0) {
					$class 	= 'bg-warning lter';
				}

				$avCOGS 	= iftn($stockArray[$fields['itemId']]['cogs'] ?? 0, $stockArray[$fields['itemId']]['cogss'] ?? 0);

				$avCOGS 	= ($avCOGS > 0) ? $avCOGS : 0;

				$prodCapTxt = '';
				if ($fields['itemProduction'] > 0) {
					$capacityIs = getProductionCapacity($allCompounds[$fields['itemId']] ?? 0, $stockArray, $allWaste);

					$textIcon 	= 'Producción';
					$prodCapTxt = 'data-toggle="tooltip" data-placement="left" title="Capacidad máxima de producción ' . $capacityIs . ' unidades"';
				}

				$amount 		= formatQty($stock, 3);
				$stockField 	= '<span class="' . $class . ' font-bold label" ' . $prodCapTxt . '>' . $amount . '</span>';

				if ($fields['itemIsParent']) {
					$stock 		= 0;
					$child 		= $childrenIds[$itemId];
					$childarr 	= explodes(',', $childrenIds[$itemId], true);
					foreach ($childarr as $childy) {
						$stock 		+= sumInventoryInOutlet($inventoryArray[$childy]);
					}
					$amount 	= formatQty($stock, 3);
					//$amount = ($stock < 1 && $stock > -1)?formatCurrentNumber($stock,'si',false,true):formatCurrentNumber($stock,'no');
					$stockField = '<span class="bg-light lter font-bold label">' . $amount . '</span>';
				}

				$stockRaw 		= $stock;
			} else {
				$avCOGS 	= 0;
				$stockField = '-';
				$allWaste 	= ($itemType == 'precombo') ? false : $allWaste;

				$capacityIs = getProductionCapacity($allCompounds[$fields['itemId']] ?? 0, $stockArray, $allWaste);

				if ($fields['itemIsParent'] || $itemType == 'group') {
					$stock 			= 0;
					$commission = '-';
					$child 			= $childrenIds[$itemId];
					$childarr 	= explodes(',', $childrenIds[$itemId]);

					foreach ($childarr as $childy) {
						$stock 		+= sumInventoryInOutlet($inventoryArray[$childy] ?? 0);
					}

					$amount 			= formatQty($stock, 3);
					$stockField 	= '<span class="bg-light lter font-bold label">' . $amount . '</span>';
					$textIcon 		= 'Grupo';
					$modalNarrow 	= 'modal-narrow';
					$rowIsGroup 	= 'group';
					$formatPrice	= '-';
					$stockRaw 		= $stock;
				} else if ($itemType == 'precombo') {
					$textIcon 		= 'Combo Predefinido';
					$stockField 	= '<span class="no-bg b b-light text-muted font-bold label" data-toggle="tooltip" data-placement="left" title="Disponibilidad">' . $capacityIs . '</span>';
					$stockRaw 		= $capacityIs;
				} else if ($itemType == 'combo') {
					$textIcon 		= 'Combo Dinámico';
					$stockField 	= '-';
					$stockRaw 		= 0;
				} else if ($itemType == 'comboAddons') {
					$textIcon 		= 'Combo Add-on';
					$stockField 	= '-';
					$stockRaw 		= 0;
				} else if ($itemType == 'direct_production') {
					$avCOGS 			= 0; //getProductionCOGS(dec($itemId));
					$textIcon 		= 'Producción Directa';
					$prodCapTxt 	= 'data-toggle="tooltip" data-placement="left" title="Capacidad actual"';
					$stock 			= $capacityIs;
					$amount 		= formatQty($stock, 3);
					$stockField 	= '<span class="bg-light lter font-bold label" ' . $prodCapTxt . '>' . $amount . '</span>';
					$stockRaw 		= $stock;
				}
			}

			//$stockRaw 		= formatNumberToInsertDB($stockRaw);//formateo numero a raw

			//Activo
			if ($fields['itemCanSale'] < 1 || $itemType == 'compound') {
				$textIcon = 'Activo/Compuesto';
			}

			if ($itemType == 'giftcard') {
				$textIcon 		= 'Gift Card';
				$modalNarrow 	= 'modal-narrow';
			} else if ($itemType == 'discount') {
				$textIcon 		= '.Descuento';
				$modalNarrow 	= 'modal-narrow';
				$discount 		= '';
			}

			$imgBlock 			= '';

			if ($fields['itemImage'] == 'true') {
				$imgBlock 			= 'class="lazy" data-src="https://assets.encom.app/60-60/0/' . enc(COMPANY_ID) . '_' . $itemId . '.jpg"';
			}

			$table .= 	'<tr id="' . $itemId . '" class="clickrow ' . $modalNarrow . ' ' . $rowIsGroup . '" data-to-filter="' . enc($oNow) . '" >' .
				' 	<td ' . $imgBlock . '>' .
				'		<span class="hidden hidden-print">' . iftn($child, implodes(',', [$itemId, $child]), $itemId) . '</span>' .
				'	</td>' .
				'	<td class="font-bold">' . toUTF8($fields['itemName']) . '</td>' .
				'	<td>' . $textIcon . '</td>' .
				' 	<td data-sort="' . $fechUgly . '">' . $fecha . '</td>' .
				' 	<td>' . iftn($fields['itemUOM'], '-', '<span class="badge">' . toUTF8($fields['itemUOM']) . '</span>') . '</td>' .
				'	<td>' . iftn($fields['itemSKU'], '-') . '</td>' .
				'	<td>' . $brand . '</td>' .
				'	<td>' . $category . '</td>' .
				'	<td>' . $outletName . '</td>' .
				'	<td class="text-right bg-light lter" data-sort="' . $fields['itemSessions'] . '">' . $sessions . '</td>' .
				'	<td class="text-right bg-light lter" data-sort="' . $fields['itemDuration'] . '">' . $duration . '</td>' .
				'	<td class="text-right bg-light lter" data-sort="' . $fields['itemWaste'] . '">' . $waste . '</td>' .
				'	<td class="text-right bg-light lter" data-sort="' . $fields['itemComissionPercent'] . '">' . $commission . '</td>' .
				'	<td class="text-right bg-light lter" data-sort="' . $fields['itemDiscount'] . '">' . $discountPerc . '</td>';

			if ($itemType == 'product' || $itemType == 'precombo' || $itemType == 'giftcard') {

				$compoundsPrice = getComboCOGS($fields['itemId']);

				if ($fields['itemDiscount'] > 0) {
					$discount		= round_precision(abs($rawPrice * ($fields['itemDiscount'] / 100)), 2);
					$discount		= $rawPrice - $discount;
					$formatPrice 	= '<strike class="text-xs text-danger block">' . formatCurrentNumber($rawPrice) . '</strike>' . formatCurrentNumber($discount);
					$formatPrice2 	= formatCurrentNumber($rawPrice);

				}

				if ($compoundsPrice > $rawPrice) {
					$formatPrice 	= '<strike class="text-xs text-danger block">' . formatCurrentNumber($compoundsPrice) . '</strike>' . formatCurrentNumber($rawPrice);
					$formatPrice2 	= formatCurrentNumber($compoundsPrice);
				}
			}

			$table .= 	'	<td class="text-right bg-light lter" data-sort="' . iftn($avCOGS, '0', $avCOGS) . '" data-format="money" width="12%">' .
				iftn($avCOGS, '-', formatCurrentNumber($avCOGS)) .
				'	</td>' .
				'	<td class="text-right bg-light lter" data-sort="' . $fields['itemPrice'] . '" data-format="money" width="12%">' .
				$formatPrice .
				'	</td>' .
				'	<td class="text-right bg-light lter" data-sort="' . $fields['itemPrice'] . 'New" data-format="money" width="12%">' .
				$formatPrice2 .
				'	</td>' .
				'	<td class="text-right bg-light lter" data-sort="' . iftn($avCOGS, '0', ($avCOGS * $stockRaw)) . '" data-format="money" width="10%">' .
				(($avCOGS <= 0) ? '0' : formatCurrentNumber($avCOGS * $stockRaw)) .
				'	</td>' .
				'	<td class="text-right bg-light lter" data-sort="' . $taxName . '" data-format="money" width="10%">' .
				$taxName .
				'%	</td>' .
				'	<td class="text-right" width="5%" data-sort="' . $stockRaw . '">' .
				$stockField .
				'	</td>' .
				' 	<td data-sort="' . $sortOnline . '" data-filter="' . $filterOnline . '">' . $ecom . '</td>' .
				'</tr>';

			// $itemsListforJS .= '["'.$itemId.'","'.toUTF8($fields['itemName']).'"],"';


			if (validateHttp('part') && !$singleRow) {
				$table .= '[@]';
			}


			$result->MoveNext();
		}

		$result->Close();
	}

	endPageLoadTimeCalculator($timeSE, 'after while', true);
	$foot = 	'</tbody>' .
		'<tfoot>' .
		'	<tr class="text-right strong">' .
		'		<th colspan="9">TOTALES:</th>' .
		'		<th class="text-right"></th>' .
		'		<th class="text-right"></th>' .
		'		<th class="text-right"></th>' .
		'		<th class="text-right"></th>' .
		'		<th class="text-right"></th>' .
		'		<th class="text-right"></th>' .
		'		<th class="text-right"></th>' .
		'		<th class="text-right"></th>' .
		'		<th class="text-right"></th>' .
		'		<th></th>' .
		'	</tr>' .
		'</tfoot>';


	endPageLoadTimeCalculator($timeSE, 'before cats btn', true);

	$catsBtn = 	'<span class="btn-group m-r-xs">' .
		'	<a href="#" class="btn dropdown-toggle b b-light r-3x" data-toggle="dropdown" id="typeActivator"  title="Categorías">' .
		'		<span class="material-icons">category</span>' .
		'	</a>' .
		'	<ul class="dropdown-menu animated fadeIn speed-4x" style="max-height:400px; overflow:auto;">';
	$result = ncmExecute('SELECT taxonomyId, taxonomyName, CAST(taxonomyExtra as UNSIGNED) as sort FROM taxonomy WHERE taxonomyType = "category" AND ' . $SQLcompanyId . ' ORDER BY sort ASC LIMIT 500', [], false, true);
	if ($result) {
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

	endPageLoadTimeCalculator($timeSE, 'after cats btn', true);

	if (validateHttp('part')) {
		dai($table);
	} else {
		$fullTable 											= $head . $table . $foot;
		$jsonResult['table'] 						= $fullTable;
		$jsonResult['categoriesSelect'] = $catsBtn;

		endPageLoadTimeCalculator($timeSE, 'END: ' . $fullTable, true);

		header('Content-Type: application/json');
		dai(json_encodes($jsonResult, true));
	}
}
?>

<div class="hidden-print col-xs-12">
	<div class="col-xs-9 text-left m-t-sm no-padder">

		<a href="#contacts?rol=supplier" class="hidden-xs m-t m-r" data-toggle="tooltip" data-placement="bottom" title="Administrar Proveedores">Proveedores</a>

		<?php
		if (validateHttp('archived')) {
		?>
			<a href="/@#items" class="hidden-xs m-t m-r viewArchived" data-typea="active" data-toggle="tooltip" data-placement="bottom" title="Ver artículos Activos">Activos</a>
		<?php
		} else {
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
		<?= ($_modules['production']) ? '<a href="#bulk_production" class="hidden-xs m-r" data-toggle="tooltip" data-placement="bottom" title="Producir uno o más productos">' : '<a href="javascript:;" class="hidden-xs m-r" data-placement="bottom" data-toggle="tooltip" data-html="true" title="Habilite el módulo de producción">' ?>Producir</a>
		<a href="#bulk_transfer" class="hidden-xs m-r" data-toggle="tooltip" data-placement="bottom" title="Transferir stock entre sucursales y depósitos">Transferir</a>
		<a href="#inventory_count" class="hidden-xs m-r" data-toggle="tooltip" data-placement="bottom" title="Realizar un conteo parcial o completo de inventario">Contabilizar</a>

	</div>

	<div class="col-xs-3 text-right no-padder">
		<div class="btn-group pull-right">
			<button class="btn btn-info bg-info dk btn-rounded dropdown-toggle" data-toggle="dropdown"><span class="m-r-sm font-bold text-u-c">Crear</span><span class="caret"></span></button>
			<ul class="dropdown-menu animated fadeIn speed-4x">
				<li class="create" data-type="<?= enc(0); ?>"><a href="#" class="createItemBtn">Artículo</a></li>
				<li class="create" data-type="<?= enc(1); ?>"><a href="#" class="combo createItemBtn">Kit o Combo</a></li>
				<li class="create" data-type="<?= enc(2); ?>"><a href="#" class="discount createItemBtn modal-narrow">Descuento</a></li>
				<li class="create" data-type="<?= enc(3); ?>"><a href="#" class="giftcard createItemBtn modal-narrow">Gift Card</a></li>
				<li class=""><a href="<?= $baseUrl; ?>?action=formCSV" id="bulkUpload">Múltiples Artículos</a></li>
			</ul>
		</div>
	</div>
</div>

<div class="wrapper col-xs-12">
	<?= headerPrint(); ?>

	<div class="col-sm-6 no-padder hidden-xs">

	</div>

	<div class="col-sm-6 col-xs-12 no-padder text-right">
		<span class="font-bold h1">
			<a href="https://docs.encom.app/panel-de-control/articulos" class="m-r-sm hidden-print" target="_blank" data-toggle="tooltip" data-placement="left" title="" data-original-title="Visitar el centro de ayuda">
				<i class="material-icons text-info m-b-xs">help_outline</i>
			</a>
			<span id="pageTitle">Productos y Servicios</span>
		</span>
	</div>
</div>

<div class="wrapper col-xs-12 panel r-24x push-chat-down table-responsive tableContainer">
	<table class="table hover no-padder" id="tableItems">
		<?= placeHolderLoader('table') ?>
	</table>
</div>

<script>
	var isArchived = <?php echo validateHttp('archived') ? 'true' : 'false' ?>;
	var archived = "<?php echo validateHttp('archived') ? '&archived=true' : '' ?><?php echo validateHttp('ungroup') ? '&ungroup=true' : '' ?>";
	window.limit = <?= $limitDetail ?>;
	window.offset = <?= $offsetDetail ?>;
	window.baseUrl = '<?= $baseUrl; ?>';
	window.baseUrlH = window.baseUrl.replace('a_', '').replace('/', '');
	var ncmDBActive = '<?= $_modules['dropboxToken'] ?>';
	var thousandSeparator = '<?= THOUSAND_SEPARATOR ?>';
	var decimal = '<?= DECIMAL ?>';
	var currency = '<?= CURRENCY ?>';
	var taxName = '<?= TAX_NAME ?>';
	var companyId = '<?= enc(COMPANY_ID) ?>';
	var itemsOps = '';




	function addLoteDate() {

		var vencimiento = $('#itemVencimiento').val();
		var lote = $('#itemLote').val();
		var id = $('#itemId').val();

		var data = {
			tasks: [{
					date: "2023-05-30",
					type: "VencimientoLoteArticulo",
					resourceId: "Ng1WN",
					lote: "1",
					data: {
						descripcion: "producto",
						lote: "243453645"
					}
				},
				{
					date: "2023-05-31",
					type: "VencimientoLoteArticulo",
					resourceId: "Ng1WN",
					lote: "14",
					data: {
						descripcion: "producto",
						lote: "2434536454"
					}
				}
			],
			api_key: "cc58c3ead1b111d48f5c0d677765f362e2a55598",
			company_id: "<?= COMPANY_ID ?>",
		};

		var datos = {
			date: vencimiento,
			type: "VencimientoLoteArticulo",
			resourceId: id,
			lote: "1",
			data: {
				descripcion: "producto",
				lote: lote,
			},
			api_key: "cc58c3ead1b111d48f5c0d677765f362e2a55598",
			company_id: "<?= COMPANY_ID ?>",
		};
		$.ajax({
			url: "<?= API_URL . '/add_task' ?>",
			type: 'POST',
			data: datos,
			success: function(response) {
				console.log(response);
			},
			error: function(xhr, status, error) {
				console.log(error);
			}
		});
	}
	<?php
	if (!empty($_GET['update'])) {
		ob_start();
	?>

		if (!isArchived) {
			itemsOps += '<li><a href="#" id="bulkEditBtn" data-type="bulkEdit" class="multi text-default"><span class="material-icons m-r-sm">edit</span>Edición masiva</a></li>' +
				'<li><a href="#" id="" data-type="group" class="multi group text-default"><span class="material-icons m-r-sm">done_all</span>Agrupar</a></li>' +
				'<li><a href="#" id="" data-type="barcode" class="multi text-default"><span class="material-icons m-r-sm">qr_code_2</span>Códigos de Barra</a></li>';
		}

		itemsOps += '<li>';

		if (isArchived) {
			itemsOps += '	<a href="#" data-type="unarchive" class="multi text-default"><span class="material-icons m-r-sm">unarchive</span>Re Activar</a>' +
				'<a href="#" data-type="delete" class="multi text-default"><span class="material-icons text-danger">delete_forever</span>Eliminar</a>';
		} else {
			itemsOps += '	<a href="#" data-type="archive" class="multi text-default" data-toggle="tooltip" data-placement="top" title="Desactivar los artículos seleccionados para que no se muestren en la caja registradora">' +
				'		<span class="material-icons text-dangers m-r-sm">archive</span>Archivar' +
				'	</a>';
		}

		itemsOps += '</li>';



		$(document).ready(function() {
					window.baseUrl = window.baseUrl ? window.baseUrl : '/items';
					FastClick.attach(document.body);
					$('.matchCols').matchHeight();

					function getAllSelectedValues(table, type) {
						var ids = [];
						table.rows('.selected').iterator('row', function(context, index) {
							var $el = $(this.row(index).node());
							var id = $el.attr('id');
							var count = 0;

							if (type) {
								count = $('#inventoryCountRow' + id).text();
								if (count == "+") {
									ids.push(id);
								} else {
									count = (count < 1) ? 1 : count;

									for (a = 0; a < count; a++) {
										ids.push(id);
									}
								}
							} else {
								ids.push(id);
							}
						});

						return ids;
					};

					var url = baseUrl + "?action=showTable" + iftn(archived, '');
					var rawUrl = url;
					var xhr = $.get(url, function(result) {

						var tiposList = [{
								type: 'products',
								name: 'Productos',
								search: 'producto'
							},
							{
								type: 'service',
								name: 'Servicios',
								search: 'servicio'
							},
							{
								type: 'combo',
								name: 'Combos',
								search: 'combo'
							},
							{
								type: 'production',
								name: 'Producción',
								search: 'producci'
							},
							{
								type: 'compounds',
								name: 'Compuestos',
								search: 'activo/comp'
							},
							{
								type: 'groups',
								name: 'Grupos',
								search: 'grupo'
							},
							{
								type: 'giftcards',
								name: 'Gift Cards',
								search: 'gift card'
							},
							{
								type: 'discounts',
								name: 'Descuentos',
								search: 'descuento'
							}
						];

						var tiposDrop = '<span class="btn-group">' +
							'	<a href="#" class="btn dropdown-toggle b b-light r-3x m-r-xs" data-toggle="dropdown" id="typeActivator" title="Tipos">' +
							'		<span class="material-icons">filter_list</span>' +
							'	</a>' +
							'	<ul class="dropdown-menu animated fadeIn speed-4x" id="typeActivatorMenu">' +
							'		<li><a href="#" data-type="all" class="typeActivator text-default">' +
							'			<i class="material-icons m-r-xs text-white">check</i>Todos</a></li>';
						$.each(tiposList, function(i, val) {
							tiposDrop += '<li><a href="#" data-type="' + val.type + '" data-name="' + val.search + '" data-index="' + i + '" class="typeActivator typeActivatorBtn' + i + ' text-default">' +
								'<i class="material-icons m-r-xs text-white">check</i>' + val.name +
								'</a></li>';
						});
						tiposDrop += '	</ul></span>';

						var options = {
							"container": ".tableContainer",
							"url": url,
							"rawUrl": rawUrl,
							"iniData": result.table,
							"table": ".table",
							"sort": 3,
							"footerSumCol": [14, 15, 16, 18],
							"currency": currency,
							"decimal": decimal,
							"thousand": thousandSeparator,
							"offset": window.offset,
							"limit": window.limit,
							"nolimit": true,
							"tableName": 'tableItems',
							"fileTitle": 'Inventario',
							"ncmTools": {
								left: tiposDrop + result.categoriesSelect,
								right: '<input type="text" class="form-control rounded no-border bg-light lter" placeholder="Buscar por Nombre o SKU" id="itemSearch" data-url="' + rawUrl + '&qry=">',
								ops: {
									menuTop: itemsOps,
									menuBottom: ''
								}
							},
							"colsFilter": {
								name: 'items9',
								menu: [{
										"index": 0,
										"name": 'Imagen',
										"visible": false
									},
									{
										"index": 1,
										"name": 'Artículo',
										"visible": true
									},
									{
										"index": 2,
										"name": 'Tipo',
										"visible": false
									},
									{
										"index": 3,
										"name": 'Fecha',
										"visible": false
									},
									{
										"index": 4,
										"name": 'Ud. Medida',
										"visible": false
									},
									{
										"index": 5,
										"name": 'Código/SKU',
										"visible": false
									},
									{
										"index": 6,
										"name": 'Marca',
										"visible": false
									},
									{
										"index": 7,
										"name": 'Categoría',
										"visible": true
									},
									{
										"index": 8,
										"name": 'Sucursal',
										"visible": false
									},

									{
										"index": 9,
										"name": 'Sesiones',
										"visible": false
									},
									{
										"index": 10,
										"name": 'Duración',
										"visible": false
									},
									{
										"index": 11,
										"name": 'Merma',
										"visible": false
									},

									{
										"index": 12,
										"name": 'Comisión',
										"visible": false
									},
									{
										"index": 13,
										"name": 'Descuento',
										"visible": false
									},
									{
										"index": 14,
										"name": 'Costo',
										"visible": false
									},
									{
										"index": 15,
										"name": 'Precio',
										"visible": true
									},
									{
										"index": 16,
										"name": 'Valor',
										"visible": false
									},
									{
										"index": 17,
										"name": taxName,
										"visible": false
									},
									{
										"index": 18,
										"name": 'Stock',
										"visible": false
									},
									{
										"index": 19,
										"name": 'Online',
										"visible": false
									}
								]
							},
							"clickCB": function(event, tis) {
								var id = tis.attr('id');
								helpers.loadPageLoad = false;
								window.location.hash = window.baseUrlH + '&i=' + id;
								$(window).trigger('hashvarchange');
							}
						};

						ncmDataTables(options, function(oTable, _scope) {
							loadTheTable(options, oTable);
						});
					});

					window.xhrs.push(xhr);

					adm();

					var loadTheTable = function(tableOps, oTable, _scope) {
						window.oTable = oTable;
						window.tableOps = tableOps;
						$('[data-toggle="tooltip"]').tooltip();

						$("table td.lazy").lazy();
						$('#bodyContent').scroll(function() {
							$("table td.lazy").lazy();
						});

						ncmHelpers.onClickWrap('.typeActivator', function(event, tis) {
							var $tis = tis;
							var type = $tis.data('type');
							var find = '';
							var index = $tis.data('index');
							var name = $tis.data('name');
							var colIdx = 2;

							$.fn.dataTable.ext.search.pop();

							$('a.typeActivator i').removeClass('text-info').addClass('text-white');
							$('a.typeActivator').removeClass('active');

							if (type == 'all') {
								window.oTable.draw();
								return false;
							}

							if (!$tis.hasClass('active')) {
								//habilito
								$('.typeActivatorBtn' + index + ' i').removeClass('text-white').addClass('text-info');
								$tis.addClass('active');

								$.fn.dataTable.ext.search.push(
									function(settings, data, dataIndex) {
										var field = data[colIdx].toLowerCase();

										if (field.indexOf(name) >= 0) {
											return data[colIdx];
										}
									}
								);
							} else {
								$('.typeActivatorBtn' + index + ' i').removeClass('text-info').addClass('text-white');
								$tis.removeClass('active');
							}

							window.oTable.draw();
						});

						ncmHelpers.onClickWrap('#editItem .clickrow', function(event, tis) {
							var id = tis.attr('id');
							helpers.loadPageLoad = false;
							window.location.hash = window.baseUrlH + '&i=' + id;
							$(window).trigger('hashvarchange');
						});

						ncmHelpers.onClickWrap('.createItemBtn', function(event, tis) {
							var extraUrl = '';
							if (tis.hasClass('discount')) {
								extraUrl = '&discount=true';
							} else if (tis.hasClass('combo')) {
								extraUrl = '&combo=true';
							} else if (tis.hasClass('giftcard')) {
								extraUrl = '&giftcard=true';
							}

							var narrow = tis.hasClass('modal-narrow');

							$.get(baseUrl + '?action=insertBtn' + extraUrl, function(response) {
								if (validity(response, 'string')) {
									response = response.split('|');
								} else {
									ncmDialogs.alert('No posee permisos');
									return false;
								}

								if (response[0] == 'true') {

									id = response[2];
									loadForm(baseUrl + '?action=editform&id=' + id, '#modalLarge .modal-content', function() {
										if (narrow) {
											$('#modalLarge .modal-dialog').removeClass('modal-lg');
										} else {
											$('#modalLarge .modal-dialog').addClass('modal-lg');
										}

										editItemActions();

										$('#modalLarge').modal('show').one('shown.bs.modal', function() {
											$.get(tableOps.rawUrl + '&part=1&singleRow=' + id, function(data) {
												var $tr = $(data);
												oTable.row.add($tr).draw();
											});
										}).one('show.bs.modal', function() {

										});
									});

								} else if (response[0] == 'false') {
									message('Error al intentar procesar su petición', 'danger');
								} else if (response[0] == 'max') {
									ncmDialogs.confirm('Ha alcanzado el límite de artículos', 'Contáctenos y le asistiremos para incrementar', 'warning');
								} else {
									ncmDialogs.alert(response[0]);
									return false;
								}
							});
						});

						ncmHelpers.onClickWrap('.ungroup', function(event, tis) {
							confirmation('¿Realmente desea remover del grupo?', function(e) {
								if (e === true) {
									var id = tis.data('id');
									$.get(baseUrl + '?action=ungroup&id=' + id, function(response) {
										if (response == 'true') {
											message('Removido', 'success');

											$('.row' + id).remove();
											$.get(tableOps.rawUrl + '&part=1&singleRow=' + id, function(data) {
												oTable.row.add($(data));
											});

											oTable.draw();

										} else {
											message('Error al intentar procesar su petición', 'danger');
										}
									});
								}
							});
						});

						ncmHelpers.onClickWrap('.multi', function(event, tis) {
							var type = tis.data('type');
							var selected = getAllSelectedValues(window.oTable);
							console.log(selected);

							spinner('body', 'show');

							if (selected.length < 1) {
								ncmDialogs.alert('No ha seleccionado ningún artículo', 'warning', 'Puede seleccionar presionando Shift + click');
								spinner('body', 'hide');
								return false;
							} else if (selected.length == 1) {
								if (type == 'barcode') {
									prompter("Ingrese la cantidad de códigos a imprimir", function(cant) {
										if (cant) {
											spinner('body', 'hide');
											window.open('/barcode?ids=' + selected + '-' + cant);
										}
									});
								} else if (type == 'delete' || type == 'edit' || type == 'group' || type == 'bulkEdit') {
									ncmDialogs.alert('Debe seleccionar mas de un artículo de la lista');
									spinner('body', 'hide');
								} else if (type == 'archive') {
									var url = baseUrl + '?multi=true&action=archive&id=' + selected.join('|');

									$.get(url, function(response) {
										//console.log(response);
										if (validity(response)) {
											message('Archivado', 'success');
											$.each(selected, function(k, id) {
												var $tRow = $('tr#' + id);
												if ($tRow.length > 0) {
													oTable.row($tRow).remove();
												}
											});
											oTable.draw();
										} else {
											message('Error al intentar procesar su petición', 'danger');
										}
										spinner('body', 'hide');
									});
								} else if (type == 'unarchive') {

									var url = baseUrl + '?multi=true&action=unarchive&id=' + selected.join('|');

									$.get(url, function(response) {
										//console.log(response);
										if (validity(response)) {
											message('Re Activado', 'success');
											$.each(selected, function(k, id) {
												var $tRow = $('tr#' + id);
												if ($tRow.length > 0) {
													oTable.row($tRow).remove();
												}
											});
											oTable.draw();
										} else {
											message('Error al intentar procesar su petición', 'danger');
										}
										spinner('body', 'hide');
									});
								} else if (type == 'inventory') {
									thalog('inventory');
									var multiSelect = getAllSelectedValues();
									window.open('inventory-view.php?ids=' + multiSelect.join('|'));
									spinner('body', 'hide');
								}
							} else {
								if (type == 'barcode') {
									var multiSelect = getAllSelectedValues(oTable, true);
									window.open('/barcode?ids=' + multiSelect.join('|'));
									spinner('body', 'hide');
								} else if (type == 'delete') {
									spinner('body', 'hide');
									ncmDialogs.confirm('¿Desea eliminar?', 'Se perderán todos los datos, inventario y reportes relacionados a estos artículos', 'warning', function(e) {
										if (e) {
											var url = baseUrl + '?multi=true&action=delete&id=' + selected.join('|');

											$.each(selected, function(k, id) {
												var $tRow = $('tr#' + id);
												if ($tRow.length > 0) {
													oTable.row($tRow).remove();
												}
											});

											oTable.draw();

											$.get(url, function(response) {
												if (response == 'true') {
													message('Eliminado', 'success');
												} else {
													message('Error al intentar procesar su petición', 'danger');
												}

												spinner('body', 'hide');
											});
										}
									});
								} else if (type == 'group') {
									var $cbx = $('.table tr.selected');
									var editGroup = false;
									var allGroups = true;
									$cbx.each(function(i) {
										if ($(this).hasClass('group')) {
											editGroup = $(this).attr('id');
										} else {
											allGroups = false;
										}
									});

									if (editGroup && !allGroups) {
										var url = baseUrl + '?multi=true&group=' + editGroup + '&action=groupEdit&id=' + selected.join('|');
										$.get(url, function(response) {
											if (response == 'true') {
												message('Realizado', 'success');
												$.each(selected, function(k, id) {
													if (id != editGroup) { //elimino todos menos el grupo
														var $tRow = $('tr#' + id);
														if ($tRow.length > 0) {
															oTable.row($tRow).remove().draw();
														}
														spinner('body', 'hide');
													}
												});
											} else {
												message('Error al intentar procesar su petición', 'danger');
											}
										});
									} else {
										spinner('body', 'hide');
										prompter("Nombre del Grupo", function(name) {
											if (name) {
												var url = baseUrl + '?multi=true&name=' + name + '&action=group&id=' + selected.join('|');

												$.get(url, function(response) { //respuesta será ID del grupo creado
													if (response) {
														$.get(tableOps.rawUrl + '&part=1&singleRow=' + response, function(data) {
															oTable.row.add($(data)).draw();
														});

														$.each(selected, function(k, id) {
															var $tRow = $('tr#' + id);
															if ($tRow.length > 0) {
																oTable.row($tRow).remove();
															}
														});

														oTable.draw();
														spinner('body', 'hide');
													} else {
														message('Error al intentar procesar su petición', 'danger');
													}
												});
											}
										});
									}
								} else if (type == 'archive') {

									var url = baseUrl + '?multi=true&action=archive&id=' + selected.join('|');

									$.get(url, function(response) {
										//console.log(response);
										if (validity(response)) {
											message('Archivados', 'success');
											$.each(selected, function(k, id) {
												var $tRow = $('tr#' + id);
												if ($tRow.length > 0) {
													oTable.row($tRow).remove();
												}
											});
										} else {
											message('Error al intentar procesar su petición', 'danger');
										}
										oTable.draw();
										spinner('body', 'hide');
									});
								} else if (type == 'unarchive') {

									var url = baseUrl + '?multi=true&action=unarchive&id=' + selected.join('|');

									$.get(url, function(response) {
										//console.log(response);
										if (response == 'true') {
											message('Re Activados', 'success');
											$.each(selected, function(k, id) {
												$.get(tableOps.rawUrl + '&part=1&singleRow=' + id, function(data) {
													var $tRow = $('tr#' + id);
													oTable.row($tRow).remove();
												});
											});
										} else {
											message('Error al intentar procesar su petición', 'danger');
										}
										oTable.draw();
										spinner('body', 'hide');
									});
								} else if (type == 'bulkEdit') {
									var rowIndex = [];

									var load = baseUrl + '?action=bulkEditForm';
									loadForm(load, '#modalLarge .modal-content', function() {
										$('#modalLarge').modal('show');
										$('[data-toggle="tooltip"]').tooltip();
										masksCurrency($('.maskInteger'), thousandSeparator, 'no');
										masksCurrency($('.maskCurrency'), thousandSeparator, decimal);
										$('input#bulkUpdateIds').val(selected.join('|'));
										select2Simple($(".search,.searchSimple"));
										spinner('body', 'hide');
									});
								} else if (type == 'inventory') {
									var multiSelect = getAllSelectedValues(oTable, true);
									window.open('inventory-view.php?ids=' + multiSelect.join('|'));
									spinner('body', 'hide');
								} else if (type == 'export') {
									thalog('export');
									var multiSelect = getAllSelectedValues(oTable, true);
									window.open('?multi=true&action=exportCSV&ids=' + selected.join('|'));
									spinner('body', 'hide');
								}
							}
						});

						$(window).off('hashvarchange').on('hashvarchange', function() {
							var rawHash = window.location.hash.substring(1);
							var jHash = rawHash.split('&').reduce(function(result, item) {
								var parts = item.split('=');
								result[parts[0]] = parts[1];
								return result;
							}, {});

							if (jHash['i']) {
								var tis = $('#' + jHash['i']);
								var load = baseUrl + '?action=editform&id=' + jHash['i'];
								var narrow = tis.hasClass('modal-narrow');
								var modal = '#modalLarge';

								if (!tis.length) {
									return false;
								}

								if (narrow) {
									var modal = '#modalSmall';
									var placeHolder = '<img src="/images/itemPlaceholderNarrow.png"/>';
								} else {
									var placeHolder = '<img src="/images/itemPlaceholder.png"/>';
								}

								if ($('.modal').is(':visible')) {
									$('.modal').modal('hide');
								}

								$(modal).find('.modal-content').html('<div class="col-xs-12 no-padder">' + placeHolder + '</div>', function() {
									setTimeout(function() {
										$(modal).modal('show');
									}, 300);

									loadForm(load, modal + ' .modal-content', function() {
										helpers.loadPageLoad = true;
										editItemActions();
									});
								});
							}
						});

						$(window).trigger('hashvarchange');



						ncmHelpers.onClickWrap('.itemsAction', function(event, tis) {
							var type = tis.data('type'); //obtengo el tipo de accion
							var index = parseInt(tis.data('position'));
							var id = tis.data('id');
							var load = tis.data('load');
							var element = tis.data('element');
							var narrow = tis.hasClass('modal-narrow');

							if (tis.hasClass('disabled')) {
								return false;
							}

							if (type == 'deleteItem' || type == 'archiveItem') {
								var warn = (type == 'archiveItem') ? '¿Seguro que desea Archivar?' : '¿Seguro que desea eliminar?';
								var done = (type == 'archiveItem') ? 'archivado' : 'eliminado';
								confirmation(warn, function(e) {
									if (e) {
										$.get(load, function(response) {
											if (response == 'false') {
												message('Error al eliminar', 'danger');
												return;
											}

											oTable.row($('tr#' + id)).remove().draw();
											$('#modalLarge').modal('hide');
											message('Artículo ' + done, 'success');
											$('.modal').modal('hide');
										});
									}
								});
							} else if (type == 'empty') {

							}
						});

						var srcValCache = '';
						$('#itemSearch').off('keyup').on('keyup', function(e) {
							var $tis = $(this);
							var value = $tis.val();
							var tmout = 800;
							var code = e.keyCode || e.which;

							if (code == 13) { //Enter keycode
								if (value.length > 2) {
									if (!$.trim(value) || srcValCache == value) {
										return false;
									}

									spinner(tableOps.container, 'show');
									$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1', function(result) {
										oTable.rows().remove();
										if (result) {
											var line = explodes('[@]', result);
											$.each(line, function(i, data) {
												if (data) {
													oTable.row.add($(data));
												}
											});
										}

										oTable.draw();

										$('.lodMoreBtnHolder').addClass('hidden');
										spinner(tableOps.container, 'hide');
									});


									srcValCache = value;

								} else if (value.length < 1 || !value) {
									srcValCache = '';

									ncmDataTablesReset(oTable, tableOps);
								} else {
									message('Añada por lo menos 3 caracteres', 'warning');
								}
							}
						});

						select2Simple($(".search,.searchSimple"));

						ncmHelpers.onClickWrap('.filterByCategory', function(event, tis) {
							var id = tis.data('id');
							spinner(tableOps.container, 'show');
							$('.filterByCategory').addClass('text-default');
							tis.removeClass('text-default');

							if (id == 'all') {
								ncmDataTablesReset(oTable, tableOps);
							} else {
								var url = tableOps.rawUrl + '&srccat=' + id + '&part=1&nolimit=1';
								$.get(url, function(result) {
									oTable.rows().remove();
									if (result) {
										var line = explodes('[@]', result);
										$.each(line, function(i, data) {
											if (data) {
												oTable.row.add($(data));
											}
										});
									}

									oTable.draw();

									$('.lodMoreBtnHolder').addClass('hidden');
									spinner(tableOps.container, 'hide');
								});
							}
						});

						ncmHelpers.onClickWrap('.table span.check', function(event, tis) {
							var $this = tis
							var $input = $this.find('input');
							var val = $input.val();
							var $tr = $this.closest('tr');

							if ($this.hasClass('selected')) {
								$this.removeClass('selected');
							} else {
								$this.addClass('selected');
							}

							if ($tr.hasClass('selected')) {
								$tr.removeClass('selected');
							} else {
								$tr.addClass('selected');
							}
						});

						submitForm2('#addItem,#editItem,#insertItem', function(element, id) {
							var modalId = '#modalLarge';
							if ($('#modalSmall').is(':visible')) {
								modalId = '#modalSmall';
							}

							loadForm(baseUrl + '?action=editform&id=' + id, modalId + ' .modal-content', function() {
								//$('.modal').modal('hide');
								$('.matchCols').matchHeight();
								$('#modalLarge, #modalSmall').trigger('ncmModalUpdate');
							});

							$.get(tableOps.rawUrl + '&part=1&singleRow=' + id, function(data) {
								var $tRow = $('tr#' + id);
								if ($tRow.length > 0) {
									oTable.row($tRow).remove();
									if (data) {
										oTable.row.add($(data));
									}
								}
								oTable.draw();
							});
						}, true);

						submitForm2('#editItemBulk', function(element, ids) {
							$('#modalLarge').modal('hide');
							var idss = ids.split(',');
							$.each(idss, function(k, id) {
								$.get(tableOps.rawUrl + '&part=1&singleRow=' + id, function(data) {
									var $tRow = $('tr#' + id);
									if ($tRow.length > 0) {
										oTable.row($tRow).remove();
										if (data) {
											oTable.row.add($(data));
										}
									}
									oTable.draw();
								});
							});
						}, true);

						submitForm2('#csvForm', function(element, ids) {
							$('#modalSmall').modal('hide');

						}, true);

						submitForm2('#inventoryForm', function(element, id) {
							$('#modalLoad').modal('hide');
							$.get(tableOps.rawUrl + '&part=1&singleRow=' + id, function(data) {
								var $tRow = $('tr#' + id);
								if ($tRow.length > 0) {
									oTable.row($tRow).remove();
									oTable.row.add($(data));
								}
								oTable.draw();
							});
						});

					};

					var editItemActions = function() {

						/*$('[data-toggle="tab"]').on('shown.bs.tab', function (e) {
						  $('.matchCols').matchHeight();
						}).on('click',function(){
							$(this).tab('show');
							if($(this).closest('li').hasClass('active')){
								$(this).tab('hide');
							}else{
								$(this).tab('show');
							}
						});*/

						masksCurrency($('.maskInteger'), thousandSeparator, 'no');
						masksCurrency($('.maskFloat'), thousandSeparator, 'yes');
						masksCurrency($('.maskFloat3'), thousandSeparator, 'yes', false, '3');
						masksCurrency($('.maskCurrency'), thousandSeparator, decimal);

						ncmHelpers.onClickWrap('a.tabs', function(e, tis) {
							var tab = tis.closest('li');
							var target = tis.attr('href');
							var allBodies = $('.tab-pane');
							var allTabs = tis.closest('.nav-tabs').find('li');

							allTabs.removeClass('active');
							allBodies.removeClass('active').hide();

							masksCurrency($('.maskInteger'), thousandSeparator, 'no');
							masksCurrency($('.maskFloat'), thousandSeparator, 'yes');
							masksCurrency($('.maskFloat3'), thousandSeparator, 'yes', false, '3');
							masksCurrency($('.maskCurrency'), thousandSeparator, decimal);

							tab.addClass('active');
							$(target).addClass('active').show();
							$('.matchCols').matchHeight();

							ncmUI.setDarkMode.autoSelected();
						});


						$('#insertItemName').off('keyup').on('keyup', function(e) {
							var name = $(this).val();
							var firstLetter = name.charAt(0);
							var secondLetter = name.charAt(1);
							var construct = '<span class="text-u-c">' + firstLetter + '</span>' + secondLetter;
							$('.itemName').html(name);
							$('#imgThumbLetters').html(construct);
						});

						ncmHelpers.onClickWrap('.comissionTypeBtn', function(event, tis) {
							var symbol = tis.data('symbol');
							$('.comissionTypeBtn').removeClass('active');
							tis.addClass('active');
							$('.comissionType').val(symbol);
							$('#comissionType b').text(symbol);

							if (symbol == '%') {
								$('#itemComission').removeClass('maskCurrency').addClass('maskInteger');
							} else {
								$('#itemComission').removeClass('maskInteger').addClass('maskCurrency');
							}

							masksCurrency($('.maskCurrency'), thousandSeparator, decimal);
							masksCurrency($('.maskInteger'), thousandSeparator, 'no');
						});

						ncmHelpers.onClickWrap('.priceTypeBtn', function(event, tis) {
							var symbol = tis.data('symbol');
							$('.priceTypeBtn').removeClass('active');
							tis.addClass('active');
							$('.priceType').val(symbol);
							$('#priceType b').text(symbol);

							if (symbol == '%') {
								$('#itemPricePercent').removeClass('disabled').attr('disabled', false).focus();
							} else {
								$('#itemPricePercent').addClass('disabled').attr('disabled', 'disabled').val(0);
							}

							masksCurrency($('.maskPercentInt'), thousandSeparator, 'no');
						});

						ncmHelpers.onClickWrap('#btnAddStock', function(event, tis) {
							$('.addRemoveStockBlocks').addClass('hidden');
							$('#addStock').removeClass('hidden');
						});

						ncmHelpers.onClickWrap('#btnRemoveStock', function(event, tis) {
							$('.addRemoveStockBlocks').addClass('hidden');
							$('#removeStock').removeClass('hidden');
						});

						ncmHelpers.onClickWrap('#btnAddStockSubmit', function(event, tis) {
							var count = $('#addStockCount').val();
							var price = $('#addCogsCount').val();
							var url = tis.attr('href');
							url = url + '&count=' + count + '&price=' + price;

							$.get(url, function(result) {
								if (result == 'true') {
									$('.addRemoveStockBlocks').addClass('hidden');
									$('#successStock').removeClass('hidden');
									setTimeout(function() {
										$('#successStock').addClass('hidden');
										$('#editItem').submit();
									}, 2000);
								}
							});
						});

						ncmHelpers.onClickWrap('#btnRemoveStockSubmit', function(event, tis) {
							var count = $('#removeStockCount').val();
							var url = tis.attr('href');
							url = url + '&count=' + count;

							$.get(url, function(result) {
								if (result == 'true') {
									$('.addRemoveStockBlocks').addClass('hidden');
									$('#successStock').removeClass('hidden');
									setTimeout(function() {
										$('#successStock').addClass('hidden');
										$('#editItem').submit();
									}, 2000);
								}
							});
						});

						ncmHelpers.onClickWrap('.cancelItemView', function(event, tis) {
							$('.modal').modal('hide');
						});

						switchit(false, true);

						ncmHelpers.onClickWrap('.toggleInventory', function(event, tis) {
							var classis = tis.data('inv');
							$(classis).toggleClass('hidden');
						});

						ncmHelpers.onClickWrap('#comboType', function() {
							spinner('body', 'show');
							$('#editItem').prepend('<input type="hidden" value="1" name="resetCombo">').submit();
						});

						ncmHelpers.onClickWrap('.maskCurrency', function(event, tis) {
							tis.select();
						});

						ncmHelpers.onClickWrap('#productionBtn,#productionOrderBtn', function(event, tis) {

							var units = $('#productionUnits').val();
							var itemName = tis.data('name');
							var outletName = tis.data('outletname');
							var id = tis.data('id');
							var max = tis.data('max');
							var isOrder = tis.data('order');
							var cogs = countPricesFromCompound();
							var expiration = $('#productionExpirationDate').val();

							if (units < 1 || isNaN(units)) {
								ncmDialogs.alert('Indique la cantidad que desea producir');
								return false;
							} else if (units > max) {
								ncmDialogs.alert('Puede producir ' + max + ' unidades como máximo');
								return false;
							} else {
								var alrt = 'Se producirán ' + units + ' ' + itemName + ' en la sucursal ' + outletName;
								if (isOrder) {
									alrt = '¿Desea ordenar ' + units + ' ' + itemName + ' en la sucursal ' + outletName + '?';
								}
								confirmation(alrt, function(e) {
									if (e) {
										spinner('body', 'show');
										$.get(baseUrl + '?action=produce&i=' + id + '&c=' + units + '&cogs=' + cogs + '&ex=' + expiration + '&ord=' + isOrder, function(result) {
											if (result == 'limit') {
												ncmDialogs.alert('Error: El producto puede tener un máximo de 30 compuestos');
											} else if (result == 'noinventory') {
												ncmDialogs.alert('Error: No hay suficientes compuestos para producir ' + units + ' unidades');
											} else if (result == 'true') {
												ncmDialogs.alert(units + ' ' + itemName + ' producidos exitosamente');
											} else if (result == 'nooutlet') {
												ncmDialogs.alert('Debe seleccionar una sucursal donde se realizará la producción');
											} else if (result.length > 255) {
												$(result).print();
												console.log(result);
											} else {
												ncmDialogs.alert(result);
											}
											spinner('body', 'hide');
										});
									}
								});
							}
						});

						function countPricesFromCompound() {
							var cogs = '';
							$('select.compoundSelect').each(function() {
								cogs += $(this).data('price');
							});
							return cogs;
						}

						select2Simple($(".search,.searchSimple"));
						$('.matchCols').matchHeight();
					};


					//IMAGE UPLOAD DESKTOP AND MOBILE


					/*switchit(function(tis,active){
						var itemId = tis.attr('data-itemId');
						if(itemId){
							if(active){
								$.get('?action=createinventory&i='+itemId+'&s=1',function(data){
									if(data == 'true'){
										$('.inventoryBtn').show();
									}
								});
							}else{
								$.get('?action=createinventory&i='+itemId+'&s=0',function(data){
									if(data == 'true'){
										$('.inventoryBtn').hide();
									}
								});
							}
						}
					});*/


					//Filter rows
					ncmHelpers.onClickWrap('#filterRows', function(event, tis) {
						var type = tis.data('type');

						if (type == 'filter') {
							tis.attr('data-type', 'reset');
							var filt = tis.attr('data-filter');

							$('.tableContainer tbody tr').hide();

							$('*[data-to-filter="' + filt + '"]').show();


							tis.text('Ver todos');

							/* $.fn.dataTable.ext.search.push(
							   function(settings, data, dataIndex) {
							       return $(table.row(dataIndex).node()).attr('data-to-filter') == filt;
							     }
							 );*/
							//table.draw();

						} else {
							tis.attr('data-type', 'filter');
							$('.tableContainer tbody tr').show();
							tis.text('Ver disponíbles en esta sucursal');

							//$.fn.dataTable.ext.search.pop();
						}
					});

					masksCurrency($('.maskPercent'), thousandSeparator, 'yes', false, '3');
					masksCurrency($('.maskPercentInt'), thousandSeparator, 'no');
					masksCurrency($('.maskCurrency'), thousandSeparator, decimal);

					//$('.maskNum').mask('T000.000.000.000.000,00', { reverse: true, 'translation':{ T: { pattern: /[-]/, optional: true } } });

					ncmHelpers.onClickWrap('#checkAll', function(event, tis) {
						if (tis.hasClass('selected')) {
							$('.table tbody .check, .table tbody tr').removeClass('selected');
						} else {
							$('.table tbody .check, .table tbody tr').addClass('selected');
						}

					});

					ncmHelpers.onClickWrap('.inventoryBtn', function(event, tis) {
						$('#modalLarge').modal('hide');

						var url = tis.attr('href');
						loadForm(url, '#modalLoad .modal-content', function() {
							$('#modalLoad').modal('show');
						});

						$('#modalLoad').one('hidden.bs.modal', function() {
							$('#modalLarge').modal('show');
						});
					});

					ncmHelpers.onClickWrap('#bulkUpload', function(event, tis) {
						var url = tis.attr('href');
						loadForm(url, '#modalSmall .modal-content', function() {
							$('#modalSmall').modal('show');
						});
					});

					ncmHelpers.onClickWrap('.singleBarcode', function(event, tis) {
						var id = tis.data('id');
						prompter("Ingrese la cantidad de códigos a imprimir", function(cant) {
							if (cant) {
								window.open('/barcode?ids=' + id + '-' + cant);
							}
						});
					});



					$('#modalLarge, #modalSmall').off('shown.bs.modal show.bs.modal hidden.bs.modal shown.bs.tab ncmModalUpdate').on('shown.bs.modal show.bs.modal shown.bs.tab ncmModalUpdate', function() {

						ncmUI.setDarkMode.autoSelected();

						var rawHash = window.location.hash.substring(1);
						var jHash = rawHash.split('&').reduce(function(result, item) {
							var parts = item.split('=');
							result[parts[0]] = parts[1];
							return result;
						}, {});

						if (jHash['i']) {
							var opts = {
								"listEl": '#ncmDBItemFilesTab',
								"token": ncmDBActive,
								'folder': '/item/' + jHash['i']
							};

							if (ncmDBActive) {
								ncmDropbox(opts);
							}
						}

						$('#comboSelector').off('change').on('change', function() {
							spinner('body', 'show');
							$('#editItem').prepend('<input type="hidden" value="1" name="resetCombo">').submit();
						});

						$('#productionType').off('change').on('change', function() {
							spinner('body', 'show');
							$('#editItem').submit();
						});

						ncmHelpers.onClickWrap('.print', function(event, tis) {
							var id = tis.data('type');
							$(id).print();
						});

						ncmHelpers.onClickWrap('a.itemImageBtn', function(event, tis) {
							$('#itemImageInput').trigger('click');
						});

						$(document).off('change', '#itemImageInput').on('change', '#itemImageInput', function(e) {
							var file = e.target.files[0]; //this.files[0];
							var reader = new FileReader();
							var name = file.name;
							var size = file.size;
							var type = file.type;
							var width = file.width;
							var height = file.height;
							var go = false;
							var $this = $(this);

							if (size > 900000 || !type || (type != 'image/jpeg' && type != 'image/png' && type != 'image/gif')) {
								alert('La imagen debe ser JPG, PNG o GIF y debe de pesar menos de 900KB');
								return false;
							} else {
								$('.itemImgFlag').val(1);
								reader.onloadend = function() {
									$('.itemImageBtn img.itemImg').attr('src', reader.result);
								};

								reader.onerror = function() {
									alert('No se pudo leer la imagen');
								}

								if (file) {
									reader.readAsDataURL(file);
								} else {
									alert('No se pudo seleccionar la imagen');
								}
							}
						});

						ncmHelpers.onClickWrap('#deleteImgBtn', function(event, tis) {
							var id = tis.data('id');
							$.get('upload.php?action=delete&id=' + companyId + '_' + id, function(res) {
								$('.itemImg').attr('src', 'images/transparent.png');
								$('.item-overlay').addClass('bg-light dk active').removeClass('opacity');
								$('#itemImgFlag').val('false');
							});
						});

						var $sSimpleEl = $('.search,.searchSimple');
						select2Simple($sSimpleEl, $('#modalLarge'));
						select2Ajax({
							element: '.searchAjax',
							url: baseUrl + '?action=searchItemInputJson',
							type: 'item',
							onLoad: function(el, container) {},
							onChange: function($el, data) {}
						});

						$('[data-toggle="tooltip"]').tooltip();
						$("[data-toggle=popover]").popover().on('show.bs.popover', function() {
							masksCurrency($('.maskCurrency'), thousandSeparator, decimal);
						});
						masksCurrency($('.maskInteger'), thousandSeparator, 'no');
						masksCurrency($('.maskFloat'), thousandSeparator, 'yes');
						masksCurrency($('.maskFloat3'), thousandSeparator, 'yes', false, '3');
						masksCurrency($('.maskCurrency'), thousandSeparator, decimal);

						masksCurrency($('.maskPercent'), thousandSeparator, 'yes', false, '3');

						addRemoveTextBox('#addCompound', '#rmCompound, .rmCompound', '#compoundHolder', (window.compBoxesList) ? window.compBoxesList : '', function() {
							masksCurrency($('.maskFloat3'), thousandSeparator, 'yes', false, '3');
							masksCurrency($('.maskFloat'), thousandSeparator, 'yes');
							masksCurrency($('.maskCurrency'), thousandSeparator, decimal);

							select2Simple($(".search,.searchSimple"), $('#modalLarge'));

							select2Ajax({
								element: '.searchAjax',
								url: baseUrl + '?action=searchItemInputJson',
								type: 'item',
								onLoad: function(el, container) {},
								onChange: function($el, data) {
									var uom = data;
									var $uomPlace = $el.closest('div.TextBoxDiv').children('div.col-sm-2').find('span.badge');

									if ($uomPlace.length > 0 && validity(uom.uom)) {
										$uomPlace.text(uom.uom);
									}
								}
							});
							$('.matchCols').matchHeight();

						});

						ncmHelpers.onClickWrap('a.setCurrenciesBtn', function(event, tis) {
							var id = tis.data('id');
							ncmHelpers.setCurrency(baseUrl, id);
						});

						var BHID = '#dateHoursTab';
						var currBH = $.trim($('#dateHoursTab .businessHoursConfig').text());
						if (currBH) {
							currBH = JSON.parse(currBH);
							var bHours = $('#dateHoursTab div.businessHours').businessHours({
								checkedColorClass: 'bg-info lt',
								uncheckedColorClass: 'bg-danger lt',
								operationTime: currBH,
								weekdays: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
								postInit: function() {

								},
								dayTmpl: '<div class="dayContainer col-md-3 col-xs-4 m-b wrapper-sm" style="min-height:134px;">' +
									' <div class="weekday font-bold text-u-c"></div>' +
									' <div data-original-title="" class="colorBox m-b-xs r-3x pointer"><input type="checkbox" class="invisible operationState"></div>' +
									' <div class="operationDayTimeContainer">' +
									'   <div class="operationTime input-group m-b-xs">' +

									'     <input type="time" name="startTime" class="mini-time form-control operationTimeFrom">' +
									'   </div>' +
									'   <div class="operationTime input-group">' +

									'     <input type="time" name="endTime" class="mini-time form-control operationTimeTill">' +
									'   </div>' +
									' </div>' +
									'</div>'
							});


							ncmHelpers.onClickWrap('#dateHoursTab div.businessHours .colorBox', function(event, tis) {
								$('#dateHoursTab input.businessHours').val(JSON.stringify(bHours.serialize()));
							});

							$('#dateHoursTab input.operationTimeFrom, #dateHoursTab input.operationTimeTill').off('change').on('change', function() {
								$('#dateHoursTab input.businessHours').val(JSON.stringify(bHours.serialize()));
							});

						}

					}).on('hidden.bs.modal', function() {
						if (window.location.hash.indexOf("#" + window.baseUrlH) > -1) {
							helpers.loadPageLoad = false;
							window.location.hash = window.baseUrlH;
							setTimeout(function() {
								helpers.loadPageLoad = true;
							}, 100);
						}
					}).on('show.bs.modal', function() {

							function countPricesFromCompound() {
								var cogs = '';
								$('select.compoundSelect').each(function() {
									cogs += $(this).data('price');
								});
								return cogs;
							}

							select2Simple($(".search,.searchSimple"));
							$('.matchCols').matchHeight();
						};


						//IMAGE UPLOAD DESKTOP AND MOBILE


						/*switchit(function(tis,active){
							var itemId = tis.attr('data-itemId');
							if(itemId){
								if(active){
									$.get('?action=createinventory&i='+itemId+'&s=1',function(data){
										if(data == 'true'){
											$('.inventoryBtn').show();
										}
									});
								}else{
									$.get('?action=createinventory&i='+itemId+'&s=0',function(data){
										if(data == 'true'){
											$('.inventoryBtn').hide();
										}
									});
								}
							}
						});*/


						//Filter rows
						ncmHelpers.onClickWrap('#filterRows', function(event, tis) {
							var type = tis.data('type');

							if (type == 'filter') {
								tis.attr('data-type', 'reset');
								var filt = tis.attr('data-filter');

								$('.tableContainer tbody tr').hide();

								$('*[data-to-filter="' + filt + '"]').show();


								tis.text('Ver todos');

								/* $.fn.dataTable.ext.search.push(
								   function(settings, data, dataIndex) {
								       return $(table.row(dataIndex).node()).attr('data-to-filter') == filt;
								     }
								 );*/
								//table.draw();

							} else {
								tis.attr('data-type', 'filter');
								$('.tableContainer tbody tr').show();
								tis.text('Ver disponíbles en esta sucursal');

								//$.fn.dataTable.ext.search.pop();
							}
						});

						masksCurrency($('.maskPercent'), thousandSeparator, 'yes', false, '3'); masksCurrency($('.maskPercentInt'), thousandSeparator, 'no'); masksCurrency($('.maskCurrency'), thousandSeparator, decimal);

						//$('.maskNum').mask('T000.000.000.000.000,00', { reverse: true, 'translation':{ T: { pattern: /[-]/, optional: true } } });

						ncmHelpers.onClickWrap('#checkAll', function(event, tis) {
							if (tis.hasClass('selected')) {
								$('.table tbody .check, .table tbody tr').removeClass('selected');
							} else {
								$('.table tbody .check, .table tbody tr').addClass('selected');
							}

						});

						$('#tipo').off('focus change').on('focus', function() {
							prev_val = $(this).val();
						}).on('change', function() {
							$(this).blur(); // Firefox fix as suggested by AgDude
							var optionSelected = $("option:selected", this);
							var valueSelected = this.value;
							var itemId = $(this).data('itemid');

							if (valueSelected == 1 || valueSelected == 2) {
								$('.inventoryTools').removeClass('hidden');
							} else {
								if (prev_val == 1 || prev_val == 2) {
									var success = confirm('Se eliminará todo el inventario de este artículo. ¿Desea continuar?');
									if (success) {
										$('.inventoryTools').addClass('hidden');
										//aqui llamo a un script para eliminar el inventario
										$.get(baseUrl + '?action=clearSingleInventory&id=' + itemId);
									} else {
										$(this).val(prev_val);
										return false;
									}
								}
							}
						});

					}); <?php
						$script = ob_gets_contents();
						minifyJS([$script => 'scripts' . $baseUrl . '.js']);
					}
						?>

			function updateDate() {

				var fechaActual = new Date();
				var opcionesFecha = {
					year: 'numeric',
					month: '2-digit',
					day: '2-digit'
				};
				var fechaFormateada = fechaActual.toLocaleDateString('en-CA', opcionesFecha).split('/').join('-');
				$('#itemComission').val(fechaFormateada);
			}


			var count = 0;

			function removeTextBoxDiv(element, taskId = null) {
				count = parseInt($("#countFor").val());
				var textBoxDiv = element.closest('.TextBox');
				if (taskId === null) {
					// Si taskId es nulo, elimina el elemento visualmente directamente
					textBoxDiv.remove();
					$("#countFor").val(count - 1);
				} else {
					// Si taskId no es nulo, realiza una solicitud AJAX para eliminar la tarea en el servidor
					var requestData = {
						"api_key": "<?= API_KEY ?>",
						"company_id": "<?= enc(COMPANY_ID) ?>",
						"ID": taskId
					};
					$.ajax({
						url: "<?= API_URL . '/delete_task' ?>", // Cambia la ruta según tu configuración
						type: 'POST',
						data: requestData, // Enviar el taskId al servidor
						success: function(response) {
							if (response.success) {
								// La tarea se eliminó con éxito en el servidor
								// Puedes realizar acciones adicionales si es necesario
								textBoxDiv.remove(); // Elimina visualmente después de eliminar en el servidor
								$("#countFor").val(count - 1);
							} else {
								// Maneja el caso en el que la eliminación falla, muestra un mensaje de error, etc.
								alert('Error al eliminar la tarea');
							}
						},
						error: function() {
							// Maneja errores de conexión o solicitud
							alert('Error de conexión');
						}
					});
				}
			}

			function addLineLote() {
				var count = parseInt($("#countFor").val());
				var line = '<div class="TextBox row">';
				line += '	<div class="col-sm-12 col-lg-6 wrapper-xs">';
				line += '		<div class="input-group">';
				line += "			<input type='date' class='form-control no-border  no-bg b-b text-left' placeholder='0' name='data[" + count + "][vencimiento]' id='itemVencimiento' value=' autocomplete='off'>";
				line += '		</div>';
				line += '	</div>';
				line += '	<div class="col-sm-12 col-lg-4 wrapper-xs">';
				line += '		<div class="input-group">';
				line += "			<input type='text' class='form-control no-border no-bg b-b text-left' placeholder='Descripcion' name='data[" + count + "][lote]' id='itemLote' value='' autocomplete='off'>";
				line += '		</div>';
				line += '	</div>';
				line += '	<div class="col-sm-2 wrapper-xs uom font-bold m-t-sm text-right">';
				line += '		<span class="badge m-r"></span>';
				line += '		<a href="#" class="rmCompound"  onclick="removeTextBoxDiv(this)" data-index="">';
				line += '			<span class="text-danger m-r-xs material-icons">close</span>';
				line += '		</a>';
				line += '	</div>';
				line += '</div>';
				$("#lineLote").append(line);
				count = count + 1;
				$("#countFor").val(parseInt(count));
			}
</script>
<script src="scripts<?= $baseUrl ?>.js?<?= date('d.i') . rand() ?>"></script>

<?php
include_once('includes/compression_end.php');
dai();
?>