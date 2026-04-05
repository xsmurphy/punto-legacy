<?php
require_once('../libraries/barcode/class/BCGFontFile.php');
require_once('../libraries/barcode/class/BCGColor.php');
require_once('../libraries/barcode/class/BCGDrawing.php');
require_once("../libraries/barcode/class/BCGcode128.barcode.php");

$text 		= urldecode($_GET['text']);
$barcode 	= urldecode($_GET['id']);
$scale 		= $_GET['scale'] ? $_GET['scale'] : 1;
$thickness 	= $_GET['thickness'] ? $_GET['thickness'] : 30;

$colorFront = new BCGColor(0, 0, 0);
$colorBack = new BCGColor(255, 255, 255);

$font 			= new BCGFontFile('../libraries/barcode/font/Arial.ttf', 18);

// Barcode Part
$code = new BCGcode128();
$code->setScale($scale);
$code->setThickness($thickness);
$code->setForegroundColor($colorFront); // Color of bars
$code->setBackgroundColor($colorBack); // Color of spaces
$code->setFont($font);
$code->setLabel($text);
$code->parse($barcode);

// Drawing Part
$drawing = new BCGDrawing('', $colorFront);
$drawing->setBarcode($code);
$drawing->draw();
header('Content-Type: image/png');
$drawing->finish(BCGDrawing::IMG_FORMAT_PNG);
?>