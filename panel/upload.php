<?php
include_once("../panel/includes/db.php");
include_once('../panel/includes/simple.config.php');
require_once('../panel/includes/config.php');
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

$img      = SYSIMGS_FOLDER.'/'.$_GET['id'].'.jpg';

if($_GET['action'] == 'delete'){
  @unlink($img);
  echo 'false';
}else{
  echo uploadImage($_FILES['image'], $img, 500000);
}


?>

  
