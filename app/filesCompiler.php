<?php
//echo phpinfo();
//die();
/*ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);*/

function curlContents($url, $method = 'GET', $data = false, $headers = false, $returnInfo = false, $spoofRef = false) {    
    $ch = curl_init();
    
    if($method == 'POST') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        if($data !== false) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    } else {
        if($data !== false) {
            if(is_array($data)) {
                $dataTokens = array();
                foreach($data as $key => $value) {
                    array_push( $dataTokens, urlencode($key) . '=' . urlencode($value) );
                }
                $data = implode('&', $dataTokens);
            }
            curl_setopt($ch, CURLOPT_URL, $url . '?' . $data);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
        }
    }

    if($spoofRef){
		curl_setopt($ch, CURLOPT_REFERER, $url);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    }

    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    if($headers !== false) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $contents = curl_exec($ch);

    if($returnInfo) {
        $info = curl_getinfo($ch);
    }

    curl_close($ch);

    if($returnInfo) {
        return array('contents' => $contents, 'info' => $info);
    } else {
        return $contents;
    }
}

function getFileContent($url){//usar solo con urls propias y controladas por encom
	// For relative/local file paths, read directly from disk
	if (!filter_var($url, FILTER_VALIDATE_URL)) {
		return file_get_contents($url);
	}

	// For self-referencing app URLs, read from disk to avoid single-threaded deadlock
	global $_appBase;
	if ($_appBase && strpos($url, $_appBase) === 0) {
		$localPath = __DIR__ . parse_url($url, PHP_URL_PATH);
		if (file_exists($localPath)) {
			return file_get_contents($localPath);
		}
	}

	$ops = 	[
							    "ssl" => [
									        "verify_peer" 		=> false,
									        "verify_peer_name" 	=> false,
									    ],
								'http' => [
											'header' 			=>
											'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? '') . "\r\n"
										]
							];

	return file_get_contents($url, false, stream_context_create($ops));
}

function compileFilesOutput($data,$type){

	if($type == 'css'){
		header("Content-Type: text/css");
	}else{
		header("Content-Type: application/javascript");
	}

    echo $data;
	die();
}

function compileFiles($files,$name,$type = 'js',$debug=false){
	$cachedir 	= "cach/";   // directorio de cache
	$cacheext 	= $type;   // extensión de cache
	$cachefile 	= $cachedir . sha1($name) . "." . $cacheext;

	if (file_exists($cachefile)) {
		$data = getFileContent($cachefile);
		//$data = curlContents($cachefile);
		compileFilesOutput($data,$type);
	}else{
		
		$contents = [];
		foreach($files as $file){
			if (filter_var($file, FILTER_VALIDATE_URL) === FALSE) {
			    $contents[] = $file;
			}else{
				//$buffer	= file_get_contents($file);
				$buffer	= getFileContent($file);
				$contents[] = $buffer;
			}
		}

		$buffer = implode("\n", $contents);

		if(!$debug){
			$fp = fopen($cachefile, "w");
			fwrite($fp, $buffer);
			fclose($fp);
		}

		compileFilesOutput($buffer,$type);
	}
}

$debug = false;

// URLs base para desarrollo local vs producción
$_scheme    = $_SERVER['REQUEST_SCHEME'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$_appBase   = $_scheme . '://' . $_SERVER['HTTP_HOST'];
$_panelBase = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false)
              ? 'http://localhost:8001'
              : 'https://panel.encom.app';

if($_GET['vendor'] == 'css'){
	$name 	= '1';
	$files 	= [
				'https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;900&display=swap',
				'https://fonts.googleapis.com/icon?family=Material+Icons',
				'https://fonts.googleapis.com/css2?family=Shadows+Into+Light&display=swap',
				$_appBase . '/css/fonts.css?' . rand(),
				'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css',
				$_appBase . '/css/app.css',
				'https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.0.0/animate.compat.min.css',
				'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/css/bootstrap-datetimepicker.min.css',
				'https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.min.css',
				$_panelBase . '/css/color-selector-2.css',
				'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css',
				'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/css/iguider.css?' . rand(),
				'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/css/iguider.theme-material.css?' . rand(),
				$_appBase . '/css/ncmCalendars.css?' . rand(),
				$_appBase . '/css/chosen.css',
				$_appBase . '/css/custom.css?' . rand()

			];
}else if($_GET['vendor'] == 'test'){
	$name 	= '1';
	$files = [
		
		
		
		'https://ncmaspace.nyc3.digitaloceanspaces.com/panel/js/fileReader.min.js',
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/ncmDropbox.min.js',
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/hereRouting.min.js',
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/dpb.min.js?' . rand()
		];
}else if($_GET['vendor'] == 'js'){
	$name 	= '1';
	$files = [
		'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.3/jquery.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/js/bootstrap.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/moment-with-locales.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/locale/es.js',
		'https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.19/js/jquery.dataTables.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/ismobilejs/0.4.1/isMobile.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/offline.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/df-number-format/2.1.6/jquery.number.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/mousetrap/1.6.3/mousetrap.min.js',
		'https://cdn.jsdelivr.net/npm/jquery.actual@1.0.19/jquery.actual.min.js',
		'https://cdn.jsdelivr.net/simplestorage/0.2.1/simpleStorage.min.js',
		'https://cdn.jsdelivr.net/npm/pouchdb@7.2.1/dist/pouchdb.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/lz-string/1.4.4/lz-string.min.js',
		$_panelBase . '/scripts/written-number.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/rsvp/4.8.3/rsvp.min.js',
		$_appBase . '/scripts/sha256.js',
		'https://cdn.rawgit.com/kjur/jsrsasign/c057d3447b194fa0a3fdcea110579454898e093d/jsrsasign-all-min.js',
		'https://cdn.jsdelivr.net/npm/qz-tray@2.2.1/qz-tray.min.js',
		$_appBase . '/scripts/sign-message.js',
		'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/js/bootstrap-datetimepicker.min.js',
		$_panelBase . '/scripts/color-selector-2.js',
		'https://cdnjs.cloudflare.com/ajax/libs/libphonenumber-js/1.6.8/libphonenumber-js.min.js',
		'https://cdn.onesignal.com/sdks/OneSignalSDK.js',
		'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.1.6/Chart.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/push.js/1.0.8/push.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/mustache.js/3.1.0/mustache.min.js',
		$_appBase . '/scripts/iguider.stub.js', // iGuider CDN no disponible localmente
		'https://cdnjs.cloudflare.com/ajax/libs/jquery-fullscreen-plugin/1.1.5/jquery.fullscreen-min.js',
		'https://ncmaspace.nyc3.digitaloceanspaces.com/panel/js/fileReader.min.js',
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/ncmDropbox.min.js',
		'https://unpkg.com/leaflet@1.7.1/dist/leaflet.js',
		'https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js',
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/hereRouting.min.js',
		'https://cdn.jsdelivr.net/npm/@fingerprintjs/fingerprintjs@3/dist/fp.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/jQuery-Geolocation/1.0.50/jquery.geolocation.min.js',
		'https://js.pusher.com/7.2/pusher.min.js',
		'https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.min.js',
		//'https://www.google.com/recaptcha/api.js?render=6LfDSOoUAAAAALtjJkK_Epxdl7qFC7D7hynzu-ph',
		'https://browser.sentry-cdn.com/6.0.1/bundle.min.js',
		$_panelBase . "/scripts/rb.min.js?" . rand(),
		$_panelBase . '/scripts/num2word.js?'. rand(),
		// 'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/dpb.min.js?' . rand(),
		$_panelBase . '/scripts/documentPrintBuilder.source.js?' . rand(),
		$_appBase."/scripts/globalv2.js?" . rand(),
		// $_appBase."/scripts/debug.js?".rand()
		];
}else if($_GET['vendor'] == 'debug'){
	
	$name 	= 'debug';
	$debug 	= true;
	$files 	= [
				'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.3/jquery.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/fastclick/1.0.6/fastclick.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/js/bootstrap.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/moment-with-locales.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/locale/es.js',
				'https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.19/js/jquery.dataTables.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/ismobilejs/0.4.1/isMobile.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/offline.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/df-number-format/2.1.6/jquery.number.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/mousetrap/1.6.3/mousetrap.min.js',
				'https://cdn.jsdelivr.net/npm/jquery.actual@1.0.19/jquery.actual.min.js',
				'https://cdn.jsdelivr.net/simplestorage/0.2.1/simpleStorage.min.js',
				'https://cdn.jsdelivr.net/npm/pouchdb@7.2.1/dist/pouchdb.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/lz-string/1.4.4/lz-string.min.js',
				$_panelBase . '/scripts/written-number.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/rsvp/4.8.5/rsvp.min.js',
				$_appBase . '/scripts/sha256.js',
				'https://cdn.rawgit.com/kjur/jsrsasign/c057d3447b194fa0a3fdcea110579454898e093d/jsrsasign-all-min.js',
				'https://cdn.jsdelivr.net/npm/qz-tray@2.2.1/qz-tray.min.js',
				$_appBase . '/scripts/sign-message.js',
				'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/js/bootstrap-datetimepicker.min.js',
				$_panelBase . '/scripts/color-selector-2.js',
				'https://cdnjs.cloudflare.com/ajax/libs/libphonenumber-js/1.6.8/libphonenumber-js.min.js',
				'https://cdn.onesignal.com/sdks/OneSignalSDK.js',
				'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.1.6/Chart.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/push.js/1.0.8/push.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/mustache.js/3.1.0/mustache.min.js',
				
				
				
				'https://cdnjs.cloudflare.com/ajax/libs/jquery-fullscreen-plugin/1.1.5/jquery.fullscreen-min.js',
				'https://browser.sentry-cdn.com/6.0.1/bundle.min.js',
				'https://ncmaspace.nyc3.digitaloceanspaces.com/panel/js/fileReader.min.js',
				'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/ncmDropbox.min.js',
				'https://unpkg.com/leaflet@1.7.1/dist/leaflet.js',
				'https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js',
				'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/hereRouting.min.js',
				'https://cdn.jsdelivr.net/npm/@fingerprintjs/fingerprintjs@3/dist/fp.min.js',
				'https://cdn.rawgit.com/neocotic/qrious/master/dist/qrious.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/jQuery-Geolocation/1.0.50/jquery.geolocation.min.js',
				'https://js.pusher.com/7.2/pusher.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.min.js',
				//'https://www.google.com/recaptcha/api.js?render=6LfDSOoUAAAAALtjJkK_Epxdl7qFC7D7hynzu-ph',
				$_panelBase . '/scripts/rb.min.js?'.rand(),
				$_panelBase . '/scripts/num2word.js?'. rand(),
				//$_panelBase . '/scripts/dpb.min.js?' . rand(),
				$_panelBase . '/scripts/documentPrintBuilder.source.js?' . rand(),
				$_appBase."/scripts/debug.js?".rand()
			];

}else if($_GET['vendor'] == 'mobile'){
	$name 	= 'mobile';
	$debug 	= false;
	$files 	= [
				'https://cdnjs.cloudflare.com/ajax/libs/fastclick/1.0.6/fastclick.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/js/bootstrap.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/moment-with-locales.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/locale/es.js',
				'https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.19/js/jquery.dataTables.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/ismobilejs/0.4.1/isMobile.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/offline.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/df-number-format/2.1.6/jquery.number.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/mousetrap/1.6.3/mousetrap.min.js',
				'https://cdn.jsdelivr.net/npm/jquery.actual@1.0.19/jquery.actual.min.js',
				'https://cdn.jsdelivr.net/simplestorage/0.2.1/simpleStorage.min.js',
				'https://cdn.jsdelivr.net/npm/pouchdb@7.2.1/dist/pouchdb.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/lz-string/1.4.4/lz-string.min.js',
				$_panelBase . '/scripts/written-number.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/js/bootstrap-datetimepicker.min.js',
				$_panelBase . '/scripts/color-selector-2.js',
				$_appBase . '/scripts/sha256.js',
				'https://cdnjs.cloudflare.com/ajax/libs/libphonenumber-js/1.6.8/libphonenumber-js.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.1.6/Chart.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/push.js/1.0.8/push.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/mustache.js/3.1.0/mustache.min.js',
				
				
				
				'https://browser.sentry-cdn.com/6.0.1/bundle.min.js',
				'https://ncmaspace.nyc3.digitaloceanspaces.com/panel/js/fileReader.min.js',
				'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/ncmDropbox.min.js',
				'https://unpkg.com/leaflet@1.7.1/dist/leaflet.js',
				'https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js',
				'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/hereRouting.min.js',
				'https://cdn.jsdelivr.net/npm/@fingerprintjs/fingerprintjs@3/dist/fp.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/jQuery-Geolocation/1.0.50/jquery.geolocation.min.js',
				'https://js.pusher.com/7.2/pusher.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.min.js',
				//'https://www.google.com/recaptcha/api.js?render=6LfDSOoUAAAAALtjJkK_Epxdl7qFC7D7hynzu-ph',
				$_panelBase . '/scripts/rb.min.js?'.rand(),
				$_panelBase . '/scripts/num2word.js?'. rand(),
				// 'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/dpb.min.js?'.rand(),
				$_panelBase . '/scripts/documentPrintBuilder.source.js?' . rand(),
				$_appBase . '/scripts/debug.js?'.rand()
			];
}else{
	die();
}

$type = ($_GET['vendor'] == 'css')?'css':$_GET['vendor'];

compileFiles($files,$name,$type,$debug);
?>