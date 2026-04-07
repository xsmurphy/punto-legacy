<?php
/**
 * Registro de nueva empresa — Panel
 * Flujo: teléfono → código WhatsApp → datos empresa
 * GET  /signup   → formulario
 * POST /signup   → procesa registro (mismo handler que /app)
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

// Construir lista de países con código telefónico para el selector
$phoneCountries = [];
foreach ((array)$countries as $iso => $c) {
    if (!empty($c->phone)) {
        $phoneCountries[$iso] = [
            'name'  => $c->native ?? $c->name,
            'phone' => $c->phone,
        ];
    }
}
// Ordenar: PY primero, luego resto alfabético
uksort($phoneCountries, function ($a, $b) {
    if ($a === 'PY') return -1;
    if ($b === 'PY') return 1;
    return strcmp($phoneCountries[$a]['name'], $phoneCountries[$b]['name']);
});

$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
    <title><?= APP_NAME ?> — Crear cuenta</title>
    <?php loadCDNFiles([
        'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css',
    ], 'css'); ?>
    <style>
        * { box-sizing: border-box; }
        body { background: #f0f4f8; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; }
        .wrap { max-width: 480px; margin: 48px auto; padding: 0 16px 48px; }
        .logo { text-align: center; margin-bottom: 24px; }
        .card { background: #fff; border-radius: 14px; box-shadow: 0 2px 20px rgba(0,0,0,.07); padding: 36px 36px 28px; }
        h1 { font-size: 21px; font-weight: 700; color: #111; margin: 0 0 4px; }
        .sub { color: #888; font-size: 13px; margin-bottom: 26px; }
        .step { display: none; }
        .step.active { display: block; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #555; margin-bottom: 5px; }
        input, select { width: 100%; padding: 11px 14px; border: 1.5px solid #dde1e9; border-radius: 9px; font-size: 15px; transition: border .15s, box-shadow .15s; background: #fff; }
        input:focus, select:focus { outline: none; border-color: #4cb6cb; box-shadow: 0 0 0 3px rgba(76,182,203,.18); }
        .phone-row { display: flex; gap: 8px; }
        .phone-row select { width: 120px; flex-shrink: 0; }
        .phone-row input  { flex: 1; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .btn { width: 100%; padding: 13px; border: none; border-radius: 9px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 6px; transition: background .15s, opacity .15s; }
        .btn-primary { background: #4cb6cb; color: #fff; }
        .btn-primary:hover { background: #3ca4b8; }
        .btn-primary:disabled { opacity: .55; cursor: not-allowed; }
        .btn-ghost { background: none; color: #888; font-size: 13px; margin-top: 10px; }
        .btn-ghost:hover { color: #4cb6cb; }
        .pin-wrap { display: flex; justify-content: center; }
        .pin-input { font-size: 32px; font-weight: 700; letter-spacing: 12px; text-align: center; width: 200px; border: 2px solid #dde1e9; border-radius: 12px; padding: 14px 0; }
        .pin-input.ok  { border-color: #4cb6cb; color: #4cb6cb; }
        .pin-input.err { border-color: #e55; color: #e55; animation: shake .4s; }
        @keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-6px)} 75%{transform:translateX(6px)} }
        .hint { text-align: center; font-size: 13px; color: #888; margin-top: 8px; }
        .resend { text-align: center; margin-top: 12px; }
        .resend a { font-size: 13px; color: #4cb6cb; cursor: pointer; text-decoration: none; }
        .debug-bar { background: #fff8e1; border: 1px solid #ffc107; border-radius: 8px; padding: 8px 14px; font-size: 12px; color: #856404; margin-bottom: 20px; }
        .footer-link { text-align: center; font-size: 13px; color: #888; margin-top: 18px; }
        .footer-link a { color: #4cb6cb; text-decoration: none; font-weight: 600; }
        .step-dots { display: flex; justify-content: center; gap: 6px; margin-bottom: 22px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #dde1e9; transition: background .2s; }
        .dot.active { background: #4cb6cb; }
        .terms { font-size: 11px; text-align: center; color: #aaa; margin-top: 14px; }
        .terms a { color: #aaa; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo">
        <img src="/images/incomeLogoLgDark.png" width="72" alt="<?= APP_NAME ?>">
    </div>

    <div class="card">
        <h1>Crear cuenta</h1>
        <p class="sub">Empezá gratis por 14 días · Sin tarjeta de crédito</p>

        <?php if ($isDebug): ?>
        <div class="debug-bar">⚠ Modo debug — el código WhatsApp será <strong>0000</strong> (no se envía)</div>
        <?php endif; ?>

        <div class="step-dots">
            <div class="dot active" id="dot1"></div>
            <div class="dot" id="dot2"></div>
            <div class="dot" id="dot3"></div>
        </div>

        <!-- ── PASO 1: Teléfono ──────────────────────────────── -->
        <div class="step active" id="step1">
            <div class="form-group">
                <label>Número de WhatsApp</label>
                <div class="phone-row">
                    <select id="countryCode">
                        <?php foreach ($phoneCountries as $iso => $c): ?>
                        <option value="<?= $iso ?>" data-code="+<?= $c['phone'] ?>" <?= $iso === 'PY' ? 'selected' : '' ?>>
                            +<?= $c['phone'] ?> <?= $c['name'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="tel" id="phoneInput" placeholder="Ej: 981 234 567" inputmode="tel" autocomplete="tel">
                </div>
                <p class="hint" style="margin-top:6px;">Te enviaremos un código de verificación por WhatsApp</p>
            </div>
            <button class="btn btn-primary" id="btnSend">Enviar código</button>
        </div>

        <!-- ── PASO 2: Código PIN ────────────────────────────── -->
        <div class="step" id="step2">
            <div class="form-group">
                <label style="text-align:center; display:block;">Ingresá el código de 4 dígitos</label>
                <p class="hint" id="pinSentTo"></p>
                <div class="pin-wrap">
                    <input type="tel" class="pin-input" id="pinInput" maxlength="4" placeholder="····" inputmode="numeric" autocomplete="one-time-code">
                </div>
                <p class="hint" id="pinStatus"></p>
            </div>
            <div class="resend"><a id="btnResend">¿No recibiste el código? Reenviar</a></div>
        </div>

        <!-- ── PASO 3: Datos empresa ─────────────────────────── -->
        <div class="step" id="step3">
            <form id="signupForm" action="/signup?signup=1" method="POST">
                <div class="form-group">
                    <label>Nombre de la empresa</label>
                    <input type="text" name="storename" placeholder="Ej: Panadería Don Pedro" required autocomplete="organization">
                </div>
                <div class="grid2">
                    <div class="form-group">
                        <label>País</label>
                        <select name="country" id="countryHidden" required>
                            <?php foreach ($countriesHispanic as $key => $val): ?>
                            <option value="<?= $key ?>" <?= $key === 'PY' ? 'selected' : '' ?>><?= $val['native'] ?></option>
                            <?php endforeach; ?>
                        </select>
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
                </div>
                <div class="form-group">
                    <label>Tu nombre y apellido</label>
                    <input type="text" name="username" placeholder="Ej: Ana García" required autocomplete="name">
                </div>
                <div class="grid2">
                    <div class="form-group">
                        <label>Contraseña</label>
                        <input type="password" name="password" id="pwd" placeholder="Mínimo 6 caracteres" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label>Confirmar</label>
                        <input type="password" name="password_confirm" placeholder="" required autocomplete="new-password">
                    </div>
                </div>
                <!-- Campos ocultos completados en pasos anteriores -->
                <input type="hidden" name="email" id="verifiedPhone">
                <button type="submit" class="btn btn-primary" id="btnRegister">Crear empresa</button>
                <p class="terms">Al registrarte aceptás los <a href="/assets/terminos.pdf" target="_blank">Términos y Condiciones</a></p>
            </form>
        </div>
    </div>

    <div class="footer-link">¿Ya tenés cuenta? <a href="/login">Ingresar</a></div>
</div>

<script>var noSessionCheck = true;</script>
<?php loadCDNFiles([
    'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js',
], 'js'); ?>
<script>
(function () {
    var verifiedPhone = '';
    var verifiedCountry = 'PY';

    // ── helpers ──────────────────────────────────────────────────────────
    function goStep(n) {
        document.querySelectorAll('.step').forEach(function (el) { el.classList.remove('active'); });
        document.querySelectorAll('.dot').forEach(function (el, i) {
            el.classList.toggle('active', i < n);
        });
        document.getElementById('step' + n).classList.add('active');
    }

    function setBtn(id, text, disabled) {
        var b = document.getElementById(id);
        b.textContent = text;
        b.disabled = !!disabled;
    }

    // ── PASO 1: Enviar código ─────────────────────────────────────────────
    document.getElementById('btnSend').addEventListener('click', function () {
        var phone   = document.getElementById('phoneInput').value.trim();
        var country = document.getElementById('countryCode').value;
        verifiedCountry = country;

        if (!phone) {
            swal('', 'Ingresá tu número de teléfono', 'warning');
            return;
        }

        setBtn('btnSend', 'Enviando...', true);

        fetch('/API/send_verification.php?phone=' + encodeURIComponent(phone) + '&country=' + country + '&new=1')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    swal('Error', data.error, 'error');
                    setBtn('btnSend', 'Enviar código', false);
                    return;
                }
                verifiedPhone = data.phone;
                document.getElementById('pinSentTo').textContent = 'Código enviado a ' + data.phone;

                // sync country selector in step 3
                var sel = document.getElementById('countryHidden');
                if (sel) sel.value = country;

                goStep(2);

                // Debug autocomplete
                if (data.code) {
                    var pinInput = document.getElementById('pinInput');
                    pinInput.value = data.code;
                    setTimeout(function () { pinInput.dispatchEvent(new Event('input')); }, 200);
                }
            })
            .catch(function () {
                swal('Error', 'No se pudo conectar. Intentá de nuevo.', 'error');
                setBtn('btnSend', 'Enviar código', false);
            });
    });

    // ── PASO 2: Verificar PIN ─────────────────────────────────────────────
    document.getElementById('pinInput').addEventListener('input', function () {
        var code = this.value;
        var status = document.getElementById('pinStatus');
        if (code.length < 4) return;

        this.disabled = true;
        status.textContent = 'Verificando...';

        fetch('/API/check_verification.php?phone=' + encodeURIComponent(verifiedPhone) + '&code=' + encodeURIComponent(code))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    document.getElementById('pinInput').classList.add('ok');
                    document.getElementById('verifiedPhone').value = data.phone;
                    setTimeout(function () { goStep(3); }, 350);
                } else {
                    document.getElementById('pinInput').classList.add('err');
                    status.textContent = 'Código incorrecto, intentá de nuevo';
                    setTimeout(function () {
                        var inp = document.getElementById('pinInput');
                        inp.classList.remove('err');
                        inp.disabled = false;
                        inp.value = '';
                        status.textContent = '';
                    }, 1000);
                }
            })
            .catch(function () {
                status.textContent = 'Error de conexión';
                document.getElementById('pinInput').disabled = false;
            });
    });

    document.getElementById('btnResend').addEventListener('click', function () {
        goStep(1);
        document.getElementById('pinInput').value = '';
        document.getElementById('pinInput').disabled = false;
        document.getElementById('pinInput').classList.remove('ok', 'err');
        setBtn('btnSend', 'Enviar código', false);
    });

    // ── PASO 3: Crear empresa ─────────────────────────────────────────────
    document.getElementById('signupForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var pwd     = document.getElementById('pwd').value;
        var confirm = this.querySelector('[name="password_confirm"]').value;

        if (pwd !== confirm) { swal('Error', 'Las contraseñas no coinciden', 'error'); return; }
        if (pwd.length < 6) { swal('Error', 'La contraseña debe tener al menos 6 caracteres', 'error'); return; }

        setBtn('btnRegister', 'Creando cuenta...', true);

        fetch(this.action, { method: 'POST', body: new FormData(this) })
            .then(function (r) { return r.text(); })
            .then(function (res) {
                if (res === 'true') {
                    window.location.replace('/@#dashboard');
                } else {
                    swal('Error', res || 'Error al registrar la empresa', 'error');
                    setBtn('btnRegister', 'Crear empresa', false);
                }
            })
            .catch(function () {
                swal('Error', 'Error de conexión', 'error');
                setBtn('btnRegister', 'Crear empresa', false);
            });
    });
})();
</script>
</body>
</html>
