<?php
include_once("includes/simple.config.php");
include_once("includes/functions.php");

echo rand();

$number = '59521739768';
$countryCode = 'PY';
$msg = 'Esto es ENCOM, lo mejor para tu negocio';

$data 	= 	'{' .
			' "from":"' . INFOBIP_PHONE . '",' .
			' "to":"' . $number . '",' .
			' "text":"' . $msg . '" ' .
			'}';

$header = 	[
				"Host: yw9kg.api.infobip.com",
				"Accept: application/json",
			    "Authorization: " . INFOBIP_AUTH,
			    "Content-Type: application/json"
			];

$result = curlContents('https://yw9kg.api.infobip.com/sms/2/text/single','POST',$data,$header,true);

echo '<pre>';
print_r($result['contents']);
echo '</pre>';

/*$isLandLine 	= curlContents('http://apilayer.net/api/validate?access_key=' . API_LAYER_KEY . '&number=' . $number . '&country_code=' . $countryCode);

print_r($isLandLine);



if(!validity($isLandLine)){
	echo iftn('internat','');
}

$isLandLineDec	= json_decode($isLandLine,true);

if($isLandLineDec['success'] != 'false' && $isLandLineDec['valid'] == 'true'){
	if($isLandLineDec['line_type'] != 'mobile'){
		echo 'not mobile is: ' . $isLandLineDec['line_type'];
	}else{
		echo 'ol gud gou';
	}
}else{
	echo 'toomal';
}*/





dai();



$html = '<html><head><meta charset="utf-8"> <style type="text/css"> @page{size:A4;margin:0;padding:0;border:0;} html,body{width:210mm;height:297mm; font-family:Verdana!important;font-size:8pt!important; color: black;}</style></head><body><div style="margin-top:20mm;margin-left:32mm;width:19mm;height:20mm;position:absolute;z-index:0;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important"><img src="https://assets.incomepos.com/src.php?src=../assets/sysimages/J9.jpg&w=150&h=150&708522923" width="100%" height="100%"></div><div style="margin-top:26mm;margin-left:57mm;width:57mm;height:12mm;position:absolute;z-index:1;overflow:hidden;font-size:20pt!important;font-family:inherit!important;text-align:left;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">COTIZACIÓN</div><div style="margin-top:51mm;margin-left:31mm;width:68mm;height:6mm;position:absolute;z-index:2;overflow:hidden;font-size:14pt!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Bruce Wayne</div><div style="margin-top:57mm;margin-left:128mm;width:50mm;height:4mm;position:absolute;z-index:3;overflow:hidden;font-size:10pt!important;font-family:inherit!important;text-align:right;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Empresa</div><div style="margin-top:64mm;margin-left:104mm;width:74mm;height:4mm;position:absolute;z-index:4;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Dirección de la Empresa</div><div style="margin-top:64mm;margin-left:56mm;width:47mm;height:4mm;position:absolute;z-index:5;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">####-##-## ##:##:##</div><div style="margin-top:64mm;margin-left:31mm;width:12mm;height:4mm;position:absolute;z-index:6;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Fecha:</div><div style="margin-top:70mm;margin-left:55mm;width:47mm;height:4mm;position:absolute;z-index:7;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">####-##-## ##:##:##</div><div style="margin-top:70mm;margin-left:31mm;width:22mm;height:4mm;position:absolute;z-index:8;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Vencimiento:</div><div style="margin-top:70mm;margin-left:124mm;width:53mm;height:4mm;position:absolute;z-index:9;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">info@suempresa.com</div><div style="margin-top:76mm;margin-left:55mm;width:48mm;height:4mm;position:absolute;z-index:10;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">#####</div><div style="margin-top:76mm;margin-left:31mm;width:8mm;height:4mm;position:absolute;z-index:11;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">No.:</div><div style="margin-top:76mm;margin-left:110mm;width:68mm;height:4mm;position:absolute;z-index:12;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">+### ### ###</div><div style="margin-top:94mm;margin-left:94mm;width:20mm;height:4mm;position:absolute;z-index:13;overflow:hidden;font-size:10pt!important;font-family:inherit!important;text-align:right;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Cantidad</div><div style="margin-top:94mm;margin-left:120mm;width:24mm;height:4mm;position:absolute;z-index:14;overflow:hidden;font-size:10pt!important;font-family:inherit!important;text-align:right;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Precio</div><div style="margin-top:94mm;margin-left:149mm;width:28mm;height:4mm;position:absolute;z-index:15;overflow:hidden;font-size:10pt!important;font-family:inherit!important;text-align:right;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Total</div><div style="margin-top:94mm;margin-left:31mm;width:59mm;height:4mm;position:absolute;z-index:16;overflow:hidden;font-size:10pt!important;font-family:inherit!important;text-align:left;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Descripción</div><div style="margin-top:101mm;margin-left:31mm;width:147mm;height:4mm;position:absolute;z-index:17;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important"><div style="border-top:1px solid #c9d0d7; width:100%; min-width:40px;" class="ui-selectee"></div></div><div style="margin-top:106mm;margin-left:121mm;width:23mm;height:102mm;position:absolute;z-index:18;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">###.##<br>###.##<br>###.##<br>###.##<br>###.##<br></div><div style="margin-top:106mm;margin-left:31mm;width:59mm;height:101mm;position:absolute;z-index:19;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: clip!important; white-space: nowrap!important;overflow: hidden!important">Artículo 1<br>Artículo 2 ##### <br>Artículo 3<br>Artículo 4<br>Artículo 5<br></div><div style="margin-top:106mm;margin-left:95mm;width:19mm;height:101mm;position:absolute;z-index:20;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">#<br>#<br>#<br>#<br>#<br></div><div style="margin-top:106mm;margin-left:149mm;width:28mm;height:101mm;position:absolute;z-index:21;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">###.##<br>###.##<br>###.##<br>###.##<br>###.##<br></div><div style="margin-top:211mm;margin-left:121mm;width:22mm;height:4mm;position:absolute;z-index:22;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Subtotal:</div><div style="margin-top:211mm;margin-left:149mm;width:28mm;height:4mm;position:absolute;z-index:23;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">###.##</div><div style="margin-top:217mm;margin-left:150mm;width:27mm;height:4mm;position:absolute;z-index:24;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">###.##</div><div style="margin-top:217mm;margin-left:121mm;width:23mm;height:4mm;position:absolute;z-index:25;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Descuento:</div><div style="margin-top:225mm;margin-left:31mm;width:147mm;height:4mm;position:absolute;z-index:26;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important"><div style="border-top:1px solid #c9d0d7; width:100%; min-width:40px;" class="ui-selectee"></div></div><div style="margin-top:233mm;margin-left:168mm;width:9mm;height:4mm;position:absolute;z-index:27;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Total</div><div style="margin-top:233mm;margin-left:31mm;width:78mm;height:10mm;position:absolute;z-index:28;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Cuatro Mil Quinientos Cincuenta Y Seis</div><div style="margin-top:236mm;margin-left:121mm;width:56mm;height:8mm;position:absolute;z-index:29;overflow:hidden;font-size:20pt!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">###.##</div><div style="margin-top:248mm;margin-left:31mm;width:147mm;height:4mm;position:absolute;z-index:30;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:left;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important"><div style="border-top:1px solid #c9d0d7; width:100%; min-width:40px;" class="ui-selectee"></div></div><div style="margin-top:254mm;margin-left:31mm;width:147mm;height:8mm;position:absolute;z-index:31;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:center;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Nota personalizada de la transacción</div><div style="margin-top:265mm;margin-left:31mm;width:99mm;height:6mm;position:absolute;z-index:32;overflow:hidden;font-size:12pt!important;font-family:inherit!important;text-align:left;font-weight:bold;text-overflow: none!important;white-space: wrap!important;overflow: none!important">Gracias por su interés!</div><div style="margin-top:266mm;margin-left:142mm;width:35mm;height:4mm;position:absolute;z-index:33;overflow:hidden;font-size:inherit!important;font-family:inherit!important;text-align:right;font-weight:normal;text-overflow: none!important;white-space: wrap!important;overflow: none!important">info@suempresa.com</div></body></html>';

$filename = 'cotiza'.rand().'.pdf';

echo $html;
die();

$apikey = PDF_API_KEY;
	                                            
$postdata = http_build_query(
    array(
        'apikey' 			=> $apikey,
        'value' 			=> $html,
        'Zoom' 				=> '1.28'
    )
);

pdfFile($html,$filename,$postdata);
echo '<a href="https://assets.incomepos.com/sysfiles/'.$filename.'">PDF</a>';
die();

?>