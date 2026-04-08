<?php
include_once("../libraries/adodb/adodb.inc.php");

$db         = ADOnewConnection('pdo');

$servername = "db-mysql-nyc1-86039-do-user-6390714-0.db.ondigitalocean.com";
$username   = 'doadmin';
$password   = 'rajhpytmj0l1h3jz';  
$database   = 'defaultdb';
$dsnString  = "mysql:host=$servername;dbname=defaultdb;port=25060";

$db->setCharset('utf8');
$db->connect($dsnString, $username, $password);

$db->debug = true;

$result = $db->Execute('SELECT * FROM test LIMIT 1');
echo $result->fields['firstname'] . ' ' . $result->fields['lastname']  . ' ' . $result->fields['email'];
?>