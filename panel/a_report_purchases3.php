<?php
include_once('includes/top_includes.php');
topHook();

if(!allowUser('expenses','edit',true) && !allowUser('expenses','delete',true)){
	allowUser('expenses','view');
}

$MAX_DAYS_RANGE 	= 170;

$baseUrl 			= '/' . basename(__FILE__,'.php');

$roc 				= getROC(1);
$saletype 			= '1';
$getAllTaxNames 	= getAllTax();
//var_dump($getAllTaxNames);

$limitDetail		= 100;
$offsetDetail		= 0;
$transArr 			= [];

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

if(validateHttp('action') == 'update' && validateHttp('id','post')){
	if(!allowUser('expenses','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	theErrorHandler('json');
	$trsTotal 	= 0;
	$trsTax 	= 0;
	$trsQty 	= 0;

	if(validateHttp('itemTrsId','post')){
		foreach (validateHttp('itemTrsId','post') as $itmSID) {
			$itmQty 					= formatNumberToInsertDB($_POST['itemQty'][$itmSID],true,2);
			$item 						= $_POST['itemNew'][$itmSID];
			$itemType					= $_POST['itemNewType'][$itmSID];
			$itemPrice 					= formatNumberToInsertDB($_POST['itemPrice'][$itmSID]);

			$record 					= [];
			if($itemType == 'expense'){
				$record['itemSoldDescription']	= $item;
				$tax 							= formatNumberToInsertDB($_POST['itemTax'][$itmSID]);
				$record['itemId']				= NULL;
			}else{
				$itemData 						= ncmExecute('SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[dec($item),COMPANY_ID]);
				$tax 							= getTaxOfPrice(getTaxValue($itemData['taxId']),$itemPrice);
				//$tax 							= $taxVal[1];
				$record['itemId']				= dec($item);
			}
			
			$record['itemSoldUnits']	= $itmQty;
			$record['itemSoldTotal']	= $itemPrice - $record['itemSoldDiscount'];
			$record['itemSoldTax']		= $tax;

			$trsTotal 	+= $itemPrice;
			$trsQty 	+= $itmQty;
			$trsTax 	+= $taxVal[1];

			$itmUpdt = $db->AutoExecute('itemSold', $record, 'UPDATE', 'itemSoldId = ' . dec($itmSID) );

			$ops['itemId']    = dec($item);
			$ops['outletId']  = dec($_POST['outlet']);
			$ops['cogs']      = divider($itemPrice,$itmQty,true);
			$ops['count']     = 0;

			$manage 		  = manageStock($ops);
		}
	}

	$record = [];

	$discount 							= validateHttp('discount','post') ? formatNumberToInsertDB(validateHttp('discount','post')) : 0;

	if($discount){
		$record['transactionDiscount'] 	= $discount;
	}

	if($trsTotal){
		$record['transactionTotal'] 	= $trsTotal - $discount;
	}

	if($trsTax){
		$record['transactionTax'] 		= $trsTax;
	}

	if($trsQty){
		$record['transactionUnitsSold'] = $trsQty;
	}

	$trsId 							= dec( validateHttp('id','post') );

	if( in_array( validateHttp('saleType','post'), [1,4]) ){
		$trType = 1;

		if(validateHttp('saleType','post') == 1){//contado
			$record['transactionComplete'] = 1;
			$db->Execute('DELETE FROM transaction WHERE transactionParentId = ? AND transactionType = 5 AND companyId = ?',[$trsId,COMPANY_ID]);
			$payment[] = [ "type" => $_POST['paymentMethod'][enc($trsId)], "price" => formatNumberToInsertDB($_POST['paymentTotal'][enc($trsId)]) ];
			$record['transactionPaymentType'] = json_encode($payment);
		}else{//credito
			$trType = 4;
			$record['transactionComplete'] 		= 0;
			$record['transactionPaymentType'] 	= NULL;
		}

		$record['transactionType'] 		= $trType;
	}
	
	if(validateHttp('note','post')){
		$record['transactionNote'] 		= validateHttp('note','post');
	}

	if(validateHttp('date','post')){
		$record['transactionDate'] 		= validateHttp('date','post');
	}

	$record['transactionDueDate']	= validateHttp('duedate','post');
	$record['invoiceNo'] 			= validateHttp('invoiceNo','post');
	$record['invoicePrefix']		= validateHttp('authNo','post') . ';' . validateHttp('invoicePrefix','post');
	$record['transactionStatus'] 	= validateHttp('salestatus','post');
	$record['customerId'] 			= dec( validateHttp('customer','post') );
	$record['supplierId'] 			= dec( validateHttp('supplier','post') );
	$record['userId'] 				= USER_ID;

	if(validateHttp('outlet','post')){
		$record['outletId'] 			= dec(validateHttp('outlet','post'));
	}
	
	$record['categoryTransId'] 		= dec(validateHttp('transactionCategory','post'));	

	$update = $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ' . $trsId); 

	if($update === false){
		echo 'false|0|' . validateHttp('id','post');
	}else{
		updateLastTimeEdit();
		echo 'true|0|' . validateHttp('id','post');
	}

	dai();
}

if(validateHttp('action') == 'delete' && validateHttp('id')){
	if(!allowUser('sales','delete',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$id 	= dec(validateHttp('id'));

	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'id' 			=> validateHttp('id'),
			    'parent' 		=> validateHttp('parent')
			  ];

	$result = curlContents('https://api.encom.app/delete_transaction','POST',$data);
	
	header('Content-Type: application/json'); 
	dai($result);
}

if(validateHttp('action') == 'edit' && validateHttp('id')){
	$id 		= validateHttp('id');
	
	$result 	= ncmExecute("SELECT * FROM transaction WHERE transactionId = ? LIMIT 1",[dec($id)]);
	$tDate 		= $result['transactionDate'];
	$tUser 		= $result['userId'];
	$tSupplier 	= $result['supplierId'];
	$tCustomer 	= $result['customerId'];
	$tOutlet 	= $result['outletId'];
	$tPayment 	= $result['transactionPaymentType'];
	$tNote 		= $result['transactionNote'];
	$tSaleType 	= $result['transactionType'];
	$tComplete 	= $result['transactionComplete'];
	$tStatus 	= $result['transactionStatus'];
	//$tDetails 	= json_decode($result['transactionDetails'],true);
	$tDue 		= $result['transactionDueDate'];
	$tags 		= json_decode($result['tags']);
	$disabledRO = validateHttp('ro') ? 'disabled' : '';

	if($tSaleType == '1'){
		$saleType = '';
	}else if($tSaleType == '4'){
		$creditStatus = ($tComplete)?'success':'danger';
		$saleType = '<span class="label bg-' . $creditStatus . ' lter">Crédito</span>';
	}

	if($tStatus == 0){
		$isOrder = true;
		$docName = 'Orden de Compra';
	}else if($tStatus == 3){
		$isOrder = true;
		$docName = 'Pedido';
	}else{
		$isOrder = false;
		$docName = 'Compra o Gasto';
	}
    ?>

    <div class="modal-body no-padder clear r-24x" id="factura">
    	<?=headerPrint(['noOutlet'=>true,'text'=>$docName]);?>
	    <form action="<?=$baseUrl?>?action=update" method="POST" id="editSale" name="editSale">
	    	<div class="row equal padder">
	    		<div class="col-md-4 col-xs-12 wrapper bg-info gradBgBlue hidden-print">
	    			<a href="#" class="cancelItemView col-xs-12 visible-xs no-padder m-b text-right">
    					<span class="material-icons">close</span>
    				</a>

	    			<div class="col-xs-12 m-b text-white no-padder <?=($isOrder) ? 'hidden' : ''?>">
	    				<label class="font-bold text-u-c text-xs">Tipo</label>
	    				<?php 
	    				if(isInvoiceEditable() && !$isOrder){
	    				?>
    					<?=selectInputGenerator([1=>'CONTADO',4=>'CRÉDITO'],['match'=>$tSaleType,'class'=>'no-bg no-border b-b text-white font-bold','name'=>'saleType']);?>
    					<?php
    					}else{
    						$arr = [1=>'CONTADO',4=>'CRÉDITO'];
    						echo '<div>';
	    					echo $isOrder ? $docName : $arr[$tSaleType];
	    					echo '</div>';
    					}
    					?>
    				</div>
	    			<div class="col-xs-12 no-padder m-b">

	    				<?php 
	    				if(isInvoiceEditable()){
	    					$authNo = '';
	    					$prefix = $result['invoicePrefix'];

	    					if(strpos($result['invoicePrefix'], ';') !== false){
		    					$authPrefix = explodes(';', $result['invoicePrefix']);
		    					$authNo		=	$authPrefix[0];
		    					$prefix		=	$authPrefix[1];
		    				}
	    				?>
	    				<div class="col-xs-12 no-padder m-b">
				            <label class="font-bold text-u-c text-xs">Timbrado o Autorización</label>
				            <input type="text" class="form-control text-white no-border no-bg b-b <?=$disabledRO;?>" name="authNo" value="<?=$authNo?>" autocomplete="off" placeholder="Timbrado o Autorización" style="background: transparent;" <?=$disabledRO;?>/>
				        </div>
		    			<div class="col-xs-6 no-padder">
				            <label class="font-bold text-u-c text-xs">&nbsp;</label>
				            <input type="text" class="form-control text-white no-border no-bg b-b <?=$disabledRO;?>" name="invoicePrefix" value="<?=$prefix?>" autocomplete="off" placeholder="Prefijo" style="background: transparent;" <?=$disabledRO;?>/>
				        </div>
				        <div class="col-xs-6 no-padder">
				        	<label class="font-bold text-u-c text-xs"># Documento</label>
				            <input type="number" min="0" step="1" class="form-control text-white no-bg text-right no-border b-b <?=$disabledRO;?>" name="invoiceNo" value="<?=$result['invoiceNo']?>" autocomplete="off" placeholder="Número" style="background: transparent;" <?=$disabledRO;?> />
				        </div>
				        <?php
    					}else{
    					?>
    						<div class="col-xs-12 no-padder">
	    						<label class="font-bold text-u-c text-xs"># Documento</label>
		    					<div><?=$result['invoicePrefix'] . ' ' . $result['invoiceNo'];?></div>
	    					</div>
	    				<?php
    					}
    					?>
				    </div>
			        <div class="col-xs-12 no-padder m-b">
				        <label class="font-bold text-u-c text-xs">Proveedor</label>
				        <?php 
				        if(validity($tSupplier)){
							$supData = ncmExecute('SELECT contactName,contactTIN FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',[$tSupplier,COMPANY_ID]);
						}

	    				if(isInvoiceEditable()){
	    				?>
			            <select name="supplier" class="form-control supplier-select no-border b-b text-white <?=$disabledRO;?>" style="background: transparent;" <?=$disabledRO;?>>
							<?php
							if(validity($tSupplier)){
							?>
								<option value="<?=enc($tSupplier)?>" selected><?=$supData['contactName'] . ( iftn($supData['contactTIN'],'',' (' . $supData['contactTIN'] . ')') );?></option>
							<?php
							}
							?>
						</select>
						<?php 
	    				}else{
	    					?>
	    					<div>
	    					<?=$supData['contactName'] . ( iftn($supData['contactTIN'],'',' (' . $supData['contactTIN'] . ')') );?>
	    					</div>
	    					<?php 
	    				}
	    				?>
					</div>

					<div class="col-xs-12 no-padder m-b">
						<label class="font-bold text-u-c text-xs">Fecha</label>
						<?php 
	    				if(isInvoiceEditable()){
	    				?>
						<div class="text-default">
			            	<input type="text" class="form-control text-white no-border b-b no-bg datetimepicker pointer text-center" name="date" value="<?=$tDate?>" style="background: transparent;" autocomplete="off" />
			            </div>
			            <?php 
	    				}else{
	    					echo '<div>' . niceDate($tDate,true) . '</div>';
	    				}
	    				?>
			        </div>

		            <?php
		            if($tSaleType != '1'){
		            ?>
		            <div class="col-xs-12 no-padder m-b <?=($isOrder) ? 'hidden' : ''?>">
			            <label class="font-bold text-u-c text-xs">Vencimiento</label>
			            <?php 
	    				if(isInvoiceEditable()){
	    				?>
			            <div class="text-default">
				            <input type="text" class="form-control text-white no-border no-bg b-b datetimepicker pointer text-center" name="duedate" value="<?=$tDue?>" style="background: transparent;" autocomplete="off" />
				        </div>
				        <?php 
	    				}else{
	    					echo '<div>' . niceDate($tDue) . '</div>';
	    				}
	    				?>
			    	</div>
					<?php
					}
					?>

					<input type="hidden" name="salestatus" value="1">
					
				    <div class="col-xs-12 no-padder m-b">
						<label class="font-bold text-u-c text-xs">Sucursal</label>
						<?php 
	    				if(isInvoiceEditable()){
	    				?>
			            <?php
					      echo selectInputOutlet($tOutlet,false,'m-b no-border b-b text-white chosen-select ' . $disabledRO);
				        ?>
				        <?php 
	    				}else{
	    					echo '<div>' . getCurrentOutletName($tOutlet) . '</div>';
	    				}
	    				?>
					</div>


					<div class="col-xs-12 no-padder m-b">
						<label class="font-bold text-u-c text-xs">Usuario</label>
						<?php 
	    				if(isInvoiceEditable()){
	    				?>
				        <?=selectInputUser($tUser,false,'m-b no-border b-b text-white chosen-select ' . $disabledRO)?>
				        <?php 
	    				}else{
	    					$userName 		= ncmExecute('SELECT contactName FROM contact WHERE contactId = ? LIMIT 1',[$tUser],true);
	    					$userName 		= iftn($userName['contactName'],'Sin Usuario');
	    					echo '<div>' . $userName . '</div>';
	    				}
	    				?>
					</div>

					<div class="col-xs-12 no-padder m-b <?=($isOrder) ? 'hidden' : ''?>">
						<label class="font-bold text-u-c text-xs">Categoría</label>
						<?php 
	    				if(isInvoiceEditable()){
	    				?>
                    	<?php 
                    		selectInputTaxonomy('transactionCategory',$result['categoryTransId'],false,'m-b chosen-select text-white no-border','taxonomyName ASC',true);
	    				}else{
	    					echo '<div>' . getTaxonomyName($result['categoryTransId']) . '</div>';
	    				}
	    				?>
					</div>

					<?php 
    				if(isInvoiceEditable()){
    				?>
					<div class="col-xs-12 no-padder m-b <?=($isOrder) ? 'hidden' : ''?>">
						<label class="font-bold text-u-c text-xs">Descuento</label>
                    	<input type="text" name="discount" value="<?=formatCurrentNumber($result['transactionDiscount'])?>" class="form-control no-border b-b no-bg text-white maskCurrency text-right">
					</div>
					<?php 
    				}
    				?>
					<div class="col-xs-12 no-padder">
						<label class="font-bold text-u-c text-xs">Nota</label>
						<?php 
	    				if(isInvoiceEditable()){
	    				?>
		        		<textarea name="note" class="form-control no-border no-bg text-white b-b <?=$disabledRO;?>"  style="background: transparent;" ><?=$tNote?></textarea>
		        		<?php 
	    				}else{
	    					echo '<div>' . unXss($tNote) . '</div>';
	    				}
	    				?>
					</div>
	    		</div>
	    		<div class="col-md-8 col-xs-12 no-padder bg-white">
	    			<div class="col-xs-12 wrapper">
	    				
	    				<div class="col-xs-12 m-b-md visible-print">
	    					<div class="col-sm-4 no-padder">
	    						<label class="font-bold text-u-c text-xs">Tipo</label> 
	    						<?php
	    						$arr = [1=>'CONTADO',4=>'CRÉDITO'];
	    						echo $isOrder ? $docName : $arr[$tSaleType];
	    						?>
	    						<br>
	    						<label class="font-bold text-u-c text-xs"># Documento</label> <?=$result['invoicePrefix']?> <?=$result['invoiceNo']?>
	    						<br>
	    						<label class="font-bold text-u-c text-xs">Fecha</label> <?=$tDate?>
	    					</div>
	    					<div class="col-sm-4 no-padder">
	    						<label class="font-bold text-u-c text-xs">Vencimiento</label> <?=$isOrder ? '-' : $tDue?>
	    						<br>
	    						<label class="font-bold text-u-c text-xs">Proveedor</label> <?=$supData['contactName'] . ( iftn($supData['contactTIN'],'',' (' . $supData['contactTIN'] . ')') );?>
	    						<br>
	    						<label class="font-bold text-u-c text-xs">Sucursal</label> <?=getCurrentOutletName($tOutlet)?>	   
	    					</div>
	    					<div class="col-sm-4 no-padder">
	    						<label class="font-bold text-u-c text-xs">Usuario</label> 
	    						<?php 
	    							$usr = getContactData($tUser);
	    							echo $usr['name'];
	    						?>
	    						<br>
	    						<label class="font-bold text-u-c text-xs">Plan de cuentas</label> 
	    						<?php
	    						$plan = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ?',[$result['categoryTransId'],COMPANY_ID]);
	    						?>
	    						<?=$isOrder ? '-' : unXss($plan['taxonomyName'])?>
	    						<br>
	    						<label class="font-bold text-u-c text-xs">Nota</label>
	    						<?=$tNote?>
	    					</div>
	    				</div>

		    			<div class="col-xs-12 m-b-md no-border panel bg-light lter r-24x table-responsive">
		    				<table class="table" id="modalItemsTable">
				                <thead>
				                    <tr class="text-u-c">
				                        <th class="text-right" style="width:15%">
				                        	<span class="text-u-l" data-toggle="tooltip" title="Para ajustar el stock use las herramientas de ajuste" data-placement="right">Cant.</span>
				                        </th>
				                        <th style="width:50%">Nombre</th>
				                        <th class="text-right" style="width:17%"><?=TAX_NAME?></th>
				                        <th class="text-right" style="width:18%">Total</th>
				                    </tr>
				                </thead>
				                <tbody>
				                    <?php
				                    	if(!$isOrder){
					                        $items 	= ncmExecute("SELECT * FROM itemSold WHERE transactionId = ?",[$result['transactionId']],false,true);

					                        if($items){
						                        while (!$items->EOF) {
						                        	$fields 	= $items->fields;
						                        	$itmSldId 	= enc($fields['itemSoldId']);

						                        	$itemData = ncmExecute('SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[$fields['itemId'],COMPANY_ID]);

						                        	if($itemData || $fields['itemSoldDescription']){
						                        		if($fields['itemSoldDescription']){
							                            	$name 		= $fields['itemSoldDescription'];
							                            	$itmNType 	= 'expense';
							                            }else{
								                            $name 		= iftn($itemData['itemName'],$fields['itemSoldDescription']);
								                            $itmNType 	= 'product';
								                        }
								                        $sku 		= iftn($itemData['itemSKU'],'');
								                        $itemName 	= $itemData['itemName'];
						                        	}else{
						                        		$sku 		= '';
								                        $itemName 	= '<i class="text-muted">Artículo Eliminado</i>';
								                        $itmNType 	= 'product';
						                        	}

					                        		$itmID 		= enc($fields['itemId']);
						                            $units 		= formatCurrentNumber($fields['itemSoldUnits'],'yes');
						                            $tax 		= formatCurrentNumber($fields['itemSoldTax']);
						                            $total 		= formatCurrentNumber($fields['itemSoldTotal']);
							                            
						                    	?>
								                        <tr>
								                            <td class="text-right">
								                            	<input type="hidden" name="itemId[<?=$itmSldId?>]" value="<?=$itmID?>">
								                            	<input type="hidden" name="itemTrsId[]" value="<?=$itmSldId?>">

								                            	<input type="text" name="itemQty[<?=$itmSldId?>]" value="<?=$units?>" class="form-control no-bg no-border b-b maskQty">
								                            </td>
								                            <td>
								                            	<?php
								                            	if($itmNType == 'expense'){
								                            	?>
								                            		<input type="text" name="itemNew[<?=$itmSldId?>]" value="<?=$name?>" class="form-control no-bg no-border b-b">
								                            	<?php
								                            	}else{
								                            	?>
									                            	<select name="itemNew[<?=$itmSldId?>]" class="form-control selectItem" data-id="<?=$encISId?>">
																		<option value="<?=$itmID?>" selected><?=$itemName;?></option>
																	</select>
																	<div class="visible-print m-t-sm"><?=$itemName;?></div>
																<?php
																}
																?>
																<input type="hidden" name="itemNewType[<?=$itmSldId?>]" value="<?=$itmNType?>">
								                            </td>
								                            <td class="text-right">
								                            	<input type="text" name="itemTax[<?=$itmSldId?>]" value="<?=$tax?>" class="form-control no-bg no-border b-b <?=($itmNType == 'expense') ? 'maskCurrency' : 'disabled text-right'?>" <?=($itmNType == 'expense') ? '' : 'disabled'?>>            	
								                            </td>
								                            <td class="text-right">
								                            	<input type="text" name="itemPrice[<?=$itmSldId?>]" value="<?=$total?>" class="form-control no-bg no-border b-b maskCurrency">
								                            </td>
								                        </tr>
							                    <?php
							                    	//}
						                        	$items->MoveNext();
						                    	}
						                    	$items->Close();
						                    }

						                }else{
						                	$details = json_decode($result['transactionDetails'],true);
						                	if(validity($details)){
						                		foreach ($details as $key => $fields) {
						                			//[{"units":"5","itemId":"yWvk","title":"Gasto"}]
						                			$qty 		= formatNumberToInsertDB($fields['qty'],true,3);
						                			$units 		= formatQty($qty);
						                			$tax 		= formatCurrentNumber($fields['tax']);
						                            $total 		= formatCurrentNumber($fields['price']);
						                			$itmNType 	= ($fields['itemId']) ? 'purchase' : 'expense';
						                			if($fields['itemId']){
						                				$itm 			= getItemData(dec($fields['itemId']));
						                				$itemName 		= $itm['itemName'];
						                			}

						                			?>
								                        <tr data-id="<?=dec($fields['itemId'])?>">
								                            <td class="text-right">
								                            	<?=$units?>
								                            </td>
								                            <td>
								                            	<?php
								                            	if($itmNType == 'expense'){
								                            	?>
								                            		<?=$fields['title']?>
								                            	<?php
								                            	}else{
								                            	?>
																	<?=$itemName;?>
																<?php
																}
																?>
								                            </td>
								                            <td class="text-right">
								                            	<?=iftn($tax,'-')?>
								                            </td>
								                            <td class="text-right">
								                            	<?=iftn($total,'-')?>
								                            </td>
								                        </tr>
							                    <?php
						                		}
						                	}
						                }
				                    ?>
				                </tbody>
				                <?php
				                if(!$isOrder){
				                ?>
				                <tfoot class="font-bold text-right">
				                	<tr>
				                		<td colspan="3"><?=TAX_NAME?></td>
				                		<td><?=formatCurrentNumber($result['transactionTax'])?></td>
				                	</tr>

				                	<tr>
				                		<td colspan="3" class="text-u-c">Descuento</td>
				                		<td><?=formatCurrentNumber($result['transactionDiscount'])?></td>
				                	</tr>

				                	<tr class="text-lg">
				                		<td colspan="3" class="text-u-c">Total</td>
				                		<td><?=formatCurrentNumber($result['transactionTotal'])?></td>
				                	</tr>
				                	
				                </tfoot>
				                <?php
				            	}else{
				            	?>
				                <tfoot class="font-bold text-right">
				                	<tr class="text-lg">
				                		<td colspan="3" class="text-u-c">Total</td>
				                		<td><?=formatCurrentNumber($result['transactionTotal'])?></td>
				                	</tr>
				                	
				                </tfoot>
				                <?php
				            	}
				                ?>
				            </table>
				            <div></div>
		    			</div>
		    		</div>
		    		<div class="col-md-12 col-md-offset-0 col-sm-6 col-sm-offset-6 col-xs-12 wrapper text-left pagebreak">
	    				<div class="m-b-md no-border panel bg-light lter r-24x col-xs-12 table-responsive">
	    					<?php
	    					if(!$isOrder){
					        	if($tSaleType == '1'){
									
									if($result['transactionPaymentType']){
										$payment 		= json_decode($result['transactionPaymentType'],true)[0];
										$paymentName 	= getPaymentMethodName($payment['type']);
									}else{
										$payment 		= [];
										$paymentName 	= '';
									}
								?>
									
										<table class="table">
											<tr>
						        				<th colspan="2" class="text-center text-u-c">Métodos de Pago</th>
						        			</tr>
											<tr>
												<td>
													<?php
													echo selectInputPaymentMethods(['match'=>$payment['type'],'class'=>'form-control no-bg no-border b-b','name'=>'paymentMethod','multiple'=>$id]);
													?>
												</td>
												<td class="text-right">
													<input type="text" name="paymentTotal[<?=$id?>]" value="<?=formatCurrentNumber($payment['price'])?>" class="form-control no-bg no-border b-b maskCurrency">													
												</td>
											</tr>
										</table>
									
								<?php
									
								}else{
									?>
									
						        		<table class="table">
						        			<tr>
						        				<th colspan="3" class="text-center text-u-c">Pagos</th>
						        			</tr>
							             <?php 
					                      $credit = ncmExecute('SELECT * FROM transaction WHERE transactionParentId = ? AND '.$SQLcompanyId,[dec($id)],false,true);
					                    ?>
					                      	
				                        <?php 
				                        	if($credit){
					                        	while (!$credit->EOF) {
					                        	$crediDate = explodes(' ',$credit->fields['transactionDate'],true,0);
				                        ?>
					                        <tr>
					                        	<td>
													<?=niceDate($credit->fields['transactionDate'])?>  
												</td>
												<td class="text-right">
													<?=CURRENCY . formatCurrentNumber($credit->fields['transactionTotal'])?>
												</td>
												<td class="text-right">
													<?php
													if(!validateHttp('ro')){
													?>
													<a href="<?=$baseUrl?>?action=delete&outlet=<?=enc($tOutlet)?>&id=<?=enc($credit->fields['transactionId']);?>&type=<?=enc($credit->fields['transactionType'])?>&parent=<?=$id?>" class="hidden-print deleteTransaction" data-payment="<?=enc($credit->fields['transactionId']);?>">
														<i class="material-icons text-danger">close</i>
													</a>
													<?php
													}
													?>
												</td>
					                        </tr>
					                        <?php 
							                        $credit->MoveNext(); 
						                        }
						                        $credit->Close();
						                    }else{
						                    	echo '<tr> <th colspan="3" class="text-center text-u-c text-muted">Sin Pagos</th> </tr>';
						                    }
				                        ?>
				                        </table>
				                    
									<?php
								}
							}
							?>
	    				</div>
	    			</div>

	    			<?php
    				if($_modules['dropbox']){
    				?>
    				<div class="text-center m-b-md no-border panel r-24x col-xs-12 table-responsive" id="DBFiles">
    					
    				</div>

    				<script type="text/javascript">
    					$(document).ready(function(){
    						var opts = {
							  "loadEl" : '#DBfileInput,#DBFiles',
							  "listEl" : '#DBFiles',
							  "token"  : '<?=$_modules['dropboxToken']?>',
							  'folder' : '/transactions/<?=enc($result['transactionId'])?>'
							};

							ncmDropbox(opts);
    					});
    				</script>

    				<?php
    				}	
    				?>

	    			<?=footerPrint(['signatures'=>2]);?>

	    			<?php
			    	if(!validateHttp('ro')){
			    	?>

				    	<div class="col-xs-12 wrapper bg-light lter hidden-print" style="margin-top:<?=($tSaleType == 1) ? 120 : 160;?>px;">
				    		<?php
					    	if(!$isOrder){
					    	?>
							<button class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right">Guardar</button>
							<?php
							}else{
								if($tStatus == 3){
							?>
								<a href="/@#bulk_transfer?i=<?=$id;?>" target="_blank" class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right">Transferir</a>
							<?php
								}else{
							?>
								<a href="/@#purchase?i=<?=$id;?>" target="_blank" class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right">Crear Compra</a>
							<?php
								}
								?>
								<button class="btn btn-default btn-rounded m-t-xs text-u-c font-bold pull-right m-r-md">Guardar</button>
								<?php
							}
							?>
							<a href="#" class="m-t cancelItemView m-r-lg pull-right">Cerrar</a>
							<a href="#" class="m-t print m-r-lg pull-right" data-element="#factura">Imprimir</a>
							<a href="<?=$baseUrl?>?action=delete&outlet=<?=enc($tOutlet)?>&id=<?=$id;?>" class="m-t m-r pull-left deleteTransaction"><span class="text-danger">Eliminar</span></a>
							<input type="hidden" name="id" value="<?=$id;?>">
						</div>

					<?php
					}else{
						?>
						<div class="col-xs-12 wrapper bg-light lter" style="margin-top:110px;">
				    		<a href="#" class="print pull-right hidden-xs" data-element="#factura">Imprimir</a>
				    	</div>
						<?php
					}
					?>
	    		</div>
	    	</div>
	    </form>
    </div>

    <?php
	dai();
}

if(validateHttp('action') == 'paymentForm'){
	if(!validateHttp('id')){
		dai('false');
	}

	$id 		= validateHttp('id');
	$result 	= ncmExecute("SELECT * FROM transaction WHERE transactionId = ? LIMIT 1",[dec($id)]);

	$deuda 		= $result['transactionTotal'];
	$payments 	= ncmExecute('SELECT * FROM transaction WHERE transactionParentId = ? AND companyId = ?',[$result['transactionId'],COMPANY_ID],false,true,true);

	if($payments){
		$totalPaid = 0;
		foreach($payments as $key => $paymnt){
			$totalPaid += $paymnt['transactionTotal'];
		}
		$deuda = $result['transactionTotal'] - $totalPaid;					
	}
	
    ?>
    
    <div class="col-xs-12 no-padder bg-white r-24x clear">
	    <form action="/a_report_purchases?action=addPayment" method="POST" id="addPaymentForm" name="addPaymentForm">
			<div class="col-xs-12 text-center m-t">
				<div class="">Deuda Total</div>
		        <div class="font-bold h2"><?=CURRENCY . formatCurrentNumber($deuda)?></div>
		    </div>

		    <div class="col-xs-12 m-t m-b">
		    	
				<label class="font-bold text-u-c text-xs">Fecha</label>
        		<input type="text" class="form-control no-border rounded bg-light datetimepicker pointer text-center" name="date" value="<?=date('Y-m-d H:i:s')?>" autocomplete="off" readonly />

        		
			    <label class="font-bold text-u-c text-xs m-t-sm"># de Comprobante</label>
			    <input type="number" min="0" step="1" class="form-control no-bg text-right no-border b-b" name="invoiceNo" value="" autocomplete="off" placeholder="Número" />

			    <label class="font-bold text-u-c text-xs m-t-sm">Método</label>
			    <?php
			    $paym = getAllPaymentMethodsArray();

			    ?>
			    <select id="paymentMethod" name="paymentMethod" tabindex="1" data-placeholder="Seleccione" class="form-control search" autocomplete="off">

			    <?php
			    foreach ($paym as $key => $value) {
			    	?>
			    	<option value="<?=$key;?>">
                    	<?=$value;?>
                    </option>
			    	<?php
			    }

			    ?>

				</select>
			    
			    
				<label class="text-u-c font-bold text-xs m-t-sm">Monto a pagar</label>
		        <input type="text" class="maskCurrency form-control input-lg" name="payAmount" value="<?=formatCurrentNumber($deuda)?>" id="payAmountField">

		        <label class="text-u-c font-bold text-xs m-t-sm">Nota</label>
		        <textarea class="form-control" name="payNote"></textarea>

			    <!--<label class="font-bold m-t text-u-c">Método de Pago</label>
	            <?php $pM = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "paymentMethod" AND ' . $SQLcompanyId . ' ORDER BY taxonomyName ASC',[],false,true); ?>
	            <select id="paymentMethod" name="paymentMethod" tabindex="1" data-placeholder="Seleccione" class="form-control search" autocomplete="off">
	               <option value="cash">Efectivo</option>
	               <option value="creditcard">T. Crédito</option>
	               <option value="debitcard">T. Débito</option>
	               <option value="check">Cheque</option>
	              <?php 
	                if($pM){
	                  while (!$pM->EOF) {
	                    $pMId = enc($pM->fields['taxonomyId']);
	              ?>
	                    <option value="<?=$pMId;?>">
	                      <?=$pM->fields['taxonomyName'];?>
	                    </option>
	              <?php 
	                    $pM->MoveNext(); 
	                  }
	                  $pM->Close();
	                }
	                
	              ?>
	            </select>-->
		    </div>

		    

			<?php
	    	if(!validateBool('ro')){
	    	?>

	    	<div class="col-xs-12 wrapper bg-light lter text-center">
				<button class="btn btn-info btn-lg btn-rounded text-u-c font-bold">Pagar</button>
				<input type="hidden" name="id" value="<?=$id;?>">
				<input type="hidden" name="debt" value="<?=$deuda;?>">
			</div>

			<?php
			}
			?>
	    </form>
    </div>

    <?php
	dai();
}

if(validateHttp('action') == 'addPayment' && validateHttp('id','post')){
	if(!allowUser('expenses','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$value 		= formatNumberToInsertDB(validateHttp('payAmount','post'));
	$parentId 	= dec(validateHttp('id','post'));
	$debt 		= validateHttp('debt','post');
	$date 		= validateHttp('date','post');
	$docNo 		= validateHttp('invoiceNo','post');
	$method 	= validateHttp('paymentMethod','post');
	$note 		= strip_tags( validateHttp('payNote','post') );
	$payment    = json_encode( [ ['type' => getPaymentMethodDecoded($method), "price" => $value] ] );

	$record 							= [];
	$record['invoiceNo'] 				= $docNo;
	$record['transactionDate'] 			= $date;
	$record['transactionTotal'] 		= $value;
	$record['transactionParentId'] 		= $parentId;
	$record['transactionPaymentType'] 	= $payment;
	$record['transactionType'] 			= 5;
	$record['transactionNote'] 			= $note;

	$record['userId'] 					= USER_ID;
	$record['outletId'] 				= OUTLET_ID;
	$record['companyId'] 				= COMPANY_ID;


	$insert 	= $db->AutoExecute('transaction', $record, 'INSERT'); 
	$insertedId = enc($db->Insert_ID());

	$status = 0;

	if($insert !== false){
		if($value >= $debt){
			$status = 1;
		}

		$db->AutoExecute('transaction', ['transactionComplete' => $status], 'UPDATE', 'transactionId = ' . $parentId); 

		dai('true|0|' . enc($parentId));
	}else{
		dai('false');
	}
}

//tables
if(validateHttp('action') == 'general'){
	$limits = getTableLimits($limitDetail,$offsetDetail);

	if(validateHttp('singleRow')){
		$singleRow = ' AND transactionId = ' . dec( validateHttp('singleRow') );
	}

	if(validateHttp('supId')){
		$supplierId = dec( validateHttp('supId') );

		$sql = "SELECT * 
				FROM transaction USE INDEX(transactionType,outletId) 
				WHERE transactionType IN (1,4)
				" . $singleRow . "
				" . $roc . "
				AND supplierId = ?
				ORDER BY transactionDate 
				DESC " . $limits;

		$saleDay 	= ncmExecute($sql, [$supplierId], false, true);
	}else{

		$sql = "SELECT * 
				FROM transaction 
				WHERE transactionType IN (1,4)
				AND transactionDate 
				BETWEEN ? 
				AND ?
				" . $singleRow . "
				" . $roc . "
				ORDER BY transactionDate 
				DESC " . $limits;

		$saleDay 	= ncmExecute($sql, [$startDate, $endDate], false, true);
	}

	$table 	= '';
	$head 	= 	'<thead class="text-u-c">'.
				'	<tr>'.
				'		<th class="ignored">Timbrado o Autorización</th>'.
				'		<th># Documento</th>'.
				'		<th>Fecha</th>'.
				'		<th class="no-search">Vencimiento</th>'.
				'		<th>Sucursal</th>'.
				'		<th class="ignored">Usuario</th>'.
				'		<th>Proveedor</th>'.
				'		<th>'.TIN_NAME.'</th>'.
				'		<th class="no-order">Nota</th>'.
				'		<th>Medio de Pago</th>'.
				'		<th>Tipo</th>'.
				'		<th>Plan de Cuentas</th>' .
				'		<th>' . TAX_NAME . '</th>';

				/*foreach ($getAllTaxNames as $tnK => $tnV) {
					$name = ($tnV['name'] > 0) ? TAX_NAME . ' ' . $tnV['name'] . '%' : 'Exentas';
					$head .= '<th class="text-center ignored">' . $name . '</th>';
				}*/

	$head 	.= 	'		<th class="text-center no-search">Descuento</th>'.
				'		<th class="text-center no-search">Total</th>'.
				'		<th class="text-center no-search">Deuda</th>'.
				'		<th class="ignored"></th>'.
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	if($saleDay){
		while (!$saleDay->EOF) {
			$transArr[] 	= $saleDay->fields['transactionId'];
			$saleDay->MoveNext();
		}

		$saleDay->MoveFirst();

		$tTotal 			= '';
		$allSuppliersArray 	= getAllContactsRaw(2,true);
		$allUsersArray 		= getAllContactsRaw(0,true);
		$fullTotal 			= 0;
		$fullSales 			= 0;
		$deudaTotal 		= 0;

		$tIds 				= implodes(',',$transArr);
		$taxTotals 			= getAllTransactionTaxes($tIds,false);
 
		while (!$saleDay->EOF) {

			$fields 		= $saleDay->fields;
			$itemId 		= enc($fields['transactionId']);
			$fecha 			= timeago($fields['transactionDate']);
			$transactionCat = ($fields['categoryTransId']) ? getTaxonomyName($fields['categoryTransId']) : '-' ;

			$transactionType 	= '<span class="label bg-light text-u-c">Contado</span>';
			$typeFilter 		= 'tipo:contado';

			if($fields['transactionType'] == '4'){
				if($fields['transactionComplete'] == 1){
					$transactionType 		= '<span class="label bg-success lter text-white text-u-c">Crédito</span>';
					$typeFilter 			= 'tipo:crédito_conpago';
				}else{
					$transactionType 		= '<span class="label bg-danger lter text-u-c">Crédito</span>';
					$typeFilter 			= 'tipo:crédito_sinpago';
				}
			}

			$transactionStatus 	= 'Pendiente';
			if($fields['transactionStatus'] == '1'){
				$transactionStatus 	= 'Concretada';
				//$typeFilter 		= '';
			}else if($fields['transactionStatus'] == '2'){
				$transactionStatus 	= 'Anulada';
				$typeFilter 		= 'tipo:anulada';
			}else if($fields['transactionStatus'] == '0'){
				$transactionStatus 	= 'Orden';
				$transactionType 	= '<span class="label bg-dark text-u-c">Orden</span>';
				$typeFilter 		= 'tipo:orden';
			}else if($fields['transactionStatus'] == '3'){//pedido
				$transactionStatus 	= 'Reposición';
				$transactionType 	= '<span class="label bg-dark text-u-c">Reposición</span>';
				$typeFilter 		= 'tipo:reposición';
			}

			$tootal 	= $fields['transactionTotal'];
			$note 		= unXss($fields['transactionNote']);
			$supTIN 	= unXss($allSuppliersArray[$fields['supplierId']]['contactTIN']);
			$supName 	= unXss($allSuppliersArray[$fields['supplierId']]['contactName']);
			$usrName 	= unXss($allUsersArray[$fields['userId']]['contactName']);

			$payBtnRow  = '<td></td>';
			$debt 		= 0;

			//payment methods
			$paymentType 	= getPaymentMethodsInArray($fields['transactionPaymentType']);
			$pMethods 		= [];

			if(validity($paymentType)){
				foreach($paymentType as $pType){	
					if($pType['price'] > 0){
						$pMethods []= iftn(getPaymentMethodName($pType['type']),$pType['name']);
					}
				}
			}

			$pMethods = arrayToLabelsUI(['data' => $pMethods, 'bg' => 'bg-light']);

			//

			if($fields['transactionType'] == '4' && $fields['transactionComplete'] == 0){
				$payed 		= ncmExecute('SELECT SUM(transactionTotal) as payed FROM transaction WHERE transactionParentId = ? AND companyId = ? GROUP BY transactionParentId',[$fields['transactionId'],COMPANY_ID]);
				$debt 		= $tootal - $payed['payed'];

				$payBtnRow  = '<td class="text-center hidden-print"><a href="#" class="addPayment" data-toggle="tooltip" data-placement="left" title="Añadir Pago" data-id="'.enc($fields['transactionId']).'"><i class="material-icons text-success">payment</i></a></td>';
			}

			$authNo = '';
			$prefix = $fields['invoicePrefix'];
			
			if(strpos($fields['invoicePrefix'], ';') !== false){
				$authPrefix = explodes(';', $fields['invoicePrefix']);
				$authNo		=	$authPrefix[0];
				$prefix		=	$authPrefix[1];
			}

			$table .= 	'<tr data-id="' . $itemId . '" data-load="' . $baseUrl . '?action=edit&id='.$itemId.'" class="clickrow">' .
						'	<td> ' . $authNo . ' </td>' .
						'	<td> ' . $prefix . ' ' . iftn($fields['invoiceNo']) . ' </td>' .
						'	<td data-order="' . $fields['transactionDate'] . '"> <span data-toggle="tooltip" data-placement="top" title="Hace ' . $fecha . '">' . niceDate($fields['transactionDate']) . '</span> </td>' .
						'	<td data-order="'.iftn($fields['transactionDueDate'],'').'"> ' . iftn( $fields['transactionDueDate'],'-',niceDate($fields['transactionDueDate']) ) . ' </td>' .
						'	<td> ' . $allOutletsArray[$fields['outletId']]['name'] . ' </td>' .
						'	<td> ' . $usrName . ' </td>' .
						'	<td> ' . $supName . ' </td>' .
						'	<td> ' . $supTIN . ' </td>' .
						'	<td> ' . $note . ' </td>' .
						' 	<td> ' . $pMethods . '</td>' .
						'	<td data-filter="'.$typeFilter.'"> ' . $transactionType . ' </td>' .
						'	<td> ' . $transactionCat . ' </td>' . 
						'	<td class="text-right bg-light lter"  data-order="' . $fields['transactionTax'] . '" data-format="money"> ' . formatCurrentNumber($fields['transactionTax']) . ' </td>';

						/*foreach ($getAllTaxNames as $tnK => $tnV) {
							$tnVN 		= (!$tnV['name'] || $tnV['name'] == 0) ? "0" : $tnV['name'];
							$tnValue 	= (float) str_replace(',', '', $taxTotals[$fields['transactionId']][$tnVN] );
							
							$table .= '	<td class="text-right bg-light lter" data-order="' . $tnValue . '" data-format="money">' . formatCurrentNumber($tnValue) . '</td>';
						}*/

			$table .= 	'	<td class="text-right bg-light lter"  data-order="' . $fields['transactionDiscount'] . '" data-format="money"> ' . formatCurrentNumber($fields['transactionDiscount']) . ' </td>' .
						'	<td class="text-right bg-light lter"  data-order="'.$tootal.'" data-format="money"> '.formatCurrentNumber($tootal).' </td>' .
						'	<td class="text-right bg-light lter"  data-order="'.$debt.'" data-format="money"> '.formatCurrentNumber($debt).' </td>' .
						$payBtnRow;

			$table .= '</tr>';

			if(validateHttp('part') && !validateHttp('singleRow')){
	        	$table .= '[@]';
	        }

			$saleDay->MoveNext();
			$isdata = true;
		}

		$saleDay->Close();
	}

	$foot 	= 	'</tbody>'.
				'<tfoot>'.
				'	<tr>'.
				'		<th colspan="12">TOTALES</th>';

				/*foreach ($getAllTaxNames as $tnK => $tnV) {
					$foot .= '<th class="text-right"></th>';
				}*/

	$foot 	.= 	'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th></th>'.
				'	</tr>'.
				'</tfoot>';
		
	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		if($_GET['debug']){
			//echo $fullTable;
			//dai();
		}

		//header('Content-Type: application/json'); 
		dai(json_encodes($jsonResult,true));
	}
}

if(validateHttp('action') == 'cobrosTable'){
	$limits = getTableLimits($limitDetail,$offsetDetail);
	$table 	= '';

	if(validateHttp('supId')){
		$supplierId = dec(validateHttp('supId'));

		$sql = 	"SELECT *
			FROM transaction 
			WHERE transactionType IN (5)
			AND transactionDate 
		   	" . $roc . "
		   	AND supplierId = ?
		  	ORDER BY transactionDate DESC" . $limits;

		$result = ncmExecute($sql,[$supplierId],false,true,true);
	}else{
		$sql = 	"SELECT *
			FROM transaction 
			WHERE transactionType IN (5)
			AND transactionDate 
			BETWEEN ?
			AND ? 
		   	" . $roc . "
		  	ORDER BY transactionDate DESC" . $limits;

		$result = ncmExecute($sql,[$startDate,$endDate],false,true,true);
	}

	

	$head 	=	'<thead class="text-u-c pointer">' .
				'	<tr>' .
				'		<th class="text-center"># Doc. Ref.</th>' .
				'		<th class="text-center"># Comprobante</th>' .
				'		<th>Fecha</th>' .
				'		<th>Usuario</th>' .
				'		<th>Sucursal</th>' .
				'		<th>Nota</th>' .
				'		<th>M. de Pago</th>' .
				'		<th class="text-center">Pagado</th>' .
				'		<th></th>' .
				'	</tr>' .
				'</thead>' .
				'<tbody>';

	if($result){
		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		
		foreach ($result as $key => $fields) {

			$parentIs = ncmExecute('SELECT transactionType, invoiceNo, invoicePrefix, supplierId FROM transaction WHERE transactionId = ? AND transactionType = 4 AND companyId = ? LIMIT 1',[$fields['transactionParentId'],COMPANY_ID]); //valido si es un pago de una compra a credito

			if($parentIs){
				$transId 		= enc($fields['transactionParentId']);
				$customer 		= getCustomerData($parentIs['supplierId']);

				if(validity($fields['userId'])){
					$user 				= getCustomerData($fields['userId']);
					$userName 			= ($user['name']);
				}else{
					$userName 			= '-';
				}

				//payment methods
				$paymentType 	= getPaymentMethodsInArray($fields['transactionPaymentType']);
				$pMethods 		= '';



				if(validity($paymentType)){
					foreach($paymentType as $pType){		
						$pMethods .= '<span class="label bg-light m-r-xs" data-toggle="tooltip" data-placement="top" title="'.formatCurrentNumber($pType['price']).'">' . iftn(getPaymentMethodName($pType['type']),getPaymentMethodName($pType['type'],true)) . '</span>';
					}
				}
				//

				//parent invoice
				$parentInvoice 		= $parentIs['invoicePrefix'] . ' ' . $parentIs['invoiceNo'];

				$customer 			= ($customer['name']);

				$table .= '<tr data-id="'.$transId.'" data-load="' . $baseUrl . '?action=edit&id=' . $transId . '&ro=true" class="clickrow pointer">'.
						'		<td class="text-right">' . $parentInvoice . '</td>'.
						'		<td class="text-right">' . $fields['invoiceNo'] . '</td>'.
						'		<td data-order="'.$fields['transactionDate'].'" data-filter="'.$fields['transactionDate'].'">'.niceDate($fields['transactionDate'],true).'</td>'.
						'		<td>' . $userName.'</td>'.
						'		<td>' . $getAllOutlets[$fields['outletId']]['name'] . '</td>'.
						'		<td>' . unXss($fields['transactionNote']) . '</td>'.
						'		<td>' . $pMethods . '</td>'.
						'		<td class="text-right bg-light lter" data-order="'.$fields['transactionTotal'].'" data-format="money"> '.formatCurrentNumber($fields['transactionTotal']).'</td>'.
						'		<td class="text-center">' . 
						'			<a href="' . $baseUrl . '?action=delete&id=' . enc($fields['transactionId']) . '&outlet=' . enc($fields['outletId']) . '&type=' . enc($fields['transactionType']) . '&parent=' . $transId . '" class="deletePayment hidden-print">' .
						' 				<i class="material-icons text-danger">close</i>' . 
						'			</a>' . 
						'		</td>' .
						'	</tr>';

				if(validateHttp('part')){
		        	$table .= '[@]';
		        }
		    }

		}
	}

	$foot 	= 		'</tbody>'.
					'<tfoot>'.
			        '    <tr>'.
			        '   	<th colspan="7">TOTALES:</th>'.
			        '       <th class="text-right"></th>'.
			        '    	<th></th>'.
			        '    </tr>'.
			        '</tfoot>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		header('Content-Type: application/json'); 
		dai(json_encodes($jsonResult));
	}
}

if(validateHttp('action') == 'detailTable'){
	theErrorHandler('json');
	//ini_set('memory_limit','256M');

	$export 	= validateBool('export') ? true : false;
	$roc 		= str_replace(['outletId','registerId','companyId'],['a.outletId','a.registerId','a.companyId'],getROC(1));
	$table 		= '';
	$xls 		= [];
	$limits 	= getTableLimits($limitDetail,$offsetDetail);
	$limits		= ($export) ? '' : $limits;

	if(validateHttp('src')){
		$word 	= $db->Prepare(validateHttp('src'));
		//primero obtengo posible fuente
		$sData 	= ncmExecute('SELECT GROUP_CONCAT(itemId) as ids FROM item WHERE (itemName LIKE "%' . $word . '%" OR itemSKU LIKE "%' . $word . '%") AND companyId = ? AND itemStatus = 1 LIMIT 100',[COMPANY_ID],true);
		
		$search = ' AND b.itemId IN(' . $sData['ids'] . ')';

		$sql = 'SELECT 
					a.supplierId as supplier,
					a.userId as trsUser,
					a.outletId,
					a.registerId,
					a.invoiceNo,
					a.invoicePrefix,
					a.transactionType,
					a.categoryTransId,
					a.transactionDetails,
					b.itemSoldId,
					b.itemId,
					b.itemSoldUnits,
					b.itemSoldTotal, 
					b.itemSoldTax, 
					b.itemSoldDiscount,
					b.itemSoldDate,
					b.itemSoldDescription,
					b.itemSoldCOGS,
					b.itemSoldComission,
					b.itemSoldParent,
					b.transactionId,
					b.userId as itemUser
				FROM transagction a, itemSold b
				WHERE a.transactionDate
				BETWEEN ?
				AND ? 
				' . $roc . '
				AND a.transactionType IN(1,4)
				AND a.transactionId = b.transactionId
				' . $search . '
				ORDER BY a.transactionDate DESC' . $limits;


		$result 	= ncmExecute($sql,[$startDate,$endDate],false,true);
	}else{

		if(validateHttp('supId')){
			$supplierId = dec(validateHttp('supId'));

			$sql = 'SELECT 
						a.supplierId as supplier,
						a.userId as trsUser,
						a.outletId,
						a.registerId,
						a.invoiceNo,
						a.invoicePrefix,
						a.transactionType,
						a.categoryTransId,
						a.transactionDetails,
						b.itemSoldId,
						b.itemId,
						b.itemSoldUnits,
						b.itemSoldTotal, 
						b.itemSoldTax, 
						b.itemSoldDiscount,
						b.itemSoldDate,
						b.itemSoldDescription,
						b.itemSoldCOGS,
						b.itemSoldComission,
						b.itemSoldParent,
						b.transactionId,
						b.userId as itemUser
					FROM transaction a, itemSold b
					WHERE a.transactionType IN(1,4)
					AND a.supplierId = ?
					' . $roc . '
					AND a.transactionId = b.transactionId
					ORDER BY a.transactionDate DESC';

			$result 	= ncmExecute($sql,[$supplierId],false,true);

		}else if(validateHttp('itmId')){
			$itemId = dec(validateHttp('itmId'));

			$sql = 'SELECT 
						a.supplierId as supplier,
						a.userId as trsUser,
						a.outletId,
						a.registerId,
						a.invoiceNo,
						a.invoicePrefix,
						a.transactionType,
						a.categoryTransId,
						a.transactionDetails,
						b.itemSoldId,
						b.itemId,
						b.itemSoldUnits,
						b.itemSoldTotal, 
						b.itemSoldTax, 
						b.itemSoldDiscount,
						b.itemSoldDate,
						b.itemSoldDescription,
						b.itemSoldCOGS,
						b.itemSoldComission,
						b.itemSoldParent,
						b.transactionId,
						b.userId as itemUser
					FROM transaction a, itemSold b
					WHERE a.transactionType IN(1,4)
					AND b.itemId = ?
					' . $roc . '
					AND a.transactionId = b.transactionId
					ORDER BY a.transactionDate DESC';

			$result 	= ncmExecute($sql,[$itemId],false,true);
		}else{

			$sql = 'SELECT 
						a.supplierId as supplier,
						a.userId as trsUser,
						a.outletId,
						a.registerId,
						a.invoiceNo,
						a.invoicePrefix,
						a.transactionType,
						a.categoryTransId,
						a.transactionDetails,
						b.itemSoldId,
						b.itemId,
						b.itemSoldUnits,
						b.itemSoldTotal, 
						b.itemSoldTax, 
						b.itemSoldDiscount,
						b.itemSoldDate,
						b.itemSoldDescription,
						b.itemSoldCOGS,
						b.itemSoldComission,
						b.itemSoldParent,
						b.transactionId,
						b.userId as itemUser
					FROM transaction a, itemSold b
					WHERE a.transactionDate
					BETWEEN ?
					AND ? 
					' . $roc . '
					AND a.transactionType IN(1,4)
					AND a.transactionId = b.transactionId
					ORDER BY a.transactionDate DESC' . $limits;

			$result 	= ncmExecute($sql,[$startDate,$endDate],false,true);
		}
	} 

	$head 	= 	'<thead class="text-u-c">'.
				'	<tr>'.
				'		<th>Sucursal</th>' .
				'		<th># Documento</th>' .
				'		<th>Usuario</th>' .
				'		<th>Proveedor</th>' .
				'		<th>Fecha</th>' .
				'		<th>Artículo</th>' .
				'		<th>Categoría</th>' .
				'		<th class="text-center">Cantidad</th>' .
				'		<th class="text-center">Costo</th>'.
				'		<th class="text-center">' . TAX_NAME . '</th>'.
				'		<th class="text-center">Total</th>'.
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	if($result){		
		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		$allUsersArray 			= getAllContactsRaw('0');
		$allTaxes 				= getAllTax();
		//$stockArray 			= getAllItemStock(OUTLET_ID);
	
		while (!$result->EOF) {
			$fields 	= $result->fields;
			$id 		= $fields['itemId'];
			$tId 		= $fields['transactionId'];
			$allItms 	= getItemData($id,true);
			$category 	= getTaxonomyName($fields['categoryTransId']);
			$category 	= ($category == 'None') ? '' : $category;

			$transactionCat = $fields['categoryTransId'];
			$details 	= json_decode($fields['transactionDetails'],true);

			if(validity($details,'array')){

				foreach($details as $k => $itm){
					if(COMPANY_ID == 10){
						//echo 'sale itmId ' . dec($itm['itemId']) . ' ' . $id . ' plan ' . $itm['plan'];
						//print_r($itm);
					}

					if(dec($itm['itemId']) == $id && $itm['plan']){
						$transactionCat = dec($itm['plan']);
					}
				}
				
			}

			$category 		= iftn(getTaxonomyName($transactionCat,true),'-');

			if($allItms){
				$taxName 	= '';//$allTaxes[$allItms['taxId']]['name'];
				$name		= iftn($allItms['itemName'],$fields['itemSoldDescription']);
				/*$cogss 		= $stockArray[$allItms['itemId']]['cogs'];
				$nucogss 	= divider($fields['itemSoldTotal'], $fields['itemSoldUnits']);
				if($cogss < 0.01 && $nucogss > 0){
					$ops['itemId']    = $allItms['itemId'];
					$ops['outletId']  = OUTLET_ID;
					$ops['cogs']      = $nucogss;
					$ops['count']     = 0;

					$manage 		  = manageStock($ops);
				}*/
			}else{
				$taxName 	= '';//0;
				$name		= $fields['itemSoldDescription'];
			}

			$uSold 		= $fields['itemSoldUnits'];
			$total 		= $fields['itemSoldTotal'];
			$cogs 		= divider($fields['itemSoldTotal'], $uSold);
			$tax 		= $fields['itemSoldTax'];
		    
		    $outlet 	= $getAllOutlets[$fields['outletId']]['name'];
		    $register 	= $getAllRegisters[$fields['registerId']]['name'];
		    $user 		= $allUsersArray[iftn($fields['itemUser'],$fields['trsUser'])]['contactName'];

		    //customer data
		    $customer 	= getContactData($fields['supplier'], false);
			$ago 		= timeago($fields['itemSoldDate'],false);
			$fecha 		= niceDate($fields['itemSoldDate']);
			$type  		= $fields['transactionType'];

			if($comission > 0){
				$earned = $comission;
			}else{	
				$commission	= $allItems[$id]['comission'];
				$earned 	= 0;
				if($commission > 0){
					$earned = divider(($commission * $total),100);
				}
			}

			$utility 	= (($total - $tax) - $cogs) - $earned;

			$discountf 	= formatCurrentNumber($discount);
			$earnedf 	= formatCurrentNumber($earned);
			$taxf 		= formatCurrentNumber($tax) . ' <span class="hidden">' . $taxName . '</span>';
			$subtotalf 	= formatCurrentNumber($subtotal);
			$totalf 	= formatCurrentNumber($total);
			$cogsf 		= formatCurrentNumber($cogs);
			$utilityf 	= formatCurrentNumber($utility);

			$authNo = '';
			$prefix = $fields['invoicePrefix'];
			
			if(strpos($fields['invoicePrefix'], ';') !== false){
				$authPrefix = explodes(';', $fields['invoicePrefix']);
				$authNo		=	$authPrefix[0];
				$prefix		=	$authPrefix[1];
			}

			$invoiceno 	= $prefix . ' ' . iftn($fields['invoiceNo']);

			$table .= 	'<tr data-load="' . $baseUrl . '?action=edit&id='.enc($tId).'&ro=1" class="clickrow pointer">' .
						'	<td> '.$outlet.' </td>' .
						'	<td class="text-right"> '.$invoiceno.' </td>' .
						'	<td> '.$user.' </td>' .
						'	<td> '.$customer['name'].' </td>' .
						'	<td><span data-toggle="tooltip" data-placement="top" title="Hace '.$ago.'">'.$fecha.'</span></td>' .
						'	<td> ' . $name . ' </td>' .
						'	<td> ' . $category . ' </td>' .
						'	<td class="text-right bg-light lter" data-order="'.$uSold.'"> ' . formatQty($uSold) . ' </td>' .
						'	<td class="text-right bg-light lter" data-order="'.$cogs.'" data-format="money"> ' . $cogsf . ' </td>' .
						'	<td class="text-right bg-light lter" data-order="'.$tax.'" data-format="money"> ' . 
								$taxf .
						'	</td>' .
						'	<td class="text-right bg-light lter" data-order="'.$total.'" data-format="money"> ' . $totalf . ' </td>' .
						'</tr>';

			if(validateHttp('part')){
	        	$table .= '[@]';
	        }

			$result->MoveNext();
		}
	}

	$foot 	= 	'	</tbody>' .
				'	<tfoot>' .
				'		<tr>' .
				'			<th>TOTALES:</th>' .
				'			<th></th>' .
				'			<th></th>' .
				'			<th></th>' .
				'			<th></th>' .
				'			<th></th>' .
				'			<th></th>' .
				'			<th class="text-right"></th>' .
				'			<th class="text-right"></th>' .
				'			<th class="text-right"></th>' .
				'			<th class="text-right"></th>' .
				'		</tr>' .
				'	</tfoot>';


	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		header('Content-Type: application/json');
		dai(json_encode($jsonResult));
	}
}

if(validateHttp('action') == 'rg90'){
	
	ini_set('memory_limit', '256M');
	/*
	0 = Venta al contado  	    
	1 = Compra al contado 	    
	2 = Guardada  	    
	3 = Venta a crédito 	    
	4 = Compra a crédito 	    
	5 = Pago de ventas a crédito 	    
	6 = Devolución 	    
	7 = Venta anulada 	    
	8 = Venta recursiva
	*/

	$saleDay 	= ncmExecute("	SELECT * 
								FROM transaction 
								WHERE transactionType IN(1,4)
								AND transactionDate 
								BETWEEN ? 
								AND ? 								
								" . $roc . "
								ORDER BY transactionDate 
								DESC", [$startDate,$endDate],true,true);

	/* print_r($roc);
	die(); */
	$var 			= [];
	$array 			= [];
	$excellRow  	= [];

	$isCreditSale 	= false;
	$No 			= validateResultFromDB($saleDay,true);

	if($saleDay){
		
		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		$cachedContact 			= [];
		$cachedUser 			= [];

		$excellRow[]			= 	[
										'CODIGO TIPO DE REGISTRO',
										'CODIGO TIPO DE IDENTIFICACION DEL PROVEEDOR/VENDEDOR',
										'NUMERO DE IDENTIFICACION DEL PROVEEDOR/VENDEDOR',
										'NOMBRE O RAZON SOCIAL DEL PROVEEDOR/VENDEDOR',
										'CODIGO TIPO DE COMPROBANTE',
										'FECHA DE EMISION DEL COMPROBANTE',
										'NUMERO DE TIMBRADO',
										'NUMERO DEL COMPROBANTE',
										'MONTO GRAVADO AL 10%',
										'MONTO GRAVADO AL 5%',
										'MONTO NO GRAVADO O EXENTO',
										'MONTO TOTAL DEL COMPROBANTE',
										'CODIGO CONDICION DE COMPRA',
										'OPERACION EN MONEDA EXTRANJERA',
										'IMPUTA AL IVA',
										'IMPUTA AL IRE',
										'IMPUTA AL IRP-RSP',
										'NUMERO DEL COMPROBANTE DE VENTA ASOCIADO',
										'TIMBRADO DEL COMPROBANTE DE VENTA ASOCIADO',
										
									];
		$jsonObjText = [];
		
		while (!$saleDay->EOF) {
		
			$fields 				= $saleDay->fields;
			
			$tStatus 				= $fields['transactionStatus'];
			if($tStatus == 0 || $tStatus == 3){
				$saleDay->MoveNext();
				continue;
			}

			$tTotal 				= ($fields['transactionTotal'] <= 0) ? 0 : $fields['transactionTotal'] - $fields['transactionDiscount'];
			$grav5 					= 0;
			$grav10					= 0;
			$grav21					= 0;
			$exentas				= 0;
			
			
			if(!$cachedContact[$fields['supplierId']]){
				$customer 				= getContactData($fields['supplierId'],'id',true);
				$cachedContact[$fields['supplierId']] = $customer;
			}else{
				$customer = $cachedContact[$fields['supplierId']];
			}
			
			$transactionId = $fields["transactionId"];
			$tax 	= ncmExecute("SELECT toTaxObjText FROM toTaxObj WHERE transactionId = ? ", ["6213743"]);
			
			$json = json_decode($tax["toTaxObjText"]);
			print_r(json_encode($json));
			die();
			foreach ($json as $key => $val){

				$jsonObjText[$key]["tax"] = $val->name;
				$jsonObjText[$key]["total"] = $fields["transactionTotal"];
		
			}
			/* print_r(json_encode($fields));
			die(); */
			/* print_r(json_encode($jsonObjText));
			die(); */
			$totalTaxes 			= getTaxTotalsBySaleItems($jsonObjText );
			
			foreach ($getAllTaxNames as $tnK => $tnV) {
				
					
					
					if($tnV['name'] == '10'){
						
						$grav10 	+= $totalTaxes['total']['10'];
					}else{
						$grav10 	+= 0;
					}
					

					if($tnV['name'] == '0'){
						$exentas 	+=$totalTaxes['total']['0'];
					}else{
						$exentas 	+= 0;
					}

					if($tnV['name'] == '5'){
						$grav5 		+= $totalTaxes['total']['5'];
					}else{
						$grav5 		+= 0;
					}
				

			}

			//CI = 12
			//RUC = 11
			//Sin nombre  = 15
			$TINType 				= 11;

			if(!$customer['ruc']){
				$customer['ruc'] 	= 'X';
				$customer['name'] 	= 'SIN NOMBRE';
				$TINType 			= 15;
			}else{
				if (strpos($customer['ruc'], '-') !== false){
					$TINType 			= 11;
					$ruc 				= explode('-', $customer['ruc']);
					$customer['ruc'] 	= $ruc[0];
				}else{
					$TINType 			= 12;
				}
			}
			$str = explode(";",$fields['invoicePrefix']);
			$excellRow[]  = [
	                            '2', //COMPRA
	                            $TINType,
	                            $customer['ruc'],
								$customer['name'],
	                            '109', //FACTURA
	                            date('d/m/Y', strtotime($fields['transactionDate'])), //FECHA
	                            $str[0], //NRO TIMBRADO
	                            $str[1] . leadingZeros($fields['invoiceNo'], 7), //NRO FACTURA
	                            formatCurrentNumber($grav10), //GRAVADO 10%
	                            formatCurrentNumber($grav5), //GRAVADO 5%
	                            formatCurrentNumber($exentas), //EXENTAS
	                            formatCurrentNumber($tTotal - $fields['transactionDiscount']), //TOTAL FACTURA
	                            ($fields['transactionType'] == '4') ? '2' : '1', //CONTADO = 1, CREDITO = 2
	                            'N', //MONEDA EXTRANJERA
	                            'S', //IMPUTA IVA
	                            'N', //IMPUTA IRE
	                            'N', //IMPUTA AL IRP-RSP
	                            '', //nro de nota de credito asociada
	                            '' //timbrado de nota de credito
								
	                        ];

			$saleDay->MoveNext();
		}

		if(!$_GET['test']){
			generateXLSfromArray($excellRow,'RG90-' . date("d-m-Y"));
		}else{
			echo '<pre>';
			print_r($excellRow);
			echo '</pre>';
		}

		$saleDay->Close();
	}

	dai();
}

?>

<style>
	.line-legend{
		list-style: none;
	}
	.line-legend li{
		float: left;
		vertical-align: middle;
		margin-right: 10px;
	}
	.line-legend li span{
		display: inline-block;
		width: 10px;
		height: 10px;
		margin-right: 5px;
	}
</style>

	<?=menuReports('<a href="#purchase" class="btn btn-sm btn-info pull-right">Realizar Compra</a>',true);?>

	<?php
  	if(validateHttp('ci')){
  		$cId 	= dec(validateHttp('ci'));
  		$cData 	= getContactData($cId);
  		$name 	= getCustomerName($cData);

  		$reportsTitle = [
    							'title' 		=> '<div class="text-md text-right font-default">Historial de</div> ' . $name,
    							'maxDays' 		=> $MAX_DAYS_RANGE,
    							'hideChart' 	=> true
    						];
  	}else{
  		$reportsTitle = [
    							'title' 		=> '<div class="text-md text-right font-default">Reporte de</div> Compras y Gastos',
    							'maxDays' 		=> $MAX_DAYS_RANGE,
    							'hideChart' 	=> true
    						];
  	}

  	echo reportsDayAndTitle($reportsTitle);
  	?>

	<div class="col-xs-12 no-padder m-t m-b-lg push-chat-down">

		<section class="col-xs-12 no-padder">

	    	<ul class="nav nav-tabs padder hidden-print">
	            <li class="active">
	                <a href="#tab1" data-toggle="tab">
	                	<span class="hidden-xs">Compra o Gasto</span>
	                	<span class="material-icons visible-xs">receipt_long</span>
	                </a>
	            </li>
	            <li class="" id="detailTab">
	                <a href="#tab2" data-toggle="tab">
	                	<span class="hidden-xs">Detalle</span>
	                	<span class="material-icons visible-xs">playlist_add_check</span>
	                </a>
	            </li>
	            <li class="" id="cobrosTab">
	                <a href="#tab3" data-toggle="tab">
	                	<span class="hidden-xs">Pagos realizados</span>
	                	<span class="material-icons visible-xs">payments</span>
	                </a>
	            </li>
	        </ul>

	        <section class="panel r-24x">
	            <div class="panel-body table-responsive" style="min-height: 500px;">

	                <div class="tab-content m-b-lg">
	                	<div class="tab-pane overflow-auto active" id="tab1">
						    <div id="tableData">
						        <table class="table table1 hover col-xs-12 no-padder" id="tableGeneral">
						        	<?=placeHolderLoader('table')?>
						        </table>
						    </div>
						</div>
						<div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab2">
	                    	<div id="detailTable">
                            	<table class="table table3 table-hover col-xs-12 no-padder" id="tableDetail">
                            		<?=placeHolderLoader('table')?>
                            	</table>
                            </div>
	                    </div>
						<div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab3">
	                    	<div id="cobrosTable">
                            	<table class="table table2 table-hover col-xs-12 no-padder" id="tablePayments">
                            		<?=placeHolderLoader('table')?>
                            	</table>
                            </div>
	                    </div>
					</div>

					<?=footerPrint(['signatures'=>2,'top'=>100]);?>
		
			</section>

        </section>
	</div>

<script>
var baseUrl   	= "<?=$baseUrl?>";
var tin_name  	= '<?=TIN_NAME?>';
var rawUrl 		= baseUrl + "?action=general";
var url 		= rawUrl + "&supId=<?=validateHttp('ci')?>";
var ci 			= "<?=validateHttp('ci')?>";
var ii 			= "<?=validateHttp('ii')?>";
var currency 	= "<?=CURRENCY?>";
var offset 		= <?=$offsetDetail?>;
var limit 		= <?=$limitDetail?>;
var noMoreBtn 	= <?=validateHttp('ci') ? 'true' : 'false'?>;
var tinName 	= "<?=TIN_NAME?>";
var taxName 	= "<?=TAX_NAME?>";
var saleType 	= "<?=$saletype?>";
var country 	= '<?=COUNTRY?>';
var rg90 		= '';

<?php
$columnNames = [
					["index" => 0, "name"  => 'Timb. o Aut.', "visible" => false],
					["index" => 1,"name" => '# Documento',"visible" => true],
					["index" => 2,"name" => 'Fecha',"visible" => true],
					["index" => 3,"name" => 'Vencimiento',"visible" => false],
					["index" => 4,"name" => 'Sucursal',"visible" => false],
					["index" => 5,"name" => 'Usuario',"visible" => false],
					["index" => 6,"name" => 'Proveedor',"visible" => true],
					["index" => 7,"name" => TIN_NAME,"visible" => false],
					["index" => 8,"name" => 'Nota',"visible" => false],
					["index" => 9,"name" => 'M. de Pago',"visible" => false],
					["index" => 10,"name" => 'Tipo',"visible" => true],
					["index" => 11,"name" => 'Plan de Cuentas',"visible" => false]
				];
	
$indx = 11;
/*foreach ($getAllTaxNames as $tnK => $tnV) {
	$name = ($tnV['name'] > 0) ? 'Gravadas ' . $tnV['name'] . '%' : 'Exentas';
	$columnNames[] = ["index" => $indx, "name" => $name, "visible" => false];
	$indx++;
}*/

$columnNames[] = ["index" => $indx++, "name" => TAX_NAME,"visible" => false];
$columnNames[] = ["index" => $indx++, "name" => 'Descuento',"visible" => false];
$columnNames[] = ["index" => $indx++, "name" => 'Total', "visible" => true];
$columnNames[] = ["index" => $indx++, "name" => 'Deuda', "visible" => false];
$columnNames[] = ["index" => $indx++, "name" => 'Pagar', "visible" => true];

$indx = 11;
$sums = [];
/*foreach ($getAllTaxNames as $tnK => $tnV) {
	$sums[] = $indx;
	$indx++;
}*/

$sums[] = $indx++;
$sums[] = $indx++;
$sums[] = $indx++;
$sums[] = $indx++;
$sums[] = $indx++;

?>

var columnsArray 	= <?=json_encode($columnNames);?>;
var columnsSums 	= <?=json_encode($sums);?>;
var dateFrom 		= "<?=$startDate?>";
var dateTo 			= "<?=$endDate?>";


<?php
//if($_GET['update']){
//  ob_start();
?>
$(document).ready(function(){
	dateRangePickerForReports(dateFrom,dateTo,false,true);

	var theClickRow = function(event,tis){
		var load = tis.data('load');
		loadForm(load,'#modalLarge .modal-content',() => {
			$('#modalLarge').modal('show');
		});
	}

	if(country == 'PY'){
		rg90 = '<a class="btn r-3x b b-light font-bold" id="rg90" href="#" data-toggle="tooltip" data-placement="bottom" title="RG90">RG90</a>';
	}

	$.get(url,function(result){

		window.info1 = {
					"container" 	: "#tableData",
					"url" 			: url,
					"rawUrl" 		: rawUrl,
					"table" 		: ".table1",
					"iniData" 		: result.table,
					"sort" 			: 2,
					"footerSumCol" 	: columnsSums,
					"currency" 		: currency,
					"decimal" 		: decimal,
					"thousand" 		: thousandSeparator,
					"offset" 		: offset,
					"limit" 		: limit,
					"noMoreBtn" 	: noMoreBtn,
					"tableName" 	: 'tableGeneral',
					"fileTitle" 	: 'Reporte de Compras y Gastos',
					"ncmTools"		: {
										left 	: rg90,
										right 	: ''
									  },
					"colsFilter"	: {
										name 		: 'reportPurchase1',
										menu 		:  columnsArray
									  },
					"clickCB" 		: function(event,tis){
									  	return theClickRow(event,tis);
			  						}
		};

		ncmDataTables(window.info1,function(oTable,_scope){
			loadTheTable(window.info1,oTable,_scope);
		});
	});

	var loadTheTable = function(tableOps,oTable,_scope){
		$('#modalLarge').off('shown.bs.modal').on('shown.bs.modal', function() {
		    select2Ajax({element:'.supplier-select',url:'/a_contacts?action=searchCustomerInputJson&t=2',type:'contact'});
		    select2Simple('.chosen-select');
		    masksCurrency($('.units'),thousandSeparator,'no');
			masksCurrency($('.maskFloat'),thousandSeparator,'yes');
			masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');
			masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
	    	masksCurrency($('.maskQty'),thousandSeparator,'yes',false,'2');

	    	select2Ajax({
						element 	: '.selectItem', 
						url 		: '/a_items?action=searchItemInputJson',
						type 		: 'item', 
						onChange 	: function(tis,data){
							var id 	= tis.data('id');
							var uid = tis.val();
							if(id){
								var url = baseUrl + '?action=updateItem&id=' + id + '&uid=' + uid;
								$.get(url,function(result){
									if(result){
										message('Actualizado','success');
									}else{
										message('Error al actualizar','danger');
									}
								});
							}
						}
					});

	    	if(isMobile.phone){
	    		$('.equal').removeClass('equal');
	    	}

	    	$('[data-toggle="tooltip"]').tooltip();

			$('.datetimepicker').datetimepicker({
				format            : 'YYYY-MM-DD HH:mm:ss',
				showClear         : true,
				ignoreReadonly    : true
			});
			$('.datepicker').datetimepicker({
				format            : 'YYYY-MM-DD',
				showClear         : true,
				ignoreReadonly    : true
			});
			onClickWrap('.print',function(event,tis){
				var el = tis.data('element');
				$('#modalItemsTable .select2').hide();
				$(el).print();
				$('#modalItemsTable .select2').show();
			});



			submitForm('#editSale',function(tis,result){
				if(result){
					$.get(tableOps.rawUrl + '&part=1&singleRow=' + result,function(data){
						oTable.row('.editting').remove().draw();
						$('#modalLarge').modal('hide');
						if(data){
							oTable.row.add($(data)).draw();
						}
					});
				}
			});

		});

		$('#modalTiny').off('shown.bs.modal').on('shown.bs.modal', function() {
			submitForm('#addPaymentForm',function(tis,result){
				if(result){
					$.get(tableOps.rawUrl + '&part=1&singleRow=' + result,function(data){
						oTable.row('.editting').remove().draw();
						if(data){
							oTable.row.add($(data)).draw();
							window.cobrosTableOpen = true;
						}
						$('#modalTiny').modal('hide');
					});
				}
			});
		});

		onClickWrap('.cancelItemView',function(event,tis){
			$('#modalLarge').modal('hide');
		});

		onClickWrap('#rg90',function(event,tis){
			var url = baseUrl + '?action=rg90&from=' + dateFrom + '&to=' + endDate;
			console.log(url);
			window.open(url);
		});

		onClickWrap('.addPayment',function(event,tis){
			var id = tis.data('id');
			$('.editting').removeClass('editting');
			tis.closest('tr').addClass('editting');
			loadForm(baseUrl + '?action=paymentForm&id=' + id,'#modalTiny .modal-content',function(){
				$('#modalTiny').modal('show');
				masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
				$('.datetimepicker').datetimepicker({
					format            : 'YYYY-MM-DD HH:mm:ss',
					showClear         : true,
					ignoreReadonly    : true
				});
				$('#payAmountField').focus();
			});
		});

		onClickWrap('.deleteTransaction',function(event,tis){
			var url        = tis.attr('href');
			var payment    = tis.data('payment');
			var $row       = $('.editting');
			ncmDialogs.confirm("¿Desea eliminar esta transacción?",'','question',function(r){
				if (r) {
				   $.get(url, function( data ) {
						if(data.success){
							message('Transacción eliminada','success');
		                    if(!payment){
		  						oTable.row($row).remove().draw();
		                    }else{
		                      $.get(tableOps.rawUrl + '&part=1&singleRow=' + $row.data('id'),function(data){
		                        oTable.row($row).remove().draw();
		                        if(data){
			                        oTable.row.add($(data)).draw();
			                    }
		                      });
		                    }
							$('#modalLarge').modal('hide');
						}else{
							message('Error, no se pudo eliminar','danger');
						}
					});
				}
			});
			
		});

		onClickWrap('#reportDownload',function(event,tis){
			var url = tableOps.rawUrl + '&download-report=true&from='+dateFrom+'&to='+endDate;
			window.open(url);
		});
	};

	//Tabla de cobros
	window.cobrosTableOpen = false;
	$('#cobrosTab').on('shown.bs.tab', function (e) {
	    if(!window.cobrosTableOpen){
	    	var rawUrl2 	= baseUrl + "?action=cobrosTable";
			var loadUrl2 	= rawUrl2 + "&sale=" + saleType + "&supId=" + ci;
	    	$.get(loadUrl2,function(result){
	    		window.info3 = {
					"container" 	: "#cobrosTable",
					"url" 			: loadUrl2,
					"rawUrl" 		: rawUrl2,
					"table" 		: ".table2",
					"iniData" 		: result.table,
					"sort" 			: 0,
					"footerSumCol" 	: [7],
					"currency" 		: currency,
					"decimal" 		: decimal,
					"thousand" 		: thousandSeparator,
					"offset" 		: offset,
					"limit" 		: limit,
					"nolimit" 		: true,
					"noMoreBtn" 	: noMoreBtn,
					"tableName" 	: 'tablePayments',
					"fileTitle" 	: 'Pagos a proveedores',
					"ncmTools"		: {
										left 	: '',
										right 	: ''
									  },
					"clickCB" 		: function(event,tis){
									  	return theClickRow(event,tis);
			  						}
				};

				ncmDataTables(window.info3,function(oTable,_scope){
					loadTheTable2(window.info3,oTable,_scope);
				});
			});
			
			var loadTheTable2 = function(tableOps,oTable,_scope){
				onClickWrap('.deletePayment',function(event,tis){
					var url        = tis.attr('href');
					var $row       = tis.closest('tr');

					ncmDialogs.confirm("¿Desea eliminar este pago?",'','question',function(r){
						if (r == true) {
						   $.get(url, function( data ) {
								if(data == 'true'){
									message('Pago eliminado.','success');
				                    oTable.row($row).remove().draw();
								}else{
									message('Error, no pudimos eliminar el pago','danger');
								}
							});
						}
					});
				});

				var timout 		= false;
			    var srcValCache = '';
			    $('#paymentTableSearch').off('keyup').on('keyup',function(e){
			    	var $tis 	= $(this);
			    	var value 	= $tis.val();
			    	var code 	= e.keyCode || e.which;

			    	if(code == 13) { //Enter keycode
				    	if(value.length > 3){
				    		if(!$.trim(value) || srcValCache == value){
				    			return false;
				    		}
				    		
			    			spinner(tableOps.container, 'show');
			    			$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1',function(result){
			    				oTable.rows().remove();
			    				if(result){
			    					var line 	= explodes('[@]',result);
			    					$.each(line,function(i,data){
			    						if(data){
			                    			oTable.row.add($(data));
			                    		}
			    					});
			    				}

			    				oTable.draw();

			    				$('.lodMoreBtnHolder').addClass('hidden');
			    				spinner(tableOps.container, 'hide');
				    		});
				    		

				    		srcValCache = value;

				    	}else if(value.length < 1 || !value){
				    		srcValCache = '';
			    			manageTableLoad(tableOps,function(oTable){
								loadTheTable2(tableOps,oTable);
							});
				    	}
				    }
			    });

			};

			window.cobrosTableOpen = true;
		}
	});

	window.detailTableOpen = false;
	$('#detailTab').on('shown.bs.tab', function (e) {
	    if(!window.detailTableOpen){

	    	var rawUrl3 	= baseUrl + "?action=detailTable";
			var loadUrl3 	= rawUrl3 + "&itmId=" + ii + "&supId=" + ci;

			$.get(loadUrl3,function(result){
				var options = {
								"container" 	: "#detailTable",
								"url" 			: loadUrl3,
								"rawUrl" 		: rawUrl3,
								"iniData" 		: result.table,
								"table" 		: ".table3",
								"sort" 			: 4,
								"footerSumCol" 	: [7,8,9,10],
								"currency" 		: currency,
								"decimal" 		: decimal,
								"thousand" 		: thousandSeparator,
								"offset" 		: offset,
								"limit" 		: limit,
								"noMoreBtn" 	: noMoreBtn,
								"nolimit" 		: true,
								"tableName" 	: 'tableDetail',
								"fileTitle" 	: 'Detalle de compras',
								"ncmTools"		: {
													left 	: '',
													right 	: ''
												  },
								"colsFilter"	: {
													name 		: 'reportDetails1',
													menu 		:  [
																		{"index":0,"name":'Sucursal',"visible":false},
																		{"index":1,"name":'# Documento',"visible":true},
																		{"index":2,"name":'Usuario',"visible":false},
																		{"index":3,"name":'Proveedor',"visible":true},
																		{"index":4,"name":'Fecha',"visible":true},
																		{"index":5,"name":'Artículo',"visible":true},
																		{"index":6,"name":'Categoría',"visible":false},
																		{"index":7,"name":'Cantidad',"visible":true},
																		{"index":8,"name":'Costo',"visible":false},
																		{"index":9,"name":taxName,"visible":false},
																		{"index":10,"name":'Total',"visible":true}
																	]
												  },
								"clickCB" 		: function(event,tis){
								  	return theClickRow(event,tis);
		  						}
							};

				ncmDataTables(options,function(oTable,_scope){	
				});

				window.detailTableOpen = true;
			});
		}
	});

	onClickWrap('.print',function(event,tis){
		var el = tis.data('element');
		$(el).print();
	});					

});

<?php
//  $script = ob_gets_contents();
//  minifyJS([$script => 'scripts' . $baseUrl . '.js']);
//}
?>
</script>
<!--<script src="scripts<?=$baseUrl?>.js?<?=date('d.i')?>"></script>-->
	
</body>
</html>
<?php
include_once('includes/compression_end.php');
dai();
?>