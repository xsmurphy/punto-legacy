/**
 * Login del admin realm. POST a /bff/admin/login.php → cookie HttpOnly _jwt_admin → redirige a /admin.
 * Vanilla JS, sin deps del shell tenant (el realm admin es standalone).
 */
(function () {
	var form = document.getElementById('adminLoginForm');
	var btn  = document.getElementById('submitBtn');
	var msg  = document.getElementById('msg');

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		msg.textContent = '';
		btn.disabled = true;
		btn.textContent = 'Ingresando…';

		var body = new URLSearchParams({
			email:    document.getElementById('email').value.trim(),
			password: document.getElementById('password').value
		});

		fetch('/bff/admin/login.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
			credentials: 'same-origin'
		})
		.then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
		.then(function (res) {
			if (res && res.ok) {
				window.location.href = '/admin';
			} else {
				msg.textContent = 'Credenciales inválidas';
				btn.disabled = false;
				btn.textContent = 'Ingresar';
			}
		})
		.catch(function () {
			msg.textContent = 'Error de conexión';
			btn.disabled = false;
			btn.textContent = 'Ingresar';
		});
	});
})();
