<?php

// First we execute our common code to connection to the database and start the session
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

// This if statement checks to determine whether the registration form has been submitted
// If it has, then the registration code is run, otherwise the form is displayed

// Ensure that the user has entered a non-empty username

if (!validateHttp('storename', 'post') || !validateHttp('password', 'post') || !validateHttp('email', 'post') || !validateHttp('category', 'post') || !validateHttp('country', 'post') || !validateHttp('username', 'post')) {
	dai('Todos los campos son requeridos');
}

$post = db_prepare($_POST);

$sign = signUp($post, false);
apiOk(['success' => $sign]);