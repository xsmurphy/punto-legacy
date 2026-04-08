<?php

    // First we execute our common code to connection to the database and start the session
	include_once("includes/db.php");
	include_once('includes/simple.config.php');
    include_once("includes/config.php");

    session_start();
    
    // We remove the user's data from the session
    unset($_SESSION['user']);
    
    // We redirect them to the login page
   	header("Location:/login");
    die("Redirecting");
?>