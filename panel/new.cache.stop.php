<?php
if(isset($goCacheGo)){	
	$buffer = ob_get_contents(); //obtenemos el contenido de la pagina actual y lo metemos en la variable $buffer
	ob_end_clean(); //cerramos el $buffer
	$buffer = str_replace("\n",' ',$buffer); //sacamos espacios del contenido
	$buffer = preg_replace("/\s\s+/", " ",$buffer); //sacamos saltos de pagina del contenido
	$s3->putObjectString($buffer, $bucket , $cacheuri, S3::ACL_PUBLIC_READ); //subimos el contenido y lo almacenamos en un archivo en el s3

	echo $buffer; //mostramos el contenido
	ob_end_flush();
}
?> 