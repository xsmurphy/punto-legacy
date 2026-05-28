<?php
/**
 * Bootstrap del super-admin inicial del admin realm — CLI, idempotente.
 *
 * Uso:  php panel/admin/bootstrap_seed.php
 * (correr DESPUÉS de aplicar database/migrations/postgres/09_admin_user.sql)
 *
 * Lee ADMIN_BOOTSTRAP_EMAIL + ADMIN_BOOTSTRAP_PASSWORD del .env y crea la fila en admin_user con
 * bcrypt (password_hash). NUNCA loguea el password. Si el admin ya existe → no-op (no pisa un password
 * que el admin haya cambiado). Si faltan las vars → no-op con aviso.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("bootstrap_seed.php es CLI-only.\n");
}

require __DIR__ . '/../includes/db.php';   // parsea .env → $_ENV y conecta $db
global $db;

$email = trim((string) ($_ENV['ADMIN_BOOTSTRAP_EMAIL'] ?? ''));
$pass  = (string) ($_ENV['ADMIN_BOOTSTRAP_PASSWORD'] ?? '');

if ($email === '' || $pass === '') {
    fwrite(STDERR, "[admin seed] ADMIN_BOOTSTRAP_EMAIL/PASSWORD no definidos en .env — no-op.\n");
    exit(0);
}

$exists = $db->Execute("SELECT adminId FROM admin_user WHERE lower(email) = lower(?) LIMIT 1", [$email]);
if ($exists && !$exists->EOF) {
    fwrite(STDOUT, "[admin seed] el super-admin '{$email}' ya existe — no-op.\n");
    exit(0);
}

$hash = password_hash($pass, PASSWORD_DEFAULT);
$ok   = $db->Execute(
    "INSERT INTO admin_user (email, passwordHash, name, status) VALUES (?, ?, ?, 1)",
    [$email, $hash, 'Super Admin']
);

if ($ok === false) {
    fwrite(STDERR, "[admin seed] FALLO al crear '{$email}': " . $db->ErrorMsg() . "\n");
    exit(1);
}

fwrite(STDOUT, "[admin seed] super-admin '{$email}' creado.\n");
exit(0);
