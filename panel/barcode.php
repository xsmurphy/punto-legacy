<?php
//VER DE PASAR A STAND ALONE
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
?>
<html>
	<head>
		<meta charset="utf-8">
		<title>Códigos de Barra</title>
		<?php
		loadCDNFiles([],'css');
		?>
		<style>
			.cell{
				text-align:center;
				background:#fff;
				padding:0 !important;
				font-size:9px;
				line-height:130%;
				color: #000;
				overflow: hidden;
			}

			.normalfont{
				font-size: 12px !important;
			}

			.graph{
				margin-top:3px;
			}

			@media print
			{
			    .cell {
			    	border:none !important;
			    }
			}
		</style>
	</head>

	<?php
	$ids 	= rawurldecode($_GET['ids']);
	$id 	= explodes('|',$ids);

	$breakCells = ($_GET['c'])?$_GET['c']:6;
	$cells = 1;
	$cols = ($_GET['c'])?$_GET['c']:2;

	function selected($num,$cell){
		if($cell == $num){
			echo 'selected';
		}
	}
	?>
	<body class="bg-white">
		<div class="col-xs-12 bg-light padder-v hidden-print">
			<div class="col-sm-3 m-b-xs">
				<select name="account" class="form-control changeSelect rounded"> 
					<option>Modelo</option> 
					<option value="1">Regular</option> 
					<option value="2">Pequeño</option> 
					<option value="3">Mediano</option> 
					<option value="4">Grande</option> 
				</select>
			</div>
			<div class="col-sm-3 m-b-xs">
				<select class="form-control changeCols rounded"> 
					<option value="2" <?=selected('2',$cols)?>>6 Columnas</option> 
					<option value="3" <?=selected('3',$cols)?>>4 Columnas</option> 
					<option value="4" <?=selected('4',$cols)?>>3 Columnas</option> 
					<option value="6" <?=selected('6',$cols)?>>2 Columnas</option> 
					<option value="12" <?=selected('12',$cols)?>>1 Columna</option>
				</select>
			</div>
			<div class="col-sm-3 m-b-xs">
				<input type="number" min="0" step="1" value="2.8" id="height" class="form-control text-right rounded" autocomplete="off" data-toggle="tooltip" data-placement="bottom" title="Altura en centimetros">
			</div>
			<div class="col-sm-3 text-right m-b-xs">
				<a href="#" class="btn btn-info btn-rounded text-white font-bold text-u-c" id="printbtn">Imprimir</a>
			</div>
			<div class="col-sm-12">
				<label class="checkbox-inline"> 
					<input type="checkbox" value="1" class="change" data-target="company" checked><i></i> 
					Empresa
				</label>
				<label class="checkbox-inline"> 
					<input type="checkbox" value="1" class="change" data-target="title" checked><i></i> 
					Título
				</label>
				<label class="checkbox-inline"> 
					<input type="checkbox" value="1" class="change" data-target="price" checked><i></i> 
					Precio
				</label>
				<label class="checkbox-inline"> 
					<input type="checkbox" value="1" class="change" data-target="code" checked><i></i> 
					Código
				</label>
			</div>
		</div>

		<div class="row text-center m-t">
			<?php
			$companyName 	= COMPANY_NAME;

			function buildBlock($name,$id,$price,$code,$loops){
				global $cols,$companyName;
				$x 			= 0;
				while($x < $loops){ //segundo loop la cantidad de veces indicada
				?>
				<div class="col-xs-<?=$cols;?> b cell">
				    <div class="company"><?=$companyName?></div>
				    <div class="title"><?=$name?></div>
				    <svg class="barcode"
					 	jsbarcode-format="CODE128"
					 	jsbarcode-value="<?=$code?>"
					 	jsbarcode-texttop="0"
					 	jsbarcode-textbottom="0"
					    jsbarcode-textmargin="0"
					    jsbarcode-marginleft="0" 
					    jsbarcode-marginright="0" 
					    jsbarcode-margintop="0"
					    jsbarcode-marginbottom="0" 
					    jsbarcode-height="30"
					    jsbarcode-displayvalue="false"
					    > </svg>
				    
				    <?php 
					$countNms = strlen((string)$id);
					$maxNums = 11;
					if($countNms < $maxNums){
						$sum = '';
						$add = $maxNums - $countNms;
						
						for($u=0;$u<$add;$u++){
							$sum .= 0;
						}
					}
					?>
				    <div style="font-size:8px;" class="code"><?=$sum.$id;?></div>
				    <div class="normalfont price"><?=$price?></div>
			    </div>
			    <?php
			    	$x++;
				}
			}

			foreach($id as $i){
				$single 	= explodes('-',$i);
				$itemId 	= iftn($single[0],$i);
				$cantidad 	= iftn($single[1],'1');
				$item 		= getItemData(dec($itemId));
				$itemName 	= $item['itemName'];
				$itemPrice	= CURRENCY.formatCurrentNumber($item['itemPrice']);
				$decItemId 	= dec($itemId);

				if($item['itemIsParent']>0){//es parent

					$children = ncmExecute('SELECT * FROM item WHERE itemParentId = ? AND companyId = ? LIMIT 100',[$item['itemId'],COMPANY_ID]);

					while (!$children->EOF) {
				        $itemName 	= $children->fields['itemName'];
						$itemPrice	= CURRENCY.formatCurrentNumber($children->fields['itemPrice']);

						buildBlock($itemName,enc($children->fields['itemId']),$itemPrice,enc($children->fields['itemId']),$cantidad);

				        $children->MoveNext(); 
				    }

				}else{
					buildBlock($itemName,$itemId,$itemPrice,$itemId,$cantidad);
				}
			}
			?>
		</div>
		<div class="pagebreak"></div>
		<?php
			loadCDNFiles(['/assets/vendor/js/JsBarcode-3.11.0.min.js'],'js');
		?>
		<script>
			$(document).ready(function(){
				$('[data-toggle="tooltip"]').tooltip();

				JsBarcode(".barcode").init();

				$('.change').on('change', function() {
					var $this 	= $(this);
					var target 	= $this.attr('data-target');
					
					$('.'+target).toggle();
					
				});

				$('#height').on('keyup', function() {
					var val 	= parseFloat($('#height').val());
					var css = {'height':val+'cm', 'max-height':val+'cm', 'min-height':val+'cm', 'overflow':'hidden'};
					$('.cell').css(css);
				});

				var val 	= parseFloat($('#height').val());
				var css = {'height':val+'cm', 'max-height':val+'cm', 'min-height':val+'cm', 'overflow':'hidden'};
				$('.cell').css(css);

				$('.changeSelect').on('change', function() {
					var $this 	= $(this);
					var val 	= $this.val();
					console.log('selected '+val);
					$('svg.barcode').each(function(){
						if(val == '1'){
							$(this).attr('jsbarcode-height','30');
						}else if(val == '2'){
							$(this).attr('jsbarcode-height','20');
						}else if(val == '3'){
							$(this).attr('jsbarcode-height','40');
						}else if(val == '4'){
							$(this).attr('jsbarcode-height','60');
						}
					});

					JsBarcode(".barcode").init();
				});

				$('.changeCols').on('change', function() {
					var value = $(this).val();
					window.location = 'barcode.php?ids=<?=$ids?>&c='+value;
				});

				$('#printbtn').on('click',function(){
					window.print();
				});
			});
		</script>
	</body>
</html>
<?php
dai();
?>