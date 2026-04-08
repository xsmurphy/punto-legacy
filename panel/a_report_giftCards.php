<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
topHook();
allowUser('sales','view');

$baseUrl = '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc = getROC(1);

$maxItemsInGraph 	= 30;
$isdata 			= false;
$offsetDetail		= 0;
$limitDetail		= 100;
$jsonResult 		= [];


if(validateHttp('action') == 'delete' && validateHttp('id')){
	$id = dec(validateBool('id'));
	$delete = $db->Execute('DELETE FROM giftCardSold WHERE giftCardSoldId = ? AND companyId = ?', array($id,COMPANY_ID));
	if($delete === false){
		dai('false');
	}else{
		dai('true');
	}
}

if(validateHttp('action') == 'giftcard' && validateHttp('id')){
	$id 	= dec(validateHttp('id'));
	$result = ncmExecute('SELECT * FROM giftCardSold WHERE giftCardSoldId = ? AND companyId = ? LIMIT 1',[$id,COMPANY_ID]);

	if($result){
		$benefName 		= getValue('contact','contactName','WHERE type = 1 AND contactId = ' . $result['giftCardSoldBeneficiaryId']);
		$transaction 	= ncmExecute('SELECT invoiceNo, invoicePrefix FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[$result['transactionId'],COMPANY_ID]);

		$internalCode 	= $result['giftCardSoldCode'];
		$amount 		= formatCurrentNumber($result['giftCardSoldValue']);
		$expires 		= $result['giftCardSoldExpires'];
		$status 		= $result['giftCardSoldStatus'];
		$note 			= isBase64Decode($result['giftCardSoldNote']);
		$sendDate 		= $result['giftCardSoldSendDate'];
		$benefNote 		= $result['giftCardSoldBeneficiaryNote'];
		$color 			= $result['giftCardSoldColor'];
		$uid 			= $result['timestamp'];

	?>

	 <div class="modal-body no-padder clear r-24x">
	    <form action="<?=$baseUrl?>?action=update" method="POST" id="editSale" name="editSale">
	    	<div class="col-xs-12 wrapper bg-white">
    			
    			<div class="col-sm-6 m-b-md">
					<span class="block text-xs text-u-c font-bold"># Documento</span> 
					<div class="h3 font-bold"><?=$transaction['invoicePrefix']?><?=iftn($transaction['invoiceNo'],'-')?></div>
				</div>

				<div class="col-sm-6 m-b-md">
					<div class="font-bold text-u-c text-xs">Código</div>
	    			<input type="text" name="code" class="form-control text-right no-border b-b maskInteger" value="<?=$internalCode?>">	    			
	    		</div>

				<div class="col-sm-6 m-b-md">
					<span class="font-bold text-u-c text-xs">Vencimiento</span>
					<input type="text" class="form-control datepicker no-border bg-light text-center rounded pointer" name="duedate" value="<?=$expires?>" autocomplete="off" readonly />
            	</div>
	            <div class="col-sm-6 m-b-md">
	            	<span class="font-bold text-u-c text-xs">Fecha de envío</span>
					<input type="text" class="form-control datepicker no-border bg-light text-center rounded pointer" name="senddate" value="<?=$sendDate?>" autocomplete="off" readonly />
	            </div>

	            <div class="col-sm-6 m-b-lg">
	            	<span class="font-bold text-u-c text-xs">Beneficiario</span>
					<select name="customer" class="form-control chosen-select">
						<?php
						if(validity($result['giftCardSoldBeneficiaryId'])){
						?>
							<option value="<?=enc($result['giftCardSoldBeneficiaryId'])?>" selected><?=$benefName;?></option>
						<?php
						}
						?>
					</select>
				</div>
    			
    			<div class="col-sm-6 m-b">
    				<span class="block text-xs text-u-c font-bold">Saldo</span> 
    				<input type="text" name="credit" class="form-control no-border b-b font-bold text-right maskCurrency" value="<?=$amount?>">
    			</div>

    			<div class="col-xs-12 m-b text-center wrapper">
    				<span class="block text-xs text-u-c font-bold">Código Único</span> 
    				<div class="col-xs-12 wrapper-sm rounded bg-light lt font-bold h2"><?=chunk_split($uid, 4, ' ')?></div>
    			</div>

    			<div class="col-xs-12 m-b-lg wrapper">
    				<span class="block text-xs text-u-c font-bold">Descripción</span> 
    				<textarea name="note" class="form-control"><?=$note?></textarea>
    			</div>
    		</div>

    		<?php
    		if(isInvoiceEditable()){
    		?>
    		<div class="col-xs-12 wrapper bg-light lter">
	    		<button class="btn btn-info btn-lg btn-rounded font-bold text-u-c pull-right">Guardar</button>
	    		<a href="#" class="m-t cancelItemView m-r-lg pull-right">Cerrar</a>
					    
			    <a href="<?=$baseUrl?>?action=delete" data-id="<?=enc($result['giftCardSoldId']);?>" class="m-t m-r pull-left delete">
			    	<span class="text-danger">Eliminar</span>
			    </a>
			    <input type="hidden" name="id" value="<?=enc($result['giftCardSoldId']);?>">				    
	    	</div>
	    	<?php
	    	}
	    	?>
    	</form>
    </div>

	<?php
	}

	dai();
}

if(validateHttp('action') == 'update' && validateHttp('id','post')){
	$record 								= [];
	$record['giftCardSoldCode'] 			= formatNumberToInsertDB($_POST['code']);
	$record['giftCardSoldValue'] 			= formatNumberToInsertDB($_POST['credit']);
	$record['giftCardSoldExpires'] 			= $_POST['duedate'];
	$record['giftCardSoldNote'] 			= $_POST['note'];
	$record['giftCardSoldSendDate'] 		= $_POST['senddate'];
	//$record['giftCardSoldBeneficiaryNote'] 	= $_POST['date'];
	$record['giftCardSoldBeneficiaryId'] 	= dec($_POST['customer']);

	$update = $db->AutoExecute('giftCardSold', $record, 'UPDATE', 'giftCardSoldId = '.$db->Prepare(dec($_POST['id']))); 
	if($update === false){
		echo 'false';
	}else{
		echo 'true|0|'.$_POST['id'];
	}
	dai();
}

if(validateHttp('action') == 'detailTable'){

	$limits = getTableLimits($limitDetail,$offsetDetail);
	if(validateHttp('singleRow')){
		$singleRow = ' AND giftCardSoldId = ' . $db->Prepare(dec(validateHttp('singleRow')));
	}

	$sql = "SELECT 
				*
			FROM giftCardSold
			WHERE transactionId 
			IS NOT NULL " . 
			$roc . 
			$singleRow . 
			$limits;

	$result 	= ncmExecute($sql,[],false,true);
	$table 		= '';
	$head = '	<thead class="text-u-c">'.
			 '		<tr>'.
			 '			<th></th>'.
			 '			<th># Documento</th>'.
			 '			<th>Beneficiario</th>'.
			 '			<th>Vencimiento</th>'.
			 '			<th>Código</th>'.
			 '			<th>Código Único</th>'.
			 '			<th>Nota</th>'.
			 '			<th>Último uso</th>'.
			 '			<th>Envío</th>'.
			 '			<th>Sucursal</th>'.
			 '			<th>Saldo</th>'. 
			 '		</tr>'.
			 '	</thead>'.
			 '<tbody>';

	$expiredCount 			= 0;
	$soonExpiredCount 		= 0;
	$noCreditCount 			= 0;
	$availableCount 		= 0;
	$availableValue 		= 0;

	if($result){
	
		while (!$result->EOF) {

			$customer = getContactData($result->fields['giftCardSoldBeneficiaryId'], 'uid');

			if(strtotime($result->fields['giftCardSoldExpires']) < strtotime(TODAY)){
				$expiredCount++;
			}else{
				if($result->fields['giftCardSoldValue'] > 0){
					$availableCount++;
					$availableValue += $result->fields['giftCardSoldValue'];
				}
			}

			if($result->fields['giftCardSoldValue'] == 0){
				$noCreditCount++;
			}

			if(strtotime($result->fields['giftCardSoldExpires']) < strtotime('1 week') && strtotime($result->fields['giftCardSoldExpires'])>strtotime(TODAY)){
				$soonExpiredCount++;
			}

			$itemId 	= enc($result->fields['transactionId']);

			$benefName 	= $customer['name'];
			$outlet 	= $allOutletsArray[$result->fields['outletId']]['name'];
			$expires	= iftn($result->fields['giftCardSoldExpires'],'-',niceDate($result->fields['giftCardSoldExpires']));
			$sendDate	= iftn($result->fields['giftCardSoldSendDate'],'-',niceDate($result->fields['giftCardSoldSendDate']));
			$lastUsed	= iftn($result->fields['giftCardSoldLastUsed'],'-',niceDate($result->fields['giftCardSoldLastUsed'],true));
			$color 		= iftn($result->fields['giftCardSoldColor'],'','color:#'.$result->fields['giftCardSoldColor']);
			$code 		= $result->fields['giftCardSoldCode'];
			$ucode 		= '<span class="badge">'.chunk_split($result->fields['timestamp'], 4, ' ').'</span>';
			$note 		= isBase64Decode($result->fields['giftCardSoldNote']);
			$saldo 		= $result->fields['giftCardSoldValue'];
			//$giftUrl 	= '/screens/giftCardRedeem?s='.base64_encode($result->fields['timestamp'].','.enc(COMPANY_ID));
			$giftUrl 	= $baseUrl . '?action=giftcard&id=' . enc($result->fields['giftCardSoldId']);
			$doc 		= getValue('transaction', 'invoiceNo', 'WHERE transactionId = ' . $result->fields['transactionId']);

			if(validity($doc)){
				$prefix		= getValue('transaction', 'invoicePrefix', 'WHERE transactionId = ' . $result->fields['transactionId']);
				$doc 		= $prefix . $doc;
			}else{
				$doc 		= '-';
			}
			
			
			$table .= 	'<tr class="clickrow pointer" data-load="'.$giftUrl.'">'.
							'<td>'.
							'	<i class="material-icons" style="'.$color.'">card_giftcard</i>'.
							'</td>'.
							'<td>' . $doc . '</td>'. 
							'<td>'.iftn($benefName,'-').'</td>'. 
							'<td data-order="'.$result->fields['giftCardSoldExpires'].'">'.$expires.'</td>'.
							'<td>'.$code.'</td>'.
							'<td>'.$ucode.'</td>'.
							'<td>'.mb_convert_encoding($note,'UTF-8', 'ISO-8859-1').'</td>'.
							'<td data-order="'.$result->fields['giftCardSoldLastUsed'].'">'.$lastUsed.'</td>'.
							'<td data-order="'.$result->fields['giftCardSoldSendDate'].'">'.$sendDate.'</td>'.
							'<td>'.$outlet.'</td>'.
							'<td class="text-right bg-light lter" data-order="'.$saldo.'">'.formatCurrentNumber($saldo).'</td>'.
						'</tr>';		


			if(validateHttp('part') && !validateHttp('singleRow')){
	        	$table .= '[@]';
	        }

			$result->MoveNext();



			$x++;
		}
		$isdata = true;
	}

	$foot = '</tbody>'.

			  '<tfoot>'.
				  '	<tr>'.
				  '		<th colspan="10">TOTALES:</th>'.
				  '		<th class="text-right"></th>'.
				  '	</tr>'.
			  '</tfoot>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] 	= $fullTable;
		// echo $fullTable;
		// die();
		$jsonResult['sumary'] 	= 	[	
										'expiredCount' 		=> $expiredCount,
										'soonExpiredCount' 	=> $soonExpiredCount,
										'noCreditCount'		=> $noCreditCount,
										'availableCount' 	=> $availableCount,
										'availableValue' 	=> formatCurrentNumber($availableValue)
									];
		// jsonDieResult($jsonResult);
		header('Content-Type: application/json'); 
		echo $result = json_encode($jsonResult);
		// if ($result === false) {
		// 	$json_error_code = json_last_error();
		// 	$json_error_message = json_last_error_msg();
		// 	echo "Error al codificar a JSON. Código de error: $json_error_code. Mensaje de error: $json_error_message";
		// }
		die();
	}

}



?>

	<?=headerPrint();?>
    
	  	<div class="col-xs-12 no-padder">
			<div class="col-xs-12 no-padder m-b">
				<div class="col-sm-4 m-t-sm">
				
				</div>
				<div class="col-sm-8 m-t-sm">
					<div class="pull-right">
						<h1 class="no-padder m-n font-bold" id="pageTitle">Gift Cards activadas</h1>
					</div>
				</div>
			</div>
		</div>

	  	<div class="col-xs-12 no-padder text-left m-b hidden-print">
	  		
	  		<section class="col-lg-3 col-md-6 col-xs-12">
				<div class="r-24x wrapper bg-white m-b" id="totalOutcome">
					<div class="text-u-c">
						Vencidas
						<a href="#" class="pull-right hidden">
							<i class="material-icons md-24">keyboard_arrow_right</i>
						</a>
					</div>
					<div class="h1 font-bold expired">
						<?=placeHolderLoader()?>
					</div>
				</div>
	        </section>
	  		<section class="col-lg-3 col-md-6 col-xs-12">
	  			<div class="r-24x wrapper bg-white m-b" id="totalOutcome">
					<div class="text-u-c">
						Por Vencer
						<a href="#" class="pull-right hidden">
							<i class="material-icons md-24">keyboard_arrow_right</i>
						</a>
					</div>
					<div class="h1 font-bold soon">
						<?=placeHolderLoader()?>
					</div>
				</div>
	        </section>
	  		<section class="col-lg-3 col-md-6 col-xs-12">
	  			<div class="r-24x wrapper bg-white m-b" id="totalOutcome">
					<div class="text-u-c">
						Canjeadas
						<a href="#" class="pull-right hidden">
							<i class="material-icons md-24">keyboard_arrow_right</i>
						</a>
					</div>
					<div class="h1 font-bold nocredit">
						<?=placeHolderLoader()?>
					</div>
				</div>
	        </section>
	        <section class="col-lg-3 col-md-6 col-xs-12">
	        	<div class="r-24x wrapper bg-white m-b" id="totalOutcome">
					<div class="text-u-c">
						<span class="available font-bold"></span> Vigentes por un valor de
						<a href="javascript:;" class="pull-right hidden">
							
						</a>
					</div>
					<div class="h1 font-bold">
						<span class="text-muted"><?=CURRENCY?></span>
						<span class="availableValue"><?=placeHolderLoader()?></span>
					</div>
				</div>
	        </section>
	  	</div>

	  	<div class="col-xs-12 wrapper panel r-24x bg-white push-chat-down">
			<div id="tableContainer">
				<div class="table-responsive no-border">                                  	
				    <table class="table table1 col-xs-12 no-padder table-hover" id="tableGift">
				    	<?=placeHolderLoader('table')?>
				    </table>
				</div>
			</div>
	    </div>


<script>
$(document).ready(function(){
	var baseUrl = '<?=$baseUrl?>';
	var rawUrl 	= baseUrl + "?action=detailTable";
	var url = rawUrl + "<?=$_GET['rol']?'&rol='.$_GET['rol']:''?>";
	$.get(url,function(result){
		var info1 = {
					"container" 	: "#tableContainer",
					"url" 			: url,
					"rawUrl" 		: rawUrl,
					"table" 		: ".table1",
					"iniData" 		: result.table,
					"sort" 			: 2,
					"footerSumCol" 	: [10],
					"currency" 		: "<?=CURRENCY?>",
					"decimal" 		: decimal,
					"thousand" 		: thousandSeparator,
					"offset" 		: <?=$offsetDetail?>,
					"limit" 		: <?=$limitDetail?>,
					"nolimit" 		: true,
					"ncmTools"			: {
												left 	: 	'<a href="#" class="btn btn-default exportTable" data-table="tableGift" data-name="Gift Cards">Exportar Listado</a>',
												right 	: 	''
											  },
					"colsFilter"		: {
											name 	: 'reportGiftcards',
											menu 	:  [
															{"index":0,"name":"Color","visible":true},
															{"index":1,"name":"# Documento","visible":true},
															{"index":2,"name":"Beneficiario","visible":true},
															{"index":3,"name":'Vencimiento',"visible":false},
															{"index":4,"name":'Código',"visible":false},
															{"index":5,"name":'Código Único',"visible":true},
															{"index":6,"name":'Nota',"visible":false},
															{"index":7,"name":'Último uso',"visible":false},
															{"index":8,"name":'Envío',"visible":false},
															{"index":9,"name":'Sucursal',"visible":false},
															{"index":10,"name":'Saldo',"visible":true}
														]
										  }
		};

		manageTableLoad(info1,function(oTable){
			loadTheTable(info1,oTable);
		});

		var sumary = result.sumary;
		$('.expired').text(sumary.expiredCount);
		$('.soon').text(sumary.soonExpiredCount);
		$('.nocredit').text(sumary.noCreditCount);
		$('.available').text(sumary.availableCount);
		$('.availableValue').text(sumary.availableValue);

	});	

	var loadTheTable = function(tableOps,oTable){
		onClickWrap('.clickrow',function(event,tis){
			var load = tis.attr('data-load');
			$('.editting').removeClass('editting');
			tis.addClass('editting');
			loadForm(load,'#modalSmall .modal-content',function(){
				$('#modalSmall').modal('show');
			});
		},false,true);

		$('#modalSmall').off('shown.bs.modal').on('shown.bs.modal',function(){
			var where = '.loadCustomerInput';
			var match = $(where).data('match');
			select2Ajax({element:'.chosen-select',url:'/a_contacts?action=searchCustomerInputJson',type:'contact'});
			
			$('.datepicker').datetimepicker({
		    	format            : 'YYYY-MM-DD HH:mm:ss',
		        showClear         : true,
		    	ignoreReadonly    : true
		    });

		    masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
			masksCurrency($('.maskInteger'),thousandSeparator,'no');

			submitForm('#editSale',function(tis,result){
				if(result){
					$('#modalSmall').modal('hide');
					$.get(tableOps.url + '&part=1&singleRow=' + result,function(data){
						oTable.row('.editting').remove();
						oTable.row.add($(data));
						oTable.draw();
					});
				}
			});
		});

		onClickWrap('.cancelItemView',function(event,tis){
			$('#modalSmall').modal('hide');
		},false,true);

		onClickWrap('.delete',function(event,tis){
			var id = tis.data('id');
			var $row = $('.editting');

			confirmation("No podrá deshacer esta acción!", function (e) {
				if (e) {
					oTable.row($row).remove().draw();
					$('.modal').modal('hide');
					$.get(baseUrl + '?action=delete&id=' + id,function(result){
						if(result == 'true'){
							message('Eliminado','success');
						}else{
							message('No se pudo eliminar','danger');
						}
					});
				}
			});
		},false,true);

		<?php
		if(validateHttp('i')){
			$id 		= validateHttp('i');
			$findGift 	= ncmExecute('SELECT giftCardSoldId FROM giftCardSold WHERE giftCardSoldCode = ? AND companyId = ? LIMIT 1',[$id,COMPANY_ID]);

			if($findGift){
				$giftUrl 	= $baseUrl . '?action=giftcard&id=' . enc($findGift['giftCardSoldId']);
			?>
				var load = '<?=$giftUrl?>';
				$('.editting').removeClass('editting');
				$('[data-load="' + load + '"]').addClass('editting');
				loadForm(load,'#modalSmall .modal-content',function(){
					$('#modalSmall').modal('show');
				});
			<?php
			}
		}
		?>
	};
	
});

</script>

<?php
include_once('includes/compression_end.php');
dai();
?>