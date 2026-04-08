<?php
/**
 * Registro de nueva empresa — Panel
 * Flujo: teléfono → código WhatsApp → datos empresa
 * GET  /signup   → formulario
 * POST /signup   → procesa registro
 */
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("includes/functions.php");
include_once("libraries/countries.php");

define('OUTLETS_COUNT', 0);

if (isset($_SESSION['user'])) {
    header('Location: /@#dashboard');
    dai();
}

// ── POST: procesar registro ────────────────────────────────────────────────
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

    $sign = signUp($_POST, true);

    if ($sign === true) {
        sendEmail(EMAIL_NOTIFICATION_TO, 'Nueva empresa — ' . APP_NAME,
            'Empresa: ' . $_POST['storename'] . ' | ' . $_POST['email'], '');
    }

    echo $sign;
    dai();
}

$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="user-scalable=no, initial-scale=1, minimum-scale=1, width=device-width" />
    <title><?= APP_NAME ?> — Crear cuenta</title>
    <?php loadCDNFiles([
        '/assets/vendor/css/sweetalert2-7.33.1.min.css',
    ], 'css'); ?>
    <style>
        body { background: #f0f4f8; }
        .signup-wrap { max-width: 500px; margin: 40px auto; padding: 0 16px 48px; }
        .signup-logo { text-align: center; margin-bottom: 24px; }
        .signup-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 20px rgba(0,0,0,.07); padding: 36px 36px 28px; }
        .signup-card h1 { font-size: 21px; font-weight: 700; color: #111; margin: 0 0 4px; }
        .signup-card .sub { color: #888; font-size: 13px; margin-bottom: 24px; }

        .step-dots { display: flex; justify-content: center; gap: 6px; margin-bottom: 22px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #dde1e9; transition: background .2s; }
        .dot.active { background: #4cb6cb; }

        .step { display: none; }
        .step.active { display: block; }

        /* phone row — igual que login */
        .phone-row { display: flex; align-items: stretch; border: 1.5px solid #dde1e9; border-radius: 9px; overflow: visible; margin-bottom: 6px; }
        .phone-row .loginEmailCountryCodes { flex-shrink: 0; position: relative; }
        .phone-row .countriesBtn { height: 44px; padding: 0 12px; background: #f8f9fa; border: none; border-right: 1.5px solid #dde1e9; border-radius: 9px 0 0 9px; font-size: 14px; cursor: pointer; white-space: nowrap; }
        .phone-row .countriesBtn:focus { outline: none; }
        .phone-row .emailWrap { flex: 1; }
        .phone-row .emailWrap input { width: 100%; height: 44px; border: none; border-radius: 0 9px 9px 0; padding: 0 14px; font-size: 15px; background: #fff; outline: none; }
        .hint { font-size: 13px; color: #888; margin: 4px 0 0; }

        /* pin */
        .pin-wrap { display: flex; justify-content: center; margin: 16px 0 8px; }
        .pin-input { font-size: 32px; font-weight: 700; letter-spacing: 12px; text-align: center; width: 200px; border: 2px solid #dde1e9; border-radius: 12px; padding: 14px 0; }
        .pin-input.ok  { border-color: #4cb6cb; color: #4cb6cb; }
        .pin-input.err { border-color: #e55; color: #e55; animation: shake .4s; }
        @keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-6px)} 75%{transform:translateX(6px)} }
        .pin-status { text-align: center; font-size: 13px; color: #888; min-height: 20px; }
        .resend { text-align: center; margin-top: 12px; }
        .resend a { font-size: 13px; color: #4cb6cb; cursor: pointer; }

        /* step 3 */
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #555; margin-bottom: 5px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 13px; border: 1.5px solid #dde1e9; border-radius: 9px; font-size: 14px; background: #fff; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #4cb6cb; }

        .btn-signup { width: 100%; padding: 13px; border: none; border-radius: 9px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 8px; background: #4cb6cb; color: #fff; transition: background .15s, opacity .15s; }
        .btn-signup:hover { background: #3ca4b8; }
        .btn-signup:disabled { opacity: .55; cursor: not-allowed; }

        .debug-bar { background: #fff8e1; border: 1px solid #ffc107; border-radius: 8px; padding: 8px 14px; font-size: 12px; color: #856404; margin-bottom: 18px; }
        .footer-link { text-align: center; font-size: 13px; color: #888; margin-top: 18px; }
        .footer-link a { color: #4cb6cb; text-decoration: none; font-weight: 600; }
        .terms { font-size: 11px; text-align: center; color: #aaa; margin-top: 12px; }
        .terms a { color: #aaa; }
    </style>
</head>
<body>

<div class="signup-wrap">
    <div class="signup-logo">
        <img src="/images/incomeLogoLgDark.png" height="30" alt="<?= APP_NAME ?>">
    </div>

    <div class="signup-card">
        <h1>Crear cuenta</h1>
        <p class="sub">Empezá gratis · Sin tarjeta de crédito</p>

        <?php if ($isDebug): ?>
        <div class="debug-bar">⚠ Modo debug — el código será <strong>0000</strong> (no se envía por WhatsApp)</div>
        <?php endif; ?>

        <div class="step-dots">
            <div class="dot active" id="dot1"></div>
            <div class="dot" id="dot2"></div>
            <div class="dot" id="dot3"></div>
        </div>

        <!-- ── PASO 1: Teléfono ──────────────────────────────── -->
        <div class="step active" id="step1">
            <div style="margin-bottom:16px;">
                <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;display:block;margin-bottom:5px;">Número de WhatsApp</label>
                <div class="phone-row">
                    <div class="loginEmailCountryCodes animated fadeInLeft speedUpAnimation">
                        <button type="button" class="countriesBtn dropdown-toggle" data-toggle="dropdown">+595</button>
                        <ul class="dropdown-menu signInCountriesList" style="max-height:200px;overflow-y:scroll;min-width:180px;"></ul>
                    </div>
                    <div class="emailWrap">
                        <input type="tel" id="phoneInput" class="loginEmail" placeholder="981 234 567" inputmode="tel" autocomplete="tel">
                    </div>
                </div>
                <p class="hint">Te enviaremos un código de verificación por WhatsApp</p>
            </div>
            <button class="btn-signup" id="btnSend">Enviar código</button>
        </div>

        <!-- ── PASO 2: Código PIN ────────────────────────────── -->
        <div class="step" id="step2">
            <p style="text-align:center;font-size:13px;color:#555;margin-bottom:4px;" id="pinSentTo"></p>
            <div class="pin-wrap">
                <input type="tel" class="pin-input" id="pinInput" maxlength="4" placeholder="····" inputmode="numeric" autocomplete="one-time-code">
            </div>
            <p class="pin-status" id="pinStatus"></p>
            <div class="resend"><a id="btnResend">¿No recibiste el código? Reenviar</a></div>
        </div>

        <!-- ── PASO 3: Datos empresa ─────────────────────────── -->
        <div class="step" id="step3">
            <form id="signupForm" action="/signup?signup=1" method="POST">
                <div class="form-group">
                    <label>Nombre de la empresa</label>
                    <input type="text" name="storename" placeholder="Ej: Panadería Don Pedro" required autocomplete="organization">
                </div>
                <div class="form-group">
                    <label>Rubro</label>
                    <select name="category" required>
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
                <div class="form-group">
                    <label>Tu nombre y apellido</label>
                    <input type="text" name="username" placeholder="Ej: Ana García" required autocomplete="name">
                </div>
                <div class="form-group">
                    <label>Contraseña</label>
                    <div class="pwd-wrap" style="position:relative;">
                        <input type="password" name="password" id="pwd" placeholder="Mínimo 6 caracteres" required autocomplete="new-password" style="padding-right:42px;">
                        <button type="button" id="pwdToggle" aria-label="Mostrar contraseña" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#888;padding:6px;">
                            <span class="material-icons" style="font-size:20px;">visibility</span>
                        </button>
                    </div>
                </div>
                <input type="hidden" name="email" id="verifiedPhone">
                <input type="hidden" name="country" id="countryHidden" value="PY">
                <button type="submit" class="btn-signup" id="btnRegister">Crear empresa</button>
                <p class="terms">Al registrarte aceptás los <a href="/assets/terminos.pdf" target="_blank">Términos y Condiciones</a></p>
            </form>
        </div>
    </div>

    <div class="footer-link">¿Ya tenés cuenta? <a href="/login">Ingresar</a></div>
</div>

<script>
    var noSessionCheck = true;
    window.standAlone  = true;
    var countries = <?= json_encode($countriesHispanic) ?>;
</script>
<?php loadCDNFiles([
    '/assets/vendor/js/sweetalert2-7.33.1.min.js',
], 'js'); ?>
<script>
$(document).ready(function () {

    // ── Inicializar dropdown de país (igual que login) ────────────────────
    helpers.loginInputUserManager({ load: true });

    $('#phoneInput').on('keyup', function () {
        helpers.loginInputUserManager({
            input:      $(this),
            areacode:   $('.loginEmailCountryCodes'),
            inputWrap:  $('.emailWrap')
        });
    });

    // ── helpers ──────────────────────────────────────────────────────────
    function goStep(n) {
        $('.step').removeClass('active');
        $('.dot').each(function (i) { $(this).toggleClass('active', i < n); });
        $('#step' + n).addClass('active');
    }

    function setBtn(id, text, disabled) {
        var $b = $('#' + id);
        $b.text(text).prop('disabled', !!disabled);
    }

    // ── PASO 1: Enviar código ─────────────────────────────────────────────
    $('#btnSend').on('click', function () {
        var phoneVal = $('#phoneInput').val().trim();
        if (!phoneVal) { swal('', 'Ingresá tu número de teléfono', 'warning'); return; }

        var pCode   = $('.selectedPhoneCode').text().trim() || '+595';
        var country = ($('.selectedPhoneCode').data('country') || 'PY').toUpperCase();
        var phone   = $.isNumeric(phoneVal) ? (pCode + phoneVal) : phoneVal;

        setBtn('btnSend', 'Enviando...', true);

        $.get('/API/send_verification.php', { phone: phone, country: country, new: 1 })
            .done(function (data) {
                if (data.error) {
                    swal('Error', data.error, 'error');
                    setBtn('btnSend', 'Enviar código', false);
                    return;
                }
                window._signupPhone   = data.phone;
                window._signupCountry = country;
                $('#pinSentTo').text('Código enviado a ' + data.phone);
                $('#countryHidden').val(country);
                goStep(2);

                if (data.code) {
                    $('#pinInput').val(data.code);
                    setTimeout(function () { $('#pinInput').trigger('input'); }, 200);
                }
            })
            .fail(function () {
                swal('Error', 'No se pudo conectar. Intentá de nuevo.', 'error');
                setBtn('btnSend', 'Enviar código', false);
            });
    });

    // ── PASO 2: Verificar PIN ─────────────────────────────────────────────
    $('#pinInput').on('input', function () {
        var code = $(this).val();
        if (code.length < 4) return;
        $(this).prop('disabled', true);
        $('#pinStatus').text('Verificando...');

        $.get('/API/check_verification.php', { phone: window._signupPhone, code: code })
            .done(function (data) {
                if (data.success) {
                    $('#pinInput').addClass('ok');
                    $('#verifiedPhone').val(data.phone);
                    setTimeout(function () { goStep(3); }, 350);
                } else {
                    $('#pinInput').addClass('err');
                    $('#pinStatus').text('Código incorrecto, intentá de nuevo');
                    setTimeout(function () {
                        $('#pinInput').removeClass('err').prop('disabled', false).val('');
                        $('#pinStatus').text('');
                    }, 1000);
                }
            })
            .fail(function () {
                $('#pinStatus').text('Error de conexión');
                $('#pinInput').prop('disabled', false);
            });
    });

    $('#btnResend').on('click', function () {
        goStep(1);
        $('#pinInput').val('').prop('disabled', false).removeClass('ok err');
        setBtn('btnSend', 'Enviar código', false);
    });

    // ── Toggle show/hide password ─────────────────────────────────────────
    $('#pwdToggle').on('click', function () {
        var $pwd = $('#pwd');
        var $icon = $(this).find('.material-icons');
        if ($pwd.attr('type') === 'password') {
            $pwd.attr('type', 'text');
            $icon.text('visibility_off');
            $(this).attr('aria-label', 'Ocultar contraseña');
        } else {
            $pwd.attr('type', 'password');
            $icon.text('visibility');
            $(this).attr('aria-label', 'Mostrar contraseña');
        }
    });

    // ── PASO 3: Crear empresa ─────────────────────────────────────────────
    $('#signupForm').on('submit', function (e) {
        e.preventDefault();
        var pwd = $('#pwd').val();

        if (pwd.length < 6) { swal('Error', 'La contraseña debe tener al menos 6 caracteres', 'error'); return; }

        setBtn('btnRegister', 'Creando cuenta...', true);

        $.ajax({
            url:     $(this).attr('action'),
            method:  'POST',
            data:    $(this).serialize(),
            success: function (res) {
                if (res === 'true') {
                    window.location.replace('/@#dashboard');
                } else {
                    swal('Error', res || 'Error al registrar la empresa', 'error');
                    setBtn('btnRegister', 'Crear empresa', false);
                }
            },
            error: function () {
                swal('Error', 'Error de conexión', 'error');
                setBtn('btnRegister', 'Crear empresa', false);
            }
        });
    });

});
</script>
</body>
</html>
