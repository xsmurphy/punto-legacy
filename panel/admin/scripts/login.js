/**
 * Login del admin realm. POST a /bff/admin/login.php → cookie HttpOnly _jwt_admin → redirige a /admin.
 * Vanilla JS, sin deps del shell tenant (el realm admin es standalone).
 */
(function () {
	var form = document.getElementById('adminLoginForm');
	var btn  = document.getElementById('submitBtn');
	var msg  = document.getElementById('msg');

	// Fallback por si ds.js no cargó (evita botón trabado en "Procesando…").
	function resetBtn() {
		if (window.dsBtn) { window.dsBtn.reset(btn); }
		else { btn.disabled = false; btn.textContent = 'Ingresar'; btn.classList.remove('is-loading'); }
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		msg.textContent = '';
		// El estado de carga (disabled + "Procesando…" + spinner) lo arranca ds.js
		// vía data-loading-text en el submit. Acá solo reseteamos si falla.

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
				window.location.href = '/admin'; // navega → no hace falta resetear
			} else {
				msg.textContent = 'Credenciales inválidas';
				resetBtn();
			}
		})
		.catch(function () {
			msg.textContent = 'Error de conexión';
			resetBtn();
		});
	});
})();
