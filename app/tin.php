<?php
require_once(__DIR__ . '/includes/cors.php');

if(isset($_GET['c']) && isset($_GET['s'])){

    include_once("libraries/rateLimiter.php");

    $rateLimiter  = new RateLimiter($_SERVER['REMOTE_ADDR']);
    $limit        = 10;       //  number of connections to limit user to per $minutes
    $minutes      = 1;        //  number of $minutes to check for.
    $seconds      = floor($minutes * 60); //  retry after $minutes in seconds.

    try {
        $rateLimiter->limitRequestsInMinutes($limit, $minutes);
    } catch (RateExceededException $e) {
        header("HTTP/1.1 429 Too Many Requests");
        header(sprintf("Retry-After: %d", $seconds));
        $data = 'Rate Limit Exceeded';
        die(json_encode($data));
    }

    header('Content-Type: application/json');

    /*$ruc = explode('-', $_GET['s']);

    $cachefile      = 'cache_rucs/' . $_GET['c'] . '_' . sha1($ruc[0]) . '.json';

    if(isset($_GET['clearcache'])){
        @unlink($cachefile);
        die('cache leared ' . $cachefile);
    }

    $cachetime      = 2.628e+9;//1 mnth
    if (file_exists($cachefile) && time() - $cachetime < filemtime($cachefile)) {
        echo file_get_contents($cachefile);
        die();
    }*/

    $cache = false;
    // if there is either no file OR the file to too old, render the page and capture the HTML.
    ob_start();



    include_once("includes/simple.config.php");
    include_once("libraries/countries.php");
    include_once("includes/functions.php");
    theErrorHandler('json');

    $time_start = microtime(true);

    function getIPS($ci){
        $return = [];
        $dom = new simple_html_dom();

        $res    = curlContents('http://servicios.ips.gov.py/consulta_asegurado/comprobacion_de_derecho_externo.php', 'POST', 'nro_cic=' . $ci . '&recuperar=Recuperar&envio=ok&vesbrbacnorc=1');
        $res    = utf8_encode ( $res );

        if(validity($res)){
            $dom->load($res);

            $table  = $dom->find('table',2);
            
            $fields = [];
            foreach($table->find('td') as $article) {
                $fields[] = $article->plaintext;
            }

            $ci         = $fields[1];
            $name       = $fields[2];
            $surname    = $fields[3];
            $fullName   = ($name && $surname) ? $name . ' ' . $surname : '';
            $bday       = ($fields[4]) ? date('Y-m-d',strtotime($fields[4])) : '';

            $return     = array($ci,$fullName,$bday);
        }

        return $return;
    }

    function getANR($key){
        //BUSCO PADRON COLORADO
        $url            = 'http://anr.org.py/padron/server.php?nro_doc=';
        $return         = [];
        $res            = curlContents($url . $key, 'GET', false, false, false, true);//@file_get_contents($url . $key);
        //curlContents($url, $method = 'GET', $data = false, $headers = false, $returnInfo = false, $spoofRef = false)

        if(validity($res)){
            $in             = json_decode($res,true);
            $tin2           = '';
            $name2          = '';
            $fullAddress    = '';

            if($in['data']){
                $data           = $in['data'];
                $name2          = $data['nombre'] . ' ' . $data['apellido'];
                $tin2           = $data['cedula'];
                $address        = $data['direccion'];
                $location       = $data['departamento'];
                $city           = $data['distrito'];
                $fullAddress    = $address . ', ' . $city . ', ' . $location;
            }

            $return = array($tin2,$name2,$fullAddress);
        }

        return $return;
    }

    function getSET($key){

        $tin  = '';
        $name = '';

        $obj    = ncmExecute("SELECT tin, name, dv FROM tins WHERE tin = ? LIMIT 1",[$key]);

        if($obj){
            $tin    = ($obj['tin']) ? $obj['tin'] . '-' . $obj['dv'] : null;
            $name   = utf8_decode($obj['name']);
        }

        return [$tin,$name];
    }

    function getMarangatu($key){
        //BUSCO EN MARANGATU
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'host: marangatu.set.gov.py',
            'authorization: Basic NzY1OTM5NDpDcmlzMjUwMzE5ODc=',
            'Connection: close'
        ];

        $url            = 'https://marangatu.set.gov.py/eset-restful/contribuyentes/consultar?codigoEstablecimiento=1&ruc=';
        //$res            = curlContents($url . $key,'GET',false,$headers);
        $res            = curlContents($url . $key);

        if(validity($res)){
            $data           = json_decode($res,true);
            $tin            = '';
            $name           = '';
            $fullName       = '';
            $fullAddress    = '';
            $phone          = '';

            if($data['procesamientoCorrecto']){
                $name           = $data['nombre'];
                $tin            = $data['ruc'] . '-' . $data['dv'];
                $fullName       = $data['nombreFantasia'];
                $fullAddress    = $data['direccion'];
                $phone          = $data['telefono'];
            }else{//si la SET no anda uso la DB de ENCOM
                list($tin,$name) = getSET($key);
            }

            $return = array($tin,$name,$fullName,$fullAddress,$phone);
        }

        return $return;
    }

    function cachedFile($key){
        $cachefile      = 'cache_rucs/' . $_GET['c'] . '_' . sha1($key) . '.json';

        if(isset($_GET['clearcache'])){
            @unlink($cachefile);
            die('cache leared ' . $cachefile);
        }

        if (file_exists($cachefile)) {
            $data   = file_get_contents($cachefile);
            $arr    = json_decode($data,true);
            $return = $arr['tins'][0];
            //@unlink($cachefile);
            return [$return['name'],$return['tin'],$return['fullName'],$return['phone'],$return['bday'],$return['address']];
        }else{
            return false;
        }
    }

    $key        = str_replace(' ','%20',$_GET['s']);
    $key        = explodes('-',$key,0);
    $name       = '';
    $name2      = '';
    $name3      = '';
    $tin        = '';
    $tin2       = '';
    $tin3       = '';
    $razon      = '';
    $bday       = '';
    $phone      = '';
    $address    = '';
    $address1   = '';

    if($_GET['c'] == 'PY'){

        define('COUNTRY_CODE', 'PY');
        $id     = explode('-', $_GET['s'])[0];
        $out    = [];
        $urlRUC = 'https://servicios.set.gov.py/eset-publico/contribuyente/estado?ruc=' . $id;
        $urlCI  = 'https://servicios.set.gov.py/eset-publico/ciudadano/recuperar?cedula=' . $id;

        $ruc    = curlContents($urlRUC);

        if(validity($ruc)){
            $ruc = json_decode($ruc,true);

            $out['name']        = $ruc['nombreCompleto'];
            $out['tin']         = $ruc['ruc'] . '-' . $ruc['dv'];
            $out['id']          = $ruc['ruc'];
            $out['fullName']    = $ruc['nombreCompleto'];
            $out['phone']       = '';
            $out['bday']        = '';
            $out['address']     = '';
        }else{//si no encuentro busco como CI
            $ci     = curlContents($urlCI);

            if(validity($ci)){
                $ci = json_decode($ci,true);

                if($ci['presente'] == true){
                    $data               = $ci['resultado'];

                    $out['name']        = $data['nombreCompleto'];
                    $out['tin']         = $data['cedula'];
                    $out['id']          = $data['cedula'];
                    $out['fullName']    = $data['nombreCompleto'];
                    $out['phone']       = '';
                    $out['bday']        = '';
                    $out['address']     = '';
                }
            }
        }

        jsonDieResult(['tins' => [$out]],200);

    }else if($_GET['c'] == 'AR'){
        $url        = 'https://soa.afip.gob.ar/sr-padron/v2/persona/';
        $res        = @file_get_contents($url . $key);
        $in         = json_decode($res,true);

        if($in['success'] == 'true'){
            $name           = $in['data']['nombre'];
            $tin            = $in['data']['idPersona'];
            $address        = $in['data']['domicilioFiscal']['direccion'];
            $location       = $in['data']['domicilioFiscal']['localidad'];
            $cp             = $in['data']['domicilioFiscal']['codPostal'];
            $fullAddress    = $address.', C.P. '.$cp.', '.$location;
        }

        if($name){
            header('Content-Type: application/json');
            $out        = array('tins'=>array(array('name'=>$name,'tin'=>$tin,'address'=>$fullAddress)));
            echo json_encode($out);
        }else{
            jsonDieMsg('Not Found',404,'error');
        }
        
    }else if($_GET['c'] == 'CO'){
        $url        = 'https://www.rues.org.co/RM/ConsultaNIT_json';
        $res        = curlContents($url, 'POST', array('txtNIT'=>$key));
        $in         = json_decode($res,true);

        if($in['records'] == '1'){
            $data           = $in['rows'][0];
            $name           = $data['razon_social'];
            $tin            = $data['identificacion'];
            $tin            = str_replace(['C.C. ','NIT '],['',''],$tin);
        }

        if($name){
            header('Content-Type: application/json');
            $out        = array('tins'=>array(array('name'=>$name,'tin'=>$tin)));
            echo json_encode($out);
        }else{
            jsonDieMsg('Not Found',404,'error');
        }
    }

    if($_GET['test']){
        $time_end = microtime(true);
        $execution_time = ($time_end - $time_start)/60;
        echo '<b>Total Execution Time:</b> '.$execution_time.' Mins';
    }
    //
    $contents = ob_get_contents();
    if($cache){
        if(validity($contents)){
            $fp = fopen($cachefile, 'w');
            fwrite($fp, $contents);
            fclose($fp); 
        }
    }
    ob_end_flush();

    dai();
}else{
    die('false');

    if($_GET['scanner']){

        include_once("includes/simple.config.php");
        include_once("libraries/countries.php");
        include_once("includes/functions.php");

        include_once("libraries/adodb/adodb.inc.php");
        $db                 = ADONewConnection('mysqli');
        $db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_rucpy');
        ini_set('default_socket_timeout', 1);

        $files = scandir('cache_rucs', 1);

        foreach ($files as $key => $value) {
            $cachefile      = 'cache_rucs/' . $value;

            if(isset($_GET['clearcache'])){
                @unlink($cachefile);
                die('cache leared ' . $cachefile);
            }

            $data   = file_get_contents($cachefile);
            $arr    = json_decode($data,true);
            $return = $arr['tins'][0];
            //@unlink($cachefile);

            $razon  = $return['name'];
            $tin    = $return['tin'];
            $name   = $return['fullName'];
            $phone  = $return['phone'];
            $bday   = $return['bday'];
            $address = $return['address'];

            $tinp   = explode('-',$tin);
            $dv     = $tinp[1];
            $tin    = $tinp[0];

            $result = ncmExecute('SELECT personaTIN FROM persona WHERE personaTIN = ?',[$tin]);

            if(!$result){
                $db->AutoExecute('persona', ['personaName'=>$razon,'personaTIN'=>$tin,'personaDV'=>$dv,'personaAltName'=>$name,'personaPhone'=>$phone,'personaBDay'=>$bday,'personaAddress'=>$address], 'INSERT');
            }
            
        }
    }
    
}

?>