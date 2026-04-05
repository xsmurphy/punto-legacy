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

$contents = ob_get_contents();
ob_get_clean();
//$contents   = preg_replace('/\v(?:[\v\h]+)/', '', $contents);
$size       = strlen($contents);
$contents   = substr($contents, 0, $size);
//$contents   = str_replace("\n",' ',$contents); //sacamos espacios del contenido
//$contents   = preg_replace("/\s\s+/", " ",$contents); //sacamos saltos de pagina del contenido
//$contents   = gzcompress($contents, 9);

if($encoding){
    //header('Content-Encoding: ' . $encoding);
}

echo $contents;
ob_end_flush();
exit();
?>