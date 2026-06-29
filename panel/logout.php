<?php
    include_once("includes/db.php");
    include_once('includes/simple.config.php');
    include_once("includes/config.php");
    // FLAG: doc dice `require_once("includes/auth_session.php")` pero auth_session.php
    // vive en app/includes/. Ruta corregida desde panel/ → ../app/includes/.
    require_once __DIR__ . "/../app/includes/auth_session.php";

    session_start();

    // Revocar la sesión opaca del panel (server-side) además de destruir la sesión PHP.
    foreach (_authExtractTokens() as $raw) {
        authSessionRevokeByToken($raw);
    }
    authClearCookie('_jwt_panel', 'Lax');

    unset($_SESSION['user']);
    header("Location:/login");
    die("Redirecting");
?>
