<?php
require_once(__DIR__ . '/includes/cors.php');
include_once("includes/simple.config.php");
include_once("libraries/hashid.php");
include_once("libraries/countries.php");
include_once("includes/functions.php");

$time_start = microtime(true);

function getIPS($ci){
    include_once('libraries/simple_html_dom.php');
    $dom = new simple_html_dom();
    $dom->load(curlContents('http://servicios.ips.gov.py/consulta_asegurado/comprobacion_de_derecho_externo.php', 'POST', 'nro_cic=' . $ci . '&recuperar=Recuperar&envio=ok&vesbrbacnorc=1'));

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

    return array($ci,$fullName,$bday);
}

function getANR($key){
    //BUSCO PADRON COLORADO
    $url            = 'http://anr.org.py/padron/server.php?nro_doc=';
    $res            = @file_get_contents($url . $key);
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

    return [$tin2, $name2, $fullAddress];
}

function getSET($key){
    include_once("libraries/adodb/adodb.inc.php");
    $ADODB_CACHE_DIR    = '../../cache/adodb';
    $db                 = ADONewConnection('mysqli');
    $db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_rucpy');
    $db->cacheSecs      = (86400*365);
    include_once("includes/functions.php");
    ini_set('default_socket_timeout', 1);

    $tin    = '';
    $name   = '';

    $obj    = ncmExecute("SELECT tin, name, dv FROM tins WHERE tin = ? LIMIT 1", [$key]);

    if($obj){
        $tin    = ($obj['tin']) ? $obj['tin'] . '-' . $obj['dv'] : null;
        $name   = $obj['name'];
    }

    return [$tin, $name];
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
    //$res            = curlContents($url . $key);
    $res            = file_get_contents($url . $key);
    $data           = json_decode($res,true);
    $tin            = '';
    $name           = '';
    $fullName       = '';
    $fullAddress    = '';
    $phone          = '';

    if($_GET['debug']){
        print_r($data);
        die();
    }

    if(validity($data['procesamientoCorrecto'])){
        $name           = $data['nombre'];
        $tin            = $data['ruc'] . '-' . $data['dv'];
        $fullName       = $data['nombreFantasia'];
        $fullAddress    = $data['direccion'];
        $phone          = $data['telefono'];
    }else{//si la SET no anda uso la DB de ENCOM
        list($tin,$name) = getSET($key);
    }

    return array($tin,$name,$fullName,$fullAddress,$phone);
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
    list($tin,$razon,$name,$address,$phone)     = getMarangatu($key);
    list($tin2,$name2,$bday)                    = getIPS($key);
    //list($tin3,$name3,$address1)                = getANR($key);

    $phone   = getPhoneFormat($phone,'PY','national_format');
    $address = ($address) ? $address : $address1;

    if(!$name2){
        if($name3){
            $name   = $name3;
        }
    }else{
        $name = $name2;
    }

    if(!$tin){
        if(!$tin2){
            $tin   = $tin3;
        }else{
            $tin   = $tin2;
        }
    }

    $out    = array('tins'=>array(array('name'=>$razon,'tin'=>$tin,'fullName'=>$name,'phone'=>$phone,'bday'=>$bday,'address'=>$address)));
    echo json_encode($out);
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

    $out        = array('tins'=>array(array('name'=>$name,'tin'=>$tin,'address'=>$fullAddress)));

    echo json_encode($out);
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

    $out        = array('tins'=>array(array('name'=>$name,'tin'=>$tin)));

    echo json_encode($out);
}

if($_GET['test']){
    $time_end = microtime(true);
    $execution_time = ($time_end - $time_start)/60;
    echo '<b>Total Execution Time:</b> '.$execution_time.' Mins';
}

dai();
?>