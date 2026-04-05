<?php
$cId = $_GET['cId'];
$iId = $_GET['iId'];
$img = 'sysimages/'.$cId.'_'.$iId.'.jpg';

echo '<img src="https://assets.incomepos.com/src.php?src='.$img.'&w=420&h=400" width="100%">';
?>