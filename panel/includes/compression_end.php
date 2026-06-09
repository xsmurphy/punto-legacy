<?php
if( headers_sent() ){
    $encoding = false;
}elseif( strpos($HTTP_ACCEPT_ENCODING, 'x-gzip') !== false ){
    $encoding = 'x-gzip';
}elseif( strpos($HTTP_ACCEPT_ENCODING,'gzip') !== false ){
    $encoding = 'gzip';
}else{
    $encoding = false;
}

// ob_get_clean() ya hace el end + clean en una operación. El ob_end_flush()
// original lanzaba E_NOTICE "no buffer to delete or flush" en PHP 8.x porque
// el buffer ya estaba cerrado — Whoops lo elevaba a excepción → "Oops" page.
// Defensive: solo cerrar buffer si efectivamente hay uno activo.
$contents = ob_get_level() > 0 ? ob_get_clean() : '';

if($encoding){
    //header('Content-Encoding: ' . $encoding);
}

echo $contents;
exit();
?>