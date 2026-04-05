<?php
include_once('includes/top_includes.php');

topHook();
allowUser('settings','view');

$baseUrl = '/' . basename(__FILE__,'.php');

$_modules 		= ncmExecute("SELECT * FROM module WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$__modules 		= json_decode($_modules['moduleData'],true);

if(validateHttp('action') == 'update'){
	$field 	= validateHttp('t');

	if(validateHttp('s') === true || validateHttp('s') == 'true'){
		$value = 1;
	}else if(validateHttp('s') > 0){
		$value = validateHttp('s');
	}else{
		$value = 0;
	}
	if($value == "false"){
		$value = 0;
	}

	$record 				= [];
	$record[$field] 		= $value;
	$record['companyId'] 	= COMPANY_ID;

	$exists = ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

	$__modules 				= json_decode($exists['moduleData'],true);
	$__modules 				= is_array($__modules) ? $__modules : [];

	$__modules = CRUDArray([
								'action' 	=> 'update',
								'array' 	=> $__modules,
								'key' 		=> $field,
								'value'		=> ['status' => $value]
							]);


	$db->AutoExecute('module', ['moduleData' => json_encode($__modules)], 'UPDATE', 'companyId = ' . COMPANY_ID);

	if($exists){
		$add = $db->AutoExecute('module', $record, 'UPDATE', 'companyId = ' . COMPANY_ID);
	}else{
		$add = $db->AutoExecute('module', $record, 'INSERT');
	}

	if($add !== false){
		dai('true');
	}else{
		dai('false');
	}
}

if(validateHttp('action') == 'loyalty'){
	$record 				= [];

	if(validateHttp('loyaltyMin')){
		$record['loyaltyMin'] 	= formatNumberToInsertDB(validateHttp('loyaltyMin'));
	}

	if(validateHttp('loyaltyValue')){
		$record['loyaltyValue'] = formatNumberToInsertDB(validateHttp('loyaltyValue'));
	}

	$record['loyaltyData'] = json_encode([
								'customerVisible' 	=> validateHttp('customerVisible'),
								'value' 			=> formatNumberToInsertDB(validateHttp('loyaltyValue')),
								'min' 				=> formatNumberToInsertDB(validateHttp('loyaltyMin'))
								]);

	$update = $db->AutoExecute('module', $record, 'UPDATE', 'companyId = ' . COMPANY_ID);
	dai();
}

if(validateHttp('action') == 'tablesCount'){
	$record 				= [];

	if(validateHttp('count')){
		$record['tablesCount'] 	= intval(validateHttp('count'));
		$update = $db->AutoExecute('module', $record, 'UPDATE', 'companyId = ' . COMPANY_ID);
	}
	
	dai();
}

if(validateHttp('action') == 'mcal'){
	$__modules = is_array($__modules) ? $__modules : [];

	$__modules = CRUDArray([
								'action' 	=> 'update',
								'array' 	=> $__modules,
								'key' 		=> 'mcal',
								'value'		=> [
													'apiKey' 	=> validateHttp('apiKey'), 
													'contract' 	=> validateHttp('contract'),
													'outlets' 	=> explode(',', validateHttp('outlets'))
											  	]
							]);

	$update = ncmUpdate(['table' => 'module', 'records' => ['moduleData' => json_encode($__modules)], 'where' => 'companyId = ' . COMPANY_ID]);
	
	dai();
}

if(validateHttp('action') == 'orderAverageTime'){
	$record 				= [];

	if(validateHttp('count')){
		$record['orderAverageTime'] 	= intval(validateHttp('count'));
		$update = $db->AutoExecute('module', $record, 'UPDATE', 'companyId = ' . COMPANY_ID);
	}
	
	dai();
}

if(validateHttp('action') == 'feedbackQuestion'){
	$record 				= [];

	if(validateHttp('text')){
		$record['feedbackQuestion'] 	= validateHttp('text');
		$update = $db->AutoExecute('module', $record, 'UPDATE', 'companyId = ' . COMPANY_ID);
	}
	
	dai();
}

if(validateHttp('action') == 'spotify'){
	$record 				= [];

	if(validateHttp('spotifyUrl')){
		$record['spotifyUrl'] 	= validateHttp('spotifyUrl');
		$update = $db->AutoExecute('module', $record, 'UPDATE', 'companyId = ' . COMPANY_ID);
	}
	
	dai();
}

if(validateHttp('action') == 'digitalInvoice'){
	$record 				= [];

	if(validateHttp('template')){
		$old 					= ncmExecute('SELECT digitalInvoiceData FROM module WHERE COMPANY_ID = ?',[COMPANY_ID]);
		$data 					= json_decode($old['digitalInvoiceData'],true);
		$text 					= substr(validateHttp('template'),0,200);
		$text 					= markupt2HTML(['text' => $text, 'type' => 'HtM']);
		$data['template'] 		= $text;

		$record['digitalInvoiceData'] 	= json_encode($data);
		$update = ncmUpdate(['table' => 'module', 'records' => $record, 'where' => 'companyId = ' . COMPANY_ID]);
	}
	
	dai();
}

if(validateHttp('action') == 'crm'){
	$record 				= [];
	$data 					= json_decode(validateHttp('crmSet','post'),true);

	if($data){
		if(validInArray($__modules,'crm')){
			if(validInArray($__modules['crm'],'crmDontAutoSendDocsToCustomer')){
				$__modules['crm']['crmDontAutoSendDocsToCustomer'] = $data['crmDontAutoSendDocsToCustomer'];
			}else{
				$__modules['crm'] = ['crmDontAutoSendDocsToCustomer' => $data['crmDontAutoSendDocsToCustomer']];
			}
		}else{
			$__modules[] = ['crm' => ['crmDontAutoSendDocsToCustomer' => $data['crmDontAutoSendDocsToCustomer']]];
		}
				
		$update = ncmUpdate(['table' => 'module', 'records' => ['moduleData' => json_encode($__modules)], 'where' => 'companyId = ' . COMPANY_ID]);

		if($update !== false){
			jsonDieResult(['success'=>'updated']);
		}else{
			jsonDieResult(['error'=>$db->ErrorMsg()]);
		}
	}
	
	jsonDieResult(['error'=>'no data']);
}

if(validateHttp('action') == 'ecom'){
	$record 				= [];

	if(validateHttp('ecomSet','post')){
		$record['ecom_data'] 	= validateHttp('ecomSet','post');
		$update = $db->AutoExecute('module', $record, 'UPDATE', 'companyId = ' . COMPANY_ID);

		if($update !== false){
			jsonDieResult(['success'=>'updated']);
		}else{
			jsonDieResult(['error'=>$db->ErrorMsg()]);
		}
	}
	
	jsonDieResult(['error'=>'no data']);
}

if(validateHttp('action') == 'tusFacturas'){
	$record 				= [];
	$outlet 				= dec(validateHttp('oid'));
	$str 					= $db->Prepare(validateHttp('data'));

	$record['taxonomyName']  = $str;
	$record['taxonomyType']  = "tusFacturas";
	$record['outletId'] 	 = $outlet;
	$record['companyId'] 	 = COMPANY_ID;

	$is = ncmExecute('SELECT * FROM taxonomy WHERE taxonomyType = "tusFacturas" AND outletId = ?',[$outlet]);

	if($is){
		$db->AutoExecute('taxonomy', $record, 'UPDATE', 'outletId = ' . $outlet);
	}else{
		 $db->AutoExecute('taxonomy', $record, 'INSERT');
	}

	dai();
}

if(validateHttp('action') == 'loadModal'){
	if($_GET['type'] == 'ecom'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="col-xs-12 text-center">
				<img src="<?=companyLogo(70)?>" width="70" class="m-b img-circle">

				<div class="text-center">Ingrese a su sitio</div>
				<div class="col-xs-12 text-center h2 m-b font-bold">
					<a href="https://<?=$_cmpSettings['settingSlug']?>.encom.site" class="text-info"><?=$_cmpSettings['settingSlug']?>.encom.site</a>
				</div>
				<div class="col-xs-12 wrapper bg-light lter r-3x m-b text-center">
					El módulo e-commerce se encuentra aún en modo Beta, estamos abiertos a sugerencias para ir mejorando.
				</div>
			</div>

			<?php
			$ecomData = json_decode( stripslashes($_modules['ecom_data']) ?? [] ,true);
			
			foreach($ecomData['tiers'] ?? [] as $key => $value){
				if(empty($ecomData['tiers'][$key])){
					unset($ecomData['tiers'][$key]);
				}
			}
			// if(!is_array($ecomData['tiers']['tier1'])){
			// 	$ecomData['tiers']['tier1'] = [];
			// }
			// if(!is_array($ecomData['tiers']['tier2'])){
			// 	$ecomData['tiers']['tier2'] = [];
			// }
			// if(!is_array($ecomData['tiers']['tier3'])){
			// 	$ecomData['tiers']['tier3'] = [];
			// }
			?>

			<div class="col-xs-12 b-b m-b-md">
	            <h3 class="font-bold text-dark pull-left">Configuración</h3>
	        </div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Entregas</span>
            </div>
            <div class="col-xs-7 no-padder">
            	<?=selectInputGenerator(['dp'=>'Delivery o Pickup','d'=>'Solo Delivery','p'=>'Solo Pickup'],['match'=>$ecomData['handle'],'class'=>'ecom_handle no-border b-b'])?>
            </div>

            <div class="col-xs-12 b-t m-t m-b"></div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Métodos de pago</span>
            </div>
            <div class="col-xs-7 no-padder" data-type="<?=$ecomData['payments']?>">
            	<?=selectInputGenerator(['ccc'=>'Efectivo y Tarjetas','c'=>'Solo Efectivo','cc'=>'Solo Tarjetas'],['match'=>$ecomData['payments'],'class'=>'ecom_payments no-border b-b'])?>
            </div>

            <div class="col-xs-12 b-t m-t m-b"></div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Sucursal asociada</span>
            </div>
            <div class="col-xs-7 no-padder">
            	<?=selectInputOutlet(iftn($ecomData['outlet'],OUTLET_ID,dec($ecomData['outlet'])),false,'no-border b-b ecom_outlet','ecom_outlet',false,false,false,true, $ecomData['register'] ? dec($ecomData['register']) : false) ?>
            </div>

            <div class="col-xs-12 b-t m-t m-b"></div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Usuario asociado</span>
            </div>
            <div class="col-xs-7 no-padder <?=$ecomData['user']?>">
            	<?=selectInputUser(iftn($ecomData['user'],USER_ID,dec($ecomData['user'])),false,'no-border b-b ecom_user','ecom_user')?>
            </div>

            <div class="col-xs-12 b-t m-t m-b"></div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Orden mínima</span>
            </div>
            <div class="col-xs-7 no-padder <?=$ecomData['user']?>">
            	<input type="tel" name="ecom_minimum" class="form-control no-border b-b masksCurrency ecom_minimum" value="<?=$ecomData['minimum']?>">
            </div>

            <div class="col-xs-12 b-t m-t m-b"></div>

            <div class="col-xs-12 no-padder">
				<span class="font-bold text-u-c block m-t-sm">Descripción del sitio</span>
            	<input type="text" class="form-control no-border b-b ecomWelcome" value="<?=$ecomData['description']?>">
            </div>

            <div class="col-xs-5 no-padder m-t">
            	<span class="font-bold text-u-c block m-t-sm">Modo Menú</span>
            </div>
            <div class="col-xs-7 no-padder m-t">
            	<?=switchIn('ecom_menu',$ecomData['menuMode'])?>
            </div>

            <div class="col-xs-12 b-t m-t m-b"></div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Google Analytics</span>
            </div>
            <div class="col-xs-7 no-padder">
            	<input type="text" name="ecomGA" placeholder="UA-XXXXXX-X" class="form-control font-bold no-border b-b ecomGA" value="<?=$ecomData['ecomGA']?>">
            	<p>Cree una cuenta en <a href="https://analytics.google.com/" target="_blank">Google Analytics</a> e inserte su tracking code</p>
            </div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Meta pixel</span>
            </div>
            <div class="col-xs-7 no-padder">
            	<input type="text" name="ecomFPixel" placeholder="XXXXXXX" class="form-control font-bold no-border b-b ecomFPixel" value="<?=$ecomData['ecomFPixel']?>">
            	<p>Ingrese el pixel code de Meta</p>
            </div>

            <div class="col-xs-12 b-b m-b-md">
	            <h3 class="font-bold text-dark pull-left">Envíos</h3>
	        </div>

	        <div class="col-sm-3 col-xs-12 no-padder"></div>
	        <div class="col-sm-3 col-xs-12 no-padder font-bold">Distancia en KM</div>
	        <div class="col-sm-6 col-xs-12 no-padder font-bold">Artículo con costo de envio</div>

			<!-- <div id="shippingCostsDetails"> -->

			<div class="col-xs-12" id="shippingCostsDetails">
				<div class="list p-b">
					<?php foreach($ecomData['tiers'] ?? [] as $key => $value):?>
						<div class="itemShipping">
							<div class="col-xs-12 b-t m-t m-b"></div>
	
							<div class="col-sm-3 col-xs-12 no-padder">
								<span class="font-bold text-u-c block m-t-sm">Envio <?= str_replace("tier","",$key)?></span>
							</div>
							<div class="col-sm-3 col-xs-12 no-padder">
								<input type="text" name="tier<?= str_replace("tier","",$key)?>-km" placeholder="10" class="form-control units font-bold no-border b-b tier<?= str_replace("tier","",$key)?>-km" value="<?php echo $value['km'];?>">
							</div>
							<div class="col-sm-5 col-xs-12 no-padder">
								<select name="tier<?= str_replace("tier","",$key)?>-id" class="form-control bg-white no-border b-b tier<?= str_replace("tier","",$key)?>-id searchAjax" autocomplete="off">
								<?php
									if($value['id']):
										$itmData 	= getItemData(dec($value['id']));
										$name 		= $itmData['itemName'];
										$price 		= $itmData['itemPrice'];
								?>
										<option value="<?=$value['id']?>" data-price="<?=$price?>" selected><?=$name?></option>
								<?php
									endif;
								?>
								</select>
							</div>
							<div class="col-sm-1 col-xs-12 no-padder">
								<a href="#" class="removeItem"> <span class="material-icons text-danger">close</span> </a>
							</div>
						</div>
					<?php endforeach;?>
				</div>
				<a href="#" class="text-u-c font-bold addItemShippingDetail m-t text-right col-xs-12"><span class="text-info">Agregar</span></a>
			</div>
			<div class="col-xs-12 b-t m-t m-b"></div>

			<div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Horarios de Entrega</span>
            </div>
            <div class="col-xs-7 no-padder">
            	<?=switchIn('ecom_delivery_hours',$ecomData['deliveryHours'])?>
            </div>

            <div class="col-xs-12 b-t m-t m-b"></div>

			<div class="col-xs-12 <?= empty($ecomData['deliveryHours']) ? "hidden" : "" ?>" id="deliveryHoursDetails">
				<div class="list p-b">
					<?php foreach($ecomData['customTime'] ?? [] as $value):?>
						<div class="col-xs-12 no-padder item m-b-sm b-b">
							<div class="col-xs-8 wrap-b-sm">
								<input type="time" value="<?= $value['hour']?>" class="itemInputTime form-control no-border b-b no-bg"/>
							</div>
							<div class="col-xs-4 text-right wrap-b-sm m-t-sm">
								<a href="#" class="removeItem"> <span class="material-icons text-danger">close</span> </a>
							</div>
						</div>
					<?php endforeach;?>
				</div>
				<a href="#" class="text-u-c font-bold addItemHours m-t text-right col-xs-12"><span class="text-info">Agregar</span></a>
			</div>
            <div class="col-xs-12 b-t m-t m-b"></div>
			

            <div class="col-xs-12 b-b m-b-md">
	            <h3 class="font-bold text-dark pull-left">Apariencia</h3>
	        </div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Color principal</span>
            </div>
            <div class="col-xs-7 no-padder">
            	<div class="input-group m-b"> 
	            	<div class="input-group-btn <?=$ecomData['color']?>"> 
						<?php
						echo colorSelector(['class'=>'ecomColor','selected'=>$ecomData['color']]);
						?>
					</div>
					<input type="text" name="ecomColor" value="#<?=$ecomData['color']?>" class="form-control no-border b-b ecomColorField font-bold" placeholder="#4DD0E1">
				</div>
            </div>

            <div class="col-xs-12 b-t m-t m-b"></div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">DarkMode</span>
            </div>
            <div class="col-xs-7 no-padder">
            	<?=switchIn('ecom_dark',$ecomData['darkMode'])?>
            </div>

            <div class="col-xs-12 b-t m-t m-b"></div>

            <?php
            if(in_array(PLAN, [1,2,9,10])){
            ?>
            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">Banner Personalizado</span>
            </div>
            <div class="col-xs-7 no-padder">
            	<input type="text" name="ecom_selfbanner" placeholder="https://imgur.com/XXXX.jpg" class="form-control font-bold no-border b-b ecom_selfbanner" value="<?=$ecomData['selfbanner']?>">
            	<p>Ingrese el link a su banner personalizado, se recomienda una medida de 810x500</p>
            </div>
            <?php
        	}
            ?>

            <div class="col-xs-12 b-t m-t m-b"></div>

            <div class="col-xs-5 no-padder">
            	<span class="font-bold text-u-c">Banner <br><span class="font-normal text-default text-muted">Powered by <a href="https://unsplash.com/" target="_blank">unsplash.com</a></span></span>
            </div>
            <div class="col-xs-7 no-padder">
            	
				<input type="text" name="ecom_banner" placeholder="W6sqUYlJRiw" class="form-control font-bold no-border b-b ecom_banner" value="<?=$ecomData['banner']?>">
				
            </div>
            <div class="col-xs-12 text-center m-t">
				<img src="https://imgur.com/PVh9eMG.png" width="70%" class="m-b">
				<p>Ingrese a <a href="https://unsplash.com/">unsplash.com</a> busque una fotografía relacionada a su rubro y seleccione la que más se adecue a su negocio. Copie el ID desde la URL de la foto y peguelo en el campo de arriba.</p>
            </div>
		</div>
		<script>
			$('.modal .btn-colorselector').css('background-color','#<?=$ecomData['color']?>');
			$('.modal .ecomColor').colorselector("setColor","#<?=$ecomData['color']?>");
			masksCurrency($('.units'),thousandSeparator,'no');
			select2Ajax({
	          element :'.searchAjax',
	          url     :'/a_items?action=searchItemInputJson',
	          type    :'item',
	          onLoad  : function(el,container){
	          },
	          onChange  : function($el,data){
	          }
	        });
		</script>>
		<?php
	}else if($_GET['type'] == 'spotify'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="text-center m-b">
				<img src="https://imgur.com/07JLXmP.png" height="38">
			</div>
			<label class="text-xs font-bold text-u-c">Playlist ID</label>
			<div class="input-group m-b"> 
				<span class="input-group-addon font-bold">
					spotify.com/playlist/
				</span> 
				<input type="text" name="spotifyUrl" placeholder="5sTHqyG2DAwmTCopHXHRdz" class="form-control font-bold input-lg spotifyUrl" value="<?=$_modules['spotifyUrl']?>">
			</div>
			<div class="text-center m-t">
				<img src="https://imgur.com/lnUFrZv.png" width="100%" class="m-b">
				<p>Copie el ID desde la URL del playlist deseado y peguelo en el campo de arriba.</p>
			</div>
		</div>
		<?php
	}else if($_GET['type'] == 'dropbox'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="text-center m-b m-t-lg">
				<img src="https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/panel/images/dropbox.png" height="38">
			</div>
			
				<div class="text-center h3 DBConnected wrapper col-xs-12 <?=($_modules['dropboxToken'] ? '' : 'hidden')?>">Su cuenta ya está conectada a ENCOM</div>
			
				<div class="text-center m-t DBConnect <?=($_modules['dropboxToken'] ? 'hidden' : '')?>">
					<div class="col-xs-12">
						<a href="https://www.dropbox.com/oauth2/authorize?client_id=rxl1dgd24nrjixg&response_type=token&redirect_uri=https://panel.encom.app/thirdparty/dropbox/auth.php&state=<?=base64_encode( enc(COMPANY_ID) );?>&response_type=code" class="btn btn-rounded btn-info btn-lg text-u-c font-bold">Conectar</a>
					</div>

					<p class="col-xs-12 m-t">Ingrese a su cuenta de Dropbox para conectarla a ENCOM</p>
				</div>

			<script type="text/javascript">
				$(document).ready(function(){
					console.log("Hola que tal");
					<?php
					if($_modules['dropboxToken']){
					?>
	      			var post = {
									url 		: 'https://api.dropboxapi.com/2/check/user',
									data 		: '{"query": "yo"}',
									contentType : 'application/json',
									headers 	: { "Authorization": "Bearer <?=$_modules['dropboxToken'];?>" },
									success 	: function(data){
										$('.DBConnect').addClass('hidden');
										$('.DBConnected').removeClass('hidden');
									},
									fail 		: function(){
										$('.DBConnect').removeClass('hidden');
										$('.DBConnected').addClass('hidden');
									}
								};

	      			ncmHelpers.load(post);

	      			<?php
	      			}
	      			?>

				});
				
			</script>
		</div>
		<?php
	}else if($_GET['type'] == 'calendar'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="font-bold h1 text-dark b-b wrap-b">Agenda y Calendario</div>
			<div class="block text-muted m-t m-b">
				
			</div>
			<div class="h2 m-t-xs m-b-xs font-bold hidden"> Calendario externo </div>
			<div class="text-muted m-b hidden">Permite visualizar su calendario por separado </div> 
			<table class="table table-hover hidden">
			<?php
			$oarray = getAllOutlets();
			foreach($oarray as $name){
				$url = 'https://app.encom.app/schedule_calendar?s=' . base64_encode( enc(COMPANY_ID) . ',' . enc($name['id']) );
				?>
				<tr>
					<td class="no-padder">
						<a class="block wrapper-md text-md" target="_blank" href="<?=$url?>"> 
							Abrir Calendario de
							<span class="block h3 font-bold"> <?=$name['name']?> </span>
						</a> 
					</td>
					<td>
						<img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?=$url?>" class="pull-right">
					</td>
				</tr>
				<?php
			}
			?>
			</table>

			<div class="h2 m-t-xs m-b-xs font-bold"> Control de Asistencia </div>
			<div class="text-muted m-b">Control de acceso y verificación de deudas de clientes </div> 
			<table class="table table-hover">
			<?php
			$oarray = getAllOutlets();
			foreach($oarray as $name){
				$url = PUBLIC_URL.'/qrGenerator?s=' . base64_encode( enc(COMPANY_ID) . ',' . enc($name['id']) . ',inHouseAttendance' );
				?>
				<tr>
					<td class="no-padder">
						<a class="block wrapper-md text-md" target="_blank" href="<?=$url?>"> 
							Abrir Controlador de
							<span class="block h3 font-bold"> <?=$name['name']?> </span>
						</a> 
					</td>
					<td>
						<img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?=$url?>" class="pull-right">
					</td>
				</tr>
				<?php
			}
			?>
			</table>

		</div>
		<?php
		}else if($_GET['type'] == 'attendance'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="h2 m-t-xs m-b-xs font-bold"> Control de Asistencia </div>
			<div class="text-muted m-b">Controla las horas trabajadas de tu staff </div> 
			<table class="table table-hover">
			<?php
			$oarray = getAllOutlets();
			foreach($oarray as $name){
				$url = PUBLIC_URL.'/qrGenerator?s=' . base64_encode( enc(COMPANY_ID) . ',' . enc($name['id']) . ',userAttendance' );
				?>
				<tr>
					<td class="no-padder">
						<a class="block wrapper-md text-md" target="_blank" href="<?=$url?>"> 
							Abrir QR de
							<span class="block h3 font-bold"> <?=$name['name']?> </span>
						</a> 
					</td>
					<td>
						<img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?=$url?>" class="pull-right">
					</td>
				</tr>
				<?php
			}
			?>
			</table>
		</div>
		<?php
		}else if($_GET['type'] == 'priceCheck'){
			$url = PUBLIC_URL.'/priceChecker?s=' . base64_encode( enc(COMPANY_ID) );
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="h2 m-t-xs m-b-xs font-bold"> Verificador de Precios </div>
			<div class="text-muted m-b">Permite a tus clientes consultar precios físicamente desde tu tienda escaneando el código de barras de tus productos</div> 
			<table class="table table-hover">
				<tr>
					<td class="no-padder">
						<a class="block wrapper-md text-md" target="_blank" href="<?=$url?>"> 
							Abrir
							<span class="block h3 font-bold"> Verificador </span>
						</a> 
					</td>
					<td>
						<img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?=$url?>" class="pull-right">
					</td>
				</tr>
			</table>
		</div>
		<?php
		}else if($_GET['type'] == 'feedback'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="font-bold h1 text-dark b-b wrap-b">Feedback</div>
			<div class="col-xs-12 no-padder m-b-md bg-white">
				<h3 class="font-bold">Pregunta de calificación</h3>
				<textarea class="form-control feedbackQuestion" placeholder="Cómo calificaría su experiencia?" name="feedbackQuestion"><?=$_modules['feedbackQuestion']?></textarea>
			</div>
			<section class="r-3x col-xs-12 no-padder clear">
				
				<div class="h3 m-t-xs m-b-xs font-bold"> Feedback anónimo </div>
				<div class="text-muted m-b">Sus clientes podrán calificar su experiencia directamente desde su negocio abriendo este link en una tablet.</div> 
			
				<table class="table table-hover">
					<tbody>
					<?php
					$oarray = getAllOutlets();
					foreach($oarray as $name){
						$url = PUBLIC_URL.'/anonFeedback?s=' . base64_encode( enc(COMPANY_ID) . ',' . enc($name['id']) );
						?>
						<tr>
							<td>
								<a class="wrapper-md text-md block" target="_blank" href="<?=$url?>"> 
									Calificación para
									<span class="block h3 font-bold"> <?=$name['name']?> </span>
								</a>
							</td>
							<td>
								<img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?=$url?>" class="pull-right">
							</td>
						</tr> 
						<?php
					}
					?>
					</tbody>
				</table>
			</section>
		</div>
		<?php
		}else if($_GET['type'] == 'kds'){
		?>
		<div class="col-xs-12 wrapper bg-white panel m-n">
			<div class="font-bold h1 text-dark b-b wrap-b">Kitchen Display System</div>
			<div class="block text-muted m-t m-b">
				Olvidate de las comandas en papel, el KDS te permite tener un control total de tus pedidos.
			</div>
			<table class="table table-hover">
			<?php
			$oarray = getAllOutlets();
			foreach($oarray as $name){
				$url = PUBLIC_URL.'/kds?s=' . base64_encode( enc(COMPANY_ID) . ',' . enc($name['id']) );
				?>
				<tr>
					<td class="no-padder">
						<a class="block wrapper-md text-md" target="_blank" href="<?=$url?>"> 
							Abrir KDS de
							<span class="block h3 font-bold"> <?=$name['name']?> </span>
						</a> 
					</td>
					<td>
						<img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?=$url?>" class="pull-right">
					</td>
				</tr>
				<?php
			}
			?>
			</table>
		
		</div>

		<?php
		}else if($_GET['type'] == 'cds'){
		?>
		<div class="col-xs-12 wrapper bg-white panel m-n">
			<div class="font-bold h1 text-dark b-b wrap-b">Customer Display System</div>
			<div class="block text-muted m-t m-b">
				Comuníca a tus clientes cuando sus pedidos estén listos
			</div>
			<table class="table table-hover">
			<?php
			$oarray = getAllOutlets();
			foreach($oarray as $name){
				$url = PUBLIC_URL.'/cds?s=' . base64_encode( enc(COMPANY_ID) . ',' . enc($name['id']) );
				?>
				<tr>
					<td class="no-padder">
						<a class="block wrapper-md text-md" target="_blank" href="<?=$url?>"> 
							Abrir CDS de
							<span class="block h3 font-bold"> <?=$name['name']?> </span>
						</a> 
					</td>
					<td>
						<img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?=$url?>" class="pull-right">
					</td>
				</tr>
				<?php
			}
			?>
			</table>
		
		</div>

		<?php
		}else if($_GET['type'] == 'cos'){
		?>
		<div class="col-xs-12 wrapper bg-white panel m-n">
			<div class="font-bold h1 text-dark b-b wrap-b">Checkout Screen</div>
			<div class="block text-muted m-t m-b">
				Utiliza una tablet o smartphone para mostrar al cliente el detalle de la venta en tiempo real
			</div>
			<table class="table table-hover">
			<?php
			$oarray = getAllRegisters();
			foreach($oarray as $name){
				$url = PUBLIC_URL.'/checkoutScreen?s=' . base64_encode( enc(COMPANY_ID) . ',' . enc($name['id']) );
				?>
				<tr>
					<td class="no-padder">
						<a class="block wrapper-md text-md" target="_blank" href="<?=$url?>"> 
							Abrir Checkout Screen de
							<span class="block h3 font-bold"> <?=$name['name']?> </span>
						</a> 
					</td>
					<td>
						<img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?=$url?>" class="pull-right">
					</td>
				</tr>
				<?php
			}
			?>
			</table>
		
		</div>
		
		<?php
	}else if($_GET['type'] == 'loyalty'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="font-bold h1 text-dark b-b m-b wrap-b">Fidelización</div>

			<div class="col-xs-12 m-b text-center">Valor del Loyalty</div>
			<div class="col-xs-5 no-padder">
				<input type="text" class="form-control no-bg no-border b-b b-light text-right masksCurrency font-bold loyaltyMin" style="font-size: 2.8em; height:1.5em;" value="<?=formatCurrentNumber(iftn($_modules['loyaltyMin'],100))?>" autocomplete="off" />
			</div>
			<div class="col-xs-2 h1 text-center font-bold">
				=
			</div>
			<div class="col-xs-5 no-padder">
				<input type="text" class="form-control no-bg no-border b-b b-light text-right masksCurrency font-bold loyaltyValue" style="font-size: 2.8em; height:1.5em;" value="<?=formatCurrentNumber(iftn($_modules['loyaltyValue'],1))?>" autocomplete="off" />
			</div>
			<div class="col-xs-12 m-b m-t-sm text-center">
				Comprando por <strong><?=CURRENCY?><span><?=formatCurrentNumber(iftn($_modules['loyaltyMin'],100))?></span></strong> el cliente adquiere <strong><?=CURRENCY?><span><?=formatCurrentNumber(iftn($_modules['loyaltyValue'],1))?></span></strong> Loyalty
			</div>
		</div>
	<?php
	}else if($_GET['type'] == 'crm'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="font-bold h1 text-dark b-b m-b wrap-b">CRM</div>

			<div class="col-xs-8 no-padder">
            	<span class="font-bold text-u-c block m-t-sm">NO Enviar documentos al cliente automáticamente</span>
            </div>
            <div class="col-xs-4 text-right no-padder">
            	<?=switchIn( 'crmDontAutoSendDocsToCustomer', validInArray($__modules['crm'],'crmDontAutoSendDocsToCustomer') )?>
            </div>
		</div>
	<?php
	}else if($_GET['type'] == 'mcal'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="font-bold h1 text-dark b-b m-b wrap-b">Shopping Mariscal y Mariano</div>
			<p>
				Ingrese el número de contrato y la llave proveída por el representante del shopping
			</p>
			<label class="font-bold text-u-c">Nro. de contrato</label>
			<input type="text" name="mcalContract" class="form-control input-lg rounded text-center font-bold mcalContract m-b" value="<?=$__modules['mcal']['contract']?>" placeholder="C0000000693">
			<label class="font-bold text-u-c">Llave</label>
			<input type="text" name="mcalApiKey" class="form-control input-lg rounded text-center font-bold mcalApiKey m-b" value="<?=$__modules['mcal']['apiKey']?>">

			<label class="font-bold text-u-c">Sucursal</label>

			<table class="table table-hover" id="mcalOutletsChk">
			<?php
			$oarray = getAllOutlets();
			foreach($oarray as $name){
				$url = 'https://app.encom.app/schedule_calendar?s=' . base64_encode( enc(COMPANY_ID) . ',' . enc($name['id']) );
				?>
				<tr>
					<td class="no-padder font-bold">
						<?=$name['name']?>
					</td>
					<td>
						<?php
						$selected 	= '';
						$allOutlets = is_array($__modules['mcal']['outlets']) ? $__modules['mcal']['outlets'] : [];

						if(in_array(enc($name['id']), $allOutlets)){
							$selected = 'checked';
						}
						?>
						<input type="checkbox" name="mcalOutlets" class="mcalOutlets" value="<?=enc($name['id'])?>" <?=$selected?>>
					</td>
				</tr>
				<?php
			}
			?>
			</table>

		</div>
	<?php
	}else if($_GET['type'] == 'tables'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="font-bold h1 text-dark b-b m-b wrap-b">Mesas y Espacios</div>
			<label class="font-bold text-u-c">Cantidad de Mesas o Espacios</label>
			<input type="tel" name="tablesCount" class="form-control input-lg rounded text-center font-bold tablesCount" value="<?=$_modules['tablesCount']?>">
		</div>
	<?php
	}else if($_GET['type'] == 'ordersPanel'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="font-bold h1 text-dark b-b m-b wrap-b">Panel de Órdenes</div>
			<label class="font-bold text-u-c">Duración promedio de una orden en minnutos</label>
			<input type="tel" name="orderAverageTime" class="form-control input-lg rounded text-center font-bold orderAverageTime" value="<?=iftn($_modules['orderAverageTime'],'60')?>">
		</div>
	<?php
	}else if($_GET['type'] == 'digitalInvoice'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="font-bold h1 text-dark b-b wrap-b">Factura Virtual</div>
			<div class="col-xs-12 no-padder m-b-md bg-white">
				<!--<h3 class="font-bold">Datos personalizados</h3>
				<textarea class="form-control digitalInvoiceDisclousure" name="digitalInvoiceDisclousure"><?php
				$dIData = json_decode($_modules['digitalInvoiceData'],true);
				echo $dIData['disclousure'];
				?></textarea>-->

				<h3 class="font-bold">Plantilla de impresión</h3>
				<?php
				$templating = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'printTemplate' AND (companyId = ? OR companyId = 1) ORDER BY taxonomyName ASC",[COMPANY_ID],false,true);
				$custom 	= [];

				if($templating){
					while (!$templating->EOF) {
						$id 			= enc($templating->fields['taxonomyId']);
						$name 			= toUTF8($templating->fields['taxonomyName']);
						$custom[$id] 	= $name; 

						$templating->MoveNext(); 
					}
					$templating->Close();
				}

				echo selectInputGenerator($custom,['select' => true, 'match' => $dIData['template'], 'data' => 'id="digitalInvoiceTemplate"', 'name' => 'digitalInvoiceTemplate', 'class' => 'no-bg no-border b-b']);
				?>
			</div>

			<div class="col-xs-12">
				<div>
					Añada información relevante que aparecerá al pie de la factura virtual
				</div>
				<em>Antes de utilizar este módulo verifique con su contador</em>
			</div>
		</div>
		<?php
	}else if($_GET['type'] == 'newton'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="col-xs-12 text-center">
				<img src="https://imgur.com/Mxy97Pp.png" height="80">
			</div>
			<div class="text-center">
				
			</div>
			<div class="col-xs-12 wrapper">
				<label class="font-bold text-u-c m-t">API Token</label>
				<input type="text" class="form-control input-lg rounded font-bold newton_apitoken" value="<?=$_modules['newton_token']?>">
			</div>
		</div>
		<?php
	}else if($_GET['type'] == 'tusfacturas'){
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="col-xs-12 text-center">
				<img src="https://blobscdn.gitbook.com/v0/b/gitbook-28427.appspot.com/o/spaces%2F-LCB9rRBemGwnJNermQn%2Favatar.png?generation=1534535576731850&alt=media" height="80">
			</div>
			<div class="text-center">
				Registrate a <a href="https://www.tusfacturas.com.ar/" rel="nofollow" target="_blank">Tus Facturas</a> y completá los campos de abajo.
			</div>
			<?php
			foreach ($allOutletsArray as $key => $value) {
				$apiData 	= ncmExecute('SELECT * FROM taxonomy WHERE taxonomyType = "tusFacturas" AND outletId = ? AND sourceId IS NULL LIMIT 1',[$key]);
				$aToken 	= explodes(',', $apiData['taxonomyName'],false,0);
				$uToken 	= explodes(',', $apiData['taxonomyName'],false,1);
				$aKey 		= explodes(',', $apiData['taxonomyName'],false,2);
				$ptoVta 	= explodes(',', $apiData['taxonomyName'],false,3);
			?>
			<div class="col-xs-12 wrapper" id="TF<?=enc($key)?>">
				<div class="text-u-c font-bold"><?=$value['name']?></div>
				<label class="font-bold text-u-c m-t">API Token</label>
				<input type="text" class="form-control input-lg rounded font-bold tusFact" id="tusfacturas_apitoken<?=enc($key)?>" data-outlet="<?=enc($key)?>" value="<?=$aToken?>">
				<label class="font-bold text-u-c m-t">User Token</label>
				<input type="text" class="form-control input-lg rounded font-bold tusFact" id="tusfacturas_usertoken<?=enc($key)?>" data-outlet="<?=enc($key)?>" value="<?=$uToken?>">
				<div class="col-xs-6">
					<label class="font-bold text-u-c m-t">API Key</label>
					<input type="text" class="form-control input-lg rounded font-bold m-b-md tusFact" id="tusfacturas_apikey<?=enc($key)?>" data-outlet="<?=enc($key)?>" value="<?=$aKey?>">
				</div>
				<div class="col-xs-6">
					<label class="font-bold text-u-c m-t">Pto. Venta</label>
					<input type="text" class="form-control input-lg rounded font-bold m-b-md tusFact" id="tusfacturas_ptoventa<?=enc($key)?>" data-outlet="<?=enc($key)?>" value="<?=$ptoVta?>">
				</div>
			</div>
			<?php
			}
			?>
		</div>
		<?php
	}else if($_GET['type'] == 'osWidget'){
		?>
		<?php
		if(PLAN == 2){
			$apis = ncmExecute('SELECT accountId FROM company WHERE companyId = ?',[COMPANY_ID]);
		}
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="col-xs-12 text-center">
				<div class="text-u-c text-muted font-bold">Tus informes</div>
				<div class="h1 font-bold m-n">En tu home screen</div>
			</div>
			<div class="col-xs-12 wrapper text-center">
				<img src="https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/assets/images/osWidget.jpg" height="300px">
			</div>
			<div class="text-center">
				Copia el link y pegalo en la aplicación <a href="https://apps.apple.com/us/app/glimpse-2/id1524217845" target="_blank"> Glimpse</a>. <br>Necesitas iOS 14 en adelante <br>
				<a href="#" class="btn btn-info btn-rounded btn-md font-bold m-t-sm" data-toggle="tooltip" title="Clic para copiar el enlace" id="copyOsWidget">
					<?=PUBLIC_URL.'/osWidget?s=' . base64_encode(enc(COMPANY_ID)) ?>
				</a>
				<br>
				<a href="https://docs.encom.app/panel-de-control/modulos/widgets" class="m-t-md block">Guía para configurar tu Widget de ENCOM</a>
			</div>
		</div>
		<?php
	}else if($_GET['type'] == 'api'){
		?>
		<?php
		if(PLAN == 2){
			$apis = ncmExecute('SELECT accountId FROM company WHERE companyId = ?',[COMPANY_ID]);
		}
		?>
		<div class="col-xs-12 wrapper bg-white">
			<div class="font-bold h1 text-dark b-b m-b wrap-b">API</div>
			<div class="col-xs-12 wrapper">
				<label class="font-bold text-u-c">Company ID</label>
				<div class="wrapper-sm text-lg font-bold text-center bg-light rounded"><?=enc(COMPANY_ID)?></div>
			</div>
			<div class="col-xs-12 wrapper m-b">
				<label class="font-bold text-u-c">API Key</label>
				<div class="wrapper-sm text-lg font-bold text-center bg-light rounded"><?=getAPICreds(COMPANY_ID)?></div>
			</div>
		</div>
		<?php
	}
	dai();
}

function modBlock($ops){
	global $baseUrl;

	$out = 	'<div class="col-md-4 col-sm-6 col-xs-12 wrapper ' . ($ops['hidden'] ? 'hidden' : '') . '">' .
			'	<div class="col-xs-12 no-padder r-24x clear bg-white" style="min-height:180px;">' .
			'		<div class="text-left wrapper col-xs-12 b-b">' .
			'			<a href="#" class="text-right block settingModule ' . ( ($ops['active'] && !$ops['blocked'] && !$ops['noConf']) ? '' : 'disabled') . '" data-modal="' . $ops['id'] . 'Modal" id="' . $ops['id'] . 'Settings">' .
							( ($ops['isNew']) ? '<span class="badge bg-danger badge-lg pull-left">NUEVO</span>' : '' ) .
			'				<i class="material-icons text-muted">settings</i>' .
			'			</a>' .
			'			<div class="m-t h1 font-bold col-xs-12 no-padder text-dark">' .
							$ops['title'] .
			'			</div>' .
			'		</div>' .
			'		<div class="col-md-9 col-xs-12 wrapper">' .
						$ops['description'] .
			' 		</div>' .
			'		<div class="col-md-3 col-xs-12 wrapper text-right">';

			if(!$ops['blocked']){
				if($ops['type'] == 'input'){
					$out .=  '<input value="' . $ops['active'] . '" type="text" id="' . $ops['id'] . '" class="form-control input-lg no-border b-b text-right font-bold units" placeholder="1">';
				}else if($ops['type'] == 'btn'){

				}else{
					$out .=  switchIn($ops['id'],$ops['active']);
				}
			}
						 
			
	$out .= '		</div>' .
			'		<div id="' . $ops['id'] . 'Modal" class="hidden" data-load="' . $baseUrl . '?action=loadModal&type=' . $ops['id'] . '">' .
			'		</div>' .
			'	</div>' .
			'</div>';

	return $out;
}

?>

	<div class="col-xs-12 h1 font-bold m-b" id="pageTitle">Módulos</div>

	<div class="col-xs-12 no-padder">
		<div class="col-xs-12 h4 wrapper font-bold text-u-c text-dark b-b b-light">Destacados</div>

		<!-- ECOM -->
		<?=modBlock(['active' => $_modules['ecom'], 'id' => 'ecom', 'title' => 'eCommerce', 'description' => 'Lleva tu negocio a la web, un nuevo canal de ventas sincronizado a tu local físico']);?>
		<!-- ECOM -->

		<!-- Dropbox -->
		<?=modBlock(['active' => $_modules['dropbox'], 'id' => 'dropbox', 'title' => '<img src="https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/panel/images/dropbox.png" height="39">', 'description' => 'Almacena archivos y asocialos a clientes, cotizaciones y más.']);?>
		<!-- Dropbox -->

		<!-- osWidget -->
		<?=modBlock(['active' => 1, 'type' => 'btn', 'id' => 'osWidget', 'title' => 'Widget', 'description' => 'Añade tu reporte a los widgets de tu smartphone.']);?>
		<!-- osWidget -->

		<!-- Spotify -->
		<?=modBlock(['active' => $_modules['spotify'], 'id' => 'spotify', 'title' => '<img src="https://imgur.com/07JLXmP.png" height="39">', 'description' => 'Maneja la playlist de tu negocio y sucursales directamente desde ENCOM']);?>
		<!-- Spotify -->

		<!-- RECORDATORIOS -->
		<?=modBlock(['active' => 1, 'type' => 'btn', 'id' => 'attendance', 'title' => 'Control de Asistencia', 'description' => 'Lleva un control de las horas trabajada de tu staff']);?>
		<!-- RECORDATORIOS -->

		<!-- PRICE CHECK -->
		<?=modBlock(['active' => 1, 'type' => 'btn', 'id' => 'priceCheck', 'title' => 'Verificador de Precios', 'description' => 'Permite a tus clientes consultar precios']);?>
		<!-- PRICE CHECK -->

	</div>

	<div class="col-xs-12 no-padder">
		<div class="col-xs-12 h4 wrapper font-bold text-u-c text-dark b-b b-light">Marketing y Fidelización</div>
		<!-- LOYALTY -->
		<?=modBlock(['active' => $_modules['loyalty'], 'id' => 'loyalty', 'title' => 'Fidelización', 'description' => 'Premia a tus clientes más fieles']);?>
		<!-- LOYALTY -->

		<!-- FEEDBACK -->
		<?=modBlock(['active' => $_modules['feedback'], 'id' => 'feedback', 'title' => 'Feedback', 'description' => 'Haz que tus clientes califiquen su experiencia']);?>
		<!-- FEEDBACK -->

		<!-- CAMPAIGNS -->
		<?=modBlock(['hidden' => 1, 'blocked' => 1, 'active' => 0/*$_modules['campaigns']*/, 'id' => 'campaigns','class' => 'hidden', 'title' => 'Campañas masivas', 'description' => 'Envía promociones a todos tus clientes por email y SMS']);?>
		<!-- CAMPAIGNS -->

		<!-- CRM -->
		<?=modBlock(['active' => $__modules['crm'], 'id' => 'crm', 'title' => 'CRM', 'description' => 'Gestiona la relación con tus clientes']);?>
		<!-- CRM -->
	</div>
	
	<div class="col-xs-12 no-padder">
		<div class="col-xs-12 h4 wrapper font-bold text-u-c text-dark b-b b-light">Operativos</div>
		<!-- AGENDA -->
		<?=modBlock(['active' => $_modules['calendar'], 'id' => 'calendar', 'title' => 'Agenda y Calendario', 'description' => 'Gestiona citas y calendarios']);?>
		<!-- AGENDA -->

		<!-- MESAS -->
		<?=modBlock(['active' => $_modules['tables'], 'id' => 'tables', 'title' => 'Mesas y Espacios', 'description' => 'Gestiona mesas en tu restaurante']);?>
		<!-- MESAS -->

		<!-- PRODUCCION -->
		<?=modBlock(['noConf' => 1, 'active' => $_modules['production'], 'id' => 'production', 'title' => 'Producción', 'description' => 'Produce, gestiona recetas, mermas y compuestos']);?>
		<!-- PRODUCCION -->

		<!-- KDS -->
		<?=modBlock(['active' => $_modules['kds'], 'id' => 'kds', 'title' => 'Kitchen Display', 'description' => 'Olvidate de las impresiones, gestiona pedidos desde una pantalla']);?>
		<!-- KDS -->

		<!-- CDS -->
		<?=modBlock(['active' => $__modules['cds'], 'id' => 'cds', 'title' => 'Customer Display', 'description' => 'Comuníca a tus clientes cuando sus pedidos estén listos']);?>
		<!-- CDS -->

		<!-- COS -->
		<?=modBlock(['active' => $__modules['cos']['status'] ?? 0, 'id' => 'cos', 'title' => 'Checkout Screen', 'description' => 'Visor de venta para el cliente']);?>
		<!-- COS -->

		<!-- ORDERS -->
		<?=modBlock(['active' => $_modules['ordersPanel'], 'id' => 'ordersPanel', 'title' => 'Panel de Órdenes', 'description' => 'Gestiona todas tus órdenes desde un mismo lugar']);?>
		<!-- ORDERS -->

		<?php
		if(COUNTRY == 'PY'){
		?>
		<!-- MCAL -->
		<?=modBlock(['active' => $__modules['mcal'], 'id' => 'mcal', 'title' => 'Mariscal y Mariano', 'description' => 'Sincroniza tus ventas automáticamente con el sistema del shopping Mariscal y el shopping Mariano']);?>
		<!-- MCAL -->
		<?php
		}
		?>
	</div>

	<div class="col-xs-12 no-padder">
		<div class="col-xs-12 h4 wrapper font-bold text-u-c text-dark b-b b-light">Facturación</div>

		<!-- RECURRING -->
		<?=modBlock(['noConf' => 1, 'active' => $_modules['recurring'], 'id' => 'recurring', 'title' => 'Suscripciones', 'description' => 'Olvídate de generar suscripciones, mebresías o cuotas manualmente']);?>
		<!-- RECURRING -->

		<!-- COBRANZAS -->
		<?=modBlock(['noConf' => 1, 'active' => $_modules['dunning'], 'id' => 'dunning', 'title' => 'Dunning', 'description' => 'Realiza un seguimiento automatizado a clientes deudores por email y SMS']);?>
		<!-- COBRANZAS -->

		<!-- FACTURA DIGITAL -->
		<?=modBlock(['active' => $_modules['digitalInvoice'], 'id' => 'digitalInvoice', 'title' => 'Factura en PDF', 'description' => 'Emite facturas digitales en formato PDF']);?>
		<!-- TUS FACTURAS -->
		<?=modBlock(['active' => $_modules['tusfacturas'], 'id' => 'tusfacturas', 'title' => '<img src="https://imgur.com/mkWjmND.png" height="38">', 'description' => 'Facturación electrónica para Argentina por medio de TusFacturas']);?>
		<!-- TUS FACTURAS -->
	</div>

	<div class="col-xs-12 no-padder">
		<div class="col-xs-12 h4 wrapper font-bold text-u-c text-dark b-b b-light">Otros</div>
		<!-- EXTRA USERS 
		<?=modBlock(['noConf' => 1,'class' => 'hidden', 'type' => 'input', 'active' => 0, 'id' => 'extraUsers', 'title' => 'Usuarios Extra', 'description' => 'Necesitas más usuarios en tu empresa? <span class="label bg-light">$5/mes por usuario</span>']);?>
		EXTRA USERS -->

		<!-- RECORDATORIOS -->
		<?=modBlock(['hidden' => 1, 'blocked' => 1, 'active' => $_modules['reminder'], 'id' => 'reminder', 'title' => 'Recordatorios', 'description' => 'Aumenta la productividad con recordatorios personalizados']);?>
		<!-- RECORDATORIOS -->

		<!-- Reportes por email -->
		<?=modBlock(['noConf' => 1, 'active' => $_modules['salesSummaryDaily'], 'id' => 'salesSummaryDaily', 'title' => 'Reportes diarios', 'description' => 'Recibe a diario en tu email el rendimiento de tu negocio']);?>
		<!-- Reportes por email -->

		<!-- API -->
		<?=modBlock(['active' => ( (PLAN == 2) ? 1 : 0 ), 'id' => 'api', 'title' => 'API', 'description' => 'Conecta otras plataformas o sistemas a ENCOM']);?>
		<!-- API -->	
	</div>	

	<script src="https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/ncmDropbox.js"></script>

	<script>
		$(document).ready(function(){
			var baseUrl = '<?=$baseUrl?>';
			onClickWrap('.settingModule',function(event,tis){
				var modal 	= tis.data('modal');
				var $modal 	= $('#' + modal);
				var content = $modal.html();
				var load 	= $modal.data('load');

				if(tis.hasClass('disabled')){
					return false;
				}
				
				if(load){
					$.get(load,function(content){
						$('#modalSmall .modal-content').html(content);
						$('#modalSmall').modal('show');
					});
				}else{
					$('#modalSmall .modal-body').html(content);
					$('#modalSmall').modal('show');
				}				
			});			
			switchit(function(tis, active){
				var type 		= tis.attr('id');
				var $modal 		= $('#' + type + 'Modal');
				var $settings 	= $('#' + type + 'Settings');
				var value 		= 0;
				
				if($.inArray(type, ['loyalty','calendar','tusfacturas','spotify','attendance','priceCheck','dropbox','newton','ecom','mcal','ordersPanel','kds','cds','cos','tables','production','recurring','dunning','digitalInvoice','electronicInvoice','reminder','salesSummaryDaily','feedback','api','crm']) !== -1){
					if(active){
						$settings.removeClass('disabled');
						$settings.trigger('click');
						/*if($modal.length){
							$('#modalSmall .modal-content').html($modal.html());
							$('#modalSmall').modal('show');
							$settings.removeClass('disabled');
						}*/
					}else{
						$settings.addClass('disabled');
					}

					$.get(baseUrl + '?action=update&t=' + type + '&s=' + active,function(){
						message(active ? 'Activado' : 'Inhabilitado','success');
					});
				}else if(type == 'crmDontAutoSendDocsToCustomer'){
					processCRMData();
				}else if(type == 'ecom_dark' || type == 'ecom_menu' || type == 'ecom_delivery_hours'){
					processEcomData();
				}
				if(type == "ecom_delivery_hours"){
					$("#deliveryHoursDetails").removeClass("hidden")
					if(active){
						$("#deliveryHoursDetails").show()
						
						onClickWrap('#deliveryHoursDetails .addItemHours',function(event,tis){

							$("#deliveryHoursDetails .list").append(`
								<div class="col-xs-12 no-padder item m-b-sm b-b">
									<div class="col-xs-8 wrap-b-sm">
										<input type="time" value="" class="itemInputTime form-control no-border b-b no-bg"/>
									</div>
									<div class="col-xs-4 text-right wrap-b-sm m-t-sm">
										<a href="#" class="removeItem"> <span class="material-icons text-danger">close</span> </a>
									</div>
								</div>`
							);

							$("#deliveryHoursDetails .list .itemInputTime").off('change').on('change',function(){
								processEcomData();
							});

						});

					}else{
						$("#deliveryHoursDetails").hide()
					}
				}
			},true);

			$('#extraUsers').on('keyup',function(){
				var value = $(this).val();
				$.get(baseUrl + '?action=update&t=extraUsers&s=' + value,function(){
					message('Actualizado','success');
				});
			});

			$('body').on('shown.bs.modal', '.modal', function () {
				$('[data-toggle="tooltip"]').tooltip();
				masksCurrency($('.masksCurrency'),thousandSeparator,decimal);
				
				onClickWrap('#deliveryHoursDetails .addItemHours',function(event,tis){

					$("#deliveryHoursDetails .list").append(`
						<div class="col-xs-12 no-padder item m-b-sm b-b">
							<div class="col-xs-8 wrap-b-sm">
								<input type="time" value="" class="itemInputTime form-control no-border b-b no-bg"/>
							</div>
							<div class="col-xs-4 text-right wrap-b-sm m-t-sm">
								<a href="#" class="removeItem"> <span class="material-icons text-danger">close</span> </a>
							</div>
						</div>`
					);

					$("#deliveryHoursDetails .list .itemInputTime").off('change').on('change',function(){
						processEcomData();
					});

				});

				onClickWrap('#shippingCostsDetails .addItemShippingDetail', function(event, tis){
					let key = $("#shippingCostsDetails .list .itemShipping").length + 1;
					$("#shippingCostsDetails .list").append(`
						<div class="itemShipping">
							<div class="col-xs-12 b-t m-t m-b"></div>

							<div class="col-sm-3 col-xs-12 no-padder">
								<span class="font-bold text-u-c block m-t-sm">Envio ${key}</span>
							</div>
							<div class="col-sm-3 col-xs-12 no-padder">
								<input type="text" name="tier${key}-km" placeholder="10" class="form-control units font-bold no-border b-b tier${key}-km">
							</div>
							<div class="col-sm-5 col-xs-12 no-padder">
								<select name="tier${key}-id" class="form-control bg-white no-border b-b tier${key}-id searchAjax" autocomplete="off">
								
								</select>
							</div>
							<div class="col-sm-1 col-xs-12 no-padder">
								<a href="#" class="removeItem"> <span class="material-icons text-danger">close</span> </a>
							</div>
						</div>
					`);
					select2Ajax({
						element :'.searchAjax',
						url     :'/a_items?action=searchItemInputJson',
						type    :'item',
					onLoad  : function(el,container){
					},
					onChange  : function($el,data){
					}
					});
					$("#shippingCostsDetails .list .itemShipping input[type=text]").off('change').on('change',function(){
						processEcomData();
					});
					$("#shippingCostsDetails .list .itemShipping select").off('change').on('change',function(){
						processEcomData();
					});
				})
				$("#shippingCostsDetails .list .itemShipping input[type=text]").off('change').on('change',function(){
					processEcomData();
				});
				$("#shippingCostsDetails .list .itemShipping select").off('change').on('change',function(){
					processEcomData();
				});
				onClickWrap('#shippingCostsDetails .list .itemShipping .removeItem',function(event,tis){
					tis.parent().parent().remove();
					processEcomData();
				});

				$('input.loyaltyValue').off('keyup').on('keyup',function(){
					var loyaltyValue 	= $(this).val();
					$('.loyaltyValue').val(loyaltyValue);
					$.get(baseUrl + '?action=loyalty&loyaltyValue=' + loyaltyValue,function(){
						message('Actualizado','success');
					});
				});

				$('input.loyaltyMin').off('keyup').on('keyup',function(){
					var loyaltyMin 		= $(this).val();
					$('.loyaltyMin').val(loyaltyMin);
					console.log($('.loyaltyMin').val());
					$.get(baseUrl + '?action=loyalty&loyaltyMin=' + loyaltyMin,function(){
						message('Actualizado','success');
					});
				});

				$('input.spotifyUrl').off('change keyup').on('change keyup',function(){
					var spotifyUrl 		= $(this).val();
					$.get(baseUrl + '?action=spotify&spotifyUrl=' + spotifyUrl,function(){
						message('Actualizado','success');
					});
				});

				$("#deliveryHoursDetails .list .itemInputTime").off('change').on('change',function(){
					processEcomData();
				});

				onClickWrap('#deliveryHoursDetails .list .removeItem',function(event,tis){
					tis.parent().parent().remove();
					processEcomData();
				});

				$('input.tablesCount').off('change').on('change',function(){
					$.get(baseUrl + '?action=tablesCount&count=' + $(this).val(),function(){
						message('Actualizado','success');
					});
				});

				$('input.mcalContract, input.mcalApiKey, input.mcalOutlets').off('change').on('change',function(){
					var contract 	= $('input.mcalContract').val();
					var apiKey 		= $('input.mcalApiKey').val();
					var outlets  	= [];

					$('#mcalOutletsChk input[type=checkbox]:checked').each(function() {
						outlets.push($(this).val());
					});

					console.log('outlets', outlets);

					$.get(baseUrl + '?action=mcal&contract=' + contract + '&apiKey=' + apiKey + '&outlets=' + outlets.join(','), () => {
						message('Actualizado','success');
					});
				});

				$('input.orderAverageTime').off('change').on('change',function(){
					$.get(baseUrl + '?action=orderAverageTime&count=' + $(this).val(),function(){
						message('Actualizado','success');
					});
				});				

				$('textarea.feedbackQuestion').off('change keyup').on('change keyup',ncmHelpers.delayKeyUp(function (e) {
				  	$.get(baseUrl + '?action=feedbackQuestion&text=' + $(this).val(),function(){
						message('Actualizado','success');
					});
				}, 1000));

				$('select#digitalInvoiceTemplate').off('change').on('change',ncmHelpers.delayKeyUp(function (e) {
				  	$.get(baseUrl + '?action=digitalInvoice&template=' + $(this).val(),function(){
						message('Actualizado','success');
					});
				}, 1000));
				$('select#electronicInvoiceTemplate').off('change').on('change',ncmHelpers.delayKeyUp(function (e) {
				  	$.get(baseUrl + '?action=electronicInvoice&template=' + $(this).val(),function(){
						message('Actualizado','success');
					});
				}, 1000));

				$('input.tusFact').off('change').on('change',function(){
					var outlet 	= $(this).data('outlet');
					var aToken 	= $('.modal #tusfacturas_apitoken' + outlet).val();
					var uToken 	= $('.modal #tusfacturas_usertoken' + outlet).val();
					var aKey 	= $('.modal #tusfacturas_apikey' + outlet).val();
					var pTo 	= $('.modal #tusfacturas_ptoventa' + outlet).val();

					$.get(baseUrl + '?action=tusFacturas&data=' + [aToken,uToken,aKey,pTo].join(',') + '&oid=' + outlet ,function(){
						message('Actualizado','success');
					});
				});

				onClickWrap('#copyOsWidget',function(event,tis){
					tis.css('user-select','initial');
				  	ncmHelpers.copyTextToClipBoard(tis);
				  	tis.css('user-select','none');
				  	ncmDialogs.toast('Copiado');
				});

				//ECOM
				$('.modal select.ecomColor').off('change').on('change',function(){
					var ecomColor 		= $(this).val();

					if(ecomColor){
						$('.modal .ecomColorField').val('#' + ecomColor).trigger('change');
					}
				});

				$('.modal input.ecomColorField, .modal input.ecomWelcome, .modal input.ecom_banner, .modal input.ecom_selfbanner, .modal select.ecom_handle, .modal input.ecomGA, input.ecomFPixel, select.ecom_payments, select.ecom_outlet, select.ecom_user, input.tier1-km, input.tier2-km, input.tier3-km, select.tier1-id, select.tier2-id, select.tier3-id,input.ecom_minimum').off('change keyup').on('change keyup',function(){
					processEcomData();
				});
				
			});

			masksCurrency($('.units'),thousandSeparator,'no');
			masksCurrency($('.maskFloat3'),thousandSeparator,'yes',false,'3');

			var processCRMData = function(){
				var out = {};
				out.crmDontAutoSendDocsToCustomer = $('#crmDontAutoSendDocsToCustomer .crmDontAutoSendDocsToCustomerClass').val();
				$.post(baseUrl + '?action=crm',{'crmSet' : JSON.stringify(out) },function(){
					message('Actualizado','success');
				});
			};

			function processEcomData(){
				var color 		= $('.modal input.ecomColorField').val().replace("#", "");
				var banner 		= $('.modal input.ecom_banner').val();
				var selfbanner 	= $('.modal input.ecom_selfbanner').val();
				var welcome 	= $('.modal input.ecomWelcome').val();
				var handle 		= $('.modal select.ecom_handle').val();
				var payments 	= $('.modal select.ecom_payments').val();
				var outlet 		= $('.modal select.ecom_outlet').val();
				var register 	= $('.modal select.ecom_outlet option:selected').data('register');
				var user 		= $('.modal select.ecom_user').val();
				var ecomGA 		= $('.modal input.ecomGA').val();
				var ecomFPixel	= $('.modal input.ecomFPixel').val();
				var minimum		= unMaskCurrency($('.modal input.ecom_minimum').val(),thousandSeparator,decimal);
				let tiers = [];
				tiers = $("#shippingCostsDetails .list .itemShipping").map(function(index, element){
					return {
						[$(element).find('select').attr('name').split("-")[0]] : {
							km: $(element).find('input[type=text]').val(),
							id: $(element).find('select').val(),
							price: $(element).find('select option:selected').data('price')
						}
					}
				}).toArray();
				if(!tiers instanceof Array){
					tiers = [];
				}
				let tiersObj = {};
				tiers.forEach((value) => {
					Object.assign(tiersObj,value);
				})
				var $darkMode 	= $('.modal #ecom_dark').find('.ecom_darkClass');
				if($darkMode.is(':checked')){
					var darkMode 	= 1;
				}else{
					var darkMode 	= 0;
				}
				
				var $menuMode 	= $('.modal #ecom_menu').find('.ecom_menuClass');
				if($menuMode.is(':checked')){
					var menuMode 	= 1;
				}else{
					var menuMode 	= 0;
				}
				var $deliveryHours 	= $('.modal #ecom_delivery_hours').find('.ecom_delivery_hoursClass');
				if($deliveryHours.attr('checked') == 'checked'){
					var deliveryHours 	= 1;
				}else{
					var deliveryHours 	= 0;
				}

				let customTimeArray = []
				if(deliveryHours == 1){
					$("#deliveryHoursDetails .list .itemInputTime").each(function(){
						if(!!$(this).val()){
							customTimeArray.push({
								hour: $(this).val()
							})
						}
					});
				}

				var out = {
							banner 			: (banner),
							selfbanner 		: (selfbanner),
							description		: (welcome),
							color 			: (color),
							darkMode 		: (darkMode),
							menuMode 		: (menuMode),
							deliveryHours	: (deliveryHours),
							handle 			: (handle),
							payments 		: (payments),
							outlet 			: (outlet),
							register		: (register),
							minimum 		: minimum,
							user 			: (user) ? (user) : '<?=enc(USER_ID)?>',
							ecomGA			: (ecomGA),
							ecomFPixel		: (ecomFPixel),
							customTime		: customTimeArray,
							tiers			: tiersObj
						};

				$.post(baseUrl + '?action=ecom',{'ecomSet' : JSON.stringify(out) },function(){
					message('Actualizado','success');
				});
			}
			
		});
	</script>

<?php
include_once('includes/compression_end.php');
dai();
?>