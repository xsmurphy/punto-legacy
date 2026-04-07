<?php
/**
 * Registro de nueva empresa — Panel administrativo
 * Acceso: GET  /signup  → muestra el formulario
 *         POST /signup  → procesa el registro (llama signUp())
 */
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("includes/functions.php");
include_once("libraries/countries.php");

define('OUTLETS_COUNT', 0);

// Si ya hay sesión activa ir al dashboard
if (isset($_SESSION['user'])) {
    header('Location: /@#dashboard');
    dai();
}

// ── Procesar registro ──────────────────────────────────────────────────────
if (validateHttp('signup')) {

    if (
        !validateHttp('storename', 'post') ||
        !validateHttp('password', 'post')  ||
        !validateHttp('email', 'post')     ||
        !validateHttp('category', 'post')  ||
        !validateHttp('country', 'post')   ||
        !validateHttp('username', 'post')
    ) {
        dai('Todos los campos son requeridos');
    }

    if (validateHttp('password', 'post') !== validateHttp('password_confirm', 'post')) {
        dai('Las contraseñas no coinciden');
    }

    $sign = signUp($_POST, true); // true = auto-login after signup

    if ($sign === true) {
        // Notificación interna al admin SaaS
        $body  = 'Nueva empresa: ' . $_POST['storename'];
        $body .= ' | Email: ' . $_POST['email'];
        $body .= ' | País: ' . $_POST['country'];
        sendEmail(EMAIL_NOTIFICATION_TO, 'Nueva empresa registrada', $body, $body);
    }

    echo $sign;
    dai();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
    <title><?= APP_NAME ?> — Crear cuenta</title>
    <?php
    loadCDNFiles([
        'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css',
    ], 'css');
    ?>
    <style>
        body { background: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .signup-wrap { max-width: 520px; margin: 60px auto; padding: 0 16px; }
        .signup-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,.08); padding: 40px 40px 32px; }
        .signup-card h1 { font-size: 22px; font-weight: 700; margin: 0 0 4px; color: #1a1a2e; }
        .signup-card .subtitle { color: #888; font-size: 14px; margin-bottom: 28px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #555; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #dde0e7; border-radius: 8px; font-size: 15px; box-sizing: border-box; transition: border .2s; }
        .form-control:focus { outline: none; border-color: #4cb6cb; box-shadow: 0 0 0 3px rgba(76,182,203,.15); }
        .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .btn-signup { width: 100%; padding: 13px; background: #4cb6cb; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 8px; transition: background .2s; }
        .btn-signup:hover { background: #3aa3b8; }
        .footer-link { text-align: center; font-size: 13px; margin-top: 20px; color: #888; }
        .footer-link a { color: #4cb6cb; text-decoration: none; font-weight: 600; }
        .debug-badge { background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 8px 12px; font-size: 12px; margin-bottom: 20px; color: #856404; }
    </style>
</head>
<body>
<div class="signup-wrap">
    <div class="text-center" style="margin-bottom:24px;">
        <img src="/images/incomeLogoLgDark.png" width="80" alt="<?= APP_NAME ?>">
    </div>
    <div class="signup-card">
        <h1>Crear cuenta</h1>
        <p class="subtitle">Comenza a usar <?= APP_NAME ?> gratis por 14 días</p>

        <?php if (($_ENV['APP_DEBUG'] ?? 'false') === 'true'): ?>
        <div class="debug-badge">⚠ Modo debug activo — los emails no se envían</div>
        <?php endif; ?>

        <form id="signupForm" action="/signup?signup=1" method="POST">
            <div class="form-group">
                <label>Nombre de la empresa</label>
                <input type="text" name="storename" class="form-control" placeholder="Ej: Panadería Don Pedro" required autocomplete="organization">
            </div>

            <div class="row-2">
                <div class="form-group">
                    <label>País</label>
                    <select name="country" class="form-control" required>
                        <?php foreach ($countriesHispanic as $key => $val): ?>
                            <option value="<?= $key ?>"><?= $val['native'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Rubro</label>
                    <select name="category" class="form-control" required>
                        <option value="">Seleccionar...</option>
                        <?php foreach ($companyCategories as $group => $items): ?>
                            <optgroup label="<?= $group ?>">
                                <?php foreach ($items as $label => $val): ?>
                                    <option value="<?= $val ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Tu nombre y apellido</label>
                <input type="text" name="username" class="form-control" placeholder="Ej: Ana García" required autocomplete="name">
            </div>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-control" placeholder="tu@empresa.com" required autocomplete="email">
            </div>

            <div class="row-2">
                <div class="form-group">
                    <label>Contraseña</label>
                    <input type="password" name="password" id="pwd" class="form-control" placeholder="Mínimo 6 caracteres" required autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>Confirmar contraseña</label>
                    <input type="password" name="password_confirm" class="form-control" placeholder="" required autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="btn-signup" id="submitBtn">Crear empresa</button>
        </form>

        <div class="footer-link" style="margin-top:16px; font-size:12px; color:#aaa;">
            Al registrarte aceptás los <a href="/assets/terminos.pdf" target="_blank">Términos y Condiciones</a>
        </div>
    </div>
    <div class="footer-link">
        ¿Ya tenés cuenta? <a href="/login">Ingresar</a>
    </div>
</div>

<script>var noSessionCheck = true;</script>
<?php
loadCDNFiles([
    'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js',
], 'js');
?>
<script>
$(document).ready(function () {
    $('#signupForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#submitBtn');
        var $pwd = $('#pwd').val();

        if ($btn.prop('disabled')) return;

        // Validar contraseñas
        var $confirm = $('[name="password_confirm"]').val();
        if ($pwd !== $confirm) {
            swal('Error', 'Las contraseñas no coinciden', 'error');
            return;
        }
        if ($pwd.length < 6) {
            swal('Error', 'La contraseña debe tener al menos 6 caracteres', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Creando cuenta...');

        $.ajax({
            url:  $(this).attr('action'),
            type: 'POST',
            data: $(this).serialize(),
            success: function (res) {
                if (res === 'true' || res === true) {
                    window.location.replace('/@#dashboard');
                } else {
                    swal('Error', res || 'Ocurrió un error al registrar la empresa', 'error');
                    $btn.prop('disabled', false).text('Crear empresa');
                }
            },
            error: function () {
                swal('Error', 'Error de conexión, intentá de nuevo', 'error');
                $btn.prop('disabled', false).text('Crear empresa');
            }
        });
    });
});
</script>
</body>
</html>
