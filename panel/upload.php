<?php
// Sesión legacy ANTES de config.php: así COMPANY_ID se popula desde $_SESSION['user']
// (config.php solo lo lee, no arranca sesión). Es la fuente de verdad del tenant.
if (session_status() === PHP_SESSION_NONE) { session_start(); }

include_once("../panel/includes/db.php");
include_once('../panel/includes/simple.config.php');
require_once('../panel/includes/config.php');
require_once('../panel/includes/jwt.php');
require_once('../panel/libraries/php-thumb/ThumbLib.inc.php');

function uploadImage($file, $itemImgPath, $max_size){
  $options  = array('jpegQuality' => 90);
  $false    = 'false';

  if($file['tmp_name'] && $file['error'] == 0) {

    if (is_uploaded_file($file['tmp_name'])) {
      $imgInfo  = getimagesize($file['tmp_name']);
      $type     = $imgInfo['mime'];

      if($type = 'image/jpeg'){
        $ext = '.jpg';
      }elseif($type = 'image/png'){
        $ext = '.png';
      }elseif($type = 'image/gif'){
        $ext = '.gif';
      }else{
        $ext = false;
      }

      if($file['size'] < $max_size && $ext){
        @unlink($itemImgPath);
        move_uploaded_file($file['tmp_name'], $itemImgPath);
        $thumb = PhpThumbFactory::create($itemImgPath, $options);  
        //$thumb->adaptiveResize($w, $h)->save($name,'jpg');
        $thumb->save($itemImgPath,'jpg');
        chmod($itemImgPath, 0705);
          
        return $itemImgPath;
      }else{
        return $false;
      }
      

    }else{
      return $false;
    }

  }else{
    return $false;
  }
}

// ── Tenant guard ──────────────────────────────────────────────────────────
// El companyId se deriva del lado servidor (sesión legacy → COMPANY_ID, o el claim
// `cid` del JWT _jwt_panel). NUNCA se confía en $_GET['id'] para el tenant: ese param
// solo aporta —en el caso de imágenes de ítem— el itemId, que se reanida bajo el
// companyId del servidor. Cierra el IDOR (overwrite/delete de logos de otra empresa).
// OJO: sin sesión, config.php define COMPANY_ID como el ENTERO 0 (no el string '0'),
// por eso casteamos a string antes de comparar (un !== estricto dejaría pasar el 0).
$companyId = (defined('COMPANY_ID') && (string) COMPANY_ID !== '0' && (string) COMPANY_ID !== '') ? (string) COMPANY_ID : '';

// Fallback JWT-puro (cuando no hay sesión legacy). El iss-gate se ejerce SOLO en
// esta rama; si la sesión legacy ya pobló COMPANY_ID arriba, no llegamos acá.
if($companyId === ''){
  $secret = $_ENV['JWT_SECRET'] ?? '';
  $token  = $_COOKIE['_jwt_panel'] ?? '';
  if($secret && $token){
    $payload = jwtDecode($token, $secret);
    // Realm gate: JWT_SECRET compartido con POS — el `iss` debe ser 'panel'.
    if(is_array($payload) && ($payload['iss'] ?? '') === 'panel' && !empty($payload['cid'])){
      $companyId = (string) $payload['cid'];
    }
  }
}

if($companyId === ''){
  http_response_code(401);
  echo 'false';
  return;
}

// Defensa en profundidad: el companyId (UUID o entero) solo puede contener chars de path
// seguros, aunque ya viene de una fuente confiable (sesión/JWT firmado) y basename() lo confina.
if(!preg_match('/^[A-Za-z0-9-]+$/', $companyId)){
  http_response_code(400);
  echo 'false';
  return;
}

// $_GET['id'] solo sirve para distinguir entidad y, en ítems, extraer el itemId:
//   - sin '_'          → logo de empresa   → {companyId}.jpg
//   - '{cid}_{itemId}' → imagen de ítem    → {companyId}_{itemId}.jpg  (cid forzado al del server)
$rawId = (string) ($_GET['id'] ?? '');
$us    = strpos($rawId, '_');

if($us === false){
  $name = $companyId;
}else{
  $itemId = substr($rawId, $us + 1);
  if(!preg_match('/^[A-Za-z0-9-]+$/', $itemId)){
    http_response_code(400);
    echo 'false';
    return;
  }
  $name = $companyId.'_'.$itemId;
}

$img = SYSIMGS_FOLDER.'/'.basename($name).'.jpg';

if(($_GET['action'] ?? '') == 'delete'){
  @unlink($img);
  echo 'false';
}else{
  echo uploadImage($_FILES['image'], $img, 500000);
}


?>

  
