<?php 
date_default_timezone_set('America/Asuncion');
if(!isset($_COOKIE['wp-settings-time-1'])){
	//config
	$bucket		= "garchis-cache-es"; 								//nombre del bucket
	$bucketUrl 	= "d3prgjesqo8z0b.cloudfront.net/"; 				//url del archivo en cloudfront
	$goCacheGo 	= true; 											//dice al archivo new.cache.stop.php que genere el cache
	$cachetime 	= 43200;   											//duración del cache 12 horas
	$cacheext 	= 'txt';   											//extensión de cache
	$cachepage 	= $_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]; 	//Url del archivo que se va a procesar
	$cacheuri	= sha1($cachepage).".".$cacheext;					//nombre del archivo con la extension a ser cacheados
	$cachefile 	= $bucketUrl.$cacheuri; 							//url del archivo en el cache
	
	if (!class_exists('S3'))require_once('S3.php'); //incluir la clase S3 de amazon
				
	//AWS access info
	if (!defined('awsAccessKey')) define('awsAccessKey', $_ENV['AWS_ACCESS_KEY'] ?? ''); //access key de amazon s3
	if (!defined('awsSecretKey')) define('awsSecretKey', $_ENV['AWS_SECRET_KEY'] ?? '');	//secret key del access key
				
	//instantiate the class
	$s3 = new S3(awsAccessKey, awsSecretKey); //iniciamos la clase
	
	//CONFIG END
	
	ob_start();

	// calculamos el tiempo del cache
	if (S3::getObject($bucket, $cacheuri) !== false) { //verificamos si existe el archivo
		if (($info = $s3->getObjectInfo($bucket, $cacheuri)) !== false) { //obtenemos la info del archivo
			$cachelast =  $info['time']; //imprimimos la fecha de creacion
		}
	} else {
		$cachelast = 0;
	}
	clearstatcache();
	// Mostramos el archivo si aun no vence
	if (time() - $cachetime < $cachelast) { //verificamos que el archivo no haya vencido
		echo file_get_contents($cachefile); //obtenemos el contenido de la url del archivo que no vencio y existe
		$cntACmp = ob_get_contents();
		ob_end_clean();
		ob_start("ob_gzhandler"); //comprimimos
		echo $cntACmp; //imprimimos el resultado final
		ob_end_flush();
		
		exit();
	}else{
		//si el cache ya vencio, ejecuto un script que elimina todos los archivo que vencieron
		echo $cachefile."sup";
		if (($buckets = $s3->getBucket($bucket)) !== false) { //pongo el contenido del bucket en una variable
			foreach ($buckets as $cachedfile) { //leo cada contenido como array
				if (time() - $cachetime > $cachedfile['time']) { //verifico si el archivo ya caduco
					S3::deleteObject($bucket, $cachedfile['name']); //borro el archivo en caso de que haya caducado
				}
			}
		}
	}
	ob_start();
}
?>