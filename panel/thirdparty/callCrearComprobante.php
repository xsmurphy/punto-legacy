<?php
$facturacion_json= array(
/** Datos de la empresa que solicita el comprobante **/
"usertoken"         =>  "3294fe22ef0f53ea4c6104bfacf4a064",
"apikey"            =>  "3085",
"apitoken"          =>  "b200bb621f74be82d19bbd0ab38f3688",

/** Datos del cliente al cual emitir el comprobante **/
"cliente"       => array (
    "documento_tipo"    =>  "DNI", //X            
    "documento_nro"     =>  "32936589", //./
    "razon_social"      =>  "Christian", //./
    "email"             =>  "c.murphy@incomepos.com", //./
    "domicilio"         =>  "Mayor Vera", //./
    "provincia"         =>  "2",                  //X
    "envia_por_mail"    =>  "S",                 //./
    "condicion_pago"    =>  "30",                //?
    "condicion_iva"     =>  "CF"                  //?
) ,

/** Datos del comprobante a emitir **/
"comprobante"  => array (   
                        "fecha"             =>  date("d/m/Y") ,          //./
                        "tipo"              =>  "FACTURA A",      //?
                        "operacion"         =>  "V",                 //./     
                        "punto_venta"       =>  "2",    //?
                        "numero"            =>  "6",  //./
                        "periodo_facturado_desde" =>"27/07/2015", //./
                        "moneda"            => "PES", //./
                        "idioma"         => 1, //./
                        "cotizacion"        => "1", //./
                        "periodo_facturado_hasta" =>"30/07/2015", //./
                        "rubro"             =>  "Servicios web", //./
                        "rubro_grupo_contable" =>  "servicios", //./
                        "detalle"           => array (
                                                0   => array (
                                                        "cantidad"  => "1", //./
                                                        "producto"  => array (
                                                                                "descripcion"   => "PAPAS", //./
                                                                                "unidad_bulto"  => "10", //?
                                                                                "lista_precios" =>  "MI LISTA DE PRECIOS", //?
                                                                                "codigo"        =>  "", //./
                                                                                "precio_unitario_sin_iva" => "100.45" ,   //?
                                                                                "alicuota"              =>   "21", //./
                                                                                "unidad_medida" => "7" //?
                                                                            ), //end producto
                                                        "leyenda"   =>  "blanca, cepillada" //./
                                                            ), // end detalle item 

                                            ) , // end detalle
                    "bonificacion"              =>   "120", //?
                                            
                    "leyenda_gral"              =>  "bla bla bla", //./
                    "percepciones_iibb"         =>   "20",  //?
                    "percepciones_iva"          =>   "0", //?
                    "exentos"                   =>   "10", //?
                    "nogravados"                =>   "100", //?
                    "impuestos_internos"        =>   "0", //?
                    "total"                     =>   "681.09" //./         
                    ), //end comprobante

);



// EJECUTO VIA CURL EL REQUEST PARA GENERAR EL COMPROBANTE

$url="https://www.tusfacturas.com.ar/app/api/v2/facturacion/nuevo" ;
$ch = curl_init( $url );
curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode($facturacion_json) );
curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
$rta        = json_decode(curl_exec($ch));              
curl_close($ch);


// MANEJO LA RESPUESTA
if ($rta->error == 'N') {
	echo '  CAE otorgado:'.$rta->cae.'

	Vto:'.$rta->vencimiento_cae.'

	Vto pago: '.$rta->vencimiento_pago.'

	PDF del comprobante: '.$rta->comprobante_pdf_url ; 
}else{
	echo 'Se han encontrado los siguientes errores:'.implode(',',$rta->errores );

}             

?>